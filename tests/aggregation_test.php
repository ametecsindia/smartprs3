<?php

declare(strict_types=1);

use SmartPRS\Platform\Rules\Aggregation\AggregationContext;
use SmartPRS\Platform\Rules\Aggregation\Aggregator;
use SmartPRS\Platform\Rules\Aggregation\Event;
use SmartPRS\Platform\Rules\Aggregation\Window;

/**
 * The aggregation windows and primitives (Part VII §5.9).
 *
 * `tests/conformance/rules.json` asserts what the windows RESOLVE TO, against
 * an independently computed table. This file asserts what they REFUSE, which is
 * the half a table cannot express — and refusing is most of the value here,
 * because every fallback available to a window resolver is a wrong answer that
 * looks like a right one.
 */

group('Aggregation (§5.9)');

$onDate = new DateTimeImmutable('2026-08-26');
$plain = new AggregationContext(financialYearStartMonth: 4);

// ---------------------------------------------------------------------------
// WHAT A WINDOW REFUSES
// ---------------------------------------------------------------------------

throws(
    InvalidArgumentException::class,
    static fn () => Window::resolve('payroll_period', $onDate, $plain),
    'R-VII-022: a payroll period with no pay cycle covering the date is REFUSED, not quietly answered '
    . 'with the calendar month — which is the exact defect that rule names',
);

throws(
    InvalidArgumentException::class,
    static fn () => Window::resolve('service_year', $onDate, $plain),
    'and a service year with no joining date is refused rather than assumed to start with the financial '
    . 'year, which would mis-accrue by up to a year',
);

throws(
    InvalidArgumentException::class,
    static fn () => Window::resolve('week', $onDate, $plain),
    'a week with no declared start day is two different weeks, so it is not a window',
);

throws(
    InvalidArgumentException::class,
    static fn () => Window::resolve('rolling', $onDate, $plain, ['n' => 30, 'unit' => 'fortnights']),
    'and the unit vocabulary is closed',
);

throws(
    InvalidArgumentException::class,
    static fn () => Window::resolve('sprint', $onDate, $plain),
    'as is the window vocabulary itself (§5.3)',
);

throws(
    InvalidArgumentException::class,
    static fn () => new AggregationContext(financialYearStartMonth: 0),
    'the financial year start is declared configuration with no default — April is right for India and '
    . 'wrong for most of the world, and Law 1 makes no exception for a value that suits the first customer',
);

// ---------------------------------------------------------------------------
// THE CUT-OFF CYCLE — the case SmartPRS 2 gets wrong twice
// ---------------------------------------------------------------------------

$cycle = AggregationContext::monthlyCycle(4, 25, new DateTimeImmutable('2026-09-25'), 12);
$august = $cycle->payrollPeriodFor(new DateTimeImmutable('2026-08-20'));

same('2026-08', $august['code'] ?? null, 'the 20th of August is in the August payroll period');
same('2026-07-26', $august['from']->format('Y-m-d'), 'which starts on 26 July, not 1 August');

same(
    '2026-09',
    $cycle->payrollPeriodFor(new DateTimeImmutable('2026-08-26'))['code'] ?? null,
    'and the 26th of August is in SEPTEMBER — on a cut-off cycle the payroll month is not the calendar '
    . 'month, which is the assumption behind two separate SmartPRS 2 defects',
);

// ---------------------------------------------------------------------------
// R-VII-023 — the rolling window, spelled out because it is ambiguous otherwise
// ---------------------------------------------------------------------------

$rolling = Window::resolve('rolling', $onDate, $plain, ['n' => 30, 'unit' => 'days']);

same(30, $rolling->days(), '"3 lates in 30 days" is thirty days INCLUDING today, not thirty-one');
same('2026-08-26', $rolling->to->format('Y-m-d'), 'ending at, and including, the subject\'s own date');

// ---------------------------------------------------------------------------
// THE PRIMITIVES
// ---------------------------------------------------------------------------

$aggregator = new Aggregator([
    new Event('late_arrival', new DateTimeImmutable('2026-08-03 09:40:00'), 20),
    new Event('late_arrival', new DateTimeImmutable('2026-08-11 09:55:00'), 45),
    new Event('late_arrival', new DateTimeImmutable('2026-08-26 09:33:00'), 33),
]);

$month = Window::resolve('calendar_month', $onDate, $plain);

same(3, $aggregator->count('late_arrival', $month), 'count over a window counts the window');
same(98, $aggregator->sum('late_arrival', $month), 'and sum sums the declared measure, in integers');
same(
    '2026-08-11',
    $aggregator->nth('late_arrival', $month, 2)?->format('Y-m-d'),
    'nth is 1-based and chronological, whatever order the store handed the events over in',
);
same(
    '2026-08-26',
    $aggregator->nth('late_arrival', $month, -1)?->format('Y-m-d'),
    'and a negative n counts from the end',
);
same(null, $aggregator->nth('late_arrival', $month, 9), 'an n past the end is null, not an error and not the last');

throws(
    InvalidArgumentException::class,
    static fn () => $aggregator->nth('late_arrival', $month, 0),
    'there is no zeroth event',
);

// The engine's own bound, asserted rather than trusted.
throws(
    InvalidArgumentException::class,
    static fn () => new Aggregator(array_fill(
        0,
        Aggregator::MAX_ROWS + 1,
        new Event('punch', new DateTimeImmutable('2026-08-26 09:00:00')),
    )),
    '§5.9.4: an aggregation is bounded or it is a denial of service — over the row cap it is refused, '
    . 'not answered slowly',
);

same(
    0,
    (new Aggregator([]))->count('late_arrival', $month),
    'and an empty event set counts zero, which is a real answer rather than a missing one',
);

throws(
    InvalidArgumentException::class,
    static fn () => (new Aggregator([new Event('leave_units', new DateTimeImmutable('2026-08-03'), 'half')]))
        ->sum('leave_units', $month),
    'a measure sums integers — minutes, days or paise — because Law 7 has no float in it',
);

throws(
    InvalidArgumentException::class,
    static fn () => $aggregator->streak('late_arrival', $onDate, 'centred'),
    "'centred' is declared in §5.9.1 and is not implemented, so it is refused rather than silently "
    . 'treated as backward',
);
