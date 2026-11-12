<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHICH WORKING SHIFT AN EMPLOYMENT IS ROSTERED ON, ON A DATE.
 *
 * SmartPRS 2's Roster: one row per employee per day naming a shift, overriding
 * the employee's default. Here a row covers a DATE RANGE and the most recent row
 * covering the date wins (newest `created_at`, then the higher id), so a one-day
 * override is a one-day row saved after the range it sits inside. Rows are never
 * rewritten: a cancellation is the cancelled status from config/roster.json.
 *
 * No roster row: the scoped shift from Working Shifts (`Shifts::for`), exactly
 * what applied before rosters existed.
 */
final class Roster
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var list<array<string, mixed>>|null live roster rows */
    private ?array $rows = null;

    /** @var array<string, array<string, mixed>>|null shift id => shifts row (retired included: a past roster may name one) */
    private ?array $shifts = null;

    public function __construct(
        private readonly RowStore $store,
        string $configFile,
        private readonly ?Shifts $default = null,
    ) {
        $decoded = json_decode((string) file_get_contents($configFile), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("{$configFile} is not readable as JSON; the roster refuses to guess its vocabulary.");
        }

        $this->config = $decoded;
    }

    /** @return array<string, mixed> */
    public function declared(): array
    {
        return $this->config;
    }

    /** The roster row in force for the employee on the date, or null. @return array<string, mixed>|null */
    public function rowFor(array $employment, string $date): ?array
    {
        $id = (string) ($employment['id'] ?? '');
        $best = null;

        foreach ($this->rows() as $row) {
            if ((string) ($row['employee_id'] ?? '') !== $id
                || (string) ($row['from_date'] ?? '') > $date || (string) ($row['to_date'] ?? '') < $date) {
                continue;
            }

            if ($best === null || self::newer($row, $best)) {
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * The shift for the employee on the date: the roster's if a row covers it,
     * else the scoped Working Shift, else null. The row carries `roster_id` and
     * `roster_note` when it came from the roster so a screen can say so.
     *
     * @return array<string, mixed>|null
     */
    public function shiftFor(array $employment, string $date): ?array
    {
        $row = $this->rowFor($employment, $date);

        if ($row !== null) {
            $shift = $this->shifts()[(string) ($row['shift_id'] ?? '')] ?? null;

            if ($shift !== null) {
                return $shift + ['roster_id' => (string) $row['id'], 'roster_note' => (string) ($row['note'] ?? '')];
            }
        }

        return $this->default?->for($employment);
    }

    /**
     * Assign one shift to many employees for a range. One row per employee,
     * validated once; nothing is written if anything is wrong.
     *
     * @param list<string> $employeeIds
     * @return array{ok: bool, why: string, rows: list<array<string, mixed>>}
     */
    public function assign(array $employeeIds, string $shiftId, string $from, string $to, string $note, string $by, string $now): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('strval', $employeeIds))));

        if ($employeeIds === []) {
            return ['ok' => false, 'why' => 'Nobody to roster: pick an employee or a scope that has some.', 'rows' => []];
        }

        if (!isset($this->shifts()[$shiftId]) || Modules::isRetired($this->shifts()[$shiftId])) {
            return ['ok' => false, 'why' => 'Pick a live Working Shift (Organization Setup → Working Shifts).', 'rows' => []];
        }

        foreach ([$from, $to] as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1 || !checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                return ['ok' => false, 'why' => "'{$d}' is not a date (YYYY-MM-DD).", 'rows' => []];
            }
        }

        if ($to < $from) {
            return ['ok' => false, 'why' => 'The range ends before it starts.', 'rows' => []];
        }

        $max = (int) ($this->config['max_range_days'] ?? 0);
        $span = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;

        if ($max > 0 && $span > $max) {
            return ['ok' => false, 'why' => "That is {$span} days; a roster range is at most {$max} (config/roster.json max_range_days).", 'rows' => []];
        }

        $written = [];

        foreach ($employeeIds as $employeeId) {
            $written[] = $this->store->put((string) $this->config['table'], [
                'employee_id' => $employeeId, 'shift_id' => $shiftId, 'from_date' => $from, 'to_date' => $to,
                'note' => $note === '' ? null : $note, 'created_by' => $by,
                'status' => (string) $this->config['active_status'], 'created_at' => $now,
            ]);
        }

        $this->rows = null;

        return ['ok' => true, 'why' => count($written) . ' employee(s) put on ' . (string) ($this->shifts()[$shiftId]['name'] ?? $shiftId)
            . " from {$from} to {$to}.", 'rows' => $written];
    }

    /** @return array{ok: bool, why: string} */
    public function cancel(string $rosterId, string $by, string $now): array
    {
        $row = $this->store->find((string) $this->config['table'], $rosterId);

        if ($row === null || Modules::isRetired($row)) {
            return ['ok' => false, 'why' => 'No live roster row with that id.'];
        }

        $this->store->put((string) $this->config['table'], ['id' => $row['id'],
            'status' => (string) $this->config['cancelled_status'], 'updated_at' => $now,
            'note' => trim((string) ($row['note'] ?? '') . " [cancelled by {$by} {$now}]")]);
        $this->rows = null;

        return ['ok' => true, 'why' => 'Roster row cancelled. The scoped Working Shift applies again for those days.'];
    }

    /**
     * The grid over an arbitrary list of dates: employee id => date (Y-m-d) =>
     * ['code' => shift code, 'rostered' => bool]. Keyed by the date itself, not
     * a day-of-month number, so a range that crosses a month boundary (or isn't
     * a whole month at all) is unambiguous.
     *
     * @param list<array<string, mixed>> $employments
     * @param list<string> $dates Y-m-d, in the order to show them
     * @return array<string, array<string, array{code: string, rostered: bool}>>
     */
    public function grid(array $employments, array $dates): array
    {
        $grid = [];

        foreach ($employments as $e) {
            $line = [];

            foreach ($dates as $date) {
                $shift = $this->shiftFor($e, $date);
                $line[$date] = [
                    'code' => $shift === null ? (string) ($this->config['grid_empty_mark'] ?? '') : (string) (($shift['code'] ?? '') !== '' ? $shift['code'] : $shift['name']),
                    'rostered' => $shift !== null && isset($shift['roster_id']),
                ];
            }

            $grid[(string) $e['id']] = $line;
        }

        return $grid;
    }

    /** The Y-m-d dates of a calendar month, in order. @return list<string> */
    public static function monthDates(string $month): array
    {
        $days = (int) date('t', (int) strtotime($month . '-01'));

        return array_map(static fn (int $d): string => sprintf('%s-%02d', $month, $d), range(1, $days));
    }

    /**
     * The Y-m-d dates of an inclusive range, capped so a screen never has to
     * render (or a mistyped year never makes it) thousands of columns.
     *
     * @return list<string>
     */
    public static function rangeDates(string $from, string $to, int $maxDays): array
    {
        $dates = [];

        for ($t = strtotime($from); $t !== false && $t <= strtotime($to) && count($dates) < $maxDays; $t += 86400) {
            $dates[] = date('Y-m-d', $t);
        }

        return $dates;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        if ($this->rows === null) {
            try {
                $this->rows = array_values(array_filter($this->store->all((string) $this->config['table']), static fn (array $r): bool => !Modules::isRetired($r)));
            } catch (\Throwable) {
                $this->rows = []; // no rosters table on this installation — nobody is rostered
            }
        }

        return $this->rows;
    }

    /** @return array<string, array<string, mixed>> */
    public function shifts(): array
    {
        if ($this->shifts === null) {
            $this->shifts = [];

            try {
                foreach ($this->store->all('shifts') as $s) {
                    $this->shifts[(string) $s['id']] = $s;
                }
            } catch (\Throwable) { // gate:allow-swallow no shifts table on this installation — a roster can name nothing
            }
        }

        return $this->shifts;
    }

    private static function newer(array $a, array $b): bool
    {
        $byTime = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));

        return $byTime > 0 || ($byTime === 0 && strnatcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')) > 0);
    }
}
