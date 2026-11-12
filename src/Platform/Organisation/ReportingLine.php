<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Organisation;

use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Persistence\EffectiveDatedRepository;

/**
 * R-XXII-043 — who reported to whom, on a date.
 *
 * The reporting manager is a dated column on `employment_assignments`, so this
 * is a walk over the assignment rows in force on the given day, never over a
 * current-state column. That is the whole difference between SmartPRS 2, where a
 * re-opened May approval routes to today's manager, and a system that can answer
 * "who approved this, and who should have?".
 *
 * BREADTH-FIRST, ONE QUERY PER LEVEL, AT MOST `depth` OF THEM. A recursive CTE
 * would be one query, but it either loops on a cycle or silently drops the
 * repeated edge, and R-XXII-044 requires the third behaviour: stop and deny.
 * Twelve indexed `IN (…)` queries against a manager index is not a performance
 * problem, and it is a problem we can see.
 */
final class ReportingLine extends EffectiveDatedRepository
{
    protected function table(): string
    {
        return 'employment_assignments';
    }

    /**
     * The employments reporting directly to this manager on this date.
     *
     * @return list<string> employment ids
     */
    public function directReportsOf(string $managerId, \DateTimeImmutable $asOf): array
    {
        return $this->reportsTo([$managerId], $asOf);
    }

    /**
     * Everything below this manager, to `depth` levels, on this date. The
     * manager itself is never in the result.
     *
     * @param  ?int  $depth  NULL takes the platform maximum. R-XXII-044: an
     *         unbounded scope is still a bounded walk.
     * @return list<string> employment ids, nearest level first
     * @throws ReportingCycleDetected when the walk revisits an employment
     */
    public function reportingTreeOf(string $managerId, ?int $depth, \DateTimeImmutable $asOf): array
    {
        $depth ??= DataScope::MAX_REPORTING_DEPTH;

        if ($depth < 1 || $depth > DataScope::MAX_REPORTING_DEPTH) {
            throw new \InvalidArgumentException(
                "Reporting depth {$depth} is outside 1.." . DataScope::MAX_REPORTING_DEPTH
                . ' (access.reporting_tree.max_depth, R-XXII-044).'
            );
        }

        $seen = [$managerId => true];
        $level = [$managerId];
        $tree = [];

        for ($step = 0; $step < $depth && $level !== []; $step++) {
            $next = [];

            foreach ($this->reportsTo($level, $asOf) as $report) {
                if (isset($seen[$report])) {
                    // The manager is somebody's report, directly or through the
                    // chain. Deny; do not truncate.
                    throw new ReportingCycleDetected(
                        'access.reporting_cycle_detected: the reporting line below employment '
                        . $managerId . ' revisits employment ' . $report . ' at level '
                        . ($step + 1) . ' as at ' . $asOf->format('Y-m-d') . '.'
                    );
                }

                $seen[$report] = true;
                $next[] = $report;
            }

            $tree = array_merge($tree, $next);
            $level = $next;
        }

        return $tree;
    }

    /**
     * One level. Tenancy and the as-at predicate are both structural here — the
     * query cannot be emitted without either.
     *
     * @param  list<string>  $managerIds
     * @return list<string>
     */
    private function reportsTo(array $managerIds, \DateTimeImmutable $asOf): array
    {
        if ($managerIds === []) {
            return [];
        }

        $rows = $this->asOf($asOf)
            ->query()
            ->whereIn('reporting_manager_employment_id', $managerIds)
            ->orderBy('employment_id')
            ->select(['employment_id']);

        // Deduplicated on the way out: two rows in force for one employment is a
        // contiguity breach, not a cycle, and must not be reported as one.
        return array_values(array_unique(
            array_map(static fn (array $row): string => (string) $row['employment_id'], $rows)
        ));
    }
}
