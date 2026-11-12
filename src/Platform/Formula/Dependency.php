<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

/**
 * One edge out of a formula (Part VIII §7.1).
 *
 * R-VIII-012 and R-VIII-033: a dependency set is DERIVED from the compiled AST,
 * never authored. A hand-maintained list is a second source of truth, and Part
 * VIII §2 is a catalogue of what second sources of truth cost.
 *
 * A reference the compiler cannot resolve statically is not expressible at all:
 * `salary.component` takes a string literal key and nothing else (Part VII §5.3
 * `key_arg ::= string_lit`). That is why the graph is complete and why cycle
 * detection is sound rather than best-effort.
 */
final readonly class Dependency
{
    /** §7.1's node kinds. `settlement`, `ledger` and `policy` are sources. */
    public const KINDS = ['component', 'formula', 'statutory', 'settlement', 'ledger', 'policy', 'input'];

    /** Kinds with no outgoing edges: the graph terminates at them. */
    public const SOURCES = ['settlement', 'ledger', 'policy', 'input'];

    public function __construct(
        public string $kind,
        public string $code,
        public ?string $resolvedVersionId = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("'{$kind}' is not a dependency kind (§7.1).");
        }

        if ($code === '') {
            throw new \InvalidArgumentException('A dependency names a code.');
        }
    }

    public function isSource(): bool
    {
        return in_array($this->kind, self::SOURCES, true);
    }

    /** The graph node identity. */
    public function node(): string
    {
        return $this->kind . ':' . $this->code;
    }

    public function withResolvedVersion(string $versionId): self
    {
        return new self($this->kind, $this->code, $versionId);
    }

    public function __toString(): string
    {
        return $this->node() . ($this->resolvedVersionId === null ? '' : ' @' . $this->resolvedVersionId);
    }
}
