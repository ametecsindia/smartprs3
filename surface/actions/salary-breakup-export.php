<?php
/**
 * EXPORT THE SALARY BREAKUP (owner, 15 Oct) — Excel or CSV, exactly the filters and the
 * monthly / annual view on the screen. Same rows as the screen: partials/salary-breakup-lib.php.
 */
declare(strict_types=1);

use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/salary-breakup-lib.php';
include_once __DIR__ . '/../partials/salary-annexure.php';
($may('payroll.view') || isAdministrator()) || (function (): never { $_SESSION['flash_problem'] = 'Denied: exports need payroll.view.'; header('Location: ?r=salary-breakup'); exit; })();
$filter = [];
foreach (array_keys(ORG_FILTERS) as $k) { $v = trim(post('f_' . $k)); $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v; }
$annual = post('view') === 'annual';
if (post('kind') === 'month') {
    // THE MONTHLY SALARY STATEMENT TAB — the generated payroll for the month picked.
    include_once __DIR__ . '/../partials/salary-statement-lib.php';
    $ssD = ssData(preg_match('/^\d{4}-\d{2}$/', post('month')) === 1 ? post('month') : '', $filter);
    $t = ssTable($ssD);
    $name = 'salary-statement-' . $ssD['month'];
    $title = 'Monthly salary statement — ' . date('F Y', (int) strtotime($ssD['month'] . '-01'));
} else {
    $t = sbTable(sbData($filter), $annual);
    $name = 'salary-breakup-' . ($annual ? 'annual' : 'monthly') . '-' . date('Y-m-d');
    $title = 'Salary breakup (CTC structure) — ' . ($annual ? 'annual' : 'monthly');
}
$audit('payroll.salary_breakup.exported', 'employees', 'salary-breakup', null, ['rows' => count($t['rows']), 'format' => post('format'), 'view' => $annual ? 'annual' : 'monthly'], 'Salary breakup export');

if (post('format') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names as UTF-8
    fputcsv($out, $t['head'], ',', '"', '');
    foreach ($t['rows'] as $row) { fputcsv($out, $row, ',', '"', ''); }
    fclose($out);
    exit;
}

$bytes = Sheet::report($title, 'Generated ' . date('d-m-Y H:i') . ' · amounts in rupees',
    $t['head'], $t['rows'], $t['money'], [], 'Salary breakup');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
echo $bytes;
exit;
