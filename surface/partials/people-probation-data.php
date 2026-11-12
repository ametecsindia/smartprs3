<?php declare(strict_types=1);
/**
 * PROBATION BOARD — the rows. Included by the `probation` route block and by
 * surface/actions/people-export.php with $params set. Sets $probConfig,
 * $prFilter, $prScoped, $prOptions, $prShown, $probBoard and $export.
 */
$probConfig = $probation->config();
$prScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$prFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $prFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$prOptions = orgFilterOptions($store, $prScoped);
$prShown = orgFilterApply($store, $prScoped, $prFilter);
$probBoard = $probation->board($prShown, date('Y-m-d'));

// COMPANY / BRANCH / ROLE / DESIGNATION through orgValueOf() — the id-first
// reader the filter uses — and the reporting manager as "CODE · Name".
$prById = [];
foreach (liveRows($store, 'employees') as $prE) {
    $prById[(string) ($prE['id'] ?? '')] = $prE;
}
foreach ($probBoard['rows'] as &$prRow) {
    $prE = $prById[$prRow['employment_id']] ?? [];
    foreach (['company', 'branch', 'role', 'designation'] as $k) {
        $prRow[$k] = orgValueOf($store, $prE, $k) ?: (string) ($prRow[$k] ?? '');
    }
    $prMgr = $prById[(string) ($prE['reporting_manager_id'] ?? '')] ?? null;
    $prRow['manager'] = $prMgr === null ? '' : SmartPRS\Surface\Employment::label($prMgr);
}
unset($prRow);

$export = ['title' => 'Probation', 'filter' => $prFilter,
    'columns' => ['EMPLOYEE ID', 'NAME', 'COMPANY', 'BRANCH', 'ROLE', 'DESIGNATION', 'REPORTING MANAGER', 'DATE OF JOINING', 'PROBATION ENDS', 'DAYS LEFT', 'STATE'],
    'rows' => array_map(static fn (array $r): array => [$r['code'], $r['name'], $r['company'], $r['branch'], $r['role'], $r['designation'], $r['manager'], $r['joined'],
        (string) ($r['end'] ?? ''), $r['days'] === null ? '' : (string) $r['days'], $r['bucket']], $probBoard['rows'])];
