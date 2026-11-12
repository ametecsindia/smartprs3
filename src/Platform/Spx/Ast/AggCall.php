<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * `agg.count(late_arrival, payroll_period, { event.value > 10 })`.
 *
 * The only construct that reads beyond the current subject (Part VII §5.9) —
 * and it takes no subject argument, so it cannot read beyond THIS subject. The
 * evaluator resolves it out of the prepared context by canonical key and never
 * touches a database; nesting is a publish-time error (R-VII-025).
 */
final readonly class AggCall extends Node
{
    /** @param list<Node> $args */
    public function __construct(
        public string $function,
        public array $args,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'agg';
    }

    public function children(): array
    {
        return $this->args;
    }

    public function fields(): array
    {
        return ['function' => $this->function];
    }
}
