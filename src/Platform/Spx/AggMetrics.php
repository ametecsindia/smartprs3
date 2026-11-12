<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE DECLARED TYPE OF EVERY AGGREGATABLE EVENT, MEASURE AND LEDGER.
 *
 * `agg.count(late_arrival, payroll_period, { event.value > … })` — the `event.value`
 * inside that predicate is the late arrival's MINUTES, and the comparand is a
 * policy in minutes. Both sides being typed is the whole point; typing one and
 * leaving the other Number is how a unit error survives review.
 *
 * SymbolTable carried an `aggMetrics` registry and `agg()` already consulted it
 * for the result type of `agg.sum`. It was empty, so every `event.value` was
 * Number and `agg.sum` over any metric reported "not declared with a type" —
 * the same shape of hole as PolicyKeys: the mechanism existed, nothing filled
 * it in, and the type system stopped at the edge of the aggregate.
 *
 * The type here is the type of ONE occurrence's value. It is what `event.value`
 * reads inside a predicate block and what `agg.sum` returns over the metric;
 * `agg.count` is a count and is Number whatever it counts.
 */
final class AggMetrics
{
    /**
     * Metric code => the SPX type of one occurrence's value.
     *
     * Every entry is used by a worked rule in the blueprint and compiled by
     * tools/blueprint/compile-worked-rules.php. Add a row in the same change
     * that adds the event, measure or ledger it names.
     */
    public const METRICS = [
        // Attendance events — the value is the quantity the event carries.
        'late_arrival' => 'minutes',
        'early_exit' => 'minutes',
        'late_exit' => 'minutes',
        'worked_minutes' => 'minutes',
        'ot_minutes' => 'minutes',
        'break_overrun' => 'minutes',
        'absent_day' => 'days',
        'attendance_day' => 'days',
        'punch' => 'number',

        // Leave and comp-off.
        'comp_off_earned' => 'days',
        'leave_day' => 'days',

        // Compliance and communication.
        'dunning_sent' => 'number',
        'credential_lapse' => 'number',

        // LEDGERS. `agg.balance('CODE', as_of)` reads a running balance rather
        // than counting occurrences, so the metric's type is the balance's type:
        // a leave ledger carries days, a reimbursement carry-forward carries
        // money. Part VIII §7.1 makes a ledger a graph SOURCE — it has no
        // outgoing edges, because the balance is a fact the caller prepares.
        'LEAVE-EL' => 'days',
        'LEAVE-CL' => 'days',
        'LEAVE-SL' => 'days',
        'COMPOFF' => 'days',
        'REIMB-CARRY' => 'money',
        'LOAN-OUTSTANDING' => 'money',
    ];

    /** @var array<string, Type>|null */
    private static ?array $types = null;

    /** @return array<string, Type> metric code => occurrence value type */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        $types = [];

        foreach (self::METRICS as $code => $name) {
            $types[$code] = match ($name) {
                'number' => Type::number(),
                'money' => Type::money(),
                'minutes' => Type::minutes(),
                'hours' => Type::hours(),
                'days' => Type::days(),
                default => throw new \LogicException("'{$name}' is not an aggregatable SPX type."),
            };
        }

        return self::$types = $types;
    }
}
