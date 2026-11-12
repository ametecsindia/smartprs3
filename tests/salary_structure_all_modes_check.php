<?php
// SALARY STRUCTURE — EVERY COMPONENT x EVERY CALCULATION (incl. As per Government rules, Same as) must SAVE
// and COMPUTE through the real payroll engine for every Professional Tax state, both genders, February and
// the half-year months (owner, 16 Oct 2026: "ensure EVERYTHING is fixed and no errors pops out again").
// Run: php tests/salary_structure_all_modes_check.php   (expects "failures: 0")
declare(strict_types=1);
namespace SmartPRS\Surface;
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\{Money, Rational};
spl_autoload_register(function ($c) {
    $u = __DIR__ . '/../';
    if (str_starts_with($c, 'SmartPRS\\Platform\\')) { $f = $u . 'src/Platform/' . str_replace('\\', '/', substr($c, 18)) . '.php'; }
    elseif (str_starts_with($c, 'SmartPRS\\Surface\\')) { $f = $u . 'surface/' . substr($c, 17) . '.php'; }
    else { return; }
    if (is_file($f)) { require $f; }
});
class Modules { static function isRetired($r) { return false; } }
class Mem implements RowStore {
  public int $next = 1000;
  function __construct(public array $d) {}
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return []; }
  function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  function put(string $c, array $row): array { $id = (string) ($row['id'] ?? $this->next++); $row['id'] = $id; $this->d[$c][$id] = array_merge($this->d[$c][$id] ?? [], $row); return $this->d[$c][$id]; }
  function count(string $c, array $s = [], string $n = ''): int { return 0; }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return []; }
}
$cfg = json_decode(file_get_contents(__DIR__ . '/../config/salary-components.json'), true);
$pt = json_decode(file_get_contents(__DIR__ . '/../config/pt.json'), true);
$branches = []; $i = 1;
foreach (array_keys($pt['states']) as $st) { $branches[(string) $i] = ['id' => (string) $i, 'name' => 'B' . $i, 'state' => ucwords($st), 'city' => $st === 'telangana' ? 'Hyderabad' : 'X']; $i++; }
$failures = []; $cases = 0;
$variants = static function (array $c) use ($cfg): array {
    $code = $c['code']; $v = [];
    if (!empty($c['government'])) { $v['govt'] = ['calc_mode' => 'government', 'calculation' => '']; }
    $v['pct_gross'] = ['calculation' => 'percent_of', 'percent' => '10', 'basis_column' => 'CTC'];
    if ($code !== 'BASIC') { $v['pct_basic'] = ['calculation' => 'percent_of', 'percent' => '10', 'basis_column' => 'BASIC']; }
    $v['flat'] = ['calculation' => 'flat', 'amount' => '1000'];
    $v['monthly'] = ['calculation' => 'monthly', 'amount' => '1000'];
    $v['slab'] = ['calculation' => 'slab', 'slab_basis' => 'CTC', 'slab' => [['from' => '0', 'to' => '20000', 'amount' => '100'], ['from' => '20000.01', 'to' => '', 'amount' => '200']]];
    $v['status'] = ['calculation' => 'employee_status', 'status_field' => 'pcc_status', 'status_value' => 'Pending', 'status_amount' => '500'];
    $v['gender'] = ['calculation' => 'employee_gender', 'gender' => ['male' => ['mode' => 'flat', 'amount' => '100'], 'female' => ['mode' => 'exempt']]];
    $v['manual'] = ['calculation' => 'manual', 'branches' => [['comparisons' => [['left' => ['component' => 'payable_days'], 'op' => '>', 'right' => ['component' => '__custom__', 'customValue' => '20']]], 'result' => [['component' => '__custom__', 'customValue' => '500']]]], 'else_result' => [['component' => '__custom__', 'customValue' => '0']]];
    if (!empty($c['same_as'])) { $v['same_as'] = ['calculation' => 'same_as']; }
    return $v;
};
foreach ($cfg['components'] as $c) {
    $code = $c['code'];
    foreach ($variants($c) as $vn => $v) {
        $set = [];
        if ($code !== 'BASIC') { $set[] = ['component' => 'BASIC', 'calculation' => 'percent_of', 'percent' => '50', 'basis_column' => 'CTC']; }
        $row = $v + ['component' => $code, 'calc_mode' => 'custom'];
        if (!empty($c['same_as'])) { $set[] = ['component' => $c['same_as'], 'calc_mode' => 'government', 'calculation' => '']; }
        $set[] = $row;
        if (!in_array($code, ['SPECIAL'], true) && $vn !== 'balance') { $set[] = ['component' => 'SPECIAL', 'calculation' => 'balance']; }
        $store = new Mem(['branches' => $branches, 'companies' => [], 'departments' => [], 'designations' => [], 'teams' => []]);
        $cr = new ComponentRules($store, __DIR__ . '/../config/salary-components.json');
        $res = $cr->publish($set, '2026-04-01', 't');
        $cases++;
        if ($res['refused'] !== []) { $failures[] = "SAVE $code/$vn: " . implode(' | ', $res['refused']); continue; }
        $pc = (new \ReflectionClass(PayCycles::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PayCycles::class, 'store'))->setValue($pc, $store);
        foreach ($branches as $b) { foreach (['Male', 'Female'] as $g) { foreach (['2027-02-28', '2026-09-30', '2026-10-31', '2027-03-31'] as $asOf) {
            $e = ['emp_code' => 'E1', 'branch_id' => $b['id'], 'gender' => $g, 'pcc_status' => 'Pending'];
            try {
                $resolved = $cr->resolveFor($e, $asOf, $pc);
                $comps = $cr->asEngineComponents($resolved);
                foreach ([12000, 30000] as $gross) {
                    (new PayrollRun(substr($asOf, 0, 7), $comps, [['employment_id' => 'E1', 'gross' => Money::of((string) $gross), 'payable_days' => Rational::of(30), 'period_days' => 30]]))->payslips[0];
                }
            } catch (\Throwable $x) { $failures[] = "RUN $code/$vn {$b['state']} $g $asOf: " . get_class($x) . ': ' . $x->getMessage(); }
        } } }
    }
}
echo "cases: $cases, failures: " . count($failures) . "\n";
foreach (array_slice(array_unique(array_map(fn ($f) => substr($f, 0, 260), $failures)), 0, 60) as $f) { echo " - $f\n"; }
exit($failures === [] ? 0 : 1);
