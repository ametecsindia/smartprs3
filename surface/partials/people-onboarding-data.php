<?php declare(strict_types=1);
/**
 * ONBOARDING BOARDS — the rows. Included by the `onboarding-status` route block
 * and by surface/actions/people-export.php with $params set. Sets $obFilter,
 * $obOptions, $obVisible, $obById, $obBoards, $obStatus, $obShown and $export.
 */
use SmartPRS\Surface\Employment;

$obScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$obFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $obFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$obOptions = orgFilterOptions($store, $obScoped);
$obVisible = orgFilterApply($store, $obScoped, $obFilter);
$obById = [];

foreach ($obVisible as $obE) {
    $obById[(string) $obE['id']] = $obE;
}

$obBoards = array_values(array_filter($onboarding->boards(), static fn (array $b): bool => isset($obById[(string) ($b['employee_id'] ?? '')])));
$obStatus = in_array((string) ($params['s'] ?? ''), ['pending', 'in_progress', 'completed'], true) ? (string) $params['s'] : '';
$obShown = array_values(array_filter($obBoards, static fn (array $b): bool => $obStatus === '' || $b['status'] === $obStatus));

$export = ['title' => 'Onboarding' . ($obStatus !== '' ? ' — ' . str_replace('_', ' ', $obStatus) : ''), 'filter' => $obFilter + ($obStatus !== '' ? ['status' => $obStatus] : []),
    'columns' => ['CODE', 'NEW HIRE', 'COMPANY', 'DEPARTMENT', 'CHECKLIST', 'JOINED ON', 'DONE', 'TOTAL', 'PROGRESS %', 'STATUS', 'OPEN ITEMS'], 'rows' => []];

foreach ($obShown as $b) {
    $e = $obById[(string) $b['employee_id']];
    $open = array_map(static fn (array $i): string => (string) $i['label'], array_filter($b['items'], static fn (array $i): bool => (int) ($i['done'] ?? 0) !== 1));
    $export['rows'][] = [Employment::code($e), trim((string) ($e['name'] ?? '')), orgValueOf($store, $e, 'company'), orgValueOf($store, $e, 'department'), (string) $b['stage'],
        (string) ($b['joined_on'] ?? ''), (string) $b['done'], (string) $b['total'], (string) ($b['total'] === 0 ? 0 : intdiv($b['done'] * 100, $b['total'])),
        str_replace('_', ' ', (string) $b['status']), implode('; ', $open)];
}
