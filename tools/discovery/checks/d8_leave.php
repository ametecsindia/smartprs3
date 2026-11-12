<?php
/**
 * D8 — leave balance derivation and the LOP blast radius.
 *
 * There is no leave ledger. leaves.type_id is never written by any code path
 * (two reads, zero writes), so the paid/unpaid flag never reaches payroll and every
 * approved leave — including leave explicitly filed as Loss of Pay — was credited
 * as a paid present day. That is a mispayment, not a migration task.
 *
 * R-XXIX-026: opening balances are STATED by the customer per employee per leave
 * type. D8.5 is evidence presented to them, never an opening balance.
 *
 * Read-only. Standalone: php checks/d8_leave.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

const D8_LOP_PATTERNS = ['%loss of pay%', '%lop%', '%unpaid%', '%without pay%', '%lwp%'];

function d8_leave(DscCtx $c): void
{
    if (!dsc_has_table($c, 'leaves')) {
        $c->note('d8', 'leaves table absent');
        dsc_finish($c, 'd8_leave');
        return;
    }
    $lc = array_keys(dsc_cols($c, 'leaves'));
    $has = static fn(string $x) => in_array($x, $lc, true);

    // D8.1  Confirm the defect on THIS install: type_id should be NULL on every row.
    $parts = ['COUNT(*) AS total'];
    if ($has('type_id')) {
        $parts[] = 'SUM(type_id IS NULL) AS null_type_id';
        $parts[] = 'SUM(type_id IS NOT NULL) AS populated_type_id';
    }
    if ($has('type_name')) {
        $parts[] = 'COUNT(DISTINCT type_name) AS distinct_type_names';
    }
    if ($has('status')) {
        $parts[] = "SUM(status = 'approved') AS approved";
    }
    $r = dsc_q($c, 'SELECT ' . implode(', ', $parts) . ' FROM leaves', [], 'd8.1');
    dsc_write_csv($c, 'leave/type_id_state.csv', $r, 'none');

    $populated = (int) ($r[0]['populated_type_id'] ?? 0);
    $c->finding($populated === 0 ? 'BLOCKER' : 'HIGH', 'leave',
        'leaves.type_id populated on ' . $populated . ' of ' . (int) ($r[0]['total'] ?? 0) . ' rows',
        $populated === 0
            ? 'DEF-LVE-02 confirmed on this install: the leave type link was never written, so the '
              . 'paid/unpaid flag never reached payroll. Leave type is recoverable only from the free-text '
              . 'type_name, which must be reconciled to leave_types by hand per tenant.'
            : 'Some rows carry a type_id here — unexpected. Establish which code path wrote them before '
              . 'trusting either source.',
        ['populated' => $populated]);

    if (!$has('type_name') || !$has('status')) {
        $c->note('d8', 'leaves.type_name or .status absent; LOP blast radius cannot be computed');
        dsc_finish($c, 'd8_leave');
        return;
    }

    $lopWhere = "l.status = 'approved' AND (" . implode(' OR ',
        array_map(static fn($p) => 'LOWER(l.type_name) LIKE ' . "'" . $p . "'", D8_LOP_PATTERNS)) . ')';

    // D8.2  The mispayment population: approved leave whose type name says unpaid,
    //       credited as a paid present day. The match is deliberately broad; every hit
    //       is triaged by a human.
    $sel = 'l.id' . ($has('tenant_id') ? ', l.tenant_id' : '') . ', l.employee_id'
        . (dsc_has_cols($c, 'employees', ['emp_code']) ? ', e.emp_code' : '')
        . ', l.type_name'
        . ($has('from_date') ? ', l.from_date' : '') . ($has('to_date') ? ', l.to_date' : '')
        . ($has('days') ? ', l.days' : '') . ', l.status'
        . ($has('from_date') ? ", DATE_FORMAT(l.from_date, '%Y-%m') AS period_label" : '');
    $join = dsc_has_table($c, 'employees') ? ' LEFT JOIN employees e ON e.id = l.employee_id' : '';
    $lop = dsc_q($c, "SELECT $sel FROM leaves l$join WHERE $lopWhere ORDER BY l.id", [], 'd8.2');
    // type_name is configuration vocabulary, not personal data: it must survive redaction
    // or the triage list is unreadable. Only the person-identifying columns are hashed.
    $leavePii = ['employee_id', 'emp_code', 'name', 'employee'];
    dsc_write_csv($c, 'leave/lop_credited_as_paid.csv', $lop, $leavePii);

    $lopDays = 0.0;
    foreach ($lop as $row) {
        $lopDays += (float) ($row['days'] ?? 0);
    }
    if ($lop) {
        $c->finding('BLOCKER', 'leave',
            count($lop) . ' approved LOP/unpaid leaves totalling ' . round($lopDays, 1) . ' days were paid in full',
            'DEF-LVE-02 blast radius on this install. This is a MISPAYMENT that already happened, not a '
            . 'migration task: §XXIX.4.2. It needs a Product Owner disposition (recover / write off / '
            . 'disclose) before any load. The name match is broad by design — triage every hit.',
            ['leaves' => count($lop), 'days' => round($lopDays, 1),
             'employees' => count(array_unique(array_column($lop, 'employee_id')))]);
    }

    // D8.3  Which of those overlap a generated payslip — i.e. money actually moved.
    if (dsc_has_cols($c, 'payslips', ['employee_id', 'month']) && $has('from_date')) {
        $psel = 'p.id AS payslip_id, p.employee_id'
            . (dsc_has_cols($c, 'employees', ['emp_code']) ? ', e.emp_code' : '')
            . ', p.month'
            . (dsc_has_cols($c, 'payslips', ['gross']) ? ', p.gross' : '')
            . (dsc_has_cols($c, 'payslips', ['net']) ? ', p.net' : '')
            . ($has('days') ? ', SUM(l.days) AS lop_days_credited_as_paid' : ', COUNT(*) AS lop_leaves');
        $pjoin = dsc_has_table($c, 'employees') ? ' JOIN employees e ON e.id = l.employee_id' : '';
        $rows = dsc_q($c, "SELECT $psel FROM leaves l$pjoin
                             JOIN payslips p ON p.employee_id = l.employee_id
                                            AND p.month = DATE_FORMAT(l.from_date, '%Y-%m')
                            WHERE $lopWhere GROUP BY p.id ORDER BY p.month, p.id", [], 'd8.3');
        dsc_write_csv($c, 'leave/lop_overlapping_payslips.csv', $rows, $leavePii);
        if ($rows) {
            $c->finding('BLOCKER', 'leave', count($rows) . ' payslips paid LOP leave in full',
                'Money moved for these. Each is an over-payment with a named employee and a named period. '
                . 'This is the population the customer must be shown at Gate G-3.',
                ['payslips' => count($rows)]);
        }
    }

    // D8.4  Leave type names that no longer resolve — the manual reconciliation step.
    if (dsc_has_cols($c, 'leave_types', ['name'])) {
        $on = 't.name = l.type_name' . ($has('tenant_id') && dsc_has_cols($c, 'leave_types', ['tenant_id'])
            ? ' AND t.tenant_id = l.tenant_id' : '');
        $rows = dsc_q($c, 'SELECT ' . ($has('tenant_id') ? 'l.tenant_id, ' : '')
            . "l.type_name, COUNT(*) AS c FROM leaves l LEFT JOIN leave_types t ON $on
                WHERE t.id IS NULL GROUP BY " . ($has('tenant_id') ? 'l.tenant_id, ' : '')
            . 'l.type_name ORDER BY c DESC', [], 'd8.4');
        dsc_write_csv($c, 'leave/unresolvable_type_names.csv', $rows, 'none');
        if ($rows) {
            $c->finding('HIGH', 'leave', count($rows) . ' distinct leave type names do not resolve to a leave_types row',
                'Renamed or hard-deleted leave types. E-… §9 requires a manual reconciliation step per tenant '
                . 'for these names before any leave history is loaded.',
                ['names' => array_slice(array_column($rows, 'type_name'), 0, 20)]);
        }
    }

    // D8.5  The naive derived balance — INPUT to the customer conversation, never an opening balance.
    if ($has('from_date') && $has('days')) {
        $g = ($has('tenant_id') ? 'l.tenant_id, ' : '') . 'l.employee_id, l.type_name, YEAR(l.from_date)';
        $sel = ($has('tenant_id') ? 'l.tenant_id, ' : '') . 'l.employee_id'
            . (dsc_has_cols($c, 'employees', ['emp_code']) ? ', e.emp_code' : '')
            . ', l.type_name, YEAR(l.from_date) AS leave_year,
               SUM(CASE WHEN l.status = \'approved\' THEN l.days ELSE 0 END) AS approved_days,
               SUM(CASE WHEN l.status = \'pending\'  THEN l.days ELSE 0 END) AS pending_days';
        if (dsc_has_cols($c, 'leave_types', ['quota'])) {
            $sel .= ', t.quota AS declared_quota';
        }
        if (dsc_has_cols($c, 'leave_types', ['paid'])) {
            $sel .= ', t.paid AS declared_paid';
        }
        if (dsc_has_cols($c, 'leave_types', ['carry_forward'])) {
            $sel .= ', t.carry_forward';
        }
        $j = dsc_has_table($c, 'employees') ? ' LEFT JOIN employees e ON e.id = l.employee_id' : '';
        if (dsc_has_cols($c, 'leave_types', ['name'])) {
            $on = 't.name = l.type_name' . ($has('tenant_id') && dsc_has_cols($c, 'leave_types', ['tenant_id'])
                ? ' AND t.tenant_id = l.tenant_id' : '');
            $j .= " LEFT JOIN leave_types t ON $on";
        }
        dsc_write_csv($c, 'leave/derivation.csv',
            dsc_q($c, "SELECT $sel FROM leaves l$j GROUP BY $g ORDER BY $g", [], 'd8.5'),
            ['employee_id', 'emp_code', 'name', 'employee']);
        $c->finding('HIGH', 'leave', 'Derived leave balances written to leave/derivation.csv',
            'R-XXIX-026: this is EVIDENCE for the customer, not an opening balance. Deriving balances from '
            . 'leave_types.carry_forward would silently grant balances nobody ever accrued, because '
            . 'carry-forward was never applied. The signed per-employee statement is the authority.');
    }

    // D8.6  Calendar-days-charged vs working-days-credited divergence.
    if ($has('from_date') && $has('to_date')) {
        $n = (int) dsc_val($c, "SELECT COUNT(*) FROM leaves
                                 WHERE status = 'approved' AND DATEDIFF(to_date, from_date) >= 2
                                   AND (WEEKDAY(from_date) >= 4 OR WEEKDAY(to_date) <= 1)", [], 'd8.6', 0);
        if ($n > 0) {
            $c->finding('MEDIUM', 'leave', "$n approved leaves span a weekend",
                'A Fri–Mon leave costs 4 days of balance and credits 2 days of attendance. R-XXIX-027: the '
                . 'recorded days value migrates AS RECORDED, marked basis = legacy_calendar_days, and must '
                . 'NOT be recomputed under a working-day rule — that would change history. '
                . 'PRODUCT OWNER DECISION (E-… PO-07).',
                ['leaves' => $n]);
        }
    }

    dsc_finish($c, 'd8_leave');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d8_leave.php', 'd8_leave');
}
