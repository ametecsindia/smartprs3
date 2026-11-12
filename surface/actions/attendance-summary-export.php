<?php
/**
 * THE ATTENDANCE SUMMARY AS EXCEL OR CSV — computed by the same partial from
 * the same posted period, filter, grouping and columns the screen showed, so
 * the file and the page cannot differ.
 */
declare(strict_types=1);

$params = $_POST;
include __DIR__ . '/../partials/attendance-summary-data.php';

if ($asFolded === null) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'attendance.report.view')->summary;
    header('Location: ?r=report-attendance-summary');
    exit;
}

$asAs = post('as') === 'csv' ? 'csv' : 'xlsx';
$asName = 'attendance-summary-' . $asPeriod['from'] . '-to-' . $asPeriod['to'];
$audit('report.exported', 'report', 'attendance-summary', null,
    ['rows' => count($asFolded['rows']), 'filter' => $asFilter, 'period' => $asPeriod, 'group' => $asGroupBy, 'columns' => $asChosen, 'as' => $asAs], 'Attendance summary exported');

if ($asAs === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asName . '.csv"');
    echo \SmartPRS\Surface\FieldReports::csv($asFolded['columns'], $asFolded['rows'], $asFolded['totals']);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $asName . '.xlsx"');
echo \SmartPRS\Surface\AttendanceSummary::xlsx($asFolded, 'Attendance summary · by ' . (string) ((array) $asSummary->config()['group_by'])[$asGroupBy],
    $asPeriod['label'] . ' (' . $asPeriod['from'] . ' to ' . $asPeriod['to'] . ')' . implode('', array_map(static fn (string $k): string => $asFilter[$k] !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . $asFilter[$k] : '', array_keys($asFilter))));
exit;
