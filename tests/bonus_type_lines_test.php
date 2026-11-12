<?php

declare(strict_types=1);

use SmartPRS\Surface\PayrollInputs;
use SmartPRS\Surface\Store;

group('bonus_type_lines');

// Owner, 5 Nov 2026: each bonus type its own payslip line, printed only in a month it is paid.
$store = (new Store(sys_get_temp_dir() . '/btl-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('bonus_encashment', ['employee_id' => '7', 'type' => 'Diwali Bonus', 'month' => '2026-11', 'basis' => 'fixed', 'amount' => '5000', 'status' => 'Approved']);
$store->put('bonus_encashment', ['employee_id' => '7', 'type' => 'Performance Bonus', 'month' => '2026-11', 'basis' => 'fixed', 'amount' => '3000', 'status' => 'Approved']);
$store->put('bonus_encashment', ['employee_id' => '7', 'type' => 'Leave Encashment', 'month' => '2026-11', 'basis' => 'fixed', 'amount' => '1200', 'status' => 'Approved']);
$store->put('bonus_encashment', ['employee_id' => '7', 'type' => 'Joining Bonus', 'month' => '2026-11', 'basis' => 'fixed', 'amount' => '9000', 'status' => 'Pending']);
$store->put('bonus_encashment', ['employee_id' => '7', 'type' => 'Diwali Bonus', 'month' => '2026-12', 'basis' => 'fixed', 'amount' => '0', 'status' => 'Approved']);
$in = new PayrollInputs($store, __DIR__ . '/../config/payroll-inputs.json');
$e = ['id' => '7', 'emp_code' => 'E7', 'salary_type' => 'Salary'];

$nov = array_column($in->forEmployment($e, '2026-11', '2026-11-01', '2026-11-30')['lines'], 'amount', 'code');
ok(isset($nov['BONUS:Diwali Bonus'], $nov['BONUS:Performance Bonus']), 'each paid bonus type is its own line: ' . implode(', ', array_keys($nov)));
ok(isset($nov['LEAVE_ENCASHMENT']), 'Leave Encashment keeps its own code');
ok(!isset($nov['BONUS:Joining Bonus']), 'a Pending bonus is not on the payslip');
ok(!isset($nov['BONUS']), 'no combined Bonus line when every row has a type');
$dec = $in->forEmployment($e, '2026-12', '2026-12-01', '2026-12-31')['lines'];
same([], array_values(array_filter($dec, static fn (array $l): bool => str_starts_with((string) $l['code'], 'BONUS'))), 'a zero bonus prints no line');
