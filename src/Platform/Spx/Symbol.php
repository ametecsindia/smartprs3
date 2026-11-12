<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Types\Type;

/**
 * One declared symbol in a symbol table (Part VII §5.7).
 *
 * `params` is null for a plain path (`employee.code`) and a list of parameter
 * types for a keyed accessor (`salary.component('BASIC')`). The distinction is
 * checked at publish: `salary.component` without a key does not resolve, and
 * `employee.code('X')` does not either.
 *
 * `keyedTypes` narrows the result type per key, which is how a custom field or
 * a policy value gets its declared type: `policy.value('late_grace_min')` is
 * Minutes while `policy.value('basic_pct_of_gross')` is a Percentage. An
 * undeclared key against a symbol that declares any is a publish-time error
 * (Part VII §5.7, `employee.custom`).
 */
final readonly class Symbol
{
    /**
     * @param list<Type>|null $params
     * @param array<string, Type> $keyedTypes
     */
    public function __construct(
        public Type $type,
        public ?array $params = null,
        public bool $notNullRequired = false,
        public array $keyedTypes = [],
    ) {
    }

    public function requiringNotNull(): self
    {
        return new self($this->type, $this->params, true, $this->keyedTypes);
    }

    /** @param array<string, Type> $types */
    public function withKeyedTypes(array $types): self
    {
        return new self($this->type, $this->params, $this->notNullRequired, $this->keyedTypes + $types);
    }

    public function resultFor(?string $key): Type
    {
        if ($key === null || $this->keyedTypes === []) {
            return $this->type;
        }

        return $this->keyedTypes[$key] ?? $this->type;
    }
}
