<?php

declare(strict_types=1);

use SmartPRS\Surface\DayRules;

/**
 * THE RULE ENGINE, REACHED THE WAY A SCREEN REACHES IT.
 *
 * `tests/rules_*_test.php` drive the engine with fixtures built in PHP. Every
 * one of them passed for months while nothing a user could open ever entered the
 * engine at all — which is the exact shape of "a check that silently checks
 * nothing": the engine was proven and unreachable at the same time.
 *
 * This drives the REAL `config/attendance-rules.json` through the real bridge,
 * so a rule that stops parsing, an action parameter that stops being accepted,
 * or a priority somebody renumbered fails here and not on the screen.
 */

group('Surface\DayRules');

$rules = new DayRules(dirname(__DIR__) . '/config/attendance-rules.json');

// The shipped file parses, in full. `refusal()` returning a string is the
// bridge saying it built nothing — and it builds nothing rather than a
// half-set, so this one assertion covers every rule in the file.
same(null, $rules->refusal(), 'the shipped rules file parses and publishes');
same(7, count($rules->rules()), 'all seven declared rules were built');

/** @param array<string, mixed> $over */
$day = static function (array $over) use ($rules): array {
    // $over FIRST: PHP's array `+` keeps the LEFT operand's keys, so writing
    // the defaults first silently discards every override and this whole file
    // asserts the same day five times.
    return $rules->classify('2026-08-12', $over + [
        '__subject' => 'E-1',
        'day.date' => '2026-08-12',
        'day.is_weekly_off' => false,
        'day.is_holiday' => false,
        'shift.code' => 'GEN',
        'shift.full_day_minutes' => 540,
        'shift.half_day_minutes' => 270,
        'attendance.worked_minutes' => 0,
        'attendance.late_minutes' => 0,
        'attendance.early_exit_minutes' => 0,
        'attendance.shortfall_minutes' => 0,
        'attendance.ot_minutes' => 0,
        'attendance.punch_count' => 2,
        'attendance.session_count' => 1,
        'attendance.has_in' => true,
        'attendance.has_out' => true,
        'attendance.is_corrected' => false,
    ]);
};

// ---------------------------------------------------------------------------
// The four outcomes the file declares, each named by the rule that produced it.
// Asserting the STATUS alone would pass with the wrong rule winning, and which
// rule won is the thing a person disputes.
// ---------------------------------------------------------------------------

$full = $day(['attendance.worked_minutes' => 540]);
same('PRESENT', $full['status'], 'a full shift is present');
same('ATT-FULL-DAY', $full['rule'], 'and the full-day rule is what said so');

$half = $day(['attendance.worked_minutes' => 300]);
same('HALF_DAY', $half['status'], 'over half the shift is a half day');
same('ATT-HALF-DAY', $half['rule'], 'and the half-day rule is what said so');

$short = $day(['attendance.worked_minutes' => 60]);
same('ABSENT', $short['status'], 'under half the shift is absent');
same('ATT-SHORTFALL', $short['rule'], 'through the explicit TRUE catch-all, not a fall-through');

$none = $day(['attendance.punch_count' => 0, 'attendance.has_in' => false,
    'attendance.has_out' => false]);
same('ABSENT', $none['status'], 'no punch at all is absent');
same('ATT-NO-PUNCH', $none['rule'], 'and the no-punch rule is what said so');

// THE ONE THAT COSTS MONEY. An in-punch with no out-punch is the commonest real
// defect in the data, and SmartPRS 2 pays it as a full absence. Here it is half
// a day AND a flag, because the person was demonstrably at work.
$open = $day(['attendance.has_out' => false, 'attendance.punch_count' => 1,
    'attendance.worked_minutes' => 0]);
same('HALF_DAY', $open['status'], 'punched in, never out: half day, not absent');
same('ATT-OPEN-SHIFT', $open['rule'], 'and the open-shift rule is what said so');
same(['missing_out_punch'], $open['flags'], 'and it is flagged for a human');

// PRIORITY IS THE WHOLE ORDER, so prove it bites: this day satisfies the
// open-shift rule AND the full-day rule, and the higher priority must win. If
// the numbers are ever renumbered so that stops being true, this fails here.
$both = $day(['attendance.has_out' => false, 'attendance.worked_minutes' => 600]);
same('ATT-OPEN-SHIFT', $both['rule'], 'priority 90 beats priority 50');

// ---------------------------------------------------------------------------
// EVERY ANSWER EXPLAINS ITSELF. A status with no trace is a number nobody can
// argue with, which is the defect this whole engine exists to remove.
// ---------------------------------------------------------------------------

ok(str_contains((string) $full['trace'], 'ATT-FULL-DAY'),
    'the trace names the winning rule');
ok(str_contains($full['why'], 'PRESENT'),
    "the reason is the rule's own business description, not a generated sentence");

// ---------------------------------------------------------------------------
// IT FAILS TO A NAMED STATE. Both failure paths must produce UNCLASSIFIED with
// a reason — never a status, and never a blank.
// ---------------------------------------------------------------------------

$missing = new DayRules('/no/such/attendance-rules.json');
$answer = $missing->classify('2026-08-12', ['__subject' => 'E-1']);
same(DayRules::UNCLASSIFIED, $answer['status'], 'a missing rules file classifies nothing');
ok($answer['why'] !== '', 'and says why');

// NON-VACUITY: break one rule and confirm the bridge refuses the WHOLE set
// rather than quietly publishing the four that still parse. A rules file that
// silently shrinks is how a typo makes everybody half-present on a screen that
// looks entirely normal.
$broken = sys_get_temp_dir() . '/attendance-rules-broken.json';
$declared = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/attendance-rules.json'), true);
$declared['rules'][4]['when'] = 'attendance.worked_minutes >= shift.no_such_symbol';
file_put_contents($broken, json_encode($declared));

$refusing = new DayRules($broken);
same([], $refusing->rules(), 'one unparseable rule publishes none of them');
ok(str_contains((string) $refusing->refusal(), 'ATT-FULL-DAY'),
    'and the refusal names the rule that caused it');
same(DayRules::UNCLASSIFIED, $refusing->classify('2026-08-12', ['__subject' => 'E-1'])['status'],
    'and classifies nothing while it stands');
unlink($broken);

// ---------------------------------------------------------------------------
// THE TWO STATUSES THAT WERE PRICED AND UNREACHABLE. `statuses` has valued
// WEEKLY_OFF and HOLIDAY at a full day since the file was written, and until the
// working calendar was handed to the classifier NO RULE COULD PRODUCE EITHER —
// the two symbols that decide it were never supplied. A status priced in the
// policy that nothing can reach reads as configured and is inert, which is the
// same defect as a permission in the catalogue that no role holds.
// ---------------------------------------------------------------------------

$off = $day(['day.is_weekly_off' => true, 'attendance.punch_count' => 0,
    'attendance.has_in' => false, 'attendance.has_out' => false]);
same('WEEKLY_OFF', $off['status'], 'a weekly off with no punches is a weekly off, not an absence');
same('ATT-WEEKLY-OFF', $off['rule'], 'and the weekly-off rule is what said so');
same(2, $rules->payableHalves('WEEKLY_OFF'), 'and it is worth a full day');

// AND IT OUTRANKS THE PUNCH RULES. Somebody who comes in on their day off has
// worked, the day was still not a working day, and paying it as a shortfall
// because they left after two hours is the arithmetic that ends in a dispute.
$offWorked = $day(['day.is_weekly_off' => true, 'attendance.worked_minutes' => 60]);
same('ATT-WEEKLY-OFF', $offWorked['rule'], 'even when somebody worked a short day on it');

$holiday = $day(['day.is_holiday' => true, 'day.is_weekly_off' => true,
    'attendance.punch_count' => 0, 'attendance.has_in' => false, 'attendance.has_out' => false]);
same('HOLIDAY', $holiday['status'],
    'a holiday landing on a weekly off is reported as the HOLIDAY — the answer somebody wants '
    . 'when they ask why the day was paid');
same(2, $rules->payableHalves('HOLIDAY'), 'and is worth a full day too');

// A WORKING DAY IS STILL A WORKING DAY. Without this the four assertions above
// pass against rules that fire on everything.
$ordinary = $day(['attendance.worked_minutes' => 540]);
same('PRESENT', $ordinary['status'], 'and an ordinary worked day is unaffected by either');

// ---------------------------------------------------------------------------
// A QUESTION THE CALLER CANNOT SUPPLY THE INPUTS FOR IS NOT A QUESTION TO ASK.
//
// The rules decide the status from `day.is_weekly_off` and `day.is_holiday`. If
// nothing knows them, SPX answers UNKNOWN — and an unknown does not stop the
// rules BELOW from firing, so a Sunday with no punches came out ABSENT: a full
// day of pay, gone, with nothing saying the holiday list was never loaded. The
// same shape charged every day of every leave span at full price on the charge
// rules, in the opposite direction. Attendance refuses to classify at all
// without a calendar, and `payableDays()` reports the days as unpriced.
// ---------------------------------------------------------------------------

$blindStore = (new SmartPRS\Surface\Store(sys_get_temp_dir() . '/dr-nocal-'
    . bin2hex(random_bytes(4))))->forTenant('1');
$blind = new SmartPRS\Surface\Attendance($blindStore, $rules);
$blind->record('E-B', '2026-09-06 09:00:00', 'in', 'device');
$blind->record('E-B', '2026-09-06 18:30:00', 'out', 'device');

$blindDay = $blind->settledDays('E-B', '09:00', '18:00')[0];
same(DayRules::UNCLASSIFIED, $blindDay['classification']['status'],
    'with no calendar, a day is UNCLASSIFIED rather than classified by the rules that happen '
    . 'not to need one');
ok(str_contains($blindDay['classification']['why'], 'calendar'),
    'and the reason names what is missing');
same(1, $blind->payableDays('E-B', '09:00', '18:00')['unpriced'],
    'and the summary reports it as unpriced rather than shrinking quietly');
