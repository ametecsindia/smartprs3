<?php
/**
 * D4 — orphan scans and the duplicates the missing constraints would have prevented.
 *
 * There are zero foreign keys on business tables and ~180 unconstrained *_id
 * columns (J-… §9 Q4). Orphans are a certainty, not a risk. This check counts the
 * classes that carry money, evidence or identity, and keeps a capped sample of ids
 * for triage.
 *
 * Every class is guarded: a class whose tables or columns are absent on this
 * install is recorded as SKIPPED, not failed.
 *
 * Read-only. Some classes are full joins over attendance_logs — see the "cost"
 * field in integrity/orphans.csv and RUNBOOK.md "When a query is too slow".
 *
 * Standalone: php checks/d4_orphans.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d4_orphan_classes(): array
{
    return [
        // id, title, requires [[table, [cols]]], count sql, sample sql (nullable), cost, severity
        ['O1', 'Payslips whose employee does not exist',
            [['payslips', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM payslips p LEFT JOIN employees e ON e.id = p.employee_id WHERE e.id IS NULL',
            'SELECT p.id, p.employee_id, p.month, p.gross, p.net FROM payslips p
               LEFT JOIN employees e ON e.id = p.employee_id WHERE e.id IS NULL ORDER BY p.id LIMIT 200',
            'low', 'BLOCKER'],

        ['O2', 'Payslips whose payroll run was hard-deleted on regenerate',
            [['payslips', ['run_id']], ['payroll_runs', ['id']]],
            'SELECT COUNT(*) FROM payslips p LEFT JOIN payroll_runs r ON r.id = p.run_id WHERE r.id IS NULL',
            'SELECT p.id, p.run_id, p.employee_id, p.month, p.net FROM payslips p
               LEFT JOIN payroll_runs r ON r.id = p.run_id WHERE r.id IS NULL ORDER BY p.id LIMIT 200',
            'low', 'BLOCKER'],

        ['O3a', 'Loan recoveries pointing at a deleted payroll run',
            [['loan_recoveries', ['run_id']], ['payroll_runs', ['id']]],
            'SELECT COUNT(*) FROM loan_recoveries lr LEFT JOIN payroll_runs r ON r.id = lr.run_id WHERE r.id IS NULL',
            'SELECT lr.loan_id, COUNT(*) AS orphan_recoveries, SUM(lr.amount) AS orphan_amount
               FROM loan_recoveries lr LEFT JOIN payroll_runs r ON r.id = lr.run_id
              WHERE r.id IS NULL GROUP BY lr.loan_id ORDER BY orphan_amount DESC LIMIT 200',
            'low', 'BLOCKER'],

        ['O3b', 'Loan recoveries pointing at a deleted loan',
            [['loan_recoveries', ['loan_id']], ['loans', ['id']]],
            'SELECT COUNT(*) FROM loan_recoveries lr LEFT JOIN loans l ON l.id = lr.loan_id WHERE l.id IS NULL',
            null, 'low', 'HIGH'],

        ['O3c', 'Loan recoveries pointing at a deleted employee',
            [['loan_recoveries', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM loan_recoveries lr LEFT JOIN employees e ON e.id = lr.employee_id WHERE e.id IS NULL',
            null, 'low', 'HIGH'],

        ['O4a', 'Commissions with no employee (incl. NULL employee_id)',
            [['commissions', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM commissions c LEFT JOIN employees e ON e.id = c.employee_id WHERE e.id IS NULL',
            'SELECT c.id, c.employee_id, c.month, c.amount, c.status FROM commissions c
               LEFT JOIN employees e ON e.id = c.employee_id WHERE e.id IS NULL ORDER BY c.id LIMIT 200',
            'low', 'HIGH'],

        ['O4b', 'Commission payments whose commission does not exist',
            [['commission_payments', ['commission_id']], ['commissions', ['id']]],
            'SELECT COUNT(*) FROM commission_payments cp LEFT JOIN commissions c ON c.id = cp.commission_id WHERE c.id IS NULL',
            null, 'low', 'HIGH'],

        ['O4c', 'Commission logs whose commission does not exist',
            [['commission_logs', ['commission_id']], ['commissions', ['id']]],
            'SELECT COUNT(*) FROM commission_logs cl LEFT JOIN commissions c ON c.id = cl.commission_id WHERE c.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O5', 'Attendance punches whose emp_code matches no employee',
            [['attendance_logs', ['emp_code']], ['employees', ['emp_code']]],
            'SELECT COUNT(*) FROM attendance_logs a
               LEFT JOIN employees e ON e.emp_code = a.emp_code AND (e.tenant_id <=> a.tenant_id)
              WHERE e.id IS NULL',
            'SELECT a.tenant_id, a.emp_code, COUNT(*) AS punches, MIN(a.log_date) AS lo, MAX(a.log_date) AS hi
               FROM attendance_logs a
               LEFT JOIN employees e ON e.emp_code = a.emp_code AND (e.tenant_id <=> a.tenant_id)
              WHERE e.id IS NULL GROUP BY a.tenant_id, a.emp_code ORDER BY punches DESC LIMIT 200',
            'HIGH — full join over attendance_logs', 'BLOCKER'],

        ['O5b', 'USER-<id> fallback punches: present days for a code no employee holds',
            [['attendance_logs', ['emp_code']]],
            "SELECT COUNT(*) FROM attendance_logs WHERE emp_code LIKE 'USER-%'",
            "SELECT emp_code, COUNT(*) AS punches, MIN(log_date) AS lo, MAX(log_date) AS hi
               FROM attendance_logs WHERE emp_code LIKE 'USER-%'
              GROUP BY emp_code ORDER BY punches DESC LIMIT 200",
            'medium', 'HIGH'],

        ['O6a', 'Employees pointing at a missing company',
            [['employees', ['company_id']], ['companies', ['id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN companies x ON x.id = e.company_id
              WHERE e.company_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'HIGH'],

        ['O6b', 'Employees pointing at a missing department',
            [['employees', ['department_id']], ['departments', ['id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN departments x ON x.id = e.department_id
              WHERE e.department_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O6c', 'Employees pointing at a missing designation',
            [['employees', ['designation_id']], ['designations', ['id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN designations x ON x.id = e.designation_id
              WHERE e.designation_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O6d', 'Employees pointing at a missing branch',
            [['employees', ['branch_id']], ['branches', ['id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN branches x ON x.id = e.branch_id
              WHERE e.branch_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O6e', 'Employees pointing at a missing team',
            [['employees', ['team_id']], ['teams', ['id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN teams x ON x.id = e.team_id
              WHERE e.team_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O6f', 'Employees pointing at a missing reporting manager',
            [['employees', ['reporting_manager_id']]],
            'SELECT COUNT(*) FROM employees e LEFT JOIN employees m ON m.id = e.reporting_manager_id
              WHERE e.reporting_manager_id IS NOT NULL AND m.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O6g', 'Reporting-manager cycles (A reports to B reports to A)',
            [['employees', ['reporting_manager_id']]],
            'SELECT COUNT(*) FROM employees e1
               JOIN employees e2 ON e2.id = e1.reporting_manager_id
               JOIN employees e3 ON e3.id = e2.reporting_manager_id
              WHERE e3.id = e1.id',
            'SELECT e1.id, e1.emp_code FROM employees e1
               JOIN employees e2 ON e2.id = e1.reporting_manager_id
               JOIN employees e3 ON e3.id = e2.reporting_manager_id
              WHERE e3.id = e1.id ORDER BY e1.id LIMIT 200',
            'low', 'MEDIUM'],

        ['O7a', 'Documents whose employee does not exist',
            [['documents', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM documents d LEFT JOIN employees e ON e.id = d.employee_id
              WHERE d.employee_id IS NOT NULL AND e.id IS NULL',
            'SELECT d.id, d.employee_id, d.kind, d.file_path FROM documents d
               LEFT JOIN employees e ON e.id = d.employee_id
              WHERE d.employee_id IS NOT NULL AND e.id IS NULL ORDER BY d.id LIMIT 200',
            'low', 'HIGH'],

        ['O7b', 'Documents with no tenant (invisible to their owner)',
            [['documents', ['tenant_id']]],
            'SELECT COUNT(*) FROM documents WHERE tenant_id IS NULL',
            null, 'low', 'HIGH'],

        ['O8', 'employee_references whose employee does not exist (table has no tenant column)',
            [['employee_references', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM employee_references r LEFT JOIN employees e ON e.id = r.employee_id WHERE e.id IS NULL',
            null, 'low', 'HIGH'],

        ['O9', 'Payroll runs whose company does not exist',
            [['payroll_runs', ['company_id']], ['companies', ['id']]],
            'SELECT COUNT(*) FROM payroll_runs r LEFT JOIN companies x ON x.id = r.company_id
              WHERE r.company_id IS NOT NULL AND x.id IS NULL',
            null, 'low', 'HIGH'],

        ['O10', 'Leaves whose employee does not exist',
            [['leaves', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM leaves l LEFT JOIN employees e ON e.id = l.employee_id WHERE e.id IS NULL',
            null, 'low', 'HIGH'],

        ['O11', 'Salary payments whose employee does not exist',
            [['salary_payments', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM salary_payments s LEFT JOIN employees e ON e.id = s.employee_id WHERE e.id IS NULL',
            null, 'low', 'HIGH'],

        ['O12', 'Deductions whose employee does not exist',
            [['deductions', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM deductions d LEFT JOIN employees e ON e.id = d.employee_id WHERE e.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O13', 'Advances whose employee does not exist',
            [['advances', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM advances a LEFT JOIN employees e ON e.id = a.employee_id WHERE e.id IS NULL',
            null, 'low', 'HIGH'],

        ['O14', 'Increments whose employee does not exist (salary history)',
            [['increments', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM increments i LEFT JOIN employees e ON e.id = i.employee_id WHERE e.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O15', 'Users pointing at a tenant that does not exist',
            [['users', ['tenant_id']], ['tenants', ['id']]],
            'SELECT COUNT(*) FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id
              WHERE u.tenant_id IS NOT NULL AND t.id IS NULL',
            null, 'low', 'HIGH'],

        ['O16', 'Subscriptions pointing at a tenant that does not exist',
            [['subscriptions', ['tenant_id']], ['tenants', ['id']]],
            'SELECT COUNT(*) FROM subscriptions s LEFT JOIN tenants t ON t.id = s.tenant_id WHERE t.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O17', 'Training records whose employee does not exist',
            [['training_records', ['employee_id']], ['employees', ['id']]],
            'SELECT COUNT(*) FROM training_records r LEFT JOIN employees e ON e.id = r.employee_id WHERE e.id IS NULL',
            null, 'low', 'LOW'],

        ['O18', 'Self-onboarding documents whose parent application does not exist',
            [['self_onboarding_docs', ['self_onboarding_id']], ['self_onboarding', ['id']]],
            'SELECT COUNT(*) FROM self_onboarding_docs d LEFT JOIN self_onboarding s ON s.id = d.self_onboarding_id
              WHERE s.id IS NULL',
            null, 'low', 'HIGH'],

        ['O19', 'Clawbacks pointing at a deleted recovery run',
            [['clawbacks', ['recovered_run_id']], ['payroll_runs', ['id']]],
            'SELECT COUNT(*) FROM clawbacks c LEFT JOIN payroll_runs r ON r.id = c.recovered_run_id
              WHERE c.recovered_run_id IS NOT NULL AND r.id IS NULL',
            null, 'low', 'MEDIUM'],

        ['O20', 'Overtime rows pointing at a deleted payment run',
            [['overtime', ['paid_run_id']], ['payroll_runs', ['id']]],
            'SELECT COUNT(*) FROM overtime o LEFT JOIN payroll_runs r ON r.id = o.paid_run_id
              WHERE o.paid_run_id IS NOT NULL AND r.id IS NULL',
            null, 'low', 'MEDIUM'],
    ];
}

/** Duplicate classes: the unique constraints that were deliberately never applied. */
function d4_duplicate_classes(): array
{
    return [
        ['payroll_runs (tenant, company, cycle_label)', 'payroll_runs', ['tenant_id', 'company_id', 'cycle_label'],
            'Two payroll runs for the same company and period. Only a PHP SELECT prevents it.'],
        ['payroll_runs (tenant, company, month)', 'payroll_runs', ['tenant_id', 'company_id', 'month'], ''],
        ['payslips (employee, month)', 'payslips', ['employee_id', 'month'],
            'Two payslips for the same person in the same month.'],
        ['payslips (run, employee)', 'payslips', ['run_id', 'employee_id'], ''],
        ['employees.emp_code', 'employees', ['tenant_id', 'emp_code'], ''],
        ['employees.pan', 'employees', ['tenant_id', 'pan'], ''],
        ['employees.uan', 'employees', ['tenant_id', 'uan'], ''],
        ['employees.bank_acc', 'employees', ['tenant_id', 'bank_acc'], ''],
        ['employees.esic_no', 'employees', ['tenant_id', 'esic_no'], ''],
        ['employees.email', 'employees', ['tenant_id', 'email'], ''],
        ['employees.mobile', 'employees', ['tenant_id', 'mobile'], ''],
        ['employees.national_id', 'employees', ['tenant_id', 'national_id'], ''],
        ['employees.device_user_id', 'employees', ['tenant_id', 'device_user_id'], ''],
        ['self_onboarding.temp_emp_code', 'self_onboarding', ['temp_emp_code'],
            'Generated by MAX()+1; two candidates can share a code.'],
        ['holidays (tenant, date)', 'holidays', ['tenant_id', 'date'],
            'Duplicate holidays double-count in the LOP denominator.'],
        ['salary_components (tenant, scope, code)', 'salary_components', ['tenant_id', 'scope', 'code'],
            'Payroll behaviour becomes order-dependent.'],
        ['leave_types (tenant, name)', 'leave_types', ['tenant_id', 'name'], ''],
        ['settings (tenant, key)', 'settings', ['tenant_id', 'key'],
            'updateOrInsert inserts and first() then reads an arbitrary row. Duplicate app_state rows are possible.'],
        ['attendance_ratings (tenant, emp_code, log_date)', 'attendance_ratings', ['tenant_id', 'emp_code', 'log_date'], ''],
        ['code_of_conduct_ack (tenant, employee)', 'code_of_conduct_ack', ['tenant_id', 'employee_id'], ''],
        ['training_records (employee, program)', 'training_records', ['employee_id', 'program_id'], ''],
    ];
}

function d4_orphans(DscCtx $c): void
{
    $summary = [];
    foreach (d4_orphan_classes() as [$id, $title, $requires, $countSql, $sampleSql, $cost, $sev]) {
        $missing = [];
        foreach ($requires as [$t, $cols]) {
            if (!dsc_has_cols($c, $t, $cols)) {
                $missing[] = $t . '(' . implode(',', $cols) . ')';
            }
        }
        if ($missing) {
            $summary[] = ['class' => $id, 'title' => $title, 'orphans' => '', 'status' => 'SKIPPED',
                'reason' => 'absent here: ' . implode(' ', $missing), 'cost' => $cost, 'severity' => $sev];
            continue;
        }
        $heavy = false;
        foreach ($requires as [$t, $_]) {
            $heavy = $heavy || dsc_is_heavy($c, $t);
        }
        if ($heavy) {
            $summary[] = ['class' => $id, 'title' => $title, 'orphans' => '', 'status' => 'SKIPPED',
                'reason' => 'heavy table, --skip-heavy', 'cost' => $cost, 'severity' => $sev];
            continue;
        }

        $n = dsc_val($c, $countSql, [], 'orphan:' . $id, null);
        if ($n === null) {
            $summary[] = ['class' => $id, 'title' => $title, 'orphans' => '', 'status' => 'FAILED',
                'reason' => 'see notes', 'cost' => $cost, 'severity' => $sev];
            continue;
        }
        $n = (int) $n;
        $summary[] = ['class' => $id, 'title' => $title, 'orphans' => $n, 'status' => 'OK',
            'reason' => '', 'cost' => $cost, 'severity' => $sev];

        if ($n > 0 && $sampleSql !== null) {
            dsc_write_csv($c, "integrity/orphan_samples/{$id}.csv", dsc_sample($c, dsc_q($c, $sampleSql, [], 'sample:' . $id)));
        }
        if ($n > 0) {
            $c->finding($sev === 'BLOCKER' ? 'BLOCKER' : $sev, 'integrity', "$id: $n — $title",
                'R-XXIX-015 requires a recorded disposition per orphan class (resolve / quarantine / '
                . 'attach-to-placeholder / do not migrate). R-XXIX-016: payroll, attendance and compliance '
                . 'orphans default to attach-to-placeholder, never to drop.',
                ['class' => $id, 'count' => $n]);
        }
    }
    dsc_write_csv($c, 'integrity/orphans.csv', $summary, 'none');

    // Duplicates the missing unique constraints would have prevented.
    $dups = [];
    foreach (d4_duplicate_classes() as [$label, $table, $cols, $note]) {
        if (!dsc_has_cols($c, $table, $cols) || dsc_is_heavy($c, $table)) {
            $dups[] = ['key' => $label, 'duplicate_groups' => '', 'extra_rows' => '',
                'status' => 'SKIPPED', 'note' => $note];
            continue;
        }
        $qc = implode(', ', array_map('dsc_id', $cols));
        $notEmpty = '';
        // Ignore NULL/blank identity values: only a real repeated value is a duplicate.
        foreach ($cols as $col) {
            if (in_array($col, ['tenant_id', 'company_id', 'run_id'], true)) {
                continue;
            }
            $q = dsc_id($col);
            $notEmpty .= ($notEmpty === '' ? ' WHERE ' : ' AND ') . "$q IS NOT NULL AND $q <> ''";
        }
        $r = dsc_q($c, "SELECT COUNT(*) AS groups_, COALESCE(SUM(c - 1), 0) AS extra FROM (
                          SELECT $qc, COUNT(*) AS c FROM " . dsc_id($table) . $notEmpty . "
                           GROUP BY $qc HAVING COUNT(*) > 1) x", [], 'dup:' . $label);
        $groups = (int) ($r[0]['groups_'] ?? 0);
        $dups[] = ['key' => $label, 'duplicate_groups' => $groups,
            'extra_rows' => (int) ($r[0]['extra'] ?? 0), 'status' => 'OK', 'note' => $note];

        if ($groups > 0) {
            $sev = str_starts_with($label, 'payslips') || str_starts_with($label, 'payroll_runs') ? 'BLOCKER' : 'HIGH';
            $c->finding($sev, 'integrity', "Duplicates on $label: $groups groups",
                ($note !== '' ? $note . ' ' : '')
                . 'The SmartPRS 3 unique constraint cannot be created until these are resolved with '
                . 'the customer (J-… §9 Q5; migration blocker 3).',
                ['key' => $label, 'groups' => $groups]);

            // Keep a redacted sample so triage has something to work from.
            $sample = dsc_q($c, "SELECT $qc, COUNT(*) AS rows_ FROM " . dsc_id($table) . $notEmpty . "
                                  GROUP BY $qc HAVING COUNT(*) > 1 ORDER BY rows_ DESC LIMIT 200",
                [], 'dupsample:' . $label);
            dsc_write_csv($c, 'integrity/duplicate_samples/' . preg_replace('/[^a-z0-9]+/i', '_', $label) . '.csv',
                dsc_sample($c, $sample));
        }
    }
    dsc_write_csv($c, 'integrity/duplicates.csv', $dups, 'none');

    dsc_finish($c, 'd4_orphans');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d4_orphans.php', 'd4_orphans');
}
