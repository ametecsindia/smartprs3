<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/** `['HYD','BLR']`. Homogeneous and <= 500 elements, both checked at publish. */
final readonly class ListLiteral extends Node
{
    /** @param list<Node> $elements */
    public function __construct(
        public array $elements,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'list';
    }

    public function children(): array
    {
        return $this->elements;
    }

    public function fields(): array
    {
        return [];
    }
}
