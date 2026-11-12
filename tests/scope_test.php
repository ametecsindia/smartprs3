<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\Scope;

group('Scope');

// --- the spine S3.2 weight table, verbatim ---------------------------------

$expectedWeights = [
    'employee' => 1000, 'team' => 500, 'sub_department' => 450, 'department' => 400,
    'designation' => 350, 'grade' => 340, 'shift' => 330, 'employee_category' => 320,
    'employment_type' => 310, 'employment_stage' => 300, 'cost_centre' => 250,
    'client_process' => 240, 'business_unit' => 230, 'location' => 200, 'branch' => 180,
    'zone' => 160, 'state' => 140, 'establishment' => 120, 'company' => 100, 'legal_entity' => 60,
];

foreach ($expectedWeights as $dimension => $weight) {
    same($weight, Scope::WEIGHTS[$dimension] ?? null, "S3.2 weight for {$dimension}");
}
same(0, Scope::WEIGHTS['pay_group'], 'pay_group carries no S3.2 weight (documented blueprint gap)');

// --- specificity arithmetic, worked ----------------------------------------

same(0, Scope::tenantDefault()->specificity(), 'an empty scope is the tenant default, specificity 0');
same(1000, Scope::of(['employee' => ['E-1001']])->specificity(), 'a single-employee scope is 1000');

// The V.8.4 worked example: {branch IN [Hyderabad], employee_category IN [FIELD]}.
same(
    180 + 320,
    Scope::of(['branch' => ['Hyderabad'], 'employee_category' => ['FIELD']])->specificity(),
    'branch + employee_category = 500',
);

same(
    60 + 100 + 140,
    Scope::of(['legal_entity' => ['LE1'], 'company' => ['C1'], 'state' => ['TG']])->specificity(),
    'legal_entity + company + state = 300',
);

same(
    500 + 450 + 400,
    Scope::of(['team' => ['T1'], 'sub_department' => ['SD1'], 'department' => ['D1']])->specificity(),
    'team + sub_department + department = 1350, which outranks a single employee — the weights are summed, per S3.2',
);

same(
    180,
    Scope::of(['branch' => ['Hyderabad', 'Chennai', 'Pune']])->specificity(),
    'the size of an IN list does not change specificity; only the dimension counts',
);

throws(
    InvalidArgumentException::class,
    static fn () => Scope::of(['favourite_colour' => ['blue']]),
    'an unknown dimension is rejected — the dimension set is fixed by S3.1',
);
throws(
    InvalidArgumentException::class,
    static fn () => Scope::of(['branch' => []]),
    'an empty IN list is rejected; ALL is expressed by omitting the dimension',
);

// --- matching ---------------------------------------------------------------

$subject = [
    'legal_entity' => 'LE1', 'company' => 'C1', 'branch' => 'Hyderabad',
    'department' => 'Collections', 'employee_category' => 'FIELD', 'employee' => 'E-1001',
];

ok(Scope::tenantDefault()->matches($subject), 'the tenant default matches everyone');
ok(Scope::of(['branch' => ['Hyderabad']])->matches($subject), 'a matching single predicate');
ok(Scope::of(['branch' => ['Hyderabad', 'Chennai']])->matches($subject), 'an IN list matches any member');
ok(
    Scope::of(['branch' => ['Hyderabad'], 'employee_category' => ['FIELD']])->matches($subject),
    'all predicates must match, and do',
);
ok(!Scope::of(['branch' => ['Chennai']])->matches($subject), 'a non-member value does not match');
ok(
    !Scope::of(['branch' => ['Hyderabad'], 'grade' => ['G4']])->matches($subject),
    'a dimension the subject does not carry does not match — matching fails closed',
);
ok(!Scope::of(['team' => ['T1']])->matches(['team' => null]), 'a null subject dimension never matches');

// --- precedence -------------------------------------------------------------

$branch = Scope::of(['branch' => ['Hyderabad']]);          // 180
$department = Scope::of(['department' => ['Collections']]); // 400

same(-1, $branch->compareTo($department), 'the more specific scope wins on specificity alone');
same(1, $department->compareTo($branch), 'and the comparison is symmetric');
same(1, $branch->compareTo(Scope::tenantDefault()), 'anything beats the tenant default');

// Equal specificity, different priority: priority decides (S3.2 rule 2).
$otherBranch = Scope::of(['branch' => ['Chennai']]);
same(1, $branch->compareTo($otherBranch, 100, 50), 'equal specificity is broken by declared priority');
same(-1, $branch->compareTo($otherBranch, 50, 100), 'and the lower priority loses');

// Equal specificity AND equal priority: a genuine tie. Reported, never resolved.
ok($branch->tiesWith($otherBranch, 100, 100), 'tiesWith reports a genuine tie');
throws(
    AmbiguousConfiguration::class,
    static fn () => $branch->compareTo($otherBranch, 100, 100),
    'a genuine tie throws rather than picking a winner (S3.2 rule 3)',
);

// Different dimensions can also tie: state(140) + branch(180) = 320 = employee_category(320).
$sum = Scope::of(['state' => ['TG'], 'branch' => ['Hyderabad']]);
$single = Scope::of(['employee_category' => ['FIELD']]);
same(320, $sum->specificity(), 'state + branch = 320');
same(320, $single->specificity(), 'employee_category = 320');
throws(
    AmbiguousConfiguration::class,
    static fn () => $sum->compareTo($single, 0, 0),
    'two different scopes summing to the same weight with the same priority is a blocked publication',
);

try {
    $sum->compareTo($single, 0, 0, 'LATE-POLICY v2', 'LATE-POLICY v3');
    ok(false, 'expected an ambiguity');
} catch (AmbiguousConfiguration $e) {
    ok(str_contains($e->getMessage(), 'LATE-POLICY v2'), 'the ambiguity names the first candidate');
    ok(str_contains($e->getMessage(), 'LATE-POLICY v3'), 'the ambiguity names the second candidate');
    same(['LATE-POLICY v2', 'LATE-POLICY v3'], $e->candidates, 'both candidates are machine-readable on the exception');
}

// --- canonical form ---------------------------------------------------------

same(
    Scope::of(['branch' => ['A', 'B'], 'grade' => ['G1']])->toJson(),
    Scope::of(['grade' => ['G1'], 'branch' => ['B', 'A']])->toJson(),
    'canonical JSON does not depend on the order the author wrote the predicates in',
);
same(
    [
        ['dimension' => 'branch', 'operator' => 'IN', 'value' => 'A'],
        ['dimension' => 'branch', 'operator' => 'IN', 'value' => 'B'],
        ['dimension' => 'grade', 'operator' => 'IN', 'value' => 'G1'],
    ],
    Scope::of(['grade' => ['G1'], 'branch' => ['B', 'A']])->toPredicateRows(),
    'the normalised predicate rows for config_version_scopes',
);
ok(Scope::tenantDefault()->isTenantDefault(), 'isTenantDefault');
