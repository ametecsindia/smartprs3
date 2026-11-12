<?php declare(strict_types=1);
/**
 * OFF-ROLL AGENTS — what every Field Force off-roll screen and action needs (23 Oct 2026).
 * Sets: $op (OffrollPay), $opReady, $opD (config), $opSet (settings in force), $opCompanies / $opBranches
 * (id => name), $opBranchCo (branch id => company id), $opVendors, $opAgents (the agents this viewer may
 * see, by id), $opEmpLabel (employee id => "CODE · Name"), $opWindow (certificate window, days),
 * $opMayEdit / $opMayApprove / $opIsAdmin, and small helpers $opMoney, $opBand, $opBadge, $opBandLabel.
 *
 * WHO SEES WHICH AGENT: a company-wide reader (Administrator, Company Admin, HR) sees every agent; anybody
 * else sees the agents supervised by an employee in their own reach, or deployed at their branch.
 */
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\FieldReports;
use SmartPRS\Surface\OffrollPay;
use SmartPRS\Surface\Scope;

$op = new OffrollPay($store, $customisation, __DIR__ . '/../../config/offroll-agents.json');
$opReady = $op->ready();
$opD = $op->declared();
$opSet = $op->settings();
$opWindow = (int) (FieldReports::config(__DIR__ . '/../../config/field-reports.json')['expiry_window_days'] ?? 30);
$opIsAdmin = isAdministrator();
$opMayEdit = $may('employee.update');
$opMayApprove = $may('payroll.run');
$opCompanies = $opBranches = $opBranchCo = [];

foreach (liveRows($store, 'companies') as $r) {
    $opCompanies[(string) $r['id']] = (string) ($r['name'] ?? '');
}

foreach (liveRows($store, 'branches') as $r) {
    $opBranches[(string) $r['id']] = (string) ($r['name'] ?? '');
    $opBranchCo[(string) $r['id']] = (string) ($r['employer_company_id'] ?? '');
}

asort($opCompanies);
asort($opBranches);
$opVendors = $opReady ? $op->vendors() : [];
$opEmpLabel = [];
$opVisibleEmp = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $opVisibleEmp[(string) $e['id']] = true;
}

foreach (liveRows($store, 'employees') as $e) {
    $opEmpLabel[(string) $e['id']] = Employment::label($e);
}

$opWhole = $opIsAdmin || ($may('employee.list') && $scope->beyondLine() === Scope::COMPANY);
$opMyBranches = $scope->myBranches();
$opAgents = [];

foreach ($opReady ? $op->agents() : [] as $a) {
    $sup = trim((string) ($a['supervisor_employee_id'] ?? ''));
    $br = trim((string) ($a['branch_id'] ?? ''));

    if ($opWhole || ($sup !== '' && isset($opVisibleEmp[$sup])) || ($br !== '' && in_array($br, $opMyBranches, true))) {
        $opAgents[(string) $a['id']] = $a;
    }
}

uasort($opAgents, static fn (array $x, array $y): int => strnatcasecmp($op->code($x), $op->code($y)));

$opMoney = static fn (mixed $v): string => Amount::of((string) $v)->format();
$opBand = static fn (?string $d, ?string $on = null): string => OffrollPay::certBand($d, $on ?? date('Y-m-d'), $opWindow);
$opBadge = ['expired' => 'badge-red', 'due' => 'badge-amber', 'valid' => 'badge-green', 'none' => 'badge-gray'];
$opBandLabel = ['expired' => 'Expired', 'due' => 'Expiring ≤ ' . $opWindow . ' d', 'valid' => 'Valid', 'none' => 'Not on file'];
$opVendorName = static fn (array $a): string => (string) ($opVendors[(string) ($a['vendor_id'] ?? '')]['name'] ?? trim((string) ($a['vendor'] ?? '')));
$opNotReady = '<div class="card"><div class="problem" style="margin:0">The off-roll agent tables are not in this database yet. '
    . 'An administrator runs the pending migration (<code>2026_10_23_000300_offroll_agents_payouts</code>) once; nothing else is needed.</div></div>';
// A bulk-sheet column in the shape ModuleBulk reads (the same sample / import every register uses).
$opCol = static fn (string $name, string $label, string $type = 'text', bool $required = false, array $lookup = [], array $options = []): array
    => ['name' => $name, 'label' => $label, 'type' => $type, 'required' => $required, 'readonly' => false, 'lookup' => $lookup, 'options' => $options, 'min' => null];
$opAgentLookup = array_values(array_map(static fn (array $a): array => ['id' => (string) $a['id'], 'label' => $op->label($a)], $opAgents));
$opBack = static function (string $to): never {
    header('Location: ' . $to);
    exit;
};
