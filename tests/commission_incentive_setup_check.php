<?php
// COMMISSION SETUP / INCENTIVE SETUP (owner, 30 Oct 2026): two screens on incentive_schemes (scheme_kind); TDS ticked →
// Flat ₹ or %, for whoever the scheme pays; not ticked → none; Applies-to incl. Role / Designation / Employee.
// Run: php tests/commission_incentive_setup_check.php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\RowStore;

class CisMem implements RowStore {
  public int $next = 1;
  function __construct(public array $d = []) {}
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return array_slice($this->all($collection), 0, $limit); }
  function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  function put(string $c, array $row): array { $id = (string) ($row['id'] ?? $this->next++); $row['id'] = $id; $this->d[$c][$id] = array_merge($this->d[$c][$id] ?? [], $row); return $this->d[$c][$id]; }
  function count(string $c, array $s = [], string $n = ''): int { return count($this->d[$c] ?? []); }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return $c === 'incentive_schemes' ? ['id', 'name', 'status', 'basis', 'rate_type', 'rate_value', 'scheme_kind', 'eligible_salary_types', 'tds_applicable', 'tds_mode', 'tds_rate', 'tds_flat', 'employer_company_id', 'branch_id', 'scope_role', 'designation_id', 'employee_id'] : []; }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$rules = ['flat_tds_salary_types' => ['Commission'], 'default_tds_rate' => '2'];
$g = Money::of('10000.00');
$sal = ['id' => '7', 'salary_type' => 'Salary', 'designation_id' => 'D1', 'branch_id' => 'B1'];
$com = ['id' => '8', 'salary_type' => 'Commission', 'designation_id' => 'D2', 'branch_id' => 'B1'];

// TDS
$inc = ['name' => 'Q4', 'scheme_kind' => 'Incentive', 'tds_applicable' => '1', 'tds_mode' => 'percent', 'tds_rate' => '5'];
$t = Compensation::schemeTds($g, $inc, $sal, $rules);
$ok($t['tds']->format() === Money::of('500.00')->format() && $t['on_entry'], 'incentive 5% on a salaried employee = 500, on the entry');
$t = Compensation::schemeTds($g, ['tds_mode' => 'flat', 'tds_flat' => '750'] + $inc, $sal, $rules);
$ok($t['tds']->minor === 75000 && $t['net']->minor === 925000, 'incentive flat ₹750');
$t = Compensation::schemeTds(Money::of('300.00'), ['tds_mode' => 'flat', 'tds_flat' => '750'] + $inc, $sal, $rules);
$ok($t['tds']->minor === 30000 && $t['net']->minor === 0, 'flat never more than the payout');
$t = Compensation::schemeTds($g, ['tds_applicable' => '0'] + $inc, $com, $rules);
$ok($t['tds']->minor === 0 && !$t['on_entry'], 'not ticked = no TDS, even for Commission type');
$cs = ['name' => 'EMI', 'scheme_kind' => 'Commission', 'tds_applicable' => '1', 'tds_rate' => '2'];
$ok(Compensation::schemeTds($g, $cs, $com, $rules)['tds']->minor === 20000, 'commission 2%');
$ok(Compensation::schemeTds($g, null, $com, $rules)['tds']->minor === 20000 && Compensation::schemeTds($g, null, $sal, $rules)['tds']->minor === 0, 'no scheme: 29 Oct rule unchanged');
$ok(Compensation::schemeTds($g, ['name' => 'old', 'tds_rate' => '3'], $com, $rules)['tds']->minor === 30000, 'older scheme (no kind): 29 Oct rule unchanged');

// Who it is for
$roles = ['D1' => 'Executive', 'D2' => 'Field Agent'];
$ok(Compensation::eligible(['scope_role' => 'Field Agent'], $com, $roles) && !Compensation::eligible(['scope_role' => 'Field Agent'], $sal, $roles), 'Role filter');
$ok(!Compensation::eligible(['scope_role' => 'Field Agent'], $com, []), 'Role set but roles unknown = not covered (fails closed)');
$ok(Compensation::eligible(['employee_id' => '7'], $sal) && !Compensation::eligible(['employee_id' => '7'], $com), 'Employee filter');
$ok(!Compensation::eligible(['branch_id' => 'B2'], $sal) && Compensation::eligible(['designation_id' => 'D1'], $sal), 'Branch / Designation filters');
// SALARY TYPE = Salary, Salary + Commission or Commission (3 Nov): one spelling; "Salary & Commission" saved earlier reads the same
$ok(Compensation::eligible(['eligible_salary_types' => 'Salary + Commission'], ['salary_type' => 'Salary & Commission']) && Compensation::eligible(['eligible_salary_types' => 'Salary + Commission'], ['salary_type' => 'Salary + Commission'])
    && !Compensation::eligible(['eligible_salary_types' => 'Salary + Commission'], ['salary_type' => 'Salary']) && !Compensation::eligible(['eligible_salary_types' => 'Salary + Commission'], ['salary_type' => 'Commission']), 'Salary + Commission is one type, not Salary or Commission');
$ok(Compensation::eligible(['eligible_salary_types' => ''], ['salary_type' => 'Salary']), 'blank = all salary types');
$ok(Compensation::eligible(['eligible_salary_types' => 'Salary + Salary & Commission'], $sal) && !Compensation::eligible(['eligible_salary_types' => 'Salary + Salary & Commission'], $com), 'Salary Type choice');

// Two screens, one table
$store = new CisMem(['incentive_schemes' => ['1' => ['id' => '1', 'name' => 'EMI Coll', 'status' => 'Active']]]);
$mods = new Modules($store, __DIR__ . '/../config/modules.json');
$cfg = json_decode((string) file_get_contents(__DIR__ . '/../config/modules.d/leave-comp.json'), true)['modules'];
$by = array_column($cfg, null, 'key');
$c = $mods->save($by['commission-setup'], ['name' => 'EMI 2026', 'status' => 'Active', 'basis' => 'Target', 'rate_type' => 'Percent', 'rate_value' => '1', 'target_amount' => '100000', 'tds_applicable' => '1', 'tds_rate' => '2', 'scheme_kind' => 'Incentive', 'eligible_salary_types' => 'Salary + Commission']);
$ok($c['ok'] && ($c['row']['scheme_kind'] ?? '') === 'Commission' && ($c['row']['eligible_salary_types'] ?? '') === 'Salary + Commission', 'Commission Setup saves kind Commission (whatever was posted) and the Salary Type picked on the scheme (3 Nov): ' . ($c['why'] ?? ''));
$cAll = $mods->save($by['commission-setup'], ['name' => 'X', 'status' => 'Active', 'rate_type' => 'Percent', 'rate_value' => '1', 'target_amount' => '1000', 'eligible_salary_types' => 'All']);
$ok(!$cAll['ok'] && str_contains($cAll['why'], 'Salary Type'), 'a Salary Type not offered on Commission Setup is refused');
$i = $mods->save($by['incentive-setup'], ['name' => 'Diwali', 'status' => 'Active', 'basis' => 'Manual', 'eligible_salary_types' => '', 'tds_applicable' => '1', 'tds_mode' => 'flat', 'tds_flat' => '100']);
$ok($i['ok'] && ($i['row']['scheme_kind'] ?? '') === 'Incentive', 'Incentive Setup saves kind Incentive: ' . ($i['why'] ?? ''));
$bad = $mods->save($by['incentive-setup'], ['name' => 'X', 'basis' => 'Manual', 'eligible_salary_types' => '', 'tds_applicable' => '1', 'tds_mode' => 'percent']);
$ok(!$bad['ok'] && str_contains($bad['why'], 'TDS %'), 'TDS % required when ticked as %: ' . $bad['why']);
$names = static fn (array $r): array => array_map(static fn (array $x): string => (string) $x['name'], $r['rows']);
$ok($names($mods->rows($by['commission-setup'])) === ['EMI 2026'], 'Commission Setup lists only its own (old EMI Coll gone)');
$ok($names($mods->rows($by['incentive-setup'])) === ['Diwali'], 'Incentive Setup lists only its own');

echo ($n - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
