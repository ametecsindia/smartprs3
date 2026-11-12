<?php
/**
 * D7 — loan and advance balance reconciliation.
 *
 * DEF-P02: loans.outstanding is never written by any code path. Two live failure
 * modes follow — outstanding = 0 means the EMI was NEVER recovered; outstanding > 0
 * means it is recovered EVERY MONTH FOREVER until somebody closes the loan by hand.
 * The only reliable evidence of what was actually recovered is loan_recoveries — a
 * runtime-only table with no index on any column, whose rows for a regenerated run
 * were hard-deleted.
 *
 * R-XXIX-024: the stored outstanding value MUST NOT be migrated as an opening
 * balance. This check produces the derived figure the customer statement is
 * reconciled against.
 *
 * Read-only. Standalone: php checks/d7_money.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d7_money(DscCtx $c): void
{
    $hasLoans = dsc_has_table($c, 'loans');
    $hasRec = dsc_has_table($c, 'loan_recoveries');

    if (!$hasLoans) {
        $c->note('d7', 'loans table absent');
    } else {
        $lc = array_keys(dsc_cols($c, 'loans'));
        $has = static fn(string $col) => in_array($col, $lc, true);

        // D7.1  The two failure modes, counted.
        $parts = ['COUNT(*) AS loans_total'];
        if ($has('outstanding')) {
            $parts[] = 'SUM(outstanding = 0 OR outstanding IS NULL) AS never_recovered_candidates';
            $parts[] = 'SUM(outstanding > 0) AS forever_recovered_candidates';
        }
        if ($has('status')) {
            $parts[] = "SUM(status = 'closed') AS closed";
            $parts[] = "SUM(status <> 'closed' OR status IS NULL) AS not_closed";
        }
        if ($has('emi')) {
            $parts[] = 'SUM(emi IS NULL OR emi = 0) AS no_emi';
        }
        if ($has('tenure')) {
            $parts[] = 'SUM(tenure IS NULL OR tenure = 0) AS no_tenure';
        }
        if ($has('amount')) {
            $parts[] = 'SUM(amount) AS sanctioned_total';
        }
        $sum = dsc_q($c, 'SELECT ' . implode(', ', $parts) . ' FROM loans', [], 'd7.1');
        dsc_write_csv($c, 'money/loans_summary.csv', $sum, 'none');

        $never = (int) ($sum[0]['never_recovered_candidates'] ?? 0);
        $forever = (int) ($sum[0]['forever_recovered_candidates'] ?? 0);
        if ($never > 0) {
            $c->finding('BLOCKER', 'money', "$never loans have outstanding = 0 or NULL",
                'DEF-P02 failure mode A: the recovery guard reads (float) 0 <= 0 and skips. The EMI on '
                . 'these loans was never recovered at all. Whether the employee still owes the money is a '
                . 'customer conversation with a signed per-employee statement (R-XXIX-024), not a migration '
                . 'default. Cross-check against money/loans_derived.csv.',
                ['loans' => $never]);
        }
        if ($forever > 0) {
            $c->finding('BLOCKER', 'money', "$forever loans have outstanding > 0",
                'DEF-P02 failure mode B: the "fully recovered" and auto-close guards never fire, so the '
                . 'EMI is deducted every month indefinitely. Check money/loans_over_recovered.csv for '
                . 'employees who have already repaid more than was sanctioned.',
                ['loans' => $forever]);
        }

        // D7.2  Per-loan truth table: stored vs derived.
        if ($hasRec && dsc_has_cols($c, 'loan_recoveries', ['loan_id', 'amount'])) {
            $eSel = dsc_has_cols($c, 'employees', ['emp_code']) ? ', e.emp_code, e.name' : '';
            $eJoin = dsc_has_table($c, 'employees') ? ' LEFT JOIN employees e ON e.id = l.employee_id' : '';
            $sel = 'l.id, ' . ($has('tenant_id') ? 'l.tenant_id, ' : '') . 'l.employee_id' . $eSel
                . ($has('amount') ? ', l.amount AS sanctioned' : '')
                . ($has('emi') ? ', l.emi AS emi_declared' : '')
                . ($has('tenure') ? ', l.tenure AS tenure_declared' : '')
                . ($has('outstanding') ? ', l.outstanding AS outstanding_stored' : '')
                . ($has('status') ? ', l.status' : '')
                . ', COUNT(lr.id) AS recovery_rows, COALESCE(SUM(lr.amount), 0) AS recovered_total'
                . ($has('amount') ? ', l.amount - COALESCE(SUM(lr.amount), 0) AS derived_outstanding' : '')
                . ', MIN(lr.created_at) AS first_recovery, MAX(lr.created_at) AS last_recovery';
            $rows = dsc_q($c, "SELECT $sel FROM loans l$eJoin
                                 LEFT JOIN loan_recoveries lr ON lr.loan_id = l.id
                                GROUP BY l.id ORDER BY l.id", [], 'd7.2');
            dsc_write_csv($c, 'money/loans_derived.csv', $rows);

            // Stored versus derived: how often is the stored figure simply wrong?
            $mismatch = 0;
            foreach ($rows as $r) {
                if (!isset($r['outstanding_stored'], $r['derived_outstanding'])) {
                    continue;
                }
                if (abs((float) $r['outstanding_stored'] - (float) $r['derived_outstanding']) >= 1.0) {
                    $mismatch++;
                }
            }
            if ($mismatch > 0) {
                $c->finding('BLOCKER', 'money',
                    "$mismatch loans where stored outstanding differs from recovered-derived outstanding by ≥ ₹1",
                    'R-XXIX-024: the stored value MUST NOT be migrated as an opening balance under any '
                    . 'circumstance. The opening balance is a customer-stated figure reconciled against this '
                    . 'derived one, with every variance explained and countersigned.',
                    ['loans' => $mismatch]);
            }

            // D7.3  Over-recovery: money potentially owed BACK to an employee.
            if ($has('amount')) {
                $over = dsc_q($c, "SELECT l.id, l.employee_id" . $eSel . ", l.amount,
                                          SUM(lr.amount) AS recovered, SUM(lr.amount) - l.amount AS over_recovered
                                     FROM loans l$eJoin JOIN loan_recoveries lr ON lr.loan_id = l.id
                                    GROUP BY l.id HAVING SUM(lr.amount) > l.amount
                                    ORDER BY over_recovered DESC", [], 'd7.3');
                dsc_write_csv($c, 'money/loans_over_recovered.csv', $over);
                if ($over) {
                    $c->finding('BLOCKER', 'money', count($over) . ' loans have been OVER-recovered',
                        'Total over-recovered: ' . number_format(array_sum(array_map(
                            static fn($r) => (float) $r['over_recovered'], $over)), 2) . '. '
                        . 'R-XXIX-025: the migration MUST NOT proceed for these employees until the Product '
                        . 'Owner records a remedy (repay / credit to next payroll / write off). The alternative '
                        . 'is opening a SmartPRS 3 ledger that inherits a debt the employee does not owe.',
                        ['loans' => count($over)]);
                }
            }

            // D7.4  Recoveries pointing at a run that no longer exists.
            if (dsc_has_cols($c, 'loan_recoveries', ['run_id']) && dsc_has_table($c, 'payroll_runs')) {
                $orph = dsc_q($c, 'SELECT lr.loan_id, COUNT(*) AS orphan_recoveries, SUM(lr.amount) AS orphan_amount
                                     FROM loan_recoveries lr LEFT JOIN payroll_runs r ON r.id = lr.run_id
                                    WHERE r.id IS NULL GROUP BY lr.loan_id ORDER BY orphan_amount DESC', [], 'd7.4');
                dsc_write_csv($c, 'money/loan_recoveries_orphan_run.csv', $orph);
                if ($orph) {
                    $c->finding('HIGH', 'money', count($orph) . ' loans have recoveries against a deleted payroll run',
                        'The best-effort try/catch at PayrollGenController:2448 failed for these. The instalment '
                        . 'counter for these loans is permanently wrong and cannot be repaired from the data.');
                }
            }
        } elseif ($hasLoans) {
            $c->finding('BLOCKER', 'money', 'loan_recoveries is ABSENT or unusable — loan balances cannot be derived',
                'Without the recovery ledger there is NO evidence of what was actually recovered, and '
                . 'loans.outstanding is known to be unreliable. Every opening balance on this install must '
                . 'be stated by the customer from their own records and countersigned before migration.');
        }

        // D7.5  installments_paid is created at run time, so it is absent on some installs.
        $c->finding('INFO', 'money',
            'loans.installments_paid ' . ($has('installments_paid') ? 'PRESENT' : 'ABSENT') . ' on this install',
            'Created at run time by generate():2580-2587. Its absence means the instalment counter never '
            . 'existed here; its presence does not mean it is correct.');
    }

    // D7.6  Advances: recovered in one month from created_at; per_month and balance ignored.
    if (dsc_has_table($c, 'advances')) {
        $ac = array_keys(dsc_cols($c, 'advances'));
        $sel = implode(', ', array_map(static fn($x) => 'a.' . dsc_id($x),
            array_values(array_intersect(['id', 'tenant_id', 'employee_id', 'amount', 'per_month', 'balance', 'status', 'created_at'], $ac))));
        $join = dsc_has_cols($c, 'employees', ['emp_code']) ? ' LEFT JOIN employees e ON e.id = a.employee_id' : '';
        $eSel = $join ? ', e.emp_code' : '';
        $rows = dsc_q($c, "SELECT $sel$eSel FROM advances a$join ORDER BY a.id", [], 'd7.6');
        dsc_write_csv($c, 'money/advances.csv', $rows);

        if (in_array('balance', $ac, true)) {
            $open = (int) dsc_val($c, 'SELECT COUNT(*) FROM advances WHERE balance IS NOT NULL AND balance > 0', [], 'd7.6b', 0);
            if ($open > 0) {
                $c->finding('HIGH', 'money', "$open advances carry a non-zero balance",
                    'Advances recover in one month keyed on created_at, have no ledger, and ignore '
                    . 'per_month/balance; shortfalls were silently dropped (A-… DEF-P19). Each of these is an '
                    . 'unresolved liability that must be restated by the customer.');
            }
        }
    }

    // D7.7  Clawbacks — the one-month-only rule is not configurable.
    if (dsc_has_table($c, 'clawbacks')) {
        dsc_write_csv($c, 'money/clawbacks.csv', dsc_q($c, 'SELECT * FROM clawbacks ORDER BY id', [], 'd7.7'));
    }

    // Recovery ledger totals per tenant — the reconciliation figure.
    if ($hasRec && dsc_has_cols($c, 'loan_recoveries', ['amount'])) {
        $g = dsc_has_cols($c, 'loan_recoveries', ['tenant_id']) ? 'tenant_id' : null;
        $rows = dsc_q($c, 'SELECT ' . ($g ? 'tenant_id, ' : '') . 'COUNT(*) AS rows_, SUM(amount) AS total,
                                  MIN(created_at) AS lo, MAX(created_at) AS hi
                             FROM loan_recoveries' . ($g ? ' GROUP BY tenant_id ORDER BY tenant_id' : ''), [], 'd7.8');
        dsc_write_csv($c, 'money/recoveries_summary.csv', $rows, 'none');
    }

    // Salary payments ledger, if the runtime table exists here.
    if (dsc_has_table($c, 'salary_payments')) {
        $cols = array_keys(dsc_cols($c, 'salary_payments'));
        $g = implode(', ', array_map('dsc_id', array_values(array_intersect(['tenant_id', 'month', 'status'], $cols))));
        $amt = in_array('amount', $cols, true) ? ', SUM(amount) AS total' : '';
        dsc_write_csv($c, 'money/salary_payments_summary.csv',
            $g ? dsc_q($c, "SELECT $g, COUNT(*) AS rows_$amt FROM salary_payments GROUP BY $g ORDER BY $g", [], 'd7.9')
               : dsc_q($c, "SELECT COUNT(*) AS rows_$amt FROM salary_payments", [], 'd7.9'), 'none');
    }

    dsc_finish($c, 'd7_money');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d7_money.php', 'd7_money');
}
