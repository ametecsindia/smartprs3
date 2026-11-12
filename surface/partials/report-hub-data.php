<?php declare(strict_types=1);
/**
 * ONE REPORT FROM THE HUB (config/reports.json), narrowed by the owner's filters —
 * included by the reports screen (with $params = $_GET) and by
 * surface/actions/report-export.php (with $params = $_POST) with $one set, so the
 * download is exactly the table shown. Sets $rpFilter, $rpPeriod, $result, $money,
 * $rpKeep, $rpLabels, $rpSeries.
 */
$rpFilter = [];

foreach (array_keys(ORG_FILTERS) as $rpK) {
$rpV = trim((string) ($params['f_' . $rpK] ?? ''));
$rpFilter[$rpK] = strcasecmp($rpV, 'all') === 0 ? '' : $rpV;
}

$rpPeople = orgFilterApply($store, liveRows($store, 'employees'), $rpFilter);
$rpPeriod = ($one['date_column'] ?? '') !== '' && isset($params['p']) ? reportPeriod($params, financialYearStart($customisation), date('Y-m-d')) : null;
$rpWhere = (array) ($one['where'] ?? []);

if (array_filter($rpFilter) !== []) {
    // Through whichever column the table names its people by: its own id, an employee_id, or an emp_code.
    $rpColumns = $store->columnsOf((string) $one['table']);
    $rpBy = (string) $one['table'] === 'employees' ? 'id' : (in_array('employee_id', $rpColumns, true) ? 'employee_id' : (in_array('emp_code', $rpColumns, true) ? 'emp_code' : ''));
    $rpWhere[$rpBy !== '' ? $rpBy : 'employee_id'] = array_map(static fn (array $e): string => $rpBy === 'emp_code' ? \SmartPRS\Surface\Employment::code($e) : (string) $e['id'], $rpPeople);
}

if ($rpPeriod !== null) {
    // Dates as a list, because the aggregate speaks IN and a year is 366 values — small enough.
    $rpWhere[(string) $one['date_column']] = \SmartPRS\Surface\AttendanceReport::dates($rpPeriod['from'], $rpPeriod['to'], true);
}

    $result = $reports->run(['where' => $rpWhere] + $one);
    $money = (bool) ($one['money'] ?? false);
$rpKeep = ['k' => (string) $one['key']] + ($rpPeriod !== null ? ['p' => $rpPeriod['mode'], 'month' => $rpPeriod['month'], 'year' => $rpPeriod['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $rpPeriod['from'], 'to' => $rpPeriod['to']] : []);
$rpLabels = array_map(static fn (array $r): string => (string) $r['label'], $result['rows']);
$rpValue = static fn (array $r): int => $money || ($one['measure'] ?? '') === 'average' ? (int) explode('.', (string) $r['value'])[0] : (int) $r['value'];
$rpSeries = [(string) ucfirst((string) ($one['measure'] ?? 'count')) . (($one['column'] ?? '') !== '' ? ' of ' . $one['column'] : '') => array_map($rpValue, $result['rows'])];
