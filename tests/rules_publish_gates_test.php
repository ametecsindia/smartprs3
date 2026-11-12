<?php

declare(strict_types=1);

use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\GateEnvironment;
use SmartPRS\Platform\Rules\PublishGates;
use SmartPRS\Platform\Rules\RulePublisher;
use SmartPRS\Platform\Rules\RuleTestCase;

require_once __DIR__ . '/lib/rules_fixtures.php';

/**
 * The decision-point catalogue (§3.2, R-VII-005) and the nine rule publish
 * gates that read it.
 *
 * The catalogue is a table, so the tests over it are not "does the table say
 * what it says" — they are the two sweeps that catch an edit nobody noticed: an
 * action no decision point can emit, and an allow-list naming an action nothing
 * implements.
 */

group('Rules\DecisionPoints');

$penalise = 'attendance.day.penalise';
$classify = 'attendance.day.classify';

same(13, count(DecisionPoints::all()), 'all thirteen decision points of §3.2 are declared');

same(
    [],
    DecisionPoints::undefinedActions(),
    'every action an allow-list names is defined in the catalogue — a rule that could be published '
    . 'and never applied is worse than one refused',
);

same(
    [],
    DecisionPoints::unreachableActions(),
    'and every action the catalogue defines is emittable somewhere — an action no decision point '
    . 'declares is either a gap in the table or dead weight, and both are invisible until asked',
);

ok(
    DecisionPoints::emits($penalise, 'APPLY_DAY_PENALTY') && !DecisionPoints::emits($penalise, 'SET_STATUS'),
    'the allow-list is a predicate on (decision point, action), not on the action alone',
);

same(
    ConflictPolicy::AllMatch,
    DecisionPoints::conflictPolicy($penalise),
    'attendance.day.penalise is additive: several rules can each cost part of a day',
);
same(
    ConflictPolicy::HighestPriorityOnly,
    DecisionPoints::conflictPolicy($classify),
    'and attendance.day.classify is not: a day has one status',
);

throws(
    InvalidArgumentException::class,
    static fn () => DecisionPoints::actions('attendance.day.invent'),
    'an undeclared decision point is refused rather than answered with an empty allow-list',
);

// ---------------------------------------------------------------------------
// Narrowing, not widening (§4.1)
// ---------------------------------------------------------------------------

group('Rules\ConflictPolicy');

ok(
    ConflictPolicy::FirstMatch->narrows(ConflictPolicy::AllMatch)
    && ConflictPolicy::HighestPriorityOnly->narrows(ConflictPolicy::AllMatch)
    && ConflictPolicy::AllMatch->narrows(ConflictPolicy::AllMatch),
    'every policy narrows all_match, the broadest',
);

ok(
    !ConflictPolicy::AllMatch->narrows(ConflictPolicy::HighestPriorityOnly),
    'and all_match does not narrow highest_priority_only — that is the widening §4.1 forbids',
);

same(
    ConflictPolicy::HighestPriorityOnly,
    ConflictPolicy::narrowerOf(ConflictPolicy::AllMatch, ConflictPolicy::HighestPriorityOnly),
    'the narrower of the two governs a run, whichever way round they are given',
);
same(
    ConflictPolicy::HighestPriorityOnly,
    ConflictPolicy::narrowerOf(ConflictPolicy::HighestPriorityOnly, ConflictPolicy::AllMatch),
    'and the operation is symmetric, so no caller can get a different answer by argument order',
);

// ---------------------------------------------------------------------------
// The gates
// ---------------------------------------------------------------------------

group('Rules\PublishGates');

/** A publishable rule at attendance.day.penalise, varying only what a test is about. */
$sound = static fn (
    string $code = 'RL-GATE-001',
    string $when = "attendance.late_minutes > policy.value('late_grace_min')",
    array $then = [],
    array $scope = [],
    string $point = 'attendance.day.penalise',
    ConflictPolicy $policy = ConflictPolicy::AllMatch,
    string $module = 'attendance',
    string $severity = 'normal',
): SmartPRS\Platform\Rules\Rule => rule(
    $code,
    $point,
    expr($when, $point),
    $then === [] ? [ruleAction('APPLY_DAY_PENALTY', ['fraction' => expr('0.5d', $point, boolean: false), 'reason' => 'late'])] : $then,
    scope: $scope,
    onConflict: $policy,
    module: $module,
    severity: $severity,
    description: 'A late arrival over the declared grace costs half a day.',
);

$clean = PublishGates::run($sound());

same('R01', $clean->checks[0]->gate, 'the gates are reported in order, R01 first');
same(9, count($clean->checks), 'and all nine run on every publication, whatever any one of them says');

// A gate handed no environment must not be green. This is the assertion that
// would have caught four enforcement gates passing over zero files.
foreach (['R07' => 'siblings', 'R08' => 'stored test cases'] as $gate => $what) {
    $check = array_values(array_filter($clean->checks, static fn ($c): bool => $c->gate === $gate))[0];
    same('warn', $check->severity, "{$gate} handed no {$what} reports a warning, not a pass");
    ok(
        str_contains($check->messages[0] ?? '', 'NOT CHECKED'),
        "and says so in words — {$gate} passing silently over nothing would be evidence of a check that never ran",
    );
}

ok($clean->isPublishable(), 'a sound rule with an empty environment is publishable — warnings do not block');

// ---- R02: the decision point, and the policy ceiling ----------------------

$widening = PublishGates::run($sound(point: $classify, policy: ConflictPolicy::AllMatch, then: [
    ruleAction('SET_STATUS', ['code' => 'HALF_DAY']),
]));

ok(!$widening->isPublishable(), 'a rule that widens its decision point is refused');
ok(
    str_contains($widening->render(), 'WIDENS attendance.day.classify'),
    'and the refusal names what it widened, because "invalid conflict policy" is not actionable',
);

// ---- R04: the action allow-list (R-VII-005) ------------------------------

$wrongAction = PublishGates::run($sound(then: [ruleAction('SET_STATUS', ['code' => 'ABSENT'])]));

ok(!$wrongAction->isPublishable(), 'an action the decision point does not declare blocks publication');
ok(
    str_contains($wrongAction->render(), 'APPLY_DAY_PENALTY'),
    'and the message lists what this decision point DOES declare, so the fix is visible',
);

// ---- R06: a rule that can never fire -------------------------------------

$unreachable = PublishGates::run($sound(when: 'attendance.late_minutes > 30m AND attendance.late_minutes < 10m'));

ok(!$unreachable->isPublishable(), 'a `when` that is provably unsatisfiable blocks — it is not a decision');
ok(
    str_contains($unreachable->render(), 'can never be true'),
    'and it says so, rather than being discovered by an administrator who wonders why nothing happens',
);

$undecidable = PublishGates::run($sound(when: "attendance.late_minutes > policy.value('late_grace_min')"));
$r06 = array_values(array_filter($undecidable->checks, static fn ($c): bool => $c->gate === 'R06'))[0];
same(
    'warn',
    $r06->severity,
    'a `when` outside the solver\'s fragment is NOT CHECKED, never assumed reachable — R-VII-060 '
    . 'takes the fail-safe direction at the conflict detector instead',
);

// ---- R07: conflicts with siblings ----------------------------------------

// Two rules, same specificity, same priority, conditions that can both hold.
$a = $sound('RL-TIE-A', 'attendance.late_minutes > 30m');
$b = $sound('RL-TIE-B', 'attendance.late_minutes > 20m');
$tie = PublishGates::run($b, new GateEnvironment(siblings: [$a]));

ok(!$tie->isPublishable(), 'a genuine tie with a sibling blocks at publish, not at payroll time');
ok(
    str_contains($tie->render(), 'RL-TIE-A'),
    'and the counterpart is named — one of the two needs a priority, and the author must know which',
);

$separated = PublishGates::run(
    rule('RL-TIE-B', $penalise, expr('attendance.late_minutes > 20m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => expr('0.5d', $penalise, boolean: false), 'reason' => 'late'])],
        priority: 1, onConflict: ConflictPolicy::AllMatch,
        description: 'A late arrival over the declared grace costs half a day.'),
    new GateEnvironment(siblings: [$a]),
);

ok($separated->isPublishable(), 'and one priority keystroke resolves it — a false positive is cheap, a false negative is not');

// ---- R08: the stored regression cases (§11.6) ----------------------------

$subject = $sound('RL-REG-001', 'attendance.late_minutes > 30m');

$passingCase = new RuleTestCase(
    name: 'forty minutes is penalised',
    ruleCode: 'RL-REG-001',
    context: ctx(['attendance.late_minutes' => 40, 'day.is_holiday' => false, 'day.is_weekly_off' => false]),
    expectMatch: true,
);
$failingCase = new RuleTestCase(
    name: 'ten minutes is not',
    ruleCode: 'RL-REG-001',
    context: ctx(['attendance.late_minutes' => 10, 'day.is_holiday' => false, 'day.is_weekly_off' => false]),
    expectMatch: true,   // deliberately wrong: ten minutes does NOT match
);

ok(
    PublishGates::run($subject, new GateEnvironment(siblings: [], tests: [$passingCase]))->isPublishable(),
    'a rule whose stored cases still pass is publishable',
);

$regressed = PublishGates::run($subject, new GateEnvironment(siblings: [], tests: [$passingCase, $failingCase]));

ok(!$regressed->isPublishable(), 'and one failing stored case blocks the publication');
ok(
    str_contains($regressed->render(), 'ten minutes is not'),
    'naming the case, because "a test failed" is a support ticket',
);

$noCases = array_values(array_filter(
    PublishGates::run($subject, new GateEnvironment(tests: []))->checks,
    static fn ($c): bool => $c->gate === 'R08',
));
same('warn', $noCases[0]->severity, 'a rule with no stored cases is warned about, not called green (R-VII-062)');

// ---- R09: the self-approval prohibition (spine S8) -----------------------

$moneyRule = $sound('RL-APPROVE-001');
ok($moneyRule->requiresApproval, 'APPLY_DAY_PENALTY is money-affecting, so approval is forced');

$selfApproved = PublishGates::run($moneyRule, GateEnvironment::fromAudit([
    'created_by' => '7', 'approved_by' => '7',
]));

ok(!$selfApproved->isPublishable(), 'and the author cannot approve their own money-affecting rule');
ok(
    str_contains($selfApproved->render(), 'cannot approve their own'),
    'the refusal is maker-checker by name, not a permissions error',
);

ok(
    PublishGates::run($moneyRule, GateEnvironment::fromAudit(['created_by' => '7', 'approved_by' => '8']))
        ->isPublishable(),
    'a second person approving it is the whole point, and passes',
);

$unknownApprover = array_values(array_filter(
    PublishGates::run($moneyRule)->checks,
    static fn ($c): bool => $c->gate === 'R09',
));
same(
    'warn',
    $unknownApprover[0]->severity,
    'and with nobody named, R09 records NOT CHECKED — an unproven maker-checker must never read as proven',
);

// ---------------------------------------------------------------------------
// The payload comes from the rule, or it is a second source of truth
// ---------------------------------------------------------------------------

group('Rules\RulePublisher');

$payload = RulePublisher::payload($moneyRule, new DateTimeImmutable('2026-08-15 09:00:00'));

same('attendance.day.penalise', $payload['decision_point'], 'the payload names the decision point from the rule');
same(1, $payload['is_money_affecting'], 'and derives is_money_affecting from the actions, never from an argument');
same(1, $payload['requires_approval'], 'and requires_approval from the rule, which forced it');
same($moneyRule->checksum(), $payload['checksum'], 'and the checksum is the rule\'s own');
same('all_match', $payload['on_conflict'], 'and the conflict policy is the one that was published');

ok(
    is_string($payload['when_ast']) && str_contains((string) $payload['when_ast'], '"ast"'),
    'the stored artefact is the AST, not only the source (R-VII-009)',
);

same(
    ActionCatalogue::VERSION,
    $payload['catalogue_version'] . '.0.0',
    'and the catalogue version it compiled against is recorded, so an upgrade can find what predates it',
);

// The seam this class exists to close: there is no argument through which a
// caller can write a row that disagrees with the rule.
$columns = array_keys($payload);
ok(
    !in_array('config_version_id', $columns, true) && !in_array('tenant_id', $columns, true),
    'the payload carries no ids — the publisher owns those, so a caller cannot point one at another tenant',
);

/**
 * An Expression that is not SPX. It cannot arise from `SpxExpression::parse()`,
 * which is the point: the gate and the publisher must refuse it on the type,
 * not on anything they can read out of it.
 */
final class NotSpxExpression implements SmartPRS\Platform\Rules\Expression
{
    public function source(): string
    {
        return 'late_minutes exceeds the grace';
    }

    public function evaluate(
        SmartPRS\Platform\Rules\EvaluationContext $context,
        ?SmartPRS\Platform\Spx\Sandbox $sandbox = null,
    ): SmartPRS\Platform\Rules\Evaluated {
        return new SmartPRS\Platform\Rules\Evaluated(true, 'never evaluated');
    }

    public function claims(): ?array
    {
        return null;
    }
}

$notSpx = rule(
    'RL-NOTSPX-001',
    $penalise,
    new NotSpxExpression(),
    [ruleAction('APPLY_DAY_PENALTY', ['fraction' => expr('0.5d', $penalise, boolean: false), 'reason' => 'late'])],
    description: 'A rule whose condition is not an SPX expression at all.',
);

throws(
    InvalidArgumentException::class,
    static fn () => RulePublisher::payload($notSpx, new DateTimeImmutable('2026-08-15 09:00:00')),
    'and an expression with no AST cannot be published at all, rather than being stored as bare text',
);

$blocked = PublishGates::run($notSpx);
ok(
    !$blocked->isPublishable() && str_contains($blocked->render(), 'not an SPX expression'),
    'R03 says the same thing first, so the author is told before the publisher throws',
);
