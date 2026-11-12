<?php

declare(strict_types=1);

/**
 * TIME & ATTENDANCE — the drop-in declarations line up with the schema they
 * need and the menu items they promise.
 */

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Navigation;
use SmartPRS\Surface\Store;

group('time section: modules');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-time-' . bin2hex(random_bytes(4))))->forTenant('1');
$modules = new Modules($store, $root . '/config/modules.json');

// The drop-in SUPERSEDES the shipped `devices`: SmartPRS 2's DEVICE_FIELDS are there.
$devices = (array) $modules->find('devices');
foreach (['brand', 'model', 'device_type', 'connect_mode', 'ip_address', 'port', 'notes', 'unique_id', 'direction', 'status'] as $c) {
    ok(isset($devices['fields'][$c]), "devices declares $c");
}
same('password', $devices['fields']['api_key']['type'] ?? '', 'the comm key is never echoed back');

$bio = (array) $modules->find('biometric-config');
foreach (['provider', 'serial_number', 'sync_interval_min', 'emp_prefix', 'device_mode', 'in_machine_id', 'out_machine_id', 'api_config', 'branch_id'] as $c) {
    ok(isset($bio['fields'][$c]), "biometric-config declares $c");
}
same('tenant.settings.update', $bio['permissions']['edit'] ?? '', 'device credentials are edited under the settings permission');

$ot = (array) $modules->find('overtime');
same('overtime', $ot['table'] ?? '', 'the register is over the ported overtime table');
same(['1', '1.5', '2'], $ot['fields']['rate']['options'] ?? [], "SmartPRS 2's multipliers");
same(['Pending', 'Approved', 'Paid'], $ot['fields']['status']['options'] ?? [], "SmartPRS 2's statuses, in the dropdowns' capitalised form (13 Oct)");
ok(str_contains((string) ($ot['description'] ?? ''), 'overtimeMinutes'), 'the OT note says where the hours come from');
same('employees', $ot['fields']['employee_id']['lookup']['table'] ?? '', 'the employee is picked, not typed as a number');
same('employees', ((array) $modules->find('geofence'))['fields']['employee_id']['lookup']['table'] ?? '', 'geofence picks the employee too');

// EVERY DECLARED FIELD IS A COLUMN the migration created or the port already had.
// A declared field the table lacks is shown as "unknown" on the screen; here it
// is a failure, checked against the column names the migration adds.
$migration = (string) file_get_contents($root . '/database/migrations/2026_03_02_000100_time_device_and_overtime_columns.php');
$ported = [
    'devices' => ['employer_company_id', 'branch_id', 'name', 'unique_id', 'direction', 'api_url', 'api_key', 'location', 'last_sync_at', 'status'],
    'biometric_configs' => ['provider', 'enabled', 'base_url', 'endpoint', 'corp_id', 'username', 'password', 'empcode', 'emp_prefix', 'in_machine_id', 'out_machine_id', 'last_sync_at', 'last_status', 'last_count', 'label', 'branch', 'device_mode'],
    'overtime' => ['employee_id', 'ot_date', 'hours', 'rate', 'amount', 'approved', 'status', 'remarks', 'paid_run_id'],
    'geofence_rules' => ['employee_id', 'start', 'lat', 'lng', 'radius_km', 'outside'],
];

foreach (['devices', 'biometric-config', 'overtime', 'geofence'] as $key) {
    $m = (array) $modules->find($key);
    $table = (string) $m['table'];
    preg_match("/\\\$add\\('" . preg_quote($table, '/') . "', \\[(.*?)\\]\\);/s", $migration, $block);
    preg_match_all("/'([a-z_]+)' => '/", $block[1] ?? '', $added);
    // ... plus the columns 2026_03_02_001100 added for SmartPRS 2 register parity (comm_key, multiplier).
    preg_match("/'" . preg_quote($table, '/') . "' => \\[(.*?)\\]/s", (string) file_get_contents($root . '/database/migrations/2026_03_02_001100_prs2_register_fields.php'), $parity);
    preg_match_all("/'([a-z_]+)' => '/", $parity[1] ?? '', $parityAdded);
    $columns = array_merge($ported[$table], $added[1], $parityAdded[1]);

    foreach (array_keys((array) $m['fields']) as $f) {
        ok(in_array($f, $columns, true), "$key.$f is a column of $table");
    }

    foreach ((array) $m['columns'] as $c) {
        ok(isset($m['fields'][$c]), "$key lists $c and declares it");
    }
}

group('time section: menu');

$sections = (new Navigation($root . '/config/navigation.json'))->sections();
$time = array_values(array_filter($sections, static fn (array $s): bool => ($s['key'] ?? '') === 'time'))[0] ?? [];
$keys = array_column((array) ($time['items'] ?? []), 'key');
ok(in_array('overtime', $keys, true), 'Overtime Register is on the Time & Attendance menu');
// Bulk upload lives on Manual Entry now: one screen, the single-punch card above the bulk sheet.
ok(in_array('manual-entry', $keys, true), 'Manual Entry (single punch + Bulk Attendance Upload) is on the Time & Attendance menu');
ok(str_contains((string) file_get_contents($root . '/public/app.php'), "if (\$route === 'manual-entry') {\n    \$route = 'attendance-bulk';"),
    'and Manual Entry opens the bulk upload screen');
same(1, count(array_keys($keys, 'devices', true)), 'the superseded devices module is listed once, not twice');
