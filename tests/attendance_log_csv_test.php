<?php

declare(strict_types=1);

use SmartPRS\Surface\AttendanceReport;

/**
 * THE LOG BUTTON'S EXPORT — one employee, one day, every punch.
 *
 * The drill-down was the only view in the attendance report with nothing to
 * export, and it is the one somebody attaches to a regularisation email. The
 * two things worth pinning are the two that are easy to get wrong: a break
 * belongs to the session it precedes (not a row of its own), and an unpaired
 * punch is LISTED rather than dropped — a missing OUT is the usual reason
 * anybody opens this at all.
 */

group('Attendance log CSV');

$day = [
    'status' => 'PRESENT',
    'why' => 'Two sessions, 8h02m worked',
    'first_in' => '09:02',
    'last_out' => '18:44',
    'worked_minutes' => 482,
    'break_minutes' => 35,
    'late_minutes' => 2,
    'early_exit_minutes' => 0,
    'overtime_minutes' => 12,
    'sessions' => [
        ['in' => '09:02', 'out' => '13:31', 'minutes' => 269,
         'break_before' => null, 'in_source' => 'device', 'out_source' => 'device'],
        ['in' => '14:06', 'out' => '18:44', 'minutes' => 278,
         'break_before' => 35, 'in_source' => 'device', 'out_source' => 'manual'],
    ],
    'unpaired' => [
        ['direction' => 'in', 'at' => '19:10', 'source' => 'device'],
    ],
];

$csv = AttendanceReport::dayCsv($day, 'ABCR-2001', '2026-09-17');
$lines = array_values(array_filter(explode("\n", trim($csv)), static fn (string $l): bool => $l !== ''));

same('emp_code,date,entry,in,out,worked,break_before,in_source,out_source', $lines[0],   // durations as 45m / 1h 05m (owner, 23 Oct 2026)
    'the header names every column the screen shows');

// A BREAK BELONGS TO THE SESSION IT PRECEDES. The first session has no break
// before it and must export an EMPTY cell, not a 0 — nobody took a nought-
// minute break, and 0 in that column reads as one that was measured.
same('ABCR-2001,2026-09-17,"session 1",09:02,13:31,"4h 29m",,device,device', $lines[1],
    'the first session carries no break before it');
same('ABCR-2001,2026-09-17,"session 2",14:06,18:44,"4h 38m",35m,device,manual', $lines[2],
    'the 35-minute gap is the break before session 2');

// AN UNPAIRED PUNCH IS LISTED, NOT DROPPED. It lands in the column matching its
// direction so a reader can see WHICH half is missing.
same('ABCR-2001,2026-09-17,unpaired,19:10,,,,device,', $lines[3],
    'an unpaired IN fills the in column and leaves out blank');

// OUTSIDE THE SHIFT WINDOW IS LISTED, NOT DROPPED. ShiftWindow::assign() keeps
// these deliberately ("silently discarding a swipe is how somebody's overtime
// disappears") and the report used to throw them away again one layer up.
$withOutside = AttendanceReport::dayCsv(
    $day + ['outside_window' => [['at' => '04:12', 'direction' => 'in', 'source' => 'device', 'on' => '2026-09-17']]],
    'ABCR-2001', '2026-09-17');
ok(str_contains($withOutside, '"outside shift window",04:12,,,,device,'),
    'a punch no window covers is still in the export, marked and uncounted');

ok(str_contains($csv, 'status,first_in,last_out,worked,break'), 'the summary header is present');
ok(str_contains($csv, 'PRESENT,09:02,18:44,"8h 02m",35m,2m,0m,12m'), 'the summary line carries the totals');

// A day with nothing on it still exports a readable file rather than throwing —
// this is reachable from a Log button on a row whose punches were since removed.
$empty = AttendanceReport::dayCsv(
    ['status' => 'ABSENT', 'why' => 'No punch', 'sessions' => [], 'unpaired' => []],
    'ABCR-2002', '2026-09-18');
ok(str_contains($empty, 'ABSENT'), 'a day with no punches still exports its status');
ok(substr_count(trim($empty), "\n") >= 2, 'and is still a well-formed file');

// ---------------------------------------------------------------------------
// WHO THE TEAM LEAD IS (owner, 23 Sep): Employee -> Team Lead -> Manager ->
// Director, all of it the reporting line. The column printed blank on every row
// because attendanceLookups() maps a team with no leader to an EMPTY STRING and
// the old chain used `??`, which only falls through on null.

$lookups = [
    'team_leaders' => ['T1' => 'TL-001 · Priya Nair', 'T2' => ''],
    'employments'  => ['M9' => 'MGR-004 · Ravi Kumar'],
];

// THE REPORTING LINE LEADS, because the column is headed "Reporting Manager" —
// it must not quietly print a team's leader instead. The team is a FALLBACK.
same('MGR-004 · Ravi Kumar',
    AttendanceReport::reportingManagerOf($lookups, ['team_id' => 'T1', 'reporting_manager_id' => 'M9']),
    'the reporting manager wins even when the team names a leader');
same('TL-001 · Priya Nair',
    AttendanceReport::reportingManagerOf($lookups, ['team_id' => 'T1']),
    'no reporting manager falls back to the leader named on the team');

// THE BUG THIS REPLACES: team T2 exists with no leader, so the lookup is '' —
// not null. Under `??` that counted as an answer and the row printed blank.
same('Old Text Lead', AttendanceReport::reportingManagerOf($lookups, ['team_id' => 'T2', 'team_leader' => ' Old Text Lead ']),
    'a team with no leader falls THROUGH the empty string, which `??` would not');

same('MGR-004 · Ravi Kumar',
    AttendanceReport::reportingManagerOf($lookups, ['reporting_manager_id' => 'M9']),
    'no team at all still resolves, which is the whole point');

same('MGR-004 · Ravi Kumar',
    AttendanceReport::reportingManagerOf($lookups, ['team_leader' => 'Old Text Lead', 'reporting_manager_id' => 'M9']),
    'the legacy text column never beats a real reporting manager');

same('', AttendanceReport::reportingManagerOf($lookups, []),
    'nothing set anywhere is an empty string, not a crash');
