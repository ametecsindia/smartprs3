<?php

declare(strict_types=1);

/**
 * WORKFLOW CUSTOMISATION: thresholds a customer can move, and the one
 * arrangement that must be refused.
 *
 * An approval chain adds people as the amount grows. If a later step has a LOWER
 * threshold than an earlier one, there is a band of amounts that reaches the
 * senior approver and skips the junior one — a ₹2,000 claim signed by the
 * director that finance never saw. Nothing errors: the routing does exactly what
 * it was told, and what it was told is inverted. That is the check this file is
 * for, and the last section proves the inversion is real by routing a request
 * through it.
 */

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Requests;
use SmartPRS\Surface\WorkflowCustomisation;

group('workflow customisation');

$shipped = [
    'key' => 'expense-claim',
    'label' => 'Expense claim',
    'subject' => 'expenses',
    'raise_permission' => 'employee.view',
    'amount_field' => 'amount',
    'steps' => [
        ['key' => 'submit', 'type' => 'submit', 'label' => 'Claimed', 'by' => 'requester'],
        ['key' => 'manager', 'type' => 'approval', 'label' => 'Reporting manager',
            'by' => 'administrator', 'sla_hours' => 48],
        ['key' => 'finance', 'type' => 'approval', 'label' => 'Finance', 'by' => 'administrator',
            'when_amount_over' => 5000, 'sla_hours' => 72],
        ['key' => 'director', 'type' => 'approval', 'label' => 'Director', 'by' => 'administrator',
            'when_amount_over' => 50000, 'requires_comment' => 'always', 'sla_hours' => 120],
    ],
];

// NON-VACUITY: the change an administrator actually wants must pass, or every
// refusal below proves only that the checker refuses everything.
$valid = ['steps' => [
    'finance' => ['when_amount_over' => 10000],
    'director' => ['when_amount_over' => 200000, 'sla_hours' => 96],
]];
ok(WorkflowCustomisation::problems($valid, $shipped) === [],
    'Moving thresholds up the chain is the whole point and must pass.');

// THE REFUSAL THIS FILE EXISTS FOR.
$inverted = ['steps' => ['director' => ['when_amount_over' => 1000]]];
$problems = WorkflowCustomisation::problems($inverted, $shipped);
ok($problems !== [],
    'A later approver with a LOWER threshold than an earlier one must be refused: amounts between '
    . 'the two skip the earlier approver entirely.');
ok(str_contains($problems[0], 'Director') && str_contains($problems[0], 'Finance'),
    'and the refusal must name both steps, because the fix is to one of them.');
ok(str_contains($problems[0], '1,000') && str_contains($problems[0], '5,000'),
    'and both figures, because which one is wrong is the customer\'s decision, not ours.');

// Equal thresholds are fine: both steps apply to the same band.
ok(WorkflowCustomisation::problems(['steps' => ['director' => ['when_amount_over' => 5000]]],
    $shipped) === [], 'Equal thresholds are not an inversion — both approvers see the same band.');

// A step with no threshold always applies and cannot be skipped, so it can never
// invert anything. The manager step has none.
ok(WorkflowCustomisation::problems(['steps' => ['manager' => ['sla_hours' => 24]]], $shipped) === [],
    'A step that always applies is never an inversion.');

// The control itself is not customisable.
foreach (['subject' => 'payslips', 'raise_permission' => 'employee.view'] as $key => $value) {
    ok(WorkflowCustomisation::problems([$key => $value], $shipped) !== [],
        "Changing {$key} through a settings form must be refused.");
}
ok(WorkflowCustomisation::problems(['steps' => ['finance' => ['by' => 'requester']]], $shipped) !== [],
    'Changing WHO decides a step must be refused — that is removing the second person the approval '
    . 'exists for, not configuring it.');
ok(WorkflowCustomisation::problems(['steps' => ['finance' => ['type' => 'submit']]], $shipped) !== [],
    'Turning an approval step into a non-approval must be refused for the same reason.');
ok(WorkflowCustomisation::problems(['steps' => ['nope' => ['sla_hours' => 1]]], $shipped) !== [],
    'A step the workflow does not have must be refused, not ignored.');

// An SLA of zero makes every request overdue the moment it is raised, which
// makes the overdue list useless rather than urgent.
ok(WorkflowCustomisation::problems(['steps' => ['finance' => ['sla_hours' => 0]]], $shipped) !== [],
    'A zero SLA must be refused.');
ok(WorkflowCustomisation::problems(['steps' => ['finance' => ['when_amount_over' => -1]]],
    $shipped) !== [], 'A negative threshold must be refused.');
ok(WorkflowCustomisation::problems(['steps' => ['finance' => ['requires_comment' => 'sometimes']]],
    $shipped) !== [], 'A comment rule outside the three that exist must be refused.');

// ---- the overlay actually reaches the ROUTING, not just the screen ----------

$store = (new SmartPRS\Surface\Store(
    sys_get_temp_dir() . '/prs3-wf-' . bin2hex(random_bytes(4))))->forTenant('1');
$customisation = new Customisation($store);
$workflows = new WorkflowCustomisation($customisation);

$requests = new Requests($store, __DIR__ . '/../config/workflows.json', $workflows);
$before = $requests->chainFor((array) $requests->workflow('expense-claim'), '8000.00');
$financeApplies = static function (array $chain): bool {
    foreach ($chain as $step) {
        if ((string) $step['key'] === 'finance') {
            return (bool) $step['applies'];
        }
    }

    return false;
};

ok($financeApplies($before),
    'As shipped, an ₹8,000 claim goes to finance — the baseline the change is measured against.');

$customisation->saveRaw('workflow:expense-claim',
    ['steps' => ['finance' => ['when_amount_over' => 25000]]], '1', 'admin', 'we raised the limit');

$requests = new Requests($store, __DIR__ . '/../config/workflows.json',
    new WorkflowCustomisation(new Customisation($store)));
$after = $requests->chainFor((array) $requests->workflow('expense-claim'), '8000.00');

ok(!$financeApplies($after),
    'After the customisation the same ₹8,000 claim no longer goes to finance — the overlay reaches '
    . 'the ROUTING, not only the screen. A screen and a router disagreeing about who must approve '
    . 'is the worst place for a disagreement.');

// A threshold cleared back to nothing means "always applies" — and it has to be
// REMOVED, not set to null, because the routing asks whether the key is there.
$customisation->saveRaw('workflow:expense-claim',
    ['steps' => ['finance' => ['when_amount_over' => '']]], '1', 'admin', 'finance sees everything');
$requests = new Requests($store, __DIR__ . '/../config/workflows.json',
    new WorkflowCustomisation(new Customisation($store)));

ok($financeApplies($requests->chainFor((array) $requests->workflow('expense-claim'), '1.00')),
    'A threshold cleared to nothing means the step always applies, including on ₹1.');

// The shipped declaration is still readable underneath, which is what a change
// gets compared against.
same(5000, $requests->shipped('expense-claim')['steps'][2]['when_amount_over'],
    'The shipped workflow is unchanged by any customisation.');

// ---- THE THRESHOLD IS AN EXACT COMPARISON, AT THE BOUNDARY ------------------
//
// Routing by amount was `$amount > (float) $threshold`. A float comparison is
// only ever wrong AT the boundary — ₹50,000.00 arriving as 49999.999999999993,
// or ₹50,000.01 arriving as 50000.009999999995 — so a test that checks ₹1 and
// ₹5,00,000 passes straight over the defect. These are the three amounts that
// matter around the director's ₹50,000 threshold, to the paisa.

$plain = new Requests($store, __DIR__ . '/../config/workflows.json');
$expense = (array) $plain->workflow('expense-claim');
$applies = static function (array $chain, string $key): bool {
    foreach ($chain as $step) {
        if ((string) $step['key'] === $key) {
            return (bool) $step['applies'];
        }
    }

    return false;
};

ok(!$applies($plain->chainFor($expense, '49999.99'), 'director'),
    'One paisa under ₹50,000 does not reach the director.');
ok(!$applies($plain->chainFor($expense, '50000.00'), 'director'),
    'EXACTLY ₹50,000 does not reach the director — the declaration says OVER, and "over" has to '
    . 'mean strictly over at the boundary or the threshold means whatever the float rounded to.');
ok($applies($plain->chainFor($expense, '50000.01'), 'director'),
    'One paisa over ₹50,000 DOES reach the director. This is the routing decision the float '
    . 'comparison could lose: a claim that should be signed by the director approved without him.');

// The columns store DECIMAL(18,4), so an amount comes back with sub-paise
// digits on it. Those are resolved by an exact half-up parse, not by whatever a
// float comparison makes of them: `50000.004 > 50000` is TRUE in float and sent
// the claim to the director; to the paisa the claim is ₹50,000.00 and is not.
ok(!$applies($plain->chainFor($expense, '50000.0000'), 'director'),
    'A stored ₹50,000.0000 is still exactly ₹50,000 and still does not reach the director.');
ok(!$applies($plain->chainFor($expense, '50000.0049'), 'director'),
    'Sub-paise digits below half a paisa do not push a claim over the threshold — in float they '
    . 'did, because 50000.0049 is a bigger float than 50000.');
ok($applies($plain->chainFor($expense, '50000.005'), 'director'),
    'and half a paisa and above rounds up to ₹50,000.01, which does reach him.');

ok(!$applies($plain->chainFor($expense, '5000.00'), 'finance'),
    'Exactly ₹5,000 does not reach finance either — the same boundary, one step down.');
ok($applies($plain->chainFor($expense, '5000.01'), 'finance'),
    'And one paisa over ₹5,000 does.');

// The routing and the reason a person reads must agree, to the paisa.
$skipped = '';

foreach ($plain->chainFor($expense, '50000.00') as $step) {
    if ((string) $step['key'] === 'director') {
        $skipped = (string) $step['why'];
    }
}

same('skipped — ₹50,000.00 is not over the ₹50,000.00 threshold', $skipped,
    'and the reason printed on the request states both figures exactly, in paise.');

// A day count is not money and is not routed as money, but it is compared just
// as exactly: half a day either side of the ten-day HR threshold.
$leave = (array) $plain->workflow('leave-request');
ok(!$applies($plain->chainFor($leave, '10'), 'hr'), 'Exactly ten days does not reach HR.');
ok($applies($plain->chainFor($leave, '10.5'), 'hr'), 'Ten and a half days does.');

// An amount nobody can read exactly is refused rather than becoming 0.0.
$bad = $plain->raise('expense-claim', '17', '4,200', 'note', '1', 'admin');
ok(!$bad['ok'], 'A malformed amount is refused at raise: ' . $bad['why']);
