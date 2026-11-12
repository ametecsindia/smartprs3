<?php

declare(strict_types=1);

/**
 * THE ABSENCE SWEEP DOES NOT DECIDE WHAT ABSENT IS. It asks the rules, skips
 * anybody with a punch or approved leave, tells nobody twice, and ships off.
 */

use SmartPRS\Surface\Absence;
use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\Store;

group('absence sweep');

$store = (new Store(sys_get_temp_dir() . '/prs3-absence-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha', 'status' => 'active']);
$store->put('employees', ['id' => '2', 'emp_code' => 'SP-002', 'name' => 'Ravi', 'status' => 'active', 'reporting_manager_id' => '1']);
$store->put('employees', ['id' => '3', 'emp_code' => 'SP-003', 'name' => 'Left', 'status' => 'exited']);
$store->put('employees', ['id' => '4', 'emp_code' => 'SP-004', 'name' => 'Leave', 'status' => 'active']);

$root = dirname(__DIR__);
$attendance = new Attendance($store, new DayRules($root . '/config/attendance-rules.json'),
    new Calendar($root . '/config/calendar.json'));
$attendance->record('SP-001', '2026-08-13 09:00:00', 'in');
$attendance->record('SP-001', '2026-08-13 18:00:00', 'out');
$leave = new Leave($store, new LeavePolicy($root . '/config/leave-policy.json'));
$store->put('leaves', ['id' => 1, 'employee_id' => 4, 'leave_type' => 'CL', 'from_date' => '2026-08-13',
    'to_date' => '2026-08-13', 'days' => '1.0000', 'status' => Leave::APPROVED, 'created_at' => '2026-08-01 09:00:00']);

$notifications = new Notifications($store, $root . '/config/notifications.json');
$customisation = new Customisation($store);
$absence = new Absence($root . '/config/absence.json', $customisation, $attendance, $notifications, $leave);

same(false, $absence->config()['enabled'], 'ships OFF, as declared');

// off: it still reports who was absent, and tells nobody
$r = $absence->run('2026-08-13', $store->all('employees'), '09:00', '18:00', '2026-08-14');
same(['SP-002'], $r['absent'], 'the one active employment with no punch and no leave on a working day');
same(1, $r['skipped']['present'], 'the punched one is skipped');
same(1, $r['skipped']['on_leave'], 'the one on approved leave is skipped');
same(1, $r['skipped']['inactive'], 'an exited employment is not absent, it is gone');
same(0, $r['notified'], 'and while the sweep is off, nobody is told');
same([], $absence->log(), 'so the log is empty');

// a weekly off is not an absence, by the rules
$sun = $absence->run('2026-08-16', $store->all('employees'), '09:00', '18:00', '2026-08-17');
same([], $sun['absent'], 'a Sunday is WEEKLY_OFF for everybody — the rule engine said so, not this class');
same(3, $sun['skipped']['non_working'], 'and every active employment is skipped as non-working');

// a holiday too
same([], $absence->run('2026-08-15', $store->all('employees'), '09:00', '18:00', '2026-08-17')['absent'],
    'Independence Day is HOLIDAY, not absence');

// today and the future are refused
throws(InvalidArgumentException::class, static fn () => $absence->run('2026-08-14', [], '09:00', '18:00', '2026-08-14'),
    'today is not swept — the punches have not all arrived');
throws(InvalidArgumentException::class, static fn () => $absence->run('14/08/2026', [], '09:00', '18:00', '2026-08-15'),
    'a date that is not a date is refused');

// on: employee and manager are told, once
$saved = $absence->saveConfig(['enabled' => '1', 'notify_employee' => '1', 'notify_manager' => '1'], 'u1', 'Admin');
ok($saved['ok'], 'the choice saves as a versioned customisation');
same(true, $absence->config()['notify_manager'], 'and reads back');

$r = $absence->run('2026-08-13', $store->all('employees'), '09:00', '18:00', '2026-08-14');
same(2, $r['notified'], 'the absent employee and their reporting manager are each queued a message');
$log = $absence->log();
same(2, count($log), 'both are in the log');
ok(str_contains((string) $log[0]['title'] . $log[1]['title'], 'SP-002'), 'naming the employment');
ok(str_contains(implode(' ', array_column($log, 'kind')), 'attendance.absent'), 'under the declared event');

$again = $absence->run('2026-08-13', $store->all('employees'), '09:00', '18:00', '2026-08-14');
same(0, $again['notified'], 'running the same morning twice tells nobody twice');
same(1, $again['already'], 'and says so');
same(2, count($absence->log()), 'the log did not grow');

// the sweep never writes attendance
same(0, count($attendance->punchesFor('SP-002')), 'no punch, no day, nothing written for the absent one');
