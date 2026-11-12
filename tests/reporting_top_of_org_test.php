<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\EmployeeExtras;
use SmartPRS\Surface\EmployeeFields;
use SmartPRS\Surface\EmployeeImport;
use SmartPRS\Surface\Scope;
use SmartPRS\Surface\Store;

/**
 * THE TOP OF THE ORGANISATION REPORTS TO NOBODY — owner, 24 Sep.
 *
 * Reporting Manager became mandatory on 23 Sep. That left the person at the top
 * with no truthful answer, so somebody picked a subordinate and the data grew a
 * two-person ring (AI2001 ⇄ AIPL-1002), which the reporting tree then tripped
 * over on every page. The field keeps its `required`; it gains ONE declared
 * answer that means "nobody", chosen on purpose, which is stored as no manager.
 *
 * And a ring that is already in the data denies the line through it instead of
 * killing the page.
 */

group('reporting manager · top of the organisation');

$dir = sys_get_temp_dir() . '/prs3-top-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('branches', ['id' => '4', 'name' => 'KONDAPUR']);
$store->put('employees', ['id' => '11', 'emp_code' => 'MGR-004', 'name' => 'Ravi Kumar', 'branch_id' => '4']);

$fields = new EmployeeFields($store, new Customisation($store), dirname(__DIR__) . '/config/employee-fields.json');

$rm = null;
foreach ($fields->inUse() as $f) {
    if ((string) $f['code'] === 'reporting_manager_id') {
        $rm = $f;
    }
}

ok($rm !== null, 'Reporting Manager is on the form');
same(true, (bool) ($rm['required'] ?? false), 'and is still required');
$none = (string) ($rm['none_option']['value'] ?? '');
ok($none !== '', 'the catalogue declares a "no one" answer for it');
ok(trim((string) ($rm['none_option']['label'] ?? '')) !== '', 'with a label a person can read');

$import = new EmployeeImport($store, $fields, new EmployeeExtras($store), $dir . '/spool',
    ['branches' => ['4' => 'KONDAPUR'], 'employees' => ['11' => 'Ravi Kumar']]);
$head = "emp_code,name,branch_id,reporting_manager_id\n";

$top = $import->parse($head . "TOP-1,Founder,KONDAPUR,{$none}\n");
same(1, $top['ok'], 'an upload row saying "no one" imports');
same(0, $top['errors'], 'and is not refused as a missing Reporting Manager');
same([true, null], [array_key_exists('reporting_manager_id', $top['rows'][0]['values']), $top['rows'][0]['values']['reporting_manager_id'] ?? null],
    'and is stored as no manager, not as the word');

$lower = $import->parse($head . 'TOP-2,Founder Two,KONDAPUR,' . strtolower($none) . "\n");
same(1, $lower['ok'], 'the answer is not case-sensitive in a sheet');

$blank = $import->parse($head . "TOP-3,Nobody Chose,KONDAPUR,\n");
same(0, $blank['ok'], 'an EMPTY cell is still refused — "no one" has to be chosen');

group('scope · a reporting ring denies, it does not crash');

$ring = ['56' => '57', '57' => '56', '10' => '1'];
$scope = new Scope('1', static fn (string $id): ?string => $ring[$id] ?? null);

$threw = null;
try {
    $inLine = $scope->inMyLine('56');
} catch (\Throwable $e) {
    $threw = $e;
}

same(null, $threw === null ? null : $threw::class, 'a ring below an account does not throw');
same(false, $inLine ?? null, 'and the ring is outside the line');
same(true, $scope->inMyLine('10'), 'a healthy line beside it still resolves');
same(false, $scope->covers('56', false), 'no permission: the ring is not readable through the line');
same(['56'], array_map('strval', $scope->cycledEmployments()), 'and the ring is recorded for the screen to name');
