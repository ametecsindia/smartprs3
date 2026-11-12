<?php
/**
 * APPRAISAL EXPORT (owner, 8 Oct) — Increment / Appraisal tab: per employee the
 * previous CTC, the rating, the % increase that rating carries, the amount
 * increased and the CTC after the appraisal. Formatted Excel or CSV, for the
 * chosen criteria / period (blank = every appraisal) and the org filters.
 */
declare(strict_types=1);

use SmartPRS\Surface\PerformanceAppraisal;
use SmartPRS\Surface\Sheet;

require_once __DIR__ . '/../partials/appraisal-common.php';

if (!$may('employee.view') || !$may('employee.salary.view')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $may('employee.view') ? 'employee.salary.view' : 'employee.view')->summary;
    header('Location: ?r=' . moduleRoute('increments'));
    exit;
}

foreach (array_keys(ORG_FILTERS) as $k) {
    $_GET['f_' . $k] = post('f_' . $k);
}

[$axFy] = array_pad(explode('|', post('cycle'), 2), 1, '');
$axPeriod = post('period');
$axAppraisal = new PerformanceAppraisal($customisation);
$axPeople = orgFilterApply($store, $scope->only(liveRows($store, 'employees'), $may('employee.list')), orgFilter());
$axRows = appraisalResults($store, $axAppraisal, $axPeople, $axFy, $axFy === '' ? '' : $axPeriod);
$axHead = ['Employee ID', 'Employee Name', 'Company', 'Branch', 'Department', 'Designation', 'Appraisal criteria', 'Review period', 'Rating',
    'Rating description', 'Previous CTC (₹)', '% Increase (as per rating)', 'Amount increased (₹)', 'CTC after appraisal (₹)', 'Effective date', 'Status'];
$axKeys = ['emp_code', 'name', 'company', 'branch', 'department', 'designation', 'criteria', 'period', 'rating', 'description', 'old', 'pct', 'amount', 'new', 'effective', 'status'];
$axOut = array_map(static fn (array $r): array => array_map(static fn (string $k): string => (string) $r[$k], $axKeys), $axRows);
$axSum = static function (string $k) use ($axRows): string {
    $t = SmartPRS\Surface\Amount::of('0');

    foreach ($axRows as $r) {
        $r[$k] === '' || $t = $t->plus(SmartPRS\Surface\Amount::of($r[$k]));
    }

    return $t->toDecimalString();
};
$axRows === [] || $axOut[] = ['Total', count($axRows) . ' employee(s)', '', '', '', '', '', '', '', '', $axSum('old'), '', $axSum('amount'), $axSum('new'), '', ''];
$axName = 'appraisal-' . ($axFy === '' ? 'all' : preg_replace('/[^A-Za-z0-9-]+/', '-', $axFy . '-' . $axPeriod));
$axTitleWhat = $axFy === '' ? 'all criteria' : PerformanceAppraisal::label($axFy) . ' · review period ' . $axPeriod;

if (post('fmt') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $axName . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $axHead);

    foreach ($axOut as $r) {
        fputcsv($out, $r);
    }

    fclose($out);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $axName . '.xlsx"');
echo Sheet::report('Increment / Appraisal — ' . $axTitleWhat, 'Generated ' . date('d-m-Y H:i') . ' by ' . (string) ($session->user()['email'] ?? ''),
    $axHead, $axOut, [10, 11, 12, 13], $axRows === [] ? [] : [count($axOut) - 1], 'Appraisal');
exit;
