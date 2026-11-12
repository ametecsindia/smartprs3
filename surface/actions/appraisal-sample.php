<?php
/**
 * RATING SAMPLE FOR ONE APPRAISAL CRITERIA BOX (owner, 8 Oct): every employee
 * this box rates (in reach, and it is their most specific box), with a Rating
 * dropdown of exactly the box's scale, the review period, and the effective
 * date prefilled. Filled in, it goes back through Increment / Appraisal →
 * Import ratings (surface/actions/appraisal-import.php).
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;
use SmartPRS\Surface\Sheet;

require_once __DIR__ . '/../partials/appraisal-common.php';

$asBack = post('back') === 'increments' ? '?r=' . moduleRoute('increments') : '?r=performance-appraisal';
$asFail = static function (string $why) use ($asBack): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $asBack);
    exit;
};

if (!$may('employee.update') && !$may('tenant.settings.update')) {
    $asFail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary);
}

$asAppraisal = new PerformanceAppraisal($customisation);
$asFyStart = financialYearStart($customisation);
[$asFy, $asBoxId] = array_pad(explode('|', post('cycle'), 2), 2, '');
$asBox = $asAppraisal->box($asFy, $asBoxId);
$asPeriod = post('period');

if ($asBox === null) {
    $asFail('Pick an appraisal criteria (Organization Configuration → Performance Appraisal) first.');
}

if (!PerformanceAppraisal::keyCoversPeriod($asFy, $asPeriod, $asFyStart)) {
    $asFail("Review period '{$asPeriod}' does not end inside " . PerformanceAppraisal::label($asFy) . '. Pick a period that belongs to it.');
}

$asPeople = appraisalCovered($store, $asAppraisal, $asFy, $asBoxId, $scope->only(liveRows($store, 'employees'), $may('employee.list')));
$asSeeCtc = $may('employee.salary.view');
$asFinal = [];

foreach ($store->all(Performance::TABLE) as $r) {
    if ((string) ($r['cycle'] ?? '') === $asPeriod && (string) ($r['status'] ?? '') === 'finalised') {
        $asFinal[(string) ($r['employee_id'] ?? '')] = trim((string) ($r['final_rating_value'] ?? '')) !== '' ? (string) $r['final_rating_value'] : (string) ($r['final_rating'] ?? '');
    }
}

$asLookups = attendanceLookups($store, employmentLabels($store));
$asCycleTag = 'PA|' . $asFy . '|' . $asBoxId . '|' . $asPeriod;
$asEffective = appraisalDefaultEffective($asFy, $asFyStart);
$asFormat = PerformanceAppraisal::format($asBox);
$asSlabs = (array) ($asBox['slabs'] ?? []);
$asHead = ['Employee ID *', 'Employee Name', 'Company', 'Branch', 'Department', 'Designation', 'Current CTC (₹)', 'Rating *', 'Effective Date', 'Remarks', 'Appraisal cycle (do not change)'];
$asCodes = ['emp_code', 'name', 'company', 'branch', 'department', 'designation', 'current_ctc', 'rating', 'effective', 'remarks', 'cycle'];
$asScaleText = $asFormat === 'slab'
    ? 'Any number in ' . implode(', ', array_map(static fn (array $s): string => $s['rating'] . '–' . ($s['rating_to'] ?? ''), $asSlabs))
    : 'Pick from the list: ' . implode(', ', array_map(static fn (array $s): string => $s['rating'] . (trim((string) ($s['description'] ?? '')) !== '' ? ' (' . $s['description'] . ')' : '') . ' = ' . $s['increment_pct'] . '%', $asSlabs));
$asHints = ['Required · do not change', 'Read only', 'Read only', 'Read only', 'Read only', 'Read only', 'Read only', 'Required · ' . $asScaleText,
    'Date dd-mm-yyyy (blank = ' . SmartPRS\Surface\Dates::dmy($asEffective) . ')', 'Text', PerformanceAppraisal::label($asFy) . ' · ' . $asPeriod];
$asRows = [$asHead, $asCodes, $asHints];

foreach ($asPeople as $e) {
    $org = employeeOrgCells($store, $e, $asLookups);
    $asRows[] = [Employment::code($e), (string) ($e['name'] ?? ''), $org['company'], $org['branch'], $org['department'], $org['designation'],
        $asSeeCtc ? Employment::ctc($e) : '', $asFinal[(string) $e['id']] ?? '', $asEffective, '', $asCycleTag];
}

$asLists = $asFormat === 'slab' ? [] : [7 => array_map(static fn (array $s): string => (string) $s['rating'], $asSlabs)];
$asChecks = [];

if ($asFormat === 'slab' && $asSlabs !== []) {
    $min = min(array_map(static fn (array $s): float => (float) $s['rating'], $asSlabs));
    $max = max(array_map(static fn (array $s): float => (float) ($s['rating_to'] ?? $s['rating']), $asSlabs));
    $asChecks[7] = ['formula' => "AND(ISNUMBER({c}),{c}>={$min},{c}<={$max})", 'title' => 'Not on the scale', 'message' => $asScaleText];
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="appraisal-ratings-' . preg_replace('/[^A-Za-z0-9-]+/', '-', $asFy . '-' . $asPeriod) . '.xlsx"');
echo Sheet::xlsx($asRows, $asLists, 3, [], $asChecks, [8], [], [], [0 => 14.0, 1 => 26.0, 2 => 18.0, 3 => 16.0, 4 => 16.0, 5 => 18.0, 6 => 14.0, 7 => 14.0, 8 => 14.0, 9 => 24.0, 10 => 34.0]);
exit;
