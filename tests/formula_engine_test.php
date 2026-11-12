<?php

declare(strict_types=1);

use SmartPRS\Platform\Formula\Calculator;
use SmartPRS\Platform\Formula\CircularDependency;
use SmartPRS\Platform\Formula\Dependencies;
use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\Graph;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;
use SmartPRS\Platform\Support\Scope;

group('Formula engine (Part VIII)');

$symbols = SymbolTable::catalogue();
$from = new DateTimeImmutable('2026-04-01');

/** A published formula, with the fields §4.1 makes mandatory already filled. */
function formula(
    string $code,
    string $source,
    string $rounding = 'half_up',
    string $unit = '0.01',
    string $kind = 'component',
    string $referenceValue = 'rounded',
    int $sequenceNo = 0,
    string $status = 'published',
    ?Type $resultType = null,
): Formula {
    return new Formula(
        code: $code,
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: $code,
        businessDescription: 'A worked formula from Part VIII, used to exercise the engine end to end.',
        expression: Compiled::parse($source, SymbolTable::catalogue()),
        resultType: $resultType ?? Type::money(),
        rounding: $rounding === 'none' ? Rounding::none() : Rounding::of($rounding, $unit),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: $code . '-v1',
        status: $status,
        kind: $kind,
        referenceValue: $referenceValue,
        unit: 'INR',
        sequenceNo: $sequenceNo,
        sampleTests: ['full month', 'mid-month joiner', 'loss of pay'],
    );
}

// =============================================================================
// R-VIII-012 / §7.1 — THE DEPENDENCY SET IS DERIVED, NEVER AUTHORED
// =============================================================================

$hra = formula('F-HRA-PCT-BASIC', "salary.component('BASIC') % policy.value('hra_pct_of_basic')");

same(
    ['component:BASIC', 'policy:hra_pct_of_basic'],
    array_map(static fn ($d): string => $d->node(), $hra->dependsOn),
    'the dependency set is derived from the AST, in a stable order',
);

ok($hra->dependencyCodes('component') === ['BASIC'], 'and it knows which components it reads');

$statutory = formula(
    'F-PF-EMPLOYEE',
    "MIN(salary.wage_base('PF'), statutory.value('PF_WAGE_CEILING')) % statutory.value('PF_EMPLOYEE_RATE')",
);

ok($statutory->readsStatutory(), 'a formula that reads a statutory head knows it');
ok($statutory->requiresApproval, 'and is forced to maker-checker without the author having to remember (§4.1)');

$ledger = formula('F-EL-ENCASH-UNITS', "agg.balance('LEAVE-EL', period.end) * 1", resultType: Type::number(), rounding: 'half_up');
ok(
    in_array('ledger:LEAVE-EL', array_map(static fn ($d): string => $d->node(), $ledger->dependsOn), true),
    'agg.balance names a ledger, which is a graph source',
);

// A source has no outgoing edges: the graph terminates there.
foreach ($hra->dependsOn as $dependency) {
    if ($dependency->kind === 'policy') {
        ok($dependency->isSource(), 'a policy read is a source, not a node with dependencies');
    }
}

// =============================================================================
// §4.1 — WHAT CANNOT BE CONSTRUCTED CANNOT BE PUBLISHED
// =============================================================================

throws(
    InvalidArgumentException::class,
    static fn () => formula('F-FIXED', "MONEY(1600, 'INR')"),
    'R-VIII-011: a component formula may not be a bare literal — a fixed amount belongs on the structure line',
);

throws(
    InvalidArgumentException::class,
    static fn () => formula('F-NO-ROUNDING', 'salary.gross % 50', rounding: 'none'),
    "R-VIII-025: rounding 'none' is refused on a component formula — there is no default rounding anywhere",
);

throws(
    InvalidArgumentException::class,
    static fn () => formula('F-EXACT-COMPONENT', 'salary.gross % 50', referenceValue: 'exact'),
    "§4.1: reference_value 'exact' needs an intermediate — a component's published number IS the number",
);

throws(
    InvalidArgumentException::class,
    static fn () => new Formula(
        code: 'F-NO-DESC',
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: 'x',
        businessDescription: 'too short',
        expression: Compiled::parse('salary.gross % 50', SymbolTable::catalogue()),
        resultType: Type::money(),
        rounding: Rounding::of('half_up'),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'x',
        status: 'draft',
        unit: 'INR',
    ),
    '§4.1: business_description is mandatory — it is printed in every trace',
);

throws(
    InvalidArgumentException::class,
    static fn () => formula('F-UNTESTED', 'salary.gross % 50', status: 'published') && new Formula(
        code: 'F-UNTESTED',
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: 'x',
        businessDescription: 'A published formula with no sample cases at all, which must be refused.',
        expression: Compiled::parse('salary.gross % 50', SymbolTable::catalogue()),
        resultType: Type::money(),
        rounding: Rounding::of('half_up'),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'x',
        status: 'published',
        unit: 'INR',
    ),
    'R-VIII-047: publication is refused with fewer than three sample cases',
);

// A formula whose result is Money must name its currency; a duration must name
// the day-length policy that gives it meaning (§4.1).
throws(
    InvalidArgumentException::class,
    static fn () => new Formula(
        code: 'F-NO-UNIT',
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: 'x',
        businessDescription: 'A money formula that does not declare which currency its result is in.',
        expression: Compiled::parse('salary.gross % 50', SymbolTable::catalogue()),
        resultType: Type::money(),
        rounding: Rounding::of('half_up'),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'x',
        status: 'draft',
        sampleTests: ['a', 'b', 'c'],
    ),
    '§4.1: unit is mandatory for a Money result',
);

// =============================================================================
// §6.3 — THE ROUNDING DECLARATION
// =============================================================================

// §6.3's own worked table, on ₹1,249.4999… — the value that lands just below
// the half. This is the table, asserted.
// ₹1,249.49999 expressed in PAISE, exactly: 124,949.999 paise.
$exact = Rational::of(124949999, 1000);

$roundedTo = static function (string $mode, string $unit) use ($exact): string {
    $r = Rounding::of($mode, $unit);

    return Money::ofMinor(
        $r->mode->divide($exact->numerator, $exact->denominator * $r->unitInMinor) * $r->unitInMinor,
    )->format();
};

same('₹1,249.50', $roundedTo('half_up', '0.01'), '§6.3: half_up to a paisa gives ₹1,249.50');
same('₹1,249.50', $roundedTo('half_even', '0.01'), 'half_even to a paisa gives the same');
same('₹1,249.50', $roundedTo('ceil', '0.01'), 'ceil to a paisa gives the same');
same('₹1,249.49', $roundedTo('floor', '0.01'), 'floor to a paisa keeps the lower paisa');
same('₹1,249.00', $roundedTo('half_up', '1.00'), 'half_up to a whole rupee gives ₹1,249');
same('₹1,250.00', $roundedTo('ceil', '1.00'), 'ceil to a whole rupee gives ₹1,250 — this is the ESI rounding');
same('₹1,249.00', $roundedTo('floor', '1.00'), 'floor to a whole rupee gives ₹1,249');

// up/down and ceil/floor differ on negative amounts, which is why both pairs
// exist: a recovery is a negative amount.
same(-3, RoundingMode::Up->divide(-5, 2), 'up on a negative goes away from zero');
same(-2, RoundingMode::Down->divide(-5, 2), 'down on a negative goes toward zero');
same(-3, RoundingMode::Floor->divide(-5, 2), 'floor on a negative goes toward -inf');
same(-2, RoundingMode::Ceiling->divide(-5, 2), 'ceil on a negative goes toward +inf');

same(1, Rounding::of('half_up', '0.01')->unitInMinor, 'a rounding unit of 0.01 is one paisa');
same(100, Rounding::of('half_up', '1.00')->unitInMinor, '1.00 is a whole rupee');
same(10000, Rounding::of('half_up', '100.00')->unitInMinor, '100.00 rounds a challan to the nearest ₹100');

throws(
    InvalidArgumentException::class,
    static fn () => Rounding::of('half_up', '0.001'),
    'a rounding unit finer than a paisa is refused: money is integer paise (Law 7)',
);

throws(
    InvalidArgumentException::class,
    static fn () => Rounding::of('nearest', '0.01'),
    'an unknown rounding mode is refused rather than defaulted',
);

same('half_up to 0.01', Rounding::of('half_up')->describe(), 'the declaration renders into the trace');

// =============================================================================
// §7.2 / R-VIII-034 — A CYCLE IS REFUSED, AND NAMED
// =============================================================================

$graph = new Graph();
$graph->add('formula:F-BASIC', [new SmartPRS\Platform\Formula\Dependency('component', 'GROSS')], 0, 'formula F-BASIC v3');
$graph->addEdge('component:GROSS', 'component:BASIC', 'basis_component_code');
$graph->addEdge('component:BASIC', 'formula:F-BASIC', 'formula');

ok($graph->hasCycle(), '§7.2: BASIC deriving from GROSS while GROSS is the balance of BASIC is a cycle');

$thrown = null;

try {
    $graph->evaluationOrder();
} catch (CircularDependency $e) {
    $thrown = $e;
}

ok($thrown instanceof CircularDependency, 'and asking for an evaluation order refuses rather than returning one');
ok(str_contains($thrown?->getMessage() ?? '', 'Circular dependency'), 'the message says what is wrong');
ok(str_contains($thrown?->getMessage() ?? '', 'basis_component_code'), 'and names the reference that created each edge');
ok(str_contains($thrown?->getMessage() ?? '', 'cycle closes here'), 'and shows where the cycle closes');
ok(count($thrown->cycle ?? []) >= 3, 'the cycle is available as data, not only as prose');

// A self-loop is a cycle too — a formula that reads its own component.
$selfLoop = new Graph();
$selfLoop->addEdge('formula:F-X', 'formula:F-X', "formula.value('F-X')");
ok($selfLoop->hasCycle(), 'a self-loop is a cycle');

// =============================================================================
// §7.3 / R-VIII-037 — ORDER IS TOPOLOGICAL, NOT sequence_no
// =============================================================================

// HRA reads BASIC, and BASIC is declared LAST on the payslip. SmartPRS 2 sorts
// by sequence_no and then hard-codes ordered passes to compensate; here the
// presentation order and the arithmetic order are different things.
$basic = formula('F-BASIC', "salary.gross % policy.value('basic_pct_of_gross')", sequenceNo: 99);
$hraOnBasic = formula('F-HRA', "salary.component('BASIC') % policy.value('hra_pct_of_basic')", sequenceNo: 1);

$calculator = new Calculator(['F-BASIC' => $basic, 'F-HRA' => $hraOnBasic]);
$graph = $calculator->graph();
$graph->addEdge('component:BASIC', 'formula:F-BASIC', 'the component is produced by this formula');

$order = $graph->evaluationOrder();
$basicAt = array_search('formula:F-BASIC', $order, true);
$hraAt = array_search('formula:F-HRA', $order, true);

ok($basicAt !== false && $hraAt !== false, 'both formulas are in the order');
ok($basicAt < $hraAt, 'R-VIII-037: BASIC is evaluated before HRA although its sequence_no is 99 and HRA’s is 1');

// The order is total and identical across runs even where the partial order
// leaves a choice: ties break by sequence_no then code, never by hash order.
same($order, $graph->evaluationOrder(), 'and the order is identical on a second call');

// =============================================================================
// EVALUATION — the rounding boundary, applied once, with the delta shown
// =============================================================================

$context = new Context(new DateTimeImmutable('2026-08-31'), [
    'salary.gross' => Value::money(Money::of('50000.00')),
    "policy.value('basic_pct_of_gross')" => Value::quantityOfType(Type::percentage(), Rational::of(50)),
]);

$only = new Calculator(['F-BASIC' => $basic]);
$results = $only->evaluateAll($context, 'digest-1');

same('₹25,000.00', $results['F-BASIC']->money?->format(), 'FL-01 worked: 50% of ₹50,000 is ₹25,000');
same(0, $results['F-BASIC']->deltaMinor()?->numerator, 'and the rounding boundary cost nothing — delta 0');
ok(str_contains($results['F-BASIC']->trace, 'exact'), 'the trace shows the exact value before rounding');
ok(str_contains($results['F-BASIC']->trace, 'half_up to 0.01'), 'and the rounding declaration that was applied');
ok(str_contains($results['F-BASIC']->trace, 'delta'), 'and what the boundary cost');

// A value that does not land on a paisa: the delta is non-zero and visible.
$odd = new Context(new DateTimeImmutable('2026-08-31'), [
    'salary.gross' => Value::money(Money::of('33333.33')),
    "policy.value('basic_pct_of_gross')" => Value::quantityOfType(Type::percentage(), Rational::of(50)),
]);

$oddResult = (new Calculator(['F-BASIC' => $basic]))->evaluateAll($odd, 'digest-2')['F-BASIC'];
same('₹16,666.67', $oddResult->money?->format(), 'half of ₹33,333.33 rounds half_up to ₹16,666.67');
ok($oddResult->deltaMinor()?->numerator !== 0, 'and the half-paisa the boundary added is reported, not hidden');

// =============================================================================
// §7.4 / R-VIII-038 — MEMOISATION IS PER-BATCH, IN-MEMORY, AND NEVER STALE
// =============================================================================

$memoised = new Calculator(['F-BASIC' => $basic]);
$memoised->evaluateAll($context, 'digest-1');
$second = $memoised->evaluate($basic, $context, 'digest-1');

ok($second->memoHit, 'the same formula under the same context digest is a memo hit');
same(1, $memoised->memoHits(), 'and the hit is counted, so a batch can be explained');

$different = $memoised->evaluate($basic, $context, 'a-different-digest');
ok(!$different->memoHit, 'a different context digest is a different answer, never a reused one');

// =============================================================================
// §4.1 — WHAT A DEPENDENT READS
// =============================================================================

$intermediate = new Formula(
    code: 'F-DAILY-RATE',
    calculationPoint: 'comp.earning',
    module: 'payroll',
    name: 'daily rate',
    businessDescription: 'An intermediate daily rate, kept exact so the money it produces rounds only once.',
    expression: Compiled::parse('salary.gross / 30', SymbolTable::catalogue()),
    resultType: Type::money(),
    rounding: Rounding::none(),
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom($from),
    versionNo: 1,
    versionId: 'F-DAILY-RATE-v1',
    status: 'published',
    kind: 'intermediate',
    referenceValue: 'exact',
    unit: 'INR',
    sampleTests: ['a', 'b', 'c'],
);

$rateResult = (new Calculator(['F-DAILY-RATE' => $intermediate]))
    ->evaluateAll($context, 'digest-1')['F-DAILY-RATE'];

// ₹50,000 / 30 does not divide evenly. The intermediate keeps the remainder.
$exactRef = $rateResult->referenceValue('exact');
$roundedRef = $rateResult->referenceValue('rounded');

ok($exactRef !== null && $exactRef->denominator !== 1, 'an intermediate hands on the exact rational, remainder intact');
ok($roundedRef !== null && $roundedRef->denominator === 1, 'while a rounded reference is a whole number of paise');
ok(
    $exactRef?->numerator !== $roundedRef?->numerator * ($exactRef->denominator ?? 1),
    'and the two differ — which is the whole reason §6.2 keeps intermediates exact',
);

// =============================================================================
// §8.3 — THE SLICES A FORMULA ACTUALLY READS ARE DERIVED, NOT DECLARED
// =============================================================================

$reader = formula('F-LOP', "settlement.lop_days * 1 + salary.gross % policy.value('basic_pct_of_gross') / 1");
$slices = $reader->readSlices();

ok(in_array('settlement', $slices, true), 'a settlement read is derived as a settlement slice');
ok(in_array('policy', $slices, true), 'and a policy read as a policy slice');
same($slices, array_values(array_unique($slices)), 'the derived slice list has no duplicates');

// =============================================================================
// A CALCULATOR HOLDS ONLY PUBLISHED FORMULAS
// =============================================================================

throws(
    InvalidArgumentException::class,
    static fn () => new Calculator(['F-DRAFT' => formula('F-DRAFT', 'salary.gross % 50', status: 'draft')]),
    "§4.1: only published formulas evaluate — a draft in a calculator is refused, not skipped",
);

throws(
    InvalidArgumentException::class,
    static fn () => new Calculator(['WRONG-KEY' => $basic]),
    'a formula keyed under anything but its own code is refused',
);

// =============================================================================
// THE FOUR DECISIONS TAKEN — the units, the registers and the band table
// =============================================================================

// A proration factor is dimensionless only when both sides of the ratio carry
// the same unit. period.working_days is Days, not a count, so this types.
$prorate = Compiled::parse('MIN(1, settlement.payable_days / period.working_days)', SymbolTable::catalogue());
$check = $prorate->typeCheck();
ok($check->isValid(), 'Part VIII\'s proration ratio type-checks: Days / Days is a Number');
same('Number', (string) $check->type, 'and the factor is dimensionless, which is what a factor is');

// statutory.value() is typed per code by the pack, defaulted by StatutoryKeys.
$pf = Compiled::parse(
    "MIN(salary.wage_base('PF'), statutory.value('PF_WAGE_CEILING')) % statutory.value('PF_EMPLOYEE_RATE')",
    SymbolTable::catalogue(),
);
ok($pf->typeCheck()->isValid(), 'a wage base capped at a ceiling and taken at a rate type-checks');
same('Money', (string) $pf->typeCheck()->type, 'and produces Money, not Money % Money');

// The band table: named by a literal so its result type is known at publish.
$banded = Compiled::parse("BANDS(settlement.late_count, 'ATT-INCENTIVE-BANDS')", SymbolTable::catalogue());
same('Money', (string) $banded->typeCheck()->type, 'a named band table declares what it returns');

$unknownTable = Compiled::parse("BANDS(settlement.late_count, 'NO-SUCH-TABLE')", SymbolTable::catalogue());
ok(
    str_contains(implode(' ', $unknownTable->typeCheck()->messages()), 'declares no result type'),
    'and an unregistered table does not publish, rather than failing mid-run',
);

$computedTable = Compiled::parse("BANDS(settlement.late_count, employee.code)", SymbolTable::catalogue());
ok(
    str_contains(implode(' ', $computedTable->typeCheck()->messages()), 'literal band-table code'),
    'a computed table reference is refused: it could not be type-checked before it ran',
);

// component.* and structure.*, which the formula library used and §11.0 never declared.
$lop = Compiled::parse('component.full_amount - component.prorated_amount', SymbolTable::catalogue());
same('Money', (string) $lop->typeCheck()->type, 'the LOP deduction is the difference of two component amounts');

$balance = Compiled::parse('ALLOCATE(salary.balance_pool, structure.balance_weights)', SymbolTable::catalogue());
ok($balance->typeCheck()->isValid(), 'and a balance component allocates the pool across the structure\'s weights');
