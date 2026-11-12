<?php

declare(strict_types=1);

use SmartPRS\Surface\Archive;
use SmartPRS\Surface\Store;

group('employee deletion: timer, bundle, move, code released');

$dir = sys_get_temp_dir() . '/prs3-arc-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'E-1', 'name' => 'Leaver', 'status' => 'active', 'photo_path' => 'uploads/1/employees/E-1_photo.jpg']);
$store->put('employees', ['id' => '2', 'emp_code' => 'E-2', 'name' => 'Stayer', 'status' => 'active']);
$store->put('attendance_logs', ['id' => '10', 'emp_code' => 'E-1', 'punch_at' => '2026-03-02 09:00:00', 'direction' => 'in']);
$store->put('attendance_logs', ['id' => '11', 'emp_code' => 'E-2', 'punch_at' => '2026-03-02 09:00:00', 'direction' => 'in']);
$store->put('employee_field_values', ['id' => '20', 'employment_code' => 'E-1', 'field_code' => 'pan', 'value' => 'ABCDE1234F']);
$store->put('leave_entries', ['id' => '30', 'employment_id' => 'E-1', 'days' => '1']);
$store->put('payroll_runs', ['id' => '40', 'period' => '2026-02', 'run_rows' => json_encode(['E-1' => ['net' => '100.00'], 'E-2' => ['net' => '200.00']])]);
@mkdir($dir . '/uploads/1/employees', 0775, true);
file_put_contents($dir . '/uploads/1/employees/E-1_photo.jpg', 'jpg');

$archive = new Archive($store, dirname(__DIR__) . '/config/archive.json', $dir . '/archive', $dir . '/uploads');
same(72, $archive->graceHours(), 'the grace is the configured 72 hours');

$r = $archive->schedule($store->find('employees', '1'), 'hr@x', '2026-09-10 10:00:00');
ok($r['ok'], 'scheduled');
same('2026-09-13 10:00:00', $r['due'], 'due 72 hours later');
ok(!$archive->schedule($store->find('employees', '1'), 'hr@x', '2026-09-10 10:00:00')['ok'], 'scheduling twice is refused');
same([], $archive->sweep('2026-09-12 10:00:00', 'scheduler'), 'before the timer lapses the sweep does nothing');
same('E-1', $store->find('employees', '1')['emp_code'], 'and the employment is untouched');

ok($archive->cancel($store->find('employees', '1'))['ok'], 'cancel clears the timer');
same([], $archive->sweep('2026-09-14 10:00:00', 'scheduler'), 'a cancelled deletion never fires');

$archive->schedule($store->find('employees', '1'), 'hr@x', '2026-09-10 10:00:00');
$done = $archive->sweep('2026-09-13 10:00:01', 'scheduler');
same(1, count($done), 'the lapsed timer archives one employment');
same('E-1_2026-09-13', $done[0]['archived_code'], 'under the code with the deletion date');
$row = $store->find('employees', '1');
same('E-1_2026-09-13', $row['emp_code'], 'the row now carries the archived code');
same('archived', $row['status'], 'and is archived');
ok((string) ($row['archived_at'] ?? '') !== '' && (string) ($row['backup_due_at'] ?? '') === '', 'archived_at set, timer cleared');
same('E-1_2026-09-13', $store->find('attendance_logs', '10')['emp_code'], 'its punches follow it');
same('E-2', $store->find('attendance_logs', '11')['emp_code'], "the other employee's punches do not");
same('E-1_2026-09-13', $store->find('employee_field_values', '20')['employment_code'], 'its extra fields follow it');
same('E-1_2026-09-13', $store->find('leave_entries', '30')['employment_id'], 'and its leave');
$bundle = json_decode((string) file_get_contents($done[0]['bundle']), true);
same('E-1', $bundle['code'], 'the bundle names the original code');
same(1, count($bundle['tables']['attendance_logs']), 'and holds the punches');
same(['2026-02' => ['net' => '100.00']], $bundle['payroll'], 'and the payroll lines that paid this code, by period');
same(1, $done[0]['files'], 'the photograph was copied beside the bundle');
ok(is_file(dirname($done[0]['bundle']) . '/E-1_photo.jpg'), 'as a file');
$codes = array_map(static fn (array $e): string => (string) $e['emp_code'], $store->all('employees'));
ok(!in_array('E-1', $codes, true), 'the code E-1 is released for a new employee');
