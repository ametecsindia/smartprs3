<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * What stages 1 to 6 produced, handed to stages 7 to 10 — Part X §X.5.2.
 *
 * The boundary exists so that persistence cannot skip a stage. A `$persist`
 * callable receives values that are already authorised, resolved against the
 * surface, coerced into their storage types, validated and sanitised; there is
 * no path by which it receives a raw request body. R-X-040 asks for a mutation
 * test asserting that bypassing any stage causes a test to fail, and a boundary
 * that only carries prepared values is the structural half of that.
 */
final readonly class PreparedWrite
{
    /**
     * @param array<string, mixed> $values  field key => storage value; null means CLEAR
     * @param array<string, array{workflow: string, value: mixed, old: mixed}> $routed
     *        held for approval (R-X-039), written to the change-request table rather than the entity
     * @param list<FieldOutcome> $outcomes
     */
    public function __construct(
        public WriteCommand $command,
        public array $values,
        public array $routed,
        public array $outcomes,
    ) {
    }

    /** @return list<string> the fields whose history mode makes them dated rows (X.6.1) */
    public function datedFields(array $definitions): array
    {
        return array_values(array_filter(
            array_keys($this->values),
            static fn (string $key): bool => ($definitions[$key] ?? null)?->isDated() === true,
        ));
    }
}
