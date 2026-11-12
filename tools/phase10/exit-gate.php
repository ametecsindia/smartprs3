<?php
/**
 * THE PHASE 10 EXIT GATE — Part XXXII, Migration, parallel run and cutover.
 *
 *   php tools/phase10/exit-gate.php
 *
 * Part XXXII states this phase's exit condition PER TENANT:
 *
 *   1. The reconciliation report passes its tolerance policy, with Class C
 *      corrections reported as successes.
 *   2. The customer signs off.
 *   3. The rollback plan is in place and TESTED.
 *
 * Criterion 2 is a fact about a person at a specific customer, and this
 * repository has no customer. It is reported NOT PROVEN — the same treatment
 * Phase 6's statutory content gets, and for the same reason: a sign-off this
 * build could produce for itself would not be a sign-off. The HARNESS that
 * refuses a cutover without one is what can be built, and it is proven here.
 *
 * Part XXXII's own honest note on this phase: configuration reconstruction —
 * discovering what each customer's policy ACTUALLY is, because it lives in PHP
 * and in people's heads rather than in their database — "is the largest and least
 * predictable item in the entire programme."
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Migration\ClassCRegister;
use SmartPRS\Platform\Migration\CutoverPlan;
use SmartPRS\Platform\Migration\ReconciliationReport;
use SmartPRS\Platform\Migration\Variance;
use SmartPRS\Platform\Support\Money;

$results = [];

echo "SmartPRS 3 — Phase 10 exit gate (Part XXXII, Migration, parallel run and cutover)\n";

/** A variance builder, so each case reads as what it is testing. */
$v = static fn (array $o): Variance => new Variance(
    checkCode: $o['check'] ?? 'RC-05',
    subject: $o['subject'] ?? 'E-1',
    period: $o['period'] ?? '2026-08',
    legacyValue: Money::of($o['legacy'] ?? '50000.00'),
    newValue: Money::of($o['new'] ?? '50000.00'),
    declaredClass: $o['class'] ?? Variance::EXACT,
    reference: $o['reference'] ?? null,
    acceptedBy: $o['accepted_by'] ?? null,
    classCCode: $o['c_code'] ?? null,
    disclosedBeforeGoLive: $o['disclosed'] ?? false,
    rulingResolved: $o['ruling'] ?? false,
);

// =============================================================================
// 1. THE RECONCILIATION REPORT PASSES, WITH CLASS C REPORTED AS SUCCESSES
// =============================================================================

$problems = [];
$report = new ReconciliationReport('PILOT-TENANT', 'FY 2025-26 historical payroll');

// Class E: copies that match, to the paisa.
$report->add($v(['check' => 'RC-04', 'legacy' => '4821340.00', 'new' => '4821340.00']));
$report->add($v(['check' => 'RC-11', 'legacy' => '112000.00', 'new' => '112000.00']));

// Class X: differs, with a reference and a named acceptor.
$report->add($v([
    'check' => 'RC-30', 'subject' => 'YTD', 'legacy' => '910000.00', 'new' => '884500.00',
    'class' => Variance::EXPLAINED,
    'reference' => 'A-9: SmartPRS 2 computes YTD unscoped and double-counts lots',
    'accepted_by' => 'u-migration-lead',
]));

// Class C: expected corrections, each in the direction its defect predicts.
$report->add($v([
    'check' => 'RC-05', 'subject' => 'E-104', 'legacy' => '48200.00', 'new' => '44300.00',
    'class' => Variance::CORRECTION, 'c_code' => 'C-LOP', 'disclosed' => true,
]));
$report->add($v([
    'check' => 'RC-05', 'subject' => 'E-221', 'legacy' => '39000.00', 'new' => '36500.00',
    'class' => Variance::CORRECTION, 'c_code' => 'C-LOAN', 'disclosed' => true,
]));
$report->add($v([
    'check' => 'RC-16', 'subject' => 'E-318', 'legacy' => '52000.00', 'new' => '58400.00',
    'class' => Variance::CORRECTION, 'c_code' => 'C-COMM', 'disclosed' => true,
]));
$report->add($v([
    'check' => 'RC-28', 'subject' => 'E-402', 'legacy' => '41000.00', 'new' => '39150.00',
    'class' => Variance::CORRECTION, 'c_code' => 'C-NIGHT', 'disclosed' => true,
]));

if (!$report->passes()) {
    $problems[] = 'a report whose only differences are explained or corrected did not pass: '
        . count($report->failures()) . ' failure(s)';
}

if (count($report->corrections()) !== 4) {
    $problems[] = 'the report counted ' . count($report->corrections()) . ' correction(s), expected 4';
}

// Class C corrections are SUCCESSES and are never in the failure total.
foreach ($report->corrections() as $correction) {
    if ($correction->isFailure()) {
        $problems[] = "{$correction->classCCode} was counted as a failure. Without this class the report "
            . "would record the rebuild's central purpose as a failure";
    }
}

// R-XXIX-107: the single number, in one place.
$total = $report->totalFinancialEffectOfCorrections();
$expected = (-390000) + (-250000) + 640000 + (-185000);

if ($total->minor !== $expected) {
    $problems[] = "the total financial effect of corrections is {$total->format()}, expected "
        . Money::ofMinor($expected)->format();
}

$rendered = $report->render();

if (substr_count($rendered, 'TOTAL FINANCIAL EFFECT OF CORRECTING KNOWN SmartPRS 2 DEFECTS') !== 1) {
    $problems[] = 'the total financial effect of the corrections is not stated exactly once. R-XXIX-107: '
        . 'this is the single number the Product Owner and the customer most need, and a figure printed '
        . 'twice is a figure that will one day disagree with itself';
}

// R-XXIX-105: the LANGUAGE of the corrections section.
$forbidden = $report->forbiddenLanguageInCorrections();

if ($forbidden !== []) {
    $problems[] = 'the corrections section calls a correction a ' . implode('/', $forbidden)
        . '. R-XXIX-105 forbids all four words: the customer reads this report, and the first SmartPRS 3 '
        . 'payroll will look different in exactly these ways';
}

if (!str_contains($rendered, 'DEFECTS CORRECTED')) {
    $problems[] = 'the corrections do not appear under a "Defects corrected" heading';
}

// ---- Now the demotions. Each of these must NOT pass. --------------------------

foreach ([
    ['a Class E figure out by one paisa', $v(['legacy' => '50000.00', 'new' => '50000.01']),
        'a migrated payslip is a COPY; there is no mechanism by which a faithful copy differs, and these '
        . 'figures are the customer\'s filed statutory record'],
    ['a Class X difference with no reference',
        $v(['legacy' => '100.00', 'new' => '90.00', 'class' => Variance::EXPLAINED,
            'accepted_by' => 'u-lead']),
        'R-XXIX-103: "We think it is because..." is not an explanation'],
    ['a Class X difference nobody accepted',
        $v(['legacy' => '100.00', 'new' => '90.00', 'class' => Variance::EXPLAINED,
            'reference' => 'D-XXIX-09']),
        'an explanation with no named acceptor has not been accepted'],
    ['a Class C correction citing no defect',
        $v(['legacy' => '100.00', 'new' => '90.00', 'class' => Variance::CORRECTION,
            'disclosed' => true]),
        'R-XXIX-106 clause 1'],
    ['a Class C LOP correction that INCREASED net pay',
        $v(['legacy' => '100.00', 'new' => '150.00', 'class' => Variance::CORRECTION,
            'c_code' => 'C-LOP', 'disclosed' => true]),
        'R-XXIX-106 clause 2: an LOP correction can only reduce net pay; when the direction is wrong the '
        . 'classification is wrong'],
    ['a Class C correction not disclosed before go-live',
        $v(['legacy' => '100.00', 'new' => '90.00', 'class' => Variance::CORRECTION,
            'c_code' => 'C-LOP']),
        'R-XXIX-106 clause 4: the customer meets it for the first time in the report'],
    ['a Class C correction awaiting a compliance ruling',
        $v(['legacy' => '100.00', 'new' => '90.00', 'class' => Variance::CORRECTION,
            'c_code' => 'C-ESI-OT', 'disclosed' => true]),
        'R-XXIX-108: it must not be applied until the ruling exists'],
] as [$what, $variance, $why]) {
    if (!$variance->isFailure()) {
        $problems[] = "{$what} was ACCEPTED (classified {$variance->effectiveClass}) — {$why}";
    }
}

// The demotion is one-way: a failed Class C becomes a FAILURE, never a Class X.
$badC = $v(['legacy' => '100.00', 'new' => '150.00', 'class' => Variance::CORRECTION,
    'c_code' => 'C-LOP', 'disclosed' => true]);

if ($badC->effectiveClass !== Variance::EXACT) {
    $problems[] = 'a Class C that failed its own requirements fell to ' . $badC->effectiveClass
        . ' rather than to a failure. A classification that degrades under missing evidence is a '
        . 'classification everything eventually qualifies for';
}

// And a blocked report does NOT pass.
$blocked = new ReconciliationReport('T', 'S');
$blocked->add($v(['legacy' => '50000.00', 'new' => '50000.01']));

if ($blocked->passes()) {
    $problems[] = 'a report containing a one-paisa Class E variance passed. Not "within Rs 1", not '
        . '"within 0.01%": a variance of one paisa halts sign-off until it is explained';
}

// Every register entry declares a direction the gate can check.
// PHPStan says this `in_array` is always true, and for TODAY'S register that is
// correct: ENTRIES is a const and every direction in it is one of the three.
// The check is not therefore pointless — it is what fires the day somebody adds
// an entry with a fourth direction, which is exactly when a variance would
// otherwise get filed in an unexaminable box. Left as it is, deliberately.
foreach (ClassCRegister::ENTRIES as $code => $entry) {
    if (!in_array($entry['direction'], [ClassCRegister::DECREASES, ClassCRegister::INCREASES,
        ClassCRegister::BOTH], true)) {
        $problems[] = "{$code} declares no checkable direction, so it is a box every unexplained number "
            . 'gets filed in';
    }

    if (trim($entry['citation']) === '') {
        $problems[] = "{$code} cites no defect register entry (R-XXIX-106 clause 1)";
    }
}

$results[] = [
    'name' => 'The reconciliation report passes its tolerance policy, with Class C corrections reported as '
        . 'successes',
    'ran' => true,
    'problems' => $problems,
    'detail' => count(ClassCRegister::ENTRIES) . ' Class C defects registered, each with a citation and a '
        . 'predicted direction; a report with 2 exact, 1 explained and 4 corrected differences PASSES, the '
        . 'corrections are reported as successes under "Defects corrected" using none of the four forbidden '
        . 'words, and their total is stated exactly once; seven demotion cases each fail, including an LOP '
        . 'correction that moved the wrong way and one awaiting a compliance ruling; and a one-paisa Class '
        . 'E variance blocks the report',
];

// =============================================================================
// 3 (BEFORE 2, BECAUSE THE PLAN IS WHAT SIGN-OFF IS GIVEN ON)
//    THE ROLLBACK PLAN IS IN PLACE AND TESTED
// =============================================================================

$problems = [];
$plan = new CutoverPlan('PILOT-TENANT', new DateTimeImmutable('2026-10-28 22:00'));

if ($plan->readiness(240)['ready']) {
    $problems[] = 'an empty plan reported itself ready';
}

$plan->step('freeze SmartPRS 2 writes', 'u-ops', 're-enable writes and discard the SmartPRS 3 delta');
$plan->step('final delta migration', 'u-migration-lead', 'restore the pre-cutover database snapshot');
$plan->step('repoint the DNS record', 'u-ops', 'repoint back; TTL is 60s and was lowered 48h earlier');
$plan->step('enable SmartPRS 3 logins', 'u-ops', 'disable logins and re-open SmartPRS 2');

try {
    $plan->step('a step with no way back', 'u-ops', '');
    $problems[] = 'a cutover step with no rollback action was accepted. A point of no return nobody '
        . 'labelled as one is found at 2am';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$unrehearsed = $plan->readiness(240);

if ($unrehearsed['ready']) {
    $problems[] = 'a plan with steps but no rehearsal reported itself ready. A rollback plan that has '
        . 'never been executed is a document';
}

$plan->rehearse(new DateTimeImmutable('2026-10-14 22:00'), 95, true,
    'full rollback from a restored snapshot on the staging copy of the tenant');

if (!$plan->readiness(240)['ready']) {
    $problems[] = 'a rehearsed plan within its window is not ready: '
        . implode('; ', $plan->readiness(240)['problems']);
}

// A rollback that does not fit the window is not a rollback for this cutover.
if ($plan->readiness(60)['ready']) {
    $problems[] = 'a 95-minute rehearsed rollback was accepted for a 60-minute cutover window';
}

$failedRehearsal = new CutoverPlan('T', new DateTimeImmutable('2026-10-28'));
$failedRehearsal->step('x', 'u', 'y');
$failedRehearsal->rehearse(new DateTimeImmutable('2026-10-14'), 30, false, 'the snapshot would not restore');

if ($failedRehearsal->readiness(240)['ready']) {
    $problems[] = 'a plan whose rehearsal FAILED reported itself ready';
}

$results[] = [
    'name' => 'The rollback plan is in place and tested',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($plan->steps()) . ' steps, each declaring how to back out of it; a step with no way '
        . 'back is refused; an unrehearsed plan and a failed rehearsal are both not ready; and a rehearsed '
        . 'rollback that does not fit the cutover window is rejected before the night rather than '
        . 'discovered during it',
];

// =============================================================================
// 2. THE CUSTOMER SIGNS OFF
// =============================================================================

// The harness is exercised in full; the SIGN-OFF ITSELF cannot be.
$cannotCutOver = $plan->canCutOver($report, 240);
$harnessProblems = [];

if ($cannotCutOver['ok']) {
    $harnessProblems[] = 'a tenant with no customer sign-off was cleared to cut over';
}

$signed = new CutoverPlan('PILOT-TENANT', new DateTimeImmutable('2026-10-28 22:00'));
$signed->step('freeze writes', 'u-ops', 'unfreeze');
$signed->rehearse(new DateTimeImmutable('2026-10-14'), 95, true, 'rehearsed');
$signed->signOff('<a named person at the customer>', '<their role>', new DateTimeImmutable('2026-10-20'),
    'FY 2025-26 historical payroll and the September parallel run');

if (!$signed->canCutOver($report, 240)['ok']) {
    $harnessProblems[] = 'a signed-off tenant with a passing report and a rehearsed rollback was still '
        . 'blocked: ' . implode('; ', $signed->canCutOver($report, 240)['problems']);
}

if ($signed->canCutOver($blocked, 240)['ok']) {
    $harnessProblems[] = 'a tenant was cleared to cut over against a FAILING reconciliation report. Each '
        . 'unexplained difference blocks sign-off and may not be waived, accepted, rounded away or netted '
        . 'against another';
}

try {
    $signed->signOff('', 'role', new DateTimeImmutable('2026-10-20'), 'scope');
    $harnessProblems[] = 'a sign-off with no named person was accepted';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'The customer signs off',
    'ran' => false,
    'problems' => array_merge([
        'This criterion is a fact about a named person at a specific customer, and this repository has no '
        . 'customer. A sign-off this build produced for itself would not be a sign-off — the same reason '
        . "Phase 6's statutory content is blocked on an appointment rather than filled in with plausible "
        . 'values.',
        'Part XXXII also requires, before this phase begins, that the six migration gates of Part XXIX pass '
        . 'FOR THE TENANT IN QUESTION and that the contaminated-data decisions are answered. Neither is a '
        . 'repository fact.',
        'WHAT IS PROVEN: the harness that refuses without it. A cutover is blocked when the report has an '
        . 'unexplained difference, when the rollback is unrehearsed, and when nobody has signed; a sign-off '
        . 'with no named person, role and scope is refused; and a signed tenant with a passing report and a '
        . 'rehearsed rollback is cleared.'
        . ($harnessProblems === [] ? ' All of that passes.' : ''),
    ], $harnessProblems),
    'detail' => '',
];

// =============================================================================

// THE ANALYSER CANNOT SEE THESE MOVE, and it is wrong about that.
// PHPStan reports `$failed === 0` below as always false and `$notRun === 0` as
// decidable, having narrowed both to literals it can prove from the array
// contents at the top of this file. At runtime the loop moves them: this gate
// currently prints "2 of 3 proven, 1 NOT PROVEN", so `$failed` is 0 and
// `$notRun` is 1 — neither of which the analyser believes. The `@var` is here
// to stop those two findings hiding a REAL always-false comparison the day one
// appears in this file, which is the only reason any of this is annotated.
/** @var int $failed */
$failed = 0;
/** @var int $notRun */
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);
    } elseif ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);
    } else {
        printf("\n  %d. \033[32mPASS      \033[0m  %s\n        %s\n", $index + 1, $result['name'],
            $result['detail']);

        continue;
    }

    foreach ($result['problems'] as $problem) {
        echo "        └─ {$problem}\n";
    }
}

echo "\n" . $report->render() . "\n";

printf("\n%s  %d of %d Phase 10 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun, count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN (a fact about a customer, not about this repository)");

exit($failed === 0 && $notRun === 0 ? 0 : 1);
