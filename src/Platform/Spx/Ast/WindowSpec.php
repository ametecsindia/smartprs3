<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** An aggregation window (Part VII §5.9): `payroll_period`, `rolling(30, days)`. */
final readonly class WindowSpec extends Node
{
    /** @param list<Node> $args */
    public function __construct(
        public string $window,
        public array $args,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'window';
    }

    public function children(): array
    {
        return $this->args;
    }

    public function fields(): array
    {
        return ['window' => $this->window];
    }
}
