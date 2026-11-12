<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Support\Money;

/**
 * A DECLARED BOUND ON A COMPONENT — Part VIII §6.5, R-VIII-030.
 *
 * "Clamping is a declared property (`non_negative`, `min_value`, `max_value`)
 * on the component, applied by the engine at a named step that appears in the
 * trace. There is no implicit `max(0, …)` anywhere in the engine."
 *
 * SmartPRS 2 has three different opinions about the same clamp: `computeSlip`
 * clamps net at zero with `max(0, …)` at :2129, the Simulator clamps at a
 * different point (:2206-2208) so the two disagree, and
 * `computeSlipFromComponents` prints a negative residue as a negative earning
 * line. None of the three is declared, so none can be found by reading the
 * configuration, and an employee cannot be told which one produced their
 * payslip.
 *
 * THE ASYMMETRY IS DELIBERATE, and it is the §6.5 table's:
 *
 *   `non_negative` NEVER modifies a value. A component that computes negative
 *   when its declaration says it cannot is `ERR_RANGE` — surfaced as a payroll
 *   preview exception naming the component, for a human to look at. A negative
 *   earning is a defect somewhere upstream, and silently turning it into zero
 *   destroys the evidence of it.
 *
 *   `min` and `max` DO modify, and record that they did. Those are policy
 *   bounds — a capped allowance, a floored stipend — where the bound IS the
 *   intended answer rather than a symptom.
 *
 * Either way the check appears in the trace, including when it did not bind:
 * "clamp non_negative — not binding" is information, because it says the bound
 * was declared and checked rather than absent.
 */
final readonly class Clamp
{
    public const KINDS = ['non_negative', 'min', 'max', 'range'];

    private function __construct(
        public string $kind,
        public ?int $minMinor = null,
        public ?int $maxMinor = null,
    ) {
    }

    /** A guard, not a clamp: it raises rather than modifies. */
    public static function nonNegative(): self
    {
        return new self('non_negative', 0, null);
    }

    public static function min(Money $bound): self
    {
        return new self('min', $bound->minor, null);
    }

    public static function max(Money $bound): self
    {
        return new self('max', null, $bound->minor);
    }

    public static function range(Money $low, Money $high): self
    {
        if ($high->minor < $low->minor) {
            throw new \InvalidArgumentException(
                "A range clamp of [{$low->format()}, {$high->format()}] is empty, so nothing can satisfy it."
            );
        }

        return new self('range', $low->minor, $high->minor);
    }

    /**
     * Apply the bound to a rounded amount.
     *
     * @return array{money: Money, applied: bool, error: ?string, step: string}
     *         `error` is set only for a violated `non_negative`, which is the
     *         one kind that refuses instead of adjusting.
     */
    public function applyTo(Money $amount, string $componentCode): array
    {
        if ($this->kind === 'non_negative') {
            return $amount->minor < 0
                ? [
                    'money' => $amount,
                    'applied' => false,
                    'error' => "ERR_RANGE: {$componentCode} computed {$amount->format()} and its declaration "
                        . 'says non_negative. This is a payroll preview exception naming the component, not a '
                        . 'clamp — a negative earning is a defect upstream, and turning it into zero destroys '
                        . 'the evidence of it (§6.5, R-VIII-030).',
                    'step' => "clamp non_negative — VIOLATED at {$amount->format()}",
                ]
                : [
                    'money' => $amount,
                    'applied' => false,
                    'error' => null,
                    'step' => 'clamp non_negative — not binding',
                ];
        }

        $bounded = $amount->minor;

        if ($this->minMinor !== null && $bounded < $this->minMinor) {
            $bounded = $this->minMinor;
        }

        if ($this->maxMinor !== null && $bounded > $this->maxMinor) {
            $bounded = $this->maxMinor;
        }

        $applied = $bounded !== $amount->minor;

        return [
            'money' => $applied ? Money::ofMinor($bounded, $amount->currency) : $amount,
            'applied' => $applied,
            'error' => null,
            'step' => $applied
                ? "clamp {$this->describe()} — APPLIED, {$amount->format()} → "
                    . Money::ofMinor($bounded, $amount->currency)->format()
                : "clamp {$this->describe()} — not binding",
        ];
    }

    public function describe(): string
    {
        return match ($this->kind) {
            'non_negative' => 'non_negative',
            'min' => 'min ' . Money::ofMinor((int) $this->minMinor)->format(),
            'max' => 'max ' . Money::ofMinor((int) $this->maxMinor)->format(),
            'range' => 'range [' . Money::ofMinor((int) $this->minMinor)->format()
                . ', ' . Money::ofMinor((int) $this->maxMinor)->format() . ']',
        };
    }

    /** For the §4.1 payload row and the checksum. */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'min_minor' => $this->minMinor, 'max_minor' => $this->maxMinor];
    }
}
