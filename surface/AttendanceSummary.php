<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * THE ATTENDANCE SUMMARY — the muster folded to one row per employee or per
 * Organization Setup level, in the columns the viewer ticked. Pure functions
 * over AttendanceReport::muster() so the screen, the chart, the CSV and the
 * workbook cannot disagree. config/attendance-summary.json declares the columns.
 */
final class AttendanceSummary
{
    private array $config;

    public function __construct(string $configFile)
    {
        $c = json_decode((string) file_get_contents($configFile), true);

        if (!is_array($c) || !is_array($c['columns'] ?? null)) {
            throw new \RuntimeException("config/attendance-summary.json is missing or unreadable at {$configFile}.");
        }

        $this->config = $c;
    }

    public function config(): array
    {
        return $this->config;
    }

    /** @return array<string, array{key: string, label: string, kind: string, status?: string}> key => column */
    public function columns(): array
    {
        $out = [];

        foreach ((array) $this->config['columns'] as $c) {
            $out[(string) $c['key']] = $c;
        }

        return $out;
    }

    /** The ticked columns, in declared order; nothing ticked means the defaults. @param list<string> $wanted @return list<string> */
    public function chosen(array $wanted): array
    {
        $keys = array_keys($this->columns());
        $picked = array_values(array_intersect($keys, array_map('strval', $wanted)));

        return $picked === [] ? array_values(array_intersect($keys, (array) $this->config['default_columns'])) : $picked;
    }

    /**
     * Fold the muster. $groupOf maps a muster row to its group label (the
     * employee's own line when grouping by employee).
     *
     * @param array{dates: list<string>, rows: list<array<string, mixed>>} $muster
     * @param list<string> $chosen column keys
     * @param callable(array): string $groupOf
     * @return array{columns: list<string>, rows: list<list<string|int>>, totals: list<string|int>, raw: array<string, array<string, int>>}
     */
    public function fold(array $muster, array $chosen, string $groupBy, callable $groupOf, string $today): array
    {
        $columns = $this->columns();
        $groups = [];   // label => [status => n, measure => n, people => n]

        foreach ($muster['rows'] as $row) {
            $label = $groupBy === 'employee' ? $row['code'] . ' · ' . $row['name'] : ($groupOf($row) ?: '(not set)');
            $g = &$groups[$label];
            $g ??= ['people' => 0];
            $g['people']++;
            // Days in the period THIS employee was on the books for: not the future, not before joining.
            $g['days'] = ($g['days'] ?? 0) + count(array_filter((array) $row['cells'], static fn (array $c): bool => !in_array($c['status'], [AttendanceReport::NOT_YET, AttendanceReport::NOT_JOINED], true)));
            $t = $row['totals'];

            foreach ((array) $t['by_status'] as $status => $n) {
                $g['status:' . $status] = ($g['status:' . $status] ?? 0) + (int) $n;
            }

            foreach (['worked_minutes', 'late_minutes', 'overtime_minutes', 'late_days', 'needing_regularisation', 'late_cut_halves'] as $k) {
                $g[$k] = ($g[$k] ?? 0) + (int) ($t[$k] ?? 0);
            }

            $break = 0;

            foreach ((array) $row['cells'] as $cell) {
                $break += (int) ($cell['break_minutes'] ?? 0);
            }

            $g['break_minutes'] = ($g['break_minutes'] ?? 0) + $break;
            unset($g);
        }

        if ($groupBy !== 'employee') {
            uksort($groups, static fn (string $a, string $b): int => ($a === '(not set)') <=> ($b === '(not set)') ?: strcasecmp($a, $b));
        }

        $head = $groupBy === 'employee' ? ['Code', 'Name'] : [ucfirst($groupBy), 'People'];
        $rows = [];
        $totals = [];

        foreach ($groups as $label => $g) {
            $line = $groupBy === 'employee' ? explode(' · ', (string) $label, 2) : [(string) $label, $g['people']];

            foreach ($chosen as $key) {
                $line[] = $this->value($columns[$key], $g);
            }

            $rows[] = $line;

            foreach ($chosen as $i => $key) {
                $totals[$i] = $this->add($totals[$i] ?? null, $columns[$key], $g);
            }
        }

        $totalLine = ['Total', count($groups) . ($groupBy === 'employee' ? ' employee(s)' : ' group(s)')];

        foreach ($chosen as $i => $key) {
            $totalLine[] = $this->render($columns[$key], $totals[$i] ?? 0);
        }

        return [
            'columns' => array_merge($head, array_map(static fn (string $k): string => (string) $columns[$k]['label'], $chosen)),
            'rows' => $rows,
            'totals' => $totalLine,
            'raw' => $groups,
        ];
    }

    /** The bar chart's series: status => list<int> aligned with the group labels. @return array{labels: list<string>, series: array<string, list<int>>, colours: array<string, string>} */
    public function chart(array $raw): array
    {
        $statuses = (array) $this->config['chart_statuses'];
        $labels = array_slice(array_keys($raw), 0, (int) ($this->config['chart_rows_max'] ?? 40));
        $named = [];   // the status column's label, so the legend reads "Weekly off" and not WEEKLY_OFF

        foreach ($this->columns() as $c) {
            ($c['kind'] ?? '') === 'status' && $named[(string) $c['status']] = (string) $c['label'];
        }

        $series = [];
        $colours = [];

        foreach ($statuses as $status => $colour) {
            $name = $named[$status] ?? (string) $status;
            $series[$name] = array_map(static fn (string $l): int => (int) ($raw[$l]['status:' . $status] ?? 0), $labels);
            $colours[$name] = (string) $colour;
        }

        return ['labels' => array_map(static fn (string $l): string => (string) $l, $labels), 'series' => $series, 'colours' => $colours];
    }

    /** The folded report as a workbook (sheet 1: title rows, header, rows, totals). */
    public static function xlsx(array $folded, string $title, string $period): string
    {
        $rows = [[$title], [$period], []];
        $rows[] = array_map('strval', $folded['columns']);

        foreach ($folded['rows'] as $r) {
            $rows[] = array_map('strval', $r);
        }

        $rows[] = array_map('strval', $folded['totals']);

        return Sheet::xlsx($rows, [], 4);
    }

    /** One cell: the raw integer (minutes, days) the column reads from the group. */
    private function raw(array $column, array $g): int
    {
        return match ((string) $column['kind']) {
            'status' => (int) ($g['status:' . $column['status']] ?? 0),
            'payable' => $this->payableMinor($g),
            // halves of a day → hundredths, the same exact unit `payable` renders in.
            'cut' => (int) ($g['late_cut_halves'] ?? 0) * 50,
            default => (int) ($g[match ((string) $column['key']) {
                'worked_hours' => 'worked_minutes',
                'break_hours' => 'break_minutes',
                'late_hours' => 'late_minutes',
                'overtime_hours' => 'overtime_minutes',
                'regularisation' => 'needing_regularisation',
                default => (string) $column['key'],
            }] ?? 0),
        };
    }

    /** Payable days in hundredths, so a half day is exact. */
    private function payableMinor(array $g): int
    {
        $sum = 0;

        foreach ((array) $this->config['payable_statuses'] as $status => $weight) {
            $sum += (int) ($g['status:' . $status] ?? 0) * Amount::of((string) $weight)->minor;
        }

        // NET OF THE LATE-LOGIN CUT, as payroll pays it (8 Oct).
        return max(0, $sum - (int) ($g['late_cut_halves'] ?? 0) * 50);
    }

    private function add(?int $so_far, array $column, array $g): int
    {
        return ($so_far ?? 0) + $this->raw($column, $g);
    }

    private function value(array $column, array $g): string|int
    {
        return $this->render($column, $this->raw($column, $g));
    }

    private function render(array $column, int $raw): string|int
    {
        if (in_array((string) $column['kind'], ['payable', 'cut'], true)) {
            return Money::ofMinor($raw)->toDecimalString();
        }

        return str_ends_with((string) $column['key'], '_hours') ? AttendanceReport::hm($raw) : $raw;
    }
}
