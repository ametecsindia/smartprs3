<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\Operation;
use SmartPRS\Platform\Access\OperationRegistry;
use SmartPRS\Platform\Access\OperationSweep;
use SmartPRS\Platform\Access\PermissionCatalogue;

/**
 * LAW 5's GATE — Part XXII §XXII.4.2.
 *
 * Every other law in spine S1 has a mechanical check in tools/gates/. Law 5 —
 * authorisation is server-side and fails closed — did not, because its check
 * needs the live route list and the permission catalogue rather than a
 * tokeniser. A law without a gate is a wish, so here it is.
 */

group('Operation registry and the Law 5 sweep');

$catalogue = PermissionCatalogue::seeded();

function op(array $overrides = []): Operation
{
    return new Operation(...array_merge([
        'code' => 'payroll.run.approve',
        'httpBindings' => ['POST /api/v3/payroll/runs/{uuid}/approve'],
        'requiredPermission' => 'payroll.run.approve',
        'subjectKind' => 'tenant',
        'subjectResolver' => 'run.tenant',
        'scopeRequirement' => 'not_applicable',
        'auditClass' => 'write',
    ], $overrides));
}

// =============================================================================
// R-XXII-073 — THE REGISTRY ENTRY
// =============================================================================

$operation = op();
same('payroll.run.approve', $operation->code, 'an operation names itself');
ok(str_contains($operation->describe(), 'POST /api/v3'), 'and the route it binds');

throws(
    InvalidArgumentException::class,
    static fn () => op(['httpBindings' => []]),
    'an operation binds at least one route, or it is a registry entry for nothing',
);

throws(
    InvalidArgumentException::class,
    static fn () => op(['requiredPermission' => '']),
    'R-XXII-074: an authenticated operation names a permission — there is no "public unless stated"',
);

throws(
    InvalidArgumentException::class,
    static fn () => op(['assurance' => 'sometimes']),
    "assurance is none, mfa or reauth(<seconds>) — not a free string",
);

same(300, op(['assurance' => 'reauth(300)'])->reauthSeconds(), 'reauth carries its window');
ok(op(['assurance' => 'reauth(300)'])->requiresReauth(), 'and says so');

// Two operations on one route is two answers to "what may this caller do here".
$registry = new OperationRegistry([op()]);

throws(
    InvalidArgumentException::class,
    static fn () => $registry->register(op(['code' => 'payroll.run.approve_again'])),
    'a route bound twice would let load order decide the authorisation',
);

throws(
    InvalidArgumentException::class,
    static fn () => (new OperationRegistry([op()]))->register(op()),
    'and a duplicate operation code is refused',
);

// =============================================================================
// R-XXII-074 — A ROUTE WITH NO OPERATION FAILS THE BUILD
// =============================================================================

$bound = new OperationRegistry([op()]);

$covered = OperationSweep::run($bound, liveRoutes: ['POST /api/v3/payroll/runs/{uuid}/approve']);
same('pass', $covered->check('OP1')?->severity, 'every route resolving to an operation passes');

$uncovered = OperationSweep::run($bound, liveRoutes: [
    'POST /api/v3/payroll/runs/{uuid}/approve',
    'GET /api/v3/employees/{uuid}/salary',
]);
$op1 = $uncovered->check('OP1');
ok($op1?->blocks(), 'R-XXII-074: a route resolving to no operation blocks the build');
ok(
    str_contains(implode(' ', $op1->messages ?? []), 'salary'),
    'and names it — this is one of SmartPRS 2\'s 78 authenticated routes with no server-side check',
);

// The sweep with no route list is the one that must never say pass.
$blind = OperationSweep::run($bound);
same('warn', $blind->check('OP1')?->severity, 'a sweep with no route list records NOT CHECKED');
ok(
    str_contains(implode(' ', $blind->check('OP1')->messages ?? []), 'THE Law 5 assertion'),
    'and says which assertion is not running',
);

// =============================================================================
// R-XXII-075 — THE UNAUTHENTICATED SURFACE IS AN EXPLICIT ALLOW-LIST
// =============================================================================

$withPublic = new OperationRegistry([
    op(),
    op([
        'code' => 'platform.health.read',
        'httpBindings' => ['GET /api/v3/health'],
        'requiredPermission' => '',
        'unauthenticated' => true,
        'auditClass' => 'none',
    ]),
]);

same(['GET /api/v3/health'], $withPublic->unauthenticatedRoutes(),
    'the registry can state its unauthenticated surface — SmartPRS 2 cannot state its 78');

$matching = OperationSweep::run($withPublic, unauthenticatedAllowList: ['GET /api/v3/health']);
same('pass', $matching->check('OP2')?->severity, 'a surface equal to the allow-list passes');

$drifted = OperationSweep::run($withPublic, unauthenticatedAllowList: []);
$op2 = $drifted->check('OP2');
ok($op2?->blocks(), 'an unauthenticated route not on the list blocks until the list is amended');
ok(
    str_contains(implode(' ', $op2->messages ?? []), 'reviewed as a unit'),
    'because the point of the list is that somebody reads the whole surface at once',
);

$stale = OperationSweep::run($bound, unauthenticatedAllowList: ['GET /api/v3/gone']);
ok($stale->check('OP2')?->blocks(), 'and a stale entry blocks too, or the list stops describing anything');

// =============================================================================
// R-XXII-076 — THE EIGHT ASSERTIONS
// =============================================================================

// Permission existence.
$unknownPermission = new OperationRegistry([op(['requiredPermission' => 'payroll.run.approve_everything'])]);
ok(
    OperationSweep::run($unknownPermission, $catalogue)->check('OP3')?->blocks(),
    'an operation naming a permission that is not in the catalogue blocks',
);
same('pass', OperationSweep::run($bound, $catalogue)->check('OP3')?->severity,
    'while a real permission passes');

// Subject resolvability.
$unscopable = new OperationRegistry([op(['scopeRequirement' => 'required', 'subjectKind' => 'none'])]);
$op4 = OperationSweep::run($unscopable)->check('OP4');
ok($op4?->blocks(), 'scope_requirement required with subject_kind none blocks');
ok(
    str_contains(implode(' ', $op4->messages ?? []), 'blindly'),
    'because there is nothing to scope the decision against',
);

$noResolver = new OperationRegistry([op([
    'scopeRequirement' => 'required',
    'subjectKind' => 'employee',
    'subjectResolver' => null,
])]);
ok(OperationSweep::run($noResolver)->check('OP4')?->blocks(), 'and so does a required scope with no resolver');

// Scope declaration parity, in both directions.
$scoped = ['payroll.run.approve'];

$missingScope = OperationSweep::run($bound, scopedPermissions: $scoped)->check('OP5');
ok($missingScope?->blocks(), 'a scoped permission bound to a not_applicable operation blocks');
ok(
    str_contains(implode(' ', $missingScope->messages ?? []), 'silently not be applied'),
    'because the scope would be declared and then never used',
);

$reverse = new OperationRegistry([op([
    'scopeRequirement' => 'required',
    'subjectKind' => 'employee',
    'subjectResolver' => 'employee.uuid',
])]);
ok(
    OperationSweep::run($reverse, scopedPermissions: [])->check('OP5')?->blocks(),
    'and the reverse — declaring a scope for an unscoped permission — blocks as well',
);

// CSRF justification.
$exempt = new OperationRegistry([op(['csrfExempt' => true])]);
$op6 = OperationSweep::run($exempt)->check('OP6');
ok($op6?->blocks(), 'a CSRF-exempt route with no compensating control blocks');
ok(
    str_contains(implode(' ', $op6->messages ?? []), 'interakt'),
    "naming the SmartPRS 2 exemption whose route comment offers no control at all",
);

$justified = new OperationRegistry([op([
    'csrfExempt' => true,
    'csrfExemptReason' => 'HMAC signature verified against the shared secret before the body is read',
])]);
same('pass', OperationSweep::run($justified)->check('OP6')?->severity,
    'while a declared compensating control passes');

// Privileged assurance.
$privileged = OperationSweep::run($bound, privilegedPermissions: ['payroll.run.approve'])->check('OP7');
ok($privileged?->blocks(), 'a privileged permission with assurance none blocks');
ok(
    str_contains(implode(' ', $privileged->messages ?? []), 'stolen session'),
    'because that is exactly what assurance is for',
);

$reauthed = new OperationRegistry([op(['assurance' => 'reauth(300)'])]);
same('pass',
    OperationSweep::run($reauthed, privilegedPermissions: ['payroll.run.approve'])->check('OP7')?->severity,
    'and a re-authentication requirement satisfies it');

// Maker-checker parity.
$declaredMakerChecker = OperationSweep::run($bound, makerCheckerOperations: ['payroll.run.approve'])->check('OP8');
ok($declaredMakerChecker?->blocks(), 'an operation declared maker-checker with no workflow binding blocks');
ok(
    str_contains(implode(' ', $declaredMakerChecker->messages ?? []), 'on paper only'),
    'because a control nothing enforces is not a control',
);

$boundWorkflow = new OperationRegistry([op(['makerChecker' => 'PAYROLL-RUN-APPROVE'])]);
same('pass',
    OperationSweep::run($boundWorkflow, makerCheckerOperations: ['payroll.run.approve'])->check('OP8')?->severity,
    'while a bound workflow satisfies it');

$missingEntirely = OperationSweep::run($bound, makerCheckerOperations: ['payroll.run.unapprove'])->check('OP8');
ok($missingEntirely?->blocks(), 'and an operation declared maker-checker that is not registered at all blocks');

// Audit class.
$unaudited = new OperationRegistry([op(['auditClass' => 'none'])]);
$op9 = OperationSweep::run($unaudited, writingOperations: ['payroll.run.approve'])->check('OP9');
ok($op9?->blocks(), 'an operation that writes and declares audit_class none blocks');
ok(
    str_contains(implode(' ', $op9->messages ?? []), 'no trace'),
    'because a change with no trace is not defensible three years later (Law 4)',
);

same('pass', OperationSweep::run($bound, writingOperations: ['payroll.run.approve'])->check('OP9')?->severity,
    'while a write with a declared audit class passes');

// =============================================================================
// THE SWEEP AS A WHOLE
// =============================================================================

$full = OperationSweep::run(
    $withPublic,
    $catalogue,
    liveRoutes: ['POST /api/v3/payroll/runs/{uuid}/approve', 'GET /api/v3/health'],
    unauthenticatedAllowList: ['GET /api/v3/health'],
    scopedPermissions: [],
    privilegedPermissions: [],
    makerCheckerOperations: [],
    writingOperations: [],
);

ok($full->isPublishable(), 'a fully-supplied sweep over a clean registry passes every assertion');
same(9, count($full->checks), 'and records all nine outcomes, so a failure names which property broke');
same(0, count($full->ofSeverity('warn')), 'with nothing left NOT CHECKED when everything was supplied');

$partial = OperationSweep::run($withPublic);
ok(count($partial->ofSeverity('warn')) >= 6, 'while a sweep supplied with nothing warns rather than passing');
ok($partial->isPublishable(), 'a NOT CHECKED assertion does not block — it is visible, which is the point');
