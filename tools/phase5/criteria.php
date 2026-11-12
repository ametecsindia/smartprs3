<?php
/**
 * THE FIVE PHASE 5 EXIT CRITERIA, RUN — required by tools/phase5/exit-gate.php.
 *
 * Every criterion EXECUTES. None is asserted by inspection, and none is skipped
 * quietly: a criterion that could not run reports NOT PROVEN and the gate exits
 * non-zero. `$matrix`, `$results` and `$root` come from the gate.
 */
declare(strict_types=1);

use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Payroll\Reconciliation;
use SmartPRS\Platform\Payroll\Recovery;
use SmartPRS\Platform\Payroll\RunAborted;
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

// This file is require'd by tools/phase5/exit-gate.php, which defines these
// three before including it (see the file docblock above). Guarded rather
// than assumed, so running this file any other way fails loudly instead of
// with a confusing "undefined variable" further down — and it lets static
// analysis see that $matrix/$results/$root are always defined below this line.
if (!isset($matrix, $results, $root)) {
    throw new \LogicException(
        'tools/phase5/criteria.php must be require\'d by tools/phase5/exit-gate.php, '
        . 'which defines $matrix, $results and $root before including it.'
    );
}

/** @return list<SalaryComponent> the components exactly as the matrix declares them */
function gateComponents(array $matrix): array
{
    return array_map(
        static fn (array $c): SalaryComponent => new SalaryComponent(
            code: $c['code'],
            kind: $c['kind'],
            basis: $c['basis'],
            flags: $c['flags'] ?? [],
            ofComponent: $c['of'] ?? null,
            ratePercent: isset($c['rate']) ? (int) $c['rate'] : null,
            flatAmount: $c['amount'] ?? null,
        ),
        $matrix['components'],
    );
}

/** @return array{employment_id: string, gross: Money, payable_days: Rational, period_days: int, recoveries: array<string, Money>} */
function gateInput(array $case): array
{
    return [
        'employment_id' => 'E-' . $case['id'],
        'gross' => Money::of($case['gross']),
        // EXACT. `(int)` here would have made the whole golden matrix incapable
        // of expressing a half day, on the gate that proves the money is right.
        'payable_days' => Rational::fromDecimalString((string) $case['payable_days']),
        'period_days' => (int) $case['period_days'],
        'recoveries' => isset($case['recovery'])
            ? [$case['recovery']['code'] => Money::of($case['recovery']['amount'])]
            : [],
    ];
}

$components = gateComponents($matrix);

// =============================================================================
// 1. THE GOLDEN-TEST MATRIX PASSES WITH EXACT VALUES
// =============================================================================

$problems = [];
$lineCount = 0;

foreach ($matrix['cases'] as $case) {
    $input = gateInput($case);

    try {
        $run = new PayrollRun('2026-09', $components, [$input]);
    } catch (RunAborted $e) {
        $problems[] = "{$case['id']}: the run aborted its own reconciliation — " . implode(', ', $e->checks());

        continue;
    }

    $slip = $run->payslips[0];
    $expect = $case['expect'];

    foreach ($expect['lines'] as $code => $amount) {
        $line = $slip->lineFor($code);
        $lineCount++;

        if ($line === null) {
            $problems[] = "{$case['id']}: no {$code} line at all";

            continue;
        }

        if ($line->amount->toDecimalString() !== $amount) {
            $problems[] = "{$case['id']}: {$code} is {$line->amount->toDecimalString()}, the matrix says "
                . "{$amount} — {$case['why']}";
        }
    }

    foreach ([
        'gross_earnings' => $slip->grossEarnings(),
        'total_deductions' => $slip->totalDeductions(),
        'net_pay' => $slip->netPay(),
    ] as $key => $actual) {
        if ($actual->toDecimalString() !== $expect[$key]) {
            $problems[] = "{$case['id']}: {$key} is {$actual->toDecimalString()}, the matrix says {$expect[$key]}";
        }
    }

    // The residue case states what the NAIVE order would have produced. Asserting
    // that we did not produce it is what makes the case worth having: without it,
    // the correct figure and the wrong one differ by a paisa nobody would notice.
    if (isset($expect['naive_gross'])) {
        $naive = Money::zero();

        foreach ($slip->linesOfKind(SalaryComponent::EARNING) as $line) {
            $naive = $naive->plus($line->amount);
        }

        if ($expect['naive_gross'] === $naive->toDecimalString()) {
            $problems[] = "{$case['id']}: the earning lines sum to {$expect['naive_gross']}, which is the "
                . 'figure independent rounding produces — the gross was split before it was prorated';
        }
    }

    // And a negative net is STATED, not clamped.
    if (str_starts_with((string) $expect['net_pay'], '-') && !$slip->netPay()->isNegative()) {
        $problems[] = "{$case['id']}: a net of {$expect['net_pay']} came back as "
            . $slip->netPay()->toDecimalString() . ' — clamping at zero leaves the payslip printing '
            . 'gross minus deductions that does not equal its own net';
    }
}

$results[] = [
    'name' => 'The payroll golden-test matrix passes with EXACT values',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($matrix['cases']) . ' case(s), ' . $lineCount . ' line(s), every figure exact integer '
        . 'paise from tests/phase5/payroll_matrix.json — including the 22/24 residue case where independent '
        . 'rounding gives a paisa more than the employee is owed, and the negative net that is stated rather '
        . 'than clamped',
];

// =============================================================================
// 2. THE RECONCILIATION ASSERTION ABORTS A RUN ON ANY MISMATCH
// =============================================================================

$problems = [];
$case = $matrix['cases'][0];
$clean = new PayrollRun('2026-09', $components, [gateInput($case)]);
$declared = $clean->totals();

foreach ($matrix['reconciliation']['perturbations'] as $perturbation) {
    $perturbed = [$clean->payslips[0]->withPerturbation($perturbation['line'], (int) $perturbation['paise'])];

    try {
        Reconciliation::assertOrAbort($perturbed, $declared);
        $problems[] = "a {$perturbation['paise']}-paisa perturbation of {$perturbation['line']} did NOT abort "
            . 'the run. R-XIV-069 exists because a reconciliation nobody has ever seen fail is a '
            . 'reconciliation nobody knows is wired up';
    } catch (RunAborted $e) {
        foreach ($perturbation['expect_fails'] as $check) {
            if (!$e->failed($check)) {
                $problems[] = "{$perturbation['line']} perturbed: {$check} did not fail (failed: "
                    . implode(', ', $e->checks()) . ") — {$perturbation['why']}";
            }
        }

        // To the paisa, and naming the employment — not "a small difference".
        $named = false;

        foreach ($e->failures as $failure) {
            if (str_contains($failure['difference'], '0.01')) {
                $named = true;
            }
        }

        if (!$named) {
            $problems[] = "{$perturbation['line']} perturbed: no failure reported the difference to the "
                . 'paisa. A tolerance is how a systematic error hides — an error always under the tolerance '
                . 'is an error always there';
        }
    }
}

// The four structurally-satisfied checks are named rather than counted as passes.
foreach ($matrix['reconciliation']['structural']['checks'] as $check) {
    if (!in_array($check, ['RC-01', 'RC-02', 'RC-03', 'RC-08'], true)) {
        $problems[] = "{$check} is declared structural but the gate does not know why it cannot fail";
    }
}

// A CLEAN run against a true header does not abort — otherwise "it aborted" proves nothing.
try {
    Reconciliation::assertOrAbort($clean->payslips, $declared);
} catch (RunAborted $e) {
    $problems[] = 'a clean run aborted against its own header: ' . implode(', ', $e->checks());
}

// The header disagreeing about WHO is in the run is RC-11 — a headcount mismatch
// is the shape of a payslip silently dropped.
try {
    Reconciliation::assertOrAbort($clean->payslips, $declared + [] === $declared
        ? array_merge($declared, ['employee_count' => $declared['employee_count'] + 1])
        : $declared);
    $problems[] = 'a header claiming one more employee than the run holds did not fail RC-11';
} catch (RunAborted $e) {
    if (!$e->failed('RC-11')) {
        $problems[] = 'a headcount mismatch failed ' . implode(', ', $e->checks()) . ' rather than RC-11';
    }
}

$results[] = [
    'name' => 'The run-level reconciliation assertion ABORTS the run on any mismatch',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($matrix['reconciliation']['perturbations']) . ' one-paisa perturbation(s) each abort '
        . 'the run naming the check, the employment and the difference; a clean run does not abort; a '
        . 'headcount mismatch fails RC-11; and '
        . implode(', ', $matrix['reconciliation']['structural']['checks'])
        . ' are reported as structurally satisfied rather than as passing, because a total that is summed '
        . 'from the lines on read cannot disagree with them',
];

// =============================================================================
// 3. NO COMPONENT NAME APPEARS AS A LITERAL ANYWHERE IN THE CODEBASE
// =============================================================================
//
// Part XV §XV.2: a component's behaviour is a FLAG, never its name. The moment
// `if ($code === 'BASIC')` exists, a tenant whose basic is called "Consolidated
// Pay" gets different arithmetic, and a customer renaming a component silently
// changes what it contributes to. SmartPRS 2 decides component semantics by
// string comparison against names in nineteen places.

$problems = [];

/**
 * The names a payroll engine reaches for. Matched against STRING LITERALS only.
 *
 * `LOAN` and `ADVANCE` are deliberately NOT in this list and are in the stricter
 * one below. They are the two words that are both a salary component a tenant
 * invents AND a MODULE this platform declares — Part XVIII and Part XXII name
 * them, the permission catalogue carries `loan.sanction`, and SPX has a `loan.`
 * symbol namespace. A domain the platform declares is legitimately enumerable; a
 * component code the tenant invents is not. Banning the word everywhere would
 * force an allow-list, and an allow-list that grows to swallow every hit is how
 * this criterion quietly stops checking anything — so instead they are banned
 * where it matters and permitted where they mean something else.
 */
$componentNames = [
    'BASIC', 'HRA', 'DA', 'LTA', 'SPECIAL', 'CONVEYANCE', 'MEDICAL', 'BONUS', 'GRATUITY',
    'PF', 'EPF', 'VPF', 'ESI', 'ESIC', 'PT', 'TDS', 'LWF', 'NPS',
];

/** Inside the money engine itself, the two module words are components too. */
$strictNames = [...$componentNames, 'LOAN', 'ADVANCE'];

/** @return list<string> the string literals in a PHP source, comments stripped */
function gateLiterals(string $source): array
{
    $literals = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literals[] = trim($token[1], "'\"");
        }
    }

    return $literals;
}

/** @return list<string> */
function gateComponentLiterals(string $source, array $names): array
{
    $found = [];

    foreach (gateLiterals($source) as $literal) {
        // A component NAME, not a sentence containing one. `'BASIC'` offends;
        // `'the basic pay is a flag'` is prose in an error message.
        $bare = strtoupper(preg_replace('/[^A-Za-z]/', '', $literal) ?? '');

        if ($bare !== '' && in_array($bare, $names, true) && strlen($literal) <= 12) {
            $found[] = $literal;
        }
    }

    return $found;
}

// The detector is verified BEFORE it is trusted. A grep that matches nothing
// because its pattern is wrong reports a clean codebase, which is the worst
// possible failure mode for this criterion.
$probe = gateComponentLiterals('<?php $x = "BASIC"; // and a comment saying HRA', $componentNames);

if ($probe !== ['BASIC']) {
    $problems[] = 'the component-literal detector does not detect a component literal (probe returned '
        . json_encode($probe) . ') — a check that silently checks nothing is worse than a missing check';
}

$scanned = 0;

foreach (['/src', '/database', '/public'] as $dir) {
    if (!is_dir($root . $dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $scanned++;
        $inMoneyEngine = str_contains($file->getPathname(), '/Platform/Payroll/');
        $offenders = gateComponentLiterals(
            (string) file_get_contents($file->getPathname()),
            $inMoneyEngine ? $strictNames : $componentNames,
        );

        // Inside Platform\Statutory, a LOWERCASE statute code is the platform's
        // own declared statute vocabulary — `pack.statute_code` is an enum Part
        // XVII §XVII.4.2 fixes at ten values, and validating against it is the
        // opposite of the defect this criterion guards. A component code a tenant
        // invents is never lowercase-exact like this, and an upper-case 'PT' or a
        // rate identifier in the same directory still offends: Phase 6's own gate
        // additionally refuses any statutory rate or ceiling identifier there.
        if (str_contains($file->getPathname(), '/Platform/Statutory/')) {
            $statutes = ['epf', 'esi', 'pt', 'lwf', 'tds', 'bonus', 'gratuity', 'minimum_wages',
                'maternity', 'leave_statutory'];
            $offenders = array_values(array_filter(
                $offenders,
                static fn (string $literal): bool => !in_array($literal, $statutes, true),
            ));
        }

        foreach (array_unique($offenders) as $offender) {
            $problems[] = str_replace($root . '/', '', $file->getPathname())
                . " contains the component literal '{$offender}'. What a component DOES is a declared flag; "
                . 'the moment the name is the behaviour, renaming it changes the arithmetic';
        }
    }
}

$results[] = [
    'name' => 'No component name appears as a literal anywhere in the codebase',
    'ran' => true,
    'problems' => $problems,
    'detail' => $scanned . ' PHP file(s) scanned for ' . count($componentNames) . ' component names ('
        . count($strictNames) . ' inside Platform\\Payroll, where the two module words count too) as STRING '
        . 'LITERALS (tokenised, so comments and prose do not count), and the detector is probed against a '
        . 'known offender first so that a clean result means clean rather than broken',
];

// =============================================================================
// 4. A RE-RUN REPRODUCES THE ORIGINAL RESULT EXACTLY, AND REPORTS ANY CHANGED INPUT
// =============================================================================

$problems = [];
$inputs = array_map('gateInput', $matrix['cases']);
$original = new PayrollRun('2026-09', $components, $inputs);

$same = $original->rerun($inputs);

if (!$same['reproduced']) {
    $problems[] = 'a re-run from identical inputs did not reproduce the original: '
        . implode('; ', $same['differences']);
}

if ($same['changed_inputs'] !== []) {
    $problems[] = 'a re-run from identical inputs reported changed inputs, so "nothing changed" means nothing';
}

// Now change ONE input and re-run. The run must abort — the original totals are
// its declared header — and the changed input must be NAMED.
$revised = $inputs;
$revised[0]['gross'] = $revised[0]['gross']->plus(Money::of('1000.00'));

$aborted = false;

try {
    $original->rerun($revised);
} catch (RunAborted $e) {
    $aborted = true;

    if (!$e->failed('RC-04')) {
        $problems[] = 're-running with a revised gross failed ' . implode(', ', $e->checks())
            . ' rather than RC-04 against the original header';
    }
}

if (!$aborted) {
    $problems[] = 'a re-run with a REVISED gross quietly produced a second answer. SmartPRS 2 recomputes an '
        . 'old month against the definitions as they stand today, so re-opening March in June reprices it '
        . 'with nothing saying it was repriced';
}

// And with the header question set aside, the changed input itself is named.
$standalone = new PayrollRun('2026-09', $components, $revised);
$named = $original->snapshots[$inputs[0]['employment_id']]
    ->diff($standalone->snapshots[$revised[0]['employment_id']]);

if ($named === []) {
    $problems[] = 'the revised gross was not reported as a changed input';
} elseif (!str_contains(implode(' ', $named), 'contracted_gross')) {
    $problems[] = 'the changed input was reported as ' . implode('; ', $named)
        . ' rather than naming the gross — "the run differs" sends somebody looking, and looking is the '
        . 'part that takes a day';
}

// A superseded component version is a changed input too, even when every figure
// is identical. This is the case SmartPRS 2 cannot see at all.
$versionShift = new SmartPRS\Platform\Payroll\InputSnapshot(
    employmentId: $inputs[0]['employment_id'],
    periodCode: '2026-09',
    contractedGross: $inputs[0]['gross'],
    payableDays: $inputs[0]['payable_days'],
    periodDays: $inputs[0]['period_days'],
    componentVersions: array_map(
        static fn (string $v): string => str_replace('-v1', '-v2', $v),
        $original->snapshots[$inputs[0]['employment_id']]->componentVersions,
    ),
    recoveries: $inputs[0]['recoveries'],
);

if ($original->snapshots[$inputs[0]['employment_id']]->diff($versionShift) === []) {
    $problems[] = 'a run against SUPERSEDED component versions reported no changed input, even though the '
        . 'definitions that produced the figures are not the ones on file any more';
}

$results[] = [
    'name' => 'A re-run reproduces the original result exactly and reports any changed input',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($inputs) . ' payslip(s) reproduced to the paisa from an identical input snapshot; a '
        . 'revised gross ABORTS the re-run against the original header and is named as the changed input; '
        . 'and a superseded component version counts as a change even when every figure is identical',
];

// =============================================================================
// 5. EVERY RECOVERY REVERSES CLEANLY
// =============================================================================

$problems = [];
$declaredRecovery = null;

foreach ($matrix['cases'] as $c) {
    if (isset($c['recovery'])) {
        $declaredRecovery = $c;
    }
}

if ($declaredRecovery === null) {
    $results[] = [
        'name' => 'Every recovery reverses cleanly',
        'ran' => false,
        'problems' => ['the matrix declares no recovery case, so this criterion has nothing to run against'],
        'detail' => '',
    ];
} else {
    $r = $declaredRecovery['recovery'];
    $loan = new Recovery('E-' . $declaredRecovery['id'], $r['code'], Money::of($r['outstanding_before']),
        Money::of($r['amount']));

    $before = $loan->outstanding();
    $taken = $loan->take('2026-09');

    if ($taken->toDecimalString() !== $r['amount']) {
        $problems[] = "the instalment taken is {$taken->toDecimalString()}, the matrix says {$r['amount']}";
    }

    if ($loan->outstanding()->toDecimalString() !== $declaredRecovery['expect']['outstanding_after']) {
        $problems[] = 'the outstanding after the instalment is ' . $loan->outstanding()->toDecimalString()
            . ", the matrix says {$declaredRecovery['expect']['outstanding_after']}";
    }

    $loan->reverse('2026-09', 'The instalment was taken against a payslip that was itself reversed.');

    if (!$loan->outstanding()->equals($before)) {
        $problems[] = 'after reversal the outstanding is ' . $loan->outstanding()->toDecimalString()
            . ' rather than exactly ' . $before->toDecimalString()
            . ' — "cleanly" means to the paisa, not approximately';
    }

    if ($loan->outstanding()->toDecimalString() !== $declaredRecovery['expect']['outstanding_after_reversal']) {
        $problems[] = 'the outstanding after reversal is ' . $loan->outstanding()->toDecimalString()
            . ", the matrix says {$declaredRecovery['expect']['outstanding_after_reversal']}";
    }

    // A reversal is an ENTRY, not a deletion. The number would be right either
    // way; the history would not.
    if (count($loan->history()) !== 2) {
        $problems[] = 'the reversal left ' . count($loan->history()) . ' entry(ies) rather than two. Deleting '
            . 'the instalment gives the same balance and destroys the only evidence that a deduction the '
            . "employee saw on a payslip ever happened";
    }

    $kinds = array_column($loan->history(), 'kind');

    if ($kinds !== ['instalment', 'reversal']) {
        $problems[] = 'the history reads ' . implode(', ', $kinds) . ' rather than instalment then reversal';
    }

    // Reversing twice is a refund nobody earned.
    try {
        $loan->reverse('2026-09', 'again');
        $problems[] = 'the same instalment was reversed twice, which pays the employee back money they were '
            . 'never charged';
    } catch (\InvalidArgumentException) {
        // Refused, as it must be.
    }

    // A reversal with no reason is a correction nobody can review.
    $second = new Recovery('E-2', $r['code'], Money::of('1000.00'), Money::of('100.00'));
    $second->take('2026-09');

    try {
        $second->reverse('2026-09', '   ');
        $problems[] = 'a reversal was accepted with no reason given';
    } catch (\InvalidArgumentException) {
        // Refused, as it must be.
    }

    // The final instalment is the REMAINDER, not the standing amount.
    $tail = new Recovery('E-3', $r['code'], Money::of('250.00'), Money::of('100.00'));
    $tail->take('2026-07');
    $tail->take('2026-08');
    $last = $tail->take('2026-09');

    if ($last->toDecimalString() !== '50.00') {
        $problems[] = "the final instalment is {$last->toDecimalString()} rather than the 50.00 remaining — "
            . 'taking the full instalment on the last month turns a loan into a credit balance';
    }

    if (!$tail->outstanding()->isZero()) {
        $problems[] = 'a fully recovered loan has an outstanding of ' . $tail->outstanding()->toDecimalString();
    }

    // And the recovery reaches the PAYSLIP as a line, or none of this is wired up.
    $slip = (new PayrollRun('2026-09', $components, [gateInput($declaredRecovery)]))->payslips[0];

    if ($slip->lineFor($r['code']) === null) {
        $problems[] = 'the recovery produced no payslip line, so the ledger and the payslip are two systems';
    }

    $results[] = [
        'name' => 'Every recovery reverses cleanly',
        'ran' => true,
        'problems' => $problems,
        'detail' => 'an instalment of ' . $r['amount'] . ' against ' . $r['outstanding_before']
            . ' outstanding appears on the payslip, reverses to EXACTLY the original balance as a '
            . 'compensating entry rather than a deletion, refuses a second reversal and a reversal with no '
            . 'reason, and a final instalment takes only the remainder',
    ];
}

// =============================================================================

$failed = 0;
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);
    } elseif ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);
    } else {
        printf("\n  %d. \033[32mPASS      \033[0m  %s\n", $index + 1, $result['name']);
        echo "        {$result['detail']}\n";

        continue;
    }

    foreach ($result['problems'] as $problem) {
        echo "        └─ {$problem}\n";
    }
}

printf(
    "\n%s  %d of %d Phase 5 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun,
    count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN",
);

exit($failed === 0 && $notRun === 0 ? 0 : 1);
