<?php
/**
 * OFF-ROLL AGENTS — EXCEL DOWNLOADS (23 Oct 2026): the agents list (what=agents) and any Agent Pay Report
 * (what=report, the same rows the screen shows). Formatted workbooks: header, sized columns, amounts as numbers,
 * account numbers kept as text, dates dd-mm-yyyy.
 */
declare(strict_types=1);

use SmartPRS\Surface\OffrollPay;
use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/offroll-boot.php';

if (!$may('employee.view') || !$opReady) {
    $_SESSION['flash_problem'] = $opReady ? 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary : 'The off-roll agent tables are not migrated yet.';
    header('Location: ?r=offroll-agents');
    exit;
}

if (post('what') === 'report') {
    $params = $_POST;
    include __DIR__ . '/../partials/offroll-report-data.php';
    $bytes = Sheet::report($rep['title'], $rep['subtitle'], $rep['head'], $rep['rows'], $rep['money'], $rep['bold'], 'Report');
    $file = 'agent-' . $rep['key'] . '-' . date('Y-m-d') . '.xlsx';
} else {
    $plans = $op->plansByAgent();
    $rows = [];

    foreach ($opAgents as $id => $a) {
        $rows[] = [$op->code($a), (string) ($a['name'] ?? ''), (string) ($a['mobile'] ?? ''), $opCompanies[(string) ($a['employer_company_id'] ?? '')] ?? '', $opBranches[(string) ($a['branch_id'] ?? '')] ?? '',
            $opVendorName($a), OffrollPay::paidTo($a) === 'vendor' ? 'Vendor' : 'Agent', $opEmpLabel[(string) ($a['supervisor_employee_id'] ?? '')] ?? '',
            (string) ($a['client_bank'] ?? ''), (string) ($a['portfolio'] ?? ''), substr((string) ($a['joining_date'] ?? ''), 0, 10), substr((string) ($a['exit_date'] ?? ''), 0, 10),
            (string) ($a['pan'] ?? ''), (string) ($a['bank_name'] ?? ''), (string) ($a['bank_account'] ?? ''), (string) ($a['ifsc'] ?? ''),
            (string) ($a['dra_cert_no'] ?? ''), substr((string) ($a['dra_expiry'] ?? ''), 0, 10), $opBandLabel[$opBand((string) ($a['dra_expiry'] ?? ''))],
            (string) ($a['pcc_cert_no'] ?? ''), substr((string) ($a['pcc_expiry'] ?? ''), 0, 10), $opBandLabel[$opBand((string) ($a['pcc_expiry'] ?? ''))],
            $op->planSummary(OffrollPay::planOn($plans[$id] ?? [], $a, date('Y-m-d'))), (string) ($a['status'] ?? '')];
    }

    $bytes = Sheet::report('Off-roll agents', 'As of ' . date('Y-m-d') . ' · ' . count($rows) . ' agent(s)',
        ['Code', 'Name', 'Mobile', 'Company', 'Branch', 'Vendor', 'Paid to', 'Supervisor', 'Client bank', 'Portfolio', 'Deployed from', 'Exit', 'PAN', 'Bank', 'Account number', 'IFSC',
            'DRA number', 'DRA valid up to', 'DRA', 'PCC number', 'PCC valid up to', 'PCC', 'Pay structure today', 'Status'], $rows, [], [], 'Agents');
    $file = 'offroll-agents-' . date('Y-m-d') . '.xlsx';
}

$audit('offroll.exported', 'offroll_agent', null, null, ['what' => post('what'), 'rep' => post('rep')], 'Downloaded');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
echo $bytes;
exit;
