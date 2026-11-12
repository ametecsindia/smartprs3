<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * One publish-time problem, with the position in the author's source.
 *
 * Type checking reports EVERY issue, not the first: an author fixing a rule
 * one error per publish attempt is an author who stops using the type system
 * and asks for a code change instead.
 */
final readonly class TypeIssue
{
    public function __construct(
        public string $message,
        public int $line,
        public int $column,
    ) {
    }

    public function __toString(): string
    {
        return sprintf('%s (line %d, column %d)', $this->message, $this->line, $this->column);
    }
}
