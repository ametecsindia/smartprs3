<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;

/**
 * The outcome of checking an AST against a decision point's symbol table.
 *
 * `type` is the expression's result type; `issues` is every type error found,
 * each with its position. A rule may be published only when `isValid()` and,
 * for a `when` or `except` clause, `isBoolean()`.
 */
final readonly class TypeCheckResult
{
    /** @param list<TypeIssue> $issues */
    public function __construct(
        public Type $type,
        public array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return $this->issues === [];
    }

    public function isBoolean(): bool
    {
        return $this->isValid() && $this->type->is(TypeKind::Boolean);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(strval(...), $this->issues);
    }

    public function __toString(): string
    {
        return $this->isValid()
            ? 'ok: ' . $this->type
            : implode("\n", $this->messages());
    }
}
