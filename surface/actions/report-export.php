<?php
/**
 * A HUB REPORT (config/reports.json) AS EXCEL OR CSV — the same rows the screen
 * showed, from the same partial and the same posted filters.
 */
declare(strict_types=1);

$one = $reports->find((string) post('k'));
$gate = $one === null ? null : $reports->permissionsFor($one);
$denied = $one === null ? [] : array_values(array_filter($gate['permissions'], static fn (string $p): bool => !$may($p)));

if ($one === null || $gate['why'] !== null || $denied !== []) {
    $_SESSION['flash_problem'] = $one === null ? 'There is no such report.' : 'Denied: ' . ($gate['why'] ?? $access->decide((string) $session->user()['role'], $denied[0])->summary);
    header('Location: ?r=reports');
    exit;
}

$params = $_POST;
include __DIR__ . '/../partials/report-hub-data.php';
$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$measure = ucfirst((string) ($one['measure'] ?? 'count'));
$lines = [[(string) ($one['group_by'] ?? 'Group'), 'Rows', $measure]];

foreach ($result['rows'] as $row) {
    $lines[] = [(string) $row['label'], (string) (int) $row['rows'], (string) $row['value']];
}

($one['measure'] ?? 'count') !== 'average' && $lines[] = ['Total', (string) (int) $result['counted'], (string) $result['total']];
$audit('report.exported', 'report', (string) $one['key'], null, ['rows' => count($result['rows']), 'filter' => $rpFilter, 'period' => $rpPeriod, 'as' => $as], 'Report exported');
$name = (string) $one['key'] . '-' . date('Ymd');

if ($as === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo SmartPRS\Surface\FieldReports::csv(array_shift($lines), $lines);
    exit;
}

$view = implode(' · ', array_filter(array_map(static fn (string $k, string $v): string => $v !== '' ? ORG_FILTERS[$k] . ': ' . $v : '', array_keys($rpFilter), $rpFilter)));
$rpPeriod !== null && $view = $rpPeriod['label'] . ' (' . $rpPeriod['from'] . ' to ' . $rpPeriod['to'] . ')' . ($view !== '' ? ' · ' . $view : '');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([[(string) $one['label']], [$view !== '' ? $view : 'Everybody, every period'], []], $lines), [], 4);
exit;
