<?php declare(strict_types=1);
// EmployeeSync self-check (4 Nov): Directory edits reach the login and every code-keyed copy; sealed payroll and admin logins untouched.
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/employee_sync_check.php
namespace SmartPRS\Platform\Identity { class PasswordHasher { public static function platformDefault(): self { return new self(); } public function hash(string $p): string { return 'h'; } } }
namespace SmartPRS\Surface {
final class Authorisation { public function roles(): array { return ['administrator','employee']; } }
}
namespace {
$U = __DIR__ . '/../surface/';
require $U . 'RowStore.php'; require $U . 'Employment.php'; require $U . "Users.php"; require $U . "Session.php";
require $U . 'EmployeeSync.php';
final class Mem implements SmartPRS\Surface\RowStore {
  public array $d = [];
  public function forTenant(string $t): SmartPRS\Surface\RowStore { return $this; }
  public function tenantId(): ?string { return '2'; }
  public function all(string $c): array { if ($c === 'missing_table') throw new RuntimeException(); return array_values($this->d[$c] ?? []); }
  public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = '', ...$r): array { return []; }
  public function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  public function put(string $c, array $row): array { $id = (string) ($row['id'] ?? count($this->d[$c] ?? []) + 1); $this->d[$c][$id] = array_merge($this->d[$c][$id] ?? [], $row, ['id' => $id]); return $this->d[$c][$id]; }
  public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
  public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = '', ...$r): array { return []; }
  public function columnsOf(string $collection): array { return []; }
}
$s = new Mem();
$s->put('employees', ['id' => '7', 'emp_code' => 'AI2001', 'name' => 'Ravi', 'email' => 'old@x.in']);
$s->put('employees', ['id' => '8', 'emp_code' => 'AI2002', 'name' => 'Boss', 'email' => 'boss@x.in']);
$s->put('users', ['id' => '1', 'tenant_id' => '2', 'email' => 'old@x.in', 'name' => 'Ravi', 'status' => 'employee', 'employee_id' => '7']);
$s->put('users', ['id' => '2', 'tenant_id' => '2', 'email' => 'admin@local', 'name' => 'admin', 'status' => 'administrator', 'employee_id' => '8']);
$s->put('attendance_logs', ['id' => '1', 'emp_code' => 'AI2001', 'emp_name' => 'Ravi']);
$s->put('attendance_logs', ['id' => '2', 'emp_code' => 'AI2009', 'emp_name' => 'Other']);
$s->put('leave_entries', ['id' => '1', 'employment_id' => 'AI2001', 'source_reference' => 'AI2001:accrual:EL:2026-10']);
$s->put('leave_entries', ['id' => '2', 'employment_id' => 'AI2001', 'source_reference' => '55']);
$s->put('payroll_runs', ['id' => '1', 'run_rows' => json_encode(['AI2001' => ['lines' => []]])]);
$extras = new Mem(); $extras->put('employee_field_values', ['id' => '1', 'employment_code' => 'AI2001', 'field_code' => 'pan', 'value' => 'X']);
$users = new SmartPRS\Surface\Users($s, new SmartPRS\Surface\Authorisation(), new SmartPRS\Platform\Identity\PasswordHasher());
$sync = new SmartPRS\Surface\EmployeeSync($s, $users, ['employee_field_values' => $extras]);
$before = $s->find('employees', '7');
$s->put('employees', ['id' => '7', 'emp_code' => 'AI3001', 'name' => 'Ravi Kumar', 'email' => 'new@x.in']);
$r = $sync->carry($before, $s->find('employees', '7'));
assert($s->d['users']['1']['email'] === 'new@x.in' && $s->d['users']['1']['name'] === 'Ravi Kumar');
assert($s->d['attendance_logs']['1']['emp_code'] === 'AI3001' && $s->d['attendance_logs']['1']['emp_name'] === 'Ravi Kumar');
assert($s->d['attendance_logs']['2']['emp_code'] === 'AI2009');
assert($s->d['leave_entries']['1']['source_reference'] === 'AI3001:accrual:EL:2026-10' && $s->d['leave_entries']['2']['source_reference'] === '55');
assert($s->d['leave_entries']['2']['employment_id'] === 'AI3001');
assert($extras->d['employee_field_values']['1']['employment_code'] === 'AI3001');
assert(json_decode($s->d['payroll_runs']['1']['run_rows'], true) === ['AI2001' => ['lines' => []]], 'sealed payroll untouched');
assert($r['login'] === 'new@x.in');
// admin: email kept, name follows
$s->put('employees', ['id' => '8', 'email' => 'boss2@x.in', 'name' => 'Big Boss']);
assert($sync->syncLogins() === []);
assert($s->d['users']['2']['email'] === 'admin@local' && $s->d['users']['2']['name'] === 'admin');
// clash: email used by another login is reported, not written
$s->put('employees', ['id' => '7', 'email' => 'admin@local']);
$p = $sync->syncLogins();
assert(count($p) === 1 && $s->d['users']['1']['email'] === 'new@x.in');
echo "ok\n";
}
