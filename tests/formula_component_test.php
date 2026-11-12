<?php

declare(strict_types=1);

/**
 * A SALARY COMPONENT THAT IS AN EXPRESSION, ASSERTED IN RUPEES.
 *
 * The product's pitch is "change configuration, not code", and until the
 * `formula` basis existed a customer whose HRA is "40% of basic, capped at
 * ₹20,000" met that pitch failing at the first thing they configure: five fixed
 * shapes, none of which can say "capped", and an answer that is a release.
 *
 * Every load-bearing assertion below is a RUPEE FIGURE a person can check by
 * hand — ₹60,000 × 50% = ₹30,000, 40% of that is ₹12,000, the cap does not bind;
 * on ₹1,20,000 the basic is ₹60,000, 40% is ₹24,000 and the cap takes it to
 * ₹20,000. That is deliberate and this file's reason for being written that way
 * is in tests/monthly_gross_test.php: a relationship between two computed
 * quantities is satisfied by both of them being wrong together, which is how
 * this repository once carried every payslip twelve times too high beneath
 * seven thousand passing assertions and arithmetically perfect working.
 *
 * The NON-VACUITY section at the end proves the outcomes differ from each other:
 * a suite in which every case fails in the same way, or in which the cap and the
 * absence of a cap produce the same number, is a suite that tests nothing.
 */

use SmartPRS\Platform\Formula\CircularDependency;
use SmartPRS\Platform\Payroll\PayslipCalculator;
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('formula component');

/** A formula component, with everything the engine makes mandatory filled in. */
$formula = static function (
    string $code,
    string $expression,
    string $kind = SalaryComponent::EARNING,
    string $mode = 'half_up',
    string $unit = '0.01',
    bool $prorates = true,
    int $sequence = 0,
    ?array $declared = null,
): SalaryComponent {
    return new SalaryComponent(
        code: $code,
        kind: $kind,
        basis: SalaryComponent::FORMULA,
        flags: ['prorates' => $prorates],
        sequence: $sequence,
        declaredFormula: $declared ?? [
            'expression' => $expression,
            'rounding_mode' => $mode,
            'rounding_unit' => $unit,
            'description' => 'A worked component from the salary structure, exercised end to end.',
            'sample_cases' => ['full month', 'mid-month joiner', 'loss of pay'],
        ],
    );
};

$basic = new SalaryComponent('C-BASIC', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF_GROSS,
    ['prorates' => true], ratePercent: 50, sequence: 10);

$structure = new Structure(
    (new Store(sys_get_temp_dir() . '/prs3-formula-' . bin2hex(random_bytes(4))))->forTenant('1'),
    __DIR__ . '/../config/salary-structure.json',
);

// =============================================================================
// THE SHIPPED EXAMPLE — config/salary-structure.json, effective 2027-04-01.
// C-HRA is 40% of C-BASIC, capped at ₹20,000 for the month.
// =============================================================================

$shipped = static fn (string $date, string $gross, int $payable, int $days) => (new PayslipCalculator(
    $structure->on($date)
))->calculate('E-1', '2027-04', Money::of($gross), Rational::of($payable), $days);

// A monthly gross of ₹60,000. Basic is half of it; 40% of ₹30,000 is ₹12,000,
// which is under the cap, so the cap changes nothing and the figures are the
// same as the version that shipped before it.
$under = $shipped('2027-04-30', '60000.00', 30, 30);

same('₹30,000.00', $under->lineFor('C-BASIC')?->amount->format(),
    'C-BASIC is 50% of a ₹60,000 monthly gross = ₹30,000.00');
same('₹12,000.00', $under->lineFor('C-HRA')?->amount->format(),
    'C-HRA is 40% of ₹30,000 = ₹12,000.00, and the ₹20,000 cap does not bind');
same('₹18,000.00', $under->lineFor('C-SPECIAL')?->amount->format(),
    'C-SPECIAL absorbs the residue: ₹60,000 − ₹30,000 − ₹12,000 = ₹18,000.00');
same('₹59,800.00', $under->netPay()->format(),
    'and the net is ₹60,000 less the ₹200 flat professional tax = ₹59,800.00');

// A monthly gross of ₹1,20,000. Basic is ₹60,000, 40% of it is ₹24,000, and the
// cap takes it to ₹20,000 — the figure that could not be expressed at all
// before this basis existed. The ₹4,000 the cap took off does not vanish: the
// balance component absorbs it, so the earnings still sum to the gross.
$capped = $shipped('2027-04-30', '120000.00', 30, 30);

same('₹60,000.00', $capped->lineFor('C-BASIC')?->amount->format(),
    'C-BASIC is 50% of a ₹1,20,000 monthly gross = ₹60,000.00');
same('₹20,000.00', $capped->lineFor('C-HRA')?->amount->format(),
    'C-HRA is MIN(40% of ₹60,000, ₹20,000) = ₹20,000.00 — 40% would have been ₹24,000.00');
same('₹40,000.00', $capped->lineFor('C-SPECIAL')?->amount->format(),
    'the ₹4,000 the cap took off lands in the balance: ₹1,20,000 − ₹60,000 − ₹20,000 = ₹40,000.00');
same('₹1,20,000.00', $capped->grossEarnings()->format(),
    'so the earning lines still sum to the gross EXACTLY, cap or no cap');
same('₹1,19,800.00', $capped->netPay()->format(),
    'and the net is ₹1,20,000 less the ₹200 professional tax = ₹1,19,800.00');

// Half a month on the same contract. The gross prorates ONCE, before the split,
// so C-BASIC is ₹30,000 and 40% of it is ₹12,000 — under the cap. The full-month
// figure is still ₹20,000, and the difference is what proration cost.
$half = $shipped('2027-04-30', '120000.00', 15, 30);

same('₹12,000.00', $half->lineFor('C-HRA')?->amount->format(),
    'half a month of a ₹1,20,000 contract: basic ₹30,000, HRA 40% of it = ₹12,000.00, cap idle');
same('₹20,000.00', $half->lineFor('C-HRA')?->amountBeforeProration->format(),
    'the full-month figure for the same line is the CAPPED ₹20,000.00 — a formula component reports '
    . 'what proration cost like every other component, computed by running the same expression '
    . 'against a full month rather than by scaling the answer');
same('₹8,000.00', $half->lineFor('C-HRA')?->prorationDelta()->format(),
    'so proration took ₹8,000.00 off this line (₹20,000 − ₹12,000), which is the RC-08 term');
same('₹59,800.00', $half->netPay()->format(),
    'and half of ₹1,20,000 nets the same ₹59,800.00 as a full month of ₹60,000');

// ------------------------------------------- the trace is the ENGINE's own one
$trace = (string) $half->lineFor('C-HRA')?->trace;

ok(str_contains($trace, "MIN(salary.component('C-BASIC') % 40, MONEY(20000, 'INR'))"),
    'the stored trace carries the expression itself, so the number can be re-derived from the payslip');
ok(str_contains($trace, 'exact                  = 1200000/1 paise'),
    'and the EXACT rational in paise before the rounding boundary — 12,00,000 paise is ₹12,000.00 — '
    . 'which is the formula engine\'s own sentence, not a re-description written afterwards');
ok(str_contains($trace, 'half_up to 0.01') && str_contains($trace, 'delta 0'),
    'and the declared rounding and what it cost, whether or not it cost anything');
ok(str_contains($trace, 'stops at ₹20,000 for the month'),
    'and the sentence the structure declared, printed under the number on the payslip');

// ------------------------------------ the version that shipped before is intact
// A new effective-dated version APPENDS. The months before 2027-04-01 are still
// computed by the structure that was in force then, to the same paisa.
$old = (new PayslipCalculator($structure->on('2026-09-30')))
    ->calculate('E-1', '2026-09', Money::of('60000.00'), Rational::of(30), 30);

same('₹12,000.00', $old->lineFor('C-HRA')?->amount->format(),
    'September 2026 still resolves to the percent_of version of C-HRA: 40% of ₹30,000 = ₹12,000.00');
same('₹59,800.00', $old->netPay()->format(),
    'and its net is unchanged at ₹59,800.00 — adding a 2027 version moved no figure in 2026');

// =============================================================================
// ONE COMPONENT READING ANOTHER — which is what makes topological order matter.
// =============================================================================

// C-BONUS is 10% of C-HRA, and it is DECLARED FIRST, before the component it
// reads. Order on the page and order of arithmetic are two different things
// (R-VIII-037); SmartPRS 2 conflates them, so a customer who reorders their
// payslip changes their arithmetic.
$chained = (new PayslipCalculator([
    $formula('C-BONUS', "salary.component('C-HRA') % 10", sequence: 5),
    $basic,
    $formula('C-HRA', "MIN(salary.component('C-BASIC') % 40, MONEY(20000, 'INR'))", sequence: 20),
]))->calculate('E-1', '2027-04', Money::of('120000.00'), Rational::of(30), 30);

same('₹20,000.00', $chained->lineFor('C-HRA')?->amount->format(),
    'C-HRA is the capped ₹20,000.00');
same('₹2,000.00', $chained->lineFor('C-BONUS')?->amount->format(),
    'C-BONUS is 10% of C-HRA = ₹2,000.00, even though it is declared FIRST — the amount is computed '
    . 'in topological order, so it reads ₹20,000 and not the ₹0.00 an unwritten component reads');
same('C-BONUS', $chained->lines[0]->componentCode,
    'and it still appears FIRST on the payslip: sequence governs presentation and nothing else');

// =============================================================================
// EVERY REFUSAL IS A SENTENCE, AND NOTHING IS EVER SILENTLY DROPPED
// =============================================================================

// ------------------------------------------------------------------- a cycle
$cyclic = [
    $formula('C-A', "salary.component('C-B') % 50", sequence: 10),
    $formula('C-B', "salary.component('C-A') % 50", sequence: 20),
];

$cycle = null;

try {
    new PayslipCalculator($cyclic);
} catch (CircularDependency $e) {
    $cycle = $e;
}

ok($cycle instanceof CircularDependency,
    'two components that read each other are refused by the graph, before a single amount is '
    . 'computed — never resolved by evaluation order and never broken by a depth limit');
ok($cycle !== null && str_contains($cycle->getMessage(), 'C-A')
    && str_contains($cycle->getMessage(), 'C-B'),
    'and the message NAMES both components, because "circular dependency detected" tells an '
    . 'administrator nothing they can act on');
ok($cycle !== null && str_contains($cycle->getMessage(), "salary.component('C-B')"),
    'and names the reference that made each edge, which is the line to change');
same('component:C-A → component:C-B → component:C-A', $cycle?->path(),
    'and the cycle itself, in the order the references were written');

// A cycle is refused at PUBLISH, with a person present, and not at 3am on a close.
$published = $structure->publish('2028-04-01', json_encode([
    ['code' => 'C-A', 'kind' => 'earning', 'basis' => 'formula', 'sequence' => 10, 'formula' => [
        'expression' => "salary.component('C-B') % 50", 'rounding_mode' => 'half_up',
        'description' => 'Half of the other one, which is half of this one.',
        'sample_cases' => ['a full month', 'a mid-month joiner', 'a month of loss of pay']]],
    ['code' => 'C-B', 'kind' => 'earning', 'basis' => 'formula', 'sequence' => 20, 'formula' => [
        'expression' => "salary.component('C-A') % 50", 'rounding_mode' => 'half_up',
        'description' => 'Half of the other one, which is half of this one.',
        'sample_cases' => ['a full month', 'a mid-month joiner', 'a month of loss of pay']]],
], JSON_THROW_ON_ERROR), 'a circular structure');

ok($published['ok'] === false && str_contains($published['why'], 'Circular dependency'),
    'so publishing it is refused with the cycle in the reason, and the structure is never stored');

// --------------------------------------------------- an expression that will not parse
$unparseable = null;

try {
    $formula('C-BROKEN', "MIN(salary.component('C-BASIC') % 40, MONEY(20000, 'INR')");
} catch (InvalidArgumentException $e) {
    $unparseable = $e->getMessage();
}

ok($unparseable !== null && str_contains($unparseable, "'C-BROKEN'"),
    'an expression that does not parse names the COMPONENT, not a line and column with no owner');
ok($unparseable !== null && str_contains($unparseable, 'cannot read')
    && str_contains($unparseable, "Expected ')'"),
    'and says what is wrong with it, in the parser\'s own words');
ok($unparseable !== null && str_contains($unparseable, 'fix the expression and publish again'),
    'and says what to do about it');

// ------------------------------------------------- a component that does not exist
$unknown = null;

try {
    new PayslipCalculator([$basic, $formula('C-X', "salary.component('C-NOPE') % 40")]);
} catch (InvalidArgumentException $e) {
    $unknown = $e->getMessage();
}

ok($unknown !== null && str_contains($unknown, "'C-X' reads salary.component('C-NOPE')")
    && str_contains($unknown, 'C-BASIC'),
    'a reference to a component nobody declared is refused, naming the reader, the missing code and '
    . 'the codes that DO exist — rather than reading as ₹0.00, which underpays somebody and looks '
    . 'exactly like a component nobody declared');

// ------------------------------------------------- a value that is not money
$notMoney = null;

try {
    $formula('C-DAYS', 'period.payable_days * 2');
} catch (InvalidArgumentException $e) {
    $notMoney = $e->getMessage();
}

ok($notMoney !== null && str_contains($notMoney, "'C-DAYS'") && str_contains($notMoney, 'not money'),
    'an expression that produces days rather than rupees is refused: a payslip line is an amount, '
    . 'and there is no coercion that could make one out of a number of days');

// --------------------------------- what an expression may deliberately NOT see
$outOfReach = null;

try {
    $formula('C-AGE', "IF(employee.gender = 'F', MONEY(1000, 'INR'), MONEY(0, 'INR'))");
} catch (InvalidArgumentException $e) {
    $outOfReach = $e->getMessage();
}

ok($outOfReach !== null && str_contains($outOfReach, "Namespace 'employee' is not declared"),
    'a payslip line may read the gross, the days and the other components, and nothing else — an '
    . 'expression reaching for employee data is refused at publish, not left to evaluate to null');

// -------------------------------------------- rounding is declared, never assumed
$noRounding = null;

try {
    $formula('C-NOROUND', 'x', declared: [
        'expression' => "salary.component('C-BASIC') % 40",
        'description' => 'Forty per cent of the basic, with no rounding declared anywhere.',
        'sample_cases' => ['full month', 'mid-month joiner', 'loss of pay'],
    ]);
} catch (InvalidArgumentException $e) {
    $noRounding = $e->getMessage();
}

ok($noRounding !== null && str_contains($noRounding, 'no default rounding'),
    'a formula component with no declared rounding is refused: there is no default rounding anywhere '
    . 'in this product, because a mode that is an accident of the function that produced the number '
    . 'is how SmartPRS 2 came to round its two gratuity reports differently from one another');

// ---------------------------------- a run is refused, never quietly short a line
$refusedRun = null;

try {
    (new PayslipCalculator([$basic, $formula('C-ZERO', 'salary.gross / 0')]))
        ->calculate('E-1', '2027-04', Money::of('60000.00'), Rational::of(30), 30);
} catch (RuntimeException $e) {
    $refusedRun = $e->getMessage();
}

ok($refusedRun !== null && str_contains($refusedRun, 'C-ZERO could not be computed: ERR_DIV_ZERO'),
    'an expression that fails at evaluation refuses the RUN rather than returning a payslip with the '
    . 'line missing — a missing earning is somebody underpaid, and it looks exactly like a component '
    . 'nobody declared');

// =============================================================================
// THE ROUNDING BOUNDARY — declared on the component, applied ONCE, to the exact
// rational. A third of ₹1,000 is ₹333.3333…, which does not exist in paise.
// =============================================================================

$third = static fn (string $mode, string $unit): string => (string) (new PayslipCalculator([
    $formula('T-THIRD', 'salary.gross / 3', mode: $mode, unit: $unit, prorates: false),
]))->calculate('E-1', '2027-04', Money::of('1000.00'), Rational::of(30), 30)->lineFor('T-THIRD')?->amount->format();

same('₹333.33', $third('half_up', '0.01'),
    'a third of ₹1,000 rounded half_up to the paisa is ₹333.33');
same('₹333.00', $third('half_up', '1.00'),
    'the same third rounded half_up to the RUPEE is ₹333.00 — the unit is declared, not assumed');
same('₹334.00', $third('ceil', '1.00'),
    'and rounded up to the rupee it is ₹334.00 — the mode is declared too');

// =============================================================================
// NON-VACUITY — the outcomes above genuinely differ from one another.
//
// Without this section a file of refusals proves nothing: every case failing for
// the same reason, or the cap making no difference to any number, would read
// exactly like the file above.
// =============================================================================

// 1. The cap is what changed the number. The SAME structure without MIN() pays
//    ₹24,000 where the shipped one pays ₹20,000 — so ₹20,000 is the cap
//    binding, not the engine quietly failing to compute 40% of anything.
$uncapped = (new PayslipCalculator([$basic, $formula('C-HRA', "salary.component('C-BASIC') % 40", sequence: 20)]))
    ->calculate('E-1', '2027-04', Money::of('120000.00'), Rational::of(30), 30);

same('₹24,000.00', $uncapped->lineFor('C-HRA')?->amount->format(),
    'NON-VACUITY: the same component with no cap pays ₹24,000.00, which is ₹4,000.00 more than the '
    . 'capped ₹20,000.00 asserted above');

// 2. The three rounding declarations produce three different figures, so
//    "rounding is declared" is not a decorative field that lands on ₹333.33
//    whatever it says.
ok($third('half_up', '0.01') !== $third('half_up', '1.00')
    && $third('half_up', '1.00') !== $third('ceil', '1.00'),
    'NON-VACUITY: the three declarations produce three different amounts');

// 3. The cycle is refused for being a CYCLE. The same two components with the
//    loop opened — C-A reads C-BASIC instead of C-B — publish and compute.
$opened = (new PayslipCalculator([
    $basic,
    $formula('C-A', "salary.component('C-BASIC') % 50", sequence: 10),
    $formula('C-B', "salary.component('C-A') % 50", sequence: 20),
]))->calculate('E-1', '2027-04', Money::of('120000.00'), Rational::of(30), 30);

same('₹30,000.00', $opened->lineFor('C-A')?->amount->format(),
    'NON-VACUITY: with the loop opened, C-A is 50% of the ₹60,000 basic = ₹30,000.00');
same('₹15,000.00', $opened->lineFor('C-B')?->amount->format(),
    'and C-B is 50% of that = ₹15,000.00 — so the refusal above is about the cycle and not about '
    . 'formula components referring to one another at all');

// 4. The unparseable expression is refused for the missing bracket. With the
//    bracket, the identical text is accepted and pays a figure.
$repaired = (new PayslipCalculator([
    $basic,
    $formula('C-BROKEN', "MIN(salary.component('C-BASIC') % 40, MONEY(20000, 'INR'))", sequence: 20),
]))->calculate('E-1', '2027-04', Money::of('120000.00'), Rational::of(30), 30);

same('₹20,000.00', $repaired->lineFor('C-BROKEN')?->amount->format(),
    'NON-VACUITY: the same expression with its closing bracket restored is accepted and pays '
    . '₹20,000.00, so the refusal above is the parser and not the basis');

// 5. A structure with no formula component at all still computes, unchanged, and
//    through the same code path — so none of the above is reachable only by the
//    new basis and the old four bases are not quietly running on a second road.
$plain = (new PayslipCalculator($structure->on('2026-09-30')))
    ->calculate('E-9', '2026-09', Money::of('50000.00'), Rational::of(22), 24);

same('₹45,833.33', $plain->grossEarnings()->format(),
    'NON-VACUITY: a structure with no formula in it prorates ₹50,000 at 22/24 to ₹45,833.33 exactly '
    . 'as it did before this basis existed');
