<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Timeline;

/**
 * A PAYSLIP FOR SOMEBODY WHO LEFT IN MARCH.
 *
 * `Platform\Lifecycle\EmploymentTimeline` names that defect in its own docblock
 * and says why it happens: leaving is a flag rather than an event with a date.
 * The class was finished and tested and nothing on any screen had ever called
 * it — and `employees` in this database carries `doj`, `status` and
 * `employment_stage` and NO EXIT DATE AT ALL, so the product genuinely could not
 * have told a month somebody worked from a month after they left.
 *
 * The assertions worth the most here are the two that pull in opposite
 * directions: a leaver is not paid for a month after they left, and an
 * employment with NO timeline is still paid. Reading silence as "not employed"
 * would have stopped every payslip in a ported tenant on the day this shipped.
 */

group('Employment timeline');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/tl-' . bin2hex(random_bytes(4))))->forTenant('1');
$timeline = new Timeline($store);

// ---------------------------------------------------------------------------
// THE ORDERING RULES ARE THE PLATFORM'S. This class rebuilds the real
// EmploymentTimeline and asks IT, so a second implementation cannot drift.
// ---------------------------------------------------------------------------

throws(InvalidArgumentException::class,
    static fn () => $timeline->record('E-1', 'confirmed', '2026-02-01', 'probation over'),
    'an employment begins by joining — anything else first is an event attached to nobody');

$timeline->record('E-1', 'joined', '2026-01-01');
same(1, count($timeline->events('E-1')), 'joining is recorded');

throws(InvalidArgumentException::class,
    static fn () => $timeline->record('E-1', 'promoted', '2025-06-01', 'early'),
    'an event cannot be back-dated behind one already on the timeline');

throws(InvalidArgumentException::class,
    static fn () => $timeline->record('E-1', 'promoted', '2026-03-01'),
    'a promotion with no reason is a change to somebody employment nobody has to justify');

$timeline->record('E-1', 'confirmed', '2026-04-01');
$timeline->record('E-1', 'promoted', '2026-05-01', 'took on the Hyderabad desk',
    ['grade' => 'M2']);

// THE QUESTION A COLUMN CANNOT ANSWER. A `grade` column holds today's grade.
same(null, $timeline->valueOn('E-1', 'grade', '2026-04-30'),
    'what was their grade in April? nothing had been recorded yet');
same('M2', $timeline->valueOn('E-1', 'grade', '2026-06-30'),
    'and in June it is the grade the promotion set — the question a column answers wrongly');

// ---------------------------------------------------------------------------
// NOTHING FOLLOWS AN EXIT.
// ---------------------------------------------------------------------------

$timeline->record('E-1', 'resigned', '2026-08-01', 'moving to Bengaluru');
$timeline->record('E-1', 'exited', '2026-08-20', 'last working day');

same('2026-08-20', $timeline->exitedOn('E-1'), 'the exit date is a date, not a flag');
same(false, $timeline->activeOn('E-1', '2026-09-01'), 'and they are not active the month after');
same(true, $timeline->activeOn('E-1', '2026-07-01'), 'and were active the month before');

throws(InvalidArgumentException::class,
    static fn () => $timeline->record('E-1', 'increment', '2026-09-01', 'annual'),
    'an increment after the exit is refused — this is the shape that produces a payslip for '
    . 'somebody who left in March');

// ---------------------------------------------------------------------------
// AND THE PAYSLIP. The whole point of the dates.
// ---------------------------------------------------------------------------

$policy = new LeavePolicy($root . '/config/leave-policy.json');
$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');
$attendance = new Attendance($store, $rules, $calendar);
$leave = new Leave($store, $policy);
$period = new PayrollPeriod($attendance, $leave, $policy, $calendar, $rules, $timeline);

// Punches in September, a month AFTER they left. A device that keeps sending, a
// row imported twice, a badge somebody else is using — the data does not know.
$attendance->record('E-1', '2026-09-07 09:00:00', 'in', 'device');
$attendance->record('E-1', '2026-09-07 18:30:00', 'out', 'device');

$after = $period->forPeriod('E-1', '2026-09-01', '2026-09-30');

same(0, $after['payable_halves'], 'a month after the exit pays NOTHING, whatever the punches say');
same('2026-08-20', $after['exited_on'], 'and the exit date is on the row');
ok(str_contains($after['why'], 'NOTHING IS PAYABLE'),
    'and the working says so — zero with no explanation is a payslip somebody queries');

// A MID-PERIOD LEAVER IS PRORATED. SmartPRS 2 pays the full month here because
// it has no exit date to prorate against, and it is the largest recoverable
// overpayment in the migration register.
$attendance->record('E-1', '2026-08-18 09:00:00', 'in', 'device');
$attendance->record('E-1', '2026-08-18 18:30:00', 'out', 'device');
$attendance->record('E-1', '2026-08-25 09:00:00', 'in', 'device');
$attendance->record('E-1', '2026-08-25 18:30:00', 'out', 'device');

$leaving = $period->forPeriod('E-1', '2026-08-01', '2026-08-31');

ok(str_contains($leaving['why'], 'after the exit date are not payable'),
    'the month of the exit names the days beyond it');
ok($leaving['payable_halves'] > 0, 'and still pays for the days actually worked before it');

// ---------------------------------------------------------------------------
// SILENCE IS NOT AN ANSWER. Most employments in a ported database have no
// events at all, and reading that as "not employed" would stop every payslip.
// ---------------------------------------------------------------------------

same(null, $timeline->activeOn('E-NOBODY', '2026-09-01'),
    'an employment with no timeline answers null, not false');

$attendance->record('E-2', '2026-09-07 09:00:00', 'in', 'device');
$attendance->record('E-2', '2026-09-07 18:30:00', 'out', 'device');

$untracked = $period->forPeriod('E-2', '2026-09-01', '2026-09-30');

ok($untracked['payable_halves'] > 0,
    'and an employment nobody has recorded a timeline for is still paid — reading silence as '
    . '"not employed" would have stopped every payslip in a ported tenant');
same(null, $untracked['exited_on'], 'with no exit date on the row');
