<?php
/**
 * LAW 2 — NO SCHEMA CHANGE AT RUNTIME, enforced at the database (spine S1;
 * Part IV §IV.10 "a production DB user without ALTER").
 *
 * The static gate (no-runtime-ddl) stops DDL that is written down. This one
 * stops DDL that is assembled at runtime, arrives through a package, or is
 * reached by a path nobody read: the application account simply cannot execute
 * it. SmartPRS 2's application account is the schema owner, which is why ~49
 * tables exist that no migration ever created.
 *
 * Migrations run as a separate, privileged account, at deploy time only.
 *
 * Configuration comes from the environment or the repo's .env:
 *   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
 * With none of those set the check skips loudly and does not fail the build.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/** Privilege prefixes an application account must never hold. */
const GATE_FORBIDDEN_PRIV = ['ALTER', 'CREATE', 'DROP', 'INDEX', 'ALL PRIVILEGES', 'SUPER', 'GRANT OPTION'];

/**
 * APPEND-ONLY TABLES, and the privileges that would make them not append-only.
 *
 * Both ledger classes say in their docblocks that this gate asserts the grant.
 * Until now it did not — it checked DDL and nothing else — so the comment was a
 * claim about a check that was not being made, which is the same defect as a
 * gate that reads no files: it reassures without proving.
 *
 *   audit_entries        the audit migration: "granted INSERT and SELECT on this
 *                        table only — no UPDATE, no DELETE". The hash chain is
 *                        evidence only if nothing can rewrite a committed row.
 *   workflow_transitions §IX.6.3 mechanism (2). The history of a decision.
 *   workflow_tasks       the same, but UPDATE is REQUIRED: the decision itself
 *                        is one conditional UPDATE (mechanism 1). Only DELETE
 *                        is forbidden.
 *   workflow_timers      §IX.7.3. A timer is cancelled, not deleted, so that
 *                        "why did this reminder never go out" stays answerable.
 *
 * @var array<string, list<string>>
 */
const GATE_APPEND_ONLY = [
    'audit_entries' => ['UPDATE', 'DELETE'],
    // Found by tools/db/grants.php, which lists every table the migrations
    // create and asks this constant what each may hold. `audit_anchors` was
    // missing: AuditLedger::anchor() says re-anchoring a day whose entries
    // changed COLLIDES with the unique index, "which is the detection working
    // rather than failing" — and that detection is defeated by an account that
    // can update the anchor row instead. The anchor is the thing a signature
    // later attaches to; if it can be rewritten it attests to nothing.
    'audit_anchors' => ['UPDATE', 'DELETE'],
    // A customisation is a record of a decision about what a screen MEANS.
    // An account that can rewrite version 3 can make the label somebody
    // complained about have never existed.
    'module_customisations' => ['UPDATE', 'DELETE'],
    'workflow_transitions' => ['DELETE'],
    'workflow_tasks' => ['DELETE'],
    'workflow_timers' => ['DELETE'],

    // The surface's hash-chained trail. The chain makes an EDIT detectable, and
    // that detection is exactly what an account holding UPDATE defeats — a row
    // rewritten together with its own hash leaves a chain that verifies and a
    // history that lies. DELETE is the same argument: a missing sequence number
    // is detectable, so an account that can remove one is an account that can
    // try.
    'audit' => ['UPDATE', 'DELETE'],

    // A closed payroll run and a settlement are records of what was PAID. They
    // are corrected by a new row beside them, never by editing the original —
    // that is the whole design — so the account has no business holding DELETE.
    'payroll_corrections' => ['DELETE'],
    'settlements' => ['DELETE'],

    // A decision that can be rewritten or removed is not a record of who
    // decided. The request row moves through its chain and is updated; the
    // DECISIONS behind it never change.
    'workflow_decisions' => ['UPDATE', 'DELETE'],
];

function gate_db_privilege(string $root): array
{
    $cfg = gate_db_config($root);
    if ($cfg === null) {
        return [gate_find('db-privilege', $root, $root . '/.env', 1,
            'SKIPPED: no database configured (set DB_DATABASE/DB_USERNAME in the environment or .env)', true)];
    }
    if (!extension_loaded('pdo_mysql')) {
        return [gate_find('db-privilege', $root, $root . '/.env', 1,
            'SKIPPED: ext-pdo_mysql not loaded', true)];
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $cfg['host'], $cfg['port'], $cfg['database']);
    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [gate_find('db-privilege', $root, $root . '/.env', 1,
            'SKIPPED: cannot connect as the application account — ' . $e->getMessage(), true)];
    }

    $findings = [];

    foreach (GATE_APPEND_ONLY as $table => $forbidden) {
        $held = gate_db_effective_privileges($grants, $cfg['database'], $table);

        foreach ($forbidden as $priv) {
            if (in_array($priv, $held, true) || in_array('ALL PRIVILEGES', $held, true)) {
                $findings[] = gate_find('db-privilege', $root, $root . '/.env', 1,
                    "application account '{$cfg['username']}' holds {$priv} on {$table}, which is append-only"
                    . ' — a row that can be rewritten or removed is not evidence. Grant the narrower set on'
                    . ' that table specifically.');
            }
        }
    }

    foreach ($grants as $g) {
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', (string) $g, $m)) {
            continue;
        }
        if (stripos($m[1], 'PROXY') === 0 || stripos($m[1], 'USAGE') === 0) {
            continue;
        }
        foreach (array_map('trim', explode(',', $m[1])) as $priv) {
            $priv = strtoupper(preg_replace('/\s*\(.*$/', '', $priv));
            foreach (GATE_FORBIDDEN_PRIV as $bad) {
                if ($priv === $bad || str_starts_with($priv, $bad . ' ')) {
                    $findings[] = gate_find('db-privilege', $root, $root . '/.env', 1,
                        "application account '{$cfg['username']}' holds $priv on {$m[2]}"
                        . ' — revoke it; DDL belongs to the migration account at deploy time');
                    break;
                }
            }
        }
    }

    if ($findings === []) {
        $findings[] = gate_find('db-privilege', $root, $root . '/.env', 1,
            "OK: '{$cfg['username']}' holds no ALTER/CREATE/DROP/INDEX on {$cfg['database']}, and no "
            . 'UPDATE or DELETE on the append-only tables', true);
    }

    return $findings;
}

/**
 * The privileges a grant list actually confers on one table.
 *
 * MySQL grants are cumulative across scopes: `ON *.*`, `ON db.*` and
 * `ON db.table` all apply, and the effective set is their union. A gate that
 * only read the table-specific grant would pass an account holding DELETE
 * globally, which is the account almost every installation actually has.
 *
 * Pure, so the parsing is asserted in the gate self-test without a database —
 * the part most likely to be wrong is the part hardest to reach at runtime.
 *
 * @param  list<string>  $grants  SHOW GRANTS output
 * @return list<string>  upper-cased privilege names
 */
function gate_db_effective_privileges(array $grants, string $database, string $table): array
{
    $applicable = [
        '*.*',
        '`' . $database . '`.*',
        $database . '.*',
        '`' . $database . '`.`' . $table . '`',
        $database . '.' . $table,
        '`' . $database . '`.' . $table,
        $database . '.`' . $table . '`',
    ];

    $held = [];

    foreach ($grants as $grant) {
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', (string) $grant, $m)) {
            continue;
        }

        if (!in_array($m[2], $applicable, true)) {
            continue;
        }

        foreach (array_map('trim', explode(',', $m[1])) as $priv) {
            // `SELECT (col)` and `UPDATE (col1, col2)` are column-level grants;
            // the privilege name is what matters here, not the columns.
            $name = strtoupper(preg_replace('/\s*\(.*$/', '', $priv));

            if ($name !== '' && $name !== 'USAGE') {
                $held[$name] = true;
            }
        }
    }

    return array_keys($held);
}

/** Environment first, then the repo .env. Null when nothing is configured. */
function gate_db_config(string $root): ?array
{
    $env = [];
    $file = rtrim($root, '/') . '/.env';
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                $env[$m[1]] = trim($m[2], "\"' \t");
            }
        }
    }
    $get = static fn(string $k, string $d = ''): string => (string) (getenv($k) ?: ($env[$k] ?? $d));

    $db = $get('DB_DATABASE');
    $user = $get('DB_USERNAME');
    if ($db === '' || $user === '') {
        return null;
    }
    return [
        'host' => $get('DB_HOST', '127.0.0.1'),
        'port' => $get('DB_PORT', '3306'),
        'database' => $db,
        'username' => $user,
        'password' => $get('DB_PASSWORD'),
    ];
}

gate_cli_main(__FILE__, 'db-privilege', 'gate_db_privilege');
