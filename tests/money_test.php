<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

group('Money');

// --- construction -----------------------------------------------------------

same(150000, Money::ofMajor(1500)->minor, 'ofMajor converts to paise');
same(-5, Money::of('-0.05')->minor, 'decimal string parses exactly, including the sign');
same(150000, Money::of('1500')->minor, 'a decimal string with no fraction is exact');
same(150000, Money::of('1500.00')->minor, 'trailing zeros are exact');
same(1500, Money::of('15.0')->minor, 'a one-digit fraction is exact');
same('1500.00', Money::ofMajor(1500)->toDecimalString(), 'decimal string round-trips for persistence');
same('-0.05', Money::of('-0.05')->toDecimalString(), 'negative decimal string round-trips');

// --- float is impossible ----------------------------------------------------
// This file declares strict_types=1, as every file in SmartPRS 3 must, so a
// float reaching an int parameter is a TypeError rather than a silent truncation.

throws(TypeError::class, static fn () => Money::ofMinor(1.5), 'ofMinor rejects a float');       // @phpstan-ignore-line
throws(TypeError::class, static fn () => Money::ofMajor(1500.75), 'ofMajor rejects a float');   // @phpstan-ignore-line
throws(TypeError::class, static fn () => Money::of(15.5), 'of() rejects a float');              // @phpstan-ignore-line
throws(InvalidArgumentException::class, static fn () => Money::of('1.5e3'), 'exponent notation is rejected');
throws(InvalidArgumentException::class, static fn () => Money::of('1,500.00'), 'a grouped string is rejected');
throws(InvalidArgumentException::class, static fn () => Money::of(''), 'an empty amount is rejected');
throws(
    InvalidArgumentException::class,
    static fn () => Money::of('1.005'),
    'sub-paise precision is an error, not a silent round',
);
throws(InvalidArgumentException::class, static fn () => Money::ofMinor(1, 'rupee'), 'currency must be ISO 4217');

// --- arithmetic -------------------------------------------------------------

same(2500, Money::ofMajor(10)->plus(Money::ofMajor(15))->minor, 'plus');
same(-500, Money::ofMajor(10)->minus(Money::ofMajor(15))->minor, 'minus');
same(600000, Money::of('15000.00')->multipliedBy(Rational::percent(40))->minor, 'BASIC % 40 is exact');
same(300000, Money::of('15000.00')->multipliedBy(Rational::of(1, 5))->minor, 'exact rational factor needs no mode');
same(4500000, Money::of('15000.00')->multipliedBy(3)->minor, 'integer factor is always exact');

throws(
    LogicException::class,
    static fn () => Money::ofMinor(100)->multipliedBy(Rational::of(1, 3)),
    'an inexact multiplication with no declared rounding mode throws — there is no default rounding',
);
same(33, Money::ofMinor(100)->multipliedBy(Rational::of(1, 3), RoundingMode::HalfUp)->minor, 'declared mode applies');

throws(
    InvalidArgumentException::class,
    static fn () => Money::ofMajor(10)->plus(Money::ofMajor(10, 'USD')),
    'currency mismatch on plus throws',
);
throws(
    InvalidArgumentException::class,
    static fn () => Money::ofMajor(10)->dividedBy(0, RoundingMode::HalfUp),
    'division by zero throws',
);

// Money x Money is a type error by construction: multipliedBy accepts int|Rational only.
throws(
    TypeError::class,
    static fn () => Money::ofMajor(10)->multipliedBy(Money::ofMajor(2)),                        // @phpstan-ignore-line
    'Money x Money is a type error (spine S5.1)',
);

// --- rounding modes ---------------------------------------------------------

$fivePaise = Money::of('0.05');
same(10, $fivePaise->roundTo(10, RoundingMode::HalfUp)->minor, 'HalfUp rounds a half away from zero');
same(0, $fivePaise->roundTo(10, RoundingMode::HalfEven)->minor, 'HalfEven rounds a half to the even neighbour');
same(0, $fivePaise->roundTo(10, RoundingMode::Down)->minor, 'Down truncates toward zero');
same(10, $fivePaise->roundTo(10, RoundingMode::Up)->minor, 'Up moves away from zero');
same(10, $fivePaise->roundTo(10, RoundingMode::Ceiling)->minor, 'Ceiling moves toward +inf');
same(0, $fivePaise->roundTo(10, RoundingMode::Floor)->minor, 'Floor moves toward -inf');

$minusFive = Money::of('-0.05');
same(-10, $minusFive->roundTo(10, RoundingMode::HalfUp)->minor, 'HalfUp on a negative goes away from zero');
same(0, $minusFive->roundTo(10, RoundingMode::Down)->minor, 'Down on a negative goes toward zero');
same(-10, $minusFive->roundTo(10, RoundingMode::Floor)->minor, 'Floor on a negative goes down');
same(0, $minusFive->roundTo(10, RoundingMode::Ceiling)->minor, 'Ceiling on a negative goes up');
same(0, $minusFive->roundTo(10, RoundingMode::HalfEven)->minor, 'HalfEven on -0.05 picks the even neighbour, zero');

same(333, Money::of('10.00')->dividedBy(3, RoundingMode::HalfUp)->minor, 'a third of ten rupees, half-up');
same(334, Money::of('10.00')->dividedBy(Rational::of(3, 1), RoundingMode::Ceiling)->minor, 'the same division, ceiling');

// --- allocation: the residue always sums back to the whole ------------------

$parts = Money::of('100.00')->allocate([1, 1, 1]);
same([3333, 3333, 3334], array_map(static fn (Money $m): int => $m->minor, $parts), '1/3 split puts the residue last');

$parts = Money::of('-100.00')->allocate([1, 1, 1]);
same([-3333, -3333, -3334], array_map(static fn (Money $m): int => $m->minor, $parts), 'negative 1/3 split');

$exhaustive = 0;
$weightSets = [
    [1], [1, 1], [1, 1, 1], [1, 2], [1, 2, 3], [0, 1], [1, 0], [0, 1, 0],
    [7, 11, 13], [3333, 3333, 3334], [50, 30, 20], [1, 1, 1, 1, 1, 1, 1],
    [999999, 1], [2, 3, 5, 7, 11, 13, 17, 19, 23],
];
$amounts = [0, 1, -1, 7, -7, 100, 9999, -9999, 100000, 123456789, -123456789, 33, 2];

foreach ($amounts as $minor) {
    foreach ($weightSets as $weights) {
        $whole = Money::ofMinor($minor);
        $allocated = $whole->allocate($weights);

        $sum = Money::sum(...$allocated);
        ok($sum->equals($whole), "allocate({$minor}, [" . implode(',', $weights) . ']) sums exactly to the whole');
        same(count($weights), count($allocated), 'allocate returns one part per weight');
        $exhaustive++;
    }
}
ok($exhaustive === count($amounts) * count($weightSets), "allocation checked across {$exhaustive} combinations");

throws(InvalidArgumentException::class, static fn () => Money::ofMajor(1)->allocate([]), 'empty weights rejected');
throws(InvalidArgumentException::class, static fn () => Money::ofMajor(1)->allocate([0, 0]), 'zero-sum weights rejected');
throws(InvalidArgumentException::class, static fn () => Money::ofMajor(1)->allocate([1, -1]), 'negative weight rejected');

// Named keys are preserved, so a structure can allocate by component code.
$byComponent = Money::of('50000.00')->allocate(['BASIC' => 50, 'HRA' => 30, 'SPECIAL' => 20]);
same(['BASIC', 'HRA', 'SPECIAL'], array_keys($byComponent), 'allocation preserves the caller keys');
ok(Money::sum(...array_values($byComponent))->equals(Money::of('50000.00')), 'keyed allocation is exact too');

// --- comparison -------------------------------------------------------------

ok(Money::ofMajor(10)->compareTo(Money::ofMajor(11)) === -1, 'compareTo is integer, not epsilon');
ok(Money::zero()->isZero(), 'isZero');
ok(Money::of('-0.01')->isNegative(), 'isNegative');
ok(!Money::zero()->isNegative(), 'zero is not negative');
ok(Money::ofMajor(5)->negated()->equals(Money::ofMajor(-5)), 'negated');
ok(Money::ofMajor(-5)->absolute()->equals(Money::ofMajor(5)), 'absolute');

// --- Indian formatting ------------------------------------------------------

same('₹1,50,000.00', Money::ofMajor(150000)->format(), 'lakh grouping');
same('₹15,000.00', Money::ofMajor(15000)->format(), 'thousand grouping');
same('₹1,000.00', Money::ofMajor(1000)->format(), 'exactly four digits');
same('₹100.00', Money::ofMajor(100)->format(), 'three digits are ungrouped');
same('₹1,23,45,678.90', Money::of('12345678.90')->format(), 'crore grouping');
same('-₹1,50,000.00', Money::ofMajor(-150000)->format(), 'negative sign precedes the symbol');
same('₹0.05', Money::of('0.05')->format(), 'paise are always two digits');
same('USD 1,000.00', Money::ofMajor(1000, 'USD')->format(), 'a non-INR currency shows its code');

// --- amount in words --------------------------------------------------------

same('Rupees One Lakh Fifty Thousand and Fifty Paise Only', Money::of('150000.50')->toWords(), 'words with paise');
same('Rupees One Lakh Fifty Thousand Only', Money::ofMajor(150000)->toWords(), 'words without paise');
same('Rupees Zero Only', Money::zero()->toWords(), 'zero in words');
same('Minus Rupees Nine Hundred Only', Money::ofMajor(-900)->toWords(), 'negative in words');
same(
    'Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Only',
    Money::ofMajor(12345678)->toWords(),
    'crore in words',
);
