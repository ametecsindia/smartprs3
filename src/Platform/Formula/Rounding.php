<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * THE ROUNDING DECLARATION — Part VIII §6.3.
 *
 * "Rounding is a property of the value, declared once, on the component or head
 * — never a consequence of which function computed it." R-VIII-025: a component
 * with no rounding declaration cannot be published.
 *
 * That rule is the answer to a real audit finding: in SmartPRS 2, ESI is the
 * only ceil in the system, statutory bonus and one of the two gratuity reports
 * are the only whole-rupee roundings, LWF is never rounded at all, and the two
 * gratuity reports round differently from one another — none of it declared
 * anywhere, each an accident of the function that happened to produce it.
 *
 * `up`/`down` are away-from-zero / toward-zero; `ceil`/`floor` are toward +∞/−∞.
 * Both pairs exist because they differ on negative amounts, and a recovery is a
 * negative amount.
 */
final readonly class Rounding
{
    /** §6.3, with `none` modelled as absence rather than as a mode. */
    private const MODES = [
        'half_up' => RoundingMode::HalfUp,
        'half_down' => RoundingMode::HalfDown,
        'half_even' => RoundingMode::HalfEven,
        'up' => RoundingMode::Up,
        'down' => RoundingMode::Down,
        'ceil' => RoundingMode::Ceiling,
        'floor' => RoundingMode::Floor,
    ];

    /** §4.1: `final` — round once, on the result. `none` — never rounded. */
    public const POINTS = ['final', 'none'];

    private function __construct(
        public ?RoundingMode $mode,
        /** The quantum in minor units: 1 = a paisa, 100 = a rupee, 10000 = ₹100. */
        public int $unitInMinor,
        public string $point,
        public string $declaredMode,
        public string $declaredUnit,
    ) {
    }

    /**
     * @param string $mode one of §6.3's eight
     * @param string $unit the quantum in major units, e.g. '0.01', '1.00', '100.00'
     */
    public static function of(string $mode, string $unit = '0.01', string $point = 'final'): self
    {
        if ($mode === 'none') {
            return self::none();
        }

        if (!isset(self::MODES[$mode])) {
            throw new \InvalidArgumentException(
                "'{$mode}' is not a rounding mode. One of: none, " . implode(', ', array_keys(self::MODES)) . ' (§6.3).'
            );
        }

        if (!in_array($point, self::POINTS, true)) {
            throw new \InvalidArgumentException("'{$point}' is not a rounding point (§4.1).");
        }

        if ($point === 'none') {
            throw new \InvalidArgumentException(
                "rounding_point 'none' means the value is never rounded, so rounding_mode must be 'none' too (§4.1)."
            );
        }

        $minor = self::minorUnits($unit);

        if ($minor <= 0) {
            throw new \InvalidArgumentException("rounding_unit must be greater than zero, got '{$unit}' (§4.1).");
        }

        return new self(self::MODES[$mode], $minor, $point, $mode, $unit);
    }

    /** An intermediate formula: never rounded, so the exact rational survives. */
    public static function none(): self
    {
        return new self(null, 0, 'none', 'none', '0.0000');
    }

    public function isNone(): bool
    {
        return $this->mode === null;
    }

    /**
     * Collapse an exact amount to the declared quantum. This is the ONE place a
     * money value stops being exact; §6.2 keeps every intermediate rational so
     * that it happens once, here, and not per operation.
     */
    public function apply(Money $amount): Money
    {
        if ($this->mode === null) {
            return $amount;
        }

        return $amount->roundTo($this->unitInMinor, $this->mode);
    }

    /** The same declaration applied to a non-money quantity (days, hours). */
    public function applyToRational(Rational $value): Rational
    {
        if ($this->mode === null) {
            return $value;
        }

        // The quantum in minor units is hundredths of the unit, as for money.
        $scaled = Rational::of($value->numerator * 100, $value->denominator * $this->unitInMinor);
        $units = $this->mode->divide($scaled->numerator, $scaled->denominator);

        return Rational::of($units * $this->unitInMinor, 100);
    }

    /** "half_up to 0.01" — what the trace prints, so the choice is visible. */
    public function describe(): string
    {
        return $this->isNone()
            ? 'not rounded (intermediate)'
            : $this->declaredMode . ' to ' . $this->declaredUnit;
    }

    /** '0.01' → 1, '1.00' → 100, '100.00' → 10000. Exact, never via a float. */
    private static function minorUnits(string $unit): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', $unit, $m) !== 1) {
            throw new \InvalidArgumentException("'{$unit}' is not a decimal rounding unit (§4.1).");
        }

        $fraction = str_pad($m[2] ?? '', 4, '0');
        $tenThousandths = (int) $m[1] * 10000 + (int) $fraction;

        if ($tenThousandths % 100 !== 0) {
            throw new \InvalidArgumentException(
                "rounding_unit '{$unit}' is finer than a paisa; money is integer paise (Law 7)."
            );
        }

        return intdiv($tenThousandths, 100);
    }
}
