<?php

declare(strict_types=1);

use SmartPRS\Surface\SaasPlatform;
use SmartPRS\Surface\Store;

/** Owner, 13 Nov 2026: Plans & Pricing shows the SmartPRS 3.0 price list and edits it — never blank. */
group('plans from price list');

$st = new Store(sys_get_temp_dir() . '/pl' . getmypid());
$saas = new SaasPlatform($st, __DIR__ . '/../config');
$packages = [['name' => 'Starter', 'employees' => '25', 'monthly' => '1500', 'extra' => '60'], ['name' => 'Growth', 'employees' => 50, 'monthly' => 2500, 'extra' => 50]];

$saas->syncPackages($packages);
$rows = $st->all('plans');
same(2, count($rows), 'an empty plans list gets one row per price-list package');
$starter = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === 'Starter'))[0];
same([true, true, 25, 'offered'], [\SmartPRS\Surface\Amount::same($starter['base_price'], '1500'), \SmartPRS\Surface\Amount::same($starter['per_user_price'], '60'), (int) $starter['seat_max'], $starter['status']], 'price, extra, employees come from the price list');

$st->put('plans', ['id' => $starter['id'], 'features' => json_encode(['modules' => ['payroll']])]);
$packages[0]['monthly'] = '1800';
$saas->syncPackages($packages);
$rows = $st->all('plans');
$starter = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === 'Starter'))[0];
same(2, count($rows), 'a price change updates the row, never a second Starter');
same(true, \SmartPRS\Surface\Amount::same($starter['base_price'], '1800'), 'the new monthly price is on the plan');
same(['payroll'], SaasPlatform::planModules($starter), 'ticked modules are kept');
