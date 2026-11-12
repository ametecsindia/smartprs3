<?php
/**
 * IMPORT APPRAISAL RATINGS (owner, 8 Oct) — Increment / Appraisal tab. For one
 * Performance Appraisal criteria box and review period, each filled-in row:
 *
 *   1. the rating is checked against that box's own scale (label / number / slab);
 *   2. the employee's Performance review for the period is FINALISED with it
 *      (so the Performance screen, the recommended increment and Apply all see it);
 *   3. CTC is raised by the slab's % (exact money, HalfUp) on the employee record —
 *      payroll, payslips, CTC/LPA, F&F, reports all read that record — and
 *   4. an `Effected` increment is logged with old CTC, new CTC, %, rating and the
 *      effective date; a payroll month ending before that date is still paid on
 *      the old CTC (Increments::ctcOn()).
 *
 * Re-importing the same criteria + period CORRECTS a rating: the earlier
 * increment is withheld (superseded) and a new one is recomputed from the CTC
 * the appraisal started from. Rows are independent: good rows are
 * kept, refused rows are listed with their line and reason.
 */
declare(strict_types=1);

use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Increments;
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;
use SmartPRS\Surface\Sheet;
use SmartPRS\Surface\SqlStore;

require_once __DIR__ . '/../partials/appraisal-common.php';

$aiBack = '?r=' . moduleRoute('increments') . '&pa_cycle=' . rawurlencode(post('cycle')) . '&pa_period=' . rawurlencode(post('period'));
$aiFail = static function (string $why) use ($aiBack): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $aiBack);
    exit;
};

foreach (['employee.update', 'employee.salary.update'] as $aiPerm) {
    $may($aiPerm) || $aiFail('Denied: ' . $access->decide((string) $session->user()['role'], $aiPerm)->summary);
}

$aiIncCols = $store instanceof SqlStore ? $store->columnsOf(Increments::TABLE) : null;
$aiIncCols === null || in_array('rating', $aiIncCols, true)
    || $aiFail('The database needs one update first: run laragon-setup.bat from the Laragon terminal (migration 2026_10_08_000100_increment_appraisal_columns). Nothing was imported.');
$aiHasValueCols = !($store instanceof SqlStore) || in_array('final_rating_value', $store->columnsOf(Performance::TABLE), true);

$aiAppraisal = new PerformanceAppraisal($customisation);
$aiFyStart = financialYearStart($customisation);
[$aiFy, $aiBoxId] = array_pad(explode('|', post('cycle'), 2), 2, '');
$aiBox = $aiAppraisal->box($aiFy, $aiBoxId);
$aiPeriod = post('period');
$aiBox === null && $aiFail('Pick the appraisal criteria the sheet was downloaded for.');
PerformanceAppraisal::keyCoversPeriod($aiFy, $aiPeriod, $aiFyStart)
    || $aiFail("Review period '{$aiPeriod}' does not end inside " . PerformanceAppraisal::label($aiFy) . '.');
$aiDefaultEffective = Dates::toIso(post('effective')) ?? appraisalDefaultEffective($aiFy, $aiFyStart);

$aiUpload = (string) ($_FILES['sheet']['tmp_name'] ?? '');
is_uploaded_file($aiUpload) || $aiFail('No file was uploaded. Nothing was read.');

try {
    $aiCsv = Sheet::toCsv((string) file_get_contents($aiUpload), (string) ($_FILES['sheet']['name'] ?? 'upload.csv'));
} catch (\Throwable $e) {
    $aiFail('The file could not be read: ' . $e->getMessage());
}

$aiLines = [];
$h = fopen('php://memory', 'w+');
fwrite($h, $aiCsv);
rewind($h);

while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
    $aiLines[] = array_map(static fn ($v): string => trim((string) $v), $cells);
}

fclose($h);

// THE HEADER: the codes row of the sample, else the labels row.
$aiCol = null;
$aiStart = 0;
$aiLabels = ['employee id' => 'emp_code', 'rating' => 'rating', 'effective date' => 'effective', 'remarks' => 'remarks', 'appraisal cycle (do not change)' => 'cycle'];

foreach (array_slice($aiLines, 0, 5, true) as $i => $cells) {
    $map = [];

    foreach ($cells as $c => $v) {
        $k = strtolower(rtrim($v, ' *'));
        $code = in_array($k, ['emp_code', 'rating', 'effective', 'remarks', 'cycle'], true) ? $k : ($aiLabels[$k] ?? null);
        $code === null || isset($map[$code]) || $map[$code] = $c;
    }

    if (isset($map['emp_code'], $map['rating'])) {
        $aiCol = $map;
        $aiStart = $i + 1;

        if (in_array('emp_code', $cells, true)) {
            break; // the codes row; the hints row below it is skipped as a non-employee line
        }
    }
}

$aiCol === null && $aiFail('This is not the appraisal rating sample: no "Employee ID" and "Rating" columns. Download the sample for this criteria and fill it in.');

$aiPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$aiByCode = [];

foreach ($aiPeople as $e) {
    $aiByCode[strtolower(Employment::code($e))] = $e;
}

$aiEmployeesById = [];

foreach ($store->all('employees') as $e) {
    $aiEmployeesById[(string) $e['id']] = $e;
}

$aiReviews = [];

foreach ($store->all(Performance::TABLE) as $r) {
    if ((string) ($r['cycle'] ?? '') === $aiPeriod) {
        $aiReviews[(string) ($r['employee_id'] ?? '')] = $r;
    }
}

$aiMarker = appraisalMarker($aiFy, $aiPeriod);
$aiDone = [];

foreach ($store->all(Increments::TABLE) as $inc) {
    if (str_contains((string) ($inc['remarks'] ?? ''), $aiMarker) && (string) ($inc['status'] ?? '') === 'Effected') {
        $aiDone[(string) ($inc['employee_id'] ?? '')] = $inc;
    }
}

$aiPerf = new Performance(__DIR__ . '/../../config/performance.json');
$aiBy = (string) ($session->user()['email'] ?? '');
$aiNow = date('Y-m-d H:i:s');
$aiCycleName = Increments::CYCLE_OF[PerformanceAppraisal::parseKey($aiFy)['granularity']] ?? 'Annual';
$aiTag = 'PA|' . $aiFy . '|' . $aiBoxId . '|' . $aiPeriod;
$aiRaised = 0;
$aiCorrected = 0;
$aiSame = 0;
$aiBlank = 0;
$aiRefused = [];

foreach (array_slice($aiLines, $aiStart, null, true) as $i => $cells) {
    $line = $i + 1;
    $code = (string) ($cells[$aiCol['emp_code']] ?? '');
    $typed = (string) ($cells[$aiCol['rating']] ?? '');

    if ($code === '' || str_starts_with(strtolower($code), 'required')) {
        continue; // blank line, or the sample's hints row
    }

    if (isset($aiCol['cycle']) && ($tag = (string) ($cells[$aiCol['cycle']] ?? '')) !== '' && $tag !== $aiTag) {
        $aiRefused[] = "Line {$line}: this row is from the sample of another criteria or period ({$tag}).";
        continue;
    }

    $e = $aiByCode[strtolower($code)] ?? null;

    if ($e === null) {
        $aiRefused[] = "Line {$line}: no employee {$code} within your reach.";
        continue;
    }

    if ($typed === '') {
        $aiBlank++;
        continue;
    }

    $own = $aiAppraisal->boxFor($aiFy, appraisalOrgValues($store, $e));

    if ($own === null || (string) $own['id'] !== $aiBoxId) {
        $aiRefused[] = "Line {$line}: {$code} is not rated by this criteria" . ($own === null ? ' (no criteria covers them).' : ' — a more specific one covers them; use its sample.');
        continue;
    }

    $value = PerformanceAppraisal::acceptValue($aiBox, $typed);
    $slab = $value === null ? null : PerformanceAppraisal::slabForValue($aiBox, $value);

    if ($slab === null) {
        $aiRefused[] = "Line {$line}: rating '{$typed}' is not on the scale (" . PerformanceAppraisal::describeScale($aiBox) . ').';
        continue;
    }

    $effTyped = (string) ($cells[$aiCol['effective'] ?? -1] ?? '');
    $effective = $effTyped === '' ? $aiDefaultEffective : Dates::toIso($effTyped);

    if ($effective === null) {
        $aiRefused[] = "Line {$line}: effective date '{$effTyped}' is not a date (dd-mm-yyyy).";
        continue;
    }

    $id = (string) $e['id'];
    $review = $aiReviews[$id] ?? null;
    $given = $review === null ? '' : (trim((string) ($review['final_rating_value'] ?? '')) !== '' ? (string) $review['final_rating_value'] : (string) ($review['final_rating'] ?? ''));
    $prior = $aiDone[$id] ?? null;

    // A REVIEW FINALISED ON THE PERFORMANCE SCREEN is not overwritten by a sheet;
    // one this import (or Apply) finalised can be corrected by re-importing.
    if ($review !== null && (string) ($review['status'] ?? '') === 'finalised' && $prior === null && $given !== '' && strcasecmp($given, $value) !== 0) {
        $aiRefused[] = "Line {$line}: {$code}'s review for {$aiPeriod} was finalised on the Performance screen as {$given}. Apply it there, or correct it there first.";
        continue;
    }

    $remarksTyped = (string) ($cells[$aiCol['remarks'] ?? -1] ?? '');
    $pct = (string) $slab['increment_pct'];
    $priorFacts = $prior === null ? null : appraisalIncrementFacts($prior);

    if ($priorFacts !== null && strcasecmp($priorFacts['rating'], $value) === 0 && substr((string) ($prior['effective'] ?? ''), 0, 10) === $effective) {
        $aiSame++;
        continue;
    }

    // THE CTC THE APPRAISAL STARTS FROM: today's, or for a correction the one the first import started from.
    $old = Amount::of($priorFacts !== null && $priorFacts['old'] !== '' ? $priorFacts['old'] : Employment::ctc($e));
    $new = $old->multipliedBy(Rational::of(100)->plus(Rational::fromDecimalString($pct))->dividedBy(Rational::of(100)), RoundingMode::HalfUp);

    if ($priorFacts !== null && $priorFacts['new'] !== '' && Amount::of(Employment::ctc($e))->toDecimalString() !== $priorFacts['new']) {
        $aiRefused[] = "Line {$line}: {$code}'s CTC has changed since this appraisal was applied (now " . Amount::of(Employment::ctc($e))->format()
            . '). Correct it on the Increment / Appraisal register instead.';
        continue;
    }

    // 1. THE REVIEW, FINALISED WITH THIS RATING.
    $reviewer = Performance::reviewerOf($e, $aiEmployeesById);
    $r = $review ?? ['employee_id' => $id, 'employer_company_id' => trim((string) ($e['employer_company_id'] ?? '')) === '' ? null : (string) $e['employer_company_id'],
        'employee' => Employment::label($e), 'cycle' => $aiPeriod, 'created_at' => $aiNow];
    $r['reviewer_id'] = $reviewer['id'] === '' ? null : $reviewer['id'];
    $r['reviewer'] = $reviewer['label'];
    $r['final_rating'] = preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    $r['rating'] = preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) === 1 ? $value : null;
    $r['status'] = 'finalised';
    $r['finalised_at'] = $aiNow;
    $r['finalised_by'] = $aiBy;
    $r['updated_at'] = $aiNow;

    if ($aiHasValueCols) {
        $r['final_rating_value'] = $value;
        $r['rating_format'] = PerformanceAppraisal::format($aiBox);
        $r['appraisal_key'] = $aiFy;
        $r['appraisal_box_id'] = $aiBoxId;
    } elseif ($r['final_rating'] === null) {
        $aiRefused[] = "Line {$line}: label / decimal ratings need the database updated first (run laragon-setup.bat — migration 2026_09_21_000200).";
        continue;
    }

    $storedReview = $store->put(Performance::TABLE, $r);
    $audit('performance.review.imported', Performance::TABLE, (string) $storedReview['id'], $review, $storedReview, "Rating {$value} imported for {$aiPeriod}");

    // 2. THE SALARY: the employee record is what payroll, payslips and F&F read.
    $store->put('employees', ['id' => $id, 'ctc' => $new->toDecimalString()]);
    $audit('performance.appraisal.applied', 'employees', $id, ['ctc' => Employment::ctc($e)], ['ctc' => $new->toDecimalString()],
        "{$aiFy} / {$aiPeriod}: rating {$value} +{$pct}% (import)");

    // 3. THE INCREMENT REGISTER — the same marker Apply writes, so neither raises twice.
    $inc = ['employee_id' => $id, 'employer_company_id' => $r['employer_company_id'] ?? null, 'cycle' => $aiCycleName,
        'old_ctc' => $old->toDecimalString(), 'new_ctc' => $new->toDecimalString(), 'pct' => $pct, 'effective' => $effective,
        'status' => 'Effected', 'decided_by' => $aiBy, 'decided_at' => $aiNow,
        'remarks' => mb_substr("{$aiMarker} rating {$value} +{$pct}% ({$old->format()} → {$new->format()})" . ($remarksTyped === '' ? '' : ' · ' . $remarksTyped), 0, 250),
        'rating' => $value, 'appraisal_key' => $aiFy, 'appraisal_box_id' => $aiBoxId, 'review_period' => $aiPeriod];
    // A CORRECTION SUPERSEDES, NEVER OVERWRITES (Law 3): the earlier row is
    // withheld with a note and a new Effected row carries the corrected rating.
    if ($prior !== null) {
        $store->put(Increments::TABLE, ['id' => $prior['id'], 'status' => 'Withheld',
            'remarks' => mb_substr('Superseded by re-import ' . $aiNow . ' · ' . (string) ($prior['remarks'] ?? ''), 0, 250)]);
    }

    $store->put(Increments::TABLE, $inc);
    $prior === null ? $aiRaised++ : $aiCorrected++;
}

$_SESSION['flash_notice'] = PerformanceAppraisal::label($aiFy) . " / {$aiPeriod}: {$aiRaised} employee(s) rated and raised"
    . ($aiCorrected > 0 ? ", {$aiCorrected} corrected" : '') . ($aiSame > 0 ? ", {$aiSame} unchanged" : '') . ($aiBlank > 0 ? ", {$aiBlank} left blank" : '') . '.';
$aiRefused === [] || $_SESSION['flash_problem'] = count($aiRefused) . ' row(s) refused — fix and upload just those: ' . implode(' ', array_slice($aiRefused, 0, 25)) . (count($aiRefused) > 25 ? ' …' : '');
header('Location: ' . $aiBack);
exit;
