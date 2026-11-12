<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * Steps 1–3 of the §7.2 pipeline: resolve, filter, order.
 *
 *   1. resolve  published, decision point matches, effective_from <= as_of <=
 *               COALESCE(effective_to, '9999-12-31')
 *   2. filter   every constrained dimension of the rule's scope contains the
 *               subject's value for that dimension AS AT as_of
 *   3. order    specificity DESC, priority DESC, code ASC
 *
 * The precedence itself is spine S3.2 and lives in Support\Scope — the weight
 * table, the comparison and the tie signal. This class sorts by it and records
 * why each rule that is not in the running is not in the running, which is what
 * makes a trace answer "why did this NOT happen?" (§11.3).
 *
 * `code ASC` is a tie-break FOR ORDERING ONLY (§7.4). It never resolves a
 * precedence tie: a genuine tie blocks publication (ConflictDetector), and if
 * one reaches the engine anyway the Engine fails rather than picking by code.
 */
final readonly class Resolver
{
    /**
     * @param  array<string, string|int|null>  $dimensions the subject's org and
     *         classification values as at $asOf, from the effective-dated
     *         assignment rows — never from a current-valued column (§7.2)
     * @return list<array{rule: Rule, rank: int, reason: ?string, detail: ?string}>
     *         candidates first in evaluation order, then everything excluded,
     *         each with the reason it was excluded
     */
    public static function resolve(RuleSet $set, array $dimensions, \DateTimeImmutable $asOf): array
    {
        $candidates = [];
        $excluded = [];

        foreach ($set->rules as $rule) {
            if (!$rule->isPublished()) {
                $excluded[] = self::row($rule, 'not_published', 'status is ' . $rule->status);

                continue;
            }

            if (!$rule->isInForce($asOf)) {
                $excluded[] = self::row(
                    $rule,
                    'not_in_force',
                    'effective ' . $rule->range . ', evaluated on ' . $asOf->format('Y-m-d'),
                );

                continue;
            }

            $miss = self::scopeMiss($rule, $dimensions);

            if ($miss !== null) {
                $excluded[] = self::row($rule, 'not_in_scope', $miss);

                continue;
            }

            $candidates[] = self::row($rule, null, null);
        }

        usort($candidates, static fn (array $a, array $b): int => [
            $b['rule']->specificity(), $b['rule']->priority, $a['rule']->code,
        ] <=> [
            $a['rule']->specificity(), $a['rule']->priority, $b['rule']->code,
        ]);

        // Rank = position in the precedence ladder. Two rules share a rank when
        // Scope::tiesWith() says they do — that is spine S3.2's own tie signal,
        // asked of the class that owns the weight table. S3.2 has no third
        // tie-break, so rules sharing a rank are genuinely equal and the engine
        // must not pretend otherwise.
        $rank = 0;
        $previous = null;

        foreach ($candidates as $i => $candidate) {
            $rule = $candidate['rule'];

            if ($previous !== null
                && !$previous->scope->tiesWith($rule->scope, $previous->priority, $rule->priority)) {
                $rank++;
            }

            $candidates[$i]['rank'] = $rank;
            $previous = $rule;
        }

        return [...$candidates, ...$excluded];
    }

    /** @return list<Rule> the candidates only, in evaluation order */
    public static function candidates(RuleSet $set, array $dimensions, \DateTimeImmutable $asOf): array
    {
        return array_map(
            static fn (array $row): Rule => $row['rule'],
            array_values(array_filter(
                self::resolve($set, $dimensions, $asOf),
                static fn (array $row): bool => $row['reason'] === null,
            )),
        );
    }

    /**
     * The dimension that kept the subject out, phrased for a support call.
     * Scope::matches() answers yes/no; a trace has to answer "which one".
     *
     * @param  array<string, string|int|null>  $dimensions
     */
    private static function scopeMiss(Rule $rule, array $dimensions): ?string
    {
        foreach ($rule->scope->predicates as $dimension => $values) {
            $actual = $dimensions[$dimension] ?? null;

            if ($actual === null) {
                return "subject has no {$dimension}; the rule constrains it to [" . implode(', ', $values) . ']';
            }

            if (!in_array((string) $actual, $values, true)) {
                return "{$dimension} = {$actual}, not in [" . implode(', ', $values) . ']';
            }
        }

        return null;
    }

    /** @return array{rule: Rule, rank: int, reason: ?string, detail: ?string} */
    private static function row(Rule $rule, ?string $reason, ?string $detail): array
    {
        return ['rule' => $rule, 'rank' => -1, 'reason' => $reason, 'detail' => $detail];
    }
}
