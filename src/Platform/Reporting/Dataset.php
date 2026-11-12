<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Reporting;

/**
 * A DECLARED DATASET — Part XXXII Phase 8.
 *
 * The exit criterion is that a CUSTOMER can create a report and add a column
 * with no code. That is only possible if the set of columns a report may reach
 * is itself data: a dataset declares its columns, their types and their labels,
 * and the report builder can offer nothing else.
 *
 * The alternative — a report that takes arbitrary SQL, or a column list a
 * developer extends — is how SmartPRS 2's reports work, which is why adding a
 * column to one is a release, and why two reports over the same figures disagree
 * about what the figures mean.
 *
 * A dataset also declares which columns carry PII, because a report definition
 * that a customer can extend is a report definition that can be extended into an
 * export of everybody's identifiers.
 */
final readonly class Dataset
{
    /** @param array<string, array{type: string, label: string, pii?: bool}> $columns */
    public function __construct(
        public string $code,
        public string $title,
        public array $columns,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException(
                "Dataset '{$code}' declares no columns, so every report over it is empty and reports success."
            );
        }
    }

    public function has(string $column): bool
    {
        return isset($this->columns[$column]);
    }

    public function isPii(string $column): bool
    {
        return (bool) ($this->columns[$column]['pii'] ?? false);
    }

    /** @return list<string> */
    public function columnCodes(): array
    {
        return array_keys($this->columns);
    }

    public function label(string $column): string
    {
        return $this->columns[$column]['label'] ?? $column;
    }
}
