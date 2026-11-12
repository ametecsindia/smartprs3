<?php
/**
 * A STATUTORY REGISTER AS CSV — the columns config/audit-reports.json names,
 * scoped by the Organization Setup filter for employee-keyed tables. The
 * confidential register is refused here whatever is posted.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;

$cfg = StatutoryReports::config(__DIR__ . '/../../config/audit-reports.json');
$fail = static function (string $why): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=audit-reports');
    exit;
};

if (!$may((string) $cfg['permission'])) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], (string) $cfg['permission'])->summary);
}

$key = trim(post('register'));
$reg = null;

foreach ((array) $cfg['registers'] as $candidate) {
    if ((string) $candidate['key'] === $key) {
        $reg = $candidate;
    }
}

if ($reg === null || !isset($reg['table']) || ($reg['confidential'] ?? false)) {
    $fail('That register is not exportable in bulk.');
}

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim(post('f_' . $k));
    $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$people = [];

foreach (orgFilterApply($store, $scope->only(liveRows($store, 'employees'), $may('employee.list')), $filter) as $e) {
    $people[(string) $e['id']] = $e;
}

$rows = liveRows($store, (string) $reg['table']);
$hasEmployee = in_array('employee_id', $store->columnsOf((string) $reg['table']), true);

if ($hasEmployee) {
    $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($people[(string) ($r['employee_id'] ?? '')])));
}

$columns = (array) $reg['columns'];
$out = fopen('php://temp', 'r+');
fputcsv($out, array_merge($hasEmployee ? ['emp_code', 'name'] : [], $columns), ',', '"', '\\');

foreach ($rows as $r) {
    $line = [];

    if ($hasEmployee) {
        $line[] = Employment::code($people[(string) $r['employee_id']]);
        $line[] = (string) ($people[(string) $r['employee_id']]['name'] ?? '');
    }

    foreach ($columns as $c) {
        $line[] = (string) ($r[$c] ?? '');
    }

    fputcsv($out, $line, ',', '"', '\\');
}

rewind($out);
$csv = (string) stream_get_contents($out);
fclose($out);
$audit('audit-report.exported', (string) $reg['table'], $key, null, ['rows' => count($rows), 'filter' => $filter], 'Statutory register CSV exported');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9-]/', '', $key) . '-' . date('Ymd') . '.csv"');
echo $csv;
exit;
