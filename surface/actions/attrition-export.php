<?php
/**
 * ATTRITION AS EXCEL OR CSV — the table the screen showed, from the same partial
 * and the same posted period and filters.
 */
declare(strict_types=1);

if (!$may('employee.list')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.list')->summary;
    header('Location: ?r=attrition');
    exit;
}

$params = $_POST;
include __DIR__ . '/../partials/attrition-data.php';
$places = (int) ($cfg['percent_places'] ?? 2);
$columns = ['Month', 'Opening', 'Joiners', 'Leavers', 'Closing', 'Avg headcount', 'Rate %', 'Joined', 'Left'];
$lines = [];

foreach ($t['rows'] as $r) {
    $lines[] = [$r['month'], (string) $r['opening'], (string) $r['joiners'], (string) $r['leavers'], (string) $r['closing'], $r['average']->toDecimalString(1),
        $r['rate'] === null ? '' : $r['rate']->multipliedBy(SmartPRS\Platform\Support\Rational::of(100))->toDecimalString($places),
        implode(', ', $t['joiners'][$r['month']] ?? []), implode(', ', $t['leavers'][$r['month']] ?? [])];
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$audit('report.exported', 'report', 'attrition', null, ['rows' => count($lines), 'filter' => $filter, 'period' => $period, 'as' => $as], 'Attrition exported');
$name = 'attrition-' . $months[0] . '-to-' . $months[count($months) - 1];

if ($as === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo SmartPRS\Surface\FieldReports::csv($columns, $lines);
    exit;
}

$view = ($period === null ? 'Last ' . $count . ' months to ' . $toMonth : $period['label'] . ' (' . $period['from'] . ' to ' . $period['to'] . ')')
    . implode('', array_map(static fn (string $k, string $v): string => $v !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . $v : '', array_keys($filter), $filter));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([['Attrition'], [$view], [], $columns], $lines), [], 4);
exit;
