<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

group('Rational');

// Kept behind a function boundary so the comparison is genuinely computed at
// runtime rather than folded by static analysis into two matching literals —
// float drift is exactly the kind of thing a compile-time check can't see.
$floatsDiffer = static fn (float $a, float $b): bool => $a !== $b;

// --- normalisation ----------------------------------------------------------

$half = Rational::of(2, 4);
same(1, $half->numerator, 'gcd reduction: numerator');
same(2, $half->denominator, 'gcd reduction: denominator');
same('2/5', (string) Rational::percent(40), '40% is 2/5, never 0.4');
same('0', (string) Rational::of(0, 7), 'zero normalises to 0/1');
ok(Rational::of(1, -2)->equals(Rational::of(-1, 2)), 'sign is carried by the numerator');
same('3/2', (string) Rational::of(3, 2), 'an overtime multiplier is 3/2');
throws(InvalidArgumentException::class, static fn () => Rational::of(1, 0), 'zero denominator rejected');

// --- exactness: the whole point --------------------------------------------

$tenth = Rational::fromDecimalString('0.1');
$fifth = Rational::fromDecimalString('0.2');
ok(
    $tenth->plus($fifth)->equals(Rational::fromDecimalString('0.3')),
    '0.1 + 0.2 === 0.3 exactly (0.1 + 0.2 !== 0.3 in float)',
);
ok($floatsDiffer(0.1 + 0.2, 0.3), 'the float the platform refuses to use does drift — this is why Rational exists');

$third = Rational::of(1, 3);
ok($third->multipliedBy(Rational::of(3))->equals(Rational::of(1)), 'a third times three is exactly one');
ok(
    $third->plus($third)->plus($third)->equals(Rational::of(1)),
    'a third added three times is exactly one, with no accumulated drift',
);

$eightPointThreeThree = Rational::fromDecimalString('8.33');
same('833/100', (string) $eightPointThreeThree, 'a statutory 8.33% parses exactly');
ok(
    $eightPointThreeThree->multipliedBy(Rational::of(1, 100))->equals(Rational::of(833, 10000)),
    'percent-of stays exact through the second multiplication',
);

throws(InvalidArgumentException::class, static fn () => Rational::fromDecimalString('1.5e3'), 'exponents rejected');
throws(InvalidArgumentException::class, static fn () => Rational::fromDecimalString('1/2'), 'a fraction string is not a decimal');

// --- arithmetic and comparison ---------------------------------------------

ok(Rational::of(3, 4)->minus(Rational::of(1, 4))->equals(Rational::of(1, 2)), 'minus');
ok(Rational::of(1, 2)->dividedBy(Rational::of(1, 4))->equals(Rational::of(2)), 'dividedBy');
same(0, Rational::of(1, 3)->compareTo(Rational::of(2, 6)), 'cross-multiplied comparison is exact');
same(-1, Rational::of(1, 3)->compareTo(Rational::of(1, 2)), 'a third is less than a half');
same(1, Rational::of(2, 3)->compareTo(Rational::of(1, 2)), 'two thirds is more than a half');
ok(Rational::of(0, 5)->isZero(), 'isZero');
ok(Rational::of(-1, 2)->isNegative(), 'isNegative');
throws(InvalidArgumentException::class, static fn () => Rational::of(1)->dividedBy(Rational::of(0)), 'divide by zero');

// --- materialisation requires an explicit mode ------------------------------

same(2, Rational::of(3, 2)->toInt(RoundingMode::HalfUp), '3/2 half-up is 2');
same(1, Rational::of(3, 2)->toInt(RoundingMode::Down), '3/2 truncated is 1');
same(2, Rational::of(3, 2)->toInt(RoundingMode::HalfEven), '3/2 half-even is 2, the even neighbour');
same(2, Rational::of(5, 2)->toInt(RoundingMode::HalfEven), '5/2 half-even is 2, the even neighbour');
same(-2, Rational::of(-3, 2)->toInt(RoundingMode::HalfUp), '-3/2 half-up goes away from zero');

// --- with Money: no float ever appears --------------------------------------

$overtimeRate = Money::of('125.00')->multipliedBy(Rational::of(3, 2));
same('187.50', $overtimeRate->toDecimalString(), 'an OT multiplier of 3/2 is exact on an odd amount');

$gratuity = Money::of('15000.00')
    ->multipliedBy(Rational::of(15, 26), RoundingMode::HalfUp);
same('8653.85', $gratuity->toDecimalString(), '15/26 of a wage rounds once, at a declared point');
