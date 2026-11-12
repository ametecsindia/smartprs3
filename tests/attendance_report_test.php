<?php

declare(strict_types=1);

/**
 * THE MUSTER AGREES WITH THE REGISTER, BECAUSE IT IS THE REGISTER'S ARITHMETIC.
 *
 * SmartPRS 2's attendance report pairs punches a second time, judges lateness
 * against a second shift and fills empty days with demo punches. Each of those
 * is a case below: a cell's figures are the settlement's figures; an empty day is
 * classified by the rules (or ON_LEAVE from an approved request), never invented;
 * a future day is blank and not ABSENT.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\AttendanceReport;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

group('attendance report');

$store = (new Store(sys_get_temp_dir() . '/prs3-muster-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha Rao', 'department' => 'Collections']);
$store->put('employees', ['id' => '2', 'emp_code' => 'SP-002', 'name' => 'Ravi Kumar']);
$store->put('employees', ['id' => '3', 'name' => 'No Code']);

$rules = new DayRules(dirname(__DIR__) . '/config/attendance-rules.json');
$calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json');
$attendance = new Attendance($store, $rules, $calendar);

// A working week in August 2026: Mon 10 … Fri 14, Sat 15 is Independence Day, Sun 16 weekly off.
$attendance->record('SP-001', '2026-08-10 09:02:00', 'in', 'device');
$attendance->record('SP-001', '2026-08-10 13:00:00', 'out', 'device');
$attendance->record('SP-001', '2026-08-10 13:40:00', 'in', 'device');
$attendance->record('SP-001', '2026-08-10 18:05:00', 'out', 'device');
$attendance->record('SP-001', '2026-08-11 09:40:00', 'in', 'device');   // late, and no out
$attendance->record('SP-001', '2026-08-12 09:00:00', 'in', 'bulk');
$attendance->record('SP-001', '2026-08-12 18:00:00', 'out', 'bulk');
// 13th: nothing. 14th: on approved leave (below). 15th holiday, 16th weekly off.

$leave = new Leave($store, new LeavePolicy(dirname(__DIR__) . '/config/leave-policy.json'));
$store->put('leaves', ['id' => 9, 'employee_id' => 1, 'type_id' => null, 'leave_type' => 'CL',
    'from_date' => '2026-08-14', 'to_date' => '2026-08-14', 'days' => '1.0000',
    'status' => Leave::APPROVED, 'reason' => 'x', 'created_at' => '2026-08-01 09:00:00']);

$report = new AttendanceReport($store, $attendance, $leave);

// --------------------------------------------------------------- ranges
same(7, count(AttendanceReport::dates('2026-08-10', '2026-08-16')), 'a week is seven dates');
same('2026-08-10', AttendanceReport::dates('2026-08-16', '2026-08-10')[0], 'a reversed range is turned round, not refused');
throws(InvalidArgumentException::class, static fn () => AttendanceReport::dates('2026-01-01', '2026-06-01'),
    'a range wider than two months is refused — export month by month');
throws(InvalidArgumentException::class, static fn () => AttendanceReport::dates('10/08/2026', '2026-08-16'),
    'a date that is not Y-m-d is refused rather than guessed');

// ---------------------------------------------------------------- muster
$m = $report->muster($store->all('employees'), '2026-08-10', '2026-08-16', '09:00', '18:00', '2026-08-15');

same(2, count($m['rows']), 'one row per employment that has a code — the codeless one cannot be found by a punch');
same('SP-001', $m['rows'][0]['code'], 'in code order');
$asha = $m['rows'][0]['cells'];

// Monday: two sessions, one break
same('09:02', $asha['2026-08-10']['first_in'], 'first in is the first in-punch');
same('18:05', $asha['2026-08-10']['last_out'], 'last out is the last out-punch');
same(238 + 265, $asha['2026-08-10']['worked_minutes'], 'worked is the sum of the sessions — the settlement\'s figure, not a second pairing');
same(40, $asha['2026-08-10']['break_minutes'], 'the gap between the sessions is the break');
same(2, $asha['2026-08-10']['sessions'], 'two sessions');
same(0, $asha['2026-08-10']['late_minutes'], '09:02 is inside the five-minute grace the calendar declares');
ok(!$asha['2026-08-10']['needs_regularisation'], 'a fully paired day needs nothing');

// Tuesday: late and unpaired
same(35, $asha['2026-08-11']['late_minutes'], '09:40 against a 09:00 shift with 5 minutes grace is 35 late');
same('09:40', $asha['2026-08-11']['first_in'], 'an unpaired in-punch is still a first in');
same(null, $asha['2026-08-11']['last_out'], 'and there is no last out to report');
ok($asha['2026-08-11']['needs_regularisation'], 'an unpaired punch is flagged');
same('HALF_DAY', $asha['2026-08-11']['status'], 'and the published rule says HALF_DAY, not ABSENT — the person was demonstrably here');

// Wednesday: bulk punches settle exactly like device ones
same(540, $asha['2026-08-12']['worked_minutes'], 'a bulk-uploaded day settles like any other');
same('PRESENT', $asha['2026-08-12']['status'], 'and is present');

// Thursday: nothing at all
same('ABSENT', $asha['2026-08-13']['status'], 'a working day with no punches is ABSENT — by the published rule, not by this class');
same(null, $asha['2026-08-13']['first_in'], 'with nothing invented for it');
ok(str_contains($asha['2026-08-13']['why'], 'no punch') || $asha['2026-08-13']['why'] !== '', 'and the rule\'s reason travels with the cell');

// Friday: approved leave
same(AttendanceReport::ON_LEAVE, $asha['2026-08-14']['status'], 'a day inside an approved leave is ON_LEAVE');
same(0, $asha['2026-08-14']['worked_minutes'], 'and worth nothing here — the leave ledger pays it');

// Saturday holiday (today), Sunday future
same('HOLIDAY', $asha['2026-08-15']['status'], 'the calendar\'s holiday classifies as HOLIDAY without a punch');
same(AttendanceReport::NOT_YET, $asha['2026-08-16']['status'], 'a day after today is blank');
ok($asha['2026-08-16']['status'] !== 'ABSENT', 'and is never ABSENT — it has not happened');

// totals
$t = $m['rows'][0]['totals'];
same(238 + 265 + 540, $t['worked_minutes'], 'worked minutes add up across the week (the unpaired day counts nothing)');
same(1, $t['late_days'], 'one late day');
same(1, $t['needing_regularisation'], 'one day needing attention');
same(1, $t['by_status']['ABSENT'], 'one absent');
same(1, $t['by_status']['HOLIDAY'], 'one holiday');
same(1, $t['by_status'][AttendanceReport::ON_LEAVE], 'one on leave');

// Ravi has no punches at all: five working days absent, not an empty row
$ravi = $m['rows'][1]['cells'];
same('ABSENT', $ravi['2026-08-10']['status'], 'somebody with no punches at all is ABSENT on a working day');
same('HOLIDAY', $ravi['2026-08-15']['status'], 'and HOLIDAY on the holiday');
same(5, $m['rows'][1]['totals']['by_status']['ABSENT'], 'five absences, Mon to Fri');
ok(($m['legend']['ABSENT'] ?? 0) >= 5, 'the legend counts every cell across the muster');

// --------------------------------------------------------------- drill-down
$day = $report->day('SP-001', '2026-08-10', '09:00', '18:00');
same(2, count($day['sessions']), 'the drill-down lists the sessions');
same('13:40', $day['sessions'][1]['in'], 'with each in');
same(40, $day['sessions'][1]['break_before'], 'and the break taken before it');
same(null, $day['sessions'][0]['break_before'], 'the first session has no break before it');
ok($day['trace'] !== '', 'and the settlement\'s own trace, so the figure explains itself (law 4)');

$empty = $report->day('SP-001', '2026-08-13', '09:00', '18:00');
same([], $empty['sessions'], 'an empty day drills down to nothing');
same('ABSENT', $empty['status'], 'and says what the rules made of it');

// ---------------------------------------------------------------- ratings
$rated = $report->rate('SP-001', '2026-08-10', 8, 'Good day', 'mgr@example');
ok($rated['ok'], 'a manager rates a day');
$m2 = $report->muster([$store->find('employees', '1')], '2026-08-10', '2026-08-10', '09:00', '18:00', '2026-08-15');
same(8, (int) $m2['rows'][0]['cells']['2026-08-10']['rating'], 'and the rating shows in the cell');
same('Good day', $m2['rows'][0]['cells']['2026-08-10']['remarks'], 'with the remark');

$again = $report->rate('SP-001', '2026-08-10', 6, 'Second look', 'mgr@example');
ok($again['ok'], 're-rating is allowed');
same(1, count(array_filter($store->all(AttendanceReport::RATINGS),
    static fn (array $r): bool => $r['emp_code'] === 'SP-001' && substr((string) $r['log_date'], 0, 10) === '2026-08-10')),
    'and keeps one row per employee-day, as the ported unique index requires');
ok(!$report->rate('SP-001', '2026-08-10', 11, '', 'x')['ok'], '11 is not a rating');
ok(!$report->rate('SP-001', '2026-08-10', null, '', 'x')['ok'], 'nothing is not a rating either');

// ------------------------------------------------------------------- export
$csv = $report->csv($m);
$lines = explode("\n", trim($csv));
same(3, count($lines), 'a header and one line per employment');
same('"Emp. Code",Name,Company,Branch,Department,"Reporting Manager",Designation,2026-08-10', substr($lines[0], 0, 85),
    'the grid export starts with the same employee columns as Attendance Logs (owner, 12 Oct), then the dates');
same('SP-001,"Asha Rao",,,Collections,,,', substr((string) array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'SP-001')))[0], 0, 34),
    'without org lookups the row still carries what the muster knows');
ok(str_contains($lines[1], ',HD,HD,P,A,L,H,,'), 'cells print as letters derived from the status (HD for a 503-minute Monday, HD, P, A, L for leave (29 Sep), H, blank future)');

same('P', AttendanceReport::abbreviation('PRESENT'), 'PRESENT is P');
same('WO', AttendanceReport::abbreviation('WEEKLY_OFF'), 'WEEKLY_OFF is WO');
same('W', AttendanceReport::abbreviation('WFH'), 'a status a tenant publishes tomorrow gets a letter without a code change');
same('?', AttendanceReport::abbreviation(DayRules::UNCLASSIFIED), 'an unclassified day is a question mark, never a P');
same('8h 05m', AttendanceReport::hm(485), 'minutes print as 8h 05m (owner rule: durations read 45m, 1h 05m)');

// ------------------------------------------------------- daily rows and columns
$byId = [];
foreach ($store->all('employees') as $e) { $byId[(string) $e['id']] = $e; }
$daily = $report->daily($m, $byId, ['companies' => [], 'employments' => []], '09:00–18:00',
    ['ON_LEAVE' => 'Leave', 'ABSENT' => 'Absent', 'PRESENT' => 'Present']);
same(12, count($daily), 'one row per employee-day that has happened: 2 employments × 6 days (the 16th is future)');
$fri = array_values(array_filter($daily, static fn (array $r): bool => $r['emp_code'] === 'SP-001' && $r['date'] === '2026-08-14'))[0];
same('Leave', $fri['status'], 'a day inside an approved leave prints Leave automatically');
$mon = array_values(array_filter($daily, static fn (array $r): bool => $r['emp_code'] === 'SP-001' && $r['date'] === '2026-08-10'))[0];
same('09:02 in · 13:00 out · 13:40 in · 18:05 out', $mon['logs'], 'the logs column lists every punch');
same(2, $mon['in_count'], 'IN counts the in-punches');
same('8h 23m', $mon['total_time'], 'total time is the settlement\'s worked minutes');
same('40m', $mon['break_time'], 'break time is the gap between sessions');

$columns = AttendanceReport::columns(dirname(__DIR__) . '/config/attendance-report.json', null);
same(27, count($columns['declared']), 'twenty-seven columns are declared: the original 19 plus 8 opt-in additions');
$onByDefault = array_keys(array_filter($columns['declared'], static fn (array $c): bool => $c['default']));
same($onByDefault, $columns['chosen'], 'only the columns declared default:true are shown until a tenant chooses');
same(20, count($onByDefault), 'the original 19 plus Reporting Manager (owner, 12 Oct) default on — the other optional columns did not widen anyone\'s existing report');
ok(!in_array('rating', $columns['chosen'], true), 'the new opt-in columns (e.g. rating) default OFF, unlike the original 19');
$csv = AttendanceReport::dailyCsv($daily, ['emp_code', 'date', 'status'], $columns['declared']);
same('"Emp. Code",Date,Status', explode("\n", $csv)[0], 'the export prints the chosen columns under their labels');

// -------------------------------------------------- the new opt-in columns carry real values
// $daily/$mon (built above) already carry every column, not just the chosen ones —
// daily() computes the full row regardless, and it is the screen/export that pick a
// subset by key — so the new fields are already there to check.
ok(array_key_exists('overtime_minutes', $mon), 'overtime_minutes is on every row, whether or not the tenant chose to print it');
ok(array_key_exists('early_exit_minutes', $mon), 'early_exit_minutes likewise');
same('No', $mon['regularisation'], 'a clean day needs no regularisation');
same('Active', $mon['employee_status'], 'an employee with no status recorded reads as Active, the same rule the escalation sweep uses');
