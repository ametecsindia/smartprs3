<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\DataScope;
use SmartPRS\Platform\Access\ScopeResolutionFailed;
use SmartPRS\Platform\Access\SubjectContext;

group('SubjectContext');

/**
 * One org tree, used by everything below.
 *
 *   LE1 (legal entity) → C1 (company) → BR-HYD (branch) → DEP-COLL → TEAM-B
 *                                     → BR-CHN (branch) → DEP-COLL-CHN
 *
 * The actor is Priya (EMP1000), an employee of tenant T7 who is not in the
 * platform tenant.
 */
$hyderabadSubject = static fn (string $employee, array $chain = []): SubjectContext => new SubjectContext(
    tenantId: 'T7',
    actorTenantId: 'T7',
    actorEmployeeId: 'EMP1000',
    employeeId: $employee,
    orgUnitPath: ['LE1', 'C1', 'BR-HYD', 'DEP-COLL', 'TEAM-B'],
    unitsByType: [
        'legal_entity' => 'LE1',
        'company' => 'C1',
        'branch' => 'BR-HYD',
        'department' => 'DEP-COLL',
        'team' => 'TEAM-B',
    ],
    managerChain: $chain,
);

$chennaiSubject = new SubjectContext(
    tenantId: 'T7',
    actorTenantId: 'T7',
    actorEmployeeId: 'EMP1000',
    employeeId: 'EMP2210',
    orgUnitPath: ['LE1', 'C1', 'BR-CHN', 'DEP-COLL-CHN'],
    unitsByType: [
        'legal_entity' => 'LE1',
        'company' => 'C1',
        'branch' => 'BR-CHN',
        'department' => 'DEP-COLL-CHN',
    ],
);

throws(
    InvalidArgumentException::class,
    static fn () => new SubjectContext(tenantId: '', actorTenantId: 'T7'),
    'an empty tenant id is rejected at construction — a sentinel tenant is never a wildcard',
);
throws(
    InvalidArgumentException::class,
    static fn () => new SubjectContext(tenantId: 'T7', actorTenantId: ''),
    'and so is an empty actor tenant',
);

ok($hyderabadSubject('EMP1042')->isResolvable(), 'a subject with an employee is placeable');
ok(
    (new SubjectContext('T7', 'T7', 'EMP1000', null, ['BR-HYD']))->isResolvable(),
    'an org-owned record with a unit path is placeable',
);
ok(
    !(new SubjectContext('T7', 'T7', 'EMP1000'))->isResolvable(),
    'a subject with neither an employee nor a unit is NOT placeable — and that denies',
);

group('DataScope');

// --- the type vocabulary is closed (R-XXII-040) -----------------------------

throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('everyone_in_the_building'),
    'an invented scope type is rejected: the vocabulary is a platform constant table',
);
throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('branch'),
    'a unit scope with no units is rejected — an empty unit list is not "all units"',
);
throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('tenant', ['BR-HYD']),
    'a tenant scope may not carry units',
);
throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('branch', ['']),
    'a blank org unit id is rejected (R-XXII-045: ids, never names)',
);
throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('branch', ['BR-HYD'], depth: 3),
    'only reporting_tree carries a depth',
);

// --- self (R-XXII-042) -------------------------------------------------------

$self = DataScope::selfOnly();

ok($self->covers($hyderabadSubject('EMP1000')), 'self covers the actor\'s own record');
ok(!$self->covers($hyderabadSubject('EMP1042')), 'self covers nobody else');
ok(
    !$self->covers(new SubjectContext('T7', 'T7', actorEmployeeId: null, employeeId: 'EMP1042')),
    'R-XXII-042: an actor with no employee binding gets the EMPTY set, not the tenant',
);
ok(
    !$self->covers(new SubjectContext('T7', 'T7', actorEmployeeId: null, employeeId: null)),
    'and null === null does not make an unbound actor their own subject',
);

// --- direct_reports ----------------------------------------------------------

$direct = DataScope::of('direct_reports');
$directPlusSelf = DataScope::of('direct_reports', includeSelf: true);

ok($direct->covers($hyderabadSubject('EMP1042', ['EMP1000', 'EMP0900'])), 'a direct report is covered');
ok(
    !$direct->covers($hyderabadSubject('EMP1099', ['EMP1042', 'EMP1000'])),
    'a skip-level report is NOT a direct report',
);
ok(!$direct->covers($hyderabadSubject('EMP1000', [])), 'direct_reports excludes the actor by default');
ok($directPlusSelf->covers($hyderabadSubject('EMP1000', [])), 'include_self is explicit and never inferred');
ok(!$direct->covers($hyderabadSubject('EMP7777', [])), 'an employee with no manager is not a report');

// --- reporting_tree, at the depth boundaries (R-XXII-044) -------------------

// EMP1099 → EMP1042 → EMP1080 → EMP1000(actor): the actor is at level 3.
$threeDeep = static fn (): SubjectContext => $hyderabadSubject('EMP1099', ['EMP1042', 'EMP1080', 'EMP1000']);

ok(!DataScope::of('reporting_tree', depth: 1)->covers($threeDeep()), 'depth 1 does not reach level 3');
ok(!DataScope::of('reporting_tree', depth: 2)->covers($threeDeep()), 'depth 2 does not reach level 3');
ok(DataScope::of('reporting_tree', depth: 3)->covers($threeDeep()), 'depth 3 reaches level 3 exactly');
ok(DataScope::of('reporting_tree', depth: 4)->covers($threeDeep()), 'depth 4 reaches it too');
ok(DataScope::of('reporting_tree')->covers($threeDeep()), 'a NULL depth is unbounded, up to the platform maximum');

ok(
    !DataScope::of('reporting_tree', depth: 3)->covers($hyderabadSubject('EMP1000', [])),
    'the actor is not in their own tree unless include_self says so',
);
ok(
    DataScope::of('reporting_tree', depth: 3, includeSelf: true)->covers($hyderabadSubject('EMP1000', [])),
    'with include_self they are',
);

throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('reporting_tree', depth: 0),
    'depth 0 is not a tree',
);
throws(
    InvalidArgumentException::class,
    static fn () => DataScope::of('reporting_tree', depth: DataScope::MAX_REPORTING_DEPTH + 1),
    'depth beyond access.reporting_tree.max_depth is refused, not silently clamped',
);

// R-XXII-044: a reporting cycle terminates the walk and DENIES. It is a data
// defect, never a licence to widen scope, so it raises rather than truncating.
throws(
    ScopeResolutionFailed::class,
    static fn () => DataScope::of('reporting_tree', depth: 5)
        ->covers($hyderabadSubject('EMP1099', ['EMP1042', 'EMP1080', 'EMP1042', 'EMP1000'])),
    'a reporting cycle raises access.reporting_cycle_detected',
);

// --- unit scopes -------------------------------------------------------------

$hyderabadBranch = DataScope::of('branch', ['BR-HYD'], key: 'HYDERABAD_BRANCH', version: 2);

ok($hyderabadBranch->covers($hyderabadSubject('EMP1042')), 'the branch scope reaches a Hyderabad employee');
ok(!$hyderabadBranch->covers($chennaiSubject), 'and not a Chennai one');

ok(DataScope::of('team', ['TEAM-B'])->covers($hyderabadSubject('EMP1042')), 'team reaches its own team');
ok(!DataScope::of('team', ['TEAM-A'])->covers($hyderabadSubject('EMP1042')), 'and not another');
ok(DataScope::of('department', ['DEP-COLL'])->covers($hyderabadSubject('EMP1042')), 'department reaches its own');
ok(DataScope::of('company', ['C1'])->covers($chennaiSubject), 'company reaches both branches');
ok(DataScope::of('legal_entity', ['LE1'])->covers($chennaiSubject), 'legal entity reaches the whole group');
ok(!DataScope::of('legal_entity', ['LE2'])->covers($chennaiSubject), 'and not another legal entity');
ok(DataScope::of('location', ['BR-HYD'])->covers($hyderabadSubject('EMP1042')), 'location resolves like any unit');

// R-XXII-046: include_descendants is explicit, defaulting true for department
// and unit_list and false for team.
ok(
    DataScope::of('department', ['BR-HYD'])->covers($hyderabadSubject('EMP1042')),
    'a department scope naming an ancestor unit reaches the subject — descendants included by default',
);
ok(
    !DataScope::of('department', ['BR-HYD'], includeDescendants: false)->covers($hyderabadSubject('EMP1042')),
    'with include_descendants off, only the exact department matches',
);
ok(
    !DataScope::of('team', ['DEP-COLL'])->covers($hyderabadSubject('EMP1042')),
    'team defaults to include_descendants FALSE, so an ancestor unit does not reach',
);
ok(
    DataScope::of('team', ['DEP-COLL'], includeDescendants: true)->covers($hyderabadSubject('EMP1042')),
    'unless it is turned on explicitly',
);

// --- unit_list: an arbitrary portfolio of units of any type ------------------

$portfolio = DataScope::of('unit_list', ['BR-CHN', 'TEAM-B'], key: 'AD_HOC');

ok($portfolio->covers($chennaiSubject), 'unit_list reaches a Chennai employee through BR-CHN');
ok($portfolio->covers($hyderabadSubject('EMP1042')), 'and a Hyderabad one through TEAM-B');
ok(
    !DataScope::of('unit_list', ['BR-CHN'])->covers($hyderabadSubject('EMP1042')),
    'and nobody outside its units',
);
ok(
    DataScope::of('unit_list', ['TEAM-B'], includeDescendants: false)->covers($hyderabadSubject('EMP1042')),
    'exact unit_list matching checks every unit type the subject carries',
);

// --- tenant, and the tenant boundary that is not a scope --------------------

$tenant = DataScope::tenantWide();

ok($tenant->covers($chennaiSubject), 'a tenant scope reaches every employee of the tenant');
ok(
    !$tenant->covers(new SubjectContext(tenantId: 'T9', actorTenantId: 'T7', actorEmployeeId: 'EMP1000', employeeId: 'X')),
    'and stops at the tenant boundary — tenancy is not a scope, and the widest scope is still inside one',
);

// --- platform (R-XXII-048) ---------------------------------------------------

$platform = DataScope::platformWide();

ok(!$platform->covers($chennaiSubject), 'a platform scope reaches nothing from inside a customer tenant');
ok(
    $platform->covers(new SubjectContext(
        tenantId: 'T9',
        actorTenantId: 'PLATFORM',
        actorEmployeeId: 'STAFF1',
        employeeId: 'EMP0001',
        actorInPlatformTenant: true,
    )),
    'and everything from inside the platform tenant',
);

// --- a declared type with no resolver ---------------------------------------

throws(
    ScopeResolutionFailed::class,
    static fn () => DataScope::of('dimension_filter')->covers($hyderabadSubject('EMP1042')),
    'dimension_filter is declared in access_scope_types but unresolvable in this build — so it throws, and denies',
);
throws(
    ScopeResolutionFailed::class,
    static fn () => DataScope::of('dimension_filter')->predicate($hyderabadSubject('EMP1042')),
    'and it refuses to emit a set expression rather than emitting an unscoped one',
);

// --- requiresSubject ---------------------------------------------------------

ok(DataScope::of('branch', ['BR-HYD'])->requiresSubject(), 'a unit scope needs a placeable subject');
ok($self->requiresSubject(), 'so does self');
ok(!$tenant->requiresSubject(), 'a tenant scope does not — it narrows nothing below the tenant');
ok(!$platform->requiresSubject(), 'nor does platform');

// --- the persistence-layer predicate (R-XXII-041) ---------------------------

same(
    [
        'scope' => 'branch',
        'scope_key' => 'HYDERABAD_BRANCH',
        'scope_version' => 2,
        'org_units' => ['BR-HYD'],
        'include_descendants' => true,
    ],
    $hyderabadBranch->predicate($hyderabadSubject('EMP1042')),
    'a unit scope compiles to a set expression naming units and the descendant flag, never a materialised id list',
);
same(
    [
        'scope' => 'reporting_tree',
        'scope_key' => 'REPORTING_TREE',
        'scope_version' => 1,
        'manager_id' => 'EMP1000',
        'depth' => 3,
        'include_self' => false,
    ],
    DataScope::of('reporting_tree', depth: 3)->predicate($hyderabadSubject('EMP1042')),
    'a reporting_tree predicate carries the actor, the depth and the include_self flag',
);
same(
    ['scope' => 'self', 'scope_key' => 'SELF', 'scope_version' => 1, 'employee_id' => 'EMP1000'],
    $self->predicate($hyderabadSubject('EMP1042')),
    'a self predicate narrows to the actor\'s own employee id, resolved from the session',
);
same(
    ['scope' => 'tenant', 'scope_key' => 'TENANT', 'scope_version' => 1, 'tenant_id' => 'T7'],
    $tenant->predicate($hyderabadSubject('EMP1042')),
    'a tenant predicate is the tenant column and nothing else',
);

same('HYDERABAD_BRANCH v2 (branch)', (string) $hyderabadBranch, 'a scope names its version for the trace (R-XXII-271)');
