<?php declare(strict_types=1);
/**
 * THE ATTENDANCE SUMMARY'S DATA — included by the screen (with $params = $_GET)
 * and by the CSV / Excel actions (with $params = $_POST), so the download is
 * exactly the table shown. Sets $asSummary, $asPeriod, $asFilter, $asPeople,
 * $asInView, $asGroupBy, $asChosen, $asFolded; $asFolded stays null when the
 * viewer may not see the report.
 */
$asFolded = null;

if (!$may('attendance.report.view')) {
    return;
}

$asSummary = new \SmartPRS\Surface\AttendanceSummary(__DIR__ . '/../../config/attendance-summary.json');
$asToday = date('Y-m-d');
$asPeriod = reportPeriod($params, financialYearStart($customisation), $asToday);
$asFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $asFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$asPeople = $scope->only(liveRows($store, 'employees'), $may('attendance.day.view.history'));
$asInView = orgFilterApply($store, $asPeople, $asFilter);
$asGroups = (array) $asSummary->config()['group_by'];
$asGroupBy = isset($asGroups[(string) ($params['g'] ?? '')]) ? (string) $params['g'] : 'employee';
$asChosen = $asSummary->chosen((array) ($params['c'] ?? []));
$asMuster = $attendanceReport->musterWide($asInView, $asPeriod['from'], $asPeriod['to'], $calendar->shiftStart(), $calendar->shiftEnd(), $asToday);
$asById = [];

foreach ($asInView as $asE) {
    $asById[(string) $asE['id']] = $asE;
}

$asFolded = $asSummary->fold($asMuster, $asChosen, $asGroupBy,
    static fn (array $row): string => isset($asById[$row['employment_id']]) ? orgValueOf($store, $asById[$row['employment_id']], $asGroupBy) : '',
    $asToday);
