<?php
// PT SLABS (owner, 28 Oct 2026): the rules engine behind Organization Setup -> PT Slabs.
// Run: php tests/pt_rules_check.php   (expects "failures: 0")
declare(strict_types=1);
namespace SmartPRS\Surface;
use SmartPRS\Platform\Support\Money;
spl_autoload_register(function ($c) {
    $u = __DIR__ . '/../';
    if (str_starts_with($c, 'SmartPRS\\Platform\\')) { $f = $u . 'src/Platform/' . str_replace('\\', '/', substr($c, 18)) . '.php'; }
    elseif (str_starts_with($c, 'SmartPRS\\Surface\\')) { $f = $u . 'surface/' . substr($c, 17) . '.php'; }
    else { return; }
    if (is_file($f)) { require $f; }
});
class PtMem implements RowStore {
  function __construct(public array $d) {}
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return []; }
  function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  function put(string $c, array $row): array { return $row; }
  function count(string $c, array $s = [], string $n = ''): int { return 0; }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return []; }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$cfgDir = __DIR__ . '/../config';
$pt = json_decode(file_get_contents($cfgDir . '/pt.json'), true);

// 1. THE SHIPPED SCHEDULE GIVES EXACTLY THE SLABS THE OLD ptSlab() GAVE — every state, gender, month.
$old = static function (array $s, string $gender, int $month): array {
    $female = strtolower(substr($gender, 0, 1)) === 'f';
    $rows = (array) ($female && isset($s['female']) ? $s['female'] : $s['slabs']);
    if (isset($s['half_yearly_months']) && !in_array($month, array_map('intval', (array) $s['half_yearly_months']), true)) { $rows = [['0', null, '0.00']]; }
    $last = count($rows) - 1;
    return array_map(static fn (array $r, int $i): array => ['from' => Money::of((string) $r[0])->minor, 'to' => $r[1] === null ? null : Money::of((string) $r[1])->minor,
        'amount' => Money::of($i === $last && $month === 2 && isset($s['february_top_amount']) && (string) $r[2] !== '0.00' ? (string) $s['february_top_amount'] : (string) $r[2])->minor], $rows, array_keys($rows));
};
$rules = new PtRules(new PtMem([]), $cfgDir);
foreach ($pt['states'] as $st => $s) { if (isset($s['bands'])) { continue; } foreach (['Male', 'Female', 'Other', ''] as $g) { for ($m = 1; $m <= 12; $m++) {
    $new = array_map(static fn (array $b): array => ['from' => Money::of($b['from'])->minor, 'to' => $b['to'] === null ? null : Money::of($b['to'])->minor, 'amount' => Money::of($b['amount'])->minor],
        (array) $rules->slab(ucwords($st), '1', '2', $g, sprintf('2026-%02d-15', $m)));
    $ok($new === $old($s, $g, $m), "shipped $st $g month $m: " . json_encode($new) . ' vs ' . json_encode($old($s, $g, $m)));
} } }
$ok($rules->slab('Atlantis', '', '', 'Male', '2026-10-01') === null, 'a state with no rule gives null (payroll refuses)');
$ok(count(PtRules::problems($rules->shipped())) === 0, 'shipped rules are valid: ' . implode(' | ', PtRules::problems($rules->shipped())));

// 2. TAMIL NADU half-yearly on half-year income: 21,000 / 6 = 3,500 a month; deducted Sep & Mar only.
$tn = $rules->ruleFor('Tamil Nadu', '', '', 'Male', '2026-09-01');
$ok($tn['frequency'] === 'half-yearly' && $tn['slab_on'] === 'half-yearly' && $tn['slabs'][0]['upto'] === '21000.00', 'TN shown in half-year income');
$ok(PtRules::amountMinor($tn, 350000, 9) === 0 && PtRules::amountMinor($tn, 350001, 9) === 13500 && PtRules::amountMinor($tn, 1300000, 9) === 125000, 'TN slab boundaries');
$ok(PtRules::amountMinor($tn, 1300000, 10) === 0, 'TN nothing in October');

// 2b. MADHYA PRADESH: yearly bands, deducted monthly, March carries the balance (166×11+174 = 2,000; 208×11+212 = 2,500).
$mp = $rules->ruleFor('Madhya Pradesh', '', '', 'Male', '2026-10-01');
$ok($mp['frequency'] === 'monthly' && $mp['slab_on'] === 'yearly' && PtRules::periodMonths($mp) === 12, 'MP yearly bands, monthly deduction');
$ok(PtRules::amountMinor($mp, 1875000, 10) === 0 && PtRules::amountMinor($mp, 1875001, 10) === 12500, 'MP: 18,750/month = 2,25,000/year is nil; a paisa more pays 125');
$ok(PtRules::amountMinor($mp, 3000000, 10) === 16600 && PtRules::amountMinor($mp, 3000000, 3) === 17400 && PtRules::amountMinor($mp, 5000000, 3) === 21200, 'MP March balances');
$mpPlan = PtRules::yearPlan($mp);
$ok(array_column($mpPlan, 'total') === [0, 150000, 200000, 250000], 'MP year totals 0 / 1,500 / 2,000 / 2,500: ' . json_encode(array_column($mpPlan, 'total')));
// 2c. KERALA (G.O. 1149/2024/LSGD): half-yearly income, deducted Aug & Feb; 1,25,000 half-year = 20,833.33/month.
$kl = $rules->ruleFor('Kerala', '', '', 'Female', '2026-08-01');
$ok($kl['months'] === [2, 8] && PtRules::amountMinor($kl, 250000, 8) === 32000 && PtRules::amountMinor($kl, 300000, 8) === 45000 && PtRules::amountMinor($kl, 300000, 9) === 0, 'Kerala: 2,500/month (15,000 half-year) pays 320, 3,000/month (18,000) pays 450, in Aug; nothing in Sep');
$ok(PtRules::amountMinor($kl, 2083317, 2) === 125000 && PtRules::amountMinor($kl, 2083316, 2) === 100000, 'Kerala top band starts at 1,24,999.01 half-year = 20,833.17 a month');
$klPlan = PtRules::yearPlan($kl);
$ok(end($klPlan)['total'] === 250000, 'Kerala top band 2,500 a year');
// 2d. A RULE SAVED BEFORE TODAY'S CHANGE ('period') still reads as its frequency's months.
$ok(PtRules::normalise(['frequency' => 'half-yearly', 'slab_on' => 'period'])['slab_on'] === 'half-yearly' && PtRules::normalise(['frequency' => 'monthly', 'slab_on' => 'period'])['slab_on'] === 'monthly', 'legacy "period" maps to the frequency');
// 2e. EXEMPTIONS: age, disability (Employee field), employee type.
$ex = new PtRules(new PtMem(['employee_field_values' => [['employment_code' => 'E9', 'field_code' => 'physically_challenged', 'value' => 'Yes']],
    'module_customisations' => [['module_key' => 'pt-rules', 'version' => 1, 'payload' => json_encode(['rules' => [['id' => 'x', 'state' => 'Telangana', 'slabs' => [['upto' => '15000', 'amount' => '0'], ['upto' => '', 'amount' => '200']],
        'exempt_age' => '65', 'exempt_pwd' => true, 'exempt_types' => ['Intern']]]])]]]), $cfgDir);
$zero = [['from' => '0', 'to' => null, 'amount' => '0.00', 'percent' => null]];
$ok($ex->slab('Telangana', '', '', 'Male', '2026-10-15', ['emp_code' => 'E1', 'dob' => '1960-10-15']) === $zero, 'aged 66: exempt');
$ok($ex->slab('Telangana', '', '', 'Male', '2026-10-15', ['emp_code' => 'E1', 'dob' => '1961-10-16']) !== $zero, 'aged 64 (65 tomorrow): not exempt');
$ok($ex->slab('Telangana', '', '', 'Male', '2026-10-15', ['emp_code' => 'E9', 'dob' => '1990-01-01']) === $zero, 'disability recorded in Employee fields: exempt');
$ok($ex->slab('Telangana', '', '', 'Male', '2026-10-15', ['emp_code' => 'E2', 'employment_type' => 'intern']) === $zero, 'employee type Intern: exempt');
$ok($ex->slab('Telangana', '', '', 'Male', '2026-10-15', ['emp_code' => 'E2']) !== $zero, 'blank fields never exempt');
$ok($ex->applies('Telangana', '', '', 'Male', 3000000, '2026-10-15', ['emp_code' => 'E9']) === false && $ex->applies('Telangana', '', '', 'Male', 3000000, '2026-10-15', ['emp_code' => 'E2']) === true, 'PT Applicable follows exemptions');

// 3. A SAVED SET WINS; most specific rule; effective dating; quarterly with Feb special; female rule.
$saved = ['rules' => [
    ['id' => 'a', 'name' => 'KA all', 'state' => 'Karnataka', 'gender' => 'all', 'frequency' => 'monthly', 'slabs' => [['upto' => '15000', 'amount' => '0'], ['upto' => '25000', 'amount' => '150'], ['upto' => '', 'amount' => '200', 'special' => '300']], 'special_month' => 2, 'effective_from' => '2026-04-01'],
    ['id' => 'b', 'name' => 'KA all new', 'state' => 'Karnataka', 'gender' => 'all', 'frequency' => 'monthly', 'slabs' => [['upto' => '24999.99', 'amount' => '0'], ['upto' => '', 'amount' => '200']], 'effective_from' => '2026-11-01'],
    ['id' => 'c', 'name' => 'KA women', 'state' => 'Karnataka', 'gender' => 'female', 'frequency' => 'monthly', 'slabs' => [['upto' => '', 'amount' => '0']], 'effective_from' => '2026-04-01'],
    ['id' => 'd', 'name' => 'KA branch 7', 'state' => 'Karnataka', 'branch_id' => '7', 'gender' => 'all', 'frequency' => 'quarterly', 'months' => [6, 9, 12, 3], 'slab_on' => 'quarterly', 'slabs' => [['upto' => '45000', 'amount' => '0'], ['upto' => '', 'amount' => '600']], 'effective_from' => '2026-04-01'],
]];
$st = new PtMem(['module_customisations' => [['module_key' => 'pt-rules', 'version' => 1, 'payload' => json_encode(['use_shipped' => true])], ['module_key' => 'pt-rules', 'version' => 2, 'payload' => json_encode($saved)]]]);
$r = new PtRules($st, $cfgDir);
$ok($r->isCustomised() && count($r->rules()) === 4, 'saved set is used');
$ok(PtRules::problems($r->rules()) === [], 'saved set valid: ' . implode(' | ', PtRules::problems($r->rules())));
$ok($r->ruleFor('karnataka', '1', '2', 'Male', '2026-10-15')['id'] === 'a', 'October: the April rule');
$ok($r->ruleFor('Karnataka', '1', '2', 'Male', '2026-11-15')['id'] === 'b', 'November: the newer rule (effective dating)');
$ok($r->ruleFor('Karnataka', '1', '2', 'Female', '2026-11-15')['id'] === 'c', 'a women\'s rule beats "All"');
$ok($r->ruleFor('Karnataka', '1', '7', 'Female', '2026-11-15')['id'] === 'd', 'a branch rule beats a gender rule');
$ok($r->ruleFor('Tamil Nadu', '1', '2', 'Male', '2026-10-15') === null, 'a saved set is complete: states not in it have no rule');
$a = $r->ruleFor('Karnataka', '1', '2', 'Male', '2027-02-10');
$ok($a['id'] === 'b', 'Feb 2027 uses rule b');
$a = $r->ruleFor('Karnataka', '1', '2', 'Male', '2026-10-15');
$ok(PtRules::amountMinor($a, 2000000, 2) === 15000 && PtRules::amountMinor($a, 3000000, 2) === 30000 && PtRules::amountMinor($a, 3000000, 3) === 20000, 'Feb special on top slab only');
$q = $r->ruleFor('Karnataka', '1', '7', 'Male', '2026-10-15');
$ok(PtRules::amountMinor($q, 1500000, 6) === 0 && PtRules::amountMinor($q, 1500001, 6) === 60000 && PtRules::amountMinor($q, 9000000, 7) === 0, 'quarterly on quarter income: 45,000/3 = 15,000 a month; nothing in July');
$ok($r->applies('Karnataka', '1', '7', 'Male', 1500001, '2026-10-15') === true && $r->applies('Karnataka', '1', '7', 'Male', 1500000, '2026-10-15') === false, 'applies() follows the slab');
$plan = PtRules::yearPlan($a);
$ok(end($plan)['total'] === 250000 && $plan[0]['total'] === 0, 'year at a glance: 11 × 200 + 300 = 2,500');

// 4. REFUSALS
$bad = PtRules::problems([PtRules::normalise(['name' => 'X', 'state' => 'Kerala', 'frequency' => 'half-yearly', 'months' => [], 'slabs' => [['upto' => '20000', 'amount' => '120'], ['upto' => '15000', 'amount' => 'abc']], 'special_month' => 5])]);
$ok(count($bad) >= 4, 'refuses: no months, falling up-to, bad amount, last not open, special month not deducted — got ' . count($bad));
$dup = PtRules::problems(array_map([PtRules::class, 'normalise'], [['name' => 'A', 'state' => 'Kerala', 'slabs' => [['upto' => '', 'amount' => '0']]], ['name' => 'B', 'state' => 'kerala', 'slabs' => [['upto' => '', 'amount' => '0']]]]));
$ok(count($dup) === 1, 'two rules for the same people from the same date are refused');
$ok(PtRules::rupees('1250000.5') === '₹12,50,000.50' && PtRules::rupees('15000') === '₹15,000', 'Indian grouping');

echo "checks: $n, failures: " . count($f) . "\n";
foreach (array_slice($f, 0, 30) as $x) { echo " - $x\n"; }
exit($f === [] ? 0 : 1);
