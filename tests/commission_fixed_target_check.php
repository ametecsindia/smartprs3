<?php
// COMMISSION SETUP: FIXED OR BASED ON TARGET (owner, 3 Nov 2026).
// Fixed = paid by itself every month, no custom entry for covered employees; Target = Target achieved entered,
// the % or the slab band picked by achievement % (achieved ÷ target × 100). Run: php tests/commission_fixed_target_check.php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\PayrollInputs;
use SmartPRS\Surface\RowStore;

class FtMem implements RowStore {
  public int $next = 100;
  function __construct(public array $d = []) {}
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return array_slice($this->all($collection), 0, $limit); }
  function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  function put(string $c, array $row): array { $id = (string) ($row['id'] ?? $this->next++); $row['id'] = $id; $this->d[$c][$id] = array_merge($this->d[$c][$id] ?? [], $row); return $this->d[$c][$id]; }
  function count(string $c, array $s = [], string $n = ''): int { return count($this->d[$c] ?? []); }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return $c === 'commissions' ? ['id', 'employee_id', 'employer_company_id', 'branch_id', 'scheme_id', 'claim_type', 'purpose', 'portfolio', 'month', 'base_amount', 'gross_amount', 'tds_rate', 'tds_amount', 'amount', 'payout_method', 'payout_date', 'status', 'remarks', 'decided_by', 'decided_at'] : []; }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$g = static fn (array $r): string => $r['gross']->toDecimalString();

// TARGET-BASED: slab by achievement %
$t = ['id' => 'T', 'name' => 'Tgt', 'scheme_kind' => 'Commission', 'basis' => 'Target', 'rate_type' => 'Slab', 'target_amount' => '100000', 'status' => 'Active',
  'slabs' => json_encode([['upto' => '80', 'rate' => '0'], ['upto' => '100', 'rate' => '2'], ['upto' => '', 'rate' => '3']]), 'tds_applicable' => '1', 'tds_rate' => '2', 'eligible_salary_types' => 'Commission'];
$ok(Money::of($g(Compensation::commission($t, '90000')))->minor === 180000, '90% → 2% of 90,000 = 1,800');
$ok(Money::of($g(Compensation::commission($t, '80000')))->minor === 0, 'exactly 80% → band up to 80 = 0%');
$ok(Money::of($g(Compensation::commission($t, '80000.01')))->minor === 160000, '80.00001% → 2% band (exact compare): ' . $g(Compensation::commission($t, '80000.01')));
$ok(Money::of($g(Compensation::commission($t, '120000')))->minor === 360000, '120% → 3% of 1,20,000 = 3,600');
$ok(str_contains(Compensation::commission($t, '90000')['working'], '90% achievement'), 'working names the achievement');
$ok(Money::of($g(Compensation::commission(['rate_type' => 'Percent', 'rate_value' => '5', 'slabs' => ''] + $t, '50000')))->minor === 250000, 'one %: 5% of 50,000');
$ok(!Compensation::commission(['target_amount' => ''] + $t, '90000')['paid'], 'no target set → nothing paid');
// ONE MINIMUM ON A TARGET SCHEME (3 Nov): the achievement %; an old ₹ minimum is not applied
$one = ['rate_type' => 'Percent', 'rate_value' => '2', 'slabs' => '', 'target_amount' => '500000', 'threshold' => '100', 'min_collected' => '85'] + $t;
$ok(Money::of($g(Compensation::commission($one, '600000')))->minor === 1200000, '120% clears the 100% minimum → 12,000 (₹85 minimum ignored)');
$ok(!Compensation::commission($one, '400000')['paid'], '80% is below the 100% minimum → nothing');
$ok(Money::of($g(Compensation::commission(['min_collected' => '700000'] + $one, '600000')))->minor === 1200000, 'a ₹ minimum above the achieved figure is still ignored');
$inc = ['scheme_kind' => 'Incentive', 'slabs' => json_encode([['upto' => '50000', 'rate' => '1'], ['upto' => '', 'rate' => '2']])] + $t;
$ok(Money::of($g(Compensation::commission($inc, '90000')))->minor === 180000, 'Incentive Setup keeps slabs by amount (90,000 > 50,000 → 2%)');

// FIXED: who is covered
$fx = ['id' => 'F', 'name' => 'Field Fixed', 'scheme_kind' => 'Commission', 'basis' => 'Fixed', 'rate_type' => 'Fixed', 'rate_value' => '5000', 'status' => 'Active', 'branch_id' => 'B1',
  'valid_from' => '2026-10-01', 'valid_till' => '', 'tds_applicable' => '1', 'tds_rate' => '2', 'eligible_salary_types' => 'Commission',
  'payout_method' => 'Separate payout (own dates - ledger)', 'payout_day' => '10', 'payout_offset' => 'next', 'purpose' => 'EMI Collection'];
$e1 = ['id' => '1', 'emp_code' => 'E1', 'name' => 'Asha', 'branch_id' => 'B1', 'salary_type' => 'Commission', 'status' => 'Active'];
$e2 = ['id' => '2', 'emp_code' => 'E2', 'name' => 'Ravi', 'branch_id' => 'B2', 'salary_type' => 'Commission', 'status' => 'Active'];
$e3 = ['id' => '3', 'emp_code' => 'E3', 'name' => 'Left', 'branch_id' => 'B1', 'salary_type' => 'Commission', 'status' => 'Inactive'];
$ok(Compensation::fixedSchemeFor([$fx, $t], $e1, [], '2026-11') !== null, 'B1 employee covered');
$ok(Compensation::fixedSchemeFor([$fx], $e2, [], '2026-11') === null, 'B2 employee not covered');
$ok(Compensation::fixedSchemeFor([$fx], $e1, [], '2026-09') === null, 'before Valid From not covered');
$ok(Compensation::fixedSchemeFor([['status' => 'Draft'] + $fx], $e1, [], '2026-11') === null, 'Draft scheme covers nobody');

// FIXED: raised by itself, once
$store = new FtMem(['incentive_schemes' => ['F' => $fx, 'T' => $t], 'employees' => ['1' => $e1, '2' => $e2, '3' => $e3], 'designations' => [], 'commissions' => []]);
$rules = (array) (json_decode((string) file_get_contents(__DIR__ . '/../config/compensation.json'), true)['commission'] ?? []);
$ok(Compensation::ensureFixed($store, '2026-11', $rules) === 1, 'one entry raised (B1 active only)');
$ok(Compensation::ensureFixed($store, '2026-11', $rules) === 0, 'running again raises nothing');
$row = array_values($store->d['commissions'])[0];
$ok($row['employee_id'] === '1' && $row['status'] === 'Approved' && $row['decided_by'] === Compensation::FIXED_BY, 'entry for E1, Approved, marked Fixed');
$ok(Money::of($row['gross_amount'])->minor === 500000 && Money::of($row['tds_amount'])->minor === 10000 && Money::of($row['amount'])->minor === 490000, 'gross 5,000 − TDS 2% 100 = 4,900');
$ok($row['payout_date'] === '2026-12-10', 'separate payout on the 10th of the month after: ' . (string) $row['payout_date']);

// COMMISSION ENTRIES: covered employee refused; target entry worked out; Fixed scheme not pickable; own Fixed entry editable
$mods = new Modules($store, __DIR__ . '/../config/modules.json');
$by = array_column(json_decode((string) file_get_contents(__DIR__ . '/../config/modules.d/leave-comp.json'), true)['modules'], null, 'key');
$simple = 'Simple claim — target / bonus / special incentive';
$r = $mods->save($by['commissions'], ['employee_id' => '1', 'claim_type' => $simple, 'month' => '2026-11', 'gross_amount' => '999', 'status' => 'Pending']);
$ok(!$r['ok'] && str_contains($r['why'], 'covered as part of Fixed Commission') && str_contains($r['why'], 'No custom commission entry allowed'), 'covered employee refused: ' . $r['why']);
$r = $mods->save($by['commissions'], ['employee_id' => '1', 'scheme_id' => 'T', 'claim_type' => $simple, 'month' => '2026-09', 'base_amount' => '90000', 'status' => 'Pending']);
$ok($r['ok'], 'same employee before the scheme starts is allowed: ' . ($r['why'] ?? ''));
// NOTHING THE SCHEME ALREADY SAYS IS TYPED (3 Nov): scheme required, Target Achieved required, purpose / portfolio / payout from the scheme
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'claim_type' => $simple, 'month' => '2026-11', 'gross_amount' => '999', 'status' => 'Pending']);
$ok(!$r['ok'] && str_contains($r['why'], 'Pick the scheme'), 'new entry without a scheme refused');
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'scheme_id' => 'T', 'claim_type' => $simple, 'month' => '2026-11', 'gross_amount' => '999', 'status' => 'Pending']);
$ok(!$r['ok'] && str_contains($r['why'], 'Target Achieved'), 'target scheme without Target Achieved refused');
$store->d['incentive_schemes']['T'] += ['purpose' => 'EMI Collection', 'portfolio' => 'HDFC', 'payout_method' => 'Separate payout (own dates - ledger)', 'payout_day' => '15', 'payout_offset' => 'same'];
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'scheme_id' => 'T', 'claim_type' => $simple, 'month' => '2026-11', 'base_amount' => '120000', 'purpose' => 'Other', 'payout_method' => 'With salary (in the payslip)', 'status' => 'Pending']);
$tr2 = $store->d['commissions'][(string) ($r['row']['id'] ?? '')] ?? [];
$ok($r['ok'] && $tr2['purpose'] === 'EMI Collection' && $tr2['portfolio'] === 'HDFC' && str_starts_with($tr2['payout_method'], 'Separate') && $tr2['payout_date'] === '2026-11-15'
    && Money::of($tr2['gross_amount'])->minor === 360000, 'purpose, portfolio, payout and date from the scheme; 120% → 3,600: ' . json_encode($tr2));
unset($store->d['incentive_schemes']['T']['purpose'], $store->d['incentive_schemes']['T']['portfolio'], $store->d['incentive_schemes']['T']['payout_method'], $store->d['incentive_schemes']['T']['payout_day'], $store->d['incentive_schemes']['T']['payout_offset']);
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'scheme_id' => 'F', 'claim_type' => $simple, 'month' => '2026-11', 'status' => 'Pending']);
$ok(!$r['ok'] && str_contains($r['why'], 'Fixed Commission'), 'Fixed scheme cannot be picked by hand');
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'scheme_id' => 'T', 'claim_type' => $simple, 'month' => '2026-11', 'base_amount' => '90000', 'gross_amount' => '50000', 'status' => 'Pending']);
$tr = $store->d["commissions"][(string) ($r["row"]["id"] ?? "")] ?? [];
$ok($r["ok"] && Money::of($tr["gross_amount"])->minor === 180000 && Money::of($tr["tds_amount"])->minor === 3600, 'target entry: 90,000 achieved → 1,800 − 36 TDS = 1,764 (typed 50,000 ignored): ' . ($r['why'] ?? ''));
$r = $mods->save($by['commissions'], ['id' => (string) $row['id'], 'employee_id' => '1', 'claim_type' => $simple, 'month' => '2026-11', 'gross_amount' => '1', 'status' => 'Paid']);
$ok($r['ok'] && $store->d['commissions'][$row['id']]['status'] === 'Paid' && Money::of($store->d['commissions'][$row['id']]['gross_amount'])->minor === 500000, 'own Fixed entry: status changes, money kept: ' . ($r['why'] ?? ''));

// PAYROLL: the month's Fixed entry is raised at close
$store->d['commissions'] = [];
$store->d['incentive_schemes']['F']['payout_method'] = 'With salary (in the payslip)';
$pi = new PayrollInputs($store, __DIR__ . '/../config/payroll-inputs.json');
$codes = array_map(static fn (array $x): string => $x['code'] . ' ' . $x['amount'], $pi->forEmployment($e1, '2026-12', '2026-12-01', '2026-12-31')['lines']);
$ok(in_array('COMMISSION ' . Money::of('5000.00')->format(), $codes, true) && in_array('TDS_COMMISSION ' . Money::of('100.00')->format(), $codes, true), 'payroll pays the Fixed commission: ' . implode(', ', $codes));
$codes2 = array_map(static fn (array $x): string => $x['code'], (new PayrollInputs($store, __DIR__ . '/../config/payroll-inputs.json'))->forEmployment($e1, '2026-12', '2026-12-01', '2026-12-31')['lines']);
$ok(count(array_filter($codes2, static fn (string $c): bool => $c === 'COMMISSION')) === 1, 'regenerated month: still one line');

// A TARGET ENTRY REACHES PAYROLL with the scheme's money and TDS (owner, 3 Nov: "wired to payroll, payslip, everywhere")
$store->d['incentive_schemes']['T']['payout_method'] = 'With salary (in the payslip)';
$r = $mods->save($by['commissions'], ['employee_id' => '2', 'scheme_id' => 'T', 'claim_type' => $simple, 'month' => '2026-12', 'base_amount' => '90000', 'status' => 'Approved']);
$codes3 = array_map(static fn (array $x): string => $x['code'] . ' ' . $x['amount'], (new PayrollInputs($store, __DIR__ . '/../config/payroll-inputs.json'))->forEmployment($e2, '2026-12', '2026-12-01', '2026-12-31')['lines']);
$ok($r['ok'] && in_array('COMMISSION ' . Money::of('1800.00')->format(), $codes3, true) && in_array('TDS_COMMISSION ' . Money::of('36.00')->format(), $codes3, true), 'target entry → payslip COMMISSION 1,800 + TDS 36: ' . implode(', ', $codes3) . ' ' . ($r['why'] ?? ''));

// THE BULK SAMPLE'S FORMULA (3 Nov) — checked cell by cell against commission() in LibreOffice when it was built
$ok(Compensation::excelGross($fx, 'I4', 'H4') === null, 'a Fixed Commission has no sample formula');
$ok(str_contains((string) Compensation::excelGross($t, 'I4', 'H4'), 'I4*100<=80*H4'), 'slab by achievement %: ' . Compensation::excelGross($t, 'I4', 'H4'));
$ok(str_contains(Compensation::excelTds($t, 'K4', 'L4'), 'ROUND(K4*N(L4)/100,2)'), 'TDS formula from the TDS % cell');

echo ($n - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
