<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** `{ event.value > 10 }` — a Boolean over the event scope only (§5.9.3). */
final readonly class PredicateBlock extends Node
{
    public function __construct(
        public Node $expression,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'predicate';
    }

    public function children(): array
    {
        return [$this->expression];
    }

    public function fields(): array
    {
        return [];
    }
}
