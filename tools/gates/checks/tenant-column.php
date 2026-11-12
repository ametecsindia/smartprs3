<?php
/**
 * LAW 6 — TENANCY IS STRUCTURAL, NOT CLERICAL (spine S1, S16).
 *
 * SmartPRS 2 defect: the declared tenancy scope is inert because the column it
 * filters on does not exist on most tables, and four different column names are
 * used for the same concept where it does. Cross-tenant reads are one missing
 * WHERE clause away, and there is no database constraint that would stop them.
 *
 * Every table created outside tools/gates/allow/platform-tables.php must declare
 * a NOT NULL tenant_id, and every unique index on such a table must lead with it
 * — otherwise "employee code is unique" quietly means "unique across all
 * customers".
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

const GATE_TENANT_COL = '/->\s*(foreignId|foreignIdFor|unsignedBigInteger|bigInteger|unsignedInteger|integer|uuid|foreignUuid|ulid|char|string)\s*\(\s*[\'"]tenant_id[\'"]/';

function gate_tenant_column(string $root): array
{
    $platform = array_flip(gate_allow('platform-tables'));
    $findings = [];

    foreach (gate_php_files($root, 'database/migrations') as $abs) {
        $t = gate_tokens($abs);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            if ($t[$i]['id'] !== T_STRING || $t[$i]['text'] !== 'create') {
                continue;
            }
            $dc = gate_sig($t, $i, -1);
            $cls = $dc === null ? null : gate_sig($t, $dc, -1);
            if ($dc === null || $cls === null || $t[$dc]['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            if (!str_ends_with(ltrim($t[$cls]['text'], '\\'), 'Schema')) {
                continue;
            }

            $open = gate_sig($t, $i, 1);
            if ($open === null || $t[$open]['id'] !== '(') {
                continue;
            }
            $close = gate_match_paren($t, $open);
            $name = gate_sig($t, $open, 1);
            if ($name === null || $t[$name]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                $findings[] = gate_find('tenant-column', $root, $abs, $t[$i]['line'],
                    'Schema::create() with a non-literal table name — the tenancy gate cannot verify it');
                continue;
            }
            $table = gate_str_value($t[$name]['text']);
            $line = $t[$i]['line'];
            if (isset($platform[$table])) {
                $findings[] = gate_find('tenant-column', $root, $abs, $line,
                    "`$table` allow-listed as platform-scoped — asserts no tenant owns a row here", true);
                continue;
            }

            $body = gate_text($t, $open, $close);

            if (!preg_match(GATE_TENANT_COL, $body)) {
                $findings[] = gate_find('tenant-column', $root, $abs, $line,
                    "table `$table` has no tenant_id column — every business table carries one (Law 6),"
                    . ' or it goes in tools/gates/allow/platform-tables.php');
            } elseif (preg_match('/' . trim(GATE_TENANT_COL, '/') . '[^;]*->\s*nullable\s*\(/', $body)) {
                $findings[] = gate_find('tenant-column', $root, $abs, $line,
                    "table `$table` declares tenant_id as nullable — there is no null-tenant state");
            }

            foreach (gate_unique_indexes($body) as $args) {
                if ($args === '') {
                    $findings[] = gate_find('tenant-column', $root, $abs, $line,
                        "table `$table` has a single-column unique index that does not include tenant_id"
                        . ' — make it ->unique([\'tenant_id\', ...])');
                    continue;
                }
                if (!preg_match('/^\[\s*[\'"]tenant_id[\'"]/', $args)) {
                    $findings[] = gate_find('tenant-column', $root, $abs, $line,
                        "table `$table` unique index `$args` does not lead with tenant_id"
                        . ' — uniqueness must be per tenant');
                }
            }
        }
    }

    return $findings;
}

/** Argument text of every ->unique(...) in a create() block; '' for a chained ->unique(). */
function gate_unique_indexes(string $body): array
{
    if (!preg_match_all('/->\s*unique\s*\(/', $body, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $out = [];
    foreach ($m[0] as [$match, $off]) {
        $start = $off + strlen($match);
        $depth = 1;
        $i = $start;
        for ($len = strlen($body); $i < $len && $depth > 0; $i++) {
            $depth += match ($body[$i]) { '(', '[' => 1, ')', ']' => -1, default => 0 };
        }
        // Columns only; a trailing index-name argument is not part of the key.
        $args = trim(substr($body, $start, $i - $start - 1));
        $out[] = str_contains($args, ']') ? substr($args, 0, strpos($args, ']') + 1) : $args;
    }
    return $out;
}

gate_cli_main(__FILE__, 'tenant-column', 'gate_tenant_column');
