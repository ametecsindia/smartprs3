<?php
/**
 * PT PART OF A YEAR AND THE ANNUAL MAXIMUM (owner, 29 Oct 2026): a quarterly / half-yearly / yearly deduction is cut
 * to the months employed in its period, a leaver pays their share in the exit month, and payroll stops at the
 * rule's annual maximum.   php tests/pt_part_year_check.php
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

use SmartPRS\Surface\PtRules;
use SmartPRS\Surface\RowStore;

class PyMem implements RowStore {
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
$amount = static function (?array $slab, string $gross): string {
    $g = PtRules::minor($gross);
    foreach ((array) $slab as $b) {
        if ($g >= PtRules::minor($b['from']) && ($b['to'] === null || $g <= PtRules::minor($b['to']))) { return $b['amount']; }
    }
    return 'none';
};
$rule = static fn (array $r): array => ['module_key' => 'pt-rules', 'version' => 1, 'payload' => json_encode(['rules' => [$r]])];

// HALF-YEARLY (Sep / Mar), ₹1,250 a half above ₹10,000 a month.
$half = ['id' => 'h', 'state' => 'Tamil Nadu', 'frequency' => 'half-yearly', 'months' => [9, 3], 'slab_on' => 'monthly',
    'slabs' => [['upto' => '10000', 'amount' => '0'], ['upto' => '', 'amount' => '1250']], 'annual_cap' => '2500'];
$pt = new PtRules(new PyMem(['module_customisations' => [$rule($half)]]), $cfgDir);
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2026-09-30', ['id' => '1', 'doj' => '2020-01-01']), '30000') === '1250.00', 'full half-year: ₹1,250');
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2026-09-30', ['id' => '1', 'doj' => '2026-08-10']), '30000') === '417.00', 'joined in Aug: 2 of 6 months = ₹417');
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2026-08-31', ['id' => '1', 'doj' => '2020-01-01']), '30000') === '0.00', 'not a deduction month: nil');
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2026-06-30', ['id' => '1', 'doj' => '2020-01-01', 'exit_date' => '2026-06-15']), '30000') === '625.00', 'leaves in Jun: Apr–Jun = 3 of 6, deducted in the exit month = ₹625');
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2026-09-30', ['id' => '1', 'doj' => '2020-01-01', 'exit_date' => '2026-06-15']), '30000') === '0.00', 'after leaving: nil');
$ok($amount($pt->slab('Tamil Nadu', '', '', 'Male', '2027-03-31', ['id' => '1', 'doj' => '2026-12-01']), '30000') === '833.00', 'joined in Dec: Oct–Mar half, Dec–Mar = 4 of 6 = ₹833');

// MONTHLY RULE: every month worked pays its month; no cut.
$mon = ['id' => 'm', 'state' => 'Telangana', 'slabs' => [['upto' => '15000', 'amount' => '0'], ['upto' => '', 'amount' => '200']], 'annual_cap' => '500'];
$run = static fn (string $p, string $paise): array => ['period' => $p, 'status' => 'approved', 'rows' => json_encode(['7' => ['lines' => [['code' => 'D-PT', 'kind' => 'deduction', 'minor' => (int) $paise]]]])];
$pt = new PtRules(new PyMem(['module_customisations' => [$rule($mon)]]), $cfgDir);
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2026-10-31', ['id' => '7', 'doj' => '2026-10-20']), '30000') === '200.00', 'monthly: joining month pays its month');
// ANNUAL MAXIMUM ₹500: Apr + May paid ₹400 → June takes ₹100 → July nil.
$pt = new PtRules(new PyMem(['module_customisations' => [$rule($mon)], 'payroll_runs' => [$run('2026-04', '20000'), $run('2026-05', '20000')]]), $cfgDir);
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2026-06-30', ['id' => '7']), '30000') === '100.00', 'annual max: only the rest (₹100)');
$pt = new PtRules(new PyMem(['module_customisations' => [$rule($mon)], 'payroll_runs' => [$run('2026-04', '20000'), $run('2026-05', '20000'), $run('2026-06', '10000')]]), $cfgDir);
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2026-07-31', ['id' => '7']), '30000') === '0.00', 'annual max reached: nil');
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2027-04-30', ['id' => '7']), '30000') === '200.00', 'a new financial year starts again');
$pt = new PtRules(new PyMem(['module_customisations' => [$rule($mon)], 'payroll_runs' => [$run('2026-04', '20000'), $run('2026-05', '20000'), ['period' => '2026-06', 'status' => 'discarded', 'rows' => $run('x', '10000')['rows']]]]), $cfgDir);
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2026-07-31', ['id' => '7']), '30000') === '100.00', 'a deleted payroll deducted nothing');
$ok($amount($pt->slab('Telangana', '', '', 'Male', '2026-05-31', ['id' => '7']), '10000') === '0.00', 'below the slab: nil');

echo "checks: {$n}, failures: " . count($f) . "\n";
foreach ($f as $x) { echo " - {$x}\n"; }
exit($f === [] ? 0 : 1);
