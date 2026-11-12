<?php declare(strict_types=1);
/**
 * THE ROWS OF ONE LIST REPORT — included by surface/partials/list-report.php
 * (the screen) and surface/actions/field-report-csv.php (the CSV) with
 * $reportKey and $params (the query string or the posted form) set, so the two
 * cannot disagree. Sets $cfg, $report, $month, $group, $groups, $people,
 * $inView, $filter, $keep and $result; $result stays null when there is no such
 * report or the viewer lacks its permission.
 */
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Surface\FieldReports;

$cfg = FieldReports::config(__DIR__ . '/../../config/field-reports.json');
$report = FieldReports::find($cfg, $reportKey);
$result = null;

// Fails closed: no report, or no permission, computes nothing.
if ($report === null || !$may((string) $report['permission'])) {
    return;
}

$route = 'report-' . $reportKey;
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($params['month'] ?? '')) === 1 ? (string) $params['month'] : date('Y-m');
// A report declared with `period` takes any period (month / year / FY / YTD / MTD / custom dates).
$period = ($report['period'] ?? false) ? reportPeriod($params, financialYearStart($customisation), date('Y-m-d')) : null;
$period !== null && $month = $period['month'];
$groups = (array) ($report['groups'] ?? []);
$group = in_array((string) ($params['g'] ?? ''), $groups, true) ? (string) $params['g'] : (string) ($groups[0] ?? '');
// THE VIEWER'S SCOPE, like every other list: a report is not a way around
// `Scope::only()`. Somebody who may read only their own employee gets a
// report of one; a manager gets their team; an administrator everybody.
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.list'));
$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$inView = orgFilterApply($store, $people, $filter);
$keep = array_filter(['month' => ($report['month'] ?? false) ? $month : '', 'g' => $group]);
$period !== null && $keep = array_filter(['p' => $period['mode'], 'month' => $period['month'], 'year' => $period['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $period['from'], 'to' => $period['to'], 'g' => $group]);

$result = match ($reportKey) {
    'headcount' => FieldReports::headcount($inView, $group, static fn (array $e, string $g): string => orgValueOf($store, $e, $g)),
    'attendance-summary' => FieldReports::attendanceSummary($inView, liveRows($store, 'attendance'), $month),
    'leave-summary' => FieldReports::leaveSummary($inView, liveRows($store, 'leaves'),
        array_column(liveRows($store, 'leave_types'), 'name', 'id'), $period === null ? $month : $period['from'] . '..' . $period['to'], \SmartPRS\Surface\Leave::APPROVED),
    'salary-register' => FieldReports::salaryRegister($inView, $register->runs(), $month,
        [SalaryComponent::EARNING], [SalaryComponent::DEDUCTION, SalaryComponent::RECOVERY]),
    'pf-esi-summary' => FieldReports::pfEsiSummary($inView, (array) $cfg['applicable_values']),
    'birthdays' => FieldReports::birthdays($inView, $month),
    default => ['columns' => [], 'rows' => [], 'totals' => null],
};
