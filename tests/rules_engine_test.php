<?php

declare(strict_types=1);

use SmartPRS\Platform\Rules\CandidateTrace;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\DecisionTrace;
use SmartPRS\Platform\Rules\Engine;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\RuleEvaluationFailed;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Rules\Resolver;
use SmartPRS\Platform\Support\Scope;

require_once __DIR__ . '/lib/rules_fixtures.php';

group('Rules\Engine');

$engine = new Engine();
$dp = 'attendance.grace';

// ---------------------------------------------------------------------------
// Precedence across the full ladder (spine S3.2, Part VII §8.1).
// tenant 0 · company 100 · state 140 · branch 180 · designation 350 ·
// department 400 · employee 1000. Each rung must beat every rung below it, and
// the winning rule must be named exactly.
// ---------------------------------------------------------------------------

$ladder = [
    'GRACE-TENANT' => [],
    'GRACE-COMPANY' => ['company' => ['CMP7']],
    'GRACE-STATE' => ['state' => ['TG']],
    'GRACE-BRANCH' => ['branch' => ['HYD']],
    'GRACE-DESIG' => ['designation' => ['SENIOR-AGENT']],
    'GRACE-DEPT' => ['department' => ['OPS']],
    'GRACE-EMP' => ['employee' => ['E-4471']],
];

$dimensions = [
    'company' => 'CMP7', 'state' => 'TG', 'branch' => 'HYD',
    'designation' => 'SENIOR-AGENT', 'department' => 'OPS', 'employee' => 'E-4471',
];

$rungRules = [];

foreach ($ladder as $code => $scope) {
    $rungRules[$code] = rule($code, $dp, always($dp),
        [ruleAction('SET_GRACE', ['minutes' => 10])],
        scope: $scope,
    );
}

// Add one rung at a time; the newest rung must win outright each time.
$expectedWinners = [
    'GRACE-TENANT' => 'GRACE-TENANT',
    'GRACE-COMPANY' => 'GRACE-COMPANY',
    'GRACE-STATE' => 'GRACE-STATE',
    'GRACE-BRANCH' => 'GRACE-BRANCH',
    'GRACE-DESIG' => 'GRACE-DESIG',
    'GRACE-DEPT' => 'GRACE-DEPT',
    'GRACE-EMP' => 'GRACE-EMP',
];

$accumulated = [];

foreach ($expectedWinners as $code => $winner) {
    $accumulated[] = $rungRules[$code];
    $result = $engine->evaluate($dp, RuleSet::of($dp, ...$accumulated), ctx(dimensions: $dimensions));

    same(
        $winner,
        $result->actions[0]->emittedBy['rule_code'],
        "with the ladder up to {$code}, {$winner} wins",
    );
    same(1, count($result->actions), "and only {$winner} emits an action");
}

// §8.2 Example 7, the intuition trap: designation(350) loses to department(400)
// even though "designation" feels narrower than "department".
$trap = RuleSet::of($dp, $rungRules['GRACE-DESIG'], $rungRules['GRACE-DEPT']);
$result = $engine->evaluate($dp, $trap, ctx(dimensions: $dimensions));
same('GRACE-DEPT', $result->actions[0]->emittedBy['rule_code'], 'department 400 beats designation 350');
ok(
    str_contains((string) $result->trace->reasonFor('GRACE-DESIG'), 'lost on specificity (350 < 400)'),
    'and the trace shows the arithmetic',
);

// §8.2 Example 2: two moderate dimensions beat one large one.
$multi = RuleSet::of(
    $dp,
    rule('OT-HYD-AGENT', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 15])],
        scope: ['branch' => ['HYD'], 'grade' => ['G3']]),
    rule('OT-OPS', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 20])],
        scope: ['department' => ['OPS']]),
);
$result = $engine->evaluate($dp, $multi, ctx(dimensions: $dimensions + ['grade' => 'G3']));
same(
    'OT-HYD-AGENT',
    $result->actions[0]->emittedBy['rule_code'],
    'branch 180 + grade 340 = 520 beats department 400 (§8.2 Example 2)',
);

// §8.2 Example 3: priority never crosses a specificity boundary.
$notCrossed = RuleSet::of(
    $dp,
    rule('LATE-EMP-4471', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 60])],
        scope: ['employee' => ['E-4471']], priority: 0),
    rule('LATE-HYD', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 15])],
        scope: ['branch' => ['HYD']], priority: 900),
);
$result = $engine->evaluate($dp, $notCrossed, ctx(dimensions: $dimensions));
same(
    'LATE-EMP-4471',
    $result->actions[0]->emittedBy['rule_code'],
    'specificity 1000 beats priority 900 at specificity 180 (R-VII-043)',
);
ok(
    str_contains((string) $result->trace->reasonFor('LATE-HYD'), 'lost on specificity (180 < 1000)'),
    'and the loser lost on specificity, not on priority',
);

// §8.2 Example 4: priority genuinely deciding, within one specificity.
$byPriority = RuleSet::of(
    $dp,
    rule('LATE-HYD-STRICT', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 5])],
        scope: ['branch' => ['HYD']], priority: 50),
    rule('LATE-HYD-LEGACY', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 15])],
        scope: ['branch' => ['HYD']], priority: 10),
);
$result = $engine->evaluate($dp, $byPriority, ctx(dimensions: $dimensions));
same('LATE-HYD-STRICT', $result->actions[0]->emittedBy['rule_code'], 'priority 50 beats priority 10 at spec 180');
ok(
    str_contains((string) $result->trace->reasonFor('LATE-HYD-LEGACY'), 'lost on priority (10 < 50 at specificity 180)'),
    'and the trace says it lost on priority, naming both values',
);

// ---------------------------------------------------------------------------
// A genuine tie is never resolved by rule code.
// ---------------------------------------------------------------------------

$tie = RuleSet::of(
    $dp,
    rule('AAA-TIE', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 10])],
        scope: ['branch' => ['HYD']]),
    rule('ZZZ-TIE', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 20])],
        scope: ['branch' => ['HYD']]),
);

try {
    $engine->evaluate($dp, $tie, ctx(dimensions: $dimensions));
    ok(false, 'expected a precedence tie to fail rather than sort by code');
} catch (RuleEvaluationFailed $e) {
    same('ERR_PRECEDENCE_TIE', $e->errorCode, 'a runtime precedence tie is a named failure');
    ok(str_contains($e->getMessage(), 'AAA-TIE'), 'the failure names the first rule');
    ok(str_contains($e->getMessage(), 'ZZZ-TIE'), 'the failure names the second rule');
    ok(str_contains($e->getMessage(), 'coin-toss'), 'and says why it is not settled by row order');
    ok($e->trace->failed(), 'and it carries a trace with status failed (R-VII-036)');
}

// ---------------------------------------------------------------------------
// Effective dating decides before precedence does (§8.2 Example 8).
// A rule published in October cannot reach back into August.
// ---------------------------------------------------------------------------

$dated = RuleSet::of(
    $dp,
    rule('LATE-HYD-V2', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 10])],
        scope: ['branch' => ['HYD']], from: '2026-04-01', to: '2026-09-30', versionNo: 2),
    rule('LATE-HYD-V3', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 15])],
        scope: ['branch' => ['HYD']], from: '2026-10-01', versionNo: 3),
);

$august = $engine->evaluate($dp, $dated, ctx(dimensions: $dimensions, date: '2026-08-15'));
same('LATE-HYD-V2', $august->actions[0]->emittedBy['rule_code'], 'an August day resolves the version in force in August');
same(10, $august->actions[0]->parameters['minutes'], 'and gets its grace, not Octobers');
same(
    CandidateTrace::NOT_IN_FORCE,
    $august->trace->outcomeFor('LATE-HYD-V3'),
    'the October version is not in force and does not participate in precedence at all',
);

$october = $engine->evaluate($dp, $dated, ctx(dimensions: $dimensions, date: '2026-10-15'));
same('LATE-HYD-V3', $october->actions[0]->emittedBy['rule_code'], 'an October day resolves the October version');

// The August answer is stable when the same evaluation is re-run later — the
// property SmartPRS 2 lacks completely.
same(
    $august->trace->deterministicJson(),
    $engine->evaluate($dp, $dated, ctx(dimensions: $dimensions, date: '2026-08-15'))->trace->deterministicJson(),
    'a re-run of the August evaluation is byte-identical (R-VII-038)',
);

// Draft rules are not evaluated.
$withDraft = $dated->with(
    rule('LATE-HYD-V4', $dp, always($dp), [ruleAction('SET_GRACE', ['minutes' => 99])],
        scope: ['branch' => ['HYD'], 'grade' => ['G3']], from: '2026-04-01', status: 'draft', versionNo: 4),
);
$result = $engine->evaluate($dp, $withDraft->published(), ctx(dimensions: $dimensions + ['grade' => 'G3'], date: '2026-08-15'));
same('LATE-HYD-V2', $result->actions[0]->emittedBy['rule_code'], 'a draft rule does not govern a real evaluation');

// ---------------------------------------------------------------------------
// first_match vs all_match vs highest_priority_only (§7.3).
// ---------------------------------------------------------------------------

$penalise = 'attendance.day.penalise';

$makeSet = static fn (ConflictPolicy $policy): RuleSet => RuleSet::of(
    $penalise,
    rule('PEN-DEPT', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'department rule'])],
        scope: ['department' => ['OPS']], onConflict: $policy),
    rule('PEN-BRANCH', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 2, 'reason' => 'branch rule'])],
        scope: ['branch' => ['HYD']], onConflict: $policy),
    rule('PEN-TENANT', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 3, 'reason' => 'tenant rule'])],
        onConflict: $policy),
);

$all = $engine->evaluate($penalise, $makeSet(ConflictPolicy::AllMatch), ctx(dimensions: $dimensions));
same(3, count($all->actions), 'all_match: every matching rule contributes');
same(
    ['PEN-DEPT', 'PEN-BRANCH', 'PEN-TENANT'],
    array_map(static fn ($a): string => $a->emittedBy['rule_code'], $all->actions),
    'and the actions come back in candidate order, most specific first (§7.4)',
);
same([1, 2, 3], array_map(static fn ($a): int => $a->sequence, $all->actions), 'sequences are 1..n and stable');

$hpo = $engine->evaluate($penalise, $makeSet(ConflictPolicy::HighestPriorityOnly), ctx(dimensions: $dimensions));
same(1, count($hpo->actions), 'highest_priority_only: only the winner contributes');
same('PEN-DEPT', $hpo->actions[0]->emittedBy['rule_code'], 'and the winner is the most specific rule');
same(
    CandidateTrace::NOT_EVALUATED_OUTRANKED,
    $hpo->trace->outcomeFor('PEN-BRANCH'),
    'the alternatives are still recorded as outranked, not omitted',
);
same(
    CandidateTrace::NOT_EVALUATED_OUTRANKED,
    $hpo->trace->outcomeFor('PEN-TENANT'),
    'including the tenant default',
);

$first = $engine->evaluate($penalise, $makeSet(ConflictPolicy::FirstMatch), ctx(dimensions: $dimensions));
same(1, count($first->actions), 'first_match: one result');
same('PEN-DEPT', $first->actions[0]->emittedBy['rule_code'], 'and it is the first candidate that matched');
same(
    CandidateTrace::NOT_EVALUATED_STOPPED,
    $first->trace->outcomeFor('PEN-BRANCH'),
    'first_match stops rather than outranking — a different sentence in the trace',
);

// stop_on_match is legal only under all_match, and it is visible in the trace.
$stopping = RuleSet::of(
    $penalise,
    rule('PEN-STOP', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'stops here'])],
        scope: ['department' => ['OPS']], onConflict: ConflictPolicy::AllMatch, stopOnMatch: true),
    rule('PEN-AFTER', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 2, 'reason' => 'never runs'])],
        onConflict: ConflictPolicy::AllMatch),
);
$stopped = $engine->evaluate($penalise, $stopping, ctx(dimensions: $dimensions));
same(1, count($stopped->actions), 'stop_on_match ends evaluation for this decision point');
ok(
    str_contains((string) $stopped->trace->reasonFor('PEN-AFTER'), 'declared stop_on_match'),
    'and the trace says which rule stopped it',
);

throws(
    InvalidArgumentException::class,
    static fn () => rule('PEN-BAD', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        onConflict: ConflictPolicy::HighestPriorityOnly, stopOnMatch: true),
    'stop_on_match outside all_match is rejected at construction (§4.1)',
);

// ---------------------------------------------------------------------------
// The `except` clause (§7.5).
// ---------------------------------------------------------------------------

$isHoliday = expr('day.is_holiday OR day.is_weekly_off', $penalise);

$carveOut = RuleSet::of(
    $penalise,
    rule('LATE-PEN', $penalise, expr('attendance.late_minutes > 10m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'late'])],
        except: $isHoliday, onConflict: ConflictPolicy::AllMatch),
);

$workday = $engine->evaluate($penalise, $carveOut, ctx([
    'attendance.late_minutes' => 23, 'day.is_holiday' => false, 'day.is_weekly_off' => false,
]));
same(1, count($workday->actions), 'the rule applies on an ordinary working day');
same(CandidateTrace::MATCHED, $workday->trace->outcomeFor('LATE-PEN'), 'and is recorded as matched');

$holiday = $engine->evaluate($penalise, $carveOut, ctx([
    'attendance.late_minutes' => 23, 'day.is_holiday' => true, 'day.is_weekly_off' => false,
]));
same(0, count($holiday->actions), 'the except clause suppresses the match on a holiday');
same(
    CandidateTrace::MATCHED_THEN_EXCEPTED,
    $holiday->trace->outcomeFor('LATE-PEN'),
    'and the trace says "matched then excepted", not "did not apply" (§7.5)',
);

$notLate = $engine->evaluate($penalise, $carveOut, ctx([
    'attendance.late_minutes' => 5, 'day.is_holiday' => true, 'day.is_weekly_off' => false,
]));
same(
    CandidateTrace::NOT_MATCHED,
    $notLate->trace->outcomeFor('LATE-PEN'),
    'except is evaluated only when when is true — a false when is simply not matched',
);

// An `unknown` except does not suppress the match: it is not a carve-out unless
// it is definitely true.
$unknownExcept = RuleSet::of(
    $penalise,
    rule('LATE-UNK', $penalise, expr('attendance.late_minutes > 10m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'late'])],
        except: expr("policy.value('absence_hold_for_review')", $penalise),
        onConflict: ConflictPolicy::AllMatch),
);
$result = $engine->evaluate($penalise, $unknownExcept, ctx(['attendance.late_minutes' => 23]));
same(1, count($result->actions), 'an unknown except does not suppress the match');
same(CandidateTrace::MATCHED, $result->trace->outcomeFor('LATE-UNK'), 'and the rule is matched');

// ---------------------------------------------------------------------------
// The engine mutates nothing (R-VII-028).
// ---------------------------------------------------------------------------

$context = ctx(
    ['attendance.late_minutes' => 23, 'day.is_holiday' => false, 'day.is_weekly_off' => false],
    $dimensions,
);
$before = $context->digest();
$beforeValues = $context->values;
$beforeDimensions = $context->dimensions;

$engine->evaluate($penalise, $carveOut, $context);
$engine->evaluate($penalise, $carveOut, $context);

same($before, $context->digest(), 'the prepared context is unchanged after two evaluations');
same($beforeValues, $context->values, 'no value was written back');
same($beforeDimensions, $context->dimensions, 'no dimension was written back');
ok(
    (new ReflectionClass(EvaluationContext::class))->isReadOnly(),
    'and it could not have been: the context is a readonly type',
);
ok(
    (new ReflectionClass(Engine::class))->isReadOnly(),
    'the engine itself is readonly and holds no repository, so there is nothing else to mutate',
);

// ---------------------------------------------------------------------------
// An evaluation error surfaces; it never defaults (R-VII-041, R-VII-042).
// ---------------------------------------------------------------------------

$poisoned = RuleSet::of(
    $penalise,
    rule('LATE-DIVZERO', $penalise,
        expr('attendance.late_minutes / 0 > 1m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'never emitted'])],
        onConflict: ConflictPolicy::AllMatch),
    rule('LATE-FALLBACK', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 2, 'reason' => 'must not become the answer'])],
        onConflict: ConflictPolicy::AllMatch),
);

try {
    $engine->evaluate($penalise, $poisoned, ctx(['attendance.late_minutes' => 23]));
    ok(false, 'expected the error to surface');
} catch (RuleEvaluationFailed $e) {
    same('ERR_DIV_ZERO', $e->errorCode, 'the SPX error code surfaces unchanged');
    same('LATE-DIVZERO', $e->ruleCode, 'the failing rule is named');
    same(DecisionTrace::FAILED, $e->trace->status, 'and the trace status is failed');
    ok(str_contains($e->getMessage(), 'not given a default outcome'), 'and no default outcome is offered');
    same([], $e->trace->actions, 'no action escapes a failed evaluation');
}

// A `when` that errors is NOT read as false: the fall-back rule never runs.
$simulator = new SmartPRS\Platform\Rules\Simulator($engine);
$shown = $simulator->run($penalise, $poisoned, ctx(['attendance.late_minutes' => 23]));
ok($shown->failed(), 'the simulator surfaces the failure rather than surviving it');
same([], $shown->actions, 'and returns no actions');

// An action parameter that errors fails the evaluation too — never a half-built action.
$badParameter = RuleSet::of(
    $penalise,
    rule('LATE-BADPARAM', $penalise, always($penalise), [ruleAction('APPLY_DAY_PENALTY', [
        // Below the first band: BANDS refuses to guess, so ERR_ARG (§5.6).
        'fraction' => expr('BANDS(attendance.late_minutes, [1000m], [1])', $penalise, boolean: false),
        'reason' => 'x',
    ])], onConflict: ConflictPolicy::AllMatch),
);
throws(
    RuleEvaluationFailed::class,
    static fn () => $engine->evaluate($penalise, $badParameter, ctx(['attendance.late_minutes' => 0])),
    'an action parameter error fails the evaluation (§5.8)',
);

// ---------------------------------------------------------------------------
// The trace names why each losing rule lost.
// ---------------------------------------------------------------------------

$mixed = RuleSet::of(
    $penalise,
    rule('L-WINNER', $penalise, expr('attendance.late_minutes > 10m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'won'])],
        scope: ['department' => ['OPS']], onConflict: ConflictPolicy::HighestPriorityOnly),
    rule('L-CONDITION-FALSE', $penalise, expr('attendance.late_minutes > 500m', $penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        scope: ['department' => ['OPS'], 'branch' => ['HYD']], onConflict: ConflictPolicy::HighestPriorityOnly),
    rule('L-OUT-OF-SCOPE', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        scope: ['branch' => ['CHN']], onConflict: ConflictPolicy::HighestPriorityOnly),
    rule('L-NOT-IN-FORCE', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        scope: ['employee' => ['E-4471']], from: '2026-10-01', onConflict: ConflictPolicy::HighestPriorityOnly),
    rule('L-LOST-SPECIFICITY', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        onConflict: ConflictPolicy::HighestPriorityOnly),
    rule('L-LOST-PRIORITY', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        scope: ['department' => ['OPS']], priority: -10, onConflict: ConflictPolicy::HighestPriorityOnly),
);

$trace = $engine->evaluate($penalise, $mixed, ctx(
    ['attendance.late_minutes' => 23],
    $dimensions,
))->trace;

same('L-WINNER', $trace->matched()[0]->ruleCode, 'the winner is named');
same(5, count($trace->losers()), 'and every other rule is accounted for');

same(CandidateTrace::NOT_MATCHED, $trace->outcomeFor('L-CONDITION-FALSE'), 'condition false is a named outcome');
ok(
    str_contains((string) $trace->reasonFor('L-CONDITION-FALSE'), 'attendance.late_minutes = 23'),
    'with the substituted value in the reason',
);

same(CandidateTrace::NOT_IN_SCOPE, $trace->outcomeFor('L-OUT-OF-SCOPE'), 'out of scope is a named outcome');
ok(
    str_contains((string) $trace->reasonFor('L-OUT-OF-SCOPE'), 'branch = HYD, not in [CHN]'),
    'naming the dimension that kept the subject out',
);

same(CandidateTrace::NOT_IN_FORCE, $trace->outcomeFor('L-NOT-IN-FORCE'), 'not in force is a named outcome');
ok(
    str_contains((string) $trace->reasonFor('L-NOT-IN-FORCE'), 'evaluated on 2026-08-12'),
    'naming the date it was evaluated on',
);

same(
    CandidateTrace::NOT_EVALUATED_OUTRANKED,
    $trace->outcomeFor('L-LOST-SPECIFICITY'),
    'outranked on specificity is a named outcome',
);
ok(
    str_contains((string) $trace->reasonFor('L-LOST-SPECIFICITY'), 'lost on specificity (0 < 400)'),
    'with the specificity arithmetic and the winner named',
);
ok(
    str_contains((string) $trace->reasonFor('L-LOST-PRIORITY'), 'lost on priority (-10 < 0 at specificity 400)'),
    'and a priority loss is a different sentence from a specificity loss',
);

$rendered = $trace->render();
ok(str_contains($rendered, 'How was this calculated?'), 'the trace renders the S15 explain view');
ok(str_contains($rendered, 'L-LOST-PRIORITY'), 'listing the losers');
ok(str_contains($rendered, 'APPLY_DAY_PENALTY'), 'and the actions produced');

$json = json_decode($trace->toJson(), true, flags: JSON_THROW_ON_ERROR);
$matchedJson = array_values(array_filter(
    $json['candidates'],
    static fn (array $c): bool => $c['outcome'] === 'matched',
));
// Candidates are listed in evaluation order, so the more specific
// L-CONDITION-FALSE (department 400 + branch 180 = 580) is evaluated — and
// recorded — before the winner it did not outrank on its condition.
same('L-CONDITION-FALSE', $json['candidates'][0]['rule_code'], 'the JSON lists candidates in evaluation order');
same(1, count($matchedJson), 'exactly one candidate matched');
same('L-WINNER', $matchedJson[0]['rule_code'], 'and it is the winner');
ok(isset($matchedJson[0]['checksum']), 'and the rule version checksum is pinned into the trace');
same(1, $json['counters']['rules_matched'], 'the counters are in the JSON');
ok(isset($json['input_digest']), 'and the input digest that makes the trace reproducible');

// ---------------------------------------------------------------------------
// Employment boundaries are guards, not rules (§7.6).
// ---------------------------------------------------------------------------

$always = RuleSet::of($penalise,
    rule('PEN-ALWAYS', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x'])],
        onConflict: ConflictPolicy::AllMatch));

$beforeJoining = $engine->evaluate($penalise, $always, ctx(date: '2026-08-12', joining: '2026-09-01'));
same(0, count($beforeJoining->actions), 'no rule runs before the joining date');
same('GUARD_NOT_YET_JOINED', $beforeJoining->trace->error['code'], 'and the guard is named in the trace');

$afterExit = $engine->evaluate($penalise, $always, ctx(date: '2026-08-12', exit: '2026-07-31'));
same(0, count($afterExit->actions), 'no rule runs after the exit date');
same('GUARD_EXITED', $afterExit->trace->error['code'], 'and that guard is named too');
ok(
    !$afterExit->failed(),
    'a guard is a no_match with a reason, not a failure',
);

// ---------------------------------------------------------------------------
// Runtime action conflict under all_match (R-VII-037).
// ---------------------------------------------------------------------------

// A singleton action can only contend when its decision point admits more than
// one outcome, so the scenario is built at an `all_match` point. SET_BASIS at
// payroll.component.eligibility is one; see the structural assertion below for
// the ones that cannot contend at all.
$eligibility = 'payroll.component.eligibility';
$contending = RuleSet::of(
    $eligibility,
    rule('CMP-A', $eligibility, always($eligibility),
        [ruleAction('SET_BASIS', ['component' => 'HRA', 'basis' => 'BASIC'])],
        scope: ['department' => ['OPS']], module: 'payroll', onConflict: ConflictPolicy::AllMatch),
    rule('CMP-B', $eligibility, always($eligibility),
        [ruleAction('SET_BASIS', ['component' => 'HRA', 'basis' => 'GROSS'])],
        scope: ['branch' => ['HYD']], module: 'payroll', onConflict: ConflictPolicy::AllMatch),
);

try {
    $engine->evaluate($eligibility, $contending, ctx(dimensions: $dimensions));
    ok(false, 'expected two different SET_BASIS values to be a conflict');
} catch (RuleEvaluationFailed $e) {
    same('ERR_ACTION_CONFLICT', $e->errorCode, 'two different SET_BASIS values is a runtime conflict');
    ok(str_contains($e->getMessage(), 'BASIC'), 'naming the first value');
    ok(str_contains($e->getMessage(), 'GROSS'), 'and the second');
    ok(str_contains($e->getMessage(), 'does not pick one'), 'the engine does not pick one');
}

// The same contention at attendance.day.classify is now IMPOSSIBLE, and not
// because the rules were written carefully. §3.2 declares that point
// `highest_priority_only`, §4.1 lets a rule narrow that but never widen it, and
// the engine takes the narrower of the two — so a second SET_STATUS is outranked
// before it is evaluated. Three of the eight singleton actions are protected
// this way; the other five, like SET_BASIS above, are not, and rely on the
// runtime conflict detector. Asserting which is which is what keeps that
// sentence true after somebody edits the catalogue.
$classify = 'attendance.day.classify';
$widening = RuleSet::of(
    $classify,
    rule('CLS-A', $classify, always($classify), [ruleAction('SET_STATUS', ['code' => 'HALF_DAY'])],
        scope: ['department' => ['OPS']], onConflict: ConflictPolicy::AllMatch),
    rule('CLS-B', $classify, always($classify), [ruleAction('SET_STATUS', ['code' => 'ABSENT'])],
        scope: ['branch' => ['HYD']], onConflict: ConflictPolicy::AllMatch),
);

$outranked = $engine->evaluate($classify, $widening, ctx(dimensions: $dimensions));
same(1, count($outranked->actions), 'a rule cannot widen its decision point by declaring all_match');
same(
    'SET_STATUS(code = HALF_DAY)',
    $outranked->rendered()[0],
    'the higher-specificity rule wins, exactly as highest_priority_only says',
);
ok(
    str_contains($outranked->trace->render(), 'outranked'),
    'and the alternative is still in the trace, named as outranked rather than dropped',
);

// GATE_PAYMENT has a declared dominance order, so block beats warn without error.
$gate = 'compliance.payment_gate';
$gates = RuleSet::of(
    $gate,
    rule('GATE-WARN', $gate, always($gate), [ruleAction('GATE_PAYMENT', [
        'mode' => 'warn', 'reason' => 'soft', 'code' => 'X', 'event' => 'commission.approve',
    ])], scope: ['department' => ['OPS']], module: 'compliance',
        onConflict: ConflictPolicy::AllMatch, severity: 'high_risk'),
    rule('GATE-BLOCK', $gate, always($gate), [ruleAction('GATE_PAYMENT', [
        'mode' => 'block', 'reason' => 'hard', 'code' => 'Y', 'event' => 'commission.approve',
    ])], scope: ['branch' => ['HYD']], module: 'compliance',
        onConflict: ConflictPolicy::AllMatch, severity: 'high_risk'),
);
$result = $engine->evaluate($gate, $gates, ctx(dimensions: $dimensions));
same(1, count($result->actions), 'block dominates warn: one gate survives');
same('block', $result->actions[0]->parameters['mode'], 'and it is the block');

// ---------------------------------------------------------------------------
// Fingerprints (§6.3): resolved parameters, so a changed amount is a new effect.
// ---------------------------------------------------------------------------

$a = $engine->evaluate($penalise, $always, ctx())->actions[0];
$b = $engine->evaluate($penalise, $always, ctx())->actions[0];
same($a->fingerprint, $b->fingerprint, 're-running a derivation produces the same fingerprint (R-VII-032)');

$different = RuleSet::of($penalise,
    rule('PEN-ALWAYS', $penalise, always($penalise),
        [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 2, 'reason' => 'x'])],
        onConflict: ConflictPolicy::AllMatch));
ok(
    $a->fingerprint !== $engine->evaluate($penalise, $different, ctx())->actions[0]->fingerprint,
    'a changed amount is correctly a different effect, not a repeat',
);
ok(
    $a->fingerprint !== $engine->evaluate($penalise, $always, ctx(date: '2026-08-13'))->actions[0]->fingerprint,
    'and so is a different subject-day',
);

// ---------------------------------------------------------------------------
// Resolver ordering is total and deterministic (§7.4).
// ---------------------------------------------------------------------------

$ordered = Resolver::candidates(
    RuleSet::of($penalise,
        rule('ZED', $penalise, always($penalise), [ruleAction('FLAG_FOR_REVIEW', ['reason' => 'z', 'severity' => 'low'])],
            scope: ['branch' => ['HYD']]),
        rule('ALPHA', $penalise, always($penalise), [ruleAction('FLAG_FOR_REVIEW', ['reason' => 'a', 'severity' => 'low'])],
            scope: ['branch' => ['HYD']]),
        rule('MID', $penalise, always($penalise), [ruleAction('FLAG_FOR_REVIEW', ['reason' => 'm', 'severity' => 'low'])],
            scope: ['department' => ['OPS']]),
    ),
    $dimensions,
    new DateTimeImmutable('2026-08-12'),
);
same(
    ['MID', 'ALPHA', 'ZED'],
    array_map(static fn ($r): string => $r->code, $ordered),
    'specificity DESC, then priority DESC, then code ASC — byte-reproducible ordering',
);

// Scope weights come from Support\Scope; the engine does not carry a second copy.
same(180, Scope::of(['branch' => ['HYD']])->specificity(), 'branch weighs 180, per spine S3.2');
same(
    520,
    Scope::of(['branch' => ['HYD'], 'grade' => ['G3']])->specificity(),
    'and specificity is the sum of the constrained dimensions',
);

// A rule set holds one decision point.
throws(
    InvalidArgumentException::class,
    static fn () => RuleSet::of($penalise,
        rule('WRONG-DP', 'attendance.grace', always('attendance.grace'), [ruleAction('SET_GRACE', ['minutes' => 1])])),
    'a rule set is per decision point, because the decision point fixes the symbol table',
);
