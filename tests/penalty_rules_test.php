<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\PenaltyRules;
use SmartPRS\Surface\Store;

/**
 * THREE LATE ARRIVALS COST HALF A DAY — RL-ATT-001, published at last.
 *
 * `attendance.late_minutes` has been computed for every settled day since Phase 4
 * and nothing ever read it: somebody could be forty minutes late every morning of
 * the month and the payslip would not know. SmartPRS 2 does have this rule, in
 * `PayrollGenController::attendanceCut():449-455`, as a loop with the numbers
 * written into it.
 *
 * The assertion that carries the most weight here is the THRESHOLD one: two late
 * arrivals cost nothing and three cost half a day. A test that only checks the
 * penalised case passes just as well against a rule that penalises everybody.
 */

group('Penalty rules');

$root = dirname(__DIR__);
$penalties = new PenaltyRules($root . '/config/attendance-penalty-rules.json');

same(null, $penalties->refusal(), 'the shipped penalty rules file parses and publishes');
same(1, count($penalties->rules()), 'the one declared rule was built');

// THE COUNTER THE RULE NEEDS. The engine queries nothing, so a rule counting
// late arrivals over a payroll month is only answerable if the caller fills this
// key — and an unfilled one is a named error, never a zero that forgives a month.
$keys = $penalties->preparedKeys();
same(1, count($keys), 'the rule declares exactly one prepared aggregation');
ok(str_starts_with($keys[0], 'agg.count(late_arrival,payroll_period,{'),
    'and its canonical key names the event, the window and the predicate hash');

$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');

/** Build an employment with $n late arrivals in September 2026, each 30 minutes late. */
$withLateDays = static function (int $n) use ($root, $rules, $calendar, $penalties): Attendance {
    $store = (new Store(sys_get_temp_dir() . '/pen-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('employees', ['emp_code' => 'E-L', 'name' => 'Late Riser']);
    $attendance = new Attendance($store, $rules, $calendar, null, $penalties);

    // Weekdays only — the rule's `except` spares holidays and weekly offs, and a
    // fixture that lands a late mark on a Sunday would be counting a day the
    // rule refuses to penalise.
    $posted = 0;
    $day = new DateTimeImmutable('2026-09-01');

    while ($posted < $n) {
        if (!in_array($day->format('D'), $calendar->weeklyOff(), true)) {
            $attendance->record('E-L', $day->format('Y-m-d') . ' 09:30:00', 'in', 'device');
            $attendance->record('E-L', $day->format('Y-m-d') . ' 18:00:00', 'out', 'device');
            $posted++;
        }

        $day = $day->modify('+1 day');
    }

    return $attendance;
};

// ---------------------------------------------------------------------------
// THE THRESHOLD BITES IN BOTH DIRECTIONS.
// ---------------------------------------------------------------------------

$twice = $withLateDays(2)->penalties('E-L', '2026-09-01', '2026-09-30', '09:00', '18:00');
same([], $twice, 'two late arrivals in a month cost nothing');

$thrice = $withLateDays(3)->penalties('E-L', '2026-09-01', '2026-09-30', '09:00', '18:00');
ok($thrice !== [], 'three late arrivals cost something');
same('RL-ATT-001', $thrice[0]['penalties'][0]['rule'] ?? '', 'and the rule that said so is named');
same(1, $thrice[0]['halves'] ?? 0, 'half a day, expressed as one HALF and never as 0.5');

// THE THIRD AND EVERY LATER LATE ARRIVAL (owner, 7 Oct) — the rule's own words.
// The 1st and 2nd stay free; it used to charge all three once the month reached three.
same(1, count($thrice), 'three late arrivals penalise only the third');
same(3, count($withLateDays(5)->penalties('E-L', '2026-09-01', '2026-09-30', '09:00', '18:00')),
    'five late arrivals penalise the third, fourth and fifth');

// ---------------------------------------------------------------------------
// A PUNCTUAL MONTH IS NOT PENALISED. Without this every assertion above passes
// against a rule that fires on everything.
// ---------------------------------------------------------------------------

$store = (new Store(sys_get_temp_dir() . '/pen-ok-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['emp_code' => 'E-P', 'name' => 'On Time']);
$punctual = new Attendance($store, $rules, $calendar, null, $penalties);

foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10'] as $date) {
    $punctual->record('E-P', $date . ' 09:00:00', 'in', 'device');
    $punctual->record('E-P', $date . ' 18:00:00', 'out', 'device');
}

same([], $punctual->penalties('E-P', '2026-09-01', '2026-09-30', '09:00', '18:00'),
    'four punctual days cost nothing');

// ---------------------------------------------------------------------------
// THE TRACE EXPLAINS IT. A deduction with no working is a number the person it
// belongs to cannot argue with, which is the whole reason this engine exists.
// ---------------------------------------------------------------------------

ok(str_contains((string) $thrice[0]['trace'], 'RL-ATT-001'), 'the trace names the rule');
ok(str_contains((string) $thrice[0]['trace'], 'agg.count'),
    'and shows the counter it was answered with, so the count can be disputed too');

// ---------------------------------------------------------------------------
// IT REPORTS RATHER THAN GUESSES. A missing file penalises nothing and says so.
// ---------------------------------------------------------------------------

$missing = new PenaltyRules('/no/such/penalty-rules.json');
$answer = $missing->penalisedOn('E-L', '2026-09-07', []);

same(0, $answer['halves'], 'a missing rules file penalises nothing');
ok($answer['why'] !== null, 'and says why, rather than reading as a clean month');

// NON-VACUITY on the prepared counter: strip the key the rule asked for and the
// evaluation must FAIL rather than quietly answering zero. This is the assertion
// that would catch somebody "simplifying" the preparation away.
$unprepared = $penalties->penalisedOn('E-L', '2026-09-07', [
    'day.date' => '2026-09-07',
    'day.is_holiday' => false,
    'day.is_weekly_off' => false,
    'attendance.late_minutes' => 30,
    "policy.value('late_grace_min')" => 10,
]);

same(0, $unprepared['halves'], 'an unprepared counter penalises nothing');
ok($unprepared['why'] !== null,
    'and is REPORTED as unavailable rather than counted as zero late arrivals — a silent zero '
    . 'here forgives a whole month and nothing on any screen would say so');
