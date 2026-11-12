<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\AttendanceBulk;
use SmartPRS\Surface\Store;

group('attendance bulk row decisions');

// Owner, 12 Oct: on Manual Entry an approver decides each row — Approve or Reject —
// not only the whole batch; Withdraw is the uploader taking back their own row.
$store = (new Store(sys_get_temp_dir() . '/bulkrow-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'E1', 'name' => 'Asha Rao', 'status' => 'active']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E2', 'name' => 'Ravi Kumar', 'status' => 'active']);
$bulk = new AttendanceBulk($store, new Attendance($store));
$up = $bulk->upload("emp_code,employee_name,date,in_time,out_time\nE1,Asha Rao,2026-10-01,09:30,18:00\nE2,Ravi Kumar,2026-10-01,09:35,18:10\nE2,Ravi Kumar,2026-10-02,09:30,24:03\n",
    'hr@x', new DateTimeImmutable('2026-10-05'));
$rows = $bulk->rows((string) $up['batch']);
[$r1, $r2, $bad] = $rows;
same('error', (string) $bad['status'], 'a 24:03 out-time is refused at upload');

ok($bulk->decideRow((string) $r1['id'], true, 'admin@x')['ok'], 'one pending row is approved on its own');
same(2, count($store->all('attendance_logs')), 'its two punches are written, and only its');
same('pending', (string) $bulk->rows((string) $up['batch'])[1]['status'], 'the other row still waits');
ok($bulk->decideRow((string) $r2['id'], false, 'admin@x')['ok'], 'a pending row is rejected on its own');
same(2, count($store->all('attendance_logs')), 'a rejection writes no punch');
ok(!$bulk->decideRow((string) $r1['id'], false, 'admin@x')['ok'], 'a decided row is not decided again');
ok(!$bulk->decideRow((string) $bad['id'], true, 'admin@x')['ok'], 'a refused row is never approved');
ok($bulk->decideRow((string) $bad['id'], false, 'admin@x')['ok'], 'but it can be rejected, which closes it');
same('rejected', (string) $bulk->rows((string) $up['batch'])[2]['status'], 'and shows rejected');
