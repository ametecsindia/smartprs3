<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\PayCycles;
use SmartPRS\Surface\Store;

group('pay cycles');

$store = (new Store(sys_get_temp_dir() . '/prs3-pc-' . bin2hex(random_bytes(4))))->forTenant('1');
$customisation = new Customisation($store);
$store->put('companies', ['id' => '1', 'name' => 'Alpha Ltd']);
$store->put('branches', ['id' => '5', 'name' => 'Kondapur', 'code' => 'HYD1']);
$store->put('departments', ['id' => '9', 'name' => 'Collections']);
$a = ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'employer_company_id' => '1', 'branch_id' => '5', 'department_id' => '9'];
$b = ['id' => '2', 'emp_code' => 'B1', 'name' => 'B', 'employer_company_id' => '1', 'branch' => 'Vizag'];
$c = ['id' => '3', 'emp_code' => 'C1', 'name' => 'C'];
$pc = new PayCycles($store, $customisation, __DIR__ . '/../config/pay-cycles.json');

same(null, $pc->for($a), 'no rules: nobody has a cycle');
$rules = [
    ['id' => 1, 'name' => 'Everyone', 'cycle' => 'monthly', 'cutoff' => 'calendar', 'pay_day' => 7, 'status' => 'active'],
    ['id' => 2, 'name' => 'Alpha', 'company' => 'alpha ltd', 'cycle' => 'monthly', 'cutoff' => '20', 'pay_day' => 5, 'status' => 'active'],
    ['id' => 3, 'name' => 'Kondapur', 'branch' => 'Kondapur', 'cycle' => 'monthly', 'cutoff' => 'custom', 'from_day' => 26, 'to_day' => 25, 'pay_day' => 1, 'status' => 'active'],
    ['id' => 4, 'name' => 'Off', 'employee' => 'A1', 'cycle' => 'weekly', 'cutoff' => 'calendar', 'pay_day' => 3, 'status' => 'inactive'],
];
$customisation->saveRaw($pc->key(), ['rules' => $rules], '1', 'test', 'rules');

same('Kondapur', $pc->for($a)['name'], 'the branch row beats the company row for A (branch resolved by id, company case-insensitively)');
same('Alpha', $pc->for($b)['name'], 'B is Alpha but not Kondapur: the company row');
same('Everyone', $pc->for($c)['name'], 'C matches nothing narrower: everyone');
ok($pc->for($a)['id'] !== 4, 'an inactive row, however specific, is ignored');
same(1, $pc->reach($rules[2], [$a, $b, $c]), 'reach counts the employees a row wins');

same(['2026-09-01', '2026-09-30'], PayCycles::window($rules[0], '2026-09'), 'calendar window');
same(['2026-08-21', '2026-09-20'], PayCycles::window($rules[1], '2026-09'), '21st to 20th');
same(['2026-08-26', '2026-09-25'], PayCycles::window($rules[2], '2026-09'), 'custom 26 → 25 spans the month boundary');
same(['2026-03-01', '2026-03-31'], PayCycles::window(['cutoff' => 'custom', 'from_day' => 1, 'to_day' => 31], '2026-03'), 'custom 1 → 31 is the month');
same(['2026-01-31', '2026-02-28'], PayCycles::window(['cutoff' => 'custom', 'from_day' => 31, 'to_day' => 30], '2026-02'), 'a 30th in February clamps to month end');

group('pay date — a window can close in one month and pay in a later one');
same('2026-09-07', PayCycles::payDate($rules[0], '2026-09'), 'no pay_month_offset on the rule: paid the same month as the period (unchanged behaviour)');
same('2026-09-05', PayCycles::payDate($rules[1], '2026-09'), 'Alpha: 21st-to-20th window, still paid the period\'s own month with no offset declared');
same('2026-09-01', PayCycles::payDate($rules[2], '2026-09'), 'Kondapur: a saved rule with no pay_month_offset keeps paying exactly where it always did — a version conflict, not a new default');
$ownerRule = ['id' => 5, 'name' => 'Field ops', 'cutoff' => 'custom', 'from_day' => 22, 'to_day' => 21, 'pay_day' => 1, 'pay_month_offset' => 1, 'status' => 'active'];
same(['2026-08-22', '2026-09-21'], PayCycles::window($ownerRule, '2026-09'), 'the owner\'s cycle: 22 Aug to 21 Sep is September\'s attendance window');
same('2026-10-01', PayCycles::payDate($ownerRule, '2026-09'), 'that window is paid 1 Oct — one month after the period it settles (pay_month_offset 1)');
same('2026-11-01', PayCycles::payDate(['pay_day' => 1, 'pay_month_offset' => 2], '2026-09'), 'pay_month_offset 2: two months after the period');
same('2026-02-28', PayCycles::payDate(['pay_day' => 31, 'pay_month_offset' => 1], '2026-01'), 'the pay day clamps to the target month\'s length, same as the window does');
same('2027-01-05', PayCycles::payDate(['pay_day' => 5, 'pay_month_offset' => 1], '2026-12'), 'an offset carries the pay date across a year boundary');
same('2026-09-10', PayCycles::payDate(['pay_day' => 10, 'pay_month_offset' => -3], '2026-09'), 'a negative offset is defensive-clamped to 0, never paid before the period');
same('2026-09-01', PayCycles::payDate(['pay_month_offset' => 0], '2026-09'), 'no pay_day at all defaults to the 1st rather than erroring');

group('working shifts by scope');
use SmartPRS\Surface\Shifts;
$store->put('shifts', ['id' => '1', 'name' => 'General', 'start_time' => '09:30', 'end_time' => '18:00', 'status' => 'active']);
$store->put('shifts', ['id' => '2', 'name' => 'Kondapur early', 'branch_id' => '5', 'start_time' => '08:00', 'end_time' => '16:30', 'status' => 'active']);
$store->put('shifts', ['id' => '3', 'name' => 'A night', 'employee_id' => '1', 'start_time' => '22:00', 'end_time' => '06:00', 'status' => 'inactive']);
$store->put('shifts', ['id' => '4', 'name' => 'Alpha', 'employer_company_id' => '1', 'start_time' => '10:00', 'end_time' => '19:00', 'status' => 'active']);
$sh = new Shifts($store);
same('Kondapur early', $sh->for($a)['name'], 'the branch shift beats the company shift');
same('Alpha', $sh->for($b)['name'], 'B: the company shift');
same('General', $sh->for($c)['name'], 'C: the shift with no scope at all');
same(['08:00', '16:30'], $sh->times($a, '09:00', '18:00'), 'times come from the winning shift');
same(['09:00', '18:00'], (new Shifts((new Store(sys_get_temp_dir() . '/prs3-pc-' . bin2hex(random_bytes(4))))->forTenant('1')))->times($a, '09:00', '18:00'), 'no shifts at all: the defaults');

group('shift full-day hours and break budget reach classification');
use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
$root = dirname(__DIR__);
$att = new Attendance($store, new DayRules($root . '/config/attendance-rules.json'), new Calendar($root . '/config/calendar.json'));
// 09:00–18:00 scheduled (540 min); a 45-minute lunch leaves 8h15 worked.
$store->put('employees', ['id' => '7', 'emp_code' => 'K1', 'name' => 'K', 'branch_id' => '5']);
$store->put('employees', ['id' => '8', 'emp_code' => 'C2', 'name' => 'C2']);
foreach (['K1', 'C2'] as $code) {
    $att->record($code, '2026-03-11 09:00:00', 'in');
    $att->record($code, '2026-03-11 13:00:00', 'out');
    $att->record($code, '2026-03-11 13:45:00', 'in');
    $att->record($code, '2026-03-11 18:00:00', 'out');
}
$store->put('shifts', ['id' => '2', 'name' => 'Kondapur early', 'branch_id' => '5', 'start_time' => '09:00', 'end_time' => '18:00', 'break_budget' => '60', 'status' => 'active']);
$store->put('shifts', ['id' => '1', 'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00', 'status' => 'active']);
$dayOf = static fn (Attendance $a, string $code): array => array_values(array_filter($a->settledDays($code, '09:00', '18:00'), static fn (array $d): bool => $d['date'] === '2026-03-11'))[0];
same('PRESENT', $dayOf($att, 'K1')['classification']['status'], 'a 45-minute lunch inside a 60-minute break budget is a full day');
same('HALF_DAY', $dayOf($att, 'C2')['classification']['status'], 'the same day on a shift with no budget is a half day (scheduled length is the bar)');
$store->put('shifts', ['id' => '1', 'full_day_hours' => '8', 'half_day_hours' => '4']);
$att2 = new Attendance($store, new DayRules($root . '/config/attendance-rules.json'), new Calendar($root . '/config/calendar.json'));
same('PRESENT', $dayOf($att2, 'C2')['classification']['status'], 'Full Day (hours) = 8 on the shift makes 8h15 a full day');
