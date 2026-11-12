<?php
/**
 * D2 — exact row counts, per-tenant counts, date ranges, reconciliation baselines.
 *
 * information_schema.TABLE_ROWS is an InnoDB ESTIMATE and must never be cited in a
 * reconciliation report (R-XXIX-012). This check does exact COUNT(*) unless
 * --counts=estimate is given, and records which mode produced each number.
 *
 * Cost warning: COUNT(*) on a large attendance_logs is the expensive part of the
 * whole pack. See RUNBOOK.md "When a query is too slow".
 *
 * Read-only. Standalone: php checks/d2_counts.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d2_counts(DscCtx $c): void
{
    $exact = (string) ($c->opt['counts'] ?? 'exact') !== 'estimate';
    $counts = [];
    $tenantCounts = [];
    $ranges = [];

    foreach (dsc_tables($c) as $t) {
        $est = dsc_est_rows($c, $t);
        $heavy = dsc_is_heavy($c, $t);

        if ($exact && !$heavy) {
            $n = dsc_val($c, 'SELECT COUNT(*) FROM ' . dsc_id($t), [], 'count:' . $t, null);
            $mode = $n === null ? 'failed' : 'exact';
        } else {
            $n = $est;
            $mode = $heavy ? 'estimate (skipped as heavy)' : 'estimate';
        }
        $counts[] = ['table' => $t, 'rows' => (int) $n, 'mode' => $mode, 'innodb_estimate' => $est];

        $cols = dsc_cols($c, $t);

        // Per-tenant counts. NULL and 0 are BOTH live platform sentinels (J-… PO-13) and a
        // NULL-tenant row is invisible to every ->when($tid,…) filter, so they are reported apart.
        foreach (['tenant_id', 'company_id'] as $tc) {
            if (!isset($cols[$tc]) || $heavy) {
                continue;
            }
            $rows = dsc_q($c, 'SELECT ' . dsc_id($tc) . ' AS k, COUNT(*) AS c FROM ' . dsc_id($t)
                . ' GROUP BY ' . dsc_id($tc) . ' ORDER BY ' . dsc_id($tc), [], "tenantcount:$t.$tc");
            foreach ($rows as $r) {
                $tenantCounts[] = [
                    'table' => $t,
                    'column' => $tc,
                    'value' => $r['k'] === null ? 'NULL' : (string) $r['k'],
                    'rows' => (int) $r['c'],
                    'sentinel' => $r['k'] === null ? 'NULL-sentinel' : ((string) $r['k'] === '0' ? 'ZERO-sentinel' : ''),
                ];
            }
        }

        // Date ranges for every date-typed column: sizes retention cut-offs and proves
        // which months of history actually exist.
        if ($heavy) {
            continue;
        }
        foreach ($cols as $name => $info) {
            if (!in_array(strtolower((string) $info['DATA_TYPE']), ['date', 'datetime', 'timestamp'], true)) {
                continue;
            }
            $q = dsc_id($name);
            $r = dsc_q($c, "SELECT MIN($q) AS lo, MAX($q) AS hi, SUM($q IS NULL) AS nulls
                              FROM " . dsc_id($t), [], "range:$t.$name");
            if ($r) {
                $ranges[] = ['table' => $t, 'column' => $name, 'data_type' => $info['DATA_TYPE'],
                    'min' => $r[0]['lo'], 'max' => $r[0]['hi'], 'nulls' => (int) $r[0]['nulls']];
            }
        }
    }

    dsc_write_csv($c, 'profile/row_counts.csv', $counts, 'none');
    dsc_write_csv($c, 'profile/tenant_counts.csv', $tenantCounts, 'none');
    dsc_write_csv($c, 'profile/date_ranges.csv', $ranges, 'none');

    // NULL-tenant rows are a migration hazard in their own right.
    $nullTenant = array_values(array_filter($tenantCounts,
        static fn($r) => $r['column'] === 'tenant_id' && $r['sentinel'] !== '' && $r['rows'] > 0));
    if ($nullTenant) {
        $total = array_sum(array_column($nullTenant, 'rows'));
        $c->finding('HIGH', 'profile', "$total rows carry a NULL or 0 tenant sentinel",
            'These rows are invisible to SmartPRS 2\'s ->when($tid, …) filters and survive every '
            . 'per-tenant purge. The migration must decide, per table, whether they are platform-owned '
            . 'or orphaned tenant data (J-… PO-13). See profile/tenant_counts.csv.',
            ['tables' => array_values(array_unique(array_column($nullTenant, 'table')))]);
    }

    // Reconciliation baselines: the numbers every later reconciliation report is checked against.
    $b = static fn(string $sql, string $step) => (int) dsc_val($c, $sql, [], $step, -1);
    $baseline = [
        'counts_mode' => $exact ? 'exact' : 'estimate',
        'collected_at_utc' => gmdate('c'),
        'tenants' => dsc_has_table($c, 'tenants') ? $b('SELECT COUNT(*) FROM tenants', 'b1') : null,
        'tenants_live' => dsc_has_cols($c, 'tenants', ['deleted_at'])
            ? $b('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL', 'b2') : null,
        'companies' => dsc_has_table($c, 'companies') ? $b('SELECT COUNT(*) FROM companies', 'b3') : null,
        'employees_total' => dsc_has_table($c, 'employees') ? $b('SELECT COUNT(*) FROM employees', 'b4') : null,
        'employees_live' => dsc_has_cols($c, 'employees', ['deleted_at'])
            ? $b('SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL', 'b5') : null,
        'employees_archived' => dsc_has_cols($c, 'employees', ['archived_at'])
            ? $b('SELECT COUNT(*) FROM employees WHERE archived_at IS NOT NULL', 'b6') : null,
        'employees_live_and_unarchived' => dsc_has_cols($c, 'employees', ['deleted_at', 'archived_at'])
            ? $b('SELECT COUNT(*) FROM employees WHERE deleted_at IS NULL AND archived_at IS NULL', 'b7') : null,
        'users' => dsc_has_table($c, 'users') ? $b('SELECT COUNT(*) FROM users', 'b8') : null,
        'attendance_logs' => dsc_has_table($c, 'attendance_logs') && !dsc_is_heavy($c, 'attendance_logs')
            ? $b('SELECT COUNT(*) FROM attendance_logs', 'b9') : null,
        'payroll_runs' => dsc_has_table($c, 'payroll_runs') ? $b('SELECT COUNT(*) FROM payroll_runs', 'b10') : null,
        'payslips' => dsc_has_table($c, 'payslips') ? $b('SELECT COUNT(*) FROM payslips', 'b11') : null,
        'leaves' => dsc_has_table($c, 'leaves') ? $b('SELECT COUNT(*) FROM leaves', 'b12') : null,
        'documents' => dsc_has_table($c, 'documents') ? $b('SELECT COUNT(*) FROM documents', 'b13') : null,
        'loans' => dsc_has_table($c, 'loans') ? $b('SELECT COUNT(*) FROM loans', 'b14') : null,
        'commissions' => dsc_has_table($c, 'commissions') ? $b('SELECT COUNT(*) FROM commissions', 'b15') : null,
        'activity_logs' => dsc_has_table($c, 'activity_logs') ? $b('SELECT COUNT(*) FROM activity_logs', 'b16') : null,
        'total_bytes' => (int) dsc_val($c, 'SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES
                                             WHERE TABLE_SCHEMA = ?', [$c->db], 'b17', 0),
    ];

    // Per-tenant headcount: the figure the customer will recognise and sign.
    if (dsc_has_cols($c, 'employees', ['tenant_id'])) {
        $where = dsc_has_cols($c, 'employees', ['deleted_at']) ? ' WHERE deleted_at IS NULL' : '';
        $baseline['headcount_per_tenant'] = dsc_q($c,
            'SELECT tenant_id, COUNT(*) AS employees FROM employees' . $where
            . ' GROUP BY tenant_id ORDER BY tenant_id', [], 'b18');
    }
    dsc_write_json($c, 'profile/baseline.json', $baseline);

    $big = array_values(array_filter($counts, static fn($r) => $r['rows'] > 1000000));
    if ($big) {
        $c->finding('MEDIUM', 'profile', count($big) . ' tables exceed one million rows',
            'These size the cutover window (§XXIX.8) and are the tables to run against a replica '
            . 'rather than live. ' . implode(', ', array_map(
                static fn($r) => $r['table'] . '=' . number_format($r['rows']), array_slice($big, 0, 8))),
            ['tables' => $big]);
    }
    $c->finding('INFO', 'profile',
        'Baseline: ' . ($baseline['employees_live'] ?? '?') . ' live employees, '
        . ($baseline['payslips'] ?? '?') . ' payslips, ' . ($baseline['payroll_runs'] ?? '?') . ' payroll runs',
        'These are the reconciliation baselines. Every later count is compared against profile/baseline.json.',
        $baseline);

    if (!$exact) {
        $c->finding('HIGH', 'profile', 'Counts are ESTIMATES, not exact',
            'R-XXIX-012 requires exact COUNT(*) for reconciliation. This pack was collected with '
            . '--counts=estimate and cannot pass Gate G-1 on its own. Re-run against a replica.');
    }

    dsc_finish($c, 'd2_counts');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d2_counts.php', 'd2_counts');
}
