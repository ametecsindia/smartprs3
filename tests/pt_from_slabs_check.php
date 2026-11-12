<?php
// PT COMES ONLY FROM PT SLABS (owner, 30 Oct 2026): a Salary structure PT row is ignored; every employee carries the
// one PT rule (id pt-slabs), priced from PT Slabs by state and gender; verify() can read it back by id.
// Run: php tests/pt_from_slabs_check.php
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
if (!class_exists(Modules::class, false) && !is_file(__DIR__ . '/../surface/Modules.php')) { eval('namespace SmartPRS\Surface; class Modules { static function isRetired($r) { return false; } }'); }
class PtMem implements RowStore {
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
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$store = new PtMem(['branches' => ['1' => ['id' => '1', 'name' => 'Hyd', 'state' => 'Telangana', 'city' => 'Hyderabad']]]);
$cr = new ComponentRules($store, __DIR__ . '/../config/salary-components.json');
// An old-style structure PT row: flat ₹999 for everyone — must be ignored now.
$res = $cr->publish([['component' => 'BASIC', 'calculation' => 'percent_of', 'percent' => '50', 'basis_column' => 'CTC'],
    ['component' => 'PT', 'calculation' => 'flat', 'amount' => '999'],
    ['component' => 'SPECIAL', 'calculation' => 'balance']], '2026-04-01', 't');
$ok($res['refused'] === [], 'publish: ' . implode(' | ', $res['refused']));
$pc = (new \ReflectionClass(PayCycles::class))->newInstanceWithoutConstructor();
(new \ReflectionProperty(PayCycles::class, 'store'))->setValue($pc, $store);
$pt = static function (array $resolved, string $gross): string {
    $slip = (new PayrollRun('2026-10', (new ComponentRules(...$GLOBALS['crArgs']))->asEngineComponents($resolved), [['employment_id' => 'E1', 'gross' => Money::of($gross), 'payable_days' => Rational::of(31), 'period_days' => 31]]))->payslips[0];
    foreach ($slip->lines as $l) { if (str_ends_with((string) $l->componentCode, 'PT')) { return $l->amount->toDecimalString(); } }
    return 'none';
};
$GLOBALS['crArgs'] = [$store, __DIR__ . '/../config/salary-components.json'];
$resolved = $cr->resolveFor(['emp_code' => 'E1', 'branch_id' => '1', 'gender' => 'Male'], '2026-10-31', $pc);
$ptRules = array_values(array_filter($resolved, static fn (array $r): bool => $r['component_code'] === 'PT'));
$ok(count($ptRules) === 1 && $ptRules[0]['id'] === 'pt-slabs', 'one PT rule, from PT Slabs');
$amount = $pt($resolved, '30000.00');
$ok($amount !== 'none' && $amount !== '999.00', "PT from slabs, not the structure row's 999 (got {$amount})");
$top = PtRules::for($store, __DIR__ . '/../config')->slab('Telangana', '', '1', 'Male', '2026-10-31');
$ok($amount === Money::of((string) end($top)['amount'])->toDecimalString(), "Telangana top slab (got {$amount})");
$ok(($cr->byId('pt-slabs')['component_code'] ?? '') === 'PT', 'verify() reads the PT rule back by id');
$ok(str_contains($cr->signatureOf($resolved), 'pt-slabs'), 'payroll group key carries it');
echo ($n - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
