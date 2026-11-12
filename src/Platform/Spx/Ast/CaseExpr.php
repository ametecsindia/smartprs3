<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * `CASE WHEN … THEN … ELSE … END`.
 *
 * R-VII-011: the ELSE is MANDATORY and the parser enforces it, because
 * PayrollGenController::tierCut():482-495 silently charges a sub-L1 late at the
 * L1 rate through a missing else-branch — a business rule nobody agreed to.
 */
final readonly class CaseExpr extends Node
{
    /** @param list<array{0: Node, 1: Node}> $whens */
    public function __construct(
        public array $whens,
        public Node $else,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'case';
    }

    public function children(): array
    {
        $children = [];

        foreach ($this->whens as [$condition, $result]) {
            $children[] = $condition;
            $children[] = $result;
        }

        $children[] = $this->else;

        return $children;
    }

    public function fields(): array
    {
        return ['branches' => count($this->whens)];
    }
}
