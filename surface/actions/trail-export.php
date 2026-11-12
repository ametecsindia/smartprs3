<?php
/**
 * THE ACTIVITY LOG AS EXCEL OR CSV — the rows the screen showed, from the same
 * partial and the same posted filters. Before/after payloads are flattened to
 * key=value text, redacted exactly as they were on the way in.
 */
declare(strict_types=1);

if (!$may('audit.tenant.read')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'audit.tenant.read')->summary;
    header('Location: ?r=trail');
    exit;
}

$params = $_POST;
include __DIR__ . '/../partials/trail-data.php';
$flat = static fn (?array $v): string => $v === null ? '' : implode(', ', array_map(static fn ($k, $x): string => $k . '=' . (is_scalar($x) ? (string) $x : json_encode($x)), array_keys($v), $v));
$columns = ['Seq', 'When', 'Action', 'Subject', 'Subject id', 'By', 'Before', 'After', 'Reason', 'Links to'];
$lines = [];

foreach ($tlRows as $e) {
    $lines[] = [(string) (int) $e['seq'], (string) $e['occurred_at'], (string) $e['action'], (string) $e['entity_type'], (string) ($e['entity_id'] ?? ''), (string) $e['actor'],
        $flat($e['payload']['old_value'] ?? null), $flat($e['payload']['new_value'] ?? null), (string) ($e['reason'] ?? ''), $e['prev_hash'] === null ? 'genesis' : (string) $e['prev_hash']];
}

$as = post('as') === 'xlsx' ? 'xlsx' : 'csv';
$audit('report.exported', 'report', 'activity-logs', null, ['rows' => count($lines), 'filters' => $tlKeep, 'as' => $as], 'Activity log exported');
$name = 'activity-logs-' . date('Ymd');

if ($as === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    echo SmartPRS\Surface\FieldReports::csv($columns, $lines);
    exit;
}

$view = implode(' · ', array_filter([$tlPeriod === null ? 'Every period' : $tlPeriod['label'] . ' (' . $tlPeriod['from'] . ' to ' . $tlPeriod['to'] . ')',
    $tlAction !== '' ? 'Action: ' . $tlAction : '', $tlType !== '' ? 'Subject: ' . $tlType : '', $tlActor !== '' ? 'By: ' . $tlActor : '', $tlWord !== '' ? 'Contains: ' . $tlWord : '']));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([['Activity Logs'], [$view], [], $columns], $lines), [], 4);
exit;
