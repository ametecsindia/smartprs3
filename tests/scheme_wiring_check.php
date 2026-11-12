<?php
// SCHEME SETTINGS REACH EVERY MODULE (owner, 2 Nov 2026): validity window, max claims / max ₹ per person, clawback on
// bounce (linked Clawbacks row, recovered in payroll), Commission vs Incentive payslip lines named with purpose and scheme.
// Run: php tests/scheme_wiring_check.php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\PayrollInputs;
use SmartPRS\Surface\RowStore;

class SwMem implements RowStore {
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
  function columnsOf(string $c): array { return $c === 'commissions' ? ['id', 'employee_id', 'employer_company_id', 'branch_id', 'scheme_id', 'claim_type', 'purpose', 'portfolio', 'month', 'base_amount', 'gross_amount', 'tds_rate', 'tds_amount', 'amount', 'payout_method', 'payout_date', 'status', 'remarks'] : []; }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$g = Money::of('1000.00');
$sch = ['id' => 'S1', 'name' => 'Q4', 'scheme_kind' => 'Incentive', 'valid_from' => '2026-10-01', 'valid_till' => '2026-12-31', 'max_claims' => '2', 'max_amount' => '2500', 'clawback' => '1', 'status' => 'Active', 'rate_type' => 'Open', 'basis' => 'Manual', 'eligible_salary_types' => 'All'];
$ok(Compensation::limitsProblem($sch, '7', '2026-09', $g, []) !== null, 'month before Valid From refused');
$ok(Compensation::limitsProblem($sch, '7', '2027-01', $g, []) !== null, 'month after Valid Till refused');
$ok(Compensation::limitsProblem($sch, '7', '2026-11', $g, []) === null, 'month inside allowed');
$two = [['id' => '1', 'scheme_id' => 'S1', 'employee_id' => '7', 'status' => 'approved', 'gross_amount' => '1000'], ['id' => '2', 'scheme_id' => 'S1', 'employee_id' => '7', 'status' => 'pending', 'gross_amount' => '1000']];
$ok(str_contains((string) Compensation::limitsProblem($sch, '7', '2026-11', $g, $two), '2 claim'), 'max claims reached');
$ok(Compensation::limitsProblem($sch, '7', '2026-11', $g, $two, '2') === null, 'editing one of them is not counted twice');
$rej = [$two[0], ['status' => 'Rejected'] + $two[1]];
$ok(Compensation::limitsProblem(['max_claims' => ''] + $sch, '7', '2026-11', Money::of('2000.00'), $rej) !== null, 'max ₹: 1000 + 2000 > 2500');
$ok(Compensation::limitsProblem(['max_claims' => ''] + $sch, '7', '2026-11', Money::of('1500.00'), $rej) === null, 'max ₹: exactly 2500 allowed; rejected not counted');
$ok(Compensation::limitsProblem($sch, '8', '2026-11', $g, $two) === null, 'another employee unaffected');

// Clawback through the Commission Entries save
$store = new SwMem(['incentive_schemes' => ['S1' => $sch, 'S2' => ['id' => 'S2', 'clawback' => '0', 'name' => 'NoCB'] + $sch],
    'employees' => ['7' => ['id' => '7', 'emp_code' => 'E7', 'salary_type' => 'Salary']], 'designations' => [],
    'commissions' => ['50' => ['id' => '50', 'employee_id' => '7', 'scheme_id' => 'S1', 'month' => '2026-10', 'gross_amount' => '1000', 'amount' => '1000', 'status' => 'Paid', 'purpose' => 'Diwali']]]);
$mods = new Modules($store, __DIR__ . '/../config/modules.json');
$by = array_column(json_decode((string) file_get_contents(__DIR__ . '/../config/modules.d/leave-comp.json'), true)['modules'], null, 'key');
$r = $mods->save($by['commissions'], ['id' => '50', 'employee_id' => '7', 'scheme_id' => 'S1', 'claim_type' => 'Simple claim — target / bonus / special incentive', 'month' => '2026-10', 'gross_amount' => '1000', 'status' => 'Clawed back']);
$cb = array_values($store->d['clawbacks'] ?? []);
$ok($r["ok"] && count($cb) === 1 && Money::of($cb[0]['amount'])->minor === 100000 && str_contains($cb[0]['reason'], '#50') && $cb[0]['status'] === 'Pending', 'clawback raised, linked to the entry: ' . $r['why']);
$ok(str_contains((string) $store->d['commissions']['50']['remarks'], 'Clawback #' . $cb[0]['id']), 'entry names the clawback');
$store->d['commissions']['51'] = ['id' => '51', 'status' => 'Paid'] + $store->d['commissions']['50'];
$store->d['commissions']['51']['scheme_id'] = 'S2';
$r = $mods->save($by['commissions'], ['id' => '51', 'employee_id' => '7', 'scheme_id' => 'S2', 'claim_type' => 'Simple claim — target / bonus / special incentive', 'month' => '2026-10', 'gross_amount' => '1000', 'status' => 'Clawed back']);
$ok(!$r['ok'] && str_contains($r['why'], 'does not allow clawback'), 'scheme without clawback refuses Clawed back');
$r = $mods->save($by['commissions'], ['employee_id' => '7', 'scheme_id' => 'S1', 'claim_type' => 'Simple claim — target / bonus / special incentive', 'month' => '2027-02', 'gross_amount' => '100', 'status' => 'Pending']);
$ok(!$r['ok'] && str_contains($r['why'], 'outside'), 'entry outside validity refused: ' . $r['why']);

// Payroll lines
$store->d['commissions']['60'] = ['id' => '60', 'employee_id' => '7', 'scheme_id' => 'S1', 'month' => '2026-11', 'gross_amount' => '1000', 'tds_amount' => '50', 'status' => 'approved', 'purpose' => 'Diwali', 'payout_method' => 'With salary (in the payslip)'];
$store->d['incentive_schemes']['S3'] = ['id' => 'S3', 'name' => 'EMI', 'scheme_kind' => 'Commission', 'tds_applicable' => '1'];
$store->d['employees']['9'] = ['id' => '9', 'salary_type' => 'Commission'];
$store->d['commissions']['61'] = ['id' => '61', 'employee_id' => '9', 'scheme_id' => 'S3', 'month' => '2026-11', 'gross_amount' => '2000', 'tds_amount' => '40', 'status' => 'approved', 'purpose' => 'EMI Collection'];
$store->d['commissions']['62'] = ['id' => '62', 'employee_id' => '9', 'month' => '2026-11', 'gross_amount' => '300', 'tds_amount' => '6', 'status' => 'approved'];
$store->d['clawbacks'] = ['70' => ['id' => '70', 'employee_id' => '9', 'month' => '2026-11', 'amount' => '500', 'status' => 'Approved', 'reason' => 'bounce'], '71' => ['id' => '71', 'employee_id' => '9', 'month' => '2026-11', 'amount' => '999', 'status' => 'Pending']];
$pi = new PayrollInputs($store, __DIR__ . '/../config/payroll-inputs.json');
$codes = static fn (array $l): array => array_map(static fn (array $x): string => $x['code'] . ' ' . $x['amount'], $l['lines']);
$sal = $pi->forEmployment($store->d['employees']['7'], '2026-11', '2026-11-01', '2026-11-30');
$ok(in_array('INCENTIVE ' . Money::of('1000.00')->format(), $codes($sal), true), 'Incentive scheme → INCENTIVE line: ' . implode(', ', $codes($sal)));
$ok(str_contains($sal['lines'][0]['trace'] ?? '', 'Incentive — Diwali (scheme "Q4")'), 'line named with purpose and scheme: ' . ($sal['lines'][0]['trace'] ?? ''));
$com = $pi->forEmployment($store->d['employees']['9'], '2026-11', '2026-11-01', '2026-11-30');
$c = $codes($com);
$ok(in_array('COMMISSION ' . Money::of('2000.00')->format(), $c, true) && in_array('TDS_COMMISSION ' . Money::of('40.00')->format(), $c, true), 'Commission scheme → COMMISSION + TDS_COMMISSION: ' . implode(', ', $c));
$ok(in_array('INCENTIVE ' . Money::of('300.00')->format(), $c, true) && in_array('TDS_INCENTIVE ' . Money::of('6.00')->format(), $c, true), 'entry without a scheme unchanged');
$ok(in_array('CLAWBACK ' . Money::of('500.00')->format(), $c, true) && !in_array('CLAWBACK ' . Money::of('999.00')->format(), $c, true), 'approved clawback recovered, pending not');
$codesCfg = array_column(json_decode((string) file_get_contents(__DIR__ . '/../config/salary-components.json'), true)['components'], 'kind', 'code');
$ok(($codesCfg['COMMISSION'] ?? '') === 'earning' && ($codesCfg['TDS_COMMISSION'] ?? '') === 'deduction' && ($codesCfg['CLAWBACK'] ?? '') === 'deduction', 'payslip components declared');
$ok(in_array('TDS_COMMISSION', (array) (json_decode((string) file_get_contents(__DIR__ . '/../config/income-tax.json'), true)['years'][array_key_first(json_decode((string) file_get_contents(__DIR__ . '/../config/income-tax.json'), true)['years'] ?? ['x' => []])]['salary_codes']['tds'] ?? []), true)
    || str_contains((string) file_get_contents(__DIR__ . '/../config/income-tax.json'), '"TDS_COMMISSION"'), 'TDS on commission credited in salary TDS / Form 16 / remittances');

echo ($n - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
