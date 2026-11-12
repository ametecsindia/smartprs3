<?php

declare(strict_types=1);

/**
 * THE RUN, NOT THE DAY. `UnauthorizedAbsence` escalates a run of ABSENT
 * working days as it crosses each day count the tenant names — this checks
 * that a run's length survives a weekly off/holiday inside it, that each
 * stage fires once at the day count it is due, that dedupe keys are stable
 * across two sweeps of the same run, and that a run broken by a real punch
 * or approved leave does not keep growing.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\UnauthorizedAbsence;

group('unauthorized absence');

function uaFresh(): array
{
    $store = (new Store(sys_get_temp_dir() . '/prs3-ua-' . bin2hex(random_bytes(4))))->forTenant('1');
    $rules = new DayRules(dirname(__DIR__) . '/config/attendance-rules.json');
    $calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json');
    $attendance = new Attendance($store, $rules, $calendar);
    $customisation = new Customisation($store);
    $notifications = new Notifications($store, dirname(__DIR__) . '/config/notifications.json');
    $leave = new Leave($store, new LeavePolicy(dirname(__DIR__) . '/config/leave-policy.json'));
    $ua = new UnauthorizedAbsence(dirname(__DIR__) . '/config/unauthorized-absence.json',
        $customisation, $attendance, $notifications, $leave, $store);

    return [$store, $attendance, $ua];
}

// ---------------------------------------------------------- a run across a weekly off
// Mon 2026-08-10 .. Fri 2026-08-14 are working days; Sat 15 is a holiday, Sun 16 weekly off.
// No punches at all for SP-001 across the whole window: absent Mon..Fri, and the
// weekend on either side neither counts nor breaks the run.
[$store, $attendance, $ua] = uaFresh();
// Joined ON the Monday, so the backward walk cannot run past it looking for
// a boundary — it hits the join date and stops there, cleanly bounding the run.
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha Rao', 'status' => 'active',
    'doj' => '2026-08-10']);

$sweep = $ua->run('2026-08-14', $store->all('employees'), '09:00', '18:00', '2026-08-17');
same(1, $sweep['evaluated'], 'one employee evaluated');
same(false, $sweep['enabled'], 'escalation ships OFF by default — the same rule Absence ships with');
$byStage = [];
foreach ($sweep['escalated'] as $e) { $byStage[$e['stage']] = $e['run_days']; }
same(5, $byStage['abscond'] ?? null, 'Mon..Fri is a 5-day run by Friday — abscond (>=3) fires');
same(5, $byStage['first_warning'] ?? null, 'first warning (>=3) also fires at 5 days');
ok(!isset($byStage['second_warning']), 'second warning (default 7) has not been reached yet at 5 days');

// ---------------------------------------------------------- a real punch breaks the run
[$store, $attendance, $ua] = uaFresh();
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-002', 'name' => 'Ravi Kumar', 'status' => 'active',
    'doj' => '2026-01-01']);
$attendance->record('SP-002', '2026-08-11 09:00:00', 'in', 'device');
$attendance->record('SP-002', '2026-08-11 18:00:00', 'out', 'device');
// Present on the 11th, absent 12, 13, 14 — a fresh 3-day run, not one that
// reaches back through the punch to whatever came before it.
$sweep = $ua->run('2026-08-14', $store->all('employees'), '09:00', '18:00', '2026-08-17');
$row = $sweep['escalated'][0] ?? null;
same('abscond', $row['stage'] ?? null, 'a run interrupted by a real punch restarts');
same(3, $row['run_days'] ?? null, 'the run ending on the 14th is only 3 days (12th-14th) — the punch on the 11th broke it, however far absence went before that');

// ---------------------------------------------------------- approved leave breaks the run
[$store, $attendance, $ua] = uaFresh();
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-003', 'name' => 'Leena', 'status' => 'active',
    'doj' => '2026-01-01']);
$store->put('leaves', ['id' => 1, 'employee_id' => 1, 'type_id' => null, 'leave_type' => 'CL',
    'from_date' => '2026-08-11', 'to_date' => '2026-08-11', 'days' => '1.0000',
    'status' => Leave::APPROVED, 'reason' => 'x', 'created_at' => '2026-08-01 09:00:00']);
$sweep = $ua->run('2026-08-14', $store->all('employees'), '09:00', '18:00', '2026-08-17');
$row = $sweep['escalated'][0] ?? null;
same(3, $row['run_days'] ?? null, 'an approved leave on the 11th ends the run there too, same as a punch');

// ---------------------------------------------------------- dedupe across two sweep dates
[$store, $attendance, $ua] = uaFresh();
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-004', 'name' => 'Kiran', 'status' => 'active',
    'doj' => '2026-08-10']);
$ua->saveConfig(['enabled' => '1', 'abscond_days' => '3', 'first_warning_day' => '3',
    'second_warning_day' => '7', 'final_fnf_day' => '15',
    'always_cc_designations' => []], 'u1', 'Tester');
$sweepA = $ua->run('2026-08-13', $store->all('employees'), '09:00', '18:00', '2026-08-17'); // 4-day run (10-13)
$sweepB = $ua->run('2026-08-14', $store->all('employees'), '09:00', '18:00', '2026-08-17'); // same run, now 5 days
ok($sweepA['notified'] > 0, 'the first sweep queues messages for the run');
same(0, $sweepB['notified'], 'day 14 still only crosses the SAME stages (abscond+first_warning) already queued for this run start, so nothing new is queued a second time for them');
same($sweepA['notified'], $sweepB['already'],
    'the dedupe key is anchored to the run\'s start date, not the sweep date — so re-running for a later day in the same run finds every earlier message already queued, not a fresh one');

// ---------------------------------------------------------- day-count validation
[$store, $attendance, $ua] = uaFresh();
$bad = $ua->saveConfig(['enabled' => '0', 'abscond_days' => '5', 'first_warning_day' => '3',
    'second_warning_day' => '7', 'final_fnf_day' => '15',
    'always_cc_designations' => []], 'u1', 'Tester');
ok(!$bad['ok'], 'a First Warning before Abscond is refused — the stages must be in ascending order');

$badNeg = $ua->saveConfig(['enabled' => '0', 'abscond_days' => '-1', 'first_warning_day' => '3',
    'second_warning_day' => '7', 'final_fnf_day' => '15',
    'always_cc_designations' => []], 'u1', 'Tester');
ok(!$badNeg['ok'], 'a negative day count is refused');

$good = $ua->saveConfig(['enabled' => '1', 'abscond_days' => '3', 'first_warning_day' => '3',
    'second_warning_day' => '7', 'final_fnf_day' => '15',
    'always_cc_designations' => ['HR Manager']], 'u1', 'Tester');
ok($good['ok'], 'ascending, non-negative day counts save cleanly');
same(['HR Manager'], $ua->config()['always_cc_designations'], 'always_cc_designations round-trips');

// ---------------------------------------------------------- a future date is refused
[$store, $attendance, $ua] = uaFresh();
throws(InvalidArgumentException::class, static fn () => $ua->run('2026-08-17', [], '09:00', '18:00', '2026-08-17'),
    'the date swept must be over — the same rule Absence::run() applies');

// ---------------------------------------------------------- recipients: hierarchy walk (unconditional) + always-cc
// The hierarchy notify-designations filter is gone — every reporting-chain
// member is told, full stop. B and C (A's manager and A's manager's
// manager) are both in A's chain, so both come back 'hierarchy' even
// though C also holds an always-cc designation: chain membership is
// decided first, and a code already placed is never re-tagged. D holds
// the same always-cc designation but is NOT in A's chain at all — D is
// the one this test can actually attribute to the always-cc list.
[$store, $attendance, $ua] = uaFresh();
$store->put('employees', ['id' => '10', 'emp_code' => 'E-A', 'name' => 'Employee A', 'status' => 'active',
    'doj' => '2026-08-10', 'reporting_manager_id' => '11', 'designation_id' => '', 'designation' => 'Executive']);
$store->put('employees', ['id' => '11', 'emp_code' => 'E-B', 'name' => 'Manager B', 'status' => 'active',
    'doj' => '2026-01-01', 'reporting_manager_id' => '12', 'designation_id' => '', 'designation' => 'Team Lead']);
$store->put('employees', ['id' => '12', 'emp_code' => 'E-C', 'name' => 'Manager C', 'status' => 'active',
    'doj' => '2026-01-01', 'designation_id' => '', 'designation' => 'HR Manager']);
$store->put('employees', ['id' => '13', 'emp_code' => 'E-D', 'name' => 'HR Manager D', 'status' => 'active',
    'doj' => '2026-01-01', 'designation_id' => '', 'designation' => 'HR Manager']);
$ua->saveConfig(['enabled' => '1', 'abscond_days' => '3', 'first_warning_day' => '3',
    'second_warning_day' => '7', 'final_fnf_day' => '15',
    'always_cc_designations' => ['HR Manager']], 'u1', 'Tester');
$sweep = $ua->run('2026-08-13', $store->all('employees'), '09:00', '18:00', '2026-08-17');
$log = $ua->log(100);
$recipientOf = static function (array $r): string {
    $meta = json_decode((string) ($r['meta'] ?? ''), true);

    return is_array($meta) ? (string) ($meta['recipient'] ?? '') : '';
};
$hierarchyHitsB = array_filter($log, static fn (array $r): bool => $recipientOf($r) === 'E-B'
    && str_contains((string) $r['kind'], 'hierarchy'));
$hierarchyHitsC = array_filter($log, static fn (array $r): bool => $recipientOf($r) === 'E-C'
    && str_contains((string) $r['kind'], 'hierarchy'));
$ccHitsD = array_filter($log, static fn (array $r): bool => $recipientOf($r) === 'E-D'
    && str_contains((string) $r['kind'], 'cc'));
ok(count($hierarchyHitsB) > 0, "Employee A's manager (B) is notified via the unconditional hierarchy walk");
ok(count($hierarchyHitsC) > 0, "Employee A's manager's manager (C) is notified via the same chain walk, "
    . 'even though C also holds an always-cc designation — chain membership is decided first');
ok(count($ccHitsD) > 0, 'D, an HR Manager NOT in the chain, is still always copied');
