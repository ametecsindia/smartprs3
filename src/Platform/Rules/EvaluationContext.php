<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * The PreparedContext of Part VII §7.1: everything one evaluation may read,
 * assembled by the caller before the engine is entered, tenant-scoped.
 *
 * Readonly, so "the engine never mutates state" (R-VII-028) is a property of the
 * type rather than a promise in a comment. The engine holds no reference to a
 * repository, so there is nothing else it *could* mutate.
 *
 * `dimensions` are the subject's org and classification values AS AT the
 * resolution date (§7.2 step 2) — resolved from the effective-dated assignment
 * rows by the caller, never from a current-valued column.
 *
 * `evaluationDate` is the only clock (§5.7). There is no NOW() below this line.
 */
final readonly class EvaluationContext
{
    /**
     * @param array<string, string|int|null> $dimensions scope dimension => value as at asOf
     * @param array<string, mixed>           $values     SPX symbol => value
     */
    public function __construct(
        public string $tenantId,
        public string $subjectType,
        public string $subjectId,
        public \DateTimeImmutable $subjectDate,
        public array $dimensions = [],
        public array $values = [],
        public ?\DateTimeImmutable $joiningDate = null,
        public ?\DateTimeImmutable $exitDate = null,
    ) {
    }

    public function value(string $symbol): mixed
    {
        return $this->values[$symbol] ?? null;
    }

    public function has(string $symbol): bool
    {
        return array_key_exists($symbol, $this->values);
    }

    /** The §9.2 `input_digest`: what makes a trace reproducible (R-VII-046). */
    public function digest(): string
    {
        return Values::digest([
            'tenant' => $this->tenantId,
            'subject' => [$this->subjectType, $this->subjectId, $this->subjectDate->format('Y-m-d')],
            'dimensions' => $this->dimensions,
            'values' => $this->values,
        ]);
    }

    /** For the simulator's date range and for stored test cases. */
    public function onDate(\DateTimeImmutable $date): self
    {
        return new self(
            $this->tenantId,
            $this->subjectType,
            $this->subjectId,
            $date,
            $this->dimensions,
            $this->values,
            $this->joiningDate,
            $this->exitDate,
        );
    }

    /** @param array<string, mixed> $values */
    public function withValues(array $values): self
    {
        return new self(
            $this->tenantId,
            $this->subjectType,
            $this->subjectId,
            $this->subjectDate,
            $this->dimensions,
            [...$this->values, ...$values],
            $this->joiningDate,
            $this->exitDate,
        );
    }

    public function subjectLabel(): string
    {
        return $this->subjectType . ':' . $this->subjectId . ' on ' . $this->subjectDate->format('Y-m-d');
    }
}
