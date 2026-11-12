<?php

declare(strict_types=1);

use SmartPRS\Platform\Access\Permission;
use SmartPRS\Platform\Access\PermissionCatalogue;
use SmartPRS\Platform\Access\Role;
use SmartPRS\Platform\Access\UnknownPermission;

group('Permission');

// --- the grammar of §XXII.2.1 ----------------------------------------------

same('payroll.run.approve', Permission::of('payroll.run.approve')->code, 'a three-segment code parses');
same('payroll', Permission::of('payroll.run.approve')->module(), 'the module is the first segment');
same('rules', Permission::of('rules.publish')->module(), 'two segments are legal — rules.publish is a real code');
same(
    'payroll.approve.finance',
    Permission::of('payroll.approve.finance')->code,
    'the hierarchical form of spine S13 parses',
);
same(
    ['employee', 'field', 'sensitive', 'view'],
    Permission::of('employee.field.sensitive.view')->segments,
    'a qualifier segment is carried, not flattened',
);
ok(
    Permission::of('attendance.day.view.history')->code === 'attendance.day.view.history',
    'a qualifier AFTER the verb parses — position is not a verb test (21 real codes look like this)',
);

// --- malformed codes are rejected at construction, every one ----------------

$malformed = [
    '' => 'the empty string',
    'payroll' => 'a single segment is not verb.resource',
    'payroll.' => 'a trailing dot',
    '.payroll.run' => 'a leading dot',
    'payroll..run' => 'an empty segment',
    'Payroll.Run.Approve' => 'uppercase — codes are lowercase, and case-insensitive comparison is how duplicates hide',
    'payroll.run approve' => 'whitespace inside the code',
    ' payroll.run.approve' => 'leading whitespace',
    'payroll.run.approve ' => 'trailing whitespace',
    'payroll-run-approve' => 'hyphens instead of dots',
    'payroll.run.1approve' => 'a segment starting with a digit',
    'payroll.run.approve.finance.south.hyderabad' => 'six segments — the namespace is bounded',
    'payroll/run/approve' => 'a path, not a permission code',
    'payroll.run.*' => 'a wildcard — a permission is a code, never a pattern',
];

foreach ($malformed as $code => $why) {
    throws(
        InvalidArgumentException::class,
        static fn () => Permission::of($code),
        'rejected: ' . $why,
    );
}

// --- comparison -------------------------------------------------------------

ok(Permission::of('payroll.run.view')->equals('payroll.run.view'), 'equal codes compare equal');
ok(!Permission::of('payroll.run.view')->equals('payroll.run.approve'), 'different codes do not');
ok(
    !Permission::of('payroll.approve')->equals('payroll.approve.finance'),
    'a lexical prefix is NOT the same permission — implication is declared, never inferred',
);
ok(
    Permission::of(Permission::of('rules.publish'))->equals('rules.publish'),
    'of() is idempotent — it accepts a Permission and returns it',
);

// --- the catalogue: path A, declared entries --------------------------------

group('PermissionCatalogue');

$catalogue = PermissionCatalogue::seeded();

// 543 from the blueprint register, plus 6 added for screens built since (catalogue.php, 17 Sep).
same(558, count($catalogue->declaredCodes()), '558 declared entries: the blueprint register\'s 543 plus 6 since, plus 9 menu-access codes (22 Oct 2026)');

// The engine capabilities over reports, dashboards and forms live under the
// PLURAL prefixes; the singular namespaces are tenant-minted only (R-XXII-010a).
ok($catalogue->contains('reporting.run'), 'the engine report-run capability is declared');
ok($catalogue->contains('dashboards.view'), 'the engine dashboard-view capability is declared');
ok($catalogue->contains('forms.definition.manage'), 'the engine form-authoring capability is declared');
foreach ($catalogue->declaredCodes() as $code) {
    if (in_array(explode('.', $code, 2)[0], ['form', 'report', 'dashboard'], true)) {
        ok(false, "no declared entry sits in a reserved namespace — $code does");
    }
}
ok(true, 'no declared entry sits in a reserved namespace (R-XXII-010a)');

ok($catalogue->contains('payroll.run.approve'), 'a declared code passes');
ok($catalogue->contains('employee.field.sensitive.view'), 'a declared four-segment code passes');
ok($catalogue->contains('rules.artefact.publish'), 'a declared code from Part V passes');
ok(!$catalogue->contains('payroll.run.annihilate'), 'an undeclared code is rejected');
ok(
    !$catalogue->contains('payroll.run.approve.hyderabad'),
    'R-XXII-013: a code encoding a record set is not in the catalogue and therefore denies',
);

same(
    ['module' => 'payroll', 'parts' => 'XXII, XXIV', 'group' => 'Payroll'],
    $catalogue->describe('payroll.run.approve'),
    'a declared entry carries its module, its declaring parts and its catalogue group',
);
same(null, $catalogue->describe('payroll.run.annihilate'), 'an undeclared code describes as nothing');

throws(
    UnknownPermission::class,
    static fn () => $catalogue->assertKnown('payroll.run.annihilate'),
    'assertKnown() throws on an undeclared code',
);
$catalogue->assertKnown('payroll.run.approve');
ok(true, 'assertKnown() is silent on a declared code');

// --- the catalogue: path B, reserved generative namespaces ------------------

ok($catalogue->isGenerative('form.leave_extension.submit'), 'form.* is a reserved generative namespace');
ok($catalogue->isGenerative('report.headcount.run'), 'report.* is one');
ok($catalogue->isGenerative('dashboard.attrition.view'), 'dashboard.* is one');
ok(!$catalogue->isGenerative('payroll.run.approve'), 'payroll.* is not');
ok(!$catalogue->isGenerative('forms.definition.manage'), 'forms.* is not form.* — the set is closed (R-XXII-010c)');

// A published artefact repository: the tenant has published form `leave_extension`
// and report `headcount`, and nothing else.
$published = static fn (string $kind, string $key): bool => match ("$kind.$key") {
    'form.leave_extension', 'report.headcount' => true,
    default => false,
};

$live = PermissionCatalogue::seeded($published);

ok(
    $live->contains('form.leave_extension.submit'),
    'a generative form.* code from a PUBLISHED artefact passes',
);
ok($live->contains('report.headcount.export'), 'a generative report.* code from a published artefact passes');
ok(
    !$live->contains('form.salary_disclosure.submit'),
    'a generative-looking code with no artefact behind it is rejected — the shape proves nothing',
);
ok(
    !$live->contains('form.leave_extension.impersonate'),
    'a published artefact mints only the verbs its generic operation serves',
);
ok(
    !$live->contains('form.leave_extension.other.submit'),
    'the mint shape is exactly <kind>.<key>.<verb>; four segments mint nothing',
);
ok(
    !$catalogue->contains('form.leave_extension.submit'),
    'with no artefact repository wired, every generative code is unprovable and denies — fail closed',
);

throws(
    UnknownPermission::class,
    static fn () => $live->assertKnown('form.salary_disclosure.submit'),
    'assertKnown() throws on an unminted generative code',
);

// R-XXII-010a: the catalogue validator rejects a seeded row in a reserved namespace.
throws(
    InvalidArgumentException::class,
    static fn () => new PermissionCatalogue(['report.definition.manage' => ['report', 'XXVI', '']]),
    'a DECLARED row inside a reserved namespace is rejected — R-XXII-010a forbids seeding one',
);

foreach ($catalogue->declaredCodes() as $code) {
    if ($catalogue->isGenerative($code)) {
        ok(false, "the shipped seed carries a reserved-namespace row: {$code}");
    }
}
ok(true, 'the shipped seed carries no reserved-namespace row');

// --- Role -------------------------------------------------------------------

group('Role');

$branchHr = Role::publish('BRANCH_HR', 'Branch HR', 4, false, [
    'employee.record.update',
    'leave.request.decide',
], catalogue: $catalogue);

ok($branchHr->grants('employee.record.update'), 'a role grants what it was published with');
ok(!$branchHr->grants('payroll.run.approve'), 'and nothing else');
ok(
    !$branchHr->grants('employee.record.update.salary'),
    'no prefix matching — a longer code is a different permission',
);
same(4, $branchHr->version, 'a role is versioned (R-XXII-019)');
same(false, $branchHr->isSystem, 'and knows whether it is Ametecs-owned or the tenant\'s');

throws(
    UnknownPermission::class,
    static fn () => Role::publish('BAD', 'Bad', 1, false, ['payroll.run.annihilate'], catalogue: $catalogue),
    'publishing a role with an uncatalogued permission fails at publication, not at request time',
);

// R-XXII-037: implies[] is closed at publication and materialised.
$implies = [
    'payroll.run.approve' => ['payroll.run.view'],
    'payroll.run.view' => ['payroll.line.view'],
];

$approver = Role::publish('PAY_APPROVER', 'Payroll Approver', 1, true, ['payroll.run.approve'], $implies, $catalogue);

ok($approver->grants('payroll.run.approve'), 'the directly granted permission is held');
ok($approver->grants('payroll.run.view'), 'implies[] is closed: approve implies view');
ok($approver->grants('payroll.line.view'), 'and transitively: view implies line.view');
ok(!$approver->grants('payroll.run.reject'), 'the closure adds only what implies[] declares');
same(
    ['payroll.line.view', 'payroll.run.approve', 'payroll.run.view'],
    $approver->permissionCodes(),
    'the effective set is flat and materialised, not walked at request time',
);

throws(
    InvalidArgumentException::class,
    static fn () => Role::publish('CYCLE', 'Cycle', 1, false, ['payroll.run.view'], [
        'payroll.run.view' => ['payroll.run.approve'],
        'payroll.run.approve' => ['payroll.run.view'],
    ], $catalogue),
    'R-XXII-014: a cycle in implies[] is a build failure, not an infinite walk',
);
