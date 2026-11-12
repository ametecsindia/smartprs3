<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE DECLARED TYPE OF EVERY POLICY KEY.
 *
 * `policy.value('late_grace_min')` is Minutes and `policy.value('hra_pct_of_basic')`
 * is a Percentage. Symbol::$keyedTypes has always been able to say so; nothing
 * had ever said it, so every policy read was Number and the unit system stopped
 * at the edge of configuration — the one place SmartPRS 2's unit confusion
 * actually lived.
 *
 * Two consequences, both wanted:
 *
 *   1. `attendance.late_minutes > policy.value('late_grace_min')` type-checks,
 *      and `> policy.value('ot_enabled')` does not.
 *   2. An undeclared key is a publish-time error naming the key (TypeChecker),
 *      so `policy.value('late_grace_mins')` is caught at authoring instead of
 *      resolving to nothing at runtime.
 *
 * This is a platform register, not tenant configuration: the TYPE of a policy is
 * part of its definition, while its VALUE is scoped, effective-dated
 * configuration like everything else. Adding a policy means adding a row here in
 * the same change that adds the policy artefact.
 *
 * PROVENANCE. The 18 keys in PROVED are proved: tools/blueprint/compile-worked-rules.php
 * compiles the worked rules of Part VII §12 against this table, and no other type
 * makes them compile. The 58 in INFERRED were read out of the blueprint's prose
 * and typed from their names; they are correct-looking, not proved, and each one
 * becomes proved the day an expression using it is compiled. Do not treat the
 * second list as authority — treat it as a to-confirm queue with a mechanism for
 * emptying itself.
 */
final class PolicyKeys
{
    /** Proved by tools/blueprint/compile-worked-rules.php. */
    public const PROVED = [
        'absence_hold_for_review'       => 'boolean',
        'basic_pct_of_ctc'                 => 'percentage',
        'basic_pct_of_gross'               => 'percentage',
        'comm_accelerator_points'          => 'number',
        'comm_accelerator_threshold'       => 'number',
        'comm_monthly_cap'                 => 'money',
        'comp_off_min_minutes'          => 'minutes',
        'compoff_day_divisor'              => 'number',
        'compoff_encash_max_days'          => 'number',
        'compoff_encash_multiplier'        => 'number',
        'credential_gate_events'        => 'codes',
        'credential_gate_mode'          => 'text',
        'credential_gate_type'          => 'code',
        'encash_day_divisor'               => 'number',
        'encash_max_days'                  => 'number',
        'fixed_day_divisor'                => 'number',
        'hra_pct_of_basic'                 => 'percentage',
        'late_band_absent_min'          => 'minutes',
        'late_band_half_min'            => 'minutes',
        'late_grace_min'                => 'minutes',
        'min_net_absolute'                 => 'money',
        'min_net_pct_of_gross'             => 'percentage',
        'ot_daily_threshold_hours'      => 'hours',
        'ot_day_divisor'                   => 'number',
        'ot_enabled'                    => 'boolean',
        'present_min_minutes'           => 'minutes',
        'probation_suppress_enabled'    => 'boolean',
        'probation_suppress_max_months' => 'number',
        'probation_suppressed_stages'   => 'codes',
        'reimb_carry_forward'              => 'boolean',
        'reimb_monthly_cap'                => 'money',
        'rolling_late_threshold'        => 'number',
        'sandwich_enabled'              => 'boolean',
        'sandwich_exempt_types'         => 'codes',
        'sandwich_requires_same_type'   => 'boolean',
    ];

    /** Read out of the blueprint and typed by name. NOT yet proved by a compile. */
    public const INFERRED = [
        'absconding_days'              => 'number',
        'absent_min_minutes'           => 'minutes',
        'att_incentive_bands'          => 'codes',
        'break_penalty_block_min'      => 'minutes',
        'break_penalty_step_days'      => 'number',
        'comm_bands'                   => 'codes',
        'comp_off_festival_units'      => 'number',
        'comp_off_full_minutes'        => 'minutes',
        'credential_alert_floor_days'  => 'number',
        'credential_warn_days'         => 'number',
        'dra_applicable_categories'    => 'codes',
        'early_band_1_min'             => 'minutes',
        'early_band_2_min'             => 'minutes',
        'half_day_min_minutes'         => 'minutes',
        'late_free_count'              => 'number',
        'late_free_window'             => 'number',
        'late_penalty_per_occurrence'  => 'money',
        'max_consecutive_workdays'     => 'number',
        'missing_punch_policy'         => 'text',
        'night_allowance_min_minutes'  => 'minutes',
        'night_window_from'            => 'text',
        'night_window_min_minutes'     => 'minutes',
        'night_window_to'              => 'text',
        'offset_daily_cap_min'         => 'minutes',
        'ot_approval_mode'             => 'text',
        'ot_days_divisor'              => 'number',
        'ot_hours_divisor'             => 'number',
        'ot_min_minutes_on_holiday'    => 'number',
        'ot_min_minutes_on_off'        => 'number',
        'ot_min_night_minutes'         => 'minutes',
        'ot_min_trigger_min'           => 'minutes',
        'ot_multiplier_bands'          => 'codes',
        'ot_requires_pre_approval'     => 'boolean',
        'ot_rounding_block_min'        => 'minutes',
        'ot_rounding_mode'             => 'text',
        'ot_shift_end_grace_min'       => 'minutes',
        'ot_weekly_threshold_hours'    => 'hours',
        'pcc_required_states'          => 'codes',
        'split_missed_segment_penalty' => 'number',
        'week_start_day'               => 'text',
        'weekly_off_work_treatment'    => 'text',
    ];

    /** @var array<string, Type>|null */
    private static ?array $types = null;

    /** @return array<string, Type> key => declared SPX type */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        $types = [];

        foreach (self::PROVED + self::INFERRED as $key => $name) {
            $types[$key] = self::type($name);
        }

        return self::$types = $types;
    }

    public static function isDeclared(string $key): bool
    {
        return isset(self::PROVED[$key]) || isset(self::INFERRED[$key]);
    }

    /** True when the key's type is proved by a compiled expression, not inferred. */
    public static function isProved(string $key): bool
    {
        return isset(self::PROVED[$key]);
    }

    private static function type(string $name): Type
    {
        return match ($name) {
            'number' => Type::number(),
            'money' => Type::money(),
            'minutes' => Type::minutes(),
            'hours' => Type::hours(),
            'days' => Type::days(),
            'boolean' => Type::boolean(),
            'text' => Type::text(),
            'date' => Type::date(),
            'percentage' => Type::percentage(),
            // Bare Code and List<Code>: the value set belongs to the reading
            // rule, and Coercion lets a bare Code stand where a declared set is
            // wanted. The value is checked against the real set at runtime.
            'code' => Type::code(),
            'codes' => Type::listOf(Type::code()),
            default => throw new \LogicException("'{$name}' is not an SPX type name."),
        };
    }
}
