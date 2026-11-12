<?php
/**
 * D9 — evidence queries for each confirmed live defect.
 *
 * Each block answers one contamination question with a count and a capped sample,
 * so blast radius is MEASURED rather than assumed. Output feeds the Contamination
 * Register (R-XXIX-028): one row per class, with a disposition field that starts
 * empty and must be filled by the Product Owner before Gate G-3.
 *
 * Read-only. Standalone: php checks/d9_defects.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d9_defects(DscCtx $c): void
{
    $reg = [];   // contamination register rows
    $add = static function (array &$reg, string $id, string $class, $count, string $money, string $scope, string $ref) {
        $reg[] = ['id' => $id, 'contamination_class' => $class, 'count' => $count,
            'money_at_risk' => $money, 'scope' => $scope, 'evidence' => $ref, 'disposition' => ''];
    };

    /* ===== DEF-P01 · fabricated 'paid' payroll runs ===================== */
    if (dsc_has_table($c, 'payroll_runs')) {
        $rc = array_keys(dsc_cols($c, 'payroll_runs'));
        $h = static fn(string $x) => in_array($x, $rc, true);

        $sel = 'r.id' . ($h('tenant_id') ? ', r.tenant_id' : '') . ($h('company_id') ? ', r.company_id' : '')
            . ($h('month') ? ', r.month' : '') . ($h('cycle_label') ? ', r.cycle_label' : '')
            . ($h('status') ? ', r.status' : '')
            . ($h('employees_count') ? ', r.employees_count' : '') . ($h('net_total') ? ', r.net_total' : '')
            . ($h('period_start') ? ', r.period_start, (r.period_start IS NULL) AS h_no_period' : '')
            . ($h('calc_note') ? ", (r.calc_note IS NULL OR r.calc_note = '') AS h_no_calc_note" : '')
            . ($h('created_at') ? ', r.created_at' : '');
        if (dsc_has_cols($c, 'payslips', ['run_id'])) {
            $sel .= ', (SELECT COUNT(*) FROM payslips p WHERE p.run_id = r.id) AS slip_count';
        }
        if (dsc_has_cols($c, 'payslips', ['run_id', 'earnings'])) {
            $sel .= ', (SELECT COUNT(*) FROM payslips p WHERE p.run_id = r.id
                          AND (p.earnings IS NULL OR JSON_LENGTH(p.earnings) <= 1)) AS h_thin_slips';
        }
        $runs = dsc_q($c, "SELECT $sel FROM payroll_runs r ORDER BY r.id", [], 'd9.p01');
        dsc_write_csv($c, 'defects/payroll_runs_heuristics.csv', $runs, 'none');

        // Fabrication signature: no period_start, no calc_note, thin slips.
        $suspect = array_values(array_filter($runs, static fn($r) =>
            (int) ($r['h_no_period'] ?? 0) === 1 || (int) ($r['h_no_calc_note'] ?? 0) === 1
            || ((int) ($r['h_thin_slips'] ?? 0) > 0 && (int) ($r['slip_count'] ?? 0) > 0)));
        $paidSuspect = array_values(array_filter($suspect, static fn($r) => ($r['status'] ?? '') === 'paid'));
        dsc_write_csv($c, 'defects/payroll_runs_suspected_fabricated.csv', $suspect, 'none');

        $money = 0.0;
        foreach ($paidSuspect as $r) {
            $money += (float) ($r['net_total'] ?? 0);
        }
        $add($reg, 'DEF-P01', 'Fabricated payroll runs (ensurePayrollHistory)', count($suspect),
            number_format($money, 2), count($paidSuspect) . " marked 'paid'",
            'defects/payroll_runs_suspected_fabricated.csv');

        if ($paidSuspect) {
            $c->finding('BLOCKER', 'defects',
                count($paidSuspect) . " payroll runs are marked 'paid' AND carry the fabrication signature",
                "A 'paid' run permanently blocks a real run for that month (PayrollGenController:2362-2365). "
                . 'These runs were inserted by ensurePayrollHistory() on an /app/data load, computed from full '
                . 'CTC with no LOP, no attendance, no components and no recoveries. §XXIX.4.1: they must not be '
                . 'migrated as payroll history, and the customer must be told which months have no real run.',
                ['runs' => count($paidSuspect)]);
        }

        // P01c  Months where a 'paid' run exists at all.
        if ($h('status') && $h('month')) {
            $g = implode(', ', array_map('dsc_id', array_values(array_intersect(['tenant_id', 'company_id', 'month'], $rc))));
            dsc_write_csv($c, 'defects/paid_runs_by_month.csv',
                dsc_q($c, "SELECT $g, COUNT(*) AS runs, SUM(status = 'paid') AS paid_runs
                             FROM payroll_runs GROUP BY $g HAVING SUM(status = 'paid') > 0 ORDER BY $g", [], 'd9.p01c'), 'none');
        }
    }

    // P01b  Payslips whose net is exactly ctc/12 with no deduction lines — the signature.
    if (dsc_has_cols($c, 'payslips', ['employee_id', 'net']) && dsc_has_cols($c, 'employees', ['ctc'])) {
        $extra = dsc_has_cols($c, 'payslips', ['earnings'])
            ? ', JSON_LENGTH(p.earnings) AS earning_lines, JSON_LENGTH(p.deductions) AS deduction_lines' : '';
        $rows = dsc_q($c, "SELECT p.id, p.employee_id, p.month, p.gross, p.net, e.ctc,
                                  ROUND(e.ctc / 12, 2) AS ctc_over_12,
                                  ABS(p.net - ROUND(e.ctc / 12, 2)) AS delta$extra
                             FROM payslips p JOIN employees e ON e.id = p.employee_id
                            WHERE e.ctc IS NOT NULL AND e.ctc > 0
                              AND ABS(p.net - ROUND(e.ctc / 12, 2)) < 1.00
                            ORDER BY p.id", [], 'd9.p01b');
        dsc_write_csv($c, 'defects/payslips_net_equals_ctc_over_12.csv', $rows);
        $total = (int) dsc_val($c, 'SELECT COUNT(*) FROM payslips', [], 'd9.p01b2', 0);
        $add($reg, 'DEF-P01b', 'Payslips whose net is exactly CTC/12 (no LOP, no deductions)', count($rows),
            '', "of $total payslips", 'defects/payslips_net_equals_ctc_over_12.csv');
        if (count($rows) > 0) {
            $c->finding('BLOCKER', 'defects',
                count($rows) . " of $total payslips have net exactly equal to CTC/12",
                'The fabrication signature. A real payslip carries LOP, attendance, components and '
                . 'recoveries; these carry none. Nothing derived from them is payroll history.',
                ['payslips' => count($rows), 'of' => $total]);
        }
    }

    /* ===== DEF-P03 · commissions stranded by the enum mismatch ========== */
    if (dsc_has_cols($c, 'commissions', ['status'])) {
        $amt = dsc_has_cols($c, 'commissions', ['amount']) ? ', SUM(amount) AS total_amount' : '';
        $rows = dsc_q($c, "SELECT status, COUNT(*) AS c, MIN(created_at) AS lo, MAX(created_at) AS hi$amt
                             FROM commissions GROUP BY status ORDER BY c DESC", [], 'd9.p03');
        dsc_write_csv($c, 'defects/commission_status.csv', $rows, 'none');

        $declared = (string) dsc_val($c, "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                                           WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'commissions'
                                             AND COLUMN_NAME = 'status'", [$c->db], 'd9.p03b', '');
        $stranded = 0;
        $strandedMoney = 0.0;
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'approved') {
                $stranded += (int) $r['c'];
                $strandedMoney += (float) ($r['total_amount'] ?? 0);
            }
        }
        $add($reg, 'DEF-P03', 'Commissions never reaching a payslip (enum mismatch)', $stranded,
            number_format($strandedMoney, 2), "declared type: $declared", 'defects/commission_status.csv');
        if ($stranded > 0) {
            $c->finding('BLOCKER', 'defects',
                "$stranded commissions are not in status 'approved' (₹" . number_format($strandedMoney, 2) . ')',
                "The migration declares ENUM('provisional','confirmed','reversed'); every code path writes "
                . "'pending'/'approved'/'rejected'. Declared type here: $declared. On a strict server the "
                . 'insert threw; on a non-strict one the status was blanked. Either way no commission ever '
                . 'reaches a payslip. §XXIX.4.4 — this is unpaid earnings, not a data-quality issue.',
                ['stranded' => $stranded, 'declared' => $declared]);
        }

        if (dsc_has_cols($c, 'commissions', ['gross_amount', 'amount'])) {
            $n = (int) dsc_val($c, 'SELECT COUNT(*) FROM commissions
                                     WHERE gross_amount IS NOT NULL AND amount = gross_amount', [], 'd9.p03c', 0);
            $c->finding($n > 0 ? 'HIGH' : 'INFO', 'defects',
                "$n commissions where amount equals gross_amount",
                'PO-5: ApprovalService states amount is the NET payable, but rows predating rev 83 may hold '
                . 'a gross. Where the two are equal there is no discriminator, so the customer must state '
                . 'which convention applied in which period.');
        }
    }

    /* ===== Cross-midnight attendance recorded as two days =============== */
    if (dsc_has_cols($c, 'attendance_logs', ['emp_code', 'log_date']) && !dsc_is_heavy($c, 'attendance_logs')) {
        $ac = array_keys(dsc_cols($c, 'attendance_logs'));
        if (in_array('direction', $ac, true)) {
            $g = in_array('tenant_id', $ac, true) ? 'a.tenant_id, ' : '';
            $rows = dsc_q($c, "SELECT {$g}a.emp_code, a.log_date,
                                      COUNT(*) AS punches,
                                      SUM(a.direction = 'in') AS ins,
                                      SUM(a.direction = 'out') AS outs
                                 FROM attendance_logs a
                                GROUP BY {$g}a.emp_code, a.log_date
                               HAVING (ins = 0 AND outs > 0) OR (ins > 0 AND outs = 0)
                                ORDER BY a.log_date DESC", [], 'd9.xm');
            dsc_write_csv($c, 'defects/cross_midnight_orphan_punches.csv', dsc_sample($c, $rows));
            $orphanOut = count(array_filter($rows, static fn($r) => (int) $r['ins'] === 0));
            $add($reg, 'ATT-XM', 'Employee-days with punches in one direction only (night-shift signature)',
                count($rows), '', "$orphanOut are OUT-only", 'defects/cross_midnight_orphan_punches.csv');
            if ($rows) {
                $c->finding('HIGH', 'defects',
                    count($rows) . ' employee-days have punches in one direction only (' . $orphanOut . ' OUT-only)',
                    'No ingest path is shift-aware: a 22:00–06:00 shift yields log_date D for the IN and D+1 '
                    . 'for the OUT, and presentDays() counts TWO present days for one shift. Cross-check '
                    . 'against blobs/shifts.csv: if any shift is cross-midnight here, every payroll period '
                    . 'covering it overstates present days. §XXIX.4.5.',
                    ['employee_days' => count($rows), 'out_only' => $orphanOut]);
            }
        }

        // Demo / fabricated attendance. Rows written before rev 51 are tagged 'biometric'
        // and are indistinguishable from real device punches.
        if (in_array('source', $ac, true)) {
            $rows = dsc_q($c, 'SELECT source, COUNT(*) AS c, MIN(log_date) AS lo, MAX(log_date) AS hi
                                 FROM attendance_logs GROUP BY source ORDER BY c DESC', [], 'd9.src');
            dsc_write_csv($c, 'defects/attendance_by_source.csv', $rows, 'none');
            $demo = 0;
            $total = 0;
            foreach ($rows as $r) {
                $total += (int) $r['c'];
                if (($r['source'] ?? '') === 'demo') {
                    $demo = (int) $r['c'];
                }
            }
            $add($reg, 'ATT-DEMO', "Attendance punches tagged source='demo'", $demo, '',
                "of $total punches", 'defects/attendance_by_source.csv');
            $c->finding($demo > 0 ? 'BLOCKER' : 'HIGH', 'defects',
                "$demo of $total attendance punches are tagged source='demo'",
                'This filter is the ONLY discriminator, and it is incomplete: rows fabricated before rev 51 '
                . "were tagged source='biometric' and the purge command's own docblock records that they are "
                . 'indistinguishable from real device punches. The demo exclusion must be by explicit filter '
                . 'plus a customer-confirmed date cut-off, never by hope (migration blocker 5).',
                ['demo' => $demo, 'total' => $total, 'by_source' => $rows]);
        }
    } elseif (dsc_has_table($c, 'attendance_logs')) {
        $c->note('d9', 'attendance_logs skipped as heavy — run these against a replica');
    }

    /* ===== PCC status values destroyed by the 27 Aug migration ========== */
    if (dsc_has_cols($c, 'employees', ['pcc_status'])) {
        $extra = dsc_has_cols($c, 'employees', ['pcc_deadline'])
            ? ', SUM(pcc_deadline IS NOT NULL) AS with_deadline,
                 SUM(pcc_deadline IS NOT NULL AND pcc_deadline < CURDATE()) AS deadline_passed' : '';
        $rows = dsc_q($c, "SELECT pcc_status, COUNT(*) AS c$extra FROM employees
                            GROUP BY pcc_status ORDER BY c DESC", [], 'd9.pcc');
        dsc_write_csv($c, 'defects/pcc_status.csv', $rows, 'none');

        $ran = dsc_has_table($c, 'migrations')
            ? dsc_q($c, "SELECT migration, batch FROM migrations WHERE migration LIKE '%2026_08_27_000100%'", [], 'd9.pccm')
            : [];
        $passed = 0;
        foreach ($rows as $r) {
            $passed += (int) ($r['deadline_passed'] ?? 0);
        }
        $add($reg, 'PCC-OVERDUE', "PCC 'overdue' values rolled back to 'pending' by migration",
            $ran ? 'migration ran' : 'migration NOT run', '', "$passed employees are past their PCC deadline",
            'defects/pcc_status.csv');
        if ($ran && $passed > 0) {
            $c->finding('HIGH', 'defects',
                "$passed employees are past their PCC deadline but no longer carry an 'overdue' status",
                'The 27 Aug migration retired "overdue" on the stated basis that Compliance Alerts derives it '
                . 'from PCC Deadline / PCC Expiry. It does not — pcc_deadline is selected and never evaluated '
                . '(F-… DEFECT-F-16). These people are non-compliant and invisible. §XXIX.4.6.',
                ['employees' => $passed]);
        }
    }

    /* ===== Audit chain breaks ========================================== */
    if (dsc_has_table($c, 'activity_logs')) {
        $alc = array_keys(dsc_cols($c, 'activity_logs'));
        $hasChain = in_array('row_hash', $alc, true) && in_array('prev_hash', $alc, true);
        if (!$hasChain) {
            $add($reg, 'AUD-CHAIN', 'Audit hash chain columns absent', 'n/a', '', '', '');
            $c->finding('HIGH', 'defects', 'activity_logs has no prev_hash/row_hash columns on this install',
                'The chain columns are added at run time and never verified on read (J-… PO-18). Without them '
                . 'the existing audit trail has no tamper-evidence at all, and migrating it as "audit" would '
                . 'overstate its evidentiary value.');
        } else {
            $r = dsc_q($c, "SELECT COUNT(*) AS total,
                                   SUM(row_hash IS NULL OR row_hash = '') AS unchained_rows,
                                   SUM(prev_hash IS NULL OR prev_hash = '') AS no_prev,
                                   MIN(id) AS lo, MAX(id) AS hi
                              FROM activity_logs", [], 'd9.aud');
            dsc_write_csv($c, 'defects/audit_chain_summary.csv', $r, 'none');
            $breaks = dsc_q($c, "SELECT id, created_at, action, entity_type, entity_id
                                   FROM activity_logs WHERE row_hash IS NULL OR row_hash = ''
                                  ORDER BY id", [], 'd9.aud2');
            dsc_write_csv($c, 'defects/audit_chain_breaks.csv', dsc_sample($c, $breaks));
            $n = (int) ($r[0]['unchained_rows'] ?? 0);
            $add($reg, 'AUD-CHAIN', 'Audit rows with no row_hash (each resets the chain to GENESIS)', $n,
                '', 'of ' . (int) ($r[0]['total'] ?? 0) . ' rows', 'defects/audit_chain_breaks.csv');
            if ($n > 0) {
                $c->finding('BLOCKER', 'defects', "$n audit rows carry no row_hash",
                    'Audit::verify() treats a null row_hash as legacy, SKIPS it and RESETS the chain to '
                    . 'GENESIS — destroying tamper-evidence for everything after each break. The chain must be '
                    . 'verified segment by segment before migration, and every segment after the first break '
                    . 'declared unverifiable. §XXIX.4.7.',
                    ['unchained' => $n, 'first_break_id' => $breaks[0]['id'] ?? null]);
            }
        }
    }

    /* ===== Archived employees consuming seats ========================== */
    if (dsc_has_cols($c, 'employees', ['archived_at'])) {
        $g = dsc_has_cols($c, 'employees', ['tenant_id']) ? 'tenant_id' : null;
        $del = dsc_has_cols($c, 'employees', ['deleted_at']) ? ' AND deleted_at IS NULL' : '';
        $rows = dsc_q($c, 'SELECT ' . ($g ? 'tenant_id, ' : '') . 'COUNT(*) AS archived_not_deleted
                             FROM employees WHERE archived_at IS NOT NULL' . $del
            . ($g ? ' GROUP BY tenant_id ORDER BY tenant_id' : ''), [], 'd9.seats');
        dsc_write_csv($c, 'defects/archived_employees.csv', $rows, 'none');
        $archived = array_sum(array_map(static fn($r) => (int) $r['archived_not_deleted'], $rows));

        // What does the subscription think it is paying for?
        $seats = [];
        if (dsc_has_table($c, 'subscriptions')) {
            $sc = array_keys(dsc_cols($c, 'subscriptions'));
            $seatCol = null;
            foreach (['seats', 'users', 'user_count', 'licensed_users', 'quantity'] as $cand) {
                if (in_array($cand, $sc, true)) {
                    $seatCol = $cand;
                    break;
                }
            }
            if ($seatCol && in_array('tenant_id', $sc, true)) {
                $seats = dsc_q($c, 'SELECT tenant_id, ' . dsc_id($seatCol) . ' AS seats FROM subscriptions
                                     ORDER BY tenant_id', [], 'd9.seats2');
                dsc_write_csv($c, 'defects/subscription_seats.csv', $seats, 'none');
            }
        }
        $add($reg, 'SEATS', 'Archived-but-not-deleted employees (seat consumption)', $archived, '',
            $seats ? 'compare against defects/subscription_seats.csv' : 'no seat column found',
            'defects/archived_employees.csv');
        if ($archived > 0) {
            $c->finding('HIGH', 'defects', "$archived employees are archived but not deleted",
                'Archive is a separate dimension from delete in SmartPRS 2, and the headcount/seat counters '
                . 'do not agree on which dimension they count. These people may be billed for. The migration '
                . 'must split the archive dimension from the delete dimension into an explicit lifecycle '
                . 'state (J-… TRANSFORM employees (d)) and the customer must confirm the licensed headcount.',
                ['archived' => $archived]);
        }
    }

    /* ===== Corrections approved against a locked period ================= */
    if (dsc_has_cols($c, 'attendance_corrections', ['status', 'payroll_locked'])) {
        $cc = array_keys(dsc_cols($c, 'attendance_corrections'));
        $sel = implode(', ', array_map('dsc_id', array_values(array_intersect(
            ['id', 'tenant_id', 'employee_id', 'log_date', 'status', 'payroll_locked',
             'orig_in', 'orig_out', 'req_in', 'req_out', 'app_in', 'app_out', 'decided_at'], $cc))));
        $rows = dsc_q($c, "SELECT $sel FROM attendance_corrections
                            WHERE payroll_locked = 1 AND status = 'approved' ORDER BY id", [], 'd9.corr');
        dsc_write_csv($c, 'defects/corrections_locked_period.csv', $rows);
        $add($reg, 'ATT-LOCKED', 'Corrections approved against a locked payroll period', count($rows), '',
            'no adjustment is ever created for these', 'defects/corrections_locked_period.csv');
        if ($rows) {
            $c->finding('HIGH', 'defects',
                count($rows) . ' attendance corrections were approved against a locked payroll period',
                'The employee was emailed that the change "will be carried into the next adjustment", and NO '
                . 'CODE CREATES SUCH AN ADJUSTMENT (B-… DEFECT-B-30). Each is an unprocessed liability the '
                . 'customer has already promised to settle.',
                ['corrections' => count($rows)]);
        }
    }

    /* ===== Backdated salary reductions with no recovery path ============ */
    if (dsc_has_cols($c, 'increments', ['old_ctc', 'new_ctc'])) {
        $ic = array_keys(dsc_cols($c, 'increments'));
        $sel = implode(', ', array_map('dsc_id', array_values(array_intersect(
            ['id', 'tenant_id', 'employee_id', 'old_ctc', 'new_ctc', 'effective_from', 'status', 'created_at'], $ic))));
        $rows = dsc_q($c, "SELECT $sel FROM increments WHERE new_ctc < old_ctc ORDER BY id", [], 'd9.inc');
        dsc_write_csv($c, 'defects/salary_reductions.csv', $rows);
        $add($reg, 'ARR-DEC', 'Backdated salary reductions (arrears engine has no decrease path)',
            count($rows), '', 'overpayment never recovered', 'defects/salary_reductions.csv');
        if ($rows) {
            $c->finding('HIGH', 'defects', count($rows) . ' backdated salary reductions produced no recovery',
                'IncrementController fires only when newCtc > oldCtc, so a reduction produces nothing and the '
                . 'overpayment is never recovered (A-… OQ-9).');
        }
    }

    /* ===== Demo tenant: lockdown + destructive seeder target ============ */
    if (dsc_has_table($c, 'tenants')) {
        $tc = array_keys(dsc_cols($c, 'tenants'));
        $sel = implode(', ', array_map('dsc_id', array_values(array_intersect(
            ['id', 'name', 'subdomain', 'status', 'created_at'], $tc))));
        $where = [];
        if (in_array('subdomain', $tc, true)) {
            $where[] = "subdomain = 'demo'";
        }
        if (in_array('name', $tc, true)) {
            $where[] = "name LIKE '%demo%'";
        }
        if ($where) {
            $rows = dsc_q($c, "SELECT $sel FROM tenants WHERE " . implode(' OR ', $where) . ' ORDER BY id', [], 'd9.demo');
            dsc_write_csv($c, 'defects/demo_tenants.csv', $rows, 'none');
            $add($reg, 'DEMO-TENANT', "Tenants matching subdomain='demo' or name like %demo%", count($rows),
                '', 'DummyDataSeeder hard-deletes 23 tables for these', 'defects/demo_tenants.csv');
            if ($rows) {
                $c->finding('BLOCKER', 'defects', count($rows) . ' tenant(s) match the demo discriminator',
                    "subdomain='demo' triggers a product lockdown AND makes the tenant the target of "
                    . "DummyDataSeeder's 23-table hard delete, including employees. If any of these is a REAL "
                    . 'customer, that is an immediate operational risk in SmartPRS 2 today, independent of the '
                    . 'rebuild (J-… PO-15). Raise it before finishing the visit.',
                    ['tenants' => $rows]);
            }
        }

        // Tenants with no subscription: never gated, never billed.
        if (dsc_has_cols($c, 'subscriptions', ['tenant_id'])) {
            $rows = dsc_q($c, 'SELECT t.id, t.name' . (in_array('subdomain', $tc, true) ? ', t.subdomain' : '')
                . ' FROM tenants t LEFT JOIN subscriptions s ON s.tenant_id = t.id
                    WHERE s.id IS NULL ORDER BY t.id', [], 'd9.nosub');
            dsc_write_csv($c, 'defects/tenants_without_subscription.csv', $rows, 'none');
            $add($reg, 'NO-SUB', 'Tenants with no subscription row', count($rows), '',
                'never gated, never billed', 'defects/tenants_without_subscription.csv');
        }
    }

    /* ===== Invoice over-credit (SaaS) ================================== */
    if (dsc_has_cols($c, 'invoices', ['amount']) && dsc_has_cols($c, 'payments', ['invoice_id', 'amount'])) {
        $tax = dsc_has_cols($c, 'invoices', ['tax']) ? 'i.tax' : '0';
        $num = dsc_has_cols($c, 'invoices', ['number']) ? ', i.number' : '';
        $rows = dsc_q($c, "SELECT i.id$num, i.amount, $tax AS tax, SUM(p.amount) AS paid,
                                  SUM(p.amount) - (i.amount + $tax) AS over_credit
                             FROM invoices i JOIN payments p ON p.invoice_id = i.id
                            GROUP BY i.id HAVING SUM(p.amount) > i.amount + $tax
                            ORDER BY over_credit DESC", [], 'd9.inv');
        dsc_write_csv($c, 'defects/invoice_over_credit.csv', $rows, 'none');
        $add($reg, 'DEF-12', 'Invoices credited more than their total', count($rows),
            number_format(array_sum(array_map(static fn($r) => (float) $r['over_credit'], $rows)), 2),
            '', 'defects/invoice_over_credit.csv');
    }

    /* ===== NULL-tenant rows on the tables where it matters most ========= */
    $nt = [];
    foreach (['attendance_logs', 'biometric_configs', 'documents', 'kb_topics', 'notices', 'settings',
              'commissions', 'payslips', 'leaves', 'employees'] as $t) {
        if (!dsc_has_cols($c, $t, ['tenant_id']) || dsc_is_heavy($c, $t)) {
            continue;
        }
        $nt[] = ['table' => $t,
            'null_tenant' => (int) dsc_val($c, 'SELECT COUNT(*) FROM ' . dsc_id($t) . ' WHERE tenant_id IS NULL', [], 'd9.nt', 0),
            'zero_tenant' => (int) dsc_val($c, 'SELECT COUNT(*) FROM ' . dsc_id($t) . ' WHERE tenant_id = 0', [], 'd9.nt0', 0)];
    }
    dsc_write_csv($c, 'defects/null_tenant_rows.csv', $nt, 'none');

    /* ===== Contamination Register (R-XXIX-028) ========================= */
    dsc_write_csv($c, 'defects/contamination_register.csv', $reg, 'none');
    $c->finding('INFO', 'defects', 'Contamination Register written with ' . count($reg) . ' classes',
        'R-XXIX-028: every row needs a Product-Owner disposition before Gate G-3. The disposition column '
        . 'is deliberately empty — fill it in the register, not in this pack.');

    dsc_finish($c, 'd9_defects');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d9_defects.php', 'd9_defects');
}
