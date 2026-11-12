<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * Every two-operand operator: arithmetic, `%` (percent-of, NOT modulo — modulo
 * is MOD(a,b)), the relational set, `IN`/`NOT IN`, `CONTAINS`/`NOT CONTAINS`,
 * `MATCHES`, `AND` and `OR`.
 */
final readonly class Binary extends Node
{
    public const ARITHMETIC = ['+', '-', '*', '/', '%'];
    public const RELATIONAL = ['=', '<>', '<', '<=', '>', '>='];
    public const MEMBERSHIP = ['IN', 'NOT IN', 'CONTAINS', 'NOT CONTAINS', 'MATCHES'];
    public const LOGICAL = ['AND', 'OR'];

    public function __construct(
        public string $operator,
        public Node $left,
        public Node $right,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'binary';
    }

    public function children(): array
    {
        return [$this->left, $this->right];
    }

    public function fields(): array
    {
        return ['operator' => $this->operator];
    }
}
