<?php

declare(strict_types=1);

use SmartPRS\Surface\Leave;
use SmartPRS\Surface\Store;

group('leave approvals');

// THE LEAVE APPROVALS QUEUE reads Leave::pending(); an approver narrowing a
// request must not have that same request counted as "other pending" leave.
$store = (new Store(sys_get_temp_dir() . '/prs3-la-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'M-1', 'name' => 'Manager']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E-2', 'name' => 'Report', 'reporting_manager_id' => '1']);
$store->put('leave_types', ['id' => '7', 'name' => 'CL']);
$store->put('leaves', ['id' => 'a', 'employee_id' => '2', 'type_id' => '7', 'from_date' => '2026-10-05', 'to_date' => '2026-10-07', 'days' => '3', 'reason' => 'x', 'status' => 'pending', 'created_at' => '2026-09-02T10:00:00']);
$store->put('leaves', ['id' => 'b', 'employee_id' => '2', 'type_id' => '7', 'from_date' => '2026-11-02', 'to_date' => '2026-11-02', 'days' => '1', 'reason' => 'y', 'status' => 'pending', 'created_at' => '2026-09-01T10:00:00']);
$store->put('leaves', ['id' => 'c', 'employee_id' => '2', 'type_id' => '7', 'from_date' => '2026-08-03', 'to_date' => '2026-08-03', 'days' => '1', 'reason' => 'z', 'status' => 'approved', 'created_at' => '2026-08-01T10:00:00']);

$leave = new Leave($store);
same(['b', 'a'], array_map(static fn (array $r): string => (string) $r['id'], $leave->pending()), 'pending only, oldest first');
same('E-2', $leave->pending()[0]['employment_id'], 'each row names the employee by code');
same('4', (string) $leave->pendingDays('E-2', 'CL'), 'both pending requests count as pending');
same('1', (string) $leave->pendingDays('E-2', 'CL', 'a'), 'but not the one being decided');

$rejected = $leave->decide('b', false, 'Manager', 'Clash with audit week');
same('rejected', $rejected['state'], 'a rejection with a comment is recorded');
same(['a'], array_map(static fn (array $r): string => (string) $r['id'], $leave->pending()), 'and leaves the queue');
$threw = false;
try { $leave->decide('a', true, 'Manager', '   '); } catch (\InvalidArgumentException) { $threw = true; }
ok($threw, 'a decision without a comment is refused');
