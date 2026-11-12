<?php
/**
 * A DATASET AS EXCEL OR CSV — every row the screen would show (the screen previews
 * the first few hundred), from the same partial and the same posted filters.
 */
declare(strict_types=1);

$params = $_POST;
include __DIR__ . '/../partials/report-datasets-data.php';

if ($dsWhy !== '') {
    $_SESSION['flash_problem'] = $dsWhy;
    header('Location: ?r=report-datasets');
    exit;
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$columns = array_values(array_map('strval', $dsColumns));
$audit('report.exported', 'report', 'dataset-' . (string) $ds['key'], null, ['rows' => count($dsRows), 'filter' => $dsFilter, 'period' => $dsPeriod, 'as' => $as], 'Dataset exported');
$name = (string) $ds['key'] . '-' . date('Ymd');

if ($as === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo SmartPRS\Surface\FieldReports::csv($columns, $dsRows);
    exit;
}

$view = implode(' · ', array_filter([$dsPeriod === null ? 'Every period' : $dsPeriod['label'] . ' (' . $dsPeriod['from'] . ' to ' . $dsPeriod['to'] . ')'],
    static fn (string $v): bool => $v !== '')) . implode('', array_map(static fn (string $k, string $v): string => $v !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . $v : '', array_keys($dsFilter), $dsFilter));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([[(string) $ds['label']], [$view], [], $columns], array_map(static fn (array $r): array => array_map('strval', $r), $dsRows)), [], 4);
exit;
