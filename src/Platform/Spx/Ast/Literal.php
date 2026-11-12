<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * A literal. `text` is the exact decimal/lexeme as written — never a float, so
 * `0.1` survives a parse/serialise round trip as the string "0.1" and becomes an
 * exact Rational at evaluation, not 0.1000000000000000055511151231257827.
 *
 * `unit` is set only for a duration literal: 'd', 'h', 'm' or 's'.
 */
final readonly class Literal extends Node
{
    public function __construct(
        public LiteralKind $literal,
        public string $text,
        public ?string $unit,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'literal';
    }

    public function children(): array
    {
        return [];
    }

    public function fields(): array
    {
        return ['literal' => $this->literal->value, 'text' => $this->text, 'unit' => $this->unit];
    }
}
