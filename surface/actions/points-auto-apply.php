<?php
/**
 * AUTO-APPLY THE POINTS RULES FOR A MONTH.
 *
 * Counts each employee's events for the month from the muster (late days,
 * days by status), the awards register and the test attempts; hands the
 * counts and the rule rows to Surface\Points::apply(); writes the resulting
 * ledger rows. A month already applied is refused: the ledger is a ledger.
 */
declare(strict_types=1);

use SmartPRS\Surface\Points;

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : post('f_' . $k); // the bar's choice travels with the POST
}

$back = '?r=points-scores' . orgFilterQuery($filter);

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$month = post('month');
$points = new Points($store, __DIR__ . '/../../config/points.json');
$cfg = $points->config();

if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    $_SESSION['flash_problem'] = 'Pick a month.';
    header('Location: ' . $back);
    exit;
}

$people = orgFilterApply($store, $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.list')), $filter);
$byId = [];

foreach ($people as $e) {
    $byId[(string) $e['id']] = $e;
}

if ($points->applied($month, array_map('strval', array_keys($byId)))) { // numeric keys come back as ints
    $_SESSION['flash_problem'] = $month . ' was already auto-applied for somebody in view. Delete their auto:' . $month . ' rows on the ledger first if it must be redone.';
    header('Location: ' . $back);
    exit;
}

$first = $month . '-01';
$last = date('Y-m-t', (int) strtotime($first));
$muster = $attendanceReport->muster($people, $first, $last, $calendar->shiftStart(), $calendar->shiftEnd(), date('Y-m-d'));
$totals = [];

foreach ($muster['rows'] as $row) {
    $totals[(string) $row['employment_id']] = $row['totals'];
}

$events = $points->monthEvents($totals, $byId, liveRows($store, 'awards'), $store->all('test_attempts'), $month);
$written = 0;
$totalPoints = 0;

foreach ($byId as $id => $e) {
    $rules = Points::rulesByEvent(liveRows($store, 'point_rules'), (string) ($e['employer_company_id'] ?? ''));
    $own = array_values(array_filter($events, static fn (array $ev): bool => $ev['employee_id'] === (string) $id));

    foreach (Points::apply($rules, $own, $month, (string) ($cfg['auto_note_prefix'] ?? 'Auto: ')) as $row) {
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = $row['created_at'];
        $stored = $store->put('points_ledger', $row);
        $audit('points.auto_applied', 'points_ledger', (string) $stored['id'], null, $stored, 'Auto-applied rules for ' . $month);
        $written++;
        $totalPoints += (int) $row['points'];
    }
}

$_SESSION['flash_notice'] = $written === 0
    ? 'Nothing to apply for ' . $month . ': no counted event has a rule with points, for the employees in view.'
    : 'Applied ' . $month . ': ' . $written . ' ledger row(s), net ' . $totalPoints . ' points, across ' . count($byId) . ' employee(s) in view.';
header('Location: ' . $back);
exit;
