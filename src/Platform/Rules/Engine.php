<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Rules\Actions\AppliedAction;
use SmartPRS\Platform\Spx\Functions\Catalogue;
use SmartPRS\Platform\Spx\Sandbox;

/**
 * The evaluation contract of spine S6 and Part VII §7.1.
 *
 *   evaluate(decision_point, rule set, prepared context, as_of)
 *       -> ordered AppliedAction[] + DecisionTrace
 *
 * It never mutates state, never enqueues a job, never sends a notification and
 * never writes an audit row (R-VII-028). It holds no repository and no clock, so
 * there is nothing it could mutate and nothing it could read that the caller did
 * not prepare. That is what makes the simulator trivially safe: the same code
 * path, the same inputs, no side effects to suppress.
 *
 * The engine is a filter, a sort and a loop:
 *
 *   1. guard     employment boundaries and tenant (§7.6)
 *   2. resolve   published, in force, in scope        Resolver
 *   3. order     specificity DESC, priority DESC, code ASC   Resolver
 *   4. evaluate  `when`, then `except`, under the conflict policy (§7.3)
 *   5. collect   resolve parameters, fingerprint, order (§7.4)
 *   6. seal      build the trace, return
 */
final readonly class Engine
{
    /** §7.7. Statically checkable, so normally rejected at publish; re-asserted here. */
    public const MAX_CANDIDATES = 200;

    /**
     * The node/depth/time budgets are SPX's (§7.7, §10.1b), so the engine holds
     * only the numbers and hands a FRESH Sandbox to every expression it
     * evaluates. Per-expression, not per-evaluation: a rule set of twenty rules
     * is not a runaway just because it is twenty rules, and a single runaway
     * condition is stopped by a named ERR_BUDGET_NODES with the budget in it.
     */
    public function __construct(
        private string $engineVersion = 'rules/1.0.0 spx/' . Catalogue::VERSION . ' actions/' . ActionCatalogue::VERSION,
        private int $nodeBudget = Sandbox::MAX_NODES,
        private int $millisBudget = Sandbox::MAX_MILLIS,
    ) {
    }

    private function sandbox(): Sandbox
    {
        return new Sandbox($this->nodeBudget, $this->millisBudget);
    }

    /**
     * @throws RuleEvaluationFailed for `status = failed`, and only for that
     */
    public function evaluate(
        string $decisionPoint,
        RuleSet $set,
        EvaluationContext $context,
        ?\DateTimeImmutable $asOf = null,
    ): EvaluationResult {
        $asOf ??= $context->subjectDate;
        $started = hrtime(true);

        if ($set->decisionPoint !== $decisionPoint) {
            throw new \InvalidArgumentException(
                "Rule set is for '{$set->decisionPoint}', evaluation asked for '{$decisionPoint}'."
            );
        }

        // ---- 1. guard (§7.6) ------------------------------------------------
        // Three platform guards that are NOT rules and cannot be overridden by
        // one. Guard 2 exists because SmartPRS 2 has no leaver proration at all
        // and pays a mid-month leaver a full month.
        $guard = $this->guard($context);

        if ($guard !== null) {
            return new EvaluationResult(
                DecisionTrace::NO_MATCH,
                [],
                $this->seal($decisionPoint, $context, $asOf, DecisionTrace::NO_MATCH, [], [], [
                    'rules_considered' => 0, 'rules_matched' => 0, 'expressions' => 0,
                    'agg_calls' => 0, 'micros' => $this->micros($started),
                ], $guard),
            );
        }

        // ---- 2 & 3. resolve, filter, order ----------------------------------
        $rows = Resolver::resolve($set, $context->dimensions, $asOf);
        $candidates = array_values(array_filter($rows, static fn (array $r): bool => $r['reason'] === null));

        if (count($candidates) > self::MAX_CANDIDATES) {
            throw new \InvalidArgumentException(
                'ERR_BUDGET_CANDIDATES: ' . count($candidates) . ' candidate rules at ' . $decisionPoint
                . ' exceeds the budget of ' . self::MAX_CANDIDATES . ' (§7.7). This is a publish-time budget.'
            );
        }

        // §7.3: the policy belongs to the decision point; a rule may narrow but
        // not widen it. The decision point's declared default is therefore the
        // CEILING, and the highest-precedence candidate may only come in under
        // it — so a rule cannot widen its decision point by winning precedence,
        // and a version published before that gate existed cannot either.
        // ConflictDetector still refuses a set whose rules disagree with each
        // other.
        $declared = DecisionPoints::conflictPolicy($decisionPoint);
        $policy = $candidates === []
            ? $declared
            : ConflictPolicy::narrowerOf($declared, $candidates[0]['rule']->onConflict);

        // ---- 4. evaluate ----------------------------------------------------
        $traces = [];
        $matches = [];
        $expressions = 0;
        $stopReason = null;
        $winningRank = null;

        foreach ($candidates as $index => $row) {
            /** @var Rule $rule */
            $rule = $row['rule'];

            if ($stopReason !== null) {
                $traces[] = $this->candidateTrace(
                    $rule,
                    $index,
                    CandidateTrace::NOT_EVALUATED_STOPPED,
                    $stopReason,
                );

                continue;
            }

            // Single-outcome policies: once a rank has produced a winner, every
            // later candidate is outranked and is recorded as such, so the
            // alternatives stay visible in the trace (§7.3).
            if ($policy->isSingleOutcome() && $winningRank !== null && $row['rank'] > $winningRank) {
                $winner = $matches[0]['rule'];
                $traces[] = $this->candidateTrace(
                    $rule,
                    $index,
                    CandidateTrace::NOT_EVALUATED_OUTRANKED,
                    $this->outrankedBy($rule, $winner),
                );

                continue;
            }

            $when = $rule->when->evaluate($context, $this->sandbox());
            $expressions++;

            if ($when->isError()) {
                throw $this->fail(
                    $decisionPoint,
                    $context,
                    $asOf,
                    [...$traces, $this->candidateTrace($rule, $index, CandidateTrace::ERROR, $when->errorCode, $when)],
                    $rule,
                    $when->errorCode,
                    $when->nodePath ?? $rule->when->source(),
                    // The SPX detail travels with it: "which budget", "which
                    // symbol", "which argument" is what an operator needs.
                    'The `when` clause produced an error value; §5.8 forbids reading it as false. '
                        . $when->substituted,
                    $started,
                    $expressions,
                    count($candidates),
                );
            }

            if (!$when->isTrue()) {
                $traces[] = $this->candidateTrace(
                    $rule,
                    $index,
                    CandidateTrace::NOT_MATCHED,
                    'condition ' . ($when->isUnknown() ? 'unknown' : 'false') . ': ' . $when->substituted,
                    $when,
                );

                continue;
            }

            // §7.5: `except` is evaluated only when `when` is true.
            $except = null;

            if ($rule->except !== null) {
                $except = $rule->except->evaluate($context, $this->sandbox());
                $expressions++;

                if ($except->isError()) {
                    throw $this->fail(
                        $decisionPoint,
                        $context,
                        $asOf,
                        [...$traces, $this->candidateTrace($rule, $index, CandidateTrace::ERROR, $except->errorCode, $when, $except)],
                        $rule,
                        $except->errorCode,
                        $except->nodePath ?? $rule->except->source(),
                        'The `except` clause produced an error value (§7.5). ' . $except->substituted,
                        $started,
                        $expressions,
                        count($candidates),
                    );
                }

                // An `unknown` except does NOT suppress the match: it is not a
                // carve-out unless it is definitely true. The trace records it.
                if ($except->isTrue()) {
                    $traces[] = $this->candidateTrace(
                        $rule,
                        $index,
                        CandidateTrace::MATCHED_THEN_EXCEPTED,
                        'matched, then excepted: ' . $except->substituted,
                        $when,
                        $except,
                    );

                    continue;
                }
            }

            $traces[] = $this->candidateTrace($rule, $index, CandidateTrace::MATCHED, 'matched', $when, $except);
            $matches[] = ['rule' => $rule, 'rank' => $row['rank']];

            if ($policy->isSingleOutcome()) {
                $winningRank = $row['rank'];

                // first_match is the cheap variant: it stops here and does not
                // look for a tie-peer. highest_priority_only finishes the rank,
                // because two matching rules of equal specificity AND equal
                // priority is the ambiguity spine S3.2 refuses to coin-toss.
                if ($policy === ConflictPolicy::FirstMatch) {
                    $stopReason = 'first_match stopped evaluation at ' . $rule->label()
                        . '; later candidates are not scope-checked (§7.3)';
                }
            } elseif ($rule->stopOnMatch) {
                $stopReason = $rule->label() . ' declared stop_on_match';
            }
        }

        if ($policy === ConflictPolicy::HighestPriorityOnly && count($matches) > 1) {
            $a = $matches[0]['rule'];
            $b = $matches[1]['rule'];

            throw $this->fail(
                $decisionPoint,
                $context,
                $asOf,
                $traces,
                $a,
                'ERR_PRECEDENCE_TIE',
                $a->code . ' / ' . $b->code,
                'Both ' . $a->describe() . ' and ' . $b->describe()
                . ' matched with identical specificity and priority, and spine S3.2 has no third tie-break. '
                . 'This configuration should have been refused at publish time by the conflict detector; '
                . 'ordering by rule code would be exactly the row-order coin-toss SmartPRS 2 does.',
                $started,
                $expressions,
                count($candidates),
            );
        }

        // Rules that never became candidates: out of scope, not in force, not
        // published. Recorded so the trace can answer "why did this NOT happen?".
        foreach ($rows as $row) {
            if ($row['reason'] === null) {
                continue;
            }

            $traces[] = $this->candidateTrace(
                $row['rule'],
                count($traces),
                $row['reason'] === 'not_published' ? CandidateTrace::NOT_PUBLISHED
                    : ($row['reason'] === 'not_in_force' ? CandidateTrace::NOT_IN_FORCE : CandidateTrace::NOT_IN_SCOPE),
                (string) $row['detail'],
            );
        }

        // ---- 5. collect (§7.4) ----------------------------------------------
        $actions = [];
        $sequence = 0;

        foreach ($matches as $match) {
            /** @var Rule $rule */
            $rule = $match['rule'];

            foreach ($rule->then as $action) {
                [$parameters, $error] = $action->resolve($context, $this->sandbox());
                $expressions++;

                if ($error !== null) {
                    throw $this->fail(
                        $decisionPoint,
                        $context,
                        $asOf,
                        $traces,
                        $rule,
                        $error->errorCode,
                        $action->code,
                        'An action parameter produced an error value; a half-built action is never emitted. '
                            . $error->substituted,
                        $started,
                        $expressions,
                        count($candidates),
                    );
                }

                $actions[] = AppliedAction::emit(
                    $decisionPoint,
                    $context,
                    $rule->code,
                    $rule->versionId,
                    $rule->checksum(),
                    $action->code,
                    $parameters,
                    ++$sequence,
                );
            }
        }

        $actions = $this->settleActionConflicts(
            $actions,
            $decisionPoint,
            $context,
            $asOf,
            $traces,
            $started,
            $expressions,
            count($candidates),
            count($matches),
        );

        // ---- 6. seal --------------------------------------------------------
        $status = $matches === [] ? DecisionTrace::NO_MATCH : DecisionTrace::MATCHED;

        return new EvaluationResult(
            $status,
            $actions,
            $this->seal($decisionPoint, $context, $asOf, $status, $traces, $actions, [
                'rules_considered' => count($rows),
                'rules_matched' => count($matches),
                'expressions' => $expressions,
                'agg_calls' => 0,
                'micros' => $this->micros($started),
            ]),
        );
    }

    /**
     * §7.3 / R-VII-037. Two matched rules emitting conflicting actions on the
     * same target is a runtime conflict. The engine does not pick one — it fails,
     * names both rules and both values, and the subject becomes an exception.
     * SmartPRS 2 resolves exactly this situation with `if ($tr > $rank)`.
     *
     * Additive actions accumulate. `GATE_PAYMENT` and `SET_RISK` carry a declared
     * dominance order, which is a property of the ACTION, not of the rules, so
     * `block` beating any number of `warn`s is never an error.
     *
     * @param  list<AppliedAction>  $actions
     * @param  list<CandidateTrace> $traces
     * @return list<AppliedAction>
     */
    private function settleActionConflicts(
        array $actions,
        string $decisionPoint,
        EvaluationContext $context,
        \DateTimeImmutable $asOf,
        array $traces,
        int $started,
        int $expressions,
        int $candidates,
        int $matched,
    ): array {
        if ($matched < 2) {
            return $actions;
        }

        $seen = [];
        $dominant = [];

        foreach ($actions as $action) {
            $class = $action->conflictClass();

            if ($class === ActionCatalogue::ADDITIVE) {
                continue;
            }

            $target = $action->contendedTarget();

            if ($class === ActionCatalogue::DOMINANT) {
                $order = ActionCatalogue::dominance($action->actionCode);
                $rank = array_search(
                    Values::render($action->parameters[$order['parameter']] ?? ''),
                    $order['order'],
                    true,
                );
                $dominant[$target][] = ['action' => $action, 'rank' => $rank === false ? -1 : $rank];

                continue;
            }

            $value = Values::json($action->parameters);

            if (isset($seen[$target]) && $seen[$target]['value'] !== $value) {
                throw $this->fail(
                    $decisionPoint,
                    $context,
                    $asOf,
                    $traces,
                    null,
                    'ERR_ACTION_CONFLICT',
                    $target,
                    sprintf(
                        '%s emitted %s and %s emitted %s on the same target. Both rules matched under all_match; '
                        . 'the engine does not pick one.',
                        $seen[$target]['action']->emittedBy['rule_code'],
                        $seen[$target]['action']->render(),
                        $action->emittedBy['rule_code'],
                        $action->render(),
                    ),
                    $started,
                    $expressions,
                    $candidates,
                );
            }

            $seen[$target] = ['value' => $value, 'action' => $action];
        }

        if ($dominant === []) {
            return $actions;
        }

        // Keep the dominant member of each contended target; drop the rest.
        $dropped = [];

        foreach ($dominant as $group) {
            usort($group, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

            foreach (array_slice($group, 1) as $loser) {
                $dropped[$loser['action']->fingerprint] = true;
            }
        }

        return array_values(array_filter(
            $actions,
            static fn (AppliedAction $a): bool => !isset($dropped[$a->fingerprint]),
        ));
    }

    /**
     * §7.6. Not rules, and not overridable by one.
     *
     * @return array<string, string>|null
     */
    private function guard(EvaluationContext $context): ?array
    {
        if ($context->joiningDate !== null && $context->subjectDate < $context->joiningDate) {
            return [
                'code' => 'GUARD_NOT_YET_JOINED',
                'message' => 'Subject date ' . $context->subjectDate->format('Y-m-d')
                    . ' precedes the joining date ' . $context->joiningDate->format('Y-m-d')
                    . '; no rule runs.',
            ];
        }

        if ($context->exitDate !== null && $context->subjectDate > $context->exitDate) {
            return [
                'code' => 'GUARD_EXITED',
                'message' => 'Subject date ' . $context->subjectDate->format('Y-m-d')
                    . ' follows the exit date ' . $context->exitDate->format('Y-m-d')
                    . '; no rule runs.',
            ];
        }

        return null;
    }

    private function outrankedBy(Rule $loser, Rule $winner): string
    {
        if ($loser->specificity() !== $winner->specificity()) {
            return sprintf(
                'lost on specificity (%d < %d), outranked by %s',
                $loser->specificity(),
                $winner->specificity(),
                $winner->label(),
            );
        }

        return sprintf(
            'lost on priority (%d < %d at specificity %d), outranked by %s',
            $loser->priority,
            $winner->priority,
            $loser->specificity(),
            $winner->label(),
        );
    }

    private function candidateTrace(
        Rule $rule,
        int $index,
        string $outcome,
        string $reason,
        ?Evaluated $when = null,
        ?Evaluated $except = null,
    ): CandidateTrace {
        return new CandidateTrace(
            ruleCode: $rule->code,
            ruleVersionId: $rule->versionId,
            versionNo: $rule->versionNo,
            checksum: $rule->checksum(),
            name: $rule->name,
            businessDescription: $rule->businessDescription,
            scopeSummary: $rule->scopeSummary(),
            specificity: $rule->specificity(),
            priority: $rule->priority,
            orderIndex: $index,
            outcome: $outcome,
            reason: $reason,
            whenSource: $rule->when->source(),
            whenSubstituted: $when?->substituted,
            // $when is genuinely nullable (no `when` clause evaluated). Spelled out
            // rather than `?->` immediately before `??`, which PHPStan flags as
            // unnecessary purely on the shape of the expression.
            whenInputs: $when === null ? [] : $when->inputs,
            exceptSource: $rule->except?->source(),
            exceptSubstituted: $except?->substituted,
            sourceRef: $rule->sourceRef,
            effectiveRange: (string) $rule->range,
        );
    }

    /**
     * @param list<CandidateTrace> $traces
     * @param list<AppliedAction>  $actions
     * @param array<string, int>   $counters
     * @param array<string, string>|null $error
     */
    private function seal(
        string $decisionPoint,
        EvaluationContext $context,
        \DateTimeImmutable $asOf,
        string $status,
        array $traces,
        array $actions,
        array $counters,
        ?array $error = null,
    ): DecisionTrace {
        return new DecisionTrace(
            traceId: DecisionTrace::idFor($context->tenantId, $decisionPoint, $context),
            tenantId: $context->tenantId,
            decisionPoint: $decisionPoint,
            subject: [
                'type' => $context->subjectType,
                'id' => $context->subjectId,
                'date' => $context->subjectDate->format('Y-m-d'),
            ],
            asOf: $asOf,
            engineVersion: $this->engineVersion,
            status: $status,
            inputDigest: $context->digest(),
            candidates: $traces,
            actions: $actions,
            counters: $counters,
            error: $error,
        );
    }

    /** @param list<CandidateTrace> $traces */
    private function fail(
        string $decisionPoint,
        EvaluationContext $context,
        \DateTimeImmutable $asOf,
        array $traces,
        ?Rule $rule,
        string $errorCode,
        string $nodePath,
        string $detail,
        int $started,
        int $expressions,
        int $candidates,
    ): RuleEvaluationFailed {
        $trace = $this->seal(
            $decisionPoint,
            $context,
            $asOf,
            DecisionTrace::FAILED,
            $traces,
            [],
            [
                'rules_considered' => $candidates,
                'rules_matched' => 0,
                'expressions' => $expressions,
                'agg_calls' => 0,
                'micros' => $this->micros($started),
            ],
            [
                'code' => $errorCode,
                // $rule is genuinely nullable (a failure can occur before any rule is
                // reached). Spelled out rather than `?->` before `??`, which PHPStan
                // flags as unnecessary purely on the shape of the expression.
                'rule_code' => $rule === null ? '' : $rule->code,
                'node_path' => $nodePath,
                'message' => $detail,
            ],
        );

        return new RuleEvaluationFailed(
            $errorCode,
            $rule?->code,
            $rule?->versionId,
            $nodePath,
            $trace,
            $detail,
        );
    }

    private function micros(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1000);
    }
}
