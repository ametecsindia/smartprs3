<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHICH WORKING SHIFT AN EMPLOYMENT IS ON.
 *
 * Organization Setup → Working Shifts rows are scoped by id — company, branch,
 * department, designation, team, employee; blank = ALL. The narrowest row that
 * covers the employee wins (employee > team > designation > department >
 * branch > company > everyone); ties go to the older row. No row: the
 * calendar's default shift, exactly as before shifts had scopes.
 */
final class Shifts
{
    private const SCOPES = ['employee_id' => 'id', 'team_id' => 'team_id', 'designation_id' => 'designation_id',
        'department_id' => 'department_id', 'branch_id' => 'branch_id', 'employer_company_id' => 'employer_company_id'];

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;
    private ?Roster $roster = null;
    /** @var array<string, array<string, array{start: string, end: string}>>|null shift id => ISO weekday => times */
    private ?array $week = null;

    /** The Customisation key holding the day-wise timings (Organization Setup → Shifts, 13 Oct). */
    public const WEEK_KEY = 'shift-week';

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * The shift for an employee — on a DATE when one is given, so a roster row
     * (Time & Attendance → Roster) covering that day wins over the scoped shift.
     */
    public function for(array $employment, ?string $date = null): ?array
    {
        $shift = $this->resolve($employment, $date);

        return $shift === null || $date === null ? $shift : $this->onDay($shift, $date);
    }

    /**
     * THE SHIFT AS IT RUNS ON THAT WEEKDAY (owner, 13 Oct): "every Saturday the
     * office is 09:30–16:00". A day with its own times on the Shifts screen
     * replaces start/end for that date only; a blank day keeps the shift's own.
     */
    public function onDay(array $shift, string $date): array
    {
        $day = $this->week()[(string) ($shift['id'] ?? '')][(string) date('N', (int) strtotime($date))] ?? null;

        if ($day !== null && ($day['start'] ?? '') !== '' && ($day['end'] ?? '') !== '') {
            $shift['start_time'] = $day['start'];
            $shift['end_time'] = $day['end'];
        }

        return $shift;
    }

    /** @return array<string, array<string, array{start: string, end: string}>> */
    public function week(): array
    {
        // ponytail: one read per request for every Shifts object (screens build several);
        // keyed by store object so a test's second tenant store reads its own.
        static $byStore = [];
        $key = spl_object_id($this->store);

        return $this->week ??= $byStore[$key] ??= (array) ((new Customisation($this->store))->live()[self::WEEK_KEY]['shifts'] ?? []);
    }

    private function resolve(array $employment, ?string $date): ?array
    {
        if ($date !== null) {
            $this->roster ??= new Roster($this->store, dirname(__DIR__) . '/config/roster.json');
            $rostered = $this->roster->rowFor($employment, $date);

            if ($rostered !== null) {
                $shift = $this->store->find('shifts', (string) $rostered['shift_id']);

                if ($shift !== null && !Modules::isRetired($shift)) {
                    return $shift;
                }
            }
        }

        if ($this->rows === null) {
            try {
                $this->rows = array_values(array_filter($this->store->all('shifts'), static fn (array $r): bool => !Modules::isRetired($r)));
            } catch (\Throwable) {
                $this->rows = []; // no shifts table on this installation — everybody is on the default shift
            }
        }

        $best = null;
        $bestRank = -1;

        foreach ($this->rows as $row) {
            $rank = 0;
            $i = count(self::SCOPES);

            foreach (self::SCOPES as $column => $employmentColumn) {
                $want = (string) ($row[$column] ?? '');

                if ($want !== '') {
                    if ($want !== (string) ($employment[$employmentColumn] ?? '')) {
                        continue 2;
                    }

                    $rank = max($rank, $i);
                }

                $i--;
            }

            if ($rank > $bestRank || ($rank === $bestRank && (int) $row['id'] < (int) $best['id'])) {
                $best = $row;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /** @return array{0: string, 1: string} start, end — the shift's when one applies, else the defaults given */
    public function times(array $employment, string $defaultStart, string $defaultEnd, ?string $date = null): array
    {
        $shift = $this->for($employment, $date);

        if ($shift === null || (string) ($shift['start_time'] ?? '') === '' || (string) ($shift['end_time'] ?? '') === '') {
            return [$defaultStart, $defaultEnd];
        }

        return [substr((string) $shift['start_time'], 0, 5), substr((string) $shift['end_time'], 0, 5)];
    }
}
