<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * TEN STATES, AND THE SET IS A PLATFORM CONSTANT — Part XXI §XXI.2.4.
 *
 * "An item type selects the subset it uses and may relabel a state for its own
 * screens; it may not invent an eleventh state, because the gate, the score, the
 * escalation ladder and the audit pack all reason over this vocabulary."
 *
 * WHICH OF THEM MEANS "VALID" IS THE TYPE'S, NOT THIS FILE'S (R-XXI-052). There
 * is deliberately no `isValid()` here and no VALID_STATES constant: valid is
 * `valid_state_codes` on the item type and nothing else, so changing it changes
 * the register, the score, the gate, the alerts and the audit pack together, in
 * one publish. SmartPRS 2 has DRA requiring `verified` while PCC accepts
 * `submitted`, asymmetrically and undocumented, because each consumer decided
 * for itself.
 */
final class ComplianceState
{
    public const NOT_APPLICABLE = 'not_applicable';
    public const UPCOMING = 'upcoming';
    public const DUE = 'due';
    public const PENDING = 'pending';
    public const SUBMITTED = 'submitted';
    public const PAID = 'paid';
    public const FILED = 'filed';
    public const ACKNOWLEDGED = 'acknowledged';
    public const VERIFIED = 'verified';
    public const OVERDUE = 'overdue';

    public const ALL = [
        self::NOT_APPLICABLE, self::UPCOMING, self::DUE, self::PENDING, self::SUBMITTED,
        self::PAID, self::FILED, self::ACKNOWLEDGED, self::VERIFIED, self::OVERDUE,
    ];

    /** How a verdict was arrived at — carried on every assertion (R-XXI-056). */
    public const BASIS_EVIDENCE = 'evidence';
    public const BASIS_DERIVED = 'derived';
    public const BASIS_NOT_ASSESSED = 'not_assessed';

    public static function assertKnown(string $state): void
    {
        if (!in_array($state, self::ALL, true)) {
            throw new \InvalidArgumentException(
                "'{$state}' is not one of the ten compliance states. A type may relabel a state for its own "
                . 'screens and may not invent an eleventh, because the gate, the score, the escalation '
                . 'ladder and the audit pack all reason over this vocabulary.'
            );
        }
    }
}
