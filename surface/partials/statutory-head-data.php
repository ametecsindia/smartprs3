<?php declare(strict_types=1);
/**
 * THE STATUTORY HEAD SUMMARY'S DATA (PF / ESI / TDS per employee from closed runs) —
 * included by statutory-head-summary.php (with $params = $_GET) and by
 * surface/actions/statutory-summary-export.php (with $params = $_POST) with $heads
 * and $route set. Sets $cal, $shPeriod, $months, $fromPeriod, $toPeriod, $shKeep,
 * $people, $filter, $inView, $runs, $packState, $byHead, $periods, $totals, $rows.
 */
$cal = \SmartPRS\Surface\StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
// THE PERIOD (9 Sep): the same picker as every summary — month / year / financial year /
// YTD / MTD / custom — over the closed runs' periods; no pick = the last `periods_shown` months.
$shPeriod = isset($params['p']) ? reportPeriod($params, financialYearStart($customisation), date('Y-m-d')) : null;
$months = max(1, min(24, (int) ($params['months'] ?? $cal['periods_shown'])));
$toPeriod = $shPeriod !== null ? substr($shPeriod['to'], 0, 7) : (preg_match('/^\d{4}-\d{2}$/', (string) ($params['to'] ?? '')) === 1 ? (string) $params['to'] : date('Y-m'));
$fromPeriod = $shPeriod !== null ? substr($shPeriod['from'], 0, 7) : (new \DateTimeImmutable($toPeriod . '-01'))->modify('-' . ($months - 1) . ' months')->format('Y-m');
$shKeep = $shPeriod === null ? ['to' => $toPeriod, 'months' => (string) $months] : ['p' => $shPeriod['mode'], 'month' => $shPeriod['month'], 'year' => $shPeriod['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $shPeriod['from'], 'to' => $shPeriod['to']];
$asOf = (new \DateTimeImmutable($toPeriod . '-01'))->format('Y-m-t');
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$filter = [];

foreach (array_keys(ORG_FILTERS) as $shK) {
    $shV = trim((string) ($params['f_' . $shK] ?? ''));
    $filter[$shK] = strcasecmp($shV, 'all') === 0 ? '' : $shV;
}

$inView = orgFilterApply($store, $people, $filter);
$runs = $register->runs();
$packState = [];

foreach ($heads as $h) {
    $pack = $statutory->packsByHead(new \DateTimeImmutable($asOf))[$h] ?? null;
    $packState[$h] = $pack === null
        ? ['ok' => false, 'why' => 'no signed pack declares ' . $h . ' on ' . $asOf . ' (' . $statutory->why() . ')']
        : ($pack->manifest->isSynthetic()
            ? ['ok' => false, 'why' => 'the only pack in force for ' . $h . ' is SYNTHETIC (jurisdiction ' . $pack->manifest->jurisdiction() . '); it states no law and no figure is shown from it']
            : ['ok' => true, 'why' => $h . ' from pack ' . $pack->manifest->packKey() . ' v' . $pack->manifest->version()]);
}

$byHead = [];

foreach ($heads as $h) {
    $byHead[$h] = \SmartPRS\Surface\StatutoryReports::byEmployment($runs, (array) $cal['heads'][$h]['line_codes'], $fromPeriod, $toPeriod);
}

$periods = [];

foreach ($runs as $run) {
    if (strcmp((string) $run['period'], $fromPeriod) >= 0 && strcmp((string) $run['period'], $toPeriod) <= 0) {
        $periods[(string) $run['period']] = true;
    }
}

$periods = array_keys($periods);
sort($periods);
$totals = array_fill_keys($heads, 0);
$rows = [];

foreach ($inView as $e) {
    $code = \SmartPRS\Surface\Employment::code($e);
    $cells = [];
    $any = false;

    foreach ($heads as $h) {
        $sum = array_sum($byHead[$h][$code] ?? []);
        $cells[$h] = $sum;
        $totals[$h] += $sum;
        $any = $any || ($byHead[$h][$code] ?? []) !== [];
    }

    $rows[] = ['e' => $e, 'code' => $code, 'cells' => $cells, 'in_runs' => $any];
}
