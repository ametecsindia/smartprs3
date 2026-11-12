<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * BAND TABLES AS CONFIGURATION — the two-argument form of BANDS().
 *
 * The blueprint writes `BANDS(value, table)` in four places, across both the
 * rule library and the formula library: an overtime multiplier by day type, an
 * attendance incentive, a commission rate, a lateness band. That is not a slip —
 * it is the more consistent design, because a band table an administrator
 * maintains as one artefact is configuration, and two lists written inline
 * inside an expression are not.
 *
 * The table is referenced BY LITERAL CODE, exactly as SLAB() references a slab
 * table. That is not decoration: the type checker must know what a BANDS call
 * returns before anything runs, and it can only know that if the table is named
 * by a literal it can resolve at publish. A computed reference would defer the
 * question to runtime, which is where SmartPRS 2's band bugs live —
 * IncentiveController::payoutFor() falls through its band loop to the LAST
 * band's rate whenever a guard is false, so a ₹6,00,000 collection lands on the
 * 3% band by accident of loop structure rather than by design.
 *
 * The BOUNDS and RESULTS live in the prepared context, put there by the caller
 * from the resolved configuration artefact. What lives here is the declared
 * RESULT TYPE of each table, which is what the expression around the call has
 * to agree with.
 */
final class BandTables
{
    /**
     * Table code => the SPX type of the value the table returns.
     *
     * A table the caller resolves that is not listed here has no declared
     * result type, and a BANDS call naming it does not publish — the same
     * stance PolicyKeys takes on an undeclared policy key, and for the same
     * reason: a typo would otherwise be discovered as a missing value at
     * runtime, in the middle of a payroll run.
     */
    public const TABLES = [
        'OT-MULTIPLIER-BANDS' => 'number',
        'ATT-INCENTIVE-BANDS' => 'money',
        'COMM-RATE-BANDS' => 'number',
        'LATE-PENALTY-BANDS' => 'day_fraction',
        'BREAK-OVERRUN-BANDS' => 'day_fraction',
    ];

    /** @var array<string, Type>|null */
    private static ?array $types = null;

    /** @return array<string, Type> */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        $types = [];

        foreach (self::TABLES as $code => $name) {
            $types[$code] = match ($name) {
                'number' => Type::number(),
                'money' => Type::money(),
                'percentage' => Type::percentage(),
                'days' => Type::days(),
                'day_fraction' => Type::dayFraction(),
                'minutes' => Type::minutes(),
                default => throw new \LogicException("'{$name}' is not a band-table result type."),
            };
        }

        return self::$types = $types;
    }

    public static function resultType(string $code): ?Type
    {
        return self::types()[$code] ?? null;
    }
}
