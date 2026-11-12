<?php
declare(strict_types=1);
// ASSET DAMAGE RECOVERY INTO PAYROLL (owner, 22 Oct 2026): a Damaged/Lost return with recovery status
// "Pending - Deduct in Next Pay Cycle" writes one Pending row to the Deductions Ledger for the next month with
// no payroll run, and the payroll close reads it as ASSET_DAMAGE_RECOVERY. Run: php tests/asset_damage_recovery_test.php
namespace SmartPRS\Surface;

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; } else { require_once $root . '/src/Platform/Support/Money.php'; require_once $root . '/surface/Amount.php'; }
foreach (['RowStore', 'Modules', 'Dates', 'Assets', 'PayrollInputs'] as $c) { require_once $root . '/surface/' . $c . '.php'; }

final class AdrMemStore implements RowStore
{
    public array $t = [];
    private int $n = 0;
    public function forTenant(string $tenantId): self { return $this; }
    public function tenantId(): ?string { return 't'; }
    public function all(string $c): array { return array_values($this->t[$c] ?? []); }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = ''): array { return []; }
    public function find(string $c, string $id): ?array { return $this->t[$c][$id] ?? null; }
    public function put(string $c, array $row): array { $id = (string) ($row['id'] ?? ++$this->n); $row['id'] = $id; $this->t[$c][$id] = array_merge($this->t[$c][$id] ?? [], $row); return $this->t[$c][$id]; }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = ''): array { return []; }
    public function columnsOf(string $collection): array { return ['returned_from_employee_id', 'branch_id']; }
}

$ok = function_exists('ok')
    ? static function (bool $c, string $what): void { \group('asset damage recovery'); \ok($c, $what); }
    : static function (bool $c, string $what): void { if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } echo "ok  $what\n"; };

$s = new AdrMemStore();
$s->put('employees', ['id' => '7', 'emp_code' => 'E7', 'name' => 'Asha', 'status' => 'active']);
$s->put('payroll_runs', ['period' => '2026-09', 'status' => 'approved']); // September is closed
$unit = $s->put('assets', ['asset_id' => 'KBD-1', 'asset_type' => 'Keyboard', 'make_model' => 'Dell', 'cost' => '700.00']);
$a = new Assets($s, $root . '/config/assets.json');
$pending = $a->payrollStatus();
$ok($pending === 'Pending - Deduct in Next Pay Cycle', 'the payroll recovery status is declared');
$ok(in_array('Pending', (array) $a->declared()['recovery_statuses'], true) && in_array($pending, (array) $a->declared()['recovery_statuses'], true), 'both Pending statuses are on the dropdown');

$a->move($s->find('assets', $unit['id']), 'issue', ['employee_id' => '7'], 'admin', '2026-09-01 10:00:00');
$bad = $a->move($s->find('assets', $unit['id']), 'return', ['on' => '2026-09-20', 'condition' => 'damaged', 'damage_resolution' => 'Cost recovered', 'recovery_amount' => '0', 'recovery_status' => $pending], 'admin', '2026-09-20 10:00:00');
$ok(!$bad['ok'], 'deduct-in-payroll with no amount is refused');
$r = $a->move($s->find('assets', $unit['id']), 'return', ['on' => '2026-09-20', 'condition' => 'damaged', 'damage_resolution' => 'Cost recovered', 'recovery_amount' => '700', 'recovery_status' => $pending], 'admin', '2026-09-20 10:00:00');
$ok($r['ok'], 'damaged return with Pending - Deduct in Next Pay Cycle');
$d = $s->all('deductions');
$ok(count($d) === 1, 'one deduction written');
$ok(($d[0]['type'] ?? '') === 'Asset Damage Recovery' && ($d[0]['status'] ?? '') === 'Pending' && ($d[0]['employee_id'] ?? '') === '7', 'type Asset Damage Recovery, Pending, for the returning employee');
$ok(($d[0]['amount'] ?? '') === '700.00', 'the recovery amount');
$ok(($d[0]['month'] ?? '') === '2026-10', 'September already has a payroll run, so the next pay cycle is October');
$ok(str_contains((string) $d[0]['source_ref'], 'KBD-1'), 'the ledger row names the asset');

// The payroll close reads it as an Other Deduction titled Asset Damage Recovery.
$in = (new PayrollInputs($s, $root . '/config/payroll-inputs.json'))->forEmployment($s->find('employees', '7'), '2026-10', '2026-10-01', '2026-10-31');
$line = array_values(array_filter($in['lines'], static fn (array $l): bool => $l['code'] === 'ASSET_DAMAGE_RECOVERY'));
$ok(count($line) === 1 && $line[0]['kind'] === 'deduction' && $line[0]['amount'] === (Amount::of('700')->format()), 'October payroll deducts it once as ASSET_DAMAGE_RECOVERY');
$sep = (new PayrollInputs($s, $root . '/config/payroll-inputs.json'))->forEmployment($s->find('employees', '7'), '2026-11', '2026-11-01', '2026-11-30');
$ok(array_filter($sep['lines'], static fn (array $l): bool => $l['code'] === 'ASSET_DAMAGE_RECOVERY') === [], 'and not again in November');
$cat = json_decode((string) file_get_contents($root . '/config/salary-components.json'), true);
$labels = array_column((array) ($cat['components'] ?? $cat), 'label', 'code');
$ok(($labels['ASSET_DAMAGE_RECOVERY'] ?? '') === 'Asset Damage Recovery', 'the payslip line is titled Asset Damage Recovery');

// October payroll approved: the deduction it took is Applied and the asset's recovery is Paid.
$dId = (string) $s->all('deductions')[0]['id'];
$done = $a->settleFromApprovedRun(['period' => '2026-10', 'rows' => ['7' => ['lines' => $in['lines']]]], 'approver');
$ok($done['paid'] === ['KBD-1'] && $s->find('deductions', $dId)['status'] === 'Applied', 'approval marks the deduction Applied');
$ok($s->find('assets', $unit['id'])['recovery_status'] === 'Paid' && $a->isSettled($s->find('assets', $unit['id'])), 'and the asset recovery Paid (settled)');
$again = $a->settleFromApprovedRun(['period' => '2026-10', 'rows' => []], 'approver');
$ok($again['paid'] === [] && $again['waived'] === [], 'a second approval pass changes nothing');

// The approved October payroll is deleted (HR Admin / Company Admin): the recovery goes back to pending, and
// approving the regenerated month settles it again.
$undone = $a->undoSettledByPayroll('2026-10');
$ok($undone === ['KBD-1'] && $s->find('deductions', $dId)['status'] === 'Pending' && $s->find('assets', $unit['id'])['recovery_status'] === $pending, 'deleting the approved payroll puts the recovery back to pending');
$redo = $a->settleFromApprovedRun(['period' => '2026-10', 'rows' => ['7' => ['lines' => $in['lines']]]], 'approver');
$ok($redo['paid'] === ['KBD-1'] && $s->find('assets', $unit['id'])['recovery_status'] === 'Paid', 'and approving the regenerated month settles it again');

// A deduction withdrawn before approval: the asset is marked Waived off when that month is approved.
$u2 = $s->put('assets', ['asset_id' => 'MSE-2', 'asset_type' => 'Mouse', 'make_model' => 'Dell', 'cost' => '400.00']);
$a->move($s->find('assets', $u2['id']), 'issue', ['employee_id' => '7'], 'admin', '2026-10-01 10:00:00');
$a->move($s->find('assets', $u2['id']), 'return', ['on' => '2026-10-05', 'condition' => 'damaged', 'damage_resolution' => 'Cost recovered', 'recovery_amount' => '400', 'recovery_status' => $pending], 'admin', '2026-10-05 10:00:00');
$d2 = array_values(array_filter($s->all('deductions'), static fn (array $r): bool => str_contains((string) $r['source_ref'], 'MSE-2')))[0];
$ok($d2['month'] === '2026-10', 'a second recovery lands in October too');
$s->put('deductions', ['id' => $d2['id'], 'status' => 'Waived']); // waived by hand on the ledger
$w = $a->settleFromApprovedRun(['period' => '2026-10', 'rows' => []], 'approver');
$ok($w['waived'] === ['MSE-2'] && $s->find('assets', $u2['id'])['recovery_status'] === 'Waived off', 'withdrawn deduction marks the asset Waived off on approval');

// Not in the run and still Pending: left alone.
$u3 = $s->put('assets', ['asset_id' => 'CAM-3', 'asset_type' => 'Webcam', 'make_model' => 'Logi', 'cost' => '900.00']);
$a->move($s->find('assets', $u3['id']), 'issue', ['employee_id' => '7'], 'admin', '2026-10-01 10:00:00');
$a->move($s->find('assets', $u3['id']), 'return', ['on' => '2026-10-06', 'condition' => 'damaged', 'damage_resolution' => 'Cost recovered', 'recovery_amount' => '900', 'recovery_status' => $pending], 'admin', '2026-10-06 10:00:00');
$a->settleFromApprovedRun(['period' => '2026-10', 'rows' => []], 'approver');
$ok($s->find('assets', $u3['id'])['recovery_status'] === $pending, 'a deduction the run did not take stays Pending');

// Settling it another way withdraws the pending deduction; the amount follows while it is pending.
$unit = $u3;
$a->settleRecovery($s->find('assets', $unit['id']), ['damage_resolution' => 'Cost recovered', 'recovery_amount' => '650', 'recovery_status' => $pending], 'hr', '2026-09-21 10:00:00');
$cam = static fn (): array => array_values(array_filter($s->all('deductions'), static fn (array $r): bool => str_contains((string) $r['source_ref'], 'CAM-3')));
$ok(count($cam()) === 1 && $cam()[0]['amount'] === '650.00', 'a changed amount updates the same pending row');
$a->settleRecovery($s->find('assets', $unit['id']), ['damage_resolution' => 'Waived off', 'recovery_status' => 'Waived off'], 'hr', '2026-09-22 10:00:00');
$ok($cam()[0]['status'] === 'Waived', 'waiving the recovery withdraws the pending deduction');
