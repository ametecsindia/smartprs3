<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * Part VII §7.3. Three policies, closed set.
 */
enum ConflictPolicy: string
{
    /**
     * Evaluate in order; the first matching rule's actions are the result.
     * Every subsequent candidate is still recorded in the trace as
     * `not_evaluated (outranked)` — the alternatives stay visible.
     */
    case HighestPriorityOnly = 'highest_priority_only';

    /**
     * As above, but evaluation STOPS at the first match: later candidates are
     * not even scope-checked. The cheap variant, for decision points whose trace
     * does not need the alternatives. Not permitted for `high_risk` rules.
     */
    case FirstMatch = 'first_match';

    /** Every matching rule contributes its actions. Additive questions. */
    case AllMatch = 'all_match';

    public function isSingleOutcome(): bool
    {
        return $this !== self::AllMatch;
    }

    /**
     * How much outcome a policy admits. §4.1: a rule "may narrow but not widen
     * the decision point's default", which is only checkable if the three are
     * ordered — so they are ordered here, once, rather than in each comparison.
     *
     * all_match admits every matching rule's actions; highest_priority_only
     * admits one rule's, with the alternatives still traced; first_match admits
     * one rule's and stops looking. Strictly decreasing.
     */
    public function breadth(): int
    {
        return match ($this) {
            self::AllMatch => 2,
            self::HighestPriorityOnly => 1,
            self::FirstMatch => 0,
        };
    }

    /** True when this policy is no broader than the decision point's default. */
    public function narrows(self $default): bool
    {
        return $this->breadth() <= $default->breadth();
    }

    /**
     * The narrower of the two — what actually governs a run.
     *
     * A rule that widens is refused at publish, so this is a second, structural
     * gate rather than a substitute for the first: a version published before
     * the gate existed cannot widen its decision point by still being in force.
     */
    public static function narrowerOf(self $a, self $b): self
    {
        return $a->breadth() <= $b->breadth() ? $a : $b;
    }
}
