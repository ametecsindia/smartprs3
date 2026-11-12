<?php
/**
 * PF & ESI / TDS SUMMARY AS EXCEL OR CSV — the same rows the screen showed, from
 * the same data partial and the same posted period and filters.
 */
declare(strict_types=1);

use SmartPRS\Platform\Support\Money;

if (!$may('payroll.view')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.view')->summary;
    header('Location: ?r=pf-esi');
    exit;
}

$route = post('route') === 'tds' ? 'tds' : 'pf-esi';
$heads = $route === 'tds' ? ['TDS'] : ['PF', 'ESI'];
$params = $_POST;
include __DIR__ . '/../partials/statutory-head-data.php';
$label = static fn (string $p): string => date('M Y', (int) strtotime($p . '-01'));
$monthCols = [];
foreach ($periods as $p) { foreach ($heads as $h) { $monthCols[] = $label($p) . (count($heads) > 1 ? ' ' . $h : ''); } }
$columns = array_merge(['Employee ID', 'Name', 'Designation', 'Branch', 'Company'], $monthCols,
    array_map(static fn (string $h): string => 'Total' . (count($heads) > 1 ? ' ' . $h : ''), $heads));
$lines = [];
$monthTot = [];

// THE SAME ROWS AS THE SCREEN: employees with a deduction in the period.
foreach ($rows as $r) {
    if (array_sum($r['cells']) === 0) { continue; }
    $line = [$r['code'], (string) ($r['e']['name'] ?? ''), orgValueOf($store, $r['e'], 'designation'), orgValueOf($store, $r['e'], 'branch'), orgValueOf($store, $r['e'], 'company')];

    foreach ($periods as $p) {
        foreach ($heads as $h) {
            $v = (int) ($byHead[$h][$r['code']][$p] ?? 0);
            $monthTot[$p . $h] = ($monthTot[$p . $h] ?? 0) + $v;
            $line[] = Money::ofMinor($v)->toDecimalString();
        }
    }

    foreach ($heads as $h) {
        $line[] = Money::ofMinor($r['cells'][$h])->toDecimalString();
    }

    $lines[] = $line;
}

$total = ['Total', count($lines) . ' employee(s)', '', '', ''];
foreach ($periods as $p) { foreach ($heads as $h) { $total[] = Money::ofMinor((int) ($monthTot[$p . $h] ?? 0))->toDecimalString(); } }
foreach ($heads as $h) {
    $total[] = Money::ofMinor($totals[$h])->toDecimalString();
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$audit('report.exported', 'report', $route . '-summary', null, ['rows' => count($lines), 'filter' => $filter, 'from' => $fromPeriod, 'to' => $toPeriod, 'as' => $as], 'Statutory summary exported');
$name = $route . '-summary-' . $fromPeriod . '-to-' . $toPeriod;

if ($as === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo SmartPRS\Surface\FieldReports::csv($columns, $lines, $total);
    exit;
}

$view = $fromPeriod . ' to ' . $toPeriod . implode('', array_map(static fn (string $k, string $v): string => $v !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . $v : '', array_keys($filter), $filter));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([[implode(' & ', $heads) . ' from closed payroll runs'], [$view], [], $columns], $lines, [$total]), [], 4);
exit;
