<?php

declare(strict_types=1);

/**
 * ADMINISTRATION — what computes behind the hand-built screens.
 *
 * The role matrix must agree with the guards (a permission the administrator
 * holds shows as held, one nobody holds shows as nobody's); a phone can only
 * make the moves config/mobile-devices.json allows; a licence and a
 * subscription say how many days they have left; the rule-file inventory
 * reports a customised file as customised.
 */

use SmartPRS\Platform\Access\PermissionCatalogue;
use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Licensing;
use SmartPRS\Surface\MobileDevices;
use SmartPRS\Surface\RoleMatrix;
use SmartPRS\Surface\RuleFiles;
use SmartPRS\Surface\Store;

group('admin-section');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-admin-' . bin2hex(random_bytes(4))))->forTenant('1');

// ---- roles & permissions matrix, derived from Authorisation ----------------
$access = new Authorisation();
// RoleMatrix is now a per-code four-state grid (10 Sep); the old matrix() rows are gone.
$matrix = new RoleMatrix($access, PermissionCatalogue::seeded());
$codes = PermissionCatalogue::seeded()->declaredCodes();
ok(in_array('payroll.run', $codes, true) && in_array('employee.view', $codes, true), 'payroll and employee codes are rows');
ok($matrix->state('administrator', 'payroll.run') !== 'none', 'the administrator holds payroll.run, as the guard says');
same([], array_values(array_filter($codes, static fn (string $c): bool => RoleMatrix::moduleOf($c) === 'payroll' && $matrix->state('manager', $c) !== 'none')),
    'the manager holds nothing in payroll, as Authorisation.php says');
ok($matrix->state('viewer', 'payroll.structure.view') !== 'none', 'the viewer may see the structure');
foreach ($access->roles() as $role) {
    foreach ($codes as $code) {
        if ($matrix->state($role, $code) !== 'none' && !$access->allows($role, $code)) { ok(false, "matrix says {$role} holds {$code} but the decider refuses"); }
    }
}
ok(!$matrix->isDeclared('report.attendance.view'), 'no minted per-artefact code is listed');

// ---- mobile devices: moves as declared -------------------------------------
$devices = new MobileDevices($store, $root . '/config/mobile-devices.json');
same(['approve', 'reject'], $devices->movesFrom('pending'), 'a pending phone can be approved or rejected');
same(['revoke'], $devices->movesFrom('approved'), 'an approved one can only be revoked');
same(['approve'], $devices->movesFrom('rejected'), 'a rejected one can be approved after all');
same([], $devices->movesFrom('revoked'), 'a revoked one is final');

ok(!$devices->register(['device_id' => ''], '2026-09-09 10:00:00')['ok'], 'a device needs an id');
$reg = $devices->register(['device_id' => 'IMEI-1', 'employee_id' => '7', 'model' => 'Redmi', 'platform' => 'Android'], '2026-09-09 10:00:00');
ok($reg['ok'], 'registered');
same('pending', $reg['row']['status'], 'as pending');
ok(!$devices->register(['device_id' => 'IMEI-1'], '2026-09-09 10:01:00')['ok'], 'the same device id cannot register twice');

$row = $reg['row'];
ok(!$devices->decide($row, 'revoke', '', 'admin', '2026-09-09 11:00:00')['ok'], 'a pending phone cannot be revoked');
ok(!$devices->decide($row, 'reject', '', 'admin', '2026-09-09 11:00:00')['ok'], 'rejecting needs a reason');
$rej = $devices->decide($row, 'reject', 'Not a company phone', 'admin', '2026-09-09 11:00:00');
ok($rej['ok'], 'rejected with a reason');
same('rejected', $rej['row']['status'], 'status moved');
same('Not a company phone', $rej['row']['reject_reason'], 'the reason is on the row for the phone to show');
$app = $devices->decide($rej['row'], 'approve', '', 'admin', '2026-09-09 12:00:00');
same('approved', $app['row']['status'], 'approved after rejection');
same('admin', $app['row']['approved_by'], 'who');
$rev = $devices->decide($app['row'], 'revoke', '', 'admin', '2026-09-09 13:00:00');
same('revoked', $rev['row']['status'], 'revoked');
same('Access revoked by your administrator.', $rev['row']['reject_reason'], 'the declared default reason when none is typed');
ok(!$devices->decide($rev['row'], 'approve', '', 'admin', '2026-09-09 14:00:00')['ok'], 'and stays revoked');
ok(!$devices->decide($row, 'lose', '', 'admin', '2026-09-09 14:00:00')['ok'], 'an undeclared move is refused');
same('revoked', $store->find('mobile_devices', (string) $row['id'])['status'], 'the store has the final status');

// ---- licensing: days left ---------------------------------------------------
same(21, Licensing::daysBetween('2026-09-09', '2026-09-30'), 'whole days ahead');
same(-9, Licensing::daysBetween('2026-09-09', '2026-08-31'), 'negative once passed');
same(null, Licensing::subscription([], [], '2026-09-09', 5), 'no subscription row: nothing to show');
$sub = Licensing::subscription([
    ['plan_id' => '2', 'seats' => 50, 'status' => 'expired', 'current_period_end' => '2025-12-31'],
    ['plan_id' => '3', 'seats' => 40, 'status' => 'active', 'current_period_end' => '2026-10-09', 'cycle' => 'yearly'],
], [['id' => '2', 'name' => 'Starter'], ['id' => '3', 'name' => 'Growth']], '2026-09-09', 36);
same('Growth', $sub['plan'], 'the latest period wins, whatever order the rows came in');
same(30, $sub['days_left'], 'days left on the period');
same(4, $sub['seats_left'], 'seats bought minus active employments');
same(-2, Licensing::subscription([['plan_id' => '9', 'seats' => 10, 'current_period_end' => '2026-12-31']], [], '2026-09-09', 12)['seats_left'], 'over the seat count shows as negative');
same('Plan #9', Licensing::subscription([['plan_id' => '9', 'seats' => 0, 'current_period_end' => '']], [], '2026-09-09', 1)['plan'], 'an unknown plan is named by id, not blank');
same(null, Licensing::subscription([['plan_id' => '9', 'seats' => 0, 'current_period_end' => '']], [], '2026-09-09', 1)['seats_left'], 'zero seats means unlimited');
$lic = Licensing::licence([
    ['id' => '1', 'edition' => 'PRO', 'key_last4' => 'AB12', 'activated_at' => '2025-01-01 00:00:00', 'amc_expires_on' => '2026-08-31', 'status' => 'active'],
    ['id' => '2', 'edition' => 'ENT', 'key_last4' => 'ZZ99', 'activated_at' => '2026-01-01 00:00:00', 'amc_expires_on' => '2027-01-01', 'status' => 'active'],
], '2026-09-09');
same('ENT', $lic['edition'], 'the most recently activated licence applies');
same(114, $lic['amc_days_left'], 'AMC days left');
same('ZZ99', $lic['key_last4'], 'only the last four of the key are ever surfaced');

// ---- rule files: customisation status ---------------------------------------
$customisation = new Customisation($store);
$files = (new RuleFiles($root . '/config', $customisation))->inventory();
$byFile = [];
foreach ($files as $f) { $byFile[$f['file']] = $f; }
ok(isset($byFile['probation']) && $byFile['probation']['key'] === 'probation', 'probation.json names its customisation key');
ok(!$byFile['probation']['customised'], 'not customised yet');
ok(isset($byFile['navigation']) && $byFile['navigation']['key'] === null, 'a file with no key is a product rule');
$customisation->saveRaw('probation', ['default_months' => 3], 'u1', 'hr@example', 'shorter');
$files = (new RuleFiles($root . '/config', new Customisation($store)))->inventory();
same('probation', $files[0]['file'], 'a customised file sorts first');
ok($files[0]['customised'], 'and is marked customised');
same(1, $files[0]['version'], 'at version 1');
same('hr@example', $files[0]['changed_by'], 'by whom');
