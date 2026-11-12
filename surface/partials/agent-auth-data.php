<?php declare(strict_types=1);
/**
 * AGENT AUTHORISATIONS — the rows. Every live agent_authorizations row joined
 * to its employee, scoped by the Organization Setup filter on the employee,
 * banded on valid_to against today with the window from
 * config/field-reports.json, expiring-first. Included by the screen and the CSV
 * action with $params set. Sets $cfg, $window, $today, $people, $filter,
 * $inView, $rows and $counts.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\FieldReports;

$cfg = FieldReports::config(__DIR__ . '/../../config/field-reports.json');
$window = (int) $cfg['expiry_window_days'];
$today = date('Y-m-d');
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$inView = [];

foreach (orgFilterApply($store, $people, $filter) as $e) {
    $inView[(string) $e['id']] = $e;
}

$rows = [];
$agentsById = [];

foreach (liveRows($store, 'offroll_agents') as $ag) {
    $agentsById[(string) $ag['id']] = $ag;
}

foreach (liveRows($store, 'agent_authorizations') as $a) {
    $e = $inView[(string) ($a['employee_id'] ?? '')] ?? null;

    // AN OFF-ROLL AGENT'S LETTER (23 Oct 2026): shown with the agent's code and name, when no Company /
    // Branch / … filter is narrowing the employees (the agent is not an employee and holds none of those).
    if ($e === null && trim((string) ($a['agent_id'] ?? '')) !== '' && array_filter($filter) === []) {
        $ag = $agentsById[(string) $a['agent_id']] ?? null;
        $ag === null || $e = ['id' => '', 'agent' => $ag];
    }

    if ($e === null) {
        continue; // an authorisation of somebody outside the filter, or of a retired employee
    }

    $rows[] = [
        'id' => (string) ($a['id'] ?? ''),
        'employee_id' => (string) $e['id'],
        'code' => isset($e['agent']) ? (trim((string) ($e['agent']['agent_code'] ?? '')) ?: 'OFR-' . str_pad((string) $e['agent']['id'], 4, '0', STR_PAD_LEFT)) : Employment::code($e),
        'name' => isset($e['agent']) ? (string) ($e['agent']['name'] ?? '') . ' (off-roll)' : (string) ($e['name'] ?? ''),
        'bank' => (string) ($a['bank'] ?? ''),
        'portfolio' => (string) ($a['portfolio'] ?? ''),
        'auth_no' => (string) ($a['auth_no'] ?? ''),
        'valid_from' => substr((string) ($a['valid_from'] ?? ''), 0, 10),
        'valid_to' => substr((string) ($a['valid_to'] ?? ''), 0, 10),
        'status' => (string) ($a['status'] ?? ''),
    ];
}

$rows = FieldReports::expiringFirst($rows, 'valid_to', $today, $window);
$counts = FieldReports::bandCounts($rows);
