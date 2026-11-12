<?php
/**
 * LAW 2 — NO SCHEMA CHANGE AT RUNTIME (spine S1).
 *
 * SmartPRS 2 defect: ~49 tables and ~60 columns are created by controllers and
 * runtime helpers, so the production schema depends on which screens somebody
 * opened, and `dra_expiry` / `pcc_deadline` become VARCHAR on installs where a
 * runtime helper wins the race.
 *
 * Zero tolerance. No allow-list. DDL lives in database/migrations/ and nowhere
 * else. Comments and doc-blocks are not scanned; code is.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/** Schema builder calls that emit DDL. */
const GATE_DDL_SCHEMA = '/\bSchema\s*::\s*(create|createDatabase|table|drop|dropIfExists|dropColumns|dropAllTables|rename|dropDatabaseIfExists)\b/i';

/** Raw DDL in a SQL string, wherever that string appears. */
const GATE_DDL_SQL = '/\b(CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+(TABLE|DATABASE|SCHEMA|INDEX|VIEW)\b|\bADD\s+COLUMN\b|\bMODIFY\s+COLUMN\b/i';

function gate_no_runtime_ddl(string $root): array
{
    $findings = [];
    foreach (gate_php_files($root) as $rel => $abs) {
        if (str_starts_with($rel, 'database/migrations/')) {
            continue;
        }
        // Law 2 governs "application request and job code" (spine S1). A test
        // harness that builds a throwaway schema is neither, and it never ships.
        // Still reported, once per file, so the exemption stays in sight.
        if (str_starts_with($rel, 'tests/')) {
            if (preg_match(GATE_DDL_SQL, (string) file_get_contents($abs), $m)) {
                $findings[] = gate_find('no-runtime-ddl', $root, $abs, 1,
                    "test harness contains DDL ('{$m[0]}') — not application code, not gated", true);
            }
            continue;
        }
        $t = gate_tokens($abs);
        $n = count($t);

        // Rebuild the file with comments blanked, keeping line numbers intact,
        // so a doc-block mentioning ALTER TABLE is not a build failure but a
        // string, a heredoc or a call is.
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $text = in_array($t[$i]['id'], [T_COMMENT, T_DOC_COMMENT], true)
                ? str_repeat("\n", substr_count($t[$i]['text'], "\n"))
                : $t[$i]['text'];
            $parts = explode("\n", $text);
            $ln = $t[$i]['line'];
            foreach ($parts as $k => $p) {
                $lines[$ln + $k] = ($lines[$ln + $k] ?? '') . $p;
            }
        }

        foreach ($lines as $ln => $text) {
            if (preg_match(GATE_DDL_SCHEMA, $text, $m)) {
                $findings[] = gate_find('no-runtime-ddl', $root, $abs, $ln,
                    "{$m[0]} outside database/migrations/ — schema changes are migrations, never runtime");
            }
            if (preg_match(GATE_DDL_SQL, $text, $m)) {
                $findings[] = gate_find('no-runtime-ddl', $root, $abs, $ln,
                    "DDL statement '{$m[0]}' outside database/migrations/");
            }
        }

        // DB::statement()/DB::unprepared() carrying DDL assembled at runtime:
        // catches the concatenated and heredoc cases the line scan can miss.
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i]['id'] !== T_STRING || !in_array($t[$i]['text'], ['statement', 'unprepared'], true)) {
                continue;
            }
            $op = gate_sig($t, $i, -1);
            if ($op === null || $t[$op]['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            $open = gate_sig($t, $i, 1);
            if ($open === null || $t[$open]['id'] !== '(') {
                continue;
            }
            $args = gate_text($t, $open, gate_match_paren($t, $open));
            if (preg_match(GATE_DDL_SQL, $args, $m)) {
                $findings[] = gate_find('no-runtime-ddl', $root, $abs, $t[$i]['line'],
                    "DB::{$t[$i]['text']}() executing DDL ('{$m[0]}') outside database/migrations/");
            }
        }
    }

    // A line can match both patterns; report each location once.
    $seen = [];
    return array_values(array_filter($findings, static function (array $f) use (&$seen): bool {
        $k = $f['file'] . ':' . $f['line'];
        return !isset($seen[$k]) && ($seen[$k] = true);
    }));
}

gate_cli_main(__FILE__, 'no-runtime-ddl', 'gate_no_runtime_ddl');
