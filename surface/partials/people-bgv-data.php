<?php declare(strict_types=1);
/**
 * BACKGROUND VERIFICATION DESK — the rows. Included by the `bgv` route block
 * (public/app.php) and by surface/actions/people-export.php with $params set,
 * so the screen and the Excel/CSV are the same table. Sets $bgD, $bgVisible,
 * $bgById, $bgFilter, $bgOptions, $bgRows (filtered, due-soonest first),
 * $bgDueDays and $export.
 */
use SmartPRS\Surface\Employment;

$bgD = $bgv->declared();
$bgScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$bgFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $bgFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$bgOptions = orgFilterOptions($store, $bgScoped);
$bgVisible = orgFilterApply($store, $bgScoped, $bgFilter);
$bgById = [];

foreach ($bgVisible as $bgE) {
    $bgById[(string) $bgE['id']] = $bgE;
}

$bgDueDays = (int) ($bgD['due_soon_days'] ?? 30);
$bgToday = date('Y-m-d');
$bgSoon = date('Y-m-d', strtotime('+' . $bgDueDays . ' days'));
$bgRows = array_values(array_filter($bgv->all(), static fn (array $r): bool => isset($bgById[(string) ($r['employee_id'] ?? '')])));
// DUE-SOONEST FIRST: overdue, then due within the window, then the rest by date; undated last.
usort($bgRows, static fn (array $a, array $b): int => [($a['next_due'] ?? '') === '' ? 1 : 0, (string) ($a['next_due'] ?? '')] <=> [($b['next_due'] ?? '') === '' ? 1 : 0, (string) ($b['next_due'] ?? '')]);

$export = ['title' => 'Background Verification', 'filter' => $bgFilter,
    'columns' => array_merge(['CODE', 'EMPLOYEE', 'AGENCY'], array_map(static fn (string $l): string => mb_strtoupper($l), array_values($bgD['checks'])), ['OVERALL', 'VERIFIED ON', 'NEXT DUE', 'DUE STATE']),
    'rows' => []];

foreach ($bgRows as $r) {
    $e = $bgById[(string) $r['employee_id']];
    $line = [Employment::code($e), trim((string) ($e['name'] ?? '')), (string) ($r['agency'] ?? '')];

    foreach (array_keys($bgD['checks']) as $k) {
        $line[] = (string) ($bgD['outcomes'][(string) ($r['outcomes'][$k] ?? 'pending')] ?? '');
    }

    $due = (string) ($r['next_due'] ?? '');
    $line[] = (string) ($bgD['statuses'][(string) $r['status']] ?? $r['status']);
    $line[] = (string) ($r['completed_on'] ?? '');
    $line[] = $due;
    $line[] = $due === '' ? '' : ($due < $bgToday ? 'overdue' : ($due <= $bgSoon ? 'due soon' : 'ok'));
    $export['rows'][] = $line;
}
