<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\OrgRename;
use SmartPRS\Surface\Store;

/** 28 Sep: a unit renamed in Organization Setup is renamed in every rule that names it. */
group('org rename');

$store = (new Store(sys_get_temp_dir() . '/prs3-rename-' . bin2hex(random_bytes(4))))->forTenant('1');
$c = new Customisation($store);
$store->put('salary_component_rules', ['component_code' => 'HRA', 'scope_branch' => 'Kondapur', 'scope_designation' => 'Analyst, Lead',
    'detail' => json_encode(['scope_department' => 'Collections'])]);
$store->put('payslip_formats', ['name' => 'F1', 'scope_branch' => 'kondapur']);
$c->saveRaw('leave-policy', ['types' => [['code' => 'KONDAPUR', 'scope' => ['branch' => 'Kondapur', 'department' => 'Collections']]]], 'u', 'U', 'seed');
$c->saveRaw('calendar', ['holidays' => [['name' => 'Kondapur', 'scope' => ['branch' => ['Kondapur', 'Gachibowli']]]]], 'u', 'U', 'seed');

$r = new OrgRename($store, $c);
ok($r->rename('branch', 'Kondapur', 'Kondapur HQ', 'u', 'U') >= 4, 'rules, formats and settings were updated');
$rule = $store->all('salary_component_rules')[0];
same('Kondapur HQ', $rule['scope_branch'], 'a salary rule follows');
same('Kondapur HQ', $store->all('payslip_formats')[0]['scope_branch'], 'a payslip format follows, whatever the case');
same('Kondapur HQ', $c->live()['leave-policy']['types'][0]['scope']['branch'], 'a leave type\'s scope follows');
same('KONDAPUR', $c->live()['leave-policy']['types'][0]['code'], 'but a leave type CODE that happens to match is not a branch and is untouched');
same(['Kondapur HQ', 'Gachibowli'], $c->live()['calendar']['holidays'][0]['scope']['branch'], 'a holiday list under a branch key follows');
same('Kondapur', $c->live()['calendar']['holidays'][0]['name'], 'a holiday NAMED like the branch is untouched');

$r->rename('designation', 'Lead', 'Team Lead', 'u', 'U');
same('Analyst,Team Lead', $store->all('salary_component_rules')[0]['scope_designation'], 'one item of a designation list follows');
$r->rename('department', 'Collections', 'Recovery', 'u', 'U');
same('Recovery', json_decode($store->all('salary_component_rules')[0]['detail'], true)['scope_department'], 'a department scope inside detail follows');
same(0, $r->rename('branch', 'Nowhere', 'Somewhere', 'u', 'U'), 'renaming a name nothing uses changes nothing');
