<?php
/**
 * SmartPRS 3 enforcement gates — shared library.
 *
 * Zero dependencies. PHP 8.4 standard library + ext-tokenizer only.
 * Nothing here needs Composer, and nothing here may grow a plugin system.
 *
 * A check is a function: (string $root) => array of findings.
 * A finding is ['check'=>, 'file'=>, 'line'=>, 'msg'=>, 'suppressed'=>bool].
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, 'SmartPRS 3 gates require PHP 8.3+; this is ' . PHP_VERSION . "\n");
    exit(2);
}

/**
 * Relative-path prefixes never scanned. `tools/` covers the gates themselves —
 * this file names DDL and PII field names for a living, and a gate that fails
 * on its own source is a gate that gets deleted. Fixtures are under tools/ too,
 * so a real run never sees them; when a fixture directory is itself the scanned
 * root its paths start at `src/` and are scanned normally.
 */
const GATE_EXCLUDE = [
    'vendor/',
    'node_modules/',
    '.git/',
    'storage/',
    'bootstrap/cache/',
    'tools/',
];

// ---------------------------------------------------------------------------
// File discovery
// ---------------------------------------------------------------------------

/**
 * Every *.php under $root/$subdir (relative path returned alongside), minus
 * GATE_EXCLUDE. Exclusion is on the path RELATIVE to $root, so the fixtures
 * are skipped in a real run but scannable when a fixture dir is itself $root.
 */
function gate_php_files(string $root, string $subdir = ''): array
{
    $base = rtrim($root, '/') . ($subdir !== '' ? '/' . trim($subdir, '/') : '');
    if (!is_dir($base)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $abs = gate_path($f->getPathname());
        $rel = gate_rel($root, $abs);
        foreach (GATE_EXCLUDE as $x) {
            if (str_starts_with($rel, $x)) {
                continue 2;
            }
        }
        $out[$rel] = $abs;
    }
    ksort($out);
    return $out;
}

/** Files under src/<Module>/Domain/ — the pure layer (Part IV §IV.2.1). */
function gate_domain_files(string $root): array
{
    return array_filter(
        gate_php_files($root, 'src'),
        static fn(string $rel): bool => (bool) preg_match('#^src/[^/]+/Domain/#', $rel),
        ARRAY_FILTER_USE_KEY
    );
}

/**
 * Every path in the gates is compared with '/' separators, whatever the host
 * uses. Found on Windows: without this, gate_rel() could not match its own
 * root, returned the absolute path, and EVERY GATE_EXCLUDE prefix silently
 * stopped matching — so a run scanned vendor/, the gates' own source and their
 * deliberately-violating fixtures, and reported 148 violations that were all
 * the harness looking at itself. A gate that cries wolf on a fresh checkout is
 * a gate someone switches off.
 */
function gate_path(string $p): string
{
    return str_replace('\\', '/', $p);
}

function gate_rel(string $root, string $abs): string
{
    $root = rtrim(gate_path($root), '/') . '/';
    $abs = gate_path($abs);

    return str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs;
}

/**
 * A check with nothing to read reports it, loudly.
 *
 * Found by asking the question: four checks — including Law 1's and Law 3/4's —
 * select `src/<Module>/Domain/`, no such directory exists yet, and all four had
 * been passing over ZERO files since the day they were written. A green gate
 * that read nothing is worse than a missing one, because it is evidence.
 *
 * This is the same shape db-privilege already uses for an unconfigured
 * database: a visible suppression, printed on every run, never a violation.
 * The build stays green and the report says the check is asleep and why.
 */
function gate_vacuous(string $check, string $root, string $why): array
{
    return [gate_find($check, $root, $root . '/src', 1, 'SKIPPED: ' . $why, true)];
}

/** Load an allow-list file, tolerating its absence. */
function gate_allow(string $name, array $default = []): array
{
    $f = __DIR__ . '/allow/' . $name . '.php';
    if (!is_file($f)) {
        return $default;
    }
    $v = require $f;
    return is_array($v) ? $v : $default;
}

// ---------------------------------------------------------------------------
// Tokeniser
// ---------------------------------------------------------------------------

/**
 * token_get_all() normalised: every entry is ['id'=>int|string,'text'=>,'line'=>].
 * Single-character tokens get their character as the id, so `$t['id'] === '='`
 * and `$t['id'] === T_RETURN` read the same way.
 */
function gate_tokens(string $file): array
{
    $src = (string) file_get_contents($file);
    $out = [];
    $line = 1;
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            $out[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
            $line = $t[2] + substr_count($t[1], "\n");
        } else {
            $out[] = ['id' => $t, 'text' => $t, 'line' => $line];
        }
    }
    return $out;
}

const GATE_SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

/** Index of the nearest significant token in direction $dir, or null. */
function gate_sig(array $t, int $i, int $dir = -1): ?int
{
    for ($j = $i + $dir; isset($t[$j]); $j += $dir) {
        if (!in_array($t[$j]['id'], GATE_SKIP, true)) {
            return $j;
        }
    }
    return null;
}

/** Text of the nearest significant token in direction $dir ('' if none). */
function gate_sig_text(array $t, int $i, int $dir = -1): string
{
    $j = gate_sig($t, $i, $dir);
    return $j === null ? '' : $t[$j]['text'];
}

/** Index of the ')' matching the '(' at $open. */
function gate_match_paren(array $t, int $open): int
{
    $d = 0;
    for ($i = $open, $n = count($t); $i < $n; $i++) {
        if ($t[$i]['id'] === '(') {
            $d++;
        } elseif ($t[$i]['id'] === ')') {
            if (--$d === 0) {
                return $i;
            }
        }
    }
    return count($t) - 1;
}

/** Raw source text of tokens [$from,$to]. */
function gate_text(array $t, int $from, int $to): string
{
    $s = '';
    for ($i = $from; $i <= $to && isset($t[$i]); $i++) {
        $s .= $t[$i]['text'];
    }
    return $s;
}

/** Unquoted body of a T_CONSTANT_ENCAPSED_STRING token. */
function gate_str_value(string $text): string
{
    return stripcslashes(substr($text, 1, -1));
}

// ---------------------------------------------------------------------------
// Findings and reporting
// ---------------------------------------------------------------------------

function gate_find(string $check, string $root, string $abs, int $line, string $msg, bool $suppressed = false): array
{
    return [
        'check' => $check,
        'file' => gate_rel($root, $abs),
        'line' => $line,
        'msg' => $msg,
        'suppressed' => $suppressed,
    ];
}

function gate_root_from_argv(array $argv): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--root=')) {
            $p = realpath(substr($a, 7));
            if ($p === false) {
                fwrite(STDERR, "gate: --root path does not exist\n");
                exit(2);
            }
            return $p;
        }
    }
    return dirname(__DIR__, 2);
}

/**
 * Bottom-of-file hook so every check is `php checks/no-empty-catch.php`.
 * Returns silently when the file was included by the orchestrator.
 */
function gate_cli_main(string $file, string $check, callable $fn): void
{
    $entry = realpath($_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? ''));
    if ($entry === false || $entry !== realpath($file)) {
        return;
    }
    $root = gate_root_from_argv($_SERVER['argv'] ?? []);
    $findings = $fn($root);
    $violations = array_values(array_filter($findings, static fn($f) => !$f['suppressed']));
    $supp = count($findings) - count($violations);
    foreach ($violations as $f) {
        printf("%s:%d  %s  %s\n", $f['file'], $f['line'], $check, $f['msg']);
    }
    printf(
        "%s: %d violation(s), %d suppression(s) [root %s]\n",
        $check,
        count($violations),
        $supp,
        $root
    );
    exit($violations === [] ? 0 : 1);
}
