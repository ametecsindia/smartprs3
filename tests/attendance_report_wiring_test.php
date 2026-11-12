<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\AttendanceReport;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

/**
 * THE MUSTER SAYS WHAT PAYROLL PAYS (owner, 8 Oct): a leave spanning a weekly
 * off leaves the off day an off day, unpaid leave is shown apart from paid
 * leave, approved leave decides a punched-but-absent day, and nothing after the
 * exit date is an absence.
 */

group('attendance report · wiring');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/arw-' . bin2hex(random_bytes(4))))->forTenant('1');
$emp = ['id' => '1', 'emp_code' => 'E-L', 'name' => 'L', 'doj' => '2026-01-01', 'exit_date' => '2026-10-20', 'status' => 'active'];
$store->put('employees', $emp);
$policy = new LeavePolicy($root . '/config/leave-policy.json');
$attendance = new Attendance($store, new DayRules($root . '/config/attendance-rules.json'), new Calendar($root . '/config/calendar.json'));
$attendance->record('E-L', '2026-10-14 09:00:00', 'in', 'device');
$attendance->record('E-L', '2026-10-14 10:00:00', 'out', 'device');

foreach ([['CL', '2026-10-09', '2026-10-12'], ['LOP', '2026-10-13', '2026-10-13'], ['CL', '2026-10-14', '2026-10-14']] as $i => [$t, $f, $to]) {
    $store->put('leaves', ['employment_id' => 'E-L', 'state' => 'approved', 'leave_type' => $t, 'from' => $f, 'to' => $to,
        'requested_at' => "2026-09-1{$i} 10:00:00"]);
}

$report = new AttendanceReport($store, $attendance, new Leave($store, $policy));
$cells = $report->muster([$emp], '2026-10-01', '2026-10-31', '09:00', '18:00', '2026-10-31')['rows'][0]['cells'];
same(AttendanceReport::ON_LEAVE, $cells['2026-10-09']['status'], 'Friday inside the CL is leave');
same('WEEKLY_OFF', $cells['2026-10-10']['status'], 'Saturday inside the CL stays a weekly off');
same('WEEKLY_OFF', $cells['2026-10-11']['status'], 'and Sunday');
same(AttendanceReport::ON_LEAVE, $cells['2026-10-12']['status'], 'Monday inside the CL is leave again');
same(AttendanceReport::UNPAID_LEAVE, $cells['2026-10-13']['status'], 'LOP shows as unpaid leave');
same('LOP', AttendanceReport::abbreviation(AttendanceReport::UNPAID_LEAVE), 'and prints LOP on the grid');
same(AttendanceReport::ON_LEAVE, $cells['2026-10-14']['status'], 'an hour punched on a CL day is the leave, not ABSENT');
same('HOLIDAY', $cells['2026-10-02']['status'], 'Gandhi Jayanti from the calendar');
same(AttendanceReport::NOT_JOINED, $cells['2026-10-21']['status'], 'after the exit date nobody is absent');
