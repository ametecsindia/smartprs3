<?php

declare(strict_types=1);

use SmartPRS\Surface\Register;
use SmartPRS\Surface\Store;

group('payroll dashboards');

// Pull the helpers the two screens use straight out of the front controller.
$src = (string) file_get_contents(__DIR__ . '/../public/app.php');
$want = ['e', 'orgFilter', 'orgNames', 'orgValueOf', 'orgFilterApply', 'orgFilterOptions', 'orgFilterBar', 'keepHidden', 'employeePicker', 'reportPeriod', 'periodBar', 'payrollFacts', 'payPaise', 'groupByTabs', 'orgFilterKeep', 'liveRows', 'isRetired', 'periodFields', 'periodNote'];
$code = '';
foreach ($want as $fn) {
    if (function_exists($fn)) { continue; }
    $at = strpos($src, "\nfunction {$fn}(");
    if ($at === false) { throw new RuntimeException("no {$fn}"); }
    $depth = 0; $i = strpos($src, '{', $at);
    for ($j = $i; ; $j++) { $c = $src[$j]; if ($c === '{') $depth++; elseif ($c === '}') { if (--$depth === 0) break; } }
    $code .= substr($src, $at, $j - $at + 1) . "\n";
}
// The constants these helpers read, as app.php declares them — pulled from the source, not retyped.
if (!defined('PERIOD_KEYS') && preg_match('/^const PERIOD_KEYS = (\[.*?\]);/m', $src, $pk) === 1) { eval('const PERIOD_KEYS = ' . $pk[1] . ';'); }
if (!defined('ORG_FILTERS')) { define('ORG_FILTERS', ['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department', 'role' => 'Role', 'designation' => 'Designation', 'team' => 'Team', 'employee' => 'Employee']); }
eval($code);
function financialYearStart($c): int { return 4; }

$store = (new Store(sys_get_temp_dir() . '/prs3-pdash-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => 'c1', 'name' => 'Ametecs']);
$store->put('branches', ['id' => 'b1', 'name' => 'Hyderabad', 'employer_company_id' => 'c1']);
$store->put('employees', ['id' => 'e1', 'emp_code' => 'E1', 'name' => 'Asha', 'employer_company_id' => 'c1', 'branch_id' => 'b1', 'bank_acc' => '123', 'ifsc' => 'HDFC0001']);
$store->put('employees', ['id' => 'e2', 'emp_code' => 'E2', 'name' => 'Ravi', 'employer_company_id' => 'c1']);
$store->put('salary_payments', ['id' => 'p1', 'employee_id' => 'e1', 'month' => '2026-05', 'amount' => '900.0000']);
$register = (new ReflectionClass(Register::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Register::class, 'store'))->setValue($register, $store);
$rows = ['E1' => ['name' => 'Asha', 'net_minor' => 90000, 'lines' => [['code' => 'PF', 'kind' => 'deduction', 'minor' => 1800], ['code' => 'BASIC', 'kind' => 'earning', 'minor' => 91800]]],
    'E2' => ['name' => 'Ravi', 'net_minor' => 50000, 'lines' => [['code' => 'PF', 'kind' => 'deduction', 'minor' => 1000], ['code' => 'PT', 'kind' => 'deduction', 'minor' => 20000]]]];
$store->put('payroll_runs', ['id' => 'r5', 'cycle_label' => '2026-05', 'status' => 'closed', 'employees_count' => 2, 'net_total' => '1400.0000', 'earnings_total' => '0', 'deductions_total' => '0', 'arrears_amount' => '0', 'payable_amount' => '0', 'run_rows' => $rows, 'locked_at' => '2026-05-31', 'closed_by' => 'hr']);

$facts = payrollFacts($store, $register, $store->all('employees'), orgFilter(), '2026-04-01', '2026-06-30');
same(2, count($facts), 'one fact per employee on the run in range');
same('Ametecs', $facts[0]['org']['company'] ?? null, 'facts carry org names');
same(0, count(payrollFacts($store, $register, $store->all('employees'), orgFilter(), '2026-06-01', '2026-06-30')), 'out-of-range months excluded');

// Render both screens with the minimum the layout would provide.
$may = static fn (string $p): bool => true;
$scope = new class { public function only(array $r, bool $b): array { return $r; } };
$customisation = null;
$_GET = ['p' => 'custom', 'from' => '2026-04-01', 'to' => '2026-06-30'];
foreach (['deductions-ledger', 'payout-reconciliation'] as $screen) {
    ob_start();
    $session = new class { public function token(): string { return 't'; } public function user(): array { return ['role' => 'administrator', 'email' => 'a@b']; } };
    include __DIR__ . '/../surface/screens/' . $screen . '.php';
    $html = (string) ob_get_clean();
    ok(str_contains($html, 'Consolidated by Company'), "{$screen}: consolidated table rendered");
    $screen === 'deductions-ledger' && ok(str_contains($html, '₹228.00') || str_contains($html, '228.00'), 'deductions total = 18 + 10 + 200');
    $screen === 'payout-reconciliation' && ok(str_contains($html, 'Matched') && str_contains($html, 'Not paid yet'), 'E1 paid in full = matched, E2 not paid yet');
    file_put_contents(sys_get_temp_dir() . "/{$screen}.html", $html);
}
