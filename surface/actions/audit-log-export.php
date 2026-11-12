<?php
/**
 * AUDIT LOG → EXCEL (owner, 22 Oct 2026): the rows the Audit Log shows for the same filters, one line per
 * changed field (an entry with no field change is one line), so the file filters by user, module or field.
 */
declare(strict_types=1);

if (!$may('audit.tenant.read')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'audit.tenant.read')->summary;
    header('Location: ?r=audit-log');
    exit;
}

$params = $_POST;
include __DIR__ . '/../partials/audit-log-data.php';
$columns = ['When', 'User', 'Category', 'Module', 'What happened', 'Action code', 'Record', 'Field', 'Old value', 'New value'];
$lines = [];

foreach ($alRows as $r) {
    foreach ($r['changes'] === [] ? [['field' => '', 'old' => '', 'new' => '']] : $r['changes'] as $c) {
        $lines[] = [$r['when'], $r['by'], $r['cat'], $r['mod'], $r['what'], $r['action'], $r['record'], $c['field'], $c['old'], $c['new']];
    }
}

$audit('report.exported', 'report', 'audit-log', null, ['rows' => count($lines), 'filters' => $alKeep], 'Audit log exported');
$view = 'From ' . SmartPRS\Surface\Dates::dmy($alFrom) . ' to ' . SmartPRS\Surface\Dates::dmy($alTo)
    . implode('', array_map(static fn (string $k, string $v): string => $v === '' ? '' : ' · ' . $k . ': ' . $v, ['Category', 'Module', 'User', 'Contains'], [$alCat, $alMod, $alBy, $alWord]));
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd-His') . '.xlsx"');
echo SmartPRS\Surface\Sheet::xlsx(array_merge([['Audit Log'], [$view], [], $columns], $lines), [], 4);
exit;
