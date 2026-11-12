<?php declare(strict_types=1);
/**
 * MOBILE DEVICES — the rows. Included by surface/screens/mobile-devices.php and
 * by surface/actions/people-export.php with $params set (status filter `s`).
 * Sets $mdDevices, $mdDeclared, $mdStatuses, $mdPeople, $mdStatus, $mdAll,
 * $mdRows and $export.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\MobileDevices;

$export = null;

if (!$may('tenant.settings.view')) {
    return;
}

$mdDevices = new MobileDevices($store, __DIR__ . '/../../config/mobile-devices.json');
$mdDeclared = $mdDevices->declared();
$mdStatuses = (array) $mdDeclared['statuses'];
$mdPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $mdPeople[(string) $e['id']] = Employment::code($e) . ' ' . (string) ($e['name'] ?? '');
}

$mdAll = liveRows($store, (string) $mdDeclared['table']);
usort($mdAll, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$mdStatus = isset($mdStatuses[(string) ($params['s'] ?? '')]) ? (string) $params['s'] : '';
$mdRows = array_values(array_filter($mdAll, static fn (array $r): bool => $mdStatus === '' || (string) $r['status'] === $mdStatus));

$export = ['title' => 'Mobile Devices' . ($mdStatus !== '' ? ' — ' . $mdStatuses[$mdStatus]['label'] : ''), 'filter' => $mdStatus !== '' ? ['status' => $mdStatus] : [],
    'columns' => ['DEVICE', 'MODEL', 'PLATFORM', 'OS', 'DEVICE ID', 'EMPLOYEE', 'CODE', 'PUSH', 'REGISTERED', 'LAST SEEN', 'STATUS', 'DECIDED BY', 'DECIDED', 'REASON'],
    'rows' => array_map(static fn (array $r): array => [(string) ($r['device_name'] ?? ''), (string) ($r['model'] ?? ''), (string) ($r['platform'] ?? ''), (string) ($r['os_version'] ?? ''),
        (string) $r['device_id'], $mdPeople[(string) ($r['employee_id'] ?? '')] ?? '', (string) ($r['code'] ?? ''), trim((string) ($r['push_token'] ?? '')) !== '' ? 'Yes' : 'No',
        substr((string) ($r['created_at'] ?? ''), 0, 16), substr((string) ($r['last_seen_at'] ?? ''), 0, 16), (string) ($mdStatuses[(string) $r['status']]['label'] ?? $r['status']),
        (string) ($r['approved_by'] ?? ''), substr((string) ($r['approved_at'] ?? ''), 0, 16), (string) ($r['reject_reason'] ?? '')], $mdRows)];
