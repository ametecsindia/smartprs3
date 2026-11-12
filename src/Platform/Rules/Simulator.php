<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * The simulator of Part VII §11.3, and R-VII-059.
 *
 * It runs THE REAL ENGINE on THE REAL PATH. There is no simulation mode, no dry
 * run flag and no side effects to suppress, because §6.1 already guarantees the
 * engine has none — it returns declarative actions and the domain service is
 * what applies them. That is the whole reason the action catalogue is
 * declarative, and it is why this class is thirty lines instead of a parallel
 * implementation that drifts.
 *
 * It also answers the inverse question — "why did this NOT happen?" — which
 * §11.3 records as the majority of the support load on any rules product. The
 * answer is already in the trace: every candidate with the reason it lost.
 */
final readonly class Simulator
{
    public function __construct(private Engine $engine = new Engine())
    {
    }

    /**
     * Draft and testing rules are included: an unpublished rule must be
     * evaluable against production data WITHOUT being published (R-VII-059).
     */
    public function run(
        string $decisionPoint,
        RuleSet $set,
        EvaluationContext $context,
        ?\DateTimeImmutable $asOf = null,
        bool $includeDrafts = false,
    ): EvaluationResult {
        $set = $includeDrafts ? $set->includingDrafts() : $set->published();

        try {
            return $this->engine->evaluate($decisionPoint, $set, $context, $asOf);
        } catch (RuleEvaluationFailed $failure) {
            // The simulator's job is to SHOW the failure, not to survive it. The
            // trace comes back with status = failed and the failing node named;
            // nothing is defaulted and nothing is swallowed (R-VII-042).
            return new EvaluationResult(DecisionTrace::FAILED, [], $failure->trace);
        }
    }

    /**
     * The day-by-day view — "3 lates in a month → half day" made visible as it
     * accumulates (§11.3). The caller supplies the context for each date because
     * building it is a query, and the engine does not query.
     *
     * @param  callable(\DateTimeImmutable): EvaluationContext  $contextForDate
     * @return array<string, EvaluationResult>  date (Y-m-d) => result
     */
    public function overRange(
        string $decisionPoint,
        RuleSet $set,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        callable $contextForDate,
        bool $includeDrafts = false,
    ): array {
        $results = [];

        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $results[$date->format('Y-m-d')] = $this->run(
                $decisionPoint,
                $set,
                $contextForDate($date),
                $date,
                $includeDrafts,
            );
        }

        return $results;
    }

    /**
     * The cohort view: one scope expression, an outcome distribution.
     *
     * @param  list<EvaluationContext>  $cohort
     * @return array<string, int>  rendered action => employee count, plus
     *                             '(no match)' and '(failed)'
     */
    public function distribution(
        string $decisionPoint,
        RuleSet $set,
        array $cohort,
        ?\DateTimeImmutable $asOf = null,
        bool $includeDrafts = false,
    ): array {
        $counts = [];

        foreach ($cohort as $context) {
            $result = $this->run($decisionPoint, $set, $context, $asOf, $includeDrafts);

            $keys = match (true) {
                $result->failed() => ['(failed)'],
                $result->actions === [] => ['(no match)'],
                default => $result->rendered(),
            };

            foreach ($keys as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }
}
