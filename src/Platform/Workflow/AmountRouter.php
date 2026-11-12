<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

use SmartPRS\Platform\Support\Money;

/**
 * AMOUNT-BASED ROUTING — Part IX §IX.9.1, R-IX-027 and R-IX-077.
 *
 * W08 has validated amount bands since the SLA work, and nothing SELECTED one:
 * a definition could declare contiguous, exhaustive, non-overlapping bands and
 * no code anywhere could say which of them a ₹75,000 loan falls in. This is
 * that code.
 *
 * Three properties, each of which is a defect in SmartPRS 2 when it is missing:
 *
 *   INTEGER MINOR UNITS (R-IX-077). Floats never touch a routing threshold.
 *   SmartPRS 2 decides whether a loan instalment is deducted with a float
 *   comparison, and its SchemeController uses explicit 0.005 epsilons — the
 *   code itself acknowledging that its own comparisons are unreliable.
 *
 *   `from` INCLUSIVE, `to` EXCLUSIVE. Spelled out because a threshold of
 *   ₹50,000 belongs to exactly one band, and which one is the difference
 *   between a manager and a director approving it.
 *
 *   SELECTED AT NODE ACTIVATION, NOT AT INSTANCE CREATION (R-IX-027). An
 *   amended amount re-routes. A request raised at ₹40,000 and amended to
 *   ₹4,00,000 before anybody approved it must not still be sitting in the
 *   manager's queue.
 */
final class AmountRouter
{
    /**
     * The band an amount falls in, and its index.
     *
     * THE BAND TYPE IS DELIBERATELY WIDE, and it used to be narrow. It said
     * `list<array{from: ?int, to: ?int, …}>`, which reads as the more rigorous
     * of the two and is the opposite: bands arrive from a workflow definition in
     * JSON, where `"from": 50000.0` and `"from": "50000"` are both what a real
     * file contains — and under `treatPhpDocTypesAsCertain` that narrow docblock
     * made the two `is_int` guards below provably dead code. PHPStan reported
     * them as "always false" the first time it was ever run here. A guard at a
     * trust boundary that a docblock has quietly disproved is the worst of both
     * worlds: it looks like a check, it costs a branch, and the analyser has
     * been told to stop believing the input can be wrong. The declaration now
     * says what actually arrives, so the guards mean something again.
     *
     * @param  list<array<string, mixed>>  $bands  `from` and `to` in MINOR UNITS; null is unbounded
     * @return array{index: int, band: array<string, mixed>, why: string}
     *
     * @throws \InvalidArgumentException when no band admits the amount, which
     *         W08 makes impossible at publish and which is therefore a platform
     *         bug rather than a configuration error if it happens
     */
    public static function select(array $bands, Money $amount): array
    {
        if ($bands === []) {
            throw new \InvalidArgumentException(
                'A node routing on amount declares at least one band. W08 refuses a definition without '
                . 'them, so reaching here means the definition was not validated.'
            );
        }

        foreach ($bands as $index => $band) {
            $from = $band['from'] ?? null;
            $to = $band['to'] ?? null;

            if ($from !== null && !is_int($from)) {
                throw new \InvalidArgumentException(
                    'A band boundary is an integer in minor units (R-IX-077). Floats never touch a routing '
                    . 'threshold: got ' . get_debug_type($from) . '.'
                );
            }

            if ($to !== null && !is_int($to)) {
                throw new \InvalidArgumentException(
                    'A band boundary is an integer in minor units (R-IX-077); got ' . get_debug_type($to) . '.'
                );
            }

            // from inclusive, to exclusive.
            if (($from === null || $amount->minor >= $from) && ($to === null || $amount->minor < $to)) {
                return [
                    'index' => $index,
                    'band' => $band,
                    // The VALIDATED values, not the raw band. describe() used to
                    // re-read `$band['from']` and cast it — a second read of
                    // unchecked input, three lines after the guards that checked
                    // it, which would have rendered a band as "[₹0.00, …)" for
                    // any boundary the guards had just refused to trust.
                    'why' => self::describe($from, $to) . ' admits ' . $amount->format()
                        . ' (from is inclusive, to is exclusive)',
                ];
            }
        }

        throw new \InvalidArgumentException(
            'No band admits ' . $amount->format() . '. W08 requires the bands to be contiguous and to cover '
            . '(-∞, +∞), so this is a gap that should have been refused at publish — an unroutable amount '
            . 'is an instance that strands at the node holding the money.'
        );
    }

    /**
     * Whether re-evaluating the amount would change the route (R-IX-027).
     *
     * The question a node asks on activation: the amount is read again, and if
     * it now falls in a different band the node routes to the different one.
     * SmartPRS 2 has no equivalent, so an amended request keeps the approver
     * its original amount chose.
     *
     * @param list<array<string, mixed>> $bands see select() on why this is wide
     */
    public static function reroutes(array $bands, Money $atCreation, Money $atActivation): bool
    {
        return self::select($bands, $atCreation)['index'] !== self::select($bands, $atActivation)['index'];
    }

    private static function describe(?int $from, ?int $to): string
    {
        return match (true) {
            $from === null && $to === null => 'the single unbounded band',
            $from === null => 'the band below ' . Money::ofMinor((int) $to)->format(),
            $to === null => 'the band from ' . Money::ofMinor($from)->format() . ' upward',
            default => 'the band [' . Money::ofMinor($from)->format()
                . ', ' . Money::ofMinor((int) $to)->format() . ')',
        };
    }
}
