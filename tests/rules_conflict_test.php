<?php

declare(strict_types=1);

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Rules\Claim;
use SmartPRS\Platform\Rules\ConflictDetector;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\Satisfiability;
use SmartPRS\Platform\Support\AmbiguousConfiguration;

require_once __DIR__ . '/lib/rules_fixtures.php';

group('Rules\ConflictDetector');

$detector = new ConflictDetector();
$dp = 'attendance.day.penalise';

$penalty = static fn (string $reason): array => [ruleAction('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => $reason])];

// ---------------------------------------------------------------------------
// §8.2 Example 5 — a genuine tie blocks publication, naming both rules.
// ---------------------------------------------------------------------------

$a = rule('LATE-OPS-A', $dp,
    expr('attendance.late_minutes > 30m', $dp),
    $penalty('A'), scope: ['department' => ['OPS']], status: 'draft');

$b = rule('LATE-OPS-B', $dp,
    expr('attendance.late_minutes >= 20m', $dp),
    $penalty('B'), scope: ['department' => ['OPS']], versionNo: 3);

try {
    $detector->assertPublishable([$a, $b]);
    ok(false, 'expected the tie to block publication');
} catch (AmbiguousConfiguration $e) {
    ok(str_contains($e->getMessage(), 'LATE-OPS-A'), 'the block names the draft being published');
    ok(str_contains($e->getMessage(), 'LATE-OPS-B'), 'and the published rule it ties with');
    ok(str_contains($e->getMessage(), 'spec 400, priority 0'), 'showing the specificity and priority that tie');
    ok(str_contains($e->getMessage(), 'department = OPS'), 'and the scope they share');
    ok(str_contains($e->getMessage(), 'Witness: attendance.late_minutes = 31'),
        'with a witness that satisfies both conditions (§8.2 Example 5)');
    ok(str_contains($e->getMessage(), 'blocked'), 'and says publication is blocked');
}

// Giving one of them a different priority resolves it — a one-click decision.
$resolved = rule('LATE-OPS-A', $dp,
    expr('attendance.late_minutes > 30m', $dp),
    $penalty('A'), scope: ['department' => ['OPS']], priority: 10, status: 'draft');
$detector->assertPublishable([$resolved, $b]);
ok(true, 'a differing priority resolves the tie and publication is allowed (§8.2 Example 4)');

// ---------------------------------------------------------------------------
// §8.2 Example 6 — a tie that is NOT a tie, because the bands are disjoint.
// The validator must not obstruct the normal shape of a banded policy.
// ---------------------------------------------------------------------------

$bandHalf = rule('LATE-BAND-HALF', $dp,
    expr('attendance.late_minutes > 120m AND attendance.late_minutes <= 240m', $dp),
    $penalty('half'), scope: ['branch' => ['HYD']]);

$bandAbsent = rule('LATE-BAND-ABSENT', $dp,
    expr('attendance.late_minutes > 240m', $dp),
    $penalty('absent'), scope: ['branch' => ['HYD']]);

$detector->assertPublishable([$bandHalf, $bandAbsent]);
same([], $detector->findConflicts([$bandHalf, $bandAbsent]),
    'provably disjoint bands at equal specificity and priority are recorded as disjoint siblings, not a conflict');

// One more band, overlapping this time, is caught.
$bandOverlap = rule('LATE-BAND-OOPS', $dp,
    expr('attendance.late_minutes > 200m', $dp),
    $penalty('oops'), scope: ['branch' => ['HYD']], status: 'draft');
throws(AmbiguousConfiguration::class,
    static fn () => $detector->assertPublishable([$bandHalf, $bandAbsent, $bandOverlap]),
    'a third band overlapping an existing one is blocked');

// ---------------------------------------------------------------------------
// Fail safe on an undecidable pair (R-VII-060).
// ---------------------------------------------------------------------------

// RL-ATT-001's condition contains agg.count, which the interval solver does not
// model. claims() answers null and the detector must treat the pair as
// conflicting rather than assume disjointness it cannot prove.
$undecidable = rule('LATE-AGG', $dp,
    expr('agg.count(late_arrival, payroll_period, { event.value > 10m }) >= 3', $dp),
    $penalty('agg'), scope: ['department' => ['OPS']], status: 'draft');

$disjointLooking = rule('LATE-NEVER', $dp,
    expr('attendance.late_minutes > 100000m', $dp),
    $penalty('never'), scope: ['department' => ['OPS']]);

$conflicts = $detector->findConflicts([$undecidable, $disjointLooking]);
same(1, count($conflicts), 'an undecidable condition is treated as conflicting');
ok($conflicts[0]['undecidable'], 'and is flagged as undecidable rather than as a proved overlap');
ok(str_contains($conflicts[0]['message'], 'LATE-AGG'), 'naming the rule the solver could not read');
ok(str_contains($conflicts[0]['message'], 'agg.*'), 'and why it could not read it');
ok(str_contains($conflicts[0]['message'], 'R-VII-060'), 'citing the rule that forbids a false negative');
throws(AmbiguousConfiguration::class,
    static fn () => $detector->assertPublishable([$undecidable, $disjointLooking]),
    'and publication is refused');

// Two undecidable rules at DIFFERENT priorities publish fine — the author's
// one-line escape hatch, exactly as §11.4 describes.
$undecidableB = rule('LATE-AGG-2', $dp,
    expr('agg.count(late_arrival, rolling(30, days), { event.value > 0m }) >= 3', $dp),
    $penalty('agg2'), scope: ['department' => ['OPS']], priority: 5);
$detector->assertPublishable([$undecidable, $undecidableB]);
ok(true, 'two undecidable rules with differing priorities are publishable');

// ---------------------------------------------------------------------------
// Scope intersection is decidable exactly (§11.4 step 2).
// ---------------------------------------------------------------------------

$hyd = rule('SCOPE-HYD', $dp,
    expr('attendance.late_minutes > 10m', $dp),
    $penalty('hyd'), scope: ['branch' => ['HYD']]);
$chn = rule('SCOPE-CHN', $dp,
    expr('attendance.late_minutes > 20m', $dp),
    $penalty('chn'), scope: ['branch' => ['CHN']], status: 'draft');

same([], $detector->findConflicts([$hyd, $chn]),
    'two rules whose scopes cannot admit a common employee never conflict, whatever their conditions');

$hydChn = rule('SCOPE-BOTH', $dp,
    expr('attendance.late_minutes > 20m', $dp),
    $penalty('both'), scope: ['branch' => ['HYD', 'CHN']], status: 'draft');
same(1, count($detector->findConflicts([$hyd, $hydChn])),
    'overlapping value sets on one dimension do admit a common employee, so the conditions are tested');

// A superseded version does not participate.
$superseded = rule('SCOPE-HYD-OLD', $dp,
    expr('attendance.late_minutes > 10m', $dp),
    $penalty('old'), scope: ['branch' => ['HYD']], status: 'superseded');
same([], $detector->findConflicts([$hyd, $superseded]),
    'a superseded version is not a candidate and cannot tie with its successor');

// ---------------------------------------------------------------------------
// The solver itself.
// ---------------------------------------------------------------------------

group('Rules\Satisfiability');

same(['x' => 31], Satisfiability::witness([new Claim('x', '>', 30), new Claim('x', '>=', 20)]),
    'the witness is the tight bound: 31 satisfies both > 30 and >= 20');
same(null, Satisfiability::witness([new Claim('x', '>', 240), new Claim('x', '<=', 240)]),
    'disjoint numeric intervals are proved unsatisfiable');
same(null, Satisfiability::witness([...Claim::between('x', 121, 240), new Claim('x', '>', 240)]),
    'the banded case is proved disjoint (§8.2 Example 6)');
same(['x' => 241], Satisfiability::witness([new Claim('x', '>', 240), new Claim('x', '<=', 300)]),
    'and an overlapping band is satisfiable with a witness');
same(null, Satisfiability::witness([new Claim('t', 'in', ['EL']), new Claim('t', 'in', ['SL'])]),
    'disjoint code sets are proved unsatisfiable');
same(['t' => 'EL'], Satisfiability::witness([new Claim('t', 'in', ['EL', 'CL']), new Claim('t', 'in', ['EL', 'SL'])]),
    'intersecting code sets yield the common member as the witness');
same(null, Satisfiability::witness([new Claim('t', 'in', ['EL']), new Claim('t', 'not_in', ['EL'])]),
    'IN and NOT IN on the same single member is unsatisfiable');
same([], Satisfiability::witness([]), 'two unconditional rules are satisfiable by any subject');
ok(Satisfiability::witness([new Claim('x', '<>', 5)]) !== null,
    'a `<>` on an unbounded integer stays satisfiable — the safe direction');

// ---------------------------------------------------------------------------
// One conflict policy per decision point (§7.3).
// ---------------------------------------------------------------------------

group('Rules\ConflictDetector');

throws(AmbiguousConfiguration::class,
    static fn () => $detector->assertOnePolicyPerDecisionPoint([
        rule('POL-A', $dp, always($dp), $penalty('a'), onConflict: ConflictPolicy::AllMatch),
        rule('POL-B', $dp, always($dp), $penalty('b'),
            scope: ['branch' => ['HYD']], onConflict: ConflictPolicy::HighestPriorityOnly),
    ]),
    'two rules at one decision point declaring different policies is refused, not settled by sort order');

// ---------------------------------------------------------------------------
// The rule model's publish-time validations (§4.1, §8.3).
// ---------------------------------------------------------------------------

group('Rules\Rule');

throws(InvalidArgumentException::class,
    static fn () => rule('bad code', $dp, always($dp), $penalty('x')),
    'a rule code must match the §4.1 pattern');
throws(InvalidArgumentException::class,
    static fn () => rule('NO-DESC', $dp, always($dp), $penalty('x'), description: 'too short'),
    'business_description is mandatory and substantial — it is printed in every trace');
throws(InvalidArgumentException::class,
    static fn () => rule('NO-ACTIONS', $dp, always($dp), []),
    'a rule with no action is rejected');
throws(InvalidArgumentException::class,
    static fn () => rule('TOO-MANY', $dp, always($dp),
        array_fill(0, 21, ruleAction('FLAG_FOR_REVIEW', ['reason' => 'x', 'severity' => 'low']))),
    'more than twenty actions exceeds the §7.7 budget');
throws(SmartPRS\Platform\Spx\SpxSyntaxError::class,
    static fn () => expr('  ', $dp),
    'SPX refuses to parse a blank source, so an empty `when` cannot even be compiled');
throws(InvalidArgumentException::class,
    static fn () => rule('EMPTY-WHEN', $dp, new BlankExpression(), $penalty('x')),
    'and Rule refuses one independently: a rule that always applies is written TRUE (R-VII-007)');
throws(InvalidArgumentException::class,
    static fn () => rule('HIGH-FIRST', $dp, always($dp), $penalty('x'),
        onConflict: ConflictPolicy::FirstMatch, severity: 'high_risk'),
    'first_match on a high_risk rule is blocked — use highest_priority_only so the trace shows the alternatives');
throws(InvalidArgumentException::class,
    static fn () => rule('BAD-MODULE', $dp, always($dp), $penalty('x'), module: 'finance'),
    'the module must be one of the five');

$moneyRule = rule('MONEY-RULE', $dp, always($dp), $penalty('x'));
ok($moneyRule->requiresApproval,
    'a money-affecting action forces maker-checker on publication, without the author remembering to');
ok($moneyRule->isMoneyAffecting(), 'and the rule knows it is money-affecting');

$quiet = rule('QUIET-RULE', $dp, always($dp),
    [ruleAction('FLAG_FOR_REVIEW', ['reason' => 'x', 'severity' => 'low'])]);
ok(!$quiet->requiresApproval, 'a rule with no money-affecting action does not force approval');

same(64, strlen($moneyRule->checksum()), 'a rule version carries a SHA-256 checksum');
ok($moneyRule->checksum() !== $quiet->checksum(), 'and two different rules checksum differently');
same($moneyRule->checksum(), $moneyRule->checksum(), 'the checksum is stable across calls');

// ---------------------------------------------------------------------------
// The action catalogue is closed (R-VII-030, R-VII-031, R-VII-034).
// ---------------------------------------------------------------------------

group('Rules\ActionCatalogue');

throws(InvalidArgumentException::class,
    static fn () => new Action('DELETE_ATTENDANCE', []),
    'there is no DELETE verb, at any severity, for any module (R-VII-030)');
throws(InvalidArgumentException::class,
    static fn () => new Action('GRANT_PERMISSION', ['role' => 'admin']),
    'and none that touches a role, a scope or a permission (R-VII-031)');
throws(InvalidArgumentException::class,
    static fn () => new Action('SEND_EMAIL', ['to' => 'x']),
    'a rule cannot send mail directly — NOTIFY emits a domain event (R-VII-029)');

try {
    new Action('CUSTOM_ACTION', []);
    ok(false, 'expected an unknown action to be refused');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'no plugin path'),
        'the refusal states there is no plugin path, no script hook and no custom-action row');
    ok(str_contains($e->getMessage(), 'six-step'), 'and points at the only procedure that exists');
}

ok(!method_exists(ActionCatalogue::class, 'register'),
    'the catalogue has no register(): the absence of a mutator is the enforcement');
ok(!method_exists(ActionCatalogue::class, 'add'), 'and no add()');

foreach (ActionCatalogue::codes() as $code) {
    ok(!preg_match('/DELETE|PURGE|TRUNCATE|DROP/i', $code), "no destructive verb in the catalogue: {$code}");
}

throws(InvalidArgumentException::class,
    static fn () => new Action('SET_STATUS', []),
    'a missing required parameter is refused at publish time');
throws(InvalidArgumentException::class,
    static fn () => new Action('SET_STATUS', ['code' => 'X', 'nonsense' => 1]),
    'and so is an undeclared one');
new Action('APPLY_DAY_PENALTY', ['fraction' => 1, 'reason' => 'x']);
ok(true, 'an optional parameter may be omitted');

same('ledger', ActionCatalogue::effectClass('CREATE_COMP_OFF'),
    'a comp-off credit is a ledger effect, reversed by a compensating debit');
same('derived', ActionCatalogue::effectClass('SET_STATUS'), 'a status is a derived effect, superseded by re-derivation');
same('terminal', ActionCatalogue::effectClass('WRITE_AUDIT'), 'an audit row is terminal — the ledger is append-only');
same('singleton', ActionCatalogue::conflictClass('SET_STATUS'), 'SET_STATUS contends; two values is a conflict');
same('additive', ActionCatalogue::conflictClass('APPLY_DAY_PENALTY'), 'penalties accumulate and never conflict');
same('dominant', ActionCatalogue::conflictClass('GATE_PAYMENT'), 'a gate has a declared dominance order');
same(['warn', 'block'], ActionCatalogue::dominance('GATE_PAYMENT')['order'], 'and block dominates warn');
ok(ActionCatalogue::isMoneyAffecting('CREATE_OT'), 'overtime is money-affecting');
ok(!ActionCatalogue::isMoneyAffecting('NOTIFY'), 'a notification is not');
ok(str_contains(ActionCatalogue::reversalRule('CREATE_COMP_OFF'), 'compensating debit'),
    'every action declares its reversal in words the domain service can act on');
ok(str_contains(ActionCatalogue::idempotencyRule('NOTIFY'), 'dedupe_key'),
    'and its idempotency rule');
same(28, count(ActionCatalogue::codes()), 'the catalogue is a closed set of a known size');

// ---------------------------------------------------------------------------
// The claim reader (§11.4). `SpxExpression::claims()` walks the type-checked AST
// and returns the conjunction the solver above consumes — or NULL, which is the
// fail-safe answer that makes the detector treat a pair as conflicting.
// ---------------------------------------------------------------------------

group('Rules\ClaimReader');

$claims = static fn (string $source): ?array => expr($source, $dp)->claims();
$read = static fn (string $source): ?array => ($c = expr($source, $dp)->claims()) === null
    ? null
    : array_map(strval(...), $c);

same([], $read('TRUE'), 'TRUE asserts nothing: the empty conjunction, so two TRUE rules genuinely tie');
same(
    ['attendance.late_minutes > 30'],
    $read('attendance.late_minutes > 30m'),
    'a comparison against a literal is one claim, in the literal\'s exact minutes',
);
same(
    ['attendance.late_minutes > 30'],
    $read('30m < attendance.late_minutes'),
    'and the symbol may sit on either side — `30 < x` is read as `x > 30`',
);
same(
    ['attendance.late_minutes > 120', 'attendance.late_minutes <= 240'],
    $read('attendance.late_minutes > 120m AND attendance.late_minutes <= 240m'),
    'AND is the conjunction the fragment is built on',
);
same(
    ['attendance.late_minutes >= 121', 'attendance.late_minutes <= 240'],
    $read('attendance.late_minutes BETWEEN 121m AND 240m'),
    'BETWEEN is inclusive on both bounds and reads as two claims',
);
same(
    ['employee.stage in [confirmed, notice]'],
    $read("employee.stage IN ['confirmed', 'notice']"),
    'IN over a list of literals is a set claim',
);
same(
    ['employee.stage in [probation]'],
    $read("employee.stage = 'probation'"),
    'and equality on a Code is a one-member set',
);
same(
    ['day.is_holiday not_in [TRUE]'],
    $read('day.is_holiday <> TRUE'),
    'equality on a Boolean too (§11.4), so is-holiday and is-not-holiday are disjoint',
);
same(
    ['day.is_weekly_off in [TRUE]'],
    $read('day.is_weekly_off'),
    'a bare symbol in a claims position is Boolean and asserts that it is TRUE',
);

// The whole fragment is what it says it is. Everything else is NULL, and NULL is
// the answer that keeps R-VII-060's asymmetry: never a false negative.
foreach ([
    'day.is_holiday OR day.is_weekly_off' => 'an OR is a disjunction, and the solver models conjunctions',
    'NOT day.is_holiday' => 'a NOT over three-valued logic is not a set complement',
    'agg.count(late_arrival, payroll_period, { event.value > 10m }) >= 3' => 'an agg.* call is not a variable',
    "attendance.late_minutes > policy.value('late_grace_min')" => 'a keyed accessor is resolved at runtime, not here',
    'attendance.late_minutes - 5m > 30m' => 'arithmetic the interval solver does not model',
    "employee.code MATCHES 'E-[0-9]+'" => 'a MATCHES',
    'attendance.late_minutes > 30.5m' => 'a fractional bound leaves the integer-only fragment',
    "attendance.late_minutes > 30m OR employee.stage = 'probation'" => 'an OR anywhere poisons the whole reading',
] as $source => $why) {
    same(null, $claims($source), "outside the decidable fragment: {$why}");
}

// And the two ends meet: the reader's claims are exactly what the solver eats,
// which is what makes §8.2 Example 6 publishable without a `priority` keystroke.
same(
    null,
    Satisfiability::witness([
        ...expr('attendance.late_minutes > 120m AND attendance.late_minutes <= 240m', $dp)->claims(),
        ...expr('attendance.late_minutes > 240m', $dp)->claims(),
    ]),
    'the banded pair is proved disjoint from claims READ OFF THE AST, not hand-written ones',
);
