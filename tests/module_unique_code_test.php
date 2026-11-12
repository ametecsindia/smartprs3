<?php

declare(strict_types=1);

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Store;

group('module unique code');

$store = (new Store(sys_get_temp_dir() . '/prs3-uniq-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('depts', ['id' => '1', 'name' => 'Recoveries Operations', 'code' => 'RO', 'company' => 'A']);
$store->put('depts', ['id' => '2', 'name' => 'Old', 'code' => 'HR', 'status' => 'inactive']);
$registry = sys_get_temp_dir() . '/prs3-uniq-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($registry, json_encode(['modules' => [
    ['key' => 'depts', 'label' => 'Departments', 'group' => 'Org', 'table' => 'depts', 'columns' => ['name', 'code'], 'search' => ['name'], 'order' => 'name',
        'fields' => [['name' => 'name', 'label' => 'Department Name'], ['name' => 'code', 'label' => 'Code'], ['name' => 'company', 'label' => 'Company'], ['name' => 'status', 'label' => 'Status']]],
]]));
$modules = new Modules($store, $registry);
$m = $modules->find('depts');

$r = $modules->save($m, ['name' => 'Recoveries Operations', 'code' => 'ro', 'company' => 'B']);
ok(!$r['ok'], 'the same code under another company is refused');
ok(str_contains($r['why'], 'Code "ro" is already used by Recoveries Operations'), 'and the message names the code and who holds it: ' . $r['why']);
ok($modules->save($m, ['name' => 'Recoveries Operations', 'code' => 'RO2', 'company' => 'B'])['ok'], 'the same NAME with a different code is fine');
ok($modules->save($m, ['id' => '1', 'name' => 'Recoveries Ops', 'code' => 'RO'])['ok'], 'editing a row keeps its own code');
ok($modules->save($m, ['name' => 'People', 'code' => 'HR'])['ok'], 'a retired row does not hold its code');

group('team manager/leader by designation');
$store->put('designations', ['id' => '10', 'name' => 'Recovery Manager']);
$store->put('designations', ['id' => '11', 'name' => 'Collections Team Leader']);
$store->put('employees', ['id' => '1', 'name' => 'Asha', 'designation_id' => '10']);
$store->put('employees', ['id' => '2', 'name' => 'Ravi', 'designation_id' => '11']);
$store->put('employees', ['id' => '3', 'name' => 'Kiran', 'designation' => 'Branch Manager']);
$store->put('employees', ['id' => '4', 'name' => 'Dev', 'designation_id' => '11', 'deleted_at' => '2026-01-01']);
$store->put('employees', ['id' => '5', 'name' => 'Tele', 'designation' => 'Tele-caller']);
file_put_contents($registry, json_encode(['modules' => [
    ['key' => 'teams', 'label' => 'Teams', 'group' => 'People', 'table' => 'teams', 'columns' => ['name', 'manager_id', 'leader_id'], 'search' => ['name'], 'order' => 'name',
        'fields' => ['name' => ['label' => 'Name'], 'manager_id' => ['label' => 'Manager', 'lookup' => ['table' => 'employees', 'show' => ['name'], 'designation' => 'manager']],
            'leader_id' => ['label' => 'Leader', 'lookup' => ['table' => 'employees', 'show' => ['name'], 'designation' => 'team leader']]]],
]]));
$modules = new Modules($store, $registry);
$names = static fn (string $col): array => array_column(array_values(array_filter($modules->fields($modules->find('teams'))['fields'], static fn (array $f): bool => $f['name'] === $col))[0]['lookup'], 'label');
same(['Asha', 'Kiran'], $names('manager_id'), 'Manager lists managers by register designation or legacy title, alphabetically');
same(['Ravi'], $names('leader_id'), 'Team Leader lists team leaders, not the deleted one');

group('declared range');
file_put_contents($registry, json_encode(['modules' => [
    ['key' => 'shifts', 'label' => 'Shifts', 'group' => 'Time', 'table' => 'shifts2', 'columns' => ['name', 'grace_min'], 'search' => ['name'], 'order' => 'name',
        'fields' => ['name' => ['label' => 'Shift'], 'grace_min' => ['label' => 'Grace (minutes)', 'min' => 0, 'max' => 240], 'hours' => ['label' => 'Hours', 'min' => 0, 'max' => 24]]],
]]));
$modules = new Modules($store, $registry);
$m = $modules->find('shifts');
$r = $modules->save($m, ['name' => 'Afternoon', 'grace_min' => '-2']);
ok(!$r['ok'] && str_contains($r['why'], 'Grace (minutes) cannot be below 0 — got -2.'), 'a negative grace is refused by name: ' . $r['why']);
ok(!$modules->save($m, ['name' => 'Afternoon', 'grace_min' => 'ten'])['ok'], 'and so is a word');
ok(!$modules->save($m, ['name' => 'Afternoon', 'hours' => '24.5'])['ok'], 'and 24.5 hours');
ok($modules->save($m, ['name' => 'Afternoon', 'grace_min' => '10', 'hours' => '4.5'])['ok'], '10 minutes and 4.5 hours are fine');
same(-1, Modules::decimalCompare('-0.5', '0'), 'decimal compare handles a negative fraction');
same(1, Modules::decimalCompare('24.5', '24'), 'and a fraction above a whole');
