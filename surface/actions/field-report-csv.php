<?php
/**
 * A LIST REPORT AS CSV — the same rows the screen shows, computed by the same
 * partial from the same posted filter, so the file and the page cannot differ.
 * Also serves the Agent Authorisations and DRA Certificates registers (report
 * = agent-auth | dra-certs), which are ordered expiring-first like the screen.
 */
declare(strict_types=1);

use SmartPRS\Surface\FieldReports;

$reportKey = preg_replace('/[^a-z0-9-]/', '', (string) post('report')) ?? '';
$params = $_POST;
$fail = static function (string $why, string $back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=' . $back);
    exit;
};

if ($reportKey === 'agent-auth' || $reportKey === 'dra-certs') {
    if (!$may('employee.view')) {
        $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary, $reportKey);
    }

    include __DIR__ . '/../partials/' . $reportKey . '-data.php';
    $columns = $reportKey === 'agent-auth'
        ? ['Code', 'Name', 'Bank', 'Portfolio', 'Letter no.', 'Valid from', 'Valid to', 'Days left', 'Band', 'Status']
        : ['Code', 'Name', 'Company', 'Branch', 'DRA status', 'DRA expiry', 'Days left', 'Band'];
    $lines = [];

    foreach ($rows as $row) {
        $lines[] = $reportKey === 'agent-auth'
            ? [$row['code'], $row['name'], $row['bank'], $row['portfolio'], $row['auth_no'], $row['valid_from'],
                $row['valid_to'], $row['days_left'] ?? '', $row['band'], $row['status']]
            : [$row['code'], $row['name'], $row['company'], $row['branch'], $row['dra_status'], $row['dra_expiry'],
                $row['days_left'] ?? '', $row['band']];
    }

    $csv = FieldReports::csv($columns, $lines);
} else {
    include __DIR__ . '/../partials/list-report-data.php';

    if ($report === null) {
        $fail('There is no such report.', 'reports');
    }

    if ($result === null) {
        $fail('Denied: ' . $access->decide((string) $session->user()['role'], (string) $report['permission'])->summary, 'report-' . $reportKey);
    }

    $csv = FieldReports::csv($result['columns'], $result['rows'], $result['totals']);
    $rows = $result['rows'];
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$audit('report.exported', 'report', $reportKey, null, ['rows' => count($rows), 'filter' => $filter, 'as' => $as, 'params' => array_intersect_key($params, ['month' => 1, 'g' => 1, 'p' => 1, 'from' => 1, 'to' => 1])], 'Report exported');

if ($as === 'xlsx') {
    // THE SAME TABLE AS A WORKBOOK: title, the view, a blank line, then the CSV's own rows.
    $lines = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', '\\'), array_filter(explode("\n", str_replace("\r", '', $csv)), static fn (string $l): bool => $l !== ''));
    $title = $report['label'] ?? ucwords(str_replace('-', ' ', $reportKey));
    $view = implode(' · ', array_filter(array_map(static fn (string $k, string $v): string => $v !== '' ? ORG_FILTERS[$k] . ': ' . $v : '', array_keys($filter), $filter)));
    isset($period) && $period !== null && $view = $period['label'] . ' (' . $period['from'] . ' to ' . $period['to'] . ')' . ($view !== '' ? ' · ' . $view : '');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $reportKey . '-' . date('Ymd') . '.xlsx"');
    echo SmartPRS\Surface\Sheet::xlsx(array_merge([[(string) $title], [$view !== '' ? $view : 'Everybody in scope'], []], $lines), [], 4);
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $reportKey . '-' . date('Ymd') . '.csv"');
echo $csv;
exit;
