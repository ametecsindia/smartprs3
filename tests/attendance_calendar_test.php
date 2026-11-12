<?php

declare(strict_types=1);

use SmartPRS\Platform\Attendance\ArrearsRecord;
use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Attendance\PeriodLock;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Platform\Attendance\ShiftWindow;
use SmartPRS\Platform\Calendar\CalendarService;
use SmartPRS\Platform\Calendar\DayType;
use SmartPRS\Platform\Calendar\WeeklyOffPattern;
use SmartPRS\Platform\Leave\CompOffAccrual;
use SmartPRS\Platform\Leave\LeaveLedger;
use SmartPRS\Platform\Support\Rational;

/**
 * THE CALENDAR AND ATTENDANCE KERNEL — Parts XI, XII and XIII, Phase 4.
 *
 * The golden-test matrix lives in tests/phase4/attendance_matrix.json and is run
 * by the exit gate. This file holds the properties a matrix cannot express: what
 * is REFUSED, what is TOTAL, and what a failure produces.
 */

group('Calendar and attendance (Parts XI–XIII)');

$sunOnly = new WeeklyOffPattern('SUN', WeeklyOffPattern::FIXED, 'v1', weekdays: [7]);
$always = static fn (string $e, DateTimeImmutable $d): WeeklyOffPattern => $sunOnly;

// =============================================================================
// THE EVALUATOR IS TOTAL — R-XII-003
// =============================================================================

$blind = new CalendarService(static fn (): ?WeeklyOffPattern => null);
$verdict = $blind->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-07'));

ok($verdict->isUnresolved(), 'no pattern gives a typed UNRESOLVED verdict, not a default');
ok(!$verdict->isWeeklyOff,
    'and the boolean is FALSE, which is the conservative half: a caller that ignores the error produces a '
    . 'visible absence somebody queries, rather than a silent day off nobody notices');
ok(str_contains($verdict->error, 'both defaults are wrong'),
    'the error says why there is no default: "working" charges an absence nobody took, "off" pays for a day '
    . 'nobody granted');

$throwing = new CalendarService(static function (): ?WeeklyOffPattern {
    throw new RuntimeException('the calendar table is unreachable');
});
ok($throwing->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-07'))->isUnresolved(),
    'a thrown resolution becomes an unresolved verdict — SmartPRS 2 marks a whole workforce absent when its '
    . 'holiday query throws, because the failure had nowhere to go but into the answer');

// The unresolved verdict travels UP through resolveDayType rather than becoming
// a day type somebody reads.
$resolved = $blind->resolveDayType('E-1', new DateTimeImmutable('2026-09-07'));
ok(str_contains($resolved['why'], 'UNRESOLVED'), 'and resolveDayType says so rather than answering "working"');

$counted = $blind->workingDays('E-1', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-07'));
same(null, $counted['days'],
    'a range containing an unresolved day has NO working-day count — a denominator computed over a range the '
    . 'system could not resolve divides somebody\'s salary by a guess');
same(7, count($counted['unresolved']), 'and every unresolved date is named');

throws(InvalidArgumentException::class,
    static fn () => (new CalendarService($always))->workingDays('E-1',
        new DateTimeImmutable('2026-09-07'), new DateTimeImmutable('2026-09-01')),
    'a backwards range is refused rather than quietly returning zero working days');

// =============================================================================
// PRECEDENCE, AND THE HOLIDAY THAT FALLS ON AN OFF
// =============================================================================

$withHoliday = new CalendarService(
    $always,
    holiday: static fn (string $e, DateTimeImmutable $d): ?string
        => $d->format('Y-m-d') === '2026-09-06' ? 'GANESH-CHATURTHI' : null,
);

$onOff = $withHoliday->resolveDayType('E-1', new DateTimeImmutable('2026-09-06'));
same(DayType::WeeklyOff, $onOff['type'],
    'a holiday falling on a weekly off is recorded as the WEEKLY OFF — the employee was already not working');
same('GANESH-CHATURTHI', $onOff['holiday'],
    'and the holiday still travels, so whether it earns a compensatory day is a policy question with the '
    . 'facts to answer it');

$counted = $withHoliday->workingDays('E-1', new DateTimeImmutable('2026-09-07'), new DateTimeImmutable('2026-09-13'));
same(6, $counted['days'], 'a week with one Sunday has six working days under a Sunday-only pattern');
same(1, $counted['by_type']['weekly_off'], 'and the breakdown says which day went where');

// The roster beats the pattern, in both directions.
$rostered = new CalendarService($always, rosterCell: static fn (string $e, DateTimeImmutable $d): ?string
    => $d->format('Y-m-d') === '2026-09-08' ? 'off' : ($d->format('Y-m-d') === '2026-09-06' ? 'work' : null));

ok($rostered->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-08'))->isWeeklyOff,
    'a roster cell grants an off on a day the pattern would not — an explicit decision about a named day '
    . 'beats the default that applies when nobody made one');
ok(!$rostered->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-06'))->isWeeklyOff,
    'and a roster cell scheduling work overrides the pattern, which is what makes it weekly-off-worked '
    . 'rather than an ordinary Tuesday');

// An elected pattern with no election grants nothing, visibly.
$elected = new CalendarService(
    static fn (): WeeklyOffPattern => new WeeklyOffPattern('ELECT', WeeklyOffPattern::ELECTED, 'v1'),
);
ok(!$elected->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-06'))->isWeeklyOff,
    'an employee under an elected pattern who has not chosen gets NO off — quietly giving them Sunday would '
    . 'hide a configuration gap somebody must close');

// =============================================================================
// PATTERN SHAPES THAT MUST BE REFUSED
// =============================================================================

throws(InvalidArgumentException::class,
    static fn () => new WeeklyOffPattern('X', WeeklyOffPattern::FIXED, 'v1'),
    'a fixed pattern naming no weekday means an employee works every day of the year');
throws(InvalidArgumentException::class,
    static fn () => new WeeklyOffPattern('X', WeeklyOffPattern::FIXED, 'v1', weekdays: [0]),
    'weekday 0 is refused: ISO-8601 throughout, because mixing it with PHP\'s `w` breaks on one day a week');
throws(InvalidArgumentException::class,
    static fn () => new WeeklyOffPattern('X', WeeklyOffPattern::ROTATION, 'v1', cycle: [0], cycleLength: 6),
    'a rotation with no anchor gives a different answer depending on when it is asked');
throws(InvalidArgumentException::class,
    static fn () => new WeeklyOffPattern('X', 'vibes', 'v1'),
    'and an undeclared pattern kind is refused');

// A count-allocated pattern does not invent offs the roster never placed.
$allocated = new CalendarService(static fn (): WeeklyOffPattern
    => new WeeklyOffPattern('N-PER-WEEK', WeeklyOffPattern::COUNT_ALLOCATED, 'v1', offsPerWindow: 2));
ok(!$allocated->isWeeklyOff('E-1', new DateTimeImmutable('2026-09-06'))->isWeeklyOff,
    'R-XII-010: a count-allocated pattern with no roster cell is NOT an off — it would give an employee a '
    . 'day the roster never granted, on a day somebody was scheduled to work');

// =============================================================================
// THE PUNCH AND THE WINDOW — WHY SmartPRS 2 CANNOT REPRESENT A NIGHT
// =============================================================================

throws(InvalidArgumentException::class,
    static fn () => new Punch('E-1', new DateTimeImmutable('2026-09-07 10:00'), 'maybe'),
    'a punch with no direction is not a punch with a default — SmartPRS 2 infers it from list position, so '
    . 'one missed swipe inverts every pair after it');

throws(InvalidArgumentException::class,
    static fn () => new Punch('E-1', new DateTimeImmutable('2026-09-07 00:00:00', new DateTimeZone('UTC')), Punch::IN),
    'and a punch at exactly midnight UTC is a date that lost its time somewhere upstream');

throws(InvalidArgumentException::class,
    static fn () => new ShiftWindow('N', new DateTimeImmutable('2026-09-30'),
        new DateTimeImmutable('2026-09-30 22:00'), new DateTimeImmutable('2026-09-30 06:00')),
    'a cross-midnight shift is an end INSTANT on the next day, never an end time numerically smaller than '
    . 'the start — that representation is what makes a night impossible to compute');

$night = new ShiftWindow('NIGHT', new DateTimeImmutable('2026-09-30'),
    new DateTimeImmutable('2026-09-30 22:00'), new DateTimeImmutable('2026-10-01 06:00'), graceMinutes: 10);

ok($night->crossesMidnight(), 'the window knows it crosses midnight, because the instants say so');
same(480, $night->scheduledMinutes(), 'and eight hours is eight hours across the boundary');
same(135, $night->latenessMinutes(new Punch('E-1', new DateTimeImmutable('2026-10-01 00:15'), Punch::IN)),
    'arriving 00:15 on a 22:00 shift is 135 minutes late — SmartPRS 2 computes max(0, 15 - 1320) = 0, so a '
    . 'night-shift worker can never be marked late');
same(125, $night->lateBeyondGrace(new Punch('E-1', new DateTimeImmutable('2026-10-01 00:15'), Punch::IN)),
    'less the declared grace');
same(0, $night->lateBeyondGrace(new Punch('E-1', new DateTimeImmutable('2026-09-30 21:50'), Punch::IN)),
    'and an early arrival is no lateness rather than negative lateness');

// =============================================================================
// SETTLEMENT — IDEMPOTENCE AND THE DIFF
// =============================================================================

$window = new ShiftWindow('GEN', new DateTimeImmutable('2026-09-07'),
    new DateTimeImmutable('2026-09-07 09:00'), new DateTimeImmutable('2026-09-07 18:00'));

$punches = [
    new Punch('E-1', new DateTimeImmutable('2026-09-07 18:00'), Punch::OUT),
    new Punch('E-1', new DateTimeImmutable('2026-09-07 09:00'), Punch::IN),
];

$settled = DaySettlement::settle($window, 'E-1', $punches);
same(540, $settled->workedMinutes,
    'punches supplied out of order still settle correctly — SmartPRS 2 orders by strcmp on a concatenated '
    . 'string, so an OUT at 06:00 sorts before an IN at 22:00 and the night reads as a negative duration');
same([], $settled->diff(DaySettlement::settle($window, 'E-1', array_reverse($punches))),
    'and re-deriving from a different order gives an identical answer');

$incomplete = DaySettlement::settle($window, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-09-07 09:00'), Punch::IN),
]);
ok($incomplete->needsRegularisation(), 'a day with an unpaired punch reports that it is INCOMPLETE');
ok(str_contains($incomplete->trace, 'not guessed at'), 'and the trace says the day was settled around it');
ok($settled->diff($incomplete) !== [], 'a real change produces a real diff, so an empty diff means something');

// =============================================================================
// ARREARS — A LOCKED MONTH DOES NOT REFUSE THE FIX, IT COSTS A DIFFERENT MONTH
// =============================================================================

$locked = new PeriodLock('2026-02', new DateTimeImmutable('2026-02-01'),
    new DateTimeImmutable('2026-02-28'), true, new DateTimeImmutable('2026-03-05'), 'u-payroll');

throws(InvalidArgumentException::class,
    static fn () => new PeriodLock('2026-02', new DateTimeImmutable('2026-02-01'),
        new DateTimeImmutable('2026-02-28'), true),
    'a lock nobody is named on is a lock nobody can lift');

$feb = new ShiftWindow('GEN', new DateTimeImmutable('2026-02-17'),
    new DateTimeImmutable('2026-02-17 09:00'), new DateTimeImmutable('2026-02-17 18:00'));
$asPaid = DaySettlement::settle($feb, 'E-1', [new Punch('E-1', new DateTimeImmutable('2026-02-17 09:00'), Punch::IN)]);
$fixed = DaySettlement::settle($feb, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-02-17 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-02-17 18:00'), Punch::OUT, 'correction'),
]);

$arrears = ArrearsRecord::forCorrection($locked, '2026-04', $asPaid, $fixed, 'u-hr-lead', 'Missed out-punch.');
ok($arrears !== null, 'a correction inside a locked period produces arrears rather than rewriting the month');
same('2026-02', $arrears->originPeriodCode, 'the origin is the month that was WRONG');
same('2026-04', $arrears->targetPeriodCode,
    'and the target is the month that carries the MONEY — conflating them files the payment against a '
    . 'return that was correct');
ok($arrears->diff !== [], 'the diff travels, so an approver decides about a change rather than an amount');

throws(InvalidArgumentException::class,
    static fn () => new ArrearsRecord('E-1', new DateTimeImmutable('2026-02-17'), '2026-02', '2026-02',
        'u-1', ['worked: 0 → 540'], 'x'),
    'and an origin equal to the target is not arrears — if it can be paid in the month it was earned, the '
    . 'period was not locked');

same(null, ArrearsRecord::forCorrection($locked, '2026-04', $fixed, $fixed, 'u-1', 'no change'),
    'an unchanged re-derivation raises nothing, or every recalculation sweep would file arrears for the '
    . 'whole workforce');

// =============================================================================
// COMP-OFF, END TO END
// =============================================================================

$calendar = new CalendarService($always);
$accrual = new CompOffAccrual($calendar, minimumMinutes: 240, fullDayMinutes: 480, expiryDays: 90);

$sunday = new DateTimeImmutable('2026-09-06');
$sundayShift = new ShiftWindow('GEN', $sunday,
    new DateTimeImmutable('2026-09-06 09:00'), new DateTimeImmutable('2026-09-06 18:00'));

$full = $accrual->forDay(DaySettlement::settle($sundayShift, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-09-06 18:00'), Punch::OUT),
]));

same('1', (string) $full['days'], 'a full day worked on a resolved weekly off earns a whole day');
ok($full['entry']->expiresOn !== null,
    'and it EXPIRES — comp-off that never does is an unbounded liability surfacing as a resignation '
    . 'settlement nobody budgeted for');
ok(str_contains($full['entry']->sourceReference, '2026-09-06'), 'the day that earned it is traceable');

$half = $accrual->forDay(DaySettlement::settle($sundayShift, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-09-06 14:00'), Punch::OUT),
]));
same('1/2', (string) $half['days'], 'five hours earns a half day, held as a Rational rather than 0.5');

$token = $accrual->forDay(DaySettlement::settle($sundayShift, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-09-06 09:20'), Punch::OUT),
]));
same(null, $token['entry'], 'twenty minutes to collect a laptop is not a day owed');
ok(str_contains($token['why'], 'not a day owed'), 'and the reason says so');

$ledger = new LeaveLedger([$full['entry']]);
same('1', (string) $ledger->balanceOn('E-1', 'COMP-OFF', $sunday), 'the balance is a SUM of entries');

$spend = $accrual->consume($ledger, 'E-1', Rational::of(1), new DateTimeImmutable('2026-09-20'), 'Taking it.');
foreach ($spend['entries'] as $entry) {
    $ledger->append($entry);
}
ok($spend['short']->isZero(), 'and it can be spent');
ok($ledger->balanceOn('E-1', 'COMP-OFF', new DateTimeImmutable('2026-09-20'))->isZero(), 'leaving nothing');

$short = $accrual->consume($ledger, 'E-1', Rational::of(3), new DateTimeImmutable('2026-09-21'), 'Too much.');
ok(!$short['short']->isZero(), 'a request beyond the balance reports the shortfall');
ok(str_contains($short['why'], 'partly granted in silence'),
    'rather than being partly granted in silence');

$lapsing = new LeaveLedger([$full['entry']]);
same(1, count($lapsing->lapsesDue('E-1', 'COMP-OFF', new DateTimeImmutable('2027-01-01'))),
    'an expired unclaimed credit produces a LAPSE ENTRY — filtering it out of a balance would mean an '
    . 'employee watching a day disappear with nothing saying when or why');
same(0, count($lapsing->lapsesDue('E-1', 'COMP-OFF', new DateTimeImmutable('2026-10-01'))),
    'and a credit still inside its window does not lapse');

ok(str_contains($ledger->statement('E-1', 'COMP-OFF', new DateTimeImmutable('2026-09-20')), 'balance:'),
    'the statement lists every entry and the running total, so a balance is always explainable');

$blindAccrual = new CompOffAccrual(new CalendarService(static fn (): ?WeeklyOffPattern => null), 240, 480, 90);
same(null, $blindAccrual->forDay(DaySettlement::settle($sundayShift, 'E-1', [
    new Punch('E-1', new DateTimeImmutable('2026-09-06 09:00'), Punch::IN),
    new Punch('E-1', new DateTimeImmutable('2026-09-06 18:00'), Punch::OUT),
]))['entry'], 'a day the calendar could not classify earns nothing — that would invent an entitlement out of a failure');

throws(InvalidArgumentException::class,
    static fn () => new CompOffAccrual($calendar, 240, 480, 0),
    'and comp-off with no expiry is refused at construction');
