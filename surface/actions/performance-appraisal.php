<?php
/**
 * SAVE ONE FY's SCOPE-BOX (year/period + who it applies to + its rating type
 * + its slabs), or APPLY a FY's boxes to the employees currently in view.
 * Both need `tenant.settings.update` (Organization Configuration); apply
 * additionally needs `employee.salary.update` because it changes pay, the
 * same permission Increments' own new_ctc field is gated on.
 */
declare(strict_types=1);

use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;

$step = post('step');
$fy = $step === 'save'
    ? PerformanceAppraisal::buildKey((int) post('year'), post('granularity'), post('sub'))
    : trim(post('fy'));
$back = '?r=performance-appraisal&fy=' . rawurlencode($fy);
$fyStartMonth = financialYearStart($customisation);
// WHAT WAS TYPED COMES BACK WITH THE REFUSAL (owner, 21 Sep: a refused save
// used to throw away every slab row just entered). The screen reopens the
// panel from this and marks each field the check named.
$fail = static function (string $why, array $fieldErrors = []) use ($back, $step): never {
    $_SESSION['flash_problem'] = $why;

    if ($step === 'save') {
        $_SESSION['pa_old'] = ['post' => array_intersect_key($_POST, array_flip(['year', 'granularity', 'sub', 'box_id', 'format', 'type', 'slab_mode',
            'scope_company', 'scope_branch', 'scope_department', 'scope_role', 'scope_designation', 'scope_team', 'scope_employee', 'slabs'])),
            'errors' => $fieldErrors];
    }

    header('Location: ' . $back);
    exit;
};

if (!$may('tenant.settings.update')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary);
}

$byId = (string) ($session->user()['id'] ?? '');
$byLabel = (string) $session->user()['email'];
$appraisal = new PerformanceAppraisal($customisation);

if ($step === 'save') {
    if ($fy === '') {
        $fail('Pick a year, and a valid period for the chosen period type.');
    }

    // NAMED $boxScope, NOT $scope — $scope is this request's global Scope
    // object (used below in the apply branch); shadowing it here would be a
    // trap for whoever edits this file next, even though this branch exits
    // before that branch runs.
    $boxScope = [];

    foreach (PerformanceAppraisal::SCOPE_DIMENSIONS as $d) {
        $boxScope[$d] = trim(post('scope_' . $d));
    }

    // RATING FORMAT — how reviewers will rate people this box covers: rating
    // labels, exact numbers, or any number inside slab ranges.
    $format = PerformanceAppraisal::FORMATS[post('format')] ?? ['type' => post('type'), 'slab_mode' => post('slab_mode')];
    $result = $appraisal->saveBox($fy, post('box_id') !== '' ? post('box_id') : null, $boxScope,
        (string) $format['type'], (array) ($_POST['slabs'] ?? []), $byId, $byLabel, (string) $format['slab_mode']);
    $result['ok'] ? $_SESSION['flash_notice'] = $result['why'] : $fail($result['why'], (array) ($result['errors'] ?? []));
    $audit('performance.appraisal.box_saved', PerformanceAppraisal::KEY, $fy, null,
        ['scope' => $boxScope, 'format' => post('format'), 'slabs' => (array) ($_POST['slabs'] ?? [])], $result['why']);
    header('Location: ' . $back);
    exit;
}

if ($step !== 'apply') {
    $fail('Unknown step.');
}

if (!$may('employee.salary.update')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.salary.update')->summary);
}

$perf = new Performance(__DIR__ . '/../../config/performance.json');
$scale = $perf->scale();
$period = post('period');
$boxes = $appraisal->boxesFor($fy);

if ($fy === '' || $boxes === []) {
    $fail("No customisation saved for '" . PerformanceAppraisal::label($fy) . "' yet — add one first.");
}

if (!in_array($period, $perf->periods(date('Y-m-d')), true)) {
    $fail('No such review period.');
}

// WIRED TO THE FINANCIAL YEAR: a period's ratings are only ever applied with
// the table written for the key whose dates hold that period's last day.
if (!PerformanceAppraisal::keyCoversPeriod($fy, $period, $fyStartMonth)) {
    $kr = PerformanceAppraisal::range($fy, $fyStartMonth);
    $fail("Review period {$period} does not end inside " . PerformanceAppraisal::label($fy)
        . ($kr === null ? '' : " ({$kr['from']} to {$kr['to']})") . ' — pick a period that belongs to that financial year.');
}

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : post('f_' . $k);
}

$back .= orgFilterQuery($filter);
$inScope = orgFilterApply($store, $scope->only(liveRows($store, 'employees'), $may('employee.list')), $filter);

$finalRatingOf = [];

foreach ($store->all(Performance::TABLE) as $r) {
    // THE RATING AS GIVEN (a label, a number, a point in a slab) when the
    // review was rated on the tenant's own scale; the integer otherwise.
    $given = trim((string) ($r['final_rating_value'] ?? '')) !== '' ? (string) $r['final_rating_value']
        : (trim((string) ($r['final_rating'] ?? '')) !== '' ? (string) (int) $r['final_rating'] : '');

    if ((string) ($r['cycle'] ?? '') === $period && (string) ($r['status'] ?? '') === 'finalised' && $given !== '') {
        $finalRatingOf[(string) ($r['employee_id'] ?? '')] = ['value' => $given, 'review' => $r];
    }
}

// IDEMPOTENCY: an employee already raised for this FY + period is found by the
// marker this same action stamps into the Increments register it writes to —
// the register that already exists to answer "was this employee raised",
// rather than a second copy of that fact kept in the customisation payload.
$marker = "[PA:{$fy}:{$period}]";
$paRange = PerformanceAppraisal::range($fy, $fyStartMonth);
$paEffective = SmartPRS\Surface\Dates::toIso(post('effective')) ?? ($paRange === null ? date('Y-m-d') : date('Y-m-d', (int) strtotime($paRange['to'] . ' +1 day')));
$paIncCols = !($store instanceof SmartPRS\Surface\SqlStore) || in_array('rating', $store->columnsOf('increments'), true);
$alreadyDone = [];

foreach ($store->all('increments') as $inc) {
    if (str_contains((string) ($inc['remarks'] ?? ''), $marker)) {
        $alreadyDone[(string) ($inc['employee_id'] ?? '')] = true;
    }
}

$applied = 0;
$already = 0;
$noRating = 0;
$noBox = 0;
$noSlab = 0;

foreach ($inScope as $e) {
    $id = (string) $e['id'];
    $given = $finalRatingOf[$id] ?? null;
    $rating = $given['value'] ?? null;

    if ($rating === null) {
        $noRating++;

        continue;
    }

    $orgValues = ['role' => orgValueOf($store, $e, 'role'), 'employee' => orgValueOf($store, $e, 'employee')] + orgEnrich($store, $e);
    // THE BOX THE REVIEW WAS RATED ON, when it was rated on one of this key's
    // boxes — an "S" means what that box said it means; otherwise the most
    // specific box covering the employee now.
    $pinnedBox = (string) ($given['review']['appraisal_key'] ?? '') === $fy
        ? $appraisal->box($fy, (string) ($given['review']['appraisal_box_id'] ?? '')) : null;
    $box = $pinnedBox ?? $appraisal->boxFor($fy, $orgValues);

    if ($box === null) {
        $noBox++;

        continue;
    }

    $slab = PerformanceAppraisal::slabForValue($box, $rating, preg_match('/^\d+$/', $rating) === 1 ? (string) ($scale[(int) $rating] ?? '') : '');
    $pct = $slab === null ? null : (string) $slab['increment_pct']; // label, number or slab range, per the box

    if ($pct === null) {
        $noSlab++;

        continue;
    }

    if (isset($alreadyDone[$id])) {
        $already++;

        continue;
    }

    $ctc = Amount::of(Employment::ctc($e));
    $factor = Rational::of(100)->plus(Rational::fromDecimalString($pct))->dividedBy(Rational::of(100));
    $newCtc = $ctc->multipliedBy($factor, RoundingMode::HalfUp);

    $store->put('employees', ['id' => $id, 'ctc' => $newCtc->toDecimalString()]);
    // THE FULL INCREMENT (8 Oct): old CTC, %, effective date and cycle too, so
    // payroll pays a month before the effective date on the old CTC
    // (Increments::ctcOn()) and the Increment / Appraisal export can show it.
    $store->put('increments', [
        'employee_id' => $id,
        'employer_company_id' => trim((string) ($e['employer_company_id'] ?? '')) === '' ? null : (string) $e['employer_company_id'],
        'cycle' => SmartPRS\Surface\Increments::CYCLE_OF[PerformanceAppraisal::parseKey($fy)['granularity']] ?? 'Annual',
        'old_ctc' => $ctc->toDecimalString(),
        'new_ctc' => $newCtc->toDecimalString(),
        'pct' => $pct,
        'effective' => $paEffective,
        'status' => 'Effected',
        'decided_by' => $byLabel,
        'decided_at' => date('Y-m-d H:i:s'),
        'remarks' => "{$marker} rating {$rating} +{$pct}% ({$ctc->format()} → {$newCtc->format()}) by {$byLabel}",
    ] + ($paIncCols ? ['rating' => $rating, 'appraisal_key' => $fy, 'appraisal_box_id' => (string) $box['id'], 'review_period' => $period] : []));
    $audit('performance.appraisal.applied', 'employees', $id,
        ['ctc' => $ctc->toDecimalString()], ['ctc' => $newCtc->toDecimalString()],
        "FY {$fy} / {$period}: rating {$rating} +{$pct}%");
    $applied++;
}

$_SESSION['flash_notice'] = PerformanceAppraisal::label($fy) . " / {$period}: {$applied} employee(s) raised, {$already} already done, "
    . "{$noRating} with no finalised rating for {$period}, {$noBox} no customisation covers, {$noSlab} rated but no slab for that rating.";
header('Location: ' . $back);
exit;
