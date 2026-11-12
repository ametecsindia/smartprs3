<?php declare(strict_types=1);
/**
 * THE ATTRITION TABLE — included by the screen (with $params = $_GET) and by
 * surface/actions/attrition-export.php (with $params = $_POST), so the download
 * is exactly the table shown. Sets $cfg, $people, $filter, $inView, $period,
 * $periodKeep, $toMonth, $count, $months and $t.
 */
$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/attrition.json'), true);
$people = $scope->only($scope->only($store->all('employees'), $may('employee.list')), $may('employee.list')); // leavers are inactive rows: liveRows() would hide the very people this counts
$people = array_values(array_filter($people, static fn (array $e): bool => trim((string) ($e['deleted_at'] ?? '')) === ''));
$filter = [];

foreach (array_keys(ORG_FILTERS) as $atK) {
    $atV = trim((string) ($params['f_' . $atK] ?? ''));
    $filter[$atK] = strcasecmp($atV, 'all') === 0 ? '' : $atV;
}

$inView = orgFilterApply($store, $people, $filter);
// THE PERIOD (9 Sep): month / year / financial year / YTD / MTD / custom dates, like every
// summary. The months drawn are the months the period touches; with no period picked,
// config `default_period` (see below).
$atToday = date('Y-m-d');
// ALWAYS A PERIOD (owner, 30 Sep): the range shown in the picker is the range
// counted — `default_period` (config) when none is picked — and it stops at
// the current month, so a financial year in progress has no empty future rows.
$period = reportPeriod(isset($params['p']) ? $params : ['p' => (string) ($cfg['default_period'] ?? 'fy')], financialYearStart($customisation), $atToday);
$fromMonth = substr($period['from'], 0, 7);
$toMonth = min(substr($period['to'], 0, 7), substr($atToday, 0, 7));
$toMonth = max($toMonth, $fromMonth);
$count = max(1, min(60, ((int) substr($toMonth, 0, 4) - (int) substr($fromMonth, 0, 4)) * 12 + (int) substr($toMonth, 5, 2) - (int) substr($fromMonth, 5, 2) + 1));
$periodKeep = ['p' => $period['mode'], 'month' => $period['month'], 'year' => $period['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $period['from'], 'to' => $period['to']];
$months = \SmartPRS\Surface\Attrition::months($toMonth, $count);
$exitedOn = [];

// One read of the events table, not one per employee.
foreach ($store->all('employment_events') as $ev) {
    if ((string) ($ev['kind'] ?? '') === \SmartPRS\Platform\Lifecycle\EmploymentTimeline::EXITED) {
        $exitedOn[(string) ($ev['employment_id'] ?? '')] = (string) ($ev['effective_on'] ?? '');
    }
}

$t = \SmartPRS\Surface\Attrition::table($inView, $exitedOn, $months, $cfg);
