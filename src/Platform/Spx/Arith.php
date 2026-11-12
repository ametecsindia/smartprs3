<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Support\Rational;

/**
 * Overflow-guarded exact rational arithmetic (Part VIII §6.2).
 *
 * Between the input and the declared rounding boundary the evaluator works in
 * exact rationals, never in paise and never in floats, so `(gross / 27) * 25`
 * and `(gross * 25) / 27` produce the identical result.
 *
 * Support\Rational does the arithmetic; this adds the one thing an untrusted
 * expression makes necessary. PHP silently promotes an overflowing int to a
 * float, and a float in a money path is Law 7 gone. So every product is checked
 * BEFORE it is computed, and an overflow returns null, which the evaluator turns
 * into ERR_PRECISION — the loud failure Part VIII §6.2 asks for.
 */
final class Arith
{
    public static function add(Rational $a, Rational $b): ?Rational
    {
        $left = self::mulInt($a->numerator, $b->denominator);
        $right = self::mulInt($b->numerator, $a->denominator);
        $denominator = self::mulInt($a->denominator, $b->denominator);

        if ($left === null || $right === null || $denominator === null) {
            return null;
        }

        $numerator = self::addInt($left, $right);

        return $numerator === null ? null : Rational::of($numerator, $denominator);
    }

    public static function subtract(Rational $a, Rational $b): ?Rational
    {
        return self::add($a, Rational::of(-$b->numerator, $b->denominator));
    }

    public static function multiply(Rational $a, Rational $b): ?Rational
    {
        $numerator = self::mulInt($a->numerator, $b->numerator);
        $denominator = self::mulInt($a->denominator, $b->denominator);

        return $numerator === null || $denominator === null ? null : Rational::of($numerator, $denominator);
    }

    /** Null on overflow; the caller distinguishes a zero divisor before calling. */
    public static function divide(Rational $a, Rational $b): ?Rational
    {
        if ($b->isZero()) {
            throw new \LogicException('Division by zero must be reported as ERR_DIV_ZERO before dividing.');
        }

        $numerator = self::mulInt($a->numerator, $b->denominator);
        $denominator = self::mulInt($a->denominator, $b->numerator);

        return $numerator === null || $denominator === null ? null : Rational::of($numerator, $denominator);
    }

    /** Percent-of: `X % 40` is forty percent of X, exactly (spine S5.2). */
    public static function percentOf(Rational $value, Rational $percent): ?Rational
    {
        $product = self::multiply($value, $percent);

        return $product === null ? null : self::divide($product, Rational::of(100));
    }

    /** -1, 0 or 1; null if the cross-multiplication would overflow. */
    public static function compare(Rational $a, Rational $b): ?int
    {
        $left = self::mulInt($a->numerator, $b->denominator);
        $right = self::mulInt($b->numerator, $a->denominator);

        return $left === null || $right === null ? null : $left <=> $right;
    }

    /** The multiplication PHP would silently turn into a float. */
    private static function mulInt(int $a, int $b): ?int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        if (abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            return null;
        }

        return $a * $b;
    }

    private static function addInt(int $a, int $b): ?int
    {
        if (($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b)) {
            return null;
        }

        return $a + $b;
    }
}
