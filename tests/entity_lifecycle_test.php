<?php

declare(strict_types=1);

use SmartPRS\Platform\Entity\AuthoringType;
use SmartPRS\Platform\Entity\ChangeValue;
use SmartPRS\Platform\Entity\EntityWriteService;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\FileValidation;
use SmartPRS\Platform\Entity\StatusTransition;
use SmartPRS\Platform\Entity\StorageKind;
use SmartPRS\Platform\Entity\Surface;
use SmartPRS\Platform\Entity\SurfaceRule;
use SmartPRS\Platform\Entity\SurfaceRuleResolver;
use SmartPRS\Platform\Entity\TransitionTable;
use SmartPRS\Platform\Entity\Visibility;
use SmartPRS\Platform\Entity\WriteCommand;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * THE EMPLOYEE STATE MACHINE AND FILE VALIDATION — Part X §X.7 and §X.3.2.
 *
 * Two SmartPRS 2 defects with one shape between them: something that should be
 * impossible is merely undocumented. Its `storeEmployee()` rewrites status on
 * every save, so an exited employee is reset to active by an unrelated edit; and
 * its uploads are checked by filename only, so `payload.php` renamed to
 * `photo.png` is a photo.
 */

group('Employment lifecycle and file validation (Part X)');

$table = new TransitionTable(
    [
        new StatusTransition('pre_hire', 'active', 'employee.activate',
            requiresReason: false, guardExpression: 'employee.joining_date <= TODAY()'),
        new StatusTransition('active', 'on_notice', 'employee.status.change', requiresReason: true),
        new StatusTransition('active', 'exited', 'employee.exit', requiresReason: true,
            workflowArtefactKey: 'EMP-EXIT'),
        new StatusTransition('on_notice', 'exited', 'employee.exit', requiresReason: true,
            workflowArtefactKey: 'EMP-EXIT'),
        new StatusTransition('exited', 'active', 'employee.rehire', requiresReason: true,
            guardExpression: 'employment.is_rehire_eligible', workflowArtefactKey: 'EMP-REHIRE'),
        new StatusTransition(StatusTransition::ANY, 'archived', 'employee.archive', requiresReason: true,
            guardExpression: 'NOT employment.has_open_payroll_input'),
    ],
    [
        'pre_hire' => ['consumes_seat' => false, 'payroll_eligible' => false, 'login_enabled' => false],
        'active' => ['consumes_seat' => true, 'payroll_eligible' => true, 'login_enabled' => true],
        'on_notice' => ['consumes_seat' => true, 'payroll_eligible' => true],
        'exited' => ['consumes_seat' => false, 'payroll_eligible' => false],
        'archived' => ['consumes_seat' => false],
    ],
);

$all = ['employee.activate', 'employee.status.change', 'employee.exit', 'employee.rehire', 'employee.archive'];
$today = new DateTimeImmutable('2026-09-04');
$holds = static fn (string $guard): ?bool => true;

// =============================================================================
// A TRANSITION NOT IN THE TABLE IS IMPOSSIBLE
// =============================================================================

$impossible = $table->decide('archived', 'exited', $all, 'because', $today, $holds);
ok(!$impossible['allowed'], 'a move the table does not declare cannot be made');
ok(str_contains($impossible['why'], 'not a permission you can be granted'),
    'and the refusal says so: this is not access somebody can be given, it is a move the lifecycle lacks');
ok(str_contains($impossible['why'], 'archived'), 'while naming what IS declared from here, so the answer is useful');

same(['active', 'archived'], $table->from('exited'),
    'an exited employment can only be rehired or archived — SmartPRS 2 lets any form save reset it to active');
ok(in_array('archived', $table->from('active'), true), 'the wildcard row applies from every status');

// =============================================================================
// PERMISSION, REASON, DATE, GUARD — FOUR WAYS TO SAY NO
// =============================================================================

ok(!$table->decide('active', 'on_notice', ['employee.update'], 'resigned', $today, $holds)['allowed'],
    'the wrong permission is refused');
ok(!$table->decide('active', 'on_notice', $all, null, $today, $holds)['allowed'],
    'a transition declaring a reason is refused without one');
ok(str_contains($table->decide('active', 'on_notice', $all, '  ', $today, $holds)['why'], 'nobody can explain'),
    'and whitespace is not a reason');
ok(!$table->decide('active', 'on_notice', $all, 'resigned', null, $holds)['allowed'],
    'an effective-dated transition with no date is refused: without one the change is "now"');

$failed = $table->decide('active', 'archived', $all, 'old data', $today,
    static fn (string $g): ?bool => false);
ok(!$failed['allowed'], 'a guard that does not hold refuses');

$unevaluable = $table->decide('active', 'archived', $all, 'old data', $today,
    static fn (string $g): ?bool => null);
ok(!$unevaluable['allowed'], 'and a guard that cannot be evaluated ALSO refuses');
ok(str_contains($unevaluable['why'], 'fails open is not a guard'),
    'because a guard that fails open is not a guard — the SmartPRS 2 pattern this whole part is written against');

ok(!$table->decide('active', 'archived', $all, 'old data', $today)['allowed'],
    'and a guarded transition with no evaluator supplied is refused rather than assumed');

// A permitted one.
$ok = $table->decide('pre_hire', 'active', $all, null, $today, $holds);
ok($ok['allowed'], 'a declared, permitted, guarded transition is allowed');
same(null, $ok['workflow'], 'and takes effect directly when no workflow is declared');

// =============================================================================
// A WORKFLOW-ROUTED TRANSITION DOES NOT TAKE EFFECT HERE
// =============================================================================

$service = new EntityWriteService(new SurfaceRuleResolver([]), [], [],
    static fn (array $r, string $p): bool => true);

$applied = false;
$exit = $service->transition($table, 'active', 'exited', $all, $today, 'resigned', $holds,
    static function () use (&$applied): void { $applied = true; });

ok(!$exit['applied'], 'a transition routed to a workflow is NOT applied by the caller');
same('EMP-EXIT', $exit['workflow'], 'it is raised, and the workflow owns what happens next');
ok(!$applied, 'and persistence is not reached');

$direct = $service->transition($table, 'active', 'on_notice', $all, $today, 'resigned', $holds,
    static function () use (&$applied): void { $applied = true; });
ok($direct['applied'] && $applied, 'an unrouted one applies');

// =============================================================================
// R-X-058 — STATUS IS NOT A FIELD, AND NO RULE CAN MAKE IT ONE
// =============================================================================

$open = new EntityWriteService(
    // A surface rule that says WRITE on `status`, which is the configuration
    // somebody would eventually write. It must still be refused.
    new SurfaceRuleResolver([new SurfaceRule('status', Surface::Edit, null, Visibility::Write)]),
    ['status' => new FieldDefinition(
        fieldKey: 'status',
        label: 'Status',
        description: 'A definition for a field the write path refuses regardless.',
        authoringType: AuthoringType::Text,
        storageKind: StorageKind::Attribute,
        groupKey: 'employment',
        displayOrder: 1,
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'status-v1',
    )],
    [],
    static fn (array $r, string $p): bool => true,
);

$result = $open->write(
    new WriteCommand('employee', 'e-1', Surface::Edit, '201', ['HR'],
        ['status' => ChangeValue::set('active')], $today),
    [],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 2],
);

ok(!$result->applied, 'R-X-058: a status supplied as a field is refused even where a rule grants write');
ok(str_contains($result->outcomeFor('status')->detail, 'transition table'),
    'and is told where status changes actually happen');
ok(str_contains($result->outcomeFor('status')->detail, 'undo an exit'),
    'naming the consequence: a save that could carry status is a save that could undo an exit');

// =============================================================================
// SEATS — R-X-056 AND R-X-057
// =============================================================================

same(7, $table->seatsUsed(['active' => 5, 'on_notice' => 2, 'exited' => 40, 'archived' => 100]),
    'seat usage is computed from the status declaration, so every consumer gets the same number');
ok(!$table->consumesSeat('archived'), 'archiving frees a seat, which is what the product already tells customers');
ok($table->consumesSeat('a_status_nobody_declared'),
    'and an UNDECLARED status consumes one: the conservative default, because the other way leaks revenue '
    . 'through a status somebody added without thinking about licensing');
ok(!$table->isPayrollEligible('exited'), 'payroll eligibility is a declaration too');
ok(!$table->isLoginEnabled('on_notice'),
    'and so is login, which defaults to OFF for a status that does not say');

same([], $table->terminalStatuses(['active', 'exited']),
    'no status here is terminal, and the table can be checked for one at publish');
same(['archived'], $table->terminalStatuses(['active', 'archived']),
    'a status nothing leads out of is found before an employment is stuck in it');

throws(InvalidArgumentException::class,
    static fn () => new StatusTransition('active', 'active', 'x'),
    'a transition to itself is not a transition');
throws(InvalidArgumentException::class,
    static fn () => new StatusTransition('active', StatusTransition::ANY, 'x'),
    'and the wildcard is only valid as the FROM end');
throws(InvalidArgumentException::class,
    static fn () => new StatusTransition('active', 'exited', ''),
    'every status change is permissioned; a blank permission is the change anybody can make');

// =============================================================================
// R-X-028 — CONTENT DECIDES THE TYPE, THE FILENAME DECIDES NOTHING
// =============================================================================

$photo = new FieldDefinition(
    fieldKey: 'photo',
    label: 'Photo',
    description: 'A photo field for the Part X file suite, checked by content.',
    authoringType: AuthoringType::Photo,
    storageKind: StorageKind::Attribute,
    groupKey: 'personal',
    displayOrder: 1,
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: 'photo-v1',
    constraints: ['max_bytes' => 4096, 'min_bytes' => 8],
);

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);

$accepted = FileValidation::check($photo, $png, 'ejaz.png');
ok($accepted['ok'], 'a genuine PNG named .png is accepted');
same('image/png', $accepted['mime'], 'and its type comes from the bytes');

$disguised = FileValidation::check($photo, '<?php system($_GET[0]); ?>', 'ejaz.png');
ok(!$disguised['ok'], 'a PHP file named .png is not a photo — SmartPRS 2 checks the extension only');
ok(str_contains($disguised['error'], 'name of the file did not come into it'), 'and says why');

ok(!FileValidation::check($photo, $png, 'ejaz.exe')['ok'],
    'a genuine PNG named .exe is refused too: content decides what it IS, the extension decides what '
    . 'everything downstream treats it as');
ok(!FileValidation::check($photo, str_repeat($png, 200), 'big.png')['ok'], 'the declared size cap is enforced');
ok(!FileValidation::check($photo, 'ab', 'tiny.png')['ok'],
    'and a file too small to be what it claims is a failed upload, not a small file');
ok(!FileValidation::check($photo, '', 'empty.png')['ok'], 'an empty upload is refused');

foreach (['pending', 'infected', 'error'] as $verdict) {
    ok(!FileValidation::isServable($verdict), "a '{$verdict}' file is not served");
    ok(FileValidation::quarantineReason($verdict) !== '', "and the person who uploaded it is told why ({$verdict})");
}

ok(FileValidation::isServable('clean'), 'only a clean file is served');
ok(str_contains(FileValidation::quarantineReason('error'), 'not a pass'),
    'a scanner failure holds the file: an outage that released files would be the outage worth causing');
throws(InvalidArgumentException::class,
    static fn () => FileValidation::quarantineReason('probably_fine'),
    'and an unknown verdict is not a synonym for clean');

same(32, count(AuthoringType::available()), 'all thirty-two authoring types are now selectable');
same([], AuthoringType::incomplete(), 'and none is declared-but-incomplete');
