<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Organisation;

use SmartPRS\Platform\Persistence\EffectiveDatedRepository;
use SmartPRS\Platform\Support\EffectiveRange;

/**
 * V.6.3 — "what was this employee's branch on 15 May?", which SmartPRS 2 cannot
 * answer at all.
 *
 * One call, one row, one join. The row is exactly one because of the V.6.4
 * invariants: not "usually one", and never "the latest by id".
 */
final class AssignmentHistory extends EffectiveDatedRepository
{
    protected function table(): string
    {
        return 'employment_assignments';
    }

    /**
     * The assignment in force on a date, or NULL before the first one and
     * inside a gap. NULL is an answer — "not employed here then" — and is not
     * the same as "take the nearest row".
     *
     * @return array<string, mixed>|null
     */
    public function inForceOn(string $employmentId, \DateTimeImmutable $date): ?array
    {
        return $this->asOf($date)->query()->where('employment_id', '=', $employmentId)->first();
    }

    /** The value of one dimension on a date; the reason this class exists. */
    public function dimensionOn(string $employmentId, string $column, \DateTimeImmutable $date): ?string
    {
        $row = $this->inForceOn($employmentId, $date);

        if ($row === null) {
            return null;
        }

        if (!array_key_exists($column, $row)) {
            throw new \InvalidArgumentException(
                "`{$column}` is not a dimension of employment_assignments. The scopeable set is "
                . 'spine S3.1; a new dimension is a migration, never a JSON bag.'
            );
        }

        return $row[$column] === null ? null : (string) $row[$column];
    }

    /**
     * The whole timeline, oldest first — the audit view.
     *
     * This is the one read on this class that is deliberately NOT as-at, which
     * is why it goes through allVersions() and says so: an audit view that
     * silently showed one version would be a lie, and a date-filtered read that
     * happened to return everything would be a bug.
     *
     * @return list<array<string, mixed>>
     */
    public function historyOf(string $employmentId): array
    {
        return $this->allVersions()
            ->where('employment_id', '=', $employmentId)
            ->orderBy('effective_from')
            ->select();
    }

    /**
     * V.6.4 mechanism 4, as a query rather than a hope: does the row set tile
     * the timeline with no gap and no overlap? Returns the first breach it
     * finds, or NULL. The write service asserts on this inside its transaction
     * and the nightly integrity job runs it across every employment.
     */
    public function contiguityBreach(string $employmentId): ?string
    {
        $previous = null;

        foreach ($this->historyOf($employmentId) as $row) {
            $range = EffectiveRange::fromInclusive((string) $row['effective_from'], $row['effective_to'] === null
                ? null
                : (string) $row['effective_to']);

            if ($previous !== null) {
                if ($previous->overlaps($range)) {
                    return "assignment {$row['id']} overlaps its predecessor ({$previous} vs {$range})";
                }

                if (!$previous->abuts($range)) {
                    return "assignment {$row['id']} leaves a gap after its predecessor ({$previous} then {$range})";
                }
            }

            $previous = $range;
        }

        return null;
    }
}
