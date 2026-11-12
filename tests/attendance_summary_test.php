<?php

declare(strict_types=1);

use SmartPRS\Surface\AttendanceSummary;

group('attendance summary: the muster folded to counts');

$as = new AttendanceSummary(__DIR__ . '/../config/attendance-summary.json');
$cell = static fn (string $status, int $worked = 0, int $break = 0, int $late = 0): array => ['status' => $status, 'worked_minutes' => $worked, 'break_minutes' => $break, 'late_minutes' => $late, 'overtime_minutes' => 0, 'needs_regularisation' => false];
$row = static function (string $code, string $name, array $cells): array {
    $totals = ['worked_minutes' => 0, 'late_days' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0, 'needing_regularisation' => 0, 'by_status' => []];

    foreach ($cells as $c) {
        $totals['worked_minutes'] += $c['worked_minutes'];
        $totals['late_minutes'] += $c['late_minutes'];
        $totals['late_days'] += $c['late_minutes'] > 0 ? 1 : 0;
        $totals['by_status'][$c['status']] = ($totals['by_status'][$c['status']] ?? 0) + 1;
    }

    return ['employment_id' => $code, 'code' => $code, 'name' => $name, 'cells' => $cells, 'totals' => $totals];
};
$muster = [
    'dates' => ['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04'],
    'rows' => [
        $row('A1', 'A', ['2026-03-01' => $cell('WEEKLY_OFF'), '2026-03-02' => $cell('PRESENT', 480, 60, 15), '2026-03-03' => $cell('HALF_DAY', 240, 30), '2026-03-04' => $cell('NOT_YET')]),
        $row('B1', 'B', ['2026-03-01' => $cell('WEEKLY_OFF'), '2026-03-02' => $cell('ABSENT'), '2026-03-03' => $cell('PRESENT', 500, 45, 5), '2026-03-04' => $cell('NOT_YET')]),
    ],
    'legend' => [],
];
$dept = ['A1' => 'Collections', 'B1' => 'Collections'];

same(['days', 'PRESENT', 'HALF_DAY', 'ABSENT', 'WEEKLY_OFF', 'HOLIDAY', 'ON_LEAVE', 'UNPAID_LEAVE', 'late_cut', 'payable', 'late_days', 'worked_hours', 'overtime_hours'], $as->chosen([]), 'nothing ticked = the declared defaults');
same(['PRESENT', 'payable'], $as->chosen(['payable', 'PRESENT', 'bogus']), 'ticked columns in declared order, unknown keys dropped');

$f = $as->fold($muster, ['days', 'PRESENT', 'HALF_DAY', 'ABSENT', 'payable', 'late_days', 'late_hours', 'worked_hours', 'break_hours'], 'employee', static fn (array $r): string => '', '2026-03-03');
same(['Code', 'Name', 'Days in period', 'Present', 'Half day', 'Absent', 'Payable days', 'Late days', 'Late (HH:MM)', 'Worked (HH:MM)', 'Breaks (HH:MM)'], $f['columns'], 'headed by the chosen labels');
same(['A1', 'A', 3, 1, 1, 0, '2.50', 1, '15m', '12h 00m', '1h 30m'], $f['rows'][0], 'A: 3 days so far, WO + present + half = 2.5 payable, 720 worked minutes = 12h 00m, breaks 1h 30m');
same(['B1', 'B', 3, 1, 0, 1, '2.00', 1, '5m', '8h 20m', '45m'], $f['rows'][1], 'B: absent day counted, WO + present = 2 payable');
same(['Total', '2 employee(s)', 6, 2, 1, 1, '4.50', 2, '20m', '20h 20m', '2h 15m'], $f['totals'], 'totals sum every column exactly');

$g = $as->fold($muster, ['PRESENT', 'payable'], 'department', static fn (array $r): string => $dept[$r['code']], '2026-03-03');
same(['Department', 'People', 'Present', 'Payable days'], $g['columns'], 'grouped: the level and its headcount first');
same([['Collections', 2, 2, '4.50']], $g['rows'], 'one row per department, counts summed');

$chart = $as->chart($g['raw']);
same(['Collections'], $chart['labels'], 'chart labels are the group labels');
same([2], $chart['series']['Present'], 'series named by the column label, not the status code');
same([0], $chart['series']['Holiday'] ?? [1], 'a status nobody had is a zero series, not a missing one');

$bytes = AttendanceSummary::xlsx($g, 'Attendance summary', 'March 2026');
ok(str_starts_with($bytes, "PK\x03\x04"), 'the workbook is a zip');
ok(str_contains($bytes, 'sheet1.xml'), 'with a first sheet');
