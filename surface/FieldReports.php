<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * FIELD FORCE expiry bands and the REPORTS & ANALYTICS list reports.
 *
 * Every method is a pure function over arrays the screen already read through
 * the tenant-scoped store, so the computations are testable without a database
 * and a screen is only a loop that prints. Rules (the expiry window, the report
 * list) come from config/field-reports.json; nothing here knows a number.
 *
 * MONEY IS EXACT: a salary register cell is a Money, summed in paise; a leave
 * day count is a DECIMAL(18,4) summed the same way and printed as its exact
 * string. Counts are ints. A cell is never a float.
 */
final class FieldReports
{
    public const EXPIRED = 'expired';
    public const DUE = 'due';
    public const VALID = 'valid';
    public const NONE = 'none';

    public static function config(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($decoded) || !is_array($decoded['reports'] ?? null)) {
            throw new \RuntimeException("The field-force configuration at {$file} is missing or unreadable; "
                . 'the screen refuses rather than computing against nothing.');
        }

        return $decoded;
    }

    public static function find(array $cfg, string $key): ?array
    {
        foreach ((array) $cfg['reports'] as $report) {
            if ((string) ($report['key'] ?? '') === $key) {
                return $report;
            }
        }

        return null;
    }

    // ---- expiry -------------------------------------------------------------------------

    /** Whole days from today to the date; negative when it has passed; null when there is no date. */
    public static function daysLeft(?string $date, string $today): ?int
    {
        $date = trim((string) $date);

        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            return null;
        }

        $diff = (new \DateTimeImmutable(substr($today, 0, 10)))->diff(new \DateTimeImmutable(substr($date, 0, 10)));

        return $diff->invert === 1 ? -$diff->days : $diff->days;
    }

    /**
     * expired: the date is today or earlier. due: within the window. valid: later.
     * none: no usable date. A certificate that expires today is not valid today.
     */
    public static function band(?string $date, string $today, int $windowDays): string
    {
        $left = self::daysLeft($date, $today);

        return match (true) {
            $left === null => self::NONE,
            $left <= 0 => self::EXPIRED,
            $left <= $windowDays => self::DUE,
            default => self::VALID,
        };
    }

    /**
     * Expiring-within-the-window first (soonest at the top), then already
     * expired (most recently first), then valid (soonest first), then undated.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> each row with 'band' and 'days_left' added
     */
    public static function expiringFirst(array $rows, string $dateKey, string $today, int $windowDays): array
    {
        $rank = [self::DUE => 0, self::EXPIRED => 1, self::VALID => 2, self::NONE => 3];

        foreach ($rows as &$row) {
            $row['days_left'] = self::daysLeft((string) ($row[$dateKey] ?? ''), $today);
            $row['band'] = self::band((string) ($row[$dateKey] ?? ''), $today, $windowDays);
        }

        unset($row);

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $byBand = $rank[$a['band']] <=> $rank[$b['band']];

            if ($byBand !== 0) {
                return $byBand;
            }

            $l = (int) ($a['days_left'] ?? 0);
            $r = (int) ($b['days_left'] ?? 0);

            // Expired: the most recent lapse first (closest to zero).
            return $a['band'] === self::EXPIRED ? $r <=> $l : $l <=> $r;
        });

        return $rows;
    }

    /** @return array<string, int> band => count, every band present even at zero */
    public static function bandCounts(array $rows): array
    {
        $counts = [self::EXPIRED => 0, self::DUE => 0, self::VALID => 0, self::NONE => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['band']]++;
        }

        return $counts;
    }

    // ---- the list reports -----------------------------------------------------------------
    // Each returns ['columns' => list<string>, 'rows' => list<list<int|string|Money>>,
    // 'totals' => list<int|string|Money>|null]. A Money cell stays Money so the
    // screen formats it and the CSV writes its exact decimal.

    /**
     * @param list<array<string, mixed>> $people
     * @param callable(array, string): string $valueOf the org-unit NAME an employee holds for the group
     */
    public static function headcount(array $people, string $group, callable $valueOf): array
    {
        $counts = [];

        foreach ($people as $e) {
            $name = trim($valueOf($e, $group));
            $counts[$name === '' ? '(not set)' : $name] = ($counts[$name === '' ? '(not set)' : $name] ?? 0) + 1;
        }

        arsort($counts);
        $rows = [];

        foreach ($counts as $name => $n) {
            $rows[] = [(string) $name, $n];
        }

        return ['columns' => [ucfirst($group), 'Headcount'], 'rows' => $rows, 'totals' => ['Total', count($people)]];
    }

    /**
     * Days per status for the month, plus exact hours, per employee. The status
     * columns are whatever the month's rows hold — a status list typed here
     * would be a second description of the attendance rules.
     *
     * @param list<array<string, mixed>> $people
     * @param list<array<string, mixed>> $attendance rows of `attendance` (employee_id, date, status, hours)
     */
    public static function attendanceSummary(array $people, array $attendance, string $month): array
    {
        $byEmployee = [];
        $statuses = [];

        foreach ($attendance as $a) {
            if (substr((string) ($a['date'] ?? ''), 0, 7) !== $month) {
                continue;
            }

            $status = trim((string) ($a['status'] ?? '')) ?: '(no status)';
            $statuses[$status] = true;
            $id = (string) ($a['employee_id'] ?? '');
            $byEmployee[$id]['status'][$status] = ($byEmployee[$id]['status'][$status] ?? 0) + 1;
            $byEmployee[$id]['hours'] = ($byEmployee[$id]['hours'] ?? Money::zero())
                ->plus(Amount::quantity($a['hours'] ?? ''));
        }

        $statuses = array_keys($statuses);
        sort($statuses);
        $rows = [];
        $totals = array_fill(0, count($statuses), 0);
        $hours = Money::zero();

        foreach ($people as $e) {
            $own = $byEmployee[(string) ($e['id'] ?? '')] ?? ['status' => [], 'hours' => Money::zero()];
            $row = [Employment::code($e), (string) ($e['name'] ?? '')];

            foreach ($statuses as $i => $s) {
                $row[] = $own['status'][$s] ?? 0;
                $totals[$i] += $own['status'][$s] ?? 0;
            }

            $row[] = $own['hours']->toDecimalString();
            $hours = $hours->plus($own['hours']);
            $rows[] = $row;
        }

        return [
            'columns' => array_merge(['Code', 'Name'], array_map(ucfirst(...), $statuses), ['Hours']),
            'rows' => $rows,
            'totals' => array_merge(['Total', count($people) . ' employee(s)'], $totals, [$hours->toDecimalString()]),
        ];
    }

    /**
     * Approved leave days by type for the month (by the leave's start date), per employee.
     *
     * @param list<array<string, mixed>> $people
     * @param list<array<string, mixed>> $leaves rows of `leaves`
     * @param array<string, string> $typeNames leave type id => name
     */
    public static function leaveSummary(array $people, array $leaves, array $typeNames, string $period, string $approvedStatus): array
    {
        // $period is a month (YYYY-MM) or a date range (YYYY-MM-DD..YYYY-MM-DD): a request counts when it starts inside it.
        [$from, $to] = str_contains($period, '..') ? explode('..', $period, 2) : [$period . '-01', $period . '-31'];
        $byEmployee = [];
        $types = [];

        foreach ($leaves as $l) {
            $starts = substr((string) ($l['from_date'] ?? ''), 0, 10);

            if ($starts < $from || $starts > $to
                || strcasecmp((string) ($l['status'] ?? ''), $approvedStatus) !== 0) {
                continue;
            }

            $type = $typeNames[(string) ($l['type_id'] ?? '')] ?? ('type ' . (string) ($l['type_id'] ?? '?'));
            $types[$type] = true;
            $id = (string) ($l['employee_id'] ?? '');
            $byEmployee[$id][$type] = ($byEmployee[$id][$type] ?? Money::zero())
                ->plus(Amount::quantity($l['days'] ?? ''));
        }

        $types = array_keys($types);
        sort($types);
        $rows = [];
        $totals = array_fill(0, count($types), Money::zero());
        $all = Money::zero();

        foreach ($people as $e) {
            $own = $byEmployee[(string) ($e['id'] ?? '')] ?? [];
            $row = [Employment::code($e), (string) ($e['name'] ?? '')];
            $sum = Money::zero();

            foreach ($types as $i => $t) {
                $days = $own[$t] ?? Money::zero();
                $row[] = $days->toDecimalString();
                $totals[$i] = $totals[$i]->plus($days);
                $sum = $sum->plus($days);
            }

            $row[] = $sum->toDecimalString();
            $all = $all->plus($sum);
            $rows[] = $row;
        }

        return [
            'columns' => array_merge(['Code', 'Name'], $types, ['Days']),
            'rows' => $rows,
            'totals' => array_merge(['Total', count($people) . ' employee(s)'],
                array_map(static fn (Money $m): string => $m->toDecimalString(), $totals), [$all->toDecimalString()]),
        ];
    }

    /**
     * Gross, deductions and net per employee from the CLOSED run of the month —
     * summed from the run's own lines in paise, so the register is the payslips
     * and not a second computation. An employee the run has no row for is listed
     * with dashes, never as zero pay.
     *
     * @param list<array<string, mixed>> $people
     * @param list<array<string, mixed>> $runs Register::runs()
     */
    public static function salaryRegister(array $people, array $runs, string $month, array $earningKinds, array $deductionKinds): array
    {
        $run = null;

        foreach ($runs as $candidate) {
            if ((string) ($candidate['period'] ?? '') === $month) {
                $run = $candidate;
                break;
            }
        }

        $rows = [];
        $totals = ['gross' => Money::zero(), 'deductions' => Money::zero(), 'net' => Money::zero()];
        $inRun = 0;

        foreach ($people as $e) {
            $code = Employment::code($e);
            $own = $run === null ? null : ((array) ($run['rows'] ?? []))[$code] ?? null;

            if ($own === null) {
                $rows[] = [$code, (string) ($e['name'] ?? ''), '—', '—', '—'];
                continue;
            }

            $gross = Money::zero();
            $ded = Money::zero();

            foreach ((array) ($own['lines'] ?? []) as $line) {
                $kind = (string) ($line['kind'] ?? '');
                $amount = Money::ofMinor((int) ($line['minor'] ?? 0));

                if (in_array($kind, $earningKinds, true)) {
                    $gross = $gross->plus($amount);
                } elseif (in_array($kind, $deductionKinds, true)) {
                    $ded = $ded->plus($amount);
                }
            }

            $net = Money::ofMinor((int) ($own['net_minor'] ?? ($gross->minus($ded)->minor)));
            $totals['gross'] = $totals['gross']->plus($gross);
            $totals['deductions'] = $totals['deductions']->plus($ded);
            $totals['net'] = $totals['net']->plus($net);
            $inRun++;
            $rows[] = [$code, (string) ($e['name'] ?? ''), $gross, $ded, $net];
        }

        return [
            'columns' => ['Code', 'Name', 'Gross', 'Deductions', 'Net'],
            'rows' => $rows,
            'totals' => ['Total', $inRun . ' of ' . count($people) . ' in the run', $totals['gross'], $totals['deductions'], $totals['net']],
            'run' => $run,
        ];
    }

    /** @param list<array<string, mixed>> $people */
    public static function pfEsiSummary(array $people, array $yesValues): array
    {
        $rows = [];
        $pf = 0;
        $esi = 0;
        $yes = static fn (mixed $v): bool => in_array(strtolower(trim((string) $v)), $yesValues, true);

        foreach ($people as $e) {
            $inPf = $yes($e['pf_applicable'] ?? '');
            $inEsi = $yes($e['esi_applicable'] ?? '');
            $pf += $inPf ? 1 : 0;
            $esi += $inEsi ? 1 : 0;
            $rows[] = [Employment::code($e), (string) ($e['name'] ?? ''), $inPf ? 'yes' : 'no',
                (string) ($e['uan'] ?? ''), $inEsi ? 'yes' : 'no', (string) ($e['esic_no'] ?? '')];
        }

        return [
            'columns' => ['Code', 'Name', 'PF Enrolled', 'UAN', 'ESI Enrolled', 'ESI Number'],
            'rows' => $rows,
            'totals' => ['Total', count($people) . ' employee(s)', $pf, '', $esi, ''],
        ];
    }

    /** Whose birthday falls in the month, day order; "turns" is the age reached that day. @param list<array<string, mixed>> $people */
    public static function birthdays(array $people, string $month): array
    {
        $rows = [];

        foreach ($people as $e) {
            $dob = (string) ($e['dob'] ?? '');

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dob, $m) !== 1 || $m[2] !== substr($month, 5, 2)) {
                continue;
            }

            $rows[] = [(int) $m[3], Employment::code($e), (string) ($e['name'] ?? ''), substr($dob, 0, 10),
                (int) substr($month, 0, 4) - (int) $m[1]];
        }

        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return ['columns' => ['Day', 'Code', 'Name', 'Date of birth', 'Turns'], 'rows' => $rows,
            'totals' => ['Total', count($rows) . ' birthday(s)', '', '', '']];
    }

    // ---- output ---------------------------------------------------------------------------

    /** A cell for the CSV: Money as its exact decimal, everything else as text. */
    public static function csv(array $columns, array $rows, ?array $totals = null): string
    {
        $out = fopen('php://temp', 'r+');
        $plain = static fn (mixed $c): string => $c instanceof Money ? $c->toDecimalString() : (string) $c;
        fputcsv($out, $columns, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($out, array_map($plain, $row), ',', '"', '\\');
        }

        if ($totals !== null) {
            fputcsv($out, array_map($plain, $totals), ',', '"', '\\');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
