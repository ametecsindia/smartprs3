<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** `x BETWEEN a AND b` — inclusive on both bounds, and non-associative. */
final readonly class Between extends Node
{
    public function __construct(
        public Node $value,
        public Node $low,
        public Node $high,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'between';
    }

    public function children(): array
    {
        return [$this->value, $this->low, $this->high];
    }

    public function fields(): array
    {
        return [];
    }
}
