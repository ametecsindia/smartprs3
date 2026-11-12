<?php

declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Regularisation;
use SmartPRS\Surface\Store;

group('regularisation hierarchy');

// EVERYONE BELOW, ALL THE WAY DOWN (21 Sep 2026): reporting managers and team leads.
$store = (new Store(sys_get_temp_dir() . '/prs3-rh-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'H-1', 'name' => 'Head']);
$store->put('employees', ['id' => '2', 'emp_code' => 'M-2', 'name' => 'Manager', 'reporting_manager_id' => '1']);
$store->put('employees', ['id' => '3', 'emp_code' => 'L-3', 'name' => 'Team lead', 'reporting_manager_id' => '2']);
$store->put('employees', ['id' => '4', 'emp_code' => 'E-4', 'name' => 'Agent', 'team_id' => 't1']);
$store->put('employees', ['id' => '5', 'emp_code' => 'X-5', 'name' => 'Elsewhere']);
$store->put('employees', ['id' => '6', 'emp_code' => 'C-6', 'name' => 'Cycle', 'reporting_manager_id' => '6']);
$store->put('teams', ['id' => 't1', 'name' => 'Alpha', 'leader_id' => '3']);

$reg = (new ReflectionClass(Regularisation::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Regularisation::class, 'store'))->setValue($reg, $store);
$codes = static fn (array $rows): array => (static function (array $c): array { sort($c); return $c; })(array_map(static fn (array $e): string => Employment::code($e), $rows));

same(['E-4', 'L-3', 'M-2'], $codes($reg->hierarchyBelow('1')), 'the head sees the manager, the team lead under them, and the team lead\'s team member');
same(['E-4'], $codes($reg->hierarchyBelow('3')), 'a team lead sees their team');
same([], $codes($reg->hierarchyBelow('4')), 'an agent with nobody below sees nobody');
same([], $codes($reg->hierarchyBelow('6')), 'a self-reporting cycle does not loop or include oneself');
