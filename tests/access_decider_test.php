<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\AccessDecider;
use SmartPRS\Platform\Access\AccessDecision;
use SmartPRS\Platform\Access\Assignment;
use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\Permission;
use SmartPRS\Platform\Access\PermissionCatalogue;
use SmartPRS\Platform\Access\Role;
use SmartPRS\Platform\Access\ScopeComposition;
use SmartPRS\Platform\Access\SubjectContext;
use SmartPRS\Platform\Support\EffectiveRange;

$catalogue = PermissionCatalogue::seeded();
$decider = new AccessDecider($catalogue);
$asOf = new DateTimeImmutable('2026-08-12');

// ---------------------------------------------------------------------------
// The §XXII.3.4 worked example, built exactly as the blueprint states it.
//
//   Priya holds two assignments:
//     A1  role "Branch HR"     (employee.record.update, leave.request.decide)
//         scope Hyderabad branch
//     A2  role "Payroll Viewer" (payroll.run.view, payroll.line.view)
//         scope Tenant
//
//   She may update an employee record only in Hyderabad, and view payroll runs
//   tenant-wide. She may NOT update a Chennai employee record — even though her
//   permission union contains employee.record.update and her scope union is the
//   whole tenant.
// ---------------------------------------------------------------------------

$branchHr = Role::publish('BRANCH_HR', 'Branch HR', 4, false, [
    'employee.record.update',
    'leave.request.decide',
], catalogue: $catalogue);

$payrollViewer = Role::publish('PAYROLL_VIEWER', 'Payroll Viewer', 1, true, [
    'payroll.run.view',
    'payroll.line.view',
], catalogue: $catalogue);

$hyderabad = DataScope::of('branch', ['BR-HYD'], key: 'HYDERABAD_BRANCH', version: 2);

$a1 = Assignment::of($branchHr, $hyderabad, '2026-04-01', 'A1');
$a2 = Assignment::of($payrollViewer, DataScope::tenantWide(), '2026-04-01', 'A2');
$priya = [$a1, $a2];

$subject = static fn (string $employee, string $branch): SubjectContext => new SubjectContext(
    tenantId: 'T7',
    actorTenantId: 'T7',
    actorEmployeeId: 'EMP1000',
    employeeId: $employee,
    orgUnitPath: ['LE1', 'C1', $branch, 'DEP-COLL'],
    unitsByType: ['legal_entity' => 'LE1', 'company' => 'C1', 'branch' => $branch, 'department' => 'DEP-COLL'],
);

$ravi = $subject('EMP1042', 'BR-HYD');    // Hyderabad
$anand = $subject('EMP2210', 'BR-CHN');   // Chennai

group('ScopeComposition — the cross-axis leak');

// The two axes, each on its own, both say yes. This is the trap.
same(
    ['employee.record.update', 'leave.request.decide', 'payroll.line.view', 'payroll.run.view'],
    (new ScopeComposition($priya))->effectivePermissions($asOf),
    'the permission union (R-XXII-030) contains employee.record.update',
);
ok(
    DataScope::tenantWide()->covers($anand),
    'and the scope union — via A2 — contains the whole tenant, Chennai included',
);

// THE LEAK. Unioning the two axes separately would grant this. R-XXII-050 does not.
$leak = $decider->decide($priya, 'employee.record.update', $anand, $asOf);

ok(!$leak->allowed, 'CROSS-AXIS LEAK: updating a Chennai record is DENIED, despite both unions saying yes');
same(AccessDecision::SCOPE_DENIED, $leak->reason, 'and it is denied on the SCOPE axis, not the permission axis');
ok(
    !(new ScopeComposition($priya))->grants('employee.record.update', $anand, $asOf),
    'the composition itself refuses it — the existential is per assignment, not a cross-product of unions',
);

// The three answers that must still be right, or the fix is just a lockout.
ok(
    $decider->decide($priya, 'employee.record.update', $ravi, $asOf)->allowed,
    'she may still update a Hyderabad record — A1 carries both axes',
);
ok(
    $decider->decide($priya, 'payroll.run.view', $anand, $asOf)->allowed,
    'and view a Chennai payroll run — A2 carries both axes',
);
ok(
    !$decider->decide($priya, 'payroll.run.approve', $ravi, $asOf)->allowed,
    'and approve nothing anywhere — neither role carries it',
);

// R-XXII-052: the list query disjoins only the scopes of assignments that carry
// the permission. The same rule, so a list cannot leak what a record check denies.
same(
    [['scope' => 'branch', 'scope_key' => 'HYDERABAD_BRANCH', 'scope_version' => 2,
        'org_units' => ['BR-HYD'], 'include_descendants' => true]],
    (new ScopeComposition($priya))->listPredicate('employee.record.update', $ravi, $asOf),
    'a list scoped by employee.record.update injects A1\'s branch expression ONLY — never A2\'s tenant scope',
);
same(
    [['scope' => 'tenant', 'scope_key' => 'TENANT', 'scope_version' => 1, 'tenant_id' => 'T7']],
    (new ScopeComposition($priya))->listPredicate('payroll.run.view', $ravi, $asOf),
    'and a payroll list injects A2\'s tenant expression only',
);
same(
    [],
    (new ScopeComposition($priya))->listPredicate('payroll.run.approve', $ravi, $asOf),
    'a permission she does not hold yields no predicate at all — an empty disjunction selects nothing',
);

group('AccessDecider — effective dating');

$expired = new Assignment(
    'BRANCH_HR',
    $branchHr,
    'HYDERABAD_BRANCH',
    $hyderabad,
    EffectiveRange::fromInclusive('2026-01-01', '2026-06-30'),
    'A0',
);

ok(
    !$decider->decide([$expired], 'employee.record.update', $ravi, $asOf)->allowed,
    'R-XXII-029: a revoked assignment is closed, not deleted, and no longer grants',
);
ok(
    $decider->decide([$expired], 'employee.record.update', $ravi, new DateTimeImmutable('2026-05-15'))->allowed,
    'and "what could this user do on 15 May?" still answers yes',
);

// ---------------------------------------------------------------------------
// FAIL-CLOSED. R-XXII-094 and R-XXII-095: there is no path that allows on error.
// Seven conditions, seven denials.
// ---------------------------------------------------------------------------

group('AccessDecider — fail closed');

// 1. An unknown permission. A deployment defect, not a free pass.
$unknown = $decider->decide($priya, 'employee.record.annihilate', $ravi, $asOf);
ok(!$unknown->allowed, '1. an unknown permission DENIES');
same(AccessDecision::PERMISSION_UNKNOWN, $unknown->reason, '   and says the catalogue does not carry it');
ok($unknown->alerts(), '   and alerts — an unmapped operation is a deployment defect');

// 2. An unknown role code on an assignment (R-XXII-024, D-05).
$roleless = new Assignment('ACCOUNTANT', null, 'TENANT', DataScope::tenantWide(), EffectiveRange::openEndedFrom('2026-01-01'), 'A9');
$badRole = $decider->decide([$roleless], 'employee.record.update', $ravi, $asOf);
ok(!$badRole->allowed, '2. an unknown role code DENIES — assigning "Accountant" does not produce an Administrator');
same(AccessDecision::ROLE_UNKNOWN, $badRole->reason, '   and names the unresolved role');
ok($badRole->alerts(), '   and alerts');

// 3. A null / missing scope.
$scopeless = new Assignment('BRANCH_HR', $branchHr, 'HYDERABAD_BRANCH', null, EffectiveRange::openEndedFrom('2026-01-01'), 'A8');
$noScope = $decider->decide([$scopeless], 'employee.record.update', $ravi, $asOf);
ok(!$noScope->allowed, '3. a null scope DENIES — a missing scope is never an unbounded scope');
same(AccessDecision::SCOPE_MISSING, $noScope->reason, '   and says so');

// 4. An empty assignment list (R-XXII-036).
$none = $decider->decide([], 'employee.record.update', $ravi, $asOf);
ok(!$none->allowed, '4. a user with no assignment DENIES — they are not an administrator');
same(AccessDecision::NO_ASSIGNMENT, $none->reason, '   and the reason names the absence, not a scope failure');

// 5. A malformed subject context: placeable by nothing.
$unplaceable = $decider->decide($priya, 'employee.record.update', new SubjectContext('T7', 'T7', 'EMP1000'), $asOf);
ok(!$unplaceable->allowed, '5. a subject with no employee and no org unit DENIES');
same(AccessDecision::SUBJECT_UNRESOLVABLE, $unplaceable->reason, '   and says the subject could not be placed');

// 6. An exception thrown inside scope evaluation: a reporting cycle.
$manager = Assignment::of($branchHr, DataScope::of('reporting_tree', depth: 5), '2026-01-01', 'A7');
$cyclic = new SubjectContext(
    tenantId: 'T7',
    actorTenantId: 'T7',
    actorEmployeeId: 'EMP1000',
    employeeId: 'EMP1099',
    orgUnitPath: ['BR-HYD'],
    managerChain: ['EMP1042', 'EMP1080', 'EMP1042', 'EMP1000'],
);
$thrown = $decider->decide([$manager], 'employee.record.update', $cyclic, $asOf);
ok(!$thrown->allowed, '6. an exception inside scope evaluation DENIES');
same(AccessDecision::ERROR, $thrown->reason, '   with the error reason, never a partial allow');
ok($thrown->alerts(), '   and alerts immediately');
ok(
    str_contains(implode("\n", $thrown->trace), 'reporting_cycle_detected'),
    '   and the trace carries the fault for the operator',
);

// 7. A scope type the decider does not recognise: declared in the type table,
//    no resolver in this build.
$unresolvable = Assignment::of($branchHr, DataScope::of('dimension_filter', key: 'PAY_GROUP_A'), '2026-01-01', 'A6');
$noResolver = $decider->decide([$unresolvable], 'employee.record.update', $ravi, $asOf);
ok(!$noResolver->allowed, '7. a scope type with no resolver DENIES');
same(AccessDecision::ERROR, $noResolver->reason, '   rather than skipping the assignment and asking the next one');
ok(
    str_contains(implode("\n", $noResolver->trace), 'dimension_filter'),
    '   and the trace names the type that could not be resolved',
);

// The structural claim behind all seven: there is exactly one `allow` in the
// decider, and it is not reachable from any error branch.
$source = (string) file_get_contents(__DIR__ . '/../src/Platform/Access/AccessDecider.php');
same(
    1,
    preg_match_all('/AccessDecision::allow\(/', $source),
    'R-XXII-095: AccessDecider contains exactly ONE allow, at the single point both axes are satisfied',
);
same(
    1,
    count(array_filter(token_get_all($source), static fn ($t): bool => is_array($t) && $t[0] === T_CATCH)),
    'and exactly one catch, which denies',
);

// Cross-tenant is a 404, not a 403 (R-XXII-097): existence is not disclosable.
$otherTenant = $decider->decide($priya, 'employee.record.update', new SubjectContext(
    tenantId: 'T9',
    actorTenantId: 'T7',
    actorEmployeeId: 'EMP1000',
    employeeId: 'EMP5000',
    orgUnitPath: ['BR-BLR'],
), $asOf);
ok(!$otherTenant->allowed, 'a cross-tenant subject DENIES');
same(AccessDecision::NOT_FOUND, $otherTenant->reason, 'as NOT_FOUND — cross-tenant existence is not disclosable');
ok($otherTenant->hidesExistence(), 'and the decision says to render 404 rather than 403');

// A malformed permission code reaching the decider is caught and denied, never
// thrown at the caller — a guard that throws is a guard someone wraps in a try.
$malformed = $decider->decide($priya, 'Payroll.Run.Approve', $ravi, $asOf);
ok(!$malformed->allowed, 'a malformed permission code DENIES rather than escaping as an exception');
same(AccessDecision::ERROR, $malformed->reason, 'through the one catch');

// ---------------------------------------------------------------------------
// The trace. §XXII.13: "why was I denied?" must be answerable, and support
// will live on this.
// ---------------------------------------------------------------------------

group('AccessDecision — the trace names the actual reason');

$trace = implode("\n", $leak->trace);

ok(str_contains($leak->render(), 'DENY  ·  ACCESS_SCOPE_DENIED'), 'the denial renders its machine-readable reason');
ok(str_contains($trace, 'employee.record.update — HELD'), 'the trace says the permission IS held');
ok(str_contains($trace, 'Data scope      NOT SATISFIED'), 'and that the scope axis is the one that failed');
ok(str_contains($trace, 'A1'), 'it names the assignment that came closest (R-XXII-051)');
ok(str_contains($trace, 'HYDERABAD_BRANCH v2'), 'and the scope VERSION it used (R-XXII-271)');
ok(str_contains($trace, '"Branch HR" v4'), 'and the role version');
ok(str_contains($trace, 'A2'), 'it names the other assignment');
ok(str_contains($trace, 'does not carry this permission'), 'and why that one was not consulted');
ok(str_contains($trace, 'What to change'), 'and what would change the answer');
ok(!str_contains($trace, 'EMP2210'), 'R-XXII-272: it does not disclose the subject\'s own attributes');

$notHeld = $decider->decide($priya, 'payroll.run.approve', $ravi, $asOf);
same(AccessDecision::PERMISSION_DENIED, $notHeld->reason, 'a permission nobody holds fails on the PERMISSION axis');
ok(
    str_contains(implode("\n", $notHeld->trace), 'payroll.run.approve — NOT HELD'),
    'and the trace distinguishes "not held" from "held but out of scope" — the two have different fixes',
);

$allowed = $decider->decide($priya, 'employee.record.update', $ravi, $asOf);
ok(str_contains($allowed->render(), 'ALLOW'), 'an allow explains itself too');
ok(str_contains(implode("\n", $allowed->trace), 'satisfied by  A1'), 'naming the assignment that satisfied it');
ok(!$allowed->alerts(), 'and raises no alert');

same(
    ['allowed', 'reason', 'summary', 'trace'],
    array_keys($allowed->toArray()),
    'the decision serialises for the audit ledger (R-XXII-274)',
);

// A decision can never claim both.
throws(
    LogicException::class,
    static fn () => AccessDecision::deny(AccessDecision::GRANTED, 'nonsense'),
    'a denial cannot carry the granted reason code',
);

// The permission argument is accepted either as a code or as the value object.
ok(
    $decider->decide($priya, Permission::of('employee.record.update'), $ravi, $asOf)->allowed,
    'the decider takes a Permission as readily as a string, and validates either way',
);
