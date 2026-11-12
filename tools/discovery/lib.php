<?php
/**
 * SmartPRS 2 Discovery Toolkit — shared library.
 *
 * READ-ONLY. Every statement this library will issue is checked against a
 * SELECT/SHOW/EXPLAIN allow-list before it reaches the server (see dsc_q()).
 * There is no code path here that can emit INSERT/UPDATE/DELETE/DDL.
 *
 * Requires: PHP 8.1+, ext-pdo_mysql, ext-json. Nothing else. No Composer.
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "SmartPRS discovery requires PHP 8.1 or newer; this is " . PHP_VERSION . "\n");
    exit(2);
}

/** Collection context: connection, output paths, redaction policy, notes. */
final class DscCtx
{
    public PDO $pdo;
    public string $db;
    public string $out;        // absolute path of the pack directory
    public string $install;
    public bool $redact;
    public string $salt;
    public array $opt;
    public array $notes = [];      // skipped/failed steps, human readable
    public array $findings = [];   // structured findings for SUMMARY.md
    public array $schemaCache = []; // table => [col => info]
    public int $emittedFindings = 0;
    public int $emittedNotes = 0;
    private float $t0;

    public function __construct(PDO $pdo, string $db, string $out, string $install, bool $redact, string $salt, array $opt)
    {
        $this->pdo = $pdo;
        $this->db = $db;
        $this->out = $out;
        $this->install = $install;
        $this->redact = $redact;
        $this->salt = $salt;
        $this->opt = $opt;
        $this->t0 = microtime(true);
    }

    public function note(string $step, string $msg): void
    {
        $this->notes[] = ['step' => $step, 'note' => $msg];
        if (($this->opt['verbose'] ?? false)) {
            fwrite(STDERR, "  note[$step]: $msg\n");
        }
    }

    /**
     * Record something that changes the migration plan.
     * $severity: BLOCKER | HIGH | MEDIUM | INFO
     */
    public function finding(string $severity, string $area, string $title, string $detail = '', array $data = []): void
    {
        $this->findings[] = compact('severity', 'area', 'title', 'detail', 'data');
    }

    public function elapsed(): float
    {
        return round(microtime(true) - $this->t0, 1);
    }
}

/* ------------------------------------------------------------------ CLI */

/** Parse --key=value and --flag arguments. */
function dsc_options(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!str_starts_with($a, '--')) {
            continue;
        }
        $a = substr($a, 2);
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $o[$k] = $v;
        } else {
            $o[$a] = true;
        }
    }
    return $o;
}

function dsc_usage(string $script): string
{
    return <<<TXT
Usage: php $script [connection] [options]

Connection (either --app, or explicit flags, or --dsn):
  --app=PATH            SmartPRS 2 application root; DB credentials are read from PATH/.env
  --host=  --port=  --db=  --user=  --pass=
  --dsn=                full PDO DSN, used with --user/--pass

Options:
  --install=ID          install identifier for the pack name (default: db name)
  --out=PATH            pack directory (default: ./discovery-pack-<install>-<date>)
  --unredacted          include raw PII. DEFAULT IS REDACTED. Requires --i-have-consent
  --i-have-consent      asserts written customer authorisation exists for unredacted collection
  --salt=STRING         pseudonymisation salt (default: derived from --install)
  --counts=exact|estimate   row counts (default exact; estimate is fast but not reconcilable)
  --sample=N            max sample rows kept per evidence query (default 50)
  --timeout=MS          per-query server-side time limit (default 30000)
  --skip-heavy          skip full-table scans over --heavy-rows rows
  --heavy-rows=N        threshold for "heavy" (default 5000000)
  --access-log=PATH     web-server access log for the D10.5 exposure evidence
  --hash-files          SHA-256 every document file (slow; needed before transfer verification)
  --sign-key=PATH       PEM private key; signs manifest.json into manifest.sig
  --verbose
TXT;
}

/** Read DB_* from a Laravel .env without loading Laravel. */
function dsc_env(string $appRoot): array
{
    $f = rtrim($appRoot, "/\\") . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($f)) {
        return [];
    }
    $env = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
    return $env;
}

/**
 * Build the context: connect, create the pack directory, set the read-only session.
 * Used both by run-discovery.php and by each check when run standalone.
 */
function dsc_bootstrap(array $opt): DscCtx
{
    $env = isset($opt['app']) ? dsc_env((string) $opt['app']) : [];

    $host = (string) ($opt['host'] ?? $env['DB_HOST'] ?? '127.0.0.1');
    $port = (string) ($opt['port'] ?? $env['DB_PORT'] ?? '3306');
    $name = (string) ($opt['db'] ?? $env['DB_DATABASE'] ?? '');
    $user = (string) ($opt['user'] ?? $env['DB_USERNAME'] ?? '');
    $pass = (string) ($opt['pass'] ?? $env['DB_PASSWORD'] ?? '');
    $dsn = (string) ($opt['dsn'] ?? "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");

    if ($name === '' && !isset($opt['dsn'])) {
        fwrite(STDERR, "No database named. Pass --app=PATH (reads .env) or --db=NAME.\n");
        exit(2);
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Cannot connect: " . $e->getMessage() . "\n");
        exit(2);
    }

    // Read-committed keeps no long read view open: this must not make InnoDB
    // purge fall behind on a live server.
    foreach ([
        'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        'SET SESSION max_execution_time = ' . (int) ($opt['timeout'] ?? 30000),           // MySQL 5.7.8+
        'SET SESSION max_statement_time = ' . ((int) ($opt['timeout'] ?? 30000)) / 1000,  // MariaDB
        'SET SESSION group_concat_max_len = 65535',
    ] as $s) {
        try {
            $pdo->exec($s);
        } catch (PDOException $e) {
            // Server does not support that knob. Not fatal.
        }
    }

    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db === '') {
        fwrite(STDERR, "Connected but no default database selected. Use --db=NAME.\n");
        exit(2);
    }

    $install = (string) ($opt['install'] ?? $db);
    $install = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $install) ?: 'install';

    $redact = !isset($opt['unredacted']);
    if (!$redact && !isset($opt['i-have-consent'])) {
        fwrite(STDERR, "--unredacted requires --i-have-consent (see RUNBOOK.md, 'Consent').\n");
        exit(2);
    }
    $salt = (string) ($opt['salt'] ?? ('smartprs-discovery:' . $install));

    $out = (string) ($opt['out'] ?? ('discovery-pack-' . $install . '-' . date('Y-m-d')));
    if (!is_dir($out) && !@mkdir($out, 0700, true) && !is_dir($out)) {
        fwrite(STDERR, "Cannot create output directory: $out\n");
        exit(2);
    }
    $out = realpath($out) ?: $out;

    $ctx = new DscCtx($pdo, $db, $out, $install, $redact, $salt, $opt);

    if (!$redact) {
        $ctx->finding('HIGH', 'pack', 'Pack collected UNREDACTED',
            'This pack contains employee personal data in the clear. Handle per RUNBOOK.md.');
    }
    return $ctx;
}

/** Warn if the collecting account can write. Cheap, and it is the safety claim. */
function dsc_check_grants(DscCtx $c): array
{
    $g = [];
    try {
        foreach ($c->pdo->query('SHOW GRANTS') as $row) {
            $g[] = (string) reset($row);
        }
    } catch (PDOException $e) {
        $c->note('grants', 'SHOW GRANTS refused: ' . $e->getMessage());
        return ['grants' => [], 'read_only_user' => null];
    }
    $writable = false;
    foreach ($g as $line) {
        if (preg_match('/GRANT\s+(ALL|.*\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b)/i', $line)) {
            $writable = true;
        }
    }
    if ($writable) {
        $c->finding('MEDIUM', 'safety', 'Collector is connected with a WRITE-capable account',
            'R-XXIX-004 requires a read-only user. Nothing in this toolkit writes, but the '
            . 'account should be downgraded before the next run.');
    }
    return ['grants' => $g, 'read_only_user' => !$writable];
}

/* -------------------------------------------------------------- querying */

/**
 * Run a read-only query. Never throws: a missing table or column is the normal
 * case on SmartPRS 2 and is recorded as a note, not an abort.
 *
 * @return list<array<string,mixed>>
 */
function dsc_q(DscCtx $c, string $sql, array $params = [], string $step = 'query'): array
{
    $probe = ltrim(preg_replace('/^\s*(--[^\n]*\n|\/\*.*?\*\/|\s)+/s', '', $sql) ?? $sql);
    if (!preg_match('/^(SELECT|SHOW|EXPLAIN|DESCRIBE|WITH)\b/i', $probe)) {
        // Belt and braces: this library must be incapable of mutating anything.
        $c->note($step, 'REFUSED non-read statement: ' . substr($probe, 0, 60));
        return [];
    }
    try {
        $st = $c->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (PDOException $e) {
        $c->note($step, trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''));
        return [];
    }
}

function dsc_val(DscCtx $c, string $sql, array $params = [], string $step = 'query', $default = null)
{
    $r = dsc_q($c, $sql, $params, $step);
    if (!$r) {
        return $default;
    }
    $first = reset($r);
    $v = reset($first);
    return $v === false ? $default : $v;
}

/** Column map for one table, cached. Empty array means the table is absent. */
function dsc_cols(DscCtx $c, string $table): array
{
    if (!isset($c->schemaCache[$table])) {
        $rows = dsc_q($c, 'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE
                             FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                            ORDER BY ORDINAL_POSITION', [$c->db, $table], 'cols:' . $table);
        $m = [];
        foreach ($rows as $r) {
            $m[$r['COLUMN_NAME']] = $r;
        }
        $c->schemaCache[$table] = $m;
    }
    return $c->schemaCache[$table];
}

function dsc_has_table(DscCtx $c, string $table): bool
{
    return dsc_cols($c, $table) !== [];
}

function dsc_has_cols(DscCtx $c, string $table, array $cols): bool
{
    $m = dsc_cols($c, $table);
    foreach ($cols as $col) {
        if (!isset($m[$col])) {
            return false;
        }
    }
    return $m !== [];
}

/** Every base table in the database, sorted. */
function dsc_tables(DscCtx $c): array
{
    static $t = null;
    if ($t === null) {
        $t = array_column(dsc_q($c, "SELECT TABLE_NAME FROM information_schema.TABLES
                                      WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
                                      ORDER BY TABLE_NAME", [$c->db], 'tables'), 'TABLE_NAME');
    }
    return $t;
}

/** InnoDB row estimate, used only to decide whether a scan is "heavy". */
function dsc_est_rows(DscCtx $c, string $table): int
{
    return (int) dsc_val($c, 'SELECT TABLE_ROWS FROM information_schema.TABLES
                               WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$c->db, $table], 'est', 0);
}

/** True when this table should not be scanned under --skip-heavy. */
function dsc_is_heavy(DscCtx $c, string $table): bool
{
    if (!isset($c->opt['skip-heavy'])) {
        return false;
    }
    return dsc_est_rows($c, $table) > (int) ($c->opt['heavy-rows'] ?? 5000000);
}

/** Backtick-quote an identifier. Only ever fed names read from information_schema. */
function dsc_id(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/* ------------------------------------------------------------- redaction */

/**
 * Column names whose VALUES are personal data. Matched case-insensitively as
 * whole names or as suffixes/prefixes. Documented verbatim in RUNBOOK.md.
 */
const DSC_PII_PATTERNS = [
    'name', 'first_name', 'last_name', 'middle_name', 'full_name', 'father', 'mother', 'spouse',
    'employee', 'employee_name', 'emp_name', 'candidate', 'referee', 'nominee', 'contact_person',
    'email', 'email_id', 'personal_email', 'mobile', 'phone', 'alt_mobile', 'emergency_contact',
    'emergency_no', 'whatsapp',
    'pan', 'aadhaar', 'aadhar', 'national_id', 'uan', 'esic_no', 'esi_no', 'pf_no', 'passport',
    'dl_no', 'voter_id', 'driving_licence',
    'bank_acc', 'bank_account', 'account_no', 'acc_no', 'ifsc', 'upi',
    'address', 'address1', 'address2', 'permanent_address', 'current_address', 'city_address',
    'dob', 'date_of_birth', 'photo', 'file_path', 'doc_path', 'signature',
    'device_user_id', 'password', 'token', 'secret', 'api_key', 'smtp_pass', 'smtp_password',
    'emp_code', 'temp_emp_code', 'employee_code', 'lat', 'lng', 'latitude', 'longitude',
];

function dsc_is_pii_column(string $col): bool
{
    $c = strtolower($col);
    foreach (DSC_PII_PATTERNS as $p) {
        if ($c === $p || str_ends_with($c, '_' . $p) || str_starts_with($c, $p . '_')) {
            return true;
        }
    }
    return false;
}

/**
 * Pseudonymise one value. Deterministic within a pack (so the same employee is
 * the same token in every file) and salted (so it is not a rainbow-table lookup
 * across packs). This is pseudonymisation, not anonymisation — see RUNBOOK.md.
 */
function dsc_hash(DscCtx $c, $v): string
{
    if ($v === null) {
        return '';
    }
    $v = (string) $v;
    if ($v === '') {
        return '';
    }
    return 'x' . substr(hash('sha256', $c->salt . "\0" . $v), 0, 12);
}

/**
 * Apply the redaction policy to a row.
 * $pii: 'auto' (default) | 'none' (row is configuration, not personal data)
 *     | list of column names to redact regardless of their name
 */
function dsc_redact_row(DscCtx $c, array $row, $pii = 'auto'): array
{
    if (!$c->redact || $pii === 'none') {
        return $row;
    }
    $explicit = is_array($pii) ? array_map('strtolower', $pii) : null;
    $out = [];
    foreach ($row as $k => $v) {
        $hit = $explicit !== null
            ? in_array(strtolower((string) $k), $explicit, true)
            : dsc_is_pii_column((string) $k);
        if (!$hit || $v === null || $v === '') {
            $out[$k] = $v;
            continue;
        }
        $lk = strtolower((string) $k);
        if (str_contains($lk, 'password') || str_contains($lk, 'secret') || str_contains($lk, 'token') || str_contains($lk, 'api_key')) {
            $out[$k] = '[present:len=' . strlen((string) $v) . ']';   // never a fingerprint of a live credential
        } elseif ($lk === 'dob' || $lk === 'date_of_birth') {
            $out[$k] = preg_match('/^(\d{4})/', (string) $v, $m) ? $m[1] . '-**-**' : '[redacted-date]';
        } elseif (in_array($lk, ['lat', 'lng', 'latitude', 'longitude'], true)) {
            $out[$k] = is_numeric($v) ? (string) round((float) $v, 1) : '[redacted]';   // ~11 km bucket
        } else {
            $out[$k] = dsc_hash($c, $v);
        }
    }
    return $out;
}

/* --------------------------------------------------------------- writing */

function dsc_path(DscCtx $c, string $rel): string
{
    $p = $c->out . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($p);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $p;
}

/** Deterministic JSON: pretty, unescaped slashes, stable key order as given. */
function dsc_write_json(DscCtx $c, string $rel, $data): void
{
    file_put_contents(
        dsc_path($c, $rel),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"
    );
}

/**
 * Write rows to CSV, applying the redaction policy. Rows must already be in the
 * order the pack should record (R-XXIX-007: reproducible output).
 */
function dsc_write_csv(DscCtx $c, string $rel, array $rows, $pii = 'auto'): int
{
    $fh = fopen(dsc_path($c, $rel), 'w');
    if ($fh === false) {
        $c->note('write', "cannot open $rel");
        return 0;
    }
    $n = 0;
    foreach ($rows as $row) {
        $row = dsc_redact_row($c, (array) $row, $pii);
        if ($n === 0) {
            fputcsv($fh, array_keys($row));
        }
        fputcsv($fh, array_map(static fn($v) => $v === null ? '' : (string) $v, $row));
        $n++;
    }
    if ($n === 0) {
        fwrite($fh, "(no rows)\n");
    }
    fclose($fh);
    return $n;
}

function dsc_write_text(DscCtx $c, string $rel, string $text): void
{
    file_put_contents(dsc_path($c, $rel), $text);
}

/**
 * Write this check's findings and notes to _meta/findings-<check>.json.
 * Only what this check added is written, so the orchestrator (one shared context
 * across all checks) produces the same files as a standalone run.
 */
function dsc_finish(DscCtx $c, string $check): void
{
    dsc_write_json($c, "_meta/findings-$check.json", [
        'check' => $check,
        'findings' => array_slice($c->findings, $c->emittedFindings),
        'notes' => array_slice($c->notes, $c->emittedNotes),
    ]);
    $c->emittedFindings = count($c->findings);
    $c->emittedNotes = count($c->notes);
}

/** Cap a sample list at --sample rows. */
function dsc_sample(DscCtx $c, array $rows): array
{
    $n = (int) ($c->opt['sample'] ?? 50);
    return $n > 0 ? array_slice($rows, 0, $n) : $rows;
}

/** Standalone-mode entry used by every checks/*.php file. */
function dsc_standalone(string $script, callable $fn): void
{
    $opt = dsc_options($_SERVER['argv'] ?? []);
    if (isset($opt['help']) || isset($opt['h'])) {
        echo dsc_usage($script), "\n";
        exit(0);
    }
    $c = dsc_bootstrap($opt);
    fwrite(STDERR, "[$script] pack: {$c->out}\n");
    $fn($c);
    fwrite(STDERR, "[$script] done in {$c->elapsed()}s"
        . ($c->notes ? ' (' . count($c->notes) . " notes)" : '') . "\n");
}
