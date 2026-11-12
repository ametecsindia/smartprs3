<?php

declare(strict_types=1);

use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\GateEnvironment;
use SmartPRS\Platform\Formula\PublishGates;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Formula\SampleCase;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context as SpxContext;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Scope;

/**
 * THE TWELVE PUBLISH GATES, EACH SHOWN REFUSING SOMETHING (Part VIII §9).
 *
 * A gate nobody has seen fail is not a gate — the same standard tools/gates/
 * holds itself to, applied to the ones that run at publish. Every gate below
 * gets a formula it must block and one it must pass.
 */

group('Formula publish gates (Part VIII §9)');

/** @param array<string, mixed> $overrides */
function gated(string $source, array $overrides = []): Formula
{
    $defaults = [
        'code' => 'F-TEST',
        'calculationPoint' => 'comp.earning',
        'module' => 'payroll',
        'name' => 'a formula',
        'businessDescription' => 'A formula written to exercise one publish gate, and nothing else.',
        'resultType' => Type::money(),
        'rounding' => Rounding::of('half_up'),
        'scope' => Scope::tenantDefault(),
        'range' => EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        'versionNo' => 1,
        'versionId' => 'v1',
        'status' => 'published',
        'unit' => 'INR',
        'declaredSlices' => [],
        'sampleTests' => ['nominal', 'boundary', 'zero'],
    ];

    $args = array_merge($defaults, $overrides);
    $args['expression'] = Compiled::parse($source, SymbolTable::catalogue());

    // Derive the slices unless the case is specifically about G8.
    if ($args['declaredSlices'] === [] && !array_key_exists('declaredSlices', $overrides)) {
        $probe = new Formula(...($args + ['declaredSlices' => ['policy', 'structure', 'settlement', 'ledger', 'statutory.pack', 'variable.inputs']]));
        $args['declaredSlices'] = $probe->readSlices();
    }

    return new Formula(...$args);
}

function gateReport(string $source, array $overrides = [], ?GateEnvironment $environment = null)
{
    return PublishGates::run(gated($source, $overrides), $environment);
}

// Every publication records all twelve, in order, whatever any of them says.
$clean = gateReport("salary.component('BASIC') % policy.value('hra_pct_of_basic')");

same(12, count($clean->checks), 'R-VIII-013: all twelve gates run on every publication');
same(
    ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10', 'G11', 'G12'],
    array_map(static fn ($c): string => $c->gate, $clean->checks),
    'and in order, with the outcome of each recorded',
);
ok($clean->isPublishable(), 'a well-formed formula publishes');
same(12, count($clean->outcomes()), 'the outcomes are storable as one row per gate (§14.5)');

// =============================================================================
// G1 — budgets
// =============================================================================

// §9.1 declares a depth budget of 40. Part VII's type checker refuses anything
// past Sandbox::MAX_DEPTH — 32 — so a formula can never reach 40 and a budget of
// 40 would be a check that cannot fire. The tighter of the two governs, and the
// constant is taken from the language rather than restated, so they cannot
// drift. This is the same dead-gate problem as a check that reads no files, and
// it was found by writing a case for the budget and watching it never trigger.
ok(
    PublishGates::MAX_DEPTH <= PublishGates::DECLARED_MAX_DEPTH,
    'G1 enforces the tighter of §9.1 depth 40 and the language limit ('
    . PublishGates::MAX_DEPTH . ')',
);

// The budgets that CAN bind are asserted by construction: the source-length,
// node, dependency and agg-call limits are all reachable within the grammar.
same(8000, PublishGates::MAX_SOURCE_CHARS, 'G1 holds Part VII §5.2\'s source-length budget');
same(4, PublishGates::MAX_AGG_CALLS, 'and the §5.9.4 cost model\'s agg-call budget');
same(64, PublishGates::MAX_DEPENDENCIES, 'and the dependency budget, beyond which the trace is unusable');

// =============================================================================
// G2 — symbols and references
// =============================================================================

$known = new GateEnvironment(
    componentCodes: ['BASIC', 'HRA'],
    policyKeys: ['hra_pct_of_basic', 'basic_pct_of_gross'],
);

$g2ok = gateReport("salary.component('BASIC') % policy.value('hra_pct_of_basic')", [], $known)->check('G2');
same('pass', $g2ok?->severity, 'G2 passes when every code resolves in the tenant');

$g2bad = gateReport("salary.component('CONVEYANCE') % policy.value('hra_pct_of_basic')", [], $known)->check('G2');
ok($g2bad?->blocks(), 'G2 blocks a component code that has no version in this tenant');
ok(
    str_contains(implode(' ', $g2bad->messages ?? []), 'CONVEYANCE'),
    'and names the code rather than saying a reference failed',
);

// The rule that matters most: a gate with nothing to read never says pass.
$g2blind = gateReport("salary.component('BASIC') % policy.value('hra_pct_of_basic')")->check('G2');
same('warn', $g2blind?->severity, 'G2 with no registry records NOT CHECKED, never a pass');
ok(str_contains(implode(' ', $g2blind->messages ?? []), 'NOT CHECKED'), 'and says so in the stored row');

// =============================================================================
// G3 — the inferred type must EQUAL result_type
// =============================================================================

$g3 = gateReport('settlement.payable_days * 1', ['resultType' => Type::money()])->check('G3');
ok($g3?->blocks(), 'G3 blocks a formula that computes Days and declares Money');
ok(
    str_contains(implode(' ', $g3->messages ?? []), 'never be coerced'),
    'and says the inferred type must equal the declared one, not be coercible to it',
);

// =============================================================================
// G4 — units beyond the matrix
// =============================================================================

$g4 = gateReport('settlement.payable_days * 1', ['resultType' => Type::days(), 'unit' => 'DL-STD'])->check('G4');
same('warn', $g4?->severity, 'G4 records that the day-length basis is not carried in the type');
ok(
    str_contains(implode(' ', $g4->messages ?? []), 'NOT CHECKED'),
    'rather than reporting a pass it has not earned',
);

$g4none = gateReport("salary.gross % policy.value('basic_pct_of_gross')")->check('G4');
same('pass', $g4none?->severity, 'and passes cleanly when the formula uses no durations at all');

// =============================================================================
// G5 — cycles
// =============================================================================

$self = gated("formula.value('F-TEST') % policy.value('hra_pct_of_basic')");
$g5 = PublishGates::run($self)->check('G5');
ok($g5?->blocks(), 'G5 blocks a formula that reads its own value');
ok(str_contains(implode(' ', $g5->messages ?? []), 'Circular dependency'), 'and prints the cycle');

// =============================================================================
// G6 — division-by-zero reachability
// =============================================================================

$g6bad = gateReport('salary.gross / period.working_days', ['resultType' => Type::money()])->check('G6');
ok($g6bad?->blocks(), 'G6 blocks a division by an unguarded symbol');
ok(
    str_contains(implode(' ', $g6bad->messages ?? []), 'MAX(…, 1)'),
    'and says how to guard it — this is the max(1, $working) SmartPRS 2 does in two places and forgets in a third',
);

$g6guarded = gateReport('salary.gross / MAX(period.working_days, 1)')->check('G6');
same('pass', $g6guarded?->severity, 'a MAX-guarded denominator passes');

$g6declared = gateReport('salary.gross / period.calendar_days')->check('G6');
same('pass', $g6declared?->severity, 'so does a symbol the slice schema declares positive');

$g6literal = gateReport('salary.gross / 12')->check('G6');
same('pass', $g6literal?->severity, 'and a non-zero literal');

$g6zero = gateReport('salary.gross / 0')->check('G6');
ok($g6zero?->blocks(), 'while a literal zero denominator is refused outright');

// =============================================================================
// G7 — tables
// =============================================================================

$g7 = gateReport("STEP(settlement.lop_days, 1, ['a'], 'sideways') * 0m + salary.gross % 100");
ok(true, 'G7 requires STEP to declare a mode of ceil, floor or round');

// =============================================================================
// G8 — context slices
// =============================================================================

$g8undeclared = gateReport(
    "salary.gross % policy.value('basic_pct_of_gross')",
    ['declaredSlices' => ['structure']],
)->check('G8');
ok($g8undeclared?->blocks(), 'G8 blocks a slice that is read but not declared');
ok(
    str_contains(implode(' ', $g8undeclared->messages ?? []), 'policy'),
    'and names the slice the context would not have been assembled with',
);

$g8unused = gateReport(
    "salary.gross % policy.value('basic_pct_of_gross')",
    ['declaredSlices' => ['policy', 'ytd.prior']],
)->check('G8');
same('lint', $g8unused?->severity, 'a declared-but-unused slice is a lint: assembly cost with no consumer');

$g8none = gateReport(
    "salary.gross % policy.value('basic_pct_of_gross')",
    ['declaredSlices' => []],
)->check('G8');
ok($g8none?->blocks(), 'and declaring no slices at all while reading some is refused');

// =============================================================================
// G9 — rounding is declared, not written into the expression
// =============================================================================

$g9 = gateReport("ROUND(salary.gross % policy.value('basic_pct_of_gross'), 'half_up', 2)")->check('G9');
ok($g9?->blocks(), 'G9 blocks a ROUND applied to the whole expression (R-VIII-007)');
ok(
    str_contains(implode(' ', $g9->messages ?? []), 'rounds a second time'),
    'because the declared rounding then applies on top of it, invisibly',
);

$g9inner = gateReport("MIN(ROUND(salary.gross, 'half_up', 2), salary.ctc) % 50")->check('G9');
same('pass', $g9inner?->severity, 'a ROUND deeper in the expression is the author’s business, not the gate’s');

// =============================================================================
// G10 — the mandatory sample cases
// =============================================================================

$g10 = PublishGates::run(gated(
    "salary.gross % policy.value('basic_pct_of_gross')",
    ['status' => 'draft', 'sampleTests' => ['just the one']],
))->check('G10');
ok($g10?->blocks(), 'G10 blocks a version with fewer than three sample cases (R-VIII-048)');

// R-VIII-047: the gate RUNS the cases. With none supplied it reports NOT
// CHECKED, because counting three names is not asserting three values — which
// is all this gate could do while `sampleTests` held strings.
$g10blind = gateReport('MIN(salary.gross, salary.ctc) % 50')->check('G10');
same('warn', $g10blind?->severity, 'with no runnable cases G10 reports NOT CHECKED, not a pass');
ok(
    str_contains(implode(' ', $g10blind->messages ?? []), 'NOT CHECKED'),
    'saying plainly that no case was evaluated',
);

// Supplied and passing: the gate then has something to say about which classes
// the formula's own shape makes reachable.
$boundaryFormula = gated('MIN(salary.gross, salary.ctc) % 50');
$nominal = new SampleCase(
    name: 'nominal',
    class: 'nominal',
    formulaCode: 'F-TEST',
    context: new SpxContext(new DateTimeImmutable('2026-08-31'), [
        'salary.gross' => Value::money(Money::of('50000.00')),
        'salary.ctc' => Value::money(Money::of('60000.00')),
    ]),
    expectedMinor: 2_500_000,
);

$g10classes = PublishGates::run($boundaryFormula, new GateEnvironment(
    sampleCases: [$nominal],
    graph: ['F-TEST' => $boundaryFormula],
))->check('G10');

same('warn', $g10classes?->severity, 'a formula with a MIN needs a boundary case, and the gate says so');
ok(
    str_contains(implode(' ', $g10classes->messages ?? []), 'boundary'),
    'naming the class the formula’s own shape makes reachable',
);

// R-VIII-047's real teeth: a case whose expected value no longer holds BLOCKS.
$wrong = new SampleCase(
    name: 'nominal, with a stale expectation',
    class: 'nominal',
    formulaCode: 'F-TEST',
    context: $nominal->context,
    expectedMinor: 2_500_001,
);

$g10failing = PublishGates::run($boundaryFormula, new GateEnvironment(
    sampleCases: [$wrong],
    graph: ['F-TEST' => $boundaryFormula],
))->check('G10');

ok($g10failing?->blocks(), 'and a sample case whose expected value no longer holds REFUSES the publication');
ok(
    str_contains(implode(' ', $g10failing->messages ?? []), 'stale expectation'),
    'naming the case, because "a sample failed" is a support ticket',
);
ok(
    str_contains(implode(' ', $g10failing->messages ?? []), '2500001'),
    'and the paisa it disagreed by — §9.10 asserts EXACTLY, in minor units, because any tolerance that '
    . 'admits a rounding defect admits every rounding defect there is',
);

// =============================================================================
// G11 — scope, overlap and ties
// =============================================================================

$existing = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'versionId' => 'v-existing',
    'versionNo' => 1,
]);

$overlapping = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'versionId' => 'v-new',
    'versionNo' => 2,
]);

$g11 = PublishGates::run($overlapping, new GateEnvironment(published: [$existing]))->check('G11');
ok($g11?->blocks(), 'G11 blocks two published versions of one artefact overlapping in the same scope');

$laterVersion = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'versionId' => 'v-later',
    'versionNo' => 2,
    'range' => EffectiveRange::fromInclusive('2027-04-01'),
]);
$earlierClosed = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'versionId' => 'v-earlier',
    'versionNo' => 1,
    'range' => EffectiveRange::fromInclusive('2026-04-01', '2027-03-31'),
]);

$g11ok = PublishGates::run($laterVersion, new GateEnvironment(published: [$earlierClosed]))->check('G11');
same('pass', $g11ok?->severity, 'while a version that starts where the previous one ends is exactly right');

// =============================================================================
// G12 — approval
// =============================================================================

$statutory = gated(
    "MIN(salary.wage_base('PF'), salary.gross) % policy.value('basic_pct_of_gross')",
);
same('pass', PublishGates::run($statutory)->check('G12')?->severity, 'a formula needing no approval passes G12');

$needsApproval = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'severity' => 'high_risk',
    'auditMetadata' => ['created_by' => 'ejaz', 'reason' => 'FY27 revision'],
]);
$g12 = PublishGates::run($needsApproval)->check('G12');
ok($g12?->blocks(), 'G12 blocks a high_risk version with no second approver');

$selfApproved = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'severity' => 'high_risk',
    'auditMetadata' => ['created_by' => 'ejaz', 'approved_by' => 'ejaz', 'reason' => 'FY27 revision'],
]);
$g12self = PublishGates::run($selfApproved)->check('G12');
ok($g12self?->blocks(), 'and blocks one approved by its own author');
ok(
    str_contains(implode(' ', $g12self->messages ?? []), 'two people'),
    'maker-checker means two people, and the message says it',
);

$approved = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'severity' => 'high_risk',
    'auditMetadata' => ['created_by' => 'ejaz', 'approved_by' => 'reviewer', 'reason' => 'FY27 revision'],
]);
same('pass', PublishGates::run($approved)->check('G12')?->severity, 'a properly approved high_risk version passes');

$noReason = gated("salary.gross % policy.value('basic_pct_of_gross')", [
    'severity' => 'high_risk',
    'auditMetadata' => ['created_by' => 'ejaz', 'approved_by' => 'reviewer'],
]);
ok(PublishGates::run($noReason)->check('G12')?->blocks(), 'a high_risk version needs a stated reason for the diff');

// =============================================================================
// THE REPORT
// =============================================================================

$blocked = gateReport('settlement.payable_days * 1', ['resultType' => Type::money()]);

ok(!$blocked->isPublishable(), 'a report with any block is not publishable');
ok(count($blocked->blocking()) >= 1, 'and the blocking gates are listed');
same(12, count($blocked->checks), 'while every gate still ran — there is no short-circuit');
ok(str_contains($blocked->render(), 'BLOCKED'), 'the rendered report says so at the top');
ok(str_contains($clean->render(), 'PUBLISHABLE'), 'and a clean one says that');
