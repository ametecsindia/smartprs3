<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Support\Rational;

/**
 * WHAT PUBLISHING THIS RULE WOULD ACTUALLY DO — Part VII §7.9.
 *
 * The blueprint asks for this in six places and it existed in none of them:
 * "the impact preview runs BEFORE publication, on real data"; it "shows the
 * exact employee count and a sample"; it "names every affected employee"; it is
 * "stored with the version and shown to the approver"; it is mandatory for any
 * rule affecting more than a fifth of the tenant or emitting a money action.
 * RC-19 states the property that makes it worth having: **the previewed
 * changed-employee count and the actual differ by zero.**
 *
 * That property is trivially true if the preview is just "run it and see", so
 * the three ways a preview stops being exact are each closed here rather than
 * left to a caller's discipline:
 *
 *   NO SAMPLING. The preview evaluates every subject it was given, and the
 *   report says how many that was. A count extrapolated from a sample is an
 *   ESTIMATE, and an estimate presented to an approver as "1,143 employees
 *   affected" is worse than no number, because it will be believed.
 *
 *   THE EFFECTIVE DATE, NOT TODAY. Both sides are evaluated as at the date the
 *   version WOULD TAKE EFFECT. Previewing a rule effective 1 October against
 *   today's September data answers a question nobody asked, and answers it
 *   reassuringly: nothing changes, because the rule is not in force yet.
 *
 *   AN ERROR IS A CHANGE. A subject whose evaluation fails on either side is
 *   counted as changed and listed separately. Treating it as unchanged is how a
 *   preview reports "12 affected" for a rule that breaks evaluation for four
 *   hundred people — the same fail-open this codebase keeps finding, wearing the
 *   costume of a summary statistic.
 *
 * Pure: the population is passed in, because the engine holds no repository
 * (spine S13). Whoever calls this is responsible for the population being the
 * real one, and `subjectCount` on the report is what makes that auditable.
 */
final class ImpactPreview
{
    /** §7.9 / line 1533: above this share of the tenant, approval is required. */
    public const APPROVAL_SHARE_PERCENT = 20;

    /** How many changed subjects the report carries as an illustrative sample. */
    public const SAMPLE_SIZE = 10;

    /** The same threshold as an exact ratio, because the share it is compared against is one. */
    public static function approvalShare(): Rational
    {
        return Rational::percent(self::APPROVAL_SHARE_PERCENT);
    }

    /**
     * @param  RuleSet|null              $current     what is published now; null when nothing is
     * @param  list<EvaluationContext>   $population  every subject, not a sample
     * @param  \DateTimeImmutable        $effectiveFrom the date the proposed version would take effect
     * @param  string|null               $authorSubjectId the publisher's own employee, for the
     *         self-affecting flag — a rule whose scope includes its author is not forbidden, it is
     *         DISCLOSED (§7.9, the "pay themselves" row of the abuse table)
     */
    public static function between(
        string $decisionPoint,
        ?RuleSet $current,
        RuleSet $proposed,
        array $population,
        \DateTimeImmutable $effectiveFrom,
        ?string $authorSubjectId = null,
    ): ImpactReport {
        if ($population === []) {
            throw new \InvalidArgumentException(
                'An impact preview over an empty population is not a preview of nothing changing — it is a '
                . 'preview that was not run. Pass the real population, or do not claim one (§7.9).'
            );
        }

        $engine = new Engine();
        $changed = [];
        $annotationOnly = [];
        $errored = [];
        $outcomes = [];

        foreach ($population as $context) {
            $before = self::outcome($engine, $decisionPoint, $current, $context, $effectiveFrom);
            $after = self::outcome($engine, $decisionPoint, $proposed, $context, $effectiveFrom);

            // Both sides per subject, kept rather than reduced to a boolean.
            // "37 employees change" is the headline; "and HERE is what changes
            // for each of them" is what an approver actually needs, and a caller
            // that had to recompute it would be a second implementation of the
            // comparison — the one that eventually disagrees with this one.
            $outcomes[$context->subjectId] = [
                'before' => $before['error'] ?? $before['rendered'],
                'after' => $after['error'] ?? $after['rendered'],
            ];

            if ($before['error'] !== null || $after['error'] !== null) {
                $errored[$context->subjectId] = $after['error'] ?? $before['error'];
                $changed[] = $context->subjectId;

                continue;
            }

            if ($before['fingerprint'] !== $after['fingerprint']) {
                $changed[] = $context->subjectId;

                continue;
            }

            if ($before['rendered'] !== $after['rendered']) {
                $annotationOnly[] = $context->subjectId;
            }
        }

        return new ImpactReport(
            decisionPoint: $decisionPoint,
            asOf: $effectiveFrom,
            subjectCount: count($population),
            changedSubjectIds: $changed,
            erroredSubjects: $errored,
            moneyAffecting: self::anyMoneyAction($proposed),
            selfAffecting: $authorSubjectId !== null && in_array($authorSubjectId, $changed, true),
            outcomes: $outcomes,
            annotationOnlySubjectIds: $annotationOnly,
        );
    }

    /**
     * One subject's outcome under one rule set, as a comparable string.
     *
     * The fingerprint is the rendered actions in order, not just their count:
     * a version that changes a penalty from half a day to a full day changes
     * nothing about how many actions fire, and it is exactly the change an
     * approver needs to see.
     *
     * @return array{fingerprint: string, rendered: string, error: ?string}
     */
    private static function outcome(
        Engine $engine,
        string $decisionPoint,
        ?RuleSet $set,
        EvaluationContext $context,
        \DateTimeImmutable $asOf,
    ): array {
        if ($set === null) {
            return ['fingerprint' => '', 'rendered' => '', 'error' => null];
        }

        try {
            $result = $engine->evaluate($decisionPoint, $set, $context, $asOf);
        } catch (RuleEvaluationFailed $e) {
            return [
                'fingerprint' => '',
                'rendered' => '',
                'error' => $e->errorCode === '' ? $e->getMessage() : $e->errorCode,
            ];
        }

        return [
            'fingerprint' => $result->status . ' | ' . implode(' ; ', self::effects($result)),
            'rendered' => $result->status . ' | ' . implode(' ; ', $result->rendered()),
            'error' => null,
        ];
    }

    /**
     * The EFFECT of each applied action: its code and every parameter except
     * the ones that only explain it.
     *
     * `reason` is documentation attached to an action — it belongs in the trace
     * and on the payslip, and it is not what the arithmetic does. Excluding it
     * here is not the preview looking away: a reason change still shows up, as
     * an annotation-only change, with the subject named.
     *
     * @return list<string>
     */
    private static function effects(EvaluationResult $result): array
    {
        return array_map(
            static function (Actions\AppliedAction $action): string {
                $parameters = $action->parameters;
                unset($parameters['reason']);
                ksort($parameters);

                return $action->actionCode . '(' . implode(', ', array_map(
                    static fn (string $name, mixed $value): string => $name . ' = ' . Values::render($value),
                    array_keys($parameters),
                    $parameters,
                )) . ')';
            },
            $result->actions,
        );
    }

    private static function anyMoneyAction(RuleSet $set): bool
    {
        foreach ($set->rules as $rule) {
            foreach ($rule->then as $action) {
                if ($action->isMoneyAffecting()) {
                    return true;
                }
            }
        }

        return false;
    }
}
