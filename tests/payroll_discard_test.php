<?php

declare(strict_types=1);

use SmartPRS\Surface\Register;
use SmartPRS\Surface\Store;

group('payroll discard');

// DELETING A GENERATED PAYROLL WHILE TESTING: the period is freed, nothing is erased.
$store = (new Store(sys_get_temp_dir() . '/prs3-pd-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('payroll_runs', ['id' => 'r5', 'cycle_label' => '2026-05', 'status' => 'closed', 'employees_count' => 3, 'net_total' => '100.0000']);
$store->put('payroll_runs', ['id' => 'r2', 'cycle_label' => '2026-02', 'status' => 'approved', 'employees_count' => 3, 'net_total' => '90.0000']);
$store->put('payroll_corrections', ['id' => 'c1', 'period' => '2026-05', 'corrects' => 'r5', 'reason' => 'x', 'deltas' => []]);
$store->put('payroll_corrections', ['id' => 'c2', 'period' => '2026-02', 'reason' => 'y', 'deltas' => [], 'settled_in' => '2026-05', 'settled_at' => '2026-06-01']);

// discard() and runs() touch only the store; the pricing collaborators are not needed.
$register = (new ReflectionClass(Register::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Register::class, 'store'))->setValue($register, $store);

ok(!$register->discard('2026-05', 'hr', '')['ok'], 'a reason is required');
ok(!$register->discard('2026-09', 'hr', 'x')['ok'], 'a period never generated cannot be deleted');
ok($register->discard('2026-05', 'hr', 'Testing')['ok'], 'a generated period is deleted');
same(null, $register->find('2026-05'), 'and is gone from the register, so it can be generated again');
same('2026-02', $register->runs()[0]['period'] ?? null, 'other periods are untouched');
same('discarded', $store->find('payroll_runs', 'r5')['status'] ?? null, 'the row itself is kept, marked discarded');
same([], $register->correctionsFor('2026-05'), 'corrections raised against it are parked with it');
same(null, $store->find('payroll_corrections', 'c2')['settled_in'] ?? null, 'arrears it had paid are owed again');
same('2026-05', $store->find('payroll_corrections', 'c1')['period'] ?? null,
    'its period column is left as it was (varchar 7: a "deleted" label does not fit and crashed MySQL)');
same('2026-05', $store->find('payroll_corrections', 'c1')['settled_in'] ?? null, 'parked as settled in its own period, so never paid as arrears');
$store->put('payroll_runs', ['id' => 'r5b', 'cycle_label' => '2026-05', 'status' => 'approved', 'employees_count' => 3, 'net_total' => '100.0000']);
same([], $register->correctionsFor('2026-05'), 'a regenerated month does not show the deleted run\'s corrections');
