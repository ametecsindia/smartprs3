<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * A call to a function in the closed catalogue (Part VII §5.6). The name is
 * upper-cased at parse time because function names are case-insensitive; an
 * unknown name is a publish-time error with no dynamic fallback, which is the
 * structural half of "SPX cannot execute arbitrary code" (§10.1a-2).
 */
final readonly class FunctionCall extends Node
{
    /** @param list<Node> $args */
    public function __construct(
        public string $name,
        public array $args,
        int $line,
        int $column,
    ) {
        parent::__construct($line, $column);
    }

    public function kind(): string
    {
        return 'call';
    }

    public function children(): array
    {
        return $this->args;
    }

    public function fields(): array
    {
        return ['name' => $this->name];
    }
}
