<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Reporting;

/**
 * A REPORT A CUSTOMER BUILT — Part XXXII Phase 8.
 *
 * *"A customer can create a report and add a column with no code."* The whole
 * class exists to make that sentence a runnable assertion rather than a claim,
 * so `withColumn()` is the operation the exit gate calls: a definition plus a
 * column code, in, a new definition out, and no file on disk changed.
 *
 * Three refusals, each of which is a thing a report builder gets wrong once and
 * then ships for years:
 *
 *   A COLUMN THAT IS NOT IN THE DATASET IS REFUSED, not rendered blank. A blank
 *   column in a report reads as "no data" — a fact about the business — when it
 *   actually means somebody mistyped a field name.
 *
 *   A PII COLUMN NEEDS AN EXPLICIT ACKNOWLEDGEMENT. A report a customer can
 *   extend is a report that can be extended into an export of everybody's
 *   identifiers, and the person doing it in the Studio at 4pm is not thinking
 *   about the data-protection consequence of one more tick box.
 *
 *   A REGULATORY REPORT MAY CARRY NO LITERAL. Part XXI R-XXI-056: no assertion on
 *   a regulatory output may originate from a literal, a default or a count no
 *   query produced. SmartPRS 2's RBI pack has `score => 92` on a customer-branded
 *   document, and it got there through a report definition.
 */
final readonly class ReportDefinition
{
    /**
     * @param list<string> $columns
     * @param array<string, string> $literals static values printed on the report
     */
    public function __construct(
        public string $code,
        public string $title,
        public Dataset $dataset,
        public array $columns = [],
        public bool $regulatory = false,
        public array $literals = [],
        public array $piiAcknowledged = [],
    ) {
        foreach ($columns as $column) {
            if (!$dataset->has($column)) {
                throw new \InvalidArgumentException(
                    "Report '{$code}' selects column '{$column}', which dataset '{$dataset->code}' does not "
                    . 'declare. A column that does not exist renders blank, and a blank column reads as a '
                    . 'fact about the business rather than as a typo.'
                );
            }

            if ($dataset->isPii($column) && !in_array($column, $piiAcknowledged, true)) {
                throw new \InvalidArgumentException(
                    "Report '{$code}' includes '{$column}', which the dataset marks as personal data, "
                    . 'without an explicit acknowledgement. A report a customer can extend is a report that '
                    . 'can be extended into an export of everybody\'s identifiers.'
                );
            }
        }

        if ($regulatory && $literals !== []) {
            throw new \InvalidArgumentException(
                "Regulatory report '{$code}' carries the literal value(s) " . implode(', ', array_keys($literals))
                . '. No assertion on a regulatory output may originate from a literal, a default or a count '
                . 'no query produced — that is exactly how a hard-coded score reaches a customer-branded '
                . 'document.'
            );
        }
    }

    /** The operation the exit criterion names: add a column, with no code. */
    public function withColumn(string $column, bool $acknowledgePii = false): self
    {
        return new self(
            $this->code,
            $this->title,
            $this->dataset,
            [...$this->columns, $column],
            $this->regulatory,
            $this->literals,
            $acknowledgePii ? [...$this->piiAcknowledged, $column] : $this->piiAcknowledged,
        );
    }

    public function withoutColumn(string $column): self
    {
        return new self(
            $this->code,
            $this->title,
            $this->dataset,
            array_values(array_filter($this->columns, static fn (string $c): bool => $c !== $column)),
            $this->regulatory,
            $this->literals,
            $this->piiAcknowledged,
        );
    }

    /** @param list<array<string, mixed>> $rows */
    public function render(array $rows): string
    {
        $out = [implode(' | ', array_map($this->dataset->label(...), $this->columns))];

        foreach ($rows as $row) {
            $out[] = implode(' | ', array_map(
                static fn (string $c): string => (string) ($row[$c] ?? ''),
                $this->columns,
            ));
        }

        return implode("\n", $out);
    }
}
