<?php declare(strict_types=1);
/**
 * DRA CERTIFICATES — the rows. From each live employee's dra_status /
 * dra_expiry, banded against today with the window from
 * config/field-reports.json, expiring-first. Included by the screen and the CSV
 * action with $params set. Sets $cfg, $window, $today, $people, $filter,
 * $inView, $rows (each: code, name, company, branch, dra_status, dra_expiry,
 * days_left, band, employee_id) and $counts.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\FieldReports;

$cfg = FieldReports::config(__DIR__ . '/../../config/field-reports.json');
$window = (int) $cfg['expiry_window_days'];
$today = date('Y-m-d');
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$inView = orgFilterApply($store, $people, $filter);
$rows = [];

foreach ($inView as $e) {
    $rows[] = [
        'employee_id' => (string) ($e['id'] ?? ''),
        'code' => Employment::code($e),
        'name' => (string) ($e['name'] ?? ''),
        'company' => orgValueOf($store, $e, 'company'),
        'branch' => orgValueOf($store, $e, 'branch'),
        'dra_status' => (string) ($e['dra_status'] ?? ''),
        'dra_expiry' => substr((string) ($e['dra_expiry'] ?? ''), 0, 10),
    ];
}

$rows = FieldReports::expiringFirst($rows, 'dra_expiry', $today, $window);
$counts = FieldReports::bandCounts($rows);
