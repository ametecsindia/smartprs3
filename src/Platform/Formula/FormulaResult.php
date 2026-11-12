<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * One formula's answer, with the arithmetic that produced it (Part VIII §10.2).
 *
 * R-VIII-050: no formula returns a value without a trace. A trace-less result is
 * a programming error and throws — Law 4, "every number explains itself" — so
 * this object carries both and there is no constructor that omits one.
 *
 * The pair that matters is `exact` and `rounded`, with `delta` between them.
 * §6.2 keeps every intermediate as an exact rational so that rounding happens
 * ONCE, at the declared boundary; the delta is what that boundary cost, in
 * paise, and it is printed rather than inferred. SmartPRS 2 rounds per
 * operation, and the accumulated difference is invisible by construction.
 */
final readonly class FormulaResult
{
    private function __construct(
        public string $code,
        public string $versionId,
        public ?Money $money,
        public ?Rational $exact,
        public ?Value $value,
        public Rounding $rounding,
        public string $trace,
        public ?string $error = null,
        public bool $memoHit = false,
    ) {
    }

    public static function money(
        string $code,
        string $versionId,
        Money $rounded,
        Rational $exact,
        Rounding $rounding,
        string $trace,
    ): self {
        return new self($code, $versionId, $rounded, $exact, null, $rounding, $trace);
    }

    /** A non-money result: days, hours, a count, a rate. */
    public static function value(
        string $code,
        string $versionId,
        Value $value,
        Rounding $rounding,
        string $trace,
    ): self {
        return new self($code, $versionId, null, null, $value, $rounding, $trace);
    }

    public static function failed(string $code, string $versionId, string $error, string $trace): self
    {
        return new self($code, $versionId, null, null, null, Rounding::none(), $trace, $error);
    }

    public function asMemoHit(): self
    {
        return new self(
            $this->code,
            $this->versionId,
            $this->money,
            $this->exact,
            $this->value,
            $this->rounding,
            $this->trace,
            $this->error,
            true,
        );
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * What a DEPENDENT formula reads (§4.1 `reference_value`, D-VIII-01).
     *
     * `rounded` is the published number — the one that appeared on the payslip.
     * `exact` is the pre-rounding rational, and is permitted only from an
     * intermediate, because reading the exact value of a component would mean
     * calculating from a number no payslip ever showed.
     */
    public function referenceValue(string $referenceValue): ?Rational
    {
        if ($this->money === null) {
            return null;
        }

        return $referenceValue === 'exact' && $this->exact !== null
            ? $this->exact
            : Rational::of($this->money->minor);
    }

    /** rounded − exact, in minor units. Zero when the boundary cost nothing. */
    public function deltaMinor(): ?Rational
    {
        if ($this->money === null || $this->exact === null) {
            return null;
        }

        return Rational::of(
            $this->money->minor * $this->exact->denominator - $this->exact->numerator,
            $this->exact->denominator,
        );
    }

    public function describe(): string
    {
        if ($this->isError()) {
            return $this->code . ': ' . $this->error;
        }

        return $this->code . ' = ' . ($this->money?->format() ?? $this->value?->describe() ?? '(no value)');
    }
}
