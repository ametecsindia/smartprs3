<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * WHAT THE WRITE DID, FIELD BY FIELD — Part X §X.5.1, R-X-038.
 *
 * The import preview is this same structure, which is the point: a preview that
 * is a different code path from the commit is a preview of something else, and
 * the difference only shows up on the row that matters.
 */
final readonly class WriteResult
{
    /** @param list<FieldOutcome> $outcomes one per field in the command, without exception */
    public function __construct(
        public bool $applied,
        public array $outcomes,
        public ?string $entityUuid = null,
        public ?int $rowVersion = null,
        public string $summary = '',
    ) {
    }

    public function outcomeFor(string $fieldKey): ?FieldOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->fieldKey === $fieldKey) {
                return $outcome;
            }
        }

        return null;
    }

    /** @return list<FieldOutcome> */
    public function rejected(): array
    {
        return array_values(array_filter($this->outcomes, static fn (FieldOutcome $o): bool => $o->isRejected()));
    }

    /** @return list<FieldOutcome> */
    public function changed(): array
    {
        return array_values(array_filter($this->outcomes, static fn (FieldOutcome $o): bool => $o->changedSomething()));
    }

    /** @return list<FieldOutcome> */
    public function routed(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (FieldOutcome $o): bool => $o->status === FieldOutcome::ROUTED,
        ));
    }

    public function render(): string
    {
        $lines = [$this->applied ? 'Applied.' : 'NOT applied.'];

        foreach ($this->outcomes as $outcome) {
            $lines[] = '  ' . $outcome->describe();
        }

        if ($this->summary !== '') {
            $lines[] = $this->summary;
        }

        return implode("\n", $lines);
    }
}
