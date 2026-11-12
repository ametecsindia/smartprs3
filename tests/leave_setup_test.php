<?php

declare(strict_types=1);

/**
 * LEAVE TYPES, THE HOLIDAY CALENDAR AND GRANTS ARE THE TENANT'S OWN, VERSIONED —
 * and a grant reaches a GROUP, lands on the ledger once, and lapses when it says so.
 */

use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeaveGrants;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

group('leave setup');

$store = (new Store(sys_get_temp_dir() . '/prs3-lset-' . bin2hex(random_bytes(4))))->forTenant('1');
$cust = new Customisation($store);
$policy = new LeavePolicy(dirname(__DIR__) . '/config/leave-policy.json', $cust);

ok(!$policy->isCustomised(), 'shipped until saved');
same(true, $policy->types()['EL']['carry_forward'], 'earned leave carries forward');
same(false, $policy->types()['CL']['carry_forward'], 'casual does not');
ok(!$policy->saveTypes([['code' => 'bad code', 'name' => 'x']], '1', 'hr')['ok'], 'a code with a space is refused');
ok(!$policy->saveTypes([['code' => 'ML', 'name' => '']], '1', 'hr')['ok'], 'a nameless type is refused');
ok(!$policy->saveTypes([['code' => 'ML', 'name' => 'M', 'maximum_days' => 'ten']], '1', 'hr')['ok'], '"ten" is not days');
ok(!$policy->saveTypes([['code' => 'ML', 'name' => 'M'], ['code' => 'ml', 'name' => 'M2']], '1', 'hr')['ok'], 'declared twice (case-insensitively) is refused');
ok(!$policy->saveTypes([[], []], '1', 'hr')['ok'], 'all-blank rows leave no types, which is refused');
ok($policy->saveTypes([['code' => 'EL', 'name' => 'Earned', 'paid' => 'yes', 'accrual_days_per_month' => '1.5', 'maximum_days' => '45', 'carry_forward' => 'yes'],
    ['code' => 'ML', 'name' => 'Maternity', 'paid' => 'yes', 'maximum_days' => '182', 'carry_forward' => 'no'], []], '1', 'hr')['ok'], 'a list is saved');
ok($policy->isCustomised(), 'now the tenant\'s own');
same(['EL', 'ML'], array_keys($policy->types()), 'the saved list REPLACES the shipped one — CL is gone because the customer removed it');
same('0', $policy->types()['ML']['opening_days'], 'a blank number is zero');
same(false, $policy->types()['ML']['carry_forward'], 'carry forward as given');
same(1, count($policy->history()), 'one version');

$calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json', $cust);
ok(in_array('Government', $calendar->holidayTypes(), true), 'holiday types are declared in the file');
ok(!$calendar->saveCalendar([['on' => '26-01-2026', 'name' => 'x']], ['Sun'], '1', 'hr')['ok'], 'a bad date is refused');
ok(!$calendar->saveCalendar([['on' => '2026-01-26', 'name' => '']], ['Sun'], '1', 'hr')['ok'], 'a nameless holiday is refused');
ok(!$calendar->saveCalendar([['on' => '2026-01-26', 'name' => 'x', 'type' => 'Picnic']], ['Sun'], '1', 'hr')['ok'], 'an undeclared type is refused');
ok(!$calendar->saveCalendar([], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], '1', 'hr')['ok'], 'every day off is refused');
ok($calendar->saveCalendar([['on' => '2026-03-04', 'name' => 'Holi', 'type' => 'Festival'], ['on' => '2026-01-26', 'name' => 'Republic Day', 'type' => 'Government'], []], ['Sun', 'sat'], '1', 'hr')['ok'], 'saved');
$calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json', $cust);
same(['Sun', 'Sat'], $calendar->weeklyOff(), 'weekly offs as given, case-fixed');
same(['2026-01-26' => 'Republic Day', '2026-03-04' => 'Holi'], $calendar->holidays(), 'holidays sorted, the shipped ones replaced');
same('09:00', $calendar->shiftStart(), 'the shift is still the file\'s — not a holiday setting');
ok(!$calendar->isWorkingDay('2026-03-04'), 'the muster sees the customised holiday');

$leave = new Leave($store, $policy);
$grants = new LeaveGrants($cust, $policy);
// Gender is on every record: ML is hidden while gender is blank (owner, 10 Oct — fails closed).
$emps = [
    ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'branch' => 'Mumbai', 'department' => 'Ops', 'doj' => '2024-01-01', 'gender' => 'Female'],
    ['id' => '2', 'emp_code' => 'B2', 'name' => 'B', 'branch' => 'Mumbai', 'department' => 'Legal', 'doj' => '2026-05-10', 'gender' => 'Female'],
    ['id' => '3', 'emp_code' => 'C3', 'name' => 'C', 'branch' => 'Pune', 'department' => 'Ops', 'doj' => '2024-01-01', 'gender' => 'Female'],
    ['id' => '4', 'emp_code' => 'D4', 'name' => 'D', 'branch' => 'Mumbai', 'department' => 'Ops', 'doj' => '2027-02-01', 'gender' => 'Female'],
];
ok(!$grants->add(['type' => 'CL', 'days' => '5'], '1', 'hr')['ok'], 'a type this tenant no longer has is refused');
ok(!$grants->add(['type' => 'ML', 'days' => '0'], '1', 'hr')['ok'], 'zero days is not a grant');
ok(!$grants->add(['type' => 'ML', 'days' => 'five'], '1', 'hr')['ok'], 'nor is "five"');
$g = $grants->add(['type' => 'ML', 'days' => '5', 'carry_forward' => 'no', 'branch' => 'mumbai', 'from_year' => '2026', 'note' => 'x'], '1', 'hr');
ok($g['ok'], 'a grant is added');
same(3, count(LeaveGrants::reach($g['grant'], $emps, [])), 'it reaches the three in Mumbai, case-insensitively');
$g2 = $grants->add(['type' => 'EL', 'days' => '2', 'branch' => 'Mumbai', 'department' => 'Ops'], '1', 'hr')['grant'];
same(2, count(LeaveGrants::reach($g2, $emps, [])), 'two scopes both must match');
same(1, count(LeaveGrants::reach($grants->add(['type' => 'EL', 'days' => '1', 'employee' => 'c3'], '1', 'hr')['grant'], $emps, [])), 'a single employee by code');

$r = $grants->apply('2026', $emps, [], $leave);
same(4, $r['posted'], 'A1+B2 for ML, A1 for EL/Ops, C3 by code — D4 joins in 2027 and gets nothing this year');
same('5', $leave->balance('A1', 'ML', new DateTimeImmutable('2026-06-01'))->toDecimalString(), 'A1 has the five');
same('0', $leave->balance('B2', 'ML', new DateTimeImmutable('2026-05-01'))->toDecimalString(), 'B2 had nothing before joining');
same('5', $leave->balance('B2', 'ML', new DateTimeImmutable('2026-06-01'))->toDecimalString(), 'and the five from the joining date');
same(0, $grants->apply('2026', $emps, [], $leave)['posted'], 'applying again posts nothing');
same(4, $grants->apply('2026', $emps, [], $leave)['already'], 'all four already there');

$l = $grants->lapse('2026', $emps, [], $leave);
same(2, $l['lapsed'], 'the non-carry-forward grant lapses for A1 and B2 (D4 has nothing yet)');
same('0', $leave->balance('A1', 'ML', new DateTimeImmutable('2027-01-01'))->toDecimalString(), 'A1 starts 2027 at zero ML');
same('2', $leave->balance('A1', 'EL', new DateTimeImmutable('2027-01-01'))->toDecimalString(), 'but keeps the EL, which carries forward');
same(0, $grants->lapse('2026', $emps, [], $leave)['lapsed'], 'lapsing again does nothing');

ok($grants->withdraw((string) $g2['id'], '1', 'hr')['ok'], 'withdrawn');
ok(!$grants->find((string) $g2['id'])['active'], 'inactive');
same('2', $leave->balance('A1', 'EL', new DateTimeImmutable('2027-01-01'))->toDecimalString(), 'what it posted stays');
same(1, $grants->apply('2027', [$emps[0]], [], $leave)['posted'], 'and it posts no more: A1 in 2027 gets only the ML grant');

// ------------------------------------------------------ weekly-off patterns
group('weekly off patterns');
$c3 = new Calendar(dirname(__DIR__) . '/config/calendar.json', $cust);
ok($c3->saveCalendar([], ['Sun'], '1', 'hr', ['Sat' => ['1', '4'], 'Mon' => [], 'Fri' => ['9']])['ok'], 'Every Sun + 1st and 4th Sat is saved (9 is not an occurrence, dropped)');
$c3 = new Calendar(dirname(__DIR__) . '/config/calendar.json', $cust);
same(['Sat' => [1, 4]], $c3->weeklyOffNth(), 'the occurrences are kept');
same('Every Sun + 1st and 4th Sat', $c3->weeklyOffLabel(), 'and read back in words');
ok($c3->isWeeklyOff('2026-09-06'), 'Sun 6 Sep is off');
ok($c3->isWeeklyOff('2026-09-05'), 'Sat 5 Sep is the 1st Saturday — off');
ok(!$c3->isWeeklyOff('2026-09-12'), 'Sat 12 Sep is the 2nd — working');
ok(!$c3->isWeeklyOff('2026-09-19'), 'the 3rd — working');
ok($c3->isWeeklyOff('2026-09-26'), 'the 4th — off');
ok(!$c3->isWeeklyOff('2026-09-07'), 'Monday is working');
ok(!$c3->isWorkingDay('2026-09-26'), 'the muster sees the 4th Saturday as off');
ok($c3->saveCalendar([], ['Sat', 'Sun'], '1', 'hr', ['Sat' => ['1']])['ok'], 'an occurrence on a day that is already off every week');
$c3 = new Calendar(dirname(__DIR__) . '/config/calendar.json', $cust);
same([], $c3->weeklyOffNth(), 'is dropped — every week already covers it');
same('Every Sat + Every Sun', $c3->weeklyOffLabel(), 'label for the plain pattern');
