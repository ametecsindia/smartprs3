<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Spine Law 7 — MONEY IS EXACT.
 *
 * Integer minor units (paise) plus a currency. Immutable. No float appears in
 * any signature; every file in SmartPRS 3 declares strict_types=1, so passing a
 * float to any constructor here is a TypeError at the call site, not a silent
 * truncation. (CI greps for a missing strict_types declaration — see README.)
 *
 * Why this exists rather than brick/money:
 *   brick/money is the library alternative and it is a good one. It is not used
 *   because the blueprint mandates three things it does not give us as-is —
 *   paise-exact semantics fixed at the type level, an ALLOCATE contract whose
 *   residue goes to the *last* element deterministically (spine S7, S19 #4), and
 *   a type-level float ban (R-V-020). That is ~150 lines against a dependency we
 *   would have to wrap anyway to enforce all three, and the wrapper would be
 *   most of this file. Revisit if multi-currency FX conversion ever lands.
 *
 * ponytail: minor-unit exponent is fixed at 2. Every currency SmartPRS 3 handles
 * today (INR) is a 2-exponent currency; add a per-currency exponent table when a
 * 0-exponent (JPY) or 3-exponent (KWD) currency is actually sold.
 */
final readonly class Money
{
    private const EXPONENT = 2;
    private const MINOR_PER_MAJOR = 100;

    private function __construct(
        public int $minor,
        public string $currency,
    ) {
    }

    public static function ofMinor(int $minor, string $currency = 'INR'): self
    {
        return new self($minor, self::currency($currency));
    }

    public static function ofMajor(int $major, string $currency = 'INR'): self
    {
        return new self($major * self::MINOR_PER_MAJOR, self::currency($currency));
    }

    public static function zero(string $currency = 'INR'): self
    {
        return new self(0, self::currency($currency));
    }

    /**
     * Exact parse of a decimal string: "1500.00", "-0.05", "1500".
     * Rejects exponents, thousands separators, and any fraction finer than the
     * minor unit — a value we cannot represent exactly is an error, not a round.
     */
    public static function of(string $decimal, string $currency = 'INR'): self
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $decimal, $m) !== 1) {
            throw new \InvalidArgumentException("Not an exact decimal amount: {$decimal}");
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > self::EXPONENT) {
            throw new \InvalidArgumentException(
                "Amount {$decimal} has more precision than the minor unit allows; round explicitly."
            );
        }

        $minor = (int) ($m[2] . str_pad($fraction, self::EXPONENT, '0'));

        return new self($m[1] === '-' ? -$minor : $minor, self::currency($currency));
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Money x Number -> Money. Money x Money is a type error by construction
     * (spine S5.1) — there is no overload that accepts one.
     *
     * An int factor is always exact. A Rational factor may not be: if it is not,
     * a rounding mode is required, and omitting it throws rather than defaulting.
     */
    public function multipliedBy(int|Rational $factor, ?RoundingMode $mode = null): self
    {
        $factor = is_int($factor) ? Rational::of($factor) : $factor;

        return $this->scale($this->minor * $factor->numerator, $factor->denominator, $mode);
    }

    /** Money / Number -> Money. The mode is mandatory; division rarely divides evenly. */
    public function dividedBy(int|Rational $divisor, RoundingMode $mode): self
    {
        $divisor = is_int($divisor) ? Rational::of($divisor) : $divisor;

        if ($divisor->isZero()) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return $this->scale($this->minor * $divisor->denominator, $divisor->numerator, $mode);
    }

    /**
     * Spine S5.4 ALLOCATE / S19 #4. Split this amount across integer weights so
     * that the parts sum *exactly* to the whole, with the residue landing on the
     * last part. Deterministic, and the only sanctioned way to split money.
     *
     * ponytail: weights are ints. A 33.33% split is expressed in basis points
     * (3333), which is exact; add Rational weights when a real case needs them.
     *
     * @param  int[]  $weights
     * @return self[]
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('Cannot allocate across zero weights.');
        }

        $total = 0;

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('Allocation weights may not be negative.');
            }

            $total += $weight;
        }

        if ($total === 0) {
            throw new \InvalidArgumentException('Allocation weights must sum to more than zero.');
        }

        $parts = [];
        $allocated = 0;
        $last = array_key_last($weights);

        foreach ($weights as $key => $weight) {
            if ($key === $last) {
                break;
            }

            $part = intdiv($this->minor * $weight, $total);
            $allocated += $part;
            $parts[$key] = new self($part, $this->currency);
        }

        // Residue to the last element: the parts now sum exactly to the whole.
        $parts[$last] = new self($this->minor - $allocated, $this->currency);

        return $parts;
    }

    /** Round to a coarser unit, e.g. roundTo(100) = to the nearest rupee. */
    public function roundTo(int $unitInMinor, RoundingMode $mode): self
    {
        if ($unitInMinor <= 0) {
            throw new \InvalidArgumentException('Rounding unit must be positive.');
        }

        return new self($mode->divide($this->minor, $unitInMinor) * $unitInMinor, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->minor, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    /** Integer comparison. There is no epsilon anywhere in SmartPRS 3. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public static function sum(self ...$amounts): self
    {
        if ($amounts === []) {
            throw new \InvalidArgumentException('Cannot sum zero amounts; the currency is unknown.');
        }

        return array_reduce(
            array_slice($amounts, 1),
            static fn (self $carry, self $item): self => $carry->plus($item),
            $amounts[0],
        );
    }

    /** The persistence shape: DECIMAL(18,4) at rest (spine S16). */
    public function toDecimalString(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);

        return $sign . intdiv($abs, self::MINOR_PER_MAJOR)
            . '.' . str_pad((string) ($abs % self::MINOR_PER_MAJOR), self::EXPONENT, '0', STR_PAD_LEFT);
    }

    /** Indian digit grouping: 150000000 paise -> "₹15,00,000.00". Presentation only. */
    public function format(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);
        $major = (string) intdiv($abs, self::MINOR_PER_MAJOR);
        $paise = str_pad((string) ($abs % self::MINOR_PER_MAJOR), self::EXPONENT, '0', STR_PAD_LEFT);

        if (strlen($major) > 3) {
            // Last three digits, then groups of two: 1,50,000.
            $head = strrev(implode(',', str_split(strrev(substr($major, 0, -3)), 2)));
            $major = $head . ',' . substr($major, -3);
        }

        $symbol = $this->currency === 'INR' ? '₹' : $this->currency . ' ';

        return $sign . $symbol . $major . '.' . $paise;
    }

    /**
     * Amount in words, Indian system (crore / lakh / thousand).
     * ponytail: INR wording only — the only currency any cheque, letter or
     * statutory form in scope is ever printed in.
     */
    public function toWords(): string
    {
        if ($this->currency !== 'INR') {
            throw new \LogicException("Amount-in-words is defined for INR only, not {$this->currency}.");
        }

        $abs = abs($this->minor);
        $major = intdiv($abs, self::MINOR_PER_MAJOR);
        $paise = $abs % self::MINOR_PER_MAJOR;

        $words = ($this->minor < 0 ? 'Minus ' : '') . 'Rupees ' . self::words($major);

        if ($paise > 0) {
            $words .= ' and ' . self::words($paise) . ' Paise';
        }

        return $words . ' Only';
    }

    private function scale(int $numerator, int $denominator, ?RoundingMode $mode): self
    {
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        if ($numerator % $denominator !== 0) {
            if ($mode === null) {
                throw new \LogicException(
                    'This operation is not exact in minor units and no rounding mode was declared. '
                    . 'There is no default rounding in SmartPRS 3 (spine S5.4).'
                );
            }

            return new self($mode->divide($numerator, $denominator), $this->currency);
        }

        return new self(intdiv($numerator, $denominator), $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} and {$other->currency}."
            );
        }
    }

    private static function currency(string $code): string
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new \InvalidArgumentException("Not an ISO 4217 currency code: {$code}");
        }

        return $code;
    }

    private const ONES = [
        'Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen',
        'Nineteen',
    ];

    private const TENS = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
    ];

    private static function words(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        if ($n < 100) {
            return self::TENS[intdiv($n, 10)] . ($n % 10 !== 0 ? ' ' . self::ONES[$n % 10] : '');
        }

        foreach ([10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $unit => $name) {
            if ($n >= $unit) {
                $rest = $n % $unit;

                return self::words(intdiv($n, $unit)) . ' ' . $name . ($rest !== 0 ? ' ' . self::words($rest) : '');
            }
        }

        return self::ONES[$n];
    }
}
