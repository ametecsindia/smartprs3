<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Store;

group('leave type scope');

// A LEAVE TYPE FOR SOME OF THE ORGANISATION (21 Sep 2026): Company, Branch,
// Department, Role, Designation on the Leave Types screen, every named one must match.
$store = (new Store(sys_get_temp_dir() . '/prs3-lts-' . bin2hex(random_bytes(4))))->forTenant('1');
$policy = new LeavePolicy(dirname(__DIR__) . '/config/leave-policy.json', new Customisation($store));
$saved = $policy->saveTypes([
    ['code' => 'CL', 'name' => 'Casual leave', 'paid' => 'yes', 'opening_days' => '12', 'accrual_days_per_month' => '0', 'maximum_days' => '12', 'carry_forward' => 'no', 'encashable' => 'no'],
    ['code' => 'CO', 'name' => 'Compensatory off', 'paid' => 'yes', 'opening_days' => '0', 'accrual_days_per_month' => '0', 'maximum_days' => '30', 'carry_forward' => 'yes', 'encashable' => 'no',
        'scope' => ['role' => 'Employee', 'department' => 'Collections']],
], '1', 'hr');
ok($saved['ok'], 'a type with a department and role scope saves: ' . $saved['why']);
same(['department' => 'Collections', 'role' => 'Employee'], array_intersect_key((array) ($policy->types()['CO']['scope'] ?? []), ['role' => 1, 'department' => 1]), 'the scope is kept, department included');

$inside = ['role' => 'Employee', 'department' => 'Collections', 'branch' => 'Vizag'];
$otherDept = ['role' => 'Employee', 'department' => 'HR'];
$manager = ['role' => 'Manager', 'department' => 'Collections'];
ok($policy->eligible('CO', $inside), 'an Employee in Collections gets CO');
ok(!$policy->eligible('CO', $otherDept), 'another department does not');
ok(!$policy->eligible('CO', $manager), 'nor another role');
ok(str_contains((string) $policy->ineligibleReason('CO', $otherDept), 'Department'), 'and the reason names the department');
ok($policy->eligible('CL', $manager), 'a type left at ALL applies to everybody');
same(['CL'], array_keys($policy->typesFor($manager)), 'so the manager is offered CL only');
