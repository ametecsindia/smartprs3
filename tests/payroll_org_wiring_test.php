<?php

declare(strict_types=1);

/**
 * PAYROLL HONOURS ORGANIZATION SETUP PER EMPLOYEE (owner, 28 Sep: "each and
 * everything should be wired properly and accurately calculated").
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\ComponentRules;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayCycles;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('payroll · org wiring');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-orgpay-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => 'C1', 'name' => 'Alpha']);
$store->put('departments', ['id' => 'D1', 'name' => 'Ops', 'branch_id' => 'B-HYD']);
$store->put('branches', ['id' => 'B-MUM', 'name' => 'Andheri', 'city' => 'Mumbai', 'state' => 'Maharashtra', 'employer_company_id' => 'C1']);
$store->put('branches', ['id' => 'B-HYD', 'name' => 'Kondapur', 'city' => 'Hyderabad', 'state' => 'Telangana', 'employer_company_id' => 'C1']);
$people = [
    'M1' => ['branch_id' => 'B-MUM'],
    'H1' => ['branch_id' => 'B-HYD', 'department_id' => 'D1'],
    'T1' => ['branch' => 'Andheri'],                      // ported: text branch only
    'P1' => ['branch_id' => 'B-HYD', 'pcc_status' => 'Pending'],
];
foreach ($people as $code => $extra) {
    $store->put('employees', ['id' => $code, 'emp_code' => $code, 'name' => $code, 'ctc' => '600000.00', 'doj' => '2026-01-01',
        'status' => 'active', 'employer_company_id' => 'C1'] + $extra);
    $store->put('leaves', ['id' => 'L-' . $code, 'employment_id' => $code, 'state' => 'approved', 'leave_type' => 'EL',
        'from' => '2026-10-01', 'to' => '2026-10-31', 'requested_at' => '2026-09-01T00:00:00+05:30']);
}

$customisation = new Customisation($store);
$rules = new ComponentRules($store, $root . '/config/salary-components.json');
$cycles = new PayCycles($store, $customisation, $root . '/config/pay-cycles.json');
$published = $rules->publish([
    ['component' => 'HRA', 'calculation' => 'percent_of', 'percent' => '40', 'basis_column' => 'BASIC', 'calc_mode' => 'government'],
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '222', 'company' => 'Alpha', 'branch' => 'Kondapur'],
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '111', 'employee' => 'H1'],
    ['component' => 'MEDICAL', 'calculation' => 'flat', 'amount' => '333', 'department' => 'Ops'],
    ['component' => 'PCC_DEDUCTION', 'calculation' => 'employee_status', 'status_field' => 'pcc_status', 'status_condition' => 'Pending', 'status_amount' => '500'],
], '2026-01-01', 'test');
same([], $published['refused'], 'the rules publish');

$policy = new LeavePolicy($root . '/config/leave-policy.json');
$calendar = new Calendar($root . '/config/calendar.json');
$register = new Register($store, new PayrollPeriod(new Attendance($store), new Leave($store, $policy), $policy, $calendar),
    new Structure($store, $root . '/config/salary-structure.json'), cycles: $cycles, componentRules: $rules);
$closed = $register->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
ok($closed['ok'], 'the month closes — a status rule no longer aborts it: ' . ($closed['why'] ?? ''));
$rows = $closed['run']['rows'] ?? [];
$line = static function (string $code, string $component) use ($rows): ?int {
    foreach ((array) ($rows[$code]['lines'] ?? []) as $l) {
        if ((string) $l['code'] === $component) {
            return (int) $l['minor'];
        }
    }

    return null;
};

$basic = static fn (string $code): int => (int) ($line($code, 'C-BASIC') ?? $line($code, 'BASIC') ?? 0);
same(intdiv($basic('M1') * 50 + 50, 100), $line('M1', 'HRA'), 'Mumbai (metro) gets 50% HRA');
// 16 Oct 2026: Hyderabad joined the 50% (metro) list for Government HRA (Income-tax Rules 2026).
same(intdiv($basic('H1') * 50 + 50, 100), $line('H1', 'HRA'), 'Hyderabad gets 50% (metro since 01-04-2026) — read from its own branch city, not whichever branch was grouped first');
same(intdiv($basic('T1') * 50 + 50, 100), $line('T1', 'HRA'), 'a text-only branch is found by name — Mumbai, 50%');
same(11100, $line('H1', 'CONVEYANCE'), 'an employee-specific rule beats a company+branch rule');
same(22200, $line('P1', 'CONVEYANCE'), 'and the company+branch rule still applies to everybody else there');
same(50000, $line('P1', 'PCC_DEDUCTION'), 'PCC Pending deducts the status amount');
same(0, (int) ($line('H1', 'PCC_DEDUCTION') ?? 0), 'nobody else pays it');
$verified = $register->verify('2026-10');
ok($verified['reproduced'], 'the month reproduces to the paisa: ' . $verified['why']);
same(33300, $line('H1', 'MEDICAL'), 'a department-scoped rule reaches the department');
same(null, $line('M1', 'MEDICAL'), 'and nobody outside it');
same('B-MUM', (string) ($rows['M1']['as_closed']['branch_id'] ?? ''), 'the run remembers the branch the person was in when it closed, for the payslip header');
