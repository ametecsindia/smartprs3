<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE DECLARED TYPE OF EVERY STATUTORY VALUE — spine S12, Part VIII §11.0.
 *
 * `statutory.value('PF_EMPLOYEE_RATE')` is a Percentage and
 * `statutory.value('PF_WAGE_CEILING')` is Money. Typed Money for every code —
 * as it was — `wage_base % rate` reads as Money % Money and does not type, so
 * the PF, bonus and gratuity formulas of Part VIII §11.6 could not be published
 * against the engine meant to run them.
 *
 * THE TYPE BELONGS TO THE PACK, NOT TO THIS FILE. A statutory value is carried
 * by a signed rule pack (spine S12), which is a third artefact class released
 * separately from the application, and the pack declares the type of each value
 * it carries. What is here is the SHIPPED DEFAULT — the codes the blueprint's
 * worked formulas use, so they compile on a checkout with no pack installed —
 * and a resolved pack overrides it through
 * `SymbolTable::withKeyedTypes('statutory.value', …)`.
 *
 * That distinction matters for a reason Part XVII is explicit about: the VALUES
 * here are recorded as "what SmartPRS 2's code currently uses" and are not a
 * statement of law. Neither are the types, and neither is signed. Verification
 * against the primary source is the named compliance owner's, before a pack is
 * signed (spine D-03). Nothing in this file is authority.
 */
final class StatutoryKeys
{
    /**
     * Statutory code => SPX type, for the codes Part VIII's worked formulas
     * read. A pack that carries a code not listed here supplies its own type.
     */
    public const DEFAULTS = [
        // Provident fund and pension — rates are percentages, ceilings are money.
        'PF_EMPLOYEE_RATE' => 'percentage',
        'PF_EMPLOYER_RATE' => 'percentage',
        'PF_WAGE_CEILING' => 'money',
        'EPS_RATE' => 'percentage',
        'EPS_WAGE_CEILING' => 'money',
        'EPS_MONTHLY_CAP' => 'money',
        'EDLI_RATE' => 'percentage',
        'EDLI_WAGE_CEILING' => 'money',

        // Bonus.
        'BONUS_PERCENT' => 'percentage',
        'BONUS_WAGE_CAP' => 'money',

        // Gratuity. The 15/26 pair are day counts, and typing them as Days is
        // what makes their ratio dimensionless — the same correction
        // period.working_days needed.
        'GRATUITY_NUMERATOR_DAYS' => 'days',
        'GRATUITY_DENOMINATOR_DAYS' => 'days',
        'GRATUITY_CEILING_AMOUNT' => 'money',

        // Leave minima.
        'EL_STATUTORY_MINIMUM_DAYS' => 'days',
        'SL_MIN_DAYS' => 'days',

        // Minimum wage, and the professional-tax slab table's own code.
        'MIN_WAGE_FOR_SCOPE' => 'money',
        'PT_SLAB_TABLE_CODE' => 'code',
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

        foreach (self::DEFAULTS as $code => $name) {
            $types[$code] = match ($name) {
                'number' => Type::number(),
                'money' => Type::money(),
                'percentage' => Type::percentage(),
                'days' => Type::days(),
                'code' => Type::code(),
                default => throw new \LogicException("'{$name}' is not a statutory value type."),
            };
        }

        return self::$types = $types;
    }
}
