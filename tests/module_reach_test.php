<?php

declare(strict_types=1);

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Scope;
use SmartPRS\Surface\Store;

/** Owner, 28 Sep: generic module screens obey the viewer's reach; employee pickers never list strangers; no blank, no duplicate options. */
group('module reach');

$store = (new Store(sys_get_temp_dir() . '/prs3-modreach-' . bin2hex(random_bytes(4))))->forTenant('1');
foreach ([['1', 'E1', 'Agent One', '2'], ['2', 'E2', 'Team Lead', '3'], ['3', 'E3', 'Manager', ''], ['4', 'E4', 'Stranger', '']] as [$id, $code, $name, $rm]) {
    $store->put('employees', ['id' => $id, 'emp_code' => $code, 'name' => $name, 'reporting_manager_id' => $rm, 'status' => 'active']);
}
foreach (['1', '2', '4'] as $who) {
    $store->put('leaves', ['employee_id' => $who, 'from_date' => '2026-10-0' . $who, 'reason' => 'r' . $who]);
}
$store->put('branches', ['id' => 'B1', 'name' => 'Kondapur', 'employer_company_id' => 'C1']);
$store->put('companies', ['id' => 'C1', 'name' => 'Alpha']);
$store->put('companies', ['id' => 'C2', 'name' => 'Beta']);
$store->put('departments', ['id' => 'D1', 'name' => 'Operations', 'branch_id' => 'B1']);
$store->put('departments', ['id' => 'D2', 'name' => 'Operations', 'employer_company_id' => 'C2']);

$reportsTo = static fn (string $id): ?string => (string) ($store->find('employees', $id)['reporting_manager_id'] ?? '') ?: null;
$modulesFor = static function (?string $me, string $reach) use ($store, $reportsTo): Modules {
    $scope = new Scope($me, $reportsTo, null, $reach);
    $m = new Modules($store, dirname(__DIR__) . '/config/modules.json', null, static fn (string $p): bool => true);
    $m->restrictEmployees(static fn (array $row): bool => in_array((string) ($row['id'] ?? ''), ['', '__all__'], true)
        ? $scope->seesEveryone(true) : $scope->covers((string) $row['id'], true));

    return $m;
};
$leaves = ['key' => 'leaves', 'table' => 'leaves', 'columns' => ['employee_id', 'from_date'], 'search' => [], 'fields' => ['employee_id' => []]];
// Sorted: the register lists newest first, and WHO is listed is the question here, not the order.
$emps = static function (array $r): array { $ids = array_map(static fn (array $x): string => (string) $x['employee_id'], $r['rows']); sort($ids); return $ids; };

same(['1'], $emps($modulesFor('1', Scope::LINE)->rows($leaves)), 'an agent lists only their own leave rows');
same(['1', '2'], $emps($modulesFor('3', Scope::LINE)->rows($leaves)), 'a manager lists their whole downline, not the stranger');
same(3, $modulesFor(null, Scope::COMPANY)->rows($leaves)['total'], 'the administrator lists everybody');
ok(!$modulesFor('1', Scope::LINE)->rowVisible($leaves, ['employee_id' => '4']), 'a stranger\'s row is not visible by id');
same('employee_id', $modulesFor('1', Scope::LINE)->ownerColumn($leaves), 'the owner column is found');

$m = $modulesFor('2', Scope::LINE);
$picker = $m->fields(['key' => 'leaves', 'table' => 'leaves', 'columns' => ['employee_id'], 'fields' => ['employee_id' => []]]);
$labels = array_column($picker['fields'][0]['lookup'] ?? [], 'label');
same(['E1 — Agent One', 'E2 — Team Lead'], $labels, 'the employee dropdown offers only people in reach, as CODE — Name, never a number box');

$d = $modulesFor(null, Scope::COMPANY)->fields(['key' => 'designations', 'table' => 'designations', 'columns' => ['department_id'], 'fields' => ['department_id' => []]]);
same(['Operations (Beta)', 'Operations (Kondapur)'], array_column($d['fields'][0]['lookup'] ?? [], 'label'), 'two same-named departments are told apart by their parent');
