<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\AppliedAction;

/**
 * §7.1: the engine returns an ordered AppliedAction list PLUS a DecisionTrace.
 *
 * R-VII-036: a result without a trace is a programming error. There is no code
 * path here that returns actions with a null trace — the constructor requires
 * one, so the law is a type signature rather than a convention.
 */
final readonly class EvaluationResult
{
    /**
     * @param list<AppliedAction> $actions
     */
    public function __construct(
        public string $status,
        public array $actions,
        public DecisionTrace $trace,
    ) {
    }

    public function matched(): bool
    {
        return $this->status === DecisionTrace::MATCHED;
    }

    public function failed(): bool
    {
        return $this->status === DecisionTrace::FAILED;
    }

    /** @return array<string, int> rules_considered, rules_matched, expressions, agg_calls, micros */
    public function counters(): array
    {
        return $this->trace->counters;
    }

    /** @return list<AppliedAction> */
    public function actionsOfCode(string $actionCode): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (AppliedAction $a): bool => $a->actionCode === $actionCode,
        ));
    }

    /** @return list<string> "APPLY_DAY_PENALTY(fraction = 1/2, reason = …)" */
    public function rendered(): array
    {
        return array_map(static fn (AppliedAction $a): string => $a->render(), $this->actions);
    }
}
