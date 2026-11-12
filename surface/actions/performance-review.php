<?php
/**
 * SAVE ONE STEP OF A PERFORMANCE REVIEW: the self-assessment, the manager
 * rating, or the finalisation. The employee may write their own self step,
 * the reporting manager on the record may write the manager step, HR
 * (employee.update) may write any step. A finalised review is closed.
 */
declare(strict_types=1);

use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;
use SmartPRS\Surface\SqlStore;

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : post('f_' . $k);
}

$employeeId = post('employee_id');
$period = post('period');
$step = post('step');
$back = '?r=performance&period=' . rawurlencode($period) . '&emp=' . rawurlencode($employeeId) . orgFilterQuery($filter);
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

if (!$may('employee.view')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary);
}

$perf = new Performance(__DIR__ . '/../../config/performance.json');
$employee = $employeeId === '' ? null : $store->find('employees', $employeeId);

if ($employee === null || !in_array($period, $perf->periods(date('Y-m-d')), true)) {
    $fail('No such employee or period.');
}

$allPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $allPeople[(string) $e['id']] = $e;
}

$own = $scope->own();
$isHr = $may('employee.update');
$isSelf = $own !== null && (string) $own === $employeeId;
$reviewer = Performance::reviewerOf($employee, $allPeople);
$isReviewer = $own !== null && $reviewer['id'] !== '' && (string) $own === $reviewer['id'];

$allowed = match ($step) {
    'self' => $isSelf || $isHr,
    'manager' => $isReviewer || $isHr,
    'finalise' => $isHr,
    default => false,
};

if (!$allowed) {
    $fail('That step is not yours to write: the employee writes the self-assessment, the reporting manager the manager rating, HR finalises.');
}

$review = null;

foreach ($store->all(Performance::TABLE) as $r) {
    if ((string) ($r['employee_id'] ?? '') === $employeeId && (string) ($r['cycle'] ?? '') === $period) {
        $review = $r;

        break;
    }
}

if ($review !== null && (string) ($review['status'] ?? '') === 'finalised') {
    $fail('This review is finalised. A correction is a new period, never an edit.');
}

// THE SCALE THIS REVIEW IS RATED ON (21 Sep 2026, owner: "customisable …
// wired to the respective company, branch, department"). The tenant's own
// Performance Appraisal criteria for the financial year this period ends in,
// the most specific box covering this employee — rating labels, exact
// numbers or slab ranges, whichever that box chose. Once a review has a
// rating its scale is fixed: a later edit to the criteria cannot turn an "S"
// already given into a number. No criterion covering them: the shipped 1–5
// scale, exactly as before.
$paAppraisal = new PerformanceAppraisal($customisation);
$paFyStart = financialYearStart($customisation);
$rated = $review !== null && (trim((string) ($review['self_rating'] ?? '')) !== '' || trim((string) ($review['self_rating_value'] ?? '')) !== ''
    || trim((string) ($review['manager_rating'] ?? '')) !== '' || trim((string) ($review['manager_rating_value'] ?? '')) !== '');
$scaleOf = null;

if ($review !== null && trim((string) ($review['appraisal_box_id'] ?? '')) !== '') {
    $pinned = $paAppraisal->box((string) $review['appraisal_key'], (string) $review['appraisal_box_id']);
    $scaleOf = $pinned === null ? null : ['fy' => (string) $review['appraisal_key'], 'box' => $pinned, 'format' => PerformanceAppraisal::format($pinned)];
    $pinned === null && $fail('The appraisal scale this review was rated on (' . PerformanceAppraisal::label((string) $review['appraisal_key']) . ') no longer exists. Restore it under Performance Appraisal before continuing.');
} elseif (!$rated) {
    $scaleOf = $paAppraisal->scaleFor($period, ['role' => orgValueOf($store, $employee, 'role'), 'employee' => orgValueOf($store, $employee, 'employee')]
        + orgEnrich($store, $employee), $paFyStart);
}

// A rating as given, checked against that scale: [value as stored, whole number or null].
$accept = static function (string $typed) use ($scaleOf, $perf): ?array {
    if ($scaleOf === null) {
        $r = $perf->rating($typed);

        return $r === null ? null : ['value' => (string) $r, 'int' => $r];
    }

    $v = PerformanceAppraisal::acceptValue($scaleOf['box'], $typed);

    return $v === null ? null : ['value' => $v, 'int' => preg_match('/^\d+$/', $v) === 1 ? (int) $v : null];
};
$offScale = $scaleOf === null ? 'Pick a rating on the scale.'
    : "That rating is not on this employee's scale (" . PerformanceAppraisal::describeScale($scaleOf['box']) . ').';
$hasValueColumns = !($store instanceof SqlStore) || in_array('final_rating_value', $store->columnsOf(Performance::TABLE), true);
$scaleOf !== null && !$hasValueColumns
    && $fail("This employee is rated on the company's own appraisal scale, which needs the database updated first: run `composer db:migrate` (migration 2026_09_21_000200).");
$valueOf = static fn (?array $r, string $col): ?string => $r === null ? null
    : (trim((string) ($r[$col . '_value'] ?? '')) !== '' ? (string) $r[$col . '_value'] : (trim((string) ($r[$col] ?? '')) !== '' ? (string) (int) $r[$col] : null));

$before = $review;
$now = date('Y-m-d H:i:s');
$review ??= ['employee_id' => $employeeId, 'employer_company_id' => trim((string) ($employee['employer_company_id'] ?? '')) === '' ? null : (string) $employee['employer_company_id'],
    'employee' => SmartPRS\Surface\Employment::label($employee), 'cycle' => $period, 'status' => 'draft', 'created_at' => $now];
$review['reviewer_id'] = $reviewer['id'] === '' ? null : $reviewer['id']; // integer column: blank is NULL
$review['reviewer'] = $reviewer['label'];
$review['updated_at'] = $now;
$user = $session->user();

if ($hasValueColumns) {
    $review['rating_format'] = $scaleOf === null ? 'shipped' : $scaleOf['format'];
    $review['appraisal_key'] = $scaleOf === null ? null : $scaleOf['fy'];
    $review['appraisal_box_id'] = $scaleOf === null ? null : (string) $scaleOf['box']['id'];
}

if ($step === 'self') {
    $rating = $accept(post('self_rating'));
    $rating === null && $fail($offScale);
    $review['self_rating'] = $rating['int'];
    $hasValueColumns && $review['self_rating_value'] = $rating['value'];
    $review['self_comment'] = post('self_comment');
    $review['status'] = 'self_submitted';
} elseif ($step === 'manager') {
    $rating = $accept(post('manager_rating'));
    $rating === null && $fail($offScale);
    $review['manager_rating'] = $rating['int'];
    $hasValueColumns && $review['manager_rating_value'] = $rating['value'];
    $review['manager_comment'] = post('manager_comment');
    $review['status'] = 'submitted';
    $review['submitted_at'] = $now;
} else {
    $overrideGiven = post('final_rating') === '' ? null : $accept(post('final_rating'));
    post('final_rating') !== '' && $overrideGiven === null && $fail('The override is not on the scale. ' . $offScale);
    $override = $overrideGiven['value'] ?? null;
    $self = $valueOf($review, 'self_rating');
    $manager = $valueOf($review, 'manager_rating');
    ($self === null || $manager === null)
        && $fail('Both the self-assessment and the manager rating are needed before this can be finalised, even with an override — an HR override replaces which rating counts, not whether both were given.');
    $final = $perf->finalRating($self, $manager, $override);
    $final['rating'] === null && $fail('Nothing to finalise: no rating has been given yet.');
    $finalValue = (string) $final['rating'];
    $review['final_rating'] = preg_match('/^\d+$/', $finalValue) === 1 ? (int) $finalValue : null;
    $hasValueColumns && $review['final_rating_value'] = $finalValue;
    $review['rating'] = preg_match('/^\d{1,3}(\.\d{1,2})?$/', $finalValue) === 1 ? $finalValue : null; // decimal(5,2): a label has no number
    $review['status'] = 'finalised';
    $review['finalised_at'] = $now;
    $review['finalised_by'] = (string) ($user['email'] ?? '');
}

$stored = $store->put(Performance::TABLE, $review);
$audit('performance.review.' . $step, Performance::TABLE, (string) $stored['id'], $before, $stored,
    $step === 'finalise' ? ('Finalised: ' . $final['working']) : ('Step ' . $step . ' for ' . $period));
$_SESSION['flash_notice'] = match ($step) {
    'self' => 'Self-assessment saved for ' . $period . '.',
    'manager' => 'Manager rating saved for ' . $period . '.',
    default => 'Review finalised: ' . $final['working'] . '.',
};
header('Location: ' . $back);
exit;
