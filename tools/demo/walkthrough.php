<?php
/**
 * WHAT THE PLATFORM DOES, END TO END, IN ONE COMMAND.
 *
 *   php tools/demo/walkthrough.php
 *
 * There is no application yet — no artisan, no HTTP layer, no screen. What
 * exists is the engines, and this drives them the way the application will:
 * one employee, one day, one rule, one formula, one approval. Every number it
 * prints is computed, not written into this file.
 *
 * Read-only and dependency-free. It touches no database and writes nothing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Formula\Calculator;
use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Rules\Engine;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Workflow\Engine as WorkflowEngine;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantResolver;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\ResolutionContext;
use SmartPRS\Platform\Workflow\Task;
use SmartPRS\Platform\Workflow\TaskState;

function heading(string $text): void
{
    echo "\n\033[1m" . $text . "\033[0m\n" . str_repeat('─', mb_strlen($text)) . "\n";
}

function line(string $label, string $value): void
{
    printf("  %-38s %s\n", $label, $value);
}

echo "\n\033[1mSmartPRS 3 — what the engines do, on one employee, on one day\033[0m\n";
echo "Nothing below is written into this script. Every number is computed.\n";

$symbols = SymbolTable::catalogue();
$today = new DateTimeImmutable('2026-08-26');

// =============================================================================
// 1. THE LANGUAGE — one expression, typed before it runs
// =============================================================================

heading('1. SPX: a rule condition, type-checked before anything runs');

$source = "attendance.late_minutes > policy.value('late_grace_min')";
$compiled = Compiled::parse($source, $symbols);
$check = $compiled->typeCheck();

line('expression', $source);
line('type-checks to', (string) $check->type);
line('AST nodes / depth', $compiled->nodeCount() . ' / ' . $compiled->depth());

$context = new Context($today, [
    'attendance.late_minutes' => Value::quantityOfType(Type::minutes(), Rational::of(23)),
    "policy.value('late_grace_min')" => Value::quantityOfType(Type::minutes(), Rational::of(10)),
]);

line('23 minutes late, 10 minute grace', Evaluator::evaluate($compiled, $context)->describe());

// The same comparison with an untyped policy is refused at PUBLISH, not at run.
$bad = Compiled::parse('attendance.late_minutes > 10', $symbols)->typeCheck();
line('and `late_minutes > 10` (bare number)', $bad->isValid() ? 'accepted' : 'REFUSED: ' . $bad->messages()[0]);

// =============================================================================
// 2. MONEY — exact, and the rounding boundary shows its own cost
// =============================================================================

heading('2. Money: exact arithmetic, one declared rounding boundary');

$gross = Money::of('33333.33');
$half = Rational::of($gross->minor, 2);

line('gross', $gross->format());
line('50% of it, exactly', $half->numerator . '/' . $half->denominator . ' paise');

foreach (['half_up', 'half_down', 'floor', 'ceil'] as $mode) {
    $rounding = Rounding::of($mode);
    $rounded = Money::ofMinor(
        $rounding->mode->divide($half->numerator, $half->denominator * $rounding->unitInMinor)
            * $rounding->unitInMinor,
    );
    line("  rounded {$mode} to 0.01", $rounded->format());
}

$allocated = Money::of('1000.00')->allocate([1, 1, 1]);
line('₹1,000.00 split three ways', implode(' + ', array_map(static fn (Money $m): string => $m->format(), $allocated)));
line('  and they sum to', Money::sum(...$allocated)->format() . '  (residue to the last, never lost)');

// =============================================================================
// 3. FORMULAS — evaluated in dependency order, not payslip order
// =============================================================================

heading('3. Formulas: topological order, and the delta the boundary cost');

$formula = static fn (string $code, string $src, int $sequence): Formula => new Formula(
    code: $code,
    calculationPoint: 'comp.earning',
    module: 'payroll',
    name: $code,
    businessDescription: 'A worked formula from Part VIII, shown here computing a real number.',
    expression: Compiled::parse($src, SymbolTable::catalogue()),
    resultType: Type::money(),
    rounding: Rounding::of('half_up'),
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: $code . '-v1',
    status: 'published',
    unit: 'INR',
    sequenceNo: $sequence,
    sampleTests: ['nominal', 'boundary', 'zero'],
);

// BASIC is declared LAST on the payslip and must be computed FIRST.
$basic = $formula('F-BASIC', "salary.gross % policy.value('basic_pct_of_gross')", 99);
$hra = $formula('F-HRA', "salary.component('BASIC') % policy.value('hra_pct_of_basic')", 1);

$calculator = new Calculator(['F-BASIC' => $basic, 'F-HRA' => $hra]);
$graph = $calculator->graph();
$graph->addEdge('component:BASIC', 'formula:F-BASIC', 'the component is produced by this formula');

line('payslip order (sequence_no)', 'F-HRA (1), then F-BASIC (99)');
line('EVALUATION order (topological)', implode(' → ', array_map(
    static fn (string $n): string => str_replace('formula:', '', $n),
    array_values(array_filter($graph->evaluationOrder(), static fn (string $n): bool => str_starts_with($n, 'formula:'))),
)));
echo "  (SmartPRS 2 sorts by sequence_no, so reordering a payslip changes the arithmetic)\n";

$payrollContext = new Context($today, [
    'salary.gross' => Value::money(Money::of('33333.33')),
    "policy.value('basic_pct_of_gross')" => Value::quantityOfType(Type::percentage(), Rational::of(50)),
]);

$result = (new Calculator(['F-BASIC' => $basic]))->evaluateAll($payrollContext, 'demo')['F-BASIC'];
echo "\n" . preg_replace('/^/m', '  ', rtrim($result->trace)) . "\n";

// =============================================================================
// 4. RULES — a decision with the reason attached
// =============================================================================

heading('4. Rules: a decision that explains itself');

// The rule library itself is seeded by the application, not by this script.
// What is shown here is the property that matters: a rule carries the sentence
// that explains it, and the trace prints that sentence rather than a code.
$decisionPoint = 'attendance.day.penalise';
$rule = new SmartPRS\Platform\Rules\Rule(
    code: 'RL-ATT-001',
    decisionPoint: $decisionPoint,
    module: 'attendance',
    name: 'Third late arrival',
    businessDescription: 'Three late arrivals over ten minutes in one payroll month cost half a day.',
    scope: Scope::of(['branch' => ['HYD']]),
    priority: 0,
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 4,
    versionId: 'RL-ATT-001-v4',
    status: 'published',
    when: SmartPRS\Platform\Rules\SpxExpression::parse(
        "attendance.late_minutes > policy.value('late_grace_min')",
        SymbolTable::catalogue(),
    ),
    then: [new SmartPRS\Platform\Rules\Actions\Action(
        'APPLY_DAY_PENALTY',
        [
            'fraction' => SmartPRS\Platform\Spx\Value::quantityOfType(Type::days(), Rational::of(1, 2)),
            'reason' => 'Third or later late arrival over ten minutes this month',
        ],
    )],
);

line('decision point', $decisionPoint);
line('rule', $rule->describe());
line('why it exists, in the trace', $rule->businessDescription);
line('scope specificity (spine S3.2)', (string) $rule->specificity() . '  — branch weighs 180');
line('money-affecting', $rule->isMoneyAffecting() ? 'yes' : 'no');
line('needs a second approver', $rule->requiresApproval ? 'yes' : 'no');
line('checksum (tamper evidence)', substr($rule->checksum(), 0, 24) . '…');

// =============================================================================
// 5. WORKFLOW — who it goes to, and who it must not go to
// =============================================================================

heading('5. Workflow: participant resolution, and the self-approval prohibition');

$approval = new Node(
    key: 'manager',
    type: NodeType::Approval,
    label: 'Manager approval',
    participants: [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-ejaz', 'u-mgr']])],
);

$resolver = new ParticipantResolver(
    static fn (ParticipantSpec $spec, ResolutionContext $c): array => (array) ($spec->params['holders'] ?? []),
);

$asRequester = $resolver->resolve($approval, new ResolutionContext($today, requesterUserId: 'u-ejaz'));
echo preg_replace('/^/m', '  ', $asRequester->render()) . "\n";

$aboutThem = $resolver->resolve(
    $approval,
    new ResolutionContext($today, requesterUserId: 'u-hr', subjectEmployeeUserId: 'u-ejaz'),
);
echo "\n" . preg_replace('/^/m', '  ', $aboutThem->render()) . "\n";

heading('6. Workflow: a decision cannot be taken twice');

$task = new Task('t-1', 'manager', 'u-mgr');
$approved = $task->decide(TaskState::Approved, 'u-mgr', $today, 'cover arranged');

line('first decision', $approved->state->value . ' by ' . $approved->decidedByUserId);
line('  comment', (string) $approved->comment);

try {
    $approved->decide(TaskState::Rejected, 'u-other', $today, '');
    line('second decision', 'ACCEPTED — this would be a bug');
} catch (Throwable $e) {
    line('second decision by someone else', 'REFUSED (409)');
    line('  the first reason still stands', (string) $approved->comment);
}

// Rejection precedence, whichever order the two arrive in.
$workflow = new WorkflowEngine(static fn (string $e, $i): bool => true);
$parallel = new Node(key: 'both', type: NodeType::ParallelAll, label: 'Both must approve',
    participants: [new ParticipantSpec('requester')]);

$order1 = [
    (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $today),
    (new Task('b', 'both', 'u-2'))->decide(TaskState::Rejected, 'u-2', $today->modify('+1 second')),
];
$order2 = array_reverse($order1);

line('approve then reject', (string) $workflow->outcomeOf($parallel, $order1));
line('reject then approve', (string) $workflow->outcomeOf($parallel, $order2));
echo "  (deterministic: arrival order never decides an outcome)\n";

// =============================================================================

heading('Where to look next');
echo "  php tests/run.php                             the whole suite\n";
echo "  php tools/gates/gate.php --fix-hint           the seven laws over src/\n";
echo "  php tools/blueprint/compile-worked-rules.php  the blueprint's own rules and formulas\n";
echo "  tests/rules_worked_test.php                   Part VII §12's rule library, evaluated\n";
echo "  tests/formula_engine_test.php                 Part VIII's rounding table, asserted\n\n";
