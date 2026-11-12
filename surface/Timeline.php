<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Lifecycle\EmploymentTimeline;

/**
 * WHEN SOMEBODY JOINED, WAS CONFIRMED, MOVED AND LEFT.
 *
 * `employees` carries `doj`, `status` and `employment_stage`, and NO EXIT DATE.
 * The product knew somebody had left and did not know when — which is precisely
 * the shape `Platform\Lifecycle\EmploymentTimeline` was written against, four
 * finished and tested classes that nothing on any screen had ever called. Its
 * own docblock names the consequence: SmartPRS 2 "issues a payslip for somebody
 * who left in March because leaving is a flag rather than an event with a date".
 *
 * THE ORDERING RULES ARE THE PLATFORM'S, NOT THIS CLASS'S. An employee begins
 * by joining; nothing follows an exit; nobody resigns twice; a promotion, a
 * transfer, a resignation and an exit each carry a reason, because a change to
 * somebody's employee that nobody has to justify is a change nobody can
 * question. Every one of those is enforced by rebuilding the real
 * `EmploymentTimeline` from the stored rows and asking IT to accept the new
 * event — so the rules are tested where they live, and a second set of them
 * here would be the second implementation that gets it wrong.
 *
 * ROWS ARE THE RECORD; THE OBJECT IS A DECISION PROCEDURE. It is rebuilt on
 * every call rather than cached, because a cached timeline is a second answer to
 * "what was true in August" that drifts from the table the moment anybody writes
 * to it directly.
 */
final class Timeline
{
    private const COLLECTION = 'employment_events';

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * Record an event, or refuse with the platform's own reason.
     *
     * @param array<string, string> $detail
     */
    public function record(string $employmentId, string $kind, string $effectiveOn,
        string $reason = '', array $detail = [], string $by = ''): void
    {
        // REBUILT AND ASKED, not checked here. `EmploymentTimeline::record()`
        // holds every ordering rule and refuses with a sentence worth reading;
        // duplicating the checks in this class would be a second implementation
        // of the one thing that decides whether somebody can be paid.
        $timeline = $this->timelineFor($employmentId);
        $timeline->record($kind, new \DateTimeImmutable($effectiveOn), $detail, $reason);

        $this->store->put(self::COLLECTION, [
            'employment_id' => $employmentId,
            'kind' => $kind,
            'effective_on' => $effectiveOn,
            'reason' => $reason,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
            'recorded_by' => $by,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The stored events, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $employmentId): array
    {
        $rows = array_values(array_filter(
            $this->store->all(self::COLLECTION),
            static fn (array $r): bool => (string) ($r['employment_id'] ?? '') === $employmentId,
        ));

        // BY THE DATE IT HAPPENED, then by the order it was recorded. Two events
        // on one day is normal — resigned and exited on the same date when
        // somebody leaves without notice — and sorting on the effective date
        // alone would put them in whichever order the store returned.
        usort($rows, static function (array $a, array $b): int {
            $byDate = strcmp((string) ($a['effective_on'] ?? ''), (string) ($b['effective_on'] ?? ''));

            return $byDate !== 0
                ? $byDate
                : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        });

        return $rows;
    }

    /**
     * Was this employee active on a date?
     *
     * NULL when the timeline says nothing — which is NOT the same as "no". Most
     * employees in a ported database have no events at all yet, and answering
     * "not employed" for them would stop every payslip in the tenant. The caller
     * decides what to do with a timeline that has not been filled in; what it
     * must not do is confuse silence with an answer.
     */
    public function activeOn(string $employmentId, string $date): ?bool
    {
        if ($this->events($employmentId) === []) {
            return null;
        }

        return $this->timelineFor($employmentId)->isActiveOn(new \DateTimeImmutable($date));
    }

    /** The exit date, or null if this employee has not exited. */
    public function exitedOn(string $employmentId): ?string
    {
        foreach ($this->events($employmentId) as $event) {
            if ((string) ($event['kind'] ?? '') === EmploymentTimeline::EXITED) {
                return (string) $event['effective_on'];
            }
        }

        return null;
    }

    /**
     * What a detail field was ON A DATE — the question a column cannot answer.
     *
     * "What was their grade in August?" A column answers with today's grade. This
     * answers the question that was asked.
     */
    public function valueOn(string $employmentId, string $field, string $asOf): ?string
    {
        return $this->timelineFor($employmentId)->valueOn($field, new \DateTimeImmutable($asOf));
    }

    /** The kinds this build records, for a form to offer. */
    public function kinds(): array
    {
        return [
            EmploymentTimeline::JOINED => 'Joined',
            EmploymentTimeline::PROBATION_STARTED => 'Probation started',
            EmploymentTimeline::CONFIRMED => 'Confirmed',
            EmploymentTimeline::INCREMENT => 'Increment',
            EmploymentTimeline::PROMOTED => 'Promoted',
            EmploymentTimeline::TRANSFERRED => 'Transferred',
            EmploymentTimeline::RESIGNED => 'Resigned',
            EmploymentTimeline::EXITED => 'Exited',
        ];
    }

    private function timelineFor(string $employmentId): EmploymentTimeline
    {
        $timeline = new EmploymentTimeline($employmentId);

        foreach ($this->events($employmentId) as $row) {
            $detail = json_decode((string) ($row['detail'] ?? '{}'), true);

            $timeline->record(
                (string) $row['kind'],
                new \DateTimeImmutable((string) $row['effective_on']),
                // A nested detail (the transfer's from/to blocks) flattens to JSON rather than "Array".
                is_array($detail) ? array_map(static fn ($v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $detail) : [],
                (string) ($row['reason'] ?? ''),
            );
        }

        return $timeline;
    }
}
