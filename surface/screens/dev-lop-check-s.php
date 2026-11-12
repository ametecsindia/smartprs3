<?php declare(strict_types=1);
// DEV ONLY (23 Oct): letter LOP reaches payroll — the same employee's window with and without one
// simulated 0.5-day letter LOP (nothing is written). Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$c = (string) ($_GET['code'] ?? 'AI2001'); $f = (string) ($_GET['from'] ?? '2026-09-01'); $t = (string) ($_GET['to'] ?? '2026-09-30'); $on = (string) ($_GET['on'] ?? '2026-09-17');
$fake = new class($store, $c, $on) implements SmartPRS\Surface\RowStore {
    public function __construct(private SmartPRS\Surface\RowStore $s, private string $c, private string $on) {}
    public function forTenant(string $tenantId): SmartPRS\Surface\RowStore { return $this; }
    public function tenantId(): ?string { return $this->s->tenantId(); }
    public function all(string $col): array { return $col === 'letter_lop' ? [['employee_code' => $this->c, 'status' => 'active', 'on_date' => $this->on, 'lop_halves' => 1, 'letter_id' => 'sim', 'reason' => 'Half Day Deduction (simulated)']] : $this->s->all($col); }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = '', ...$r): array { return $this->s->page($collection, $orderBy, $limit, $offset, $direction, $searchColumns, $needle, ...$r); }
    public function find(string $col, string $id): ?array { return $this->s->find($col, $id); }
    public function put(string $col, array $row): array { throw new RuntimeException('read-only check'); }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return $this->s->count($collection, $searchColumns, $needle); }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = '', ...$r): array { return $this->s->aggregate($collection, $column, $where, $groupBy, ...$r); }
    public function columnsOf(string $collection): array { return $this->s->columnsOf($collection); }
};
$p2 = new SmartPRS\Surface\PayrollPeriod($attendance, $leave, $policy, $calendar, $dayRules, $timeline, $fake);
$a = $period->forPeriod($c, $f, $t, $calendar->shiftStart(), $calendar->shiftEnd());
$b = $p2->forPeriod($c, $f, $t, $calendar->shiftStart(), $calendar->shiftEnd());
echo '<pre id="rt">', 'without: payable=', $a['payable']->toDecimalString(2), ' lop=', $a['unpaid_leave_days'], "\nwith:    payable=", $b['payable']->toDecimalString(2), ' lop=', $b['unpaid_leave_days'], ' letter_lop_halves=', $b['letter_lop_halves'], "\n", e(substr((string) $b['why'], -160)), '</pre>';
