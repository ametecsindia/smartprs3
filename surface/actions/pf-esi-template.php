<?php
/**
 * PF & ESI REMITTANCE TEMPLATE, EVERY EMPLOYEE PRE-LISTED (owner, 14 Oct): the register's own
 * columns (ModuleBulk — the same sheet the upload reads), one row per active employee in reach with
 * the wage month, UAN and ESI IP number already filled from the employee record. HR fills the
 * amounts, TRRN / challan and paid-on dates; rows left without any amount are skipped on upload.
 */
declare(strict_types=1);

use SmartPRS\Surface\ModuleBulk;

// ONE TEMPLATE ACTION FOR BOTH REMITTANCE REGISTERS (14 Oct): PF & ESI, and TDS — `k` picks it.
$ptKey = in_array(post('k'), ['tds-remittances', 'pt-remittances'], true) ? post('k') : 'pf-esi-remittances';
$ptModule = $modules->find($ptKey);

if ($ptModule === null || !$may($modules->permission($ptModule, 'edit'))) {
    $_SESSION['flash_problem'] = $ptModule === null ? 'No such register.' : 'Denied: ' . $access->decide((string) $session->user()['role'], $modules->permission($ptModule, 'edit'))->summary;
    header('Location: ?r=' . $ptKey);
    exit;
}

$ptMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', trim(post('month'))) === 1 ? trim(post('month')) : date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
$ptFields = ModuleBulk::columns($modules->fields($ptModule));
$ptLabel = [];

foreach ($ptFields as $f) {
    if ($f['name'] === 'employee_id') {
        foreach ((array) $f['lookup'] as $o) { $ptLabel[(string) $o['id']] = (string) $o['label']; }
    }
}

$ptEmployees = array_filter($scope->only(liveRows($store, 'employees'), $may('employee.list')),
    static fn (array $e): bool => in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true));
usort($ptEmployees, static fn (array $a, array $b): int => strnatcasecmp(SmartPRS\Surface\Employment::code($a), SmartPRS\Surface\Employment::code($b)));
$ptData = [];

// TDS: the month's salary paid and TDS deducted come straight from the COMPLETED payroll, so HR
// only adds the challan. PF & ESI: UAN and ESI IP number from the employee record.
$ptRun = [];
if ($ptKey === 'tds-remittances') {
    $ptCodes = (array) (($taxDeclarations->year(SmartPRS\Surface\IncomeTax::fyOf($ptMonth . '-01'))['salary_codes'] ?? [])['tds'] ?? ['TDS']);
    foreach ((array) ($taxDeclarations->runRows()[$ptMonth] ?? []) as $k => $row) {
        $t = 0;
        foreach ((array) ($row['lines'] ?? []) as $l) { if (($l['kind'] ?? '') !== 'earning' && in_array((string) preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? '')), $ptCodes, true)) { $t += (int) ($l['minor'] ?? 0); } }
        $ptRun[(string) $k] = ['paid' => SmartPRS\Surface\IncomeTax::p($row['earnings'] ?? ''), 'tds' => $t];
    }
}

// PT (15 Oct): the month's gross and PT deducted from the completed payroll, and the employee's PT
// state (branch state, else company state), so HR only adds the challan.
$ptState = [];
if ($ptKey === 'pt-remittances') {
    $ptCal = SmartPRS\Surface\StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
    foreach ($register->runs() as $run) {
        if ((string) $run['period'] !== $ptMonth) { continue; }
        foreach ((array) ($run['rows'] ?? []) as $k => $row) {
            $ptRun[(string) $k] = ['paid' => SmartPRS\Surface\IncomeTax::p($row['earnings'] ?? ''),
                'pt' => SmartPRS\Surface\StatutoryReports::linesMinor((array) $row, (array) ($ptCal['heads']['PT']['line_codes'] ?? ['PT']))];
        }
    }
    $ptCoState = [];
    foreach (liveRows($store, 'companies') as $c) { $ptCoState[trim((string) ($c['name'] ?? ''))] = trim((string) ($c['state'] ?? '')); }
    foreach (liveRows($store, 'branches') as $b) { $ptState[(string) $b['id']] = trim((string) ($b['state'] ?? '')); }
}

foreach ($ptEmployees as $e) {
    $run = $ptRun[(string) $e['id']] ?? $ptRun[SmartPRS\Surface\Employment::code($e)] ?? null;
    if ($ptKey === 'pt-remittances') {
        $m = static fn (int $x): string => $x === 0 ? '' : SmartPRS\Platform\Support\Money::ofMinor($x)->toDecimalString();
        $prefill = ['employee_id' => $ptLabel[(string) $e['id']] ?? SmartPRS\Surface\Employment::label($e), 'month' => $ptMonth,
            'state' => ($ptState[(string) ($e['branch_id'] ?? '')] ?? '') ?: ($ptCoState[orgValueOf($store, $e, 'company')] ?? ''),
            'gross_wages' => $run === null ? '' : $m($run['paid']), 'pt_deducted' => $run === null ? '' : $m($run['pt']), 'pt_paid' => $run === null ? '' : $m($run['pt'])];
        $ptData[] = array_map(static fn (array $f): string => (string) ($prefill[$f['name']] ?? ''), $ptFields);
        continue;
    }
    $prefill = ['employee_id' => $ptLabel[(string) $e['id']] ?? SmartPRS\Surface\Employment::label($e), 'month' => $ptMonth,
        'uan' => trim((string) ($e['uan'] ?? '')), 'esi_ip_number' => trim((string) ($e['esic_no'] ?? '')), 'pan' => strtoupper(trim((string) ($e['pan'] ?? ''))),
        'amount_paid' => $run === null ? '' : SmartPRS\Platform\Support\Money::ofMinor($run['paid'])->toDecimalString(),
        'tds_deducted' => $run === null || $run['tds'] === 0 ? '' : SmartPRS\Platform\Support\Money::ofMinor($run['tds'])->toDecimalString(),
        'tds_deposited' => $run === null || $run['tds'] === 0 ? '' : SmartPRS\Platform\Support\Money::ofMinor($run['tds'])->toDecimalString()];
    $ptData[] = array_map(static fn (array $f): string => (string) ($prefill[$f['name']] ?? ''), $ptFields);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . (['tds-remittances' => 'tds', 'pt-remittances' => 'pt'][$ptKey] ?? 'pf-esi') . '-remittance-' . $ptMonth . '.xlsx"');
echo ModuleBulk::sample($ptFields, $ptData);
exit;
