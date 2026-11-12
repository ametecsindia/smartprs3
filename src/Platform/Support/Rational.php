<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Exact rational arithmetic for multipliers and divisors.
 *
 * Spine S7: "Division is exact rational until an explicit ROUND." An overtime
 * multiplier is 3/2, never 1.5 — 1.5 happens to be representable but 1/3 and
 * 8.33% are not, and the engine must not have two behaviours.
 *
 * Normalised: gcd reduced, denominator always positive, zero is 0/1.
 */
final readonly class Rational
{
    private function __construct(
        public int $numerator,
        public int $denominator,
    ) {
    }

    public static function of(int $numerator, int $denominator = 1): self
    {
        if ($denominator === 0) {
            throw new \InvalidArgumentException('Rational denominator may not be zero.');
        }

        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        $gcd = self::gcd(abs($numerator), $denominator);

        return new self(intdiv($numerator, $gcd), intdiv($denominator, $gcd));
    }

    /** "40%" as a rational: percent(40) === 40/100 === 2/5. */
    public static function percent(int $percent): self
    {
        return self::of($percent, 100);
    }

    /** Exact parse of a decimal string. No float ever appears. */
    public static function fromDecimalString(string $decimal): self
    {
        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $decimal) !== 1) {
            throw new \InvalidArgumentException("Not an exact decimal string: {$decimal}");
        }

        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($decimal, '+-');
        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');

        $denominator = 10 ** strlen($fraction);
        $numerator = (int) ($whole . $fraction);

        return self::of($negative ? -$numerator : $numerator, $denominator);
    }

    public function plus(self $other): self
    {
        return self::of(
            $this->numerator * $other->denominator + $other->numerator * $this->denominator,
            $this->denominator * $other->denominator,
        );
    }

    public function minus(self $other): self
    {
        return $this->plus(self::of(-$other->numerator, $other->denominator));
    }

    public function multipliedBy(self $other): self
    {
        return self::of($this->numerator * $other->numerator, $this->denominator * $other->denominator);
    }

    public function dividedBy(self $other): self
    {
        if ($other->isZero()) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return self::of($this->numerator * $other->denominator, $this->denominator * $other->numerator);
    }

    /** -1, 0 or 1. Cross-multiplied, so exact — there is no epsilon anywhere. */
    public function compareTo(self $other): int
    {
        return $this->numerator * $other->denominator <=> $other->numerator * $this->denominator;
    }

    public function equals(self $other): bool
    {
        return $this->numerator === $other->numerator && $this->denominator === $other->denominator;
    }

    public function isZero(): bool
    {
        return $this->numerator === 0;
    }

    public function isNegative(): bool
    {
        return $this->numerator < 0;
    }

    /** Materialise to an integer. Explicit mode, always — spine S5.4. */
    public function toInt(RoundingMode $mode): int
    {
        return $mode->divide($this->numerator, $this->denominator);
    }

    /**
     * Read back what `__toString()` wrote — "3", "1/2", "-35/2".
     *
     * The round trip existed in one direction only, which is how "1/2" reached
     * a caller with no exact way to parse it. The tempting repair at the call
     * site is `(float) "1/2"`, which is 1.0, and the value in question was half
     * a day of somebody's pay. A serialisation a class emits and cannot read is
     * an invitation to that cast.
     *
     * Decimals go through `fromDecimalString()`; this is for the fraction form.
     */
    public static function parse(string $value): self
    {
        $value = trim($value);

        if (!str_contains($value, '/')) {
            return self::fromDecimalString($value);
        }

        [$numerator, $denominator] = explode('/', $value, 2);

        if (!preg_match('/^-?\d+$/', trim($numerator))
            || !preg_match('/^-?\d+$/', trim($denominator))) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a rational this class wrote. Refused rather than coerced: "
                . 'every caller of this method is holding a number that means money or days.'
            );
        }

        return self::of((int) trim($numerator), (int) trim($denominator));
    }

    public function __toString(): string
    {
        return $this->denominator === 1
            ? (string) $this->numerator
            : $this->numerator . '/' . $this->denominator;
    }

    /**
     * FOR PRINTING, and only for printing.
     *
     * `__toString` is exact and gives "35/2", which is correct and is what a
     * fingerprint should hash — and is not what somebody wants to read next to
     * the words "days paid". This renders a fixed number of decimal places,
     * truncating rather than rounding, because a display that rounds 17.499 up
     * to 17.5 next to a payslip is a display somebody will reconcile against.
     *
     * Never feed the result back into a calculation. The exact value is this
     * object; a decimal string of it is a lossy picture of one.
     */
    public function toDecimalString(int $places = 2): string
    {
        if ($places < 0) {
            throw new \InvalidArgumentException('Places is not negative.');
        }

        $negative = $this->numerator < 0;
        $numerator = abs($this->numerator);
        $whole = intdiv($numerator, $this->denominator);
        $rest = $numerator % $this->denominator;

        if ($places === 0) {
            return ($negative ? '-' : '') . $whole;
        }

        $digits = '';

        for ($i = 0; $i < $places; $i++) {
            $rest *= 10;
            $digits .= intdiv($rest, $this->denominator);
            $rest %= $this->denominator;
        }

        // A whole number prints as a whole number: "17.00 days paid" invites the
        // question of what the hundredths are, and there aren't any.
        $digits = rtrim($digits, '0');

        return ($negative ? '-' : '') . $whole . ($digits === '' ? '' : '.' . $digits);
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : $a;
    }
}
