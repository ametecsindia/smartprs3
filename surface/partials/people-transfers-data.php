<?php declare(strict_types=1);
/**
 * TRANSFERS — the rows. Included by the `transfers` route block and by
 * surface/actions/people-export.php with $params set. Sets $trState,
 * $trFilter, $trOptions, $trScoped, $trVisible, $trById, $trRows and $export.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Transfers;

$trState = in_array((string) ($params['s'] ?? ''), [Transfers::PENDING, Transfers::APPROVED, Transfers::APPLIED, Transfers::REJECTED], true) ? (string) $params['s'] : '';
$trScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$trFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $trFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$trOptions = orgFilterOptions($store, $trScoped);
$trVisible = orgFilterApply($store, $trScoped, $trFilter);
$trById = [];

foreach ($trVisible as $trE) {
    $trById[(string) $trE['id']] = $trE;
}

// THE LIST IS SCOPED BY THE EMPLOYEE IT MOVES: a manager sees their people's transfers, nobody else's.
$trRows = array_values(array_filter($transfers->all($trState === '' ? null : $trState),
    static fn (array $t): bool => isset($trById[(string) $t['employee_id']])));
$trTypeLabel = static fn (string $type): string => ['company' => 'Company transfer', 'branch' => 'Branch transfer', 'department' => 'Department transfer'][$type] ?? ucfirst($type);
$trTo = static fn (array $t): string => implode(', ', array_filter([(string) ($t['to_company'] ?? ''), (string) ($t['to_branch'] ?? ''), (string) ($t['to_department'] ?? '')]));
$trFrom = static fn (array $t): string => trim(((string) ($t['from_branch'] ?? '')) . ' ' . ((string) ($t['from_company'] ?? '')));

$export = ['title' => 'Transfers' . ($trState !== '' ? ' — ' . $trState : ''), 'filter' => $trFilter + ($trState !== '' ? ['status' => $trState] : []),
    'columns' => ['CODE', 'EMPLOYEE', 'TYPE', 'FROM', 'TO', 'EFFECTIVE', 'REASON', 'STATUS', 'RAISED', 'DECIDED BY', 'DECIDED', 'ORDER SENT', 'ACKNOWLEDGED', 'APPLIED'],
    'rows' => []];

foreach ($trRows as $t) {
    $e = $trById[(string) $t['employee_id']];
    $export['rows'][] = [Employment::code($e), trim((string) ($e['name'] ?? '')), $trTypeLabel((string) ($t['type'] ?? '')), $trFrom($t), $trTo($t),
        (string) ($t['effective_date'] ?? ''), (string) ($t['reason'] ?? ''), (string) ($t['status'] ?? ''), substr((string) ($t['created_at'] ?? ''), 0, 16),
        (string) ($t['decided_by'] ?? ''), substr((string) ($t['decided_at'] ?? ''), 0, 16), substr((string) ($t['order_sent_at'] ?? ''), 0, 16),
        substr((string) ($t['accepted_at'] ?? ''), 0, 16), substr((string) ($t['applied_at'] ?? ''), 0, 16)];
}
