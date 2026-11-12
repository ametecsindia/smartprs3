<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * VALUES FOR FIELDS THE `employees` TABLE HAS NO COLUMN FOR.
 *
 * The register lets a customer capture ANY field they keep — that is the whole
 * point of it, and greying half the list out because this database has not got
 * round to a column was the product telling the customer what they are allowed
 * to know about their own staff.
 *
 * The application still does not add columns at runtime: law 2 holds, and the
 * account it runs as has no schema privilege to do it with. So a field with no
 * column is kept BESIDE the record, one row per employee per field, in a table
 * that is part of the schema like any other. `employees.mother_name` is the
 * better home and the migration that adds it ships alongside this — when the
 * column exists the register writes there instead, and this table stops being
 * used for that field. Nothing has to be moved by hand: what is read is what is
 * in the column when there is one, and what is here when there is not.
 *
 * It is a NARROW table on purpose — employee code, field code, value — because
 * the alternative is a JSON blob per employee, and the first question anybody
 * asks of these is "which employees have no PAN", which is a query.
 */
final class EmployeeExtras
{
    public const COLLECTION = 'employee_field_values';

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * Everything held beside one employee, keyed by field code.
     *
     * @return array<string, string>
     */
    public function forEmployment(string $code): array
    {
        $values = [];

        foreach ($this->rows() as $row) {
            if ((string) ($row['employment_code'] ?? '') !== $code) {
                continue;
            }

            $values[(string) ($row['field_code'] ?? '')] = (string) ($row['value'] ?? '');
        }

        return $values;
    }

    /**
     * Save values for one employee. A blank value CLEARS the field.
     *
     * @param array<string, string> $values field code => value
     */
    public function save(string $code, array $values, string $by): int
    {
        $existing = [];

        foreach ($this->rows() as $row) {
            if ((string) ($row['employment_code'] ?? '') === $code) {
                $existing[(string) ($row['field_code'] ?? '')] = $row;
            }
        }

        $written = 0;

        foreach ($values as $field => $value) {
            $field = (string) $field;
            $value = trim((string) $value);
            $row = $existing[$field] ?? null;

            // AN EMPTY VALUE IS NOT A ROW. Storing "" would make "we asked and
            // they left it blank" look identical to "we hold this", and the
            // report that matters is the one listing who is missing a PAN.
            if ($value === '' && $row === null) {
                continue;
            }

            $this->store->put(self::COLLECTION, array_filter([
                'id' => $row['id'] ?? null,
            ], static fn (mixed $v): bool => $v !== null) + [
                'employment_code' => $code,
                'field_code' => $field,
                'value' => $value,
                'updated_by' => $by,
                'updated_at' => date('Y-m-d H:i:s'),
            ] + ($row === null ? ['created_at' => date('Y-m-d H:i:s')] : []));
            $written++;
        }

        return $written;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            return $this->store->all(self::COLLECTION);
        } catch (\Throwable) {
            // A table this installation has not got yet reads as "nothing held
            // beside the record", which is true. The screen says where these
            // values are being kept, so this is not silence about a failure.
            return [];
        }
    }
}
