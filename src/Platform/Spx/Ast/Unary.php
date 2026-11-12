<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** Precedence level 2 (`-`) and level 7 (`NOT`), both right-associative. */
final readonly class Unary extends Node
{
    public function __construct(
        public string $operator,
        public Node $operand,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'unary';
    }

    public function children(): array
    {
        return [$this->operand];
    }

    public function fields(): array
    {
        return ['operator' => $this->operator];
    }
}
