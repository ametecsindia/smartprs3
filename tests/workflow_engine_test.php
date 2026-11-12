<?php

declare(strict_types=1);

use SmartPRS\Platform\Workflow\Engine;
use SmartPRS\Platform\Workflow\Instance;
use SmartPRS\Platform\Workflow\InstanceState;
use SmartPRS\Platform\Workflow\Delegation;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeStranded;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantResolver;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\ResolutionContext;
use SmartPRS\Platform\Workflow\Task;
use SmartPRS\Platform\Workflow\TaskAlreadyDecided;
use SmartPRS\Platform\Workflow\TaskState;
use SmartPRS\Platform\Workflow\Transition;
use SmartPRS\Platform\Workflow\Validator;
use SmartPRS\Platform\Workflow\ValidationEnvironment;
use SmartPRS\Platform\Workflow\WorkflowDefinition;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

group('Workflow engine (Part IX)');

$now = new DateTimeImmutable('2026-08-15 10:00:00');

require_once __DIR__ . '/lib/workflow_fixtures.php';

// =============================================================================
// THE CLOSED NODE CATALOGUE (§IX.4, R-IX-020)
// =============================================================================

same(13, count(NodeType::cases()), 'the catalogue is the thirteen node types spine S8 declares');

foreach (NodeType::cases() as $type) {
    ok($type->outcomes() !== [] || $type === NodeType::ConditionBranch,
        "{$type->value} declares the outcomes it can produce, so W03 can check they are all handled");
}

foreach ([NodeType::Reject, NodeType::Cancel, NodeType::Finalise] as $terminal) {
    ok($terminal->isTerminal(), "{$terminal->value} is terminal");
}

ok(NodeType::ReturnForEdit->breaksCycles(), 'a return_for_edit makes a loop legal: a human sees it and acts');
ok(NodeType::Escalate->breaksCycles(), 'so does an escalate');
ok(!NodeType::Approval->breaksCycles(), 'an approval does not — an approval loop is an infinite loop');

// =============================================================================
// R-IX-011 — AN UNKNOWN PARAMETER IS AN ERROR, NEVER A SILENT DISCARD
// =============================================================================

throws(
    InvalidArgumentException::class,
    static fn () => wfNode('x', NodeType::Notify, ['params' => ['tmeplate_key' => 'a typo']]),
    'R-IX-011: an unknown params key is refused — this is SmartPRS 2\'s safeRow() dropping nine fields silently',
);

throws(
    InvalidArgumentException::class,
    static fn () => new Node(key: 'x', type: NodeType::Approval, label: '  '),
    'R-IX-012: a node without a label is refused — an unnamed step is an unexplainable step',
);

// W12, held at construction so an unsatisfiable quorum cannot exist as an object.
throws(
    InvalidArgumentException::class,
    static fn () => wfNode('panel', NodeType::Quorum, ['params' => ['n' => 4, 'm' => 3, 'tie_policy' => 'reject']]),
    'W12: a quorum of 4 of 3 is not satisfiable',
);

throws(
    InvalidArgumentException::class,
    static fn () => wfNode('panel', NodeType::Quorum, ['params' => ['n' => 2, 'm' => 3]]),
    'W12: a quorum must declare a tie policy',
);

// W16, likewise: escalation that decides for a human needs a clock to decide by.
throws(
    InvalidArgumentException::class,
    static fn () => wfNode('m', NodeType::Approval, ['escalationPolicy' => 'auto_approve']),
    'W16: an escalation that auto-approves must declare sla_minutes',
);

// =============================================================================
// R-IX-037 — NO CONFIGURATION LETS ONE PERSON COMPLETE MAKER-CHECKER
// =============================================================================

throws(
    InvalidArgumentException::class,
    static fn () => wfDefinition(['isSection56Operation' => true, 'allowsSelfApproval' => true]),
    'R-IX-037: a Section 56 operation may not permit self-approval — this is `admin` satisfying both '
    . 'isHR() and isFinance() today',
);

throws(
    InvalidArgumentException::class,
    static fn () => wfDefinition(['isSection56Operation' => true, 'selfApprovalOverride' => 'permission']),
    'and no override route reaches it either',
);

// =============================================================================
// THE PUBLISH CHECKS (§IX.3.6)
// =============================================================================

$clean = Validator::validate(wfDefinition());
ok($clean->isPublishable(), 'a well-formed definition publishes');
same(18, count($clean->checks), 'and every check is recorded, whatever it said');

// W01
$twoSubmits = wfDefinition(['nodes' => [
    wfNode('raise', NodeType::Submit),
    wfNode('raise_two', NodeType::Submit),
    wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
    wfNode('refuse', NodeType::Reject),
]]);
ok(Validator::validate($twoSubmits)->check('W01')?->blocks(), 'W01 blocks two entry points');

// W02
$orphan = wfDefinition(['nodes' => [
    wfNode('raise', NodeType::Submit),
    wfNode('manager', NodeType::Approval, ['participants' => [new ParticipantSpec('requester')]]),
    wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
    wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
    wfNode('refuse', NodeType::Reject),
    wfNode('forgotten', NodeType::Notify, ['params' => ['template_key' => 't']]),
]]);
$w02 = Validator::validate($orphan)->check('W02');
ok($w02?->blocks(), 'W02 blocks a node nothing reaches');
ok(str_contains(implode(' ', $w02->messages ?? []), 'forgotten'), 'and names the dead configuration');

// W03
$uncovered = wfDefinition(['transitions' => [
    new Transition('raise', 'submitted', 'manager'),
    new Transition('raise', 'blocked', 'refuse'),
    new Transition('manager', 'approved', 'charge'),
]]);
$w03 = Validator::validate($uncovered)->check('W03');
ok($w03?->blocks(), 'W03 blocks an outcome with no declared transition');
ok(str_contains(implode(' ', $w03->messages ?? []), 'rejected'), 'naming the outcome that would strand an instance');

// W04
$dangling = wfDefinition(['transitions' => [
    new Transition('raise', 'submitted', 'nowhere'),
    new Transition('raise', 'blocked', 'refuse'),
]]);
ok(Validator::validate($dangling)->check('W04')?->blocks(), 'W04 blocks a transition to a node that does not exist');

// W05 — the loop that matters
$badLoop = wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        wfNode('first', NodeType::Approval, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('second', NodeType::Approval, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
        wfNode('refuse', NodeType::Reject),
    ],
    'transitions' => [
        new Transition('raise', 'submitted', 'first'),
        new Transition('raise', 'blocked', 'refuse'),
        new Transition('first', 'approved', 'second'),
        new Transition('first', 'rejected', 'refuse'),
        new Transition('first', 'returned', 'second'),
        new Transition('first', 'escalated', 'second'),
        new Transition('first', 'auto_decided', 'second'),
        new Transition('second', 'approved', 'charge'),
        new Transition('second', 'rejected', 'refuse'),
        new Transition('second', 'returned', 'first'),
        new Transition('second', 'escalated', 'first'),
        new Transition('second', 'auto_decided', 'charge'),
    ],
]);
$w05 = Validator::validate($badLoop)->check('W05');
ok($w05?->blocks(), 'W05 blocks a cycle between two approvals with no human-visible return');
ok(str_contains(implode(' ', $w05->messages ?? []), 'infinite loop'), 'and says why');

same('pass', Validator::validate(wfDefinition())->check('W05')?->severity,
    'while the baseline loops through return_for_edit and is legal');

// W13
$terminalWithExit = wfDefinition(['transitions' => [
    ...wfDefinition()->transitions,
    new Transition('charge', 'completed', 'manager'),
]]);
ok(Validator::validate($terminalWithExit)->check('W13')?->blocks(), 'W13 blocks an outgoing edge from a finalise node');

// W14
$noReject = wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
    ],
    'transitions' => [
        new Transition('raise', 'submitted', 'charge'),
        new Transition('raise', 'blocked', 'charge'),
    ],
]);
$w14 = Validator::validate($noReject)->check('W14');
ok($w14?->blocks(), 'W14 blocks a workflow with no path to a reject node');
ok(
    str_contains(implode(' ', $w14->messages ?? []), 'not a control'),
    'because a workflow that cannot be refused is not a control',
);

// W09 — a named user is a warn, not a block: a five-person tenant is a real shape.
$named = wfDefinition(['nodes' => [
    wfNode('raise', NodeType::Submit),
    wfNode('manager', NodeType::Approval, [
        'participants' => [new ParticipantSpec('named_user', ['user_id' => 'u-ejaz'])],
    ]),
    wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
    wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
    wfNode('refuse', NodeType::Reject),
]]);
$w09 = Validator::validate($named, new ValidationEnvironment(userIds: ['u-ejaz']))->check('W09');
same('warn', $w09?->severity, 'W09 warns that a named user is a single point of failure');
ok(!$w09?->blocks(), 'but does not block: a proprietor who is genuinely the only approver is a real tenant');

$w09missing = Validator::validate($named, new ValidationEnvironment(userIds: ['u-someone-else']))->check('W09');
ok($w09missing?->blocks(), 'while a named user who is not active in the tenant blocks');

// The NOT-CHECKED rule, carried over from the formula gates.
$w09blind = Validator::validate($named)->check('W09');
same('warn', $w09blind?->severity, 'W09 with no user registry records NOT CHECKED, never a pass');
ok(str_contains(implode(' ', $w09blind->messages ?? []), 'NOT CHECKED'), 'and says so');

// W10 — the check `admin`-satisfies-both would fail (§IX.1.4)
$segregated = wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        wfNode('hr', NodeType::Approval, ['participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'HR'])]]),
        wfNode('finance', NodeType::Approval, ['participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'FIN'])]]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'x']]),
        wfNode('refuse', NodeType::Reject),
    ],
    'transitions' => [
        new Transition('raise', 'submitted', 'hr'),
        new Transition('raise', 'blocked', 'refuse'),
        new Transition('hr', 'approved', 'finance'),
        new Transition('hr', 'rejected', 'refuse'),
        new Transition('hr', 'returned', 'amend'),
        new Transition('hr', 'escalated', 'finance'),
        new Transition('hr', 'auto_decided', 'finance'),
        new Transition('finance', 'approved', 'charge'),
        new Transition('finance', 'rejected', 'refuse'),
        new Transition('finance', 'returned', 'amend'),
        new Transition('finance', 'escalated', 'charge'),
        new Transition('finance', 'auto_decided', 'charge'),
        new Transition('amend', 'returned', 'amend'),
        new Transition('amend', 'resubmitted', 'hr'),
        new Transition('amend', 'exhausted', 'refuse'),
        new Transition('amend', 'abandoned', 'refuse'),
    ],
    'segregationSets' => [
        ['set_key' => 'maker_checker', 'node_keys' => ['hr', 'finance'], 'rule' => 'distinct_actor'],
    ],
]);

$w10bad = Validator::validate($segregated, new ValidationEnvironment(participantsByNode: [
    'hr' => ['u-admin', 'u-hr'],
    'finance' => ['u-admin', 'u-fin'],
]))->check('W10');
ok($w10bad?->blocks(), 'W10 blocks when one user can decide both halves of a maker-checker control');
ok(str_contains(implode(' ', $w10bad->messages ?? []), 'u-admin'), 'and names the user who can');

$w10ok = Validator::validate($segregated, new ValidationEnvironment(participantsByNode: [
    'hr' => ['u-hr'],
    'finance' => ['u-fin'],
]))->check('W10');
same('pass', $w10ok?->severity, 'and passes when the sets are genuinely disjoint');

$w10blind = Validator::validate($segregated)->check('W10');
same('warn', $w10blind?->severity, 'with no resolved sets it records NOT CHECKED — intersection is the check that matters');

// ---------------------------------------------------------------------------
// W08 — amount bands (§IX.9.1). `from` inclusive, `to` exclusive, NULL
// unbounded, integer minor units. A gap is an amount that routes nowhere.
// ---------------------------------------------------------------------------

/** The definition with one set of bands on the approval node. */
$banded = static fn (array $bands): WorkflowDefinition => wfDefinition([
    'subjectAmountPath' => 'request.amount',
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        wfNode('manager', NodeType::Approval, [
            'participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])],
            'params' => ['amount_bands' => $bands],
        ]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ],
]);

same(
    'pass',
    Validator::validate($banded([
        ['from' => null, 'to' => 5_000_00],
        ['from' => 5_000_00, 'to' => 50_000_00],
        ['from' => 50_000_00, 'to' => null],
    ]))->check('W08')?->severity,
    'W08 passes contiguous bands that cover everything from minus infinity to plus infinity',
);

$gap = Validator::validate($banded([
    ['from' => null, 'to' => 5_000_00],
    ['from' => 10_000_00, 'to' => null],
]))->check('W08');

ok($gap?->blocks(), 'W08 blocks a gap — an amount in it would route nowhere and the instance would strand');
ok(
    str_contains(implode(' ', $gap->messages ?? []), '[500000, 1000000)'),
    'and names the interval nothing routes, in minor units, so the fix is arithmetic rather than a hunt',
);

$overlap = Validator::validate($banded([
    ['from' => null, 'to' => 10_000_00],
    ['from' => 5_000_00, 'to' => null],
]))->check('W08');

ok($overlap?->blocks(), 'and blocks an overlap, where two bands claim the same amount');

$openTop = Validator::validate($banded([
    ['from' => null, 'to' => 5_000_00],
    ['from' => 5_000_00, 'to' => 50_000_00],
]))->check('W08');

ok($openTop?->blocks(), 'a highest band with a bound leaves everything above it unroutable');

$float = Validator::validate($banded([
    ['from' => null, 'to' => 5000.50],
    ['from' => 5000.50, 'to' => null],
]))->check('W08');

ok($float?->blocks(), 'a float threshold is refused outright (R-IX-077): money is integer minor units');

// Bands declared out of order are still judged on what they cover, not on how
// they were typed — an author should be told the truth about their bands
// rather than told to sort them first.
same(
    'pass',
    Validator::validate($banded([
        ['from' => 50_000_00, 'to' => null],
        ['from' => null, 'to' => 5_000_00],
        ['from' => 5_000_00, 'to' => 50_000_00],
    ]))->check('W08')?->severity,
    'and the same three bands declared in any order still pass',
);

same(
    'pass',
    Validator::validate(wfDefinition())->check('W08')?->severity,
    'a definition with no bands PASSES rather than reporting NOT CHECKED — the absence is a property '
    . 'of the definition, not of the checkout, so the check ran and found nothing wrong',
);

// ---------------------------------------------------------------------------
// W15 — the subject entity and every completion event key are registered.
// ---------------------------------------------------------------------------

$registries = new ValidationEnvironment(
    entityKeys: ['leave_request', 'expense_claim'],
    eventKeys: ['leave.charged', 'expense.paid'],
);

same(
    'pass',
    Validator::validate(wfDefinition(), $registries)->check('W15')?->severity,
    'W15 passes when the subject entity and the completion event are both registered',
);

$unregisteredEvent = Validator::validate(
    wfDefinition(['nodes' => [
        wfNode('raise', NodeType::Submit),
        wfNode('manager', NodeType::Approval, ['participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])]]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.chargd']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ]]),
    $registries,
)->check('W15');

ok(
    $unregisteredEvent?->blocks(),
    'and blocks a completion event nothing has registered — a typo there is not a runtime error, it '
    . 'is silence: the outbox row is written and no consumer exists',
);
ok(
    str_contains(implode(' ', $unregisteredEvent->messages ?? []), 'leave.chargd'),
    'naming the key, because one letter is exactly what makes this hard to see',
);

$w15blind = Validator::validate(wfDefinition())->check('W15');
same('warn', $w15blind?->severity, 'with no registry it records NOT CHECKED, never a pass');

// =============================================================================
// §IX.5 — PARTICIPANT RESOLUTION AND THE SELF-APPROVAL PROHIBITION
// =============================================================================

$resolver = new ParticipantResolver(
    static fn (ParticipantSpec $spec, ResolutionContext $c): array => match ($spec->kind) {
        'named_user' => [(string) $spec->params['user_id']],
        'role_in_scope' => (array) ($spec->params['holders'] ?? []),
        'requester' => [$c->requesterUserId],
        default => [],
    },
);

$approval = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-ejaz', 'u-mgr']])],
]);

// R-IX-036 identity 1: the initiating user.
$asRequester = new ResolutionContext($now, requesterUserId: 'u-ejaz');
$result = $resolver->resolve($approval, $asRequester);

same(['u-mgr'], $result->assignees, 'R-IX-036: the requester is dropped from their own approval');
ok(str_contains($result->render(), 'is the requester'), 'and the trace says why they were dropped');

// R-IX-036 identity 2: the subject employee, even when someone else raised it.
$aboutEjaz = new ResolutionContext($now, requesterUserId: 'u-hr', subjectEmployeeUserId: 'u-ejaz');
$result = $resolver->resolve($approval, $aboutEjaz);

same(['u-mgr'], $result->assignees, 'an employee does not approve their own change even when HR raised it');
ok(
    str_contains($result->render(), 'even when somebody else raised it'),
    'and the trace names that identity specifically',
);

// R-IX-036 identity 3: a delegate of either.
$delegated = new ResolutionContext($now, requesterUserId: 'u-ejaz', delegations: [
    new Delegation('u-ejaz', 'u-mgr', effectiveTo: $now->modify('+7 days')),
]);
$result = $resolver->resolve($approval, $delegated);

ok($result->isEmpty(), 'a user holding a delegation FROM the requester is dropped too');
same('fail_instance', $result->noParticipantPolicy, 'and the empty set applies on_no_participant, never a silent pass');

// =============================================================================
// WC-09 — DELEGATION: ACTIVE, EXPIRED, CHAINED AND CIRCULAR (§IX.5.5)
// =============================================================================

// The same delegation, expired. Until the WC-09 work this state could not be
// expressed at all: the resolver took array<from, to>, which has no dates, so a
// delegation raised for a fortnight's leave routed approvals for ever.
$expired = new ResolutionContext($now, requesterUserId: 'u-ejaz', delegations: [
    new Delegation('u-ejaz', 'u-mgr', effectiveTo: $now->modify('-1 day')),
]);
$result = $resolver->resolve($approval, $expired);

same(['u-mgr'], $result->assignees, 'an EXPIRED delegation from the requester does not taint the former delegate');

// The boundary itself: `effectiveTo` is inclusive, so the last day still routes.
ok(
    (new Delegation('a', 'b', effectiveTo: $now))->activeOn($now),
    'a delegation "until the 14th" covers the 14th — the last day of leave is a day of leave',
);
ok(
    !(new Delegation('a', 'b', effectiveFrom: $now->modify('+1 day')))->activeOn($now),
    'and one that starts tomorrow does not route today',
);

// Chained: A delegates to B, who is out of office with C as backup. One hop and
// stop — what the map did — assigns the task to somebody already known to be away.
$chainNode = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-a']])],
]);
$result = $resolver->resolve($chainNode, new ResolutionContext($now, requesterUserId: 'u-hr', delegations: [
    new Delegation('u-a', 'u-b'),
    new Delegation('u-b', 'u-c', kind: Delegation::BACKUP),
]));

same(['u-c'], $result->assignees, 'the chain is followed to whoever actually holds the work');
ok(str_contains($result->render(), 'out-of-office backup'), 'and the trace names each hop and what kind it was');

// Circular: A → B → A. There is no terminal delegate, and taking the first hop
// hands the task to the person the delegation existed to route around.
$result = $resolver->resolve($chainNode, new ResolutionContext($now, requesterUserId: 'u-hr', delegations: [
    new Delegation('u-a', 'u-b'),
    new Delegation('u-b', 'u-a'),
]));

ok($result->isEmpty(), 'a circular delegation removes that candidate rather than resolving the cycle arbitrarily');
ok(str_contains($result->render(), 'Circular delegation'), 'and says so, naming the loop');

// But only that candidate: a node with another eligible approver still routes.
$twoApprovers = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-a', 'u-d']])],
]);
$result = $resolver->resolve($twoApprovers, new ResolutionContext($now, requesterUserId: 'u-hr', delegations: [
    new Delegation('u-a', 'u-b'),
    new Delegation('u-b', 'u-a'),
]));

same(['u-d'], $result->assignees, "one user's broken delegation does not block a node with another approver");

throws(
    InvalidArgumentException::class,
    static fn () => new Delegation('u-a', 'u-a'),
    'a self-delegation is a cycle of length one and is refused where it is created',
);

throws(
    InvalidArgumentException::class,
    static fn () => new Delegation('u-a', 'u-b', effectiveFrom: $now, effectiveTo: $now->modify('-1 day')),
    'and one that ends before it begins is never in force, which is not what anybody typing those dates meant',
);

ok(
    Delegation::wouldCycle([new Delegation('u-b', 'u-a')], new Delegation('u-a', 'u-b'), $now),
    'wouldCycle() lets the screen that ACCEPTS a delegation refuse it, rather than discovering it at 6pm',
);
ok(
    !Delegation::wouldCycle([new Delegation('u-b', 'u-c')], new Delegation('u-a', 'u-b'), $now),
    'and a chain that terminates is not a cycle',
);

// Two delegations in force at once is a tie, and a tie is never resolved by
// picking one (spine S4).
throws(
    SmartPRS\Platform\Workflow\AmbiguousDelegation::class,
    static fn () => Delegation::chain(
        [new Delegation('u-a', 'u-b'), new Delegation('u-a', 'u-c')],
        'u-a',
        $now,
    ),
    'two delegations in force from one user is ambiguous, not a coin toss',
);

// R-IX-077's sibling: the node's own switch. `delegation_allowed` has been
// written by the publisher since the workflow payload landed and nothing read
// it, so a node declaring its approval may not be delegated was delegating it.
$noDelegation = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-a']])],
    'delegationAllowed' => false,
]);
$result = $resolver->resolve($noDelegation, new ResolutionContext($now, requesterUserId: 'u-hr', delegations: [
    new Delegation('u-a', 'u-b'),
]));

same(['u-a'], $result->assignees, 'a node that forbids delegation keeps the original approver');

// Step 7 fails closed on the node's required permission.
$permissioned = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'MGR', 'holders' => ['u-a', 'u-b']])],
    'requiredPermission' => 'leave.approve',
]);
$result = $resolver->resolve($permissioned, new ResolutionContext(
    $now,
    requesterUserId: 'u-hr',
    permissionsByUser: ['u-a' => ['leave.approve']],
));
same(['u-a'], $result->assignees, 'step 7 fails closed: a user without the node permission is not an assignee');

// Step 6: inactive accounts. R-IX-042's precondition.
$result = $resolver->resolve($approval, new ResolutionContext(
    $now,
    requesterUserId: 'u-hr',
    inactiveUsers: ['u-ejaz'],
));
same(['u-mgr'], $result->assignees, 'a deactivated account is never left holding a task');

// R-IX-039: segregation enforced at DECISION time, not only at publish.
$second = wfNode('finance', NodeType::Approval, [
    'participants' => [new ParticipantSpec('role_in_scope', ['role_key' => 'FIN', 'holders' => ['u-admin', 'u-fin']])],
]);
$result = $resolver->resolve($second, new ResolutionContext(
    $now,
    requesterUserId: 'u-hr',
    priorActors: ['u-admin' => 'hr'],
    segregationSets: [['set_key' => 'mc', 'node_keys' => ['hr', 'finance'], 'rule' => 'distinct_actor']],
));
same(['u-fin'], $result->assignees, 'R-IX-039: whoever decided the earlier node is excluded from the later one');
ok(
    str_contains($result->render(), 'segregation set'),
    'because role membership changes between publication and decision',
);

// Delegation and backup substitution are applied, and recorded.
$result = $resolver->resolve($approval, new ResolutionContext(
    $now,
    requesterUserId: 'u-hr',
    delegations: [new Delegation('u-ejaz', 'u-cover', kind: Delegation::BACKUP)],
));
ok(in_array('u-cover', $result->assignees, true), 'an out-of-office backup receives the task');
ok(str_contains($result->render(), 'out-of-office backup'), 'and the substitution is in the trace (R-IX-028)');

// =============================================================================
// §IX.6.3 — RE-DECIDING IS IMPOSSIBLE, NOT DISCOURAGED
// =============================================================================

$task = new Task('t-1', 'manager', 'u-mgr');
$approved = $task->decide(TaskState::Approved, 'u-mgr', $now, 'looks fine');

same(TaskState::Approved, $approved->state, 'a pending task takes a decision');
same(2, $approved->rowVersion, 'and the row version moves, which is what the conditional UPDATE keys on');

$thrown = null;

try {
    $approved->decide(TaskState::Rejected, 'u-other', $now->modify('+1 minute'), 'actually no');
} catch (TaskAlreadyDecided $e) {
    $thrown = $e;
}

ok($thrown instanceof TaskAlreadyDecided, 'R-IX-045: a second decision on a decided task is impossible');
same(409, $thrown?->statusCode(), 'and is a 409, not a silent overwrite');
ok(str_contains($thrown?->getMessage() ?? '', 'u-other'), 'the loser is named');
ok(str_contains($thrown?->getMessage() ?? '', 'recorded'), 'and their attempt is evidence (R-IX-050)');

// R-IX-046: the comment is on the transition. The first decision's reason
// survives because the second decision cannot happen at all.
same('looks fine', $approved->comment, "the first decision's stated reason is not erasable by a blank re-decide");

// R-IX-040: reassignment needs a reason and does not reset the clock.
$due = $now->modify('+2 days');
$open = new Task('t-2', 'manager', 'u-mgr', dueAt: $due);
[$closed, $reopened] = $open->reassignTo('u-other', 'u-mgr', $now, 'on leave');

same(TaskState::Reassigned, $closed->state, 'reassignment closes the old row');
same(TaskState::Pending, $reopened->state, 'and opens a new one, so the history is a chain of rows');
same($due, $reopened->dueAt, 'R-IX-040: the SLA clock is NOT reset — a resettable clock makes SLA reporting meaningless');

throws(
    InvalidArgumentException::class,
    static fn () => $open->reassignTo('u-other', 'u-mgr', $now, ''),
    'R-IX-040: reassignment requires a reason',
);

// =============================================================================
// §IX.6.1 — A TERMINAL INSTANCE IS NEVER REOPENED (R-IX-043)
// =============================================================================

$instance = new Instance('i-1', 'LEAVE-APPROVE', 'wf-v1', 'lr-9', 'u-ejaz', 'manager');
$rejected = $instance->movedTo(InstanceState::Rejected, 'refuse', 'manager rejected');

ok($rejected->isTerminal(), 'a rejected instance is terminal');
same([], InstanceState::Rejected->permittedNext(), 'and has no outgoing transitions at all');

$thrown = null;

try {
    $rejected->movedTo(InstanceState::Running, 'manager', 'reopening');
} catch (LogicException $e) {
    $thrown = $e;
}

ok($thrown !== null, "R-IX-043: a terminal instance cannot be reopened — this is SmartPRS 2's commission reopen");
ok(
    str_contains($thrown?->getMessage() ?? '', 'supersedes_instance_id'),
    'and the message names the correct path: a new instance that carries what it supersedes',
);

$correction = $rejected->supersededBy('i-2');
same('i-1', $correction->supersedesInstanceId, 'the correction points at the original');
same(InstanceState::Running, $correction->state, 'and starts fresh');
ok(str_contains($correction->trace[0], 'supersedes i-1'), 'with the link in its own trace, so both are in the ledger');

// Resuming a blocked instance is an operator act that needs a reason.
$blocked = $instance->movedTo(InstanceState::Blocked, 'manager', 'no participant resolved');
throws(
    LogicException::class,
    static fn () => $blocked->movedTo(InstanceState::Running, 'manager', 'resumed'),
    '§IX.6.1: resuming a blocked instance needs workflow.instance.resume and a stated reason',
);
ok(
    $blocked->movedTo(InstanceState::Running, 'manager', 'resumed', 'participant added')->state === InstanceState::Running,
    'and succeeds once the reason is given',
);

// R-IX-051: an in-flight instance completes on the version it started on.
same('wf-v1', $rejected->definitionVersionId, 'the pinned definition version survives every transition');

// =============================================================================
// §IX.6.5 — CONCURRENT DECISIONS: REJECTION WINS, DETERMINISTICALLY
// =============================================================================

$engine = new Engine(static fn (string $expr, Instance $i): bool => $expr === 'TRUE');

$parallel = wfNode('both', NodeType::ParallelAll, [
    'participants' => [new ParticipantSpec('requester')],
]);

$approveThenReject = [
    (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $now),
    (new Task('b', 'both', 'u-2'))->decide(TaskState::Rejected, 'u-2', $now->modify('+1 second')),
];
$rejectThenApprove = [
    (new Task('b', 'both', 'u-2'))->decide(TaskState::Rejected, 'u-2', $now),
    (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $now->modify('+1 second')),
];

same('rejected', $engine->outcomeOf($parallel, $approveThenReject), '§IX.6.5: on all_must, the rejection wins');
same('rejected', $engine->outcomeOf($parallel, $rejectThenApprove), 'and wins whichever order they arrived in');

$bothApproved = [
    (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $now),
    (new Task('b', 'both', 'u-2'))->decide(TaskState::Approved, 'u-2', $now),
];
same('joined', $engine->outcomeOf($parallel, $bothApproved), 'and only a full set of approvals joins');

same(null, $engine->outcomeOf($parallel, [
    (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $now),
    new Task('b', 'both', 'u-2'),
]), 'while one approval and one open task is not an outcome at all');

// The quorum tally, under the same lock.
$quorum = wfNode('panel', NodeType::Quorum, ['params' => ['n' => 2, 'm' => 3, 'tie_policy' => 'reject']]);
$twoOfThree = [
    (new Task('a', 'panel', 'u-1'))->decide(TaskState::Approved, 'u-1', $now),
    (new Task('b', 'panel', 'u-2'))->decide(TaskState::Approved, 'u-2', $now),
    new Task('c', 'panel', 'u-3'),
];
same('approved', $engine->outcomeOf($quorum, $twoOfThree), 'a quorum of 2 of 3 decides on the second approval');

$twoRejections = [
    (new Task('a', 'panel', 'u-1'))->decide(TaskState::Rejected, 'u-1', $now),
    (new Task('b', 'panel', 'u-2'))->decide(TaskState::Rejected, 'u-2', $now),
    new Task('c', 'panel', 'u-3'),
];
same('rejected', $engine->outcomeOf($quorum, $twoRejections),
    'and decides the other way the moment two approvals are no longer possible, rather than waiting for a third');

// =============================================================================
// §IX.6.7 and R-IX-021 — SKIPPING IS DELIBERATE; AN ERROR NEVER APPROVES
// =============================================================================

$definition = wfDefinition();
$live = new Instance('i-3', 'LEAVE-APPROVE', 'wf-v1', 'lr-1', 'u-ejaz', 'manager');

same('charge', $engine->nextNode($definition, 'manager', 'approved', $live), 'an outcome follows its declared transition');
same('refuse', $engine->nextNode($definition, 'manager', 'rejected', $live), 'and a rejection follows its own');

$stranded = null;

try {
    $engine->nextNode($definition, 'manager', 'no_such_outcome', $live);
} catch (NodeStranded $e) {
    $stranded = $e;
}

ok($stranded instanceof NodeStranded, 'an outcome with no transition parks the instance');
ok(
    str_contains($stranded?->getMessage() ?? '', 'no business decision was taken'),
    'R-IX-021: it never picks a branch to be helpful',
);

$throwing = new Engine(static function (string $expr, Instance $i): bool {
    throw new RuntimeException('the context was not prepared');
});

$guarded = wfNode('maybe', NodeType::Notify, [
    'guardExpression' => 'leave.days > 5',
    'params' => ['template_key' => 't'],
]);

throws(
    NodeStranded::class,
    static fn () => $throwing->shouldSkip($guarded, $live),
    'R-IX-021: a guard that fails to evaluate parks the instance — it is never read as false',
);

$skipping = new Engine(static fn (string $expr, Instance $i): bool => false);
ok($skipping->shouldSkip($guarded, $live), '§IX.6.7: a guard that is genuinely false skips the node');
ok(!$engine->shouldSkip(wfNode('always', NodeType::Notify, ['params' => ['template_key' => 't']]), $live),
    'and a node with no guard is never skipped');
