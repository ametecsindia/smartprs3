<?php

declare(strict_types=1);

use SmartPRS\Platform\Payroll\PayslipCalculator;
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * HALF A DAY IS PAID AS HALF A DAY, EXACTLY.
 *
 * `PayslipCalculator::calculate()` took `int $payableDays` until the rule engine
 * started classifying attendance, at which point half a day became something the
 * product can say — and an int parameter can only round it, in one direction,
 * silently, on the number that multiplies somebody's gross. The parameter is a
 * `Rational` now and this file is what stops it going back.
 *
 * The value of these assertions is that they FAIL under every plausible wrong
 * answer: a float would drift, an int cast would pay 17 days or 18, and a
 * "round the money at the end instead" would be out by a paisa on the third
 * case. Each expected figure below is computed by hand from the fraction.
 */

group('Half-day pay');

/** @return list<SalaryComponent> a gross with nothing clever in it */
$structure = static fn (): array => [
    new SalaryComponent('X1', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF_GROSS,
        ['prorates' => true], ratePercent: 100),
];

$pay = static fn (string $gross, Rational $days, int $periodDays): string => (new PayslipCalculator($structure()))
    ->calculate('E-1', '2026-09', Money::of($gross), $days, $periodDays)
    ->grossEarnings()->toDecimalString();

// ---------------------------------------------------------------------------
// A month of 30 days on ₹60,000: one day is ₹2,000, half a day is ₹1,000.
// ---------------------------------------------------------------------------

same('60000.00', $pay('60000.00', Rational::of(30), 30), 'a full month is the full gross');
same('58000.00', $pay('60000.00', Rational::of(29), 30), 'one day absent costs exactly one day');

// THE ONE THE INT PARAMETER COULD NOT EXPRESS. 29 and a half days.
same('59000.00', $pay('60000.00', Rational::of(59, 2), 30),
    'half a day absent costs exactly half a day, not a whole one and not nothing');

// And it is genuinely BETWEEN the two whole days either side — the assertion
// that fails if a cast quietly rounds the fraction away in either direction.
$half = $pay('60000.00', Rational::of(59, 2), 30);
ok($half !== $pay('60000.00', Rational::of(29), 30), 'and is not 29 days rounded down');
ok($half !== $pay('60000.00', Rational::of(30), 30), 'and is not 30 days rounded up');

// ---------------------------------------------------------------------------
// A fraction that does NOT divide evenly, which is where a float would show.
// ₹50,000 over 31 days, 17.5 days payable = 50000 * 35/62 = ₹28,225.806451…
// Rounded HALF-UP once, at the end: ₹28,225.81.
// ---------------------------------------------------------------------------

same('28225.81', $pay('50000.00', Rational::of(35, 2), 31),
    'a fraction that does not divide is rounded ONCE, at the end, half up');

// ---------------------------------------------------------------------------
// The boundary the calculator refuses. More payable days than the period has is
// a settlement question, and the guard has to survive the type change: it used
// to be `$payableDays > $periodDays` on two ints.
// ---------------------------------------------------------------------------

throws(InvalidArgumentException::class,
    static fn () => $pay('60000.00', Rational::of(61, 2), 30),
    'thirty and a half days in a thirty-day month is refused, not prorated to 101%');

throws(InvalidArgumentException::class,
    static fn () => $pay('60000.00', Rational::of(-1, 2), 30),
    'and so is a negative half day');

// A half day EQUAL to the period is not over it. The guard is `>`, and a `>=`
// written by somebody being careful would refuse a full month.
same('60000.00', $pay('60000.00', Rational::of(60, 2), 30),
    'exactly the period, expressed as a fraction, is a full month and not a refusal');

// ---------------------------------------------------------------------------
// The display path, which is where the exactness was actually being lost. The
// screens printed `(int) $row['payable_days']`, so a person paid for 17.5 days
// read "17" and had no way to know.
// ---------------------------------------------------------------------------

same('17.5', Rational::of(35, 2)->toDecimalString(), 'a half day prints as a half day');
same('17', Rational::of(17)->toDecimalString(),
    'and a whole number prints with no decimals — "17.00 days paid" invites a question '
    . 'about hundredths that do not exist');
same('0.33', Rational::of(1, 3)->toDecimalString(),
    'a third truncates rather than rounding: a display that rounds up next to a payslip '
    . 'is one somebody reconciles against');
same('-17.5', Rational::of(-35, 2)->toDecimalString(), 'and a negative keeps its sign');
