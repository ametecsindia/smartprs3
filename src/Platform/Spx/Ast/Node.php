<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Ast;

/**
 * The base of the closed AST node set (Part VII §10.1a-3: "evaluation walks a
 * typed AST of a closed node set with a switch over node kinds").
 *
 * Immutable. Every node carries the position of the token that opened it, so a
 * type error, a budget breach and a trace entry can all name a place in the
 * author's source rather than a node index.
 *
 * The subclass list here IS the language. Adding a node kind is a language
 * change: the evaluator's match() is exhaustive over these and rejects anything
 * else (spx_safety_test asserts it).
 */
abstract readonly class Node
{
    public function __construct(
        public int $line,
        public int $column,
    ) {
    }

    /** Stable discriminator for serialisation. Never change one of these. */
    abstract public function kind(): string;

    /** @return list<Node> */
    abstract public function children(): array;

    /** Node's own fields, without kind/line/column. @return array<string, mixed> */
    abstract public function fields(): array;

    /** Total node count including this one — the publish-time size check. */
    final public function size(): int
    {
        $size = 1;

        foreach ($this->children() as $child) {
            $size += $child->size();
        }

        return $size;
    }

    /** Longest root-to-leaf path. Part VII §7.7: capped at 32 at publish time. */
    final public function depth(): int
    {
        $deepest = 0;

        foreach ($this->children() as $child) {
            $deepest = max($deepest, $child->depth());
        }

        return $deepest + 1;
    }

    final public function position(): string
    {
        return "line {$this->line}, column {$this->column}";
    }
}
