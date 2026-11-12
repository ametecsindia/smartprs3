<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Functions;

use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Types\Type;

/**
 * One row of the closed function catalogue (Part VII §5.6, R-VII-017).
 *
 * The result type of most SPX functions depends on the argument types (MIN over
 * Money is Money; over Days is Days) and several depend on the argument *nodes*
 * — ROUND's mode must be a literal from a fixed set, MATCHES' pattern must be a
 * literal at all. So a signature carries a resolver rather than a fixed return
 * type: it returns the result Type, or a message explaining the type error.
 */
final readonly class Signature
{
    /** @param \Closure(list<Type>, list<Node>): (Type|string) $resolve */
    public function __construct(
        public string $name,
        public int $minArity,
        public ?int $maxArity,
        public \Closure $resolve,
        public string $summary,
    ) {
    }

    public function arityMessage(int $given): ?string
    {
        if ($given < $this->minArity || ($this->maxArity !== null && $given > $this->maxArity)) {
            $expected = $this->maxArity === null
                ? "at least {$this->minArity}"
                : ($this->maxArity === $this->minArity ? (string) $this->minArity : "{$this->minArity} to {$this->maxArity}");

            return "{$this->name}() takes {$expected} argument(s), {$given} given — {$this->summary}";
        }

        return null;
    }
}
