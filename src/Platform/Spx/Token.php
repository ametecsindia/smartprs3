<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

/**
 * One token, with the position that a parse error will name (Part VII §5.2).
 *
 * `text` is the normalised lexeme: a keyword is upper-cased (keywords are
 * case-insensitive), an identifier is verbatim (identifiers are case-sensitive),
 * a string literal has its quotes removed and its '' escapes collapsed, and a
 * money literal has its grouping commas stripped.
 */
final readonly class Token
{
    public function __construct(
        public TokenKind $kind,
        public string $text,
        public int $line,
        public int $column,
    ) {
    }

    public function is(TokenKind $kind, ?string $text = null): bool
    {
        return $this->kind === $kind && ($text === null || $this->text === $text);
    }

    public function __toString(): string
    {
        return $this->kind === TokenKind::End ? 'end of expression' : "'{$this->text}'";
    }
}
