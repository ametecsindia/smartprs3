<?php
/**
 * EXCEL / CSV OF ONE EMPLOYEES-TAB LIST — exactly the table the screen shows.
 * The posted form carries `screen` and the screen's own filter fields; the
 * matching surface/partials/people-<screen>-data.php computes the rows for
 * the screen and for this file alike, so the two cannot differ. Each partial
 * leaves $export = {title, columns, rows, filter} (or null when denied).
 */
declare(strict_types=1);

use SmartPRS\Surface\FieldReports;
use SmartPRS\Surface\Sheet;

$screen = preg_replace('/[^a-z0-9-]/', '', (string) post('screen')) ?? '';
$partial = __DIR__ . '/../partials/people-' . $screen . '-data.php';
$back = (string) (json_decode((string) file_get_contents(__DIR__ . '/../../config/people-exports.json'), true)['screens'][$screen] ?? '');

if ($screen === '' || !is_file($partial) || $back === '') {
    $_SESSION['flash_problem'] = 'There is no such list to export.';
    header('Location: ?r=dashboard');
    exit;
}

$params = $_POST;
$export = null;
include $partial;

if (!is_array($export)) {
    $_SESSION['flash_problem'] = 'Denied: this list needs a permission you do not hold.';
    header('Location: ?r=' . $back);
    exit;
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$audit('report.exported', 'report', $screen, null, ['rows' => count($export['rows']), 'filter' => $export['filter'] ?? [], 'as' => $as], $export['title'] . ' exported');
$file = $screen . '-' . date('Ymd');

if ($as === 'xlsx') {
    $view = implode(' · ', array_filter(array_map(static fn (string $k, string $v): string => $v !== '' ? (ORG_FILTERS[$k] ?? ucfirst($k)) . ': ' . $v : '', array_keys((array) ($export['filter'] ?? [])), array_values((array) ($export['filter'] ?? [])))));
    $lines = array_merge([[(string) $export['title']], [$view !== '' ? $view : 'Everybody in scope'], [], array_map('strval', $export['columns'])],
        array_map(static fn (array $r): array => array_map('strval', $r), $export['rows']));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '.xlsx"');
    echo Sheet::xlsx($lines, [], 4);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '.csv"');
echo FieldReports::csv($export['columns'], $export['rows']);
exit;
