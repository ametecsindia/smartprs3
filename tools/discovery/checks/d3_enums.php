<?php
/**
 * D3 — enum violation scans.
 *
 * Generic, not a fixed list: every ENUM column in the live schema is read from
 * information_schema, its declared set parsed, and the distinct values actually
 * present counted. Anything present that the declared set does not permit is a
 * violation. The twelve known live violations (J-… §6.6.2 E1–E12) fall out of
 * this scan; so does any violation specific to this install.
 *
 * On a strict server the offending write THREW, so the evidence is missing data
 * (NULL / empty counts). On a non-strict server it was silently blanked. Both are
 * reported; schema/server.json says which server this is.
 *
 * Read-only. Standalone: php checks/d3_enums.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d3_enums(DscCtx $c): void
{
    // D3.0  Declared enum sets on THIS install — they differ between installs.
    $declared = dsc_q($c, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
                             FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = ? AND DATA_TYPE = 'enum'
                            ORDER BY TABLE_NAME, COLUMN_NAME", [$c->db], 'd3.0');
    dsc_write_csv($c, 'integrity/enums_declared.csv', $declared, 'none');

    $observed = [];
    $violations = [];
    $skipped = [];

    foreach ($declared as $col) {
        $t = (string) $col['TABLE_NAME'];
        $n = (string) $col['COLUMN_NAME'];
        $set = d3_parse_enum((string) $col['COLUMN_TYPE']);

        if (dsc_is_heavy($c, $t)) {
            $skipped[] = ['table' => $t, 'column' => $n, 'reason' => 'heavy table, --skip-heavy'];
            continue;
        }

        $rows = dsc_q($c, 'SELECT ' . dsc_id($n) . ' AS v, COUNT(*) AS c FROM ' . dsc_id($t)
            . ' GROUP BY ' . dsc_id($n) . ' ORDER BY c DESC, v', [], "enum:$t.$n");
        if ($rows === []) {
            continue;
        }

        foreach ($rows as $r) {
            $v = $r['v'];
            $isNull = $v === null;
            $isEmpty = !$isNull && (string) $v === '';
            $permitted = $isNull ? ($col['IS_NULLABLE'] === 'YES') : in_array((string) $v, $set, true);

            $rec = [
                'table' => $t,
                'column' => $n,
                'value' => $isNull ? '(NULL)' : (string) $v,
                'rows' => (int) $r['c'],
                'declared_set' => implode('|', $set),
                'permitted' => $permitted ? 'yes' : 'no',
                'class' => $isNull ? 'null' : ($isEmpty ? 'blanked-empty-string' : ($permitted ? 'ok' : 'not-in-declared-set')),
            ];
            $observed[] = $rec;
            if (!$permitted || $isEmpty) {
                $violations[] = $rec;
            }
        }
    }

    usort($observed, static fn($a, $b) => [$a['table'], $a['column'], $b['rows']] <=> [$b['table'], $b['column'], $a['rows']]);
    usort($violations, static fn($a, $b) => [$b['rows'], $a['table'], $a['column']] <=> [$a['rows'], $b['table'], $b['column']]);

    dsc_write_csv($c, 'integrity/enum_observed.csv', $observed, 'none');
    dsc_write_csv($c, 'integrity/enum_violations.csv', $violations, 'none');
    if ($skipped) {
        dsc_write_csv($c, 'integrity/enum_skipped.csv', $skipped, 'none');
    }

    if ($violations) {
        $affected = array_sum(array_column($violations, 'rows'));
        $cols = array_values(array_unique(array_map(static fn($r) => $r['table'] . '.' . $r['column'], $violations)));
        $c->finding('BLOCKER', 'integrity',
            count($cols) . ' enum columns hold values their declared type does not permit (' . $affected . ' rows)',
            'R-XXIX-013: the target reference-data vocabulary is the union of declared and observed '
            . 'values, every observed value mapped explicitly, and the load fails on an unmapped value. '
            . 'Blank/NULL classes are R-XXIX-014 cases: load as UNKNOWN_FROM_MIGRATION, never invent a value. '
            . 'Columns: ' . implode(', ', array_slice($cols, 0, 12)),
            ['columns' => $cols, 'rows_affected' => $affected]);
    } else {
        $c->finding('INFO', 'integrity', 'No enum violations found',
            'Either this install never hit them, or a strict server threw on every attempt and the '
            . 'values were never stored — check the NULL/blank counts in integrity/enum_observed.csv.');
    }

    // D3.2  E8: the "+1 km" / "+2 km" geofence options are impossible to save and become NULL.
    if (dsc_has_cols($c, 'employees', ['geo_outside'])) {
        $r = dsc_q($c, "SELECT COUNT(*) AS total, SUM(geo_outside IS NULL) AS nulls,
                               SUM(geo_outside = '') AS blanks
                          FROM employees", [], 'd3.2');
        dsc_write_csv($c, 'integrity/e8_geo_outside.csv', $r, 'none');
        $nulls = (int) ($r[0]['nulls'] ?? 0);
        if ($nulls > 0) {
            $c->finding('HIGH', 'integrity', "$nulls employees have a NULL geo_outside",
                'E8: the Employee form offers "+1 km"/"+2 km" grace bands that the ENUM cannot store, so '
                . 'they were discarded silently. The geofence grace band was never recorded for these people '
                . 'and cannot be recovered from the database — it must be re-stated by the customer.');
        }
    }

    // D3.3  E9: the form still offers PCC 'Overdue', retired by the 27 Aug migration.
    if (dsc_has_cols($c, 'employees', ['pcc_status'])) {
        $sel = 'pcc_status, COUNT(*) AS rows_';
        if (dsc_has_cols($c, 'employees', ['pcc_deadline'])) {
            $sel .= ', SUM(pcc_deadline IS NOT NULL) AS with_deadline';
        }
        $r = dsc_q($c, "SELECT $sel FROM employees GROUP BY pcc_status ORDER BY rows_ DESC", [], 'd3.3');
        dsc_write_csv($c, 'integrity/e9_pcc_status.csv', $r, 'none');
    }

    // D3.4  Case normalisation — dra_status / pcc_status / training_programs.status carry
    //       case-inconsistent vocabularies that a case-sensitive filter will miss.
    $case = [];
    foreach ([['employees', 'dra_status'], ['employees', 'pcc_status'], ['training_programs', 'status'],
              ['letters', 'status'], ['recruitment', 'stage'], ['documents', 'status']] as [$t, $n]) {
        if (!dsc_has_cols($c, $t, [$n]) || dsc_is_heavy($c, $t)) {
            continue;
        }
        $q = dsc_id($n);
        foreach (dsc_q($c, "SELECT $q AS v, COUNT(*) AS c FROM " . dsc_id($t)
            . " GROUP BY $q ORDER BY c DESC", [], "case:$t.$n") as $r) {
            $v = (string) ($r['v'] ?? '');
            $case[] = ['table' => $t, 'column' => $n, 'value' => $r['v'] === null ? '(NULL)' : $v,
                'rows' => (int) $r['c'], 'is_lowercase' => $v === strtolower($v) ? 'yes' : 'no'];
        }
    }
    dsc_write_csv($c, 'integrity/case_vocabularies.csv', $case, 'none');
    $mixed = [];
    foreach ($case as $r) {
        $mixed[$r['table'] . '.' . $r['column']][$r['is_lowercase']] = true;
    }
    foreach ($mixed as $k => $v) {
        if (count($v) > 1) {
            $c->finding('MEDIUM', 'integrity', "Mixed-case vocabulary in $k",
                'Case-sensitive filters miss some of these rows today. The migration must normalise '
                . 'case explicitly and record the mapping (J-… §9 Q2, TRANSFORM employees (c)).');
        }
    }

    dsc_finish($c, 'd3_enums');
}

/** enum('a','b','c') → ['a','b','c'] */
function d3_parse_enum(string $columnType): array
{
    if (!preg_match("/^enum\((.*)\)$/is", trim($columnType), $m)) {
        return [];
    }
    $out = [];
    if (preg_match_all("/'((?:[^']|'')*)'/", $m[1], $mm)) {
        foreach ($mm[1] as $v) {
            $out[] = str_replace("''", "'", $v);
        }
    }
    return $out;
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d3_enums.php', 'd3_enums');
}
