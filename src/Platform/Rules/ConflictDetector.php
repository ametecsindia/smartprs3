<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\Scope;

/**
 * The publish-time check of Part VII §11.4 and spine S3.2 rule 3.
 *
 * Two rules of identical specificity AND identical priority whose conditions can
 * both be true for one subject are ambiguous, and spine S3.2 has no third
 * tie-break. Publication is refused, naming both rules and a witness.
 *
 *   1. group by (decision point, specificity, priority)
 *   2. within a group, test SCOPE INTERSECTION — a conjunction of set
 *      memberships, so this is decidable exactly
 *   3. for intersecting pairs, test CONDITION SATISFIABILITY over the decidable
 *      fragment: numeric and temporal comparisons, BETWEEN, IN, equality on Code
 *      and Boolean, combined with AND
 *   4. anything outside that fragment is UNDECIDABLE and is treated as
 *      CONFLICTING
 *
 * R-VII-060: the detector must never report a false NEGATIVE. If it cannot prove
 * disjointness it treats the pair as a conflict. A false positive costs one
 * `priority` keystroke; a false negative would reintroduce row-order resolution,
 * which is what SmartPRS 2 does in four separate implementations.
 *
 * §8.2 Example 6 is the case this must NOT obstruct: two banded rules,
 * `> 120 AND <= 240` against `> 240`, provably disjoint, publication allowed.
 */
final readonly class ConflictDetector
{
    /**
     * @param  list<Rule>  $rules  published plus the candidate being published
     * @throws AmbiguousConfiguration on the first genuine tie
     */
    public function assertPublishable(array $rules): void
    {
        $this->assertOnePolicyPerDecisionPoint($rules);

        foreach ($this->findConflicts($rules) as $conflict) {
            throw new AmbiguousConfiguration(
                [$conflict['a']->describe(), $conflict['b']->describe()],
                $conflict['message'],
            );
        }
    }

    /**
     * @param  list<Rule>  $rules
     * @return list<array{a: Rule, b: Rule, undecidable: bool, witness: ?array, message: string}>
     */
    public function findConflicts(array $rules): array
    {
        // ponytail: O(n²) inside each (decision point, specificity, priority)
        // group. A group is the set of rules an author deliberately made equally
        // specific — a handful, not thousands. If a tenant ever ships a group
        // large enough to matter, index the groups by scope dimension first.
        $groups = [];

        foreach ($rules as $rule) {
            if (in_array($rule->status, ['superseded', 'archived'], true)) {
                continue;
            }

            $groups[$rule->decisionPoint . '|' . $rule->specificity() . '|' . $rule->priority][] = $rule;
        }

        ksort($groups);
        $conflicts = [];

        foreach ($groups as $group) {
            $count = count($group);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $conflict = $this->pair($group[$i], $group[$j]);

                    if ($conflict !== null) {
                        $conflicts[] = $conflict;
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * §7.3: a rule may narrow but not widen the decision point's policy. Two
     * rules at one decision point declaring different policies makes "what does
     * this decision point do" unanswerable, so it is refused here rather than
     * settled by whichever rule sorted first.
     *
     * @param list<Rule> $rules
     */
    public function assertOnePolicyPerDecisionPoint(array $rules): void
    {
        $byPoint = [];

        foreach ($rules as $rule) {
            if (!$rule->isPublished() && $rule->status !== 'draft' && $rule->status !== 'testing') {
                continue;
            }

            $seen = $byPoint[$rule->decisionPoint] ?? null;

            if ($seen !== null && $seen->onConflict !== $rule->onConflict) {
                throw new AmbiguousConfiguration(
                    [$seen->describe(), $rule->describe()],
                    'They declare different conflict policies (' . $seen->onConflict->value
                    . ' and ' . $rule->onConflict->value . ') at decision point ' . $rule->decisionPoint
                    . '. The policy belongs to the decision point (§7.3).',
                );
            }

            $byPoint[$rule->decisionPoint] = $rule;
        }
    }

    /** @return array{a: Rule, b: Rule, undecidable: bool, witness: ?array, message: string}|null */
    private function pair(Rule $a, Rule $b): ?array
    {
        if (!$a->scope->tiesWith($b->scope, $a->priority, $b->priority)) {
            return null; // grouping already guarantees this; kept as the assertion it is
        }

        if (!$this->scopesIntersect($a->scope, $b->scope)) {
            return null;
        }

        $claimsA = $a->when->claims();
        $claimsB = $b->when->claims();

        if ($claimsA === null || $claimsB === null) {
            $which = $claimsA === null ? $a->code : $b->code;

            return [
                'a' => $a,
                'b' => $b,
                'undecidable' => true,
                'witness' => null,
                'message' => "The condition of {$which} is outside the solver's decidable fragment "
                    . '(an agg.* call, a MATCHES, an OR, or arithmetic the interval solver does not model), '
                    . 'so disjointness cannot be proved. Undecidable is treated as conflicting (R-VII-060): '
                    . 'a false negative here would reintroduce row-order resolution.',
            ];
        }

        $witness = Satisfiability::witness([...$claimsA, ...$claimsB]);

        if ($witness === null) {
            return null; // provably disjoint — the normal shape of a banded policy (§8.2 Example 6)
        }

        $rendered = [];

        foreach ($witness as $symbol => $value) {
            $rendered[] = $symbol . ' = ' . Values::render($value);
        }

        return [
            'a' => $a,
            'b' => $b,
            'undecidable' => false,
            'witness' => $witness,
            'message' => 'Both rules can match the same employee-day and their conditions are simultaneously '
                . 'satisfiable. Witness: ' . ($rendered === [] ? '(any subject)' : implode(', ', $rendered)) . '.',
        ];
    }

    /**
     * Scope is a conjunction of set memberships, so intersection is decidable
     * exactly: two scopes admit a common employee unless some dimension is
     * constrained in both with disjoint value sets. `ALL` on either side
     * intersects anything.
     */
    private function scopesIntersect(Scope $a, Scope $b): bool
    {
        foreach ($a->predicates as $dimension => $values) {
            $other = $b->predicates[$dimension] ?? null;

            if ($other !== null && array_intersect($values, $other) === []) {
                return false;
            }
        }

        return true;
    }
}
