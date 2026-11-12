<?php

declare(strict_types=1);

use SmartPRS\Platform\Entity\AuthoringType;
use SmartPRS\Platform\Entity\ChangeValue;
use SmartPRS\Platform\Entity\EntityWriteService;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\FieldOutcome;
use SmartPRS\Platform\Entity\Requiredness;
use SmartPRS\Platform\Entity\StorageKind;
use SmartPRS\Platform\Entity\Surface;
use SmartPRS\Platform\Entity\SurfaceRule;
use SmartPRS\Platform\Entity\SurfaceRuleResolver;
use SmartPRS\Platform\Entity\ValueCoercion;
use SmartPRS\Platform\Entity\Visibility;
use SmartPRS\Platform\Entity\WriteCommand;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Scope;

/**
 * THE ENTITY SCHEMA ENGINE — Part X, Phase 3.
 *
 * The four properties this file exists to hold down, each one a SmartPRS 2
 * defect the blueprint names:
 *
 *   PATCH, not replace. A field absent from the command is untouched, and
 *   clearing is explicit (R-X-036). The SmartPRS 2 employee save NULLs every
 *   omitted key.
 *
 *   Every field gets an outcome, including the refused ones (R-X-038). Four
 *   SmartPRS 2 importers drop unknown columns by four different mechanisms and
 *   none says so.
 *
 *   Resolution fails closed at every step (R-X-030). `denyUnlessRole()` there
 *   catches Throwable and returns null, where null means ALLOWED.
 *
 *   A file round-trips (R-X-026). SmartPRS 2 exports three date fields as
 *   `Y-m-d` under a `(DD-MM-YYYY)` header.
 */

group('Entity schema engine (Part X)');

$range = EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01'));

$field = static fn (string $key, AuthoringType $type, array $overrides = []): FieldDefinition => new FieldDefinition(
    fieldKey: $key,
    label: $overrides['label'] ?? ucfirst(str_replace('_', ' ', $key)),
    description: $overrides['description'] ?? 'A field declared for the Part X test suite, in the tenant\'s words.',
    authoringType: $type,
    storageKind: $overrides['storageKind'] ?? StorageKind::Attribute,
    groupKey: 'personal',
    displayOrder: 10,
    scope: Scope::tenantDefault(),
    range: $range,
    versionNo: 1,
    versionId: $key . '-v1',
    defaultVisibility: $overrides['defaultVisibility'] ?? Visibility::Hidden,
    defaultRequiredness: $overrides['defaultRequiredness'] ?? Requiredness::Never,
    piiClass: $overrides['piiClass'] ?? 'none',
    maskingRule: $overrides['maskingRule'] ?? 'none',
    valueSetKey: $overrides['valueSetKey'] ?? null,
    constraints: $overrides['constraints'] ?? [],
);

// =============================================================================
// THE CLOSED CATALOGUES
// =============================================================================

same(13, count(Surface::all()), 'R-X-029: thirteen surfaces, and the set is closed');
same(8, count(Surface::writable()), 'eight of them write; the other five only read');
ok(!Surface::Directory->writes(), 'the employee list does not save employees');
ok(!Surface::Payslip->writes(), 'and neither does a payslip');

// R-X-025: a type without all nine artefacts is not available for selection.
//
// Every one of the thirty-two is now complete, so there is no incomplete type
// left to assert the refusal against — and an assertion that needs a defect to
// exist is one that disappears the day the defect is fixed. What holds the rule
// down instead is `tools/phase3/exit-gate.php`, which PRODUCES each of the nine
// artefacts for each type and fails when one returns nothing. That check was
// verified by breaking the mask renderer and watching it go red, which is the
// property a passing check cannot demonstrate about itself.

same([], AuthoringType::incomplete(), 'every declared authoring type is complete');
same(32, count(AuthoringType::available()), 'so all thirty-two are selectable');

foreach (AuthoringType::cases() as $case) {
    same([], $case->missingArtefacts(), "{$case->value} is missing none of the nine artefacts");
}

// =============================================================================
// THE FIELD KEY CONTRACT (R-X-007)
// =============================================================================

throws(InvalidArgumentException::class, static fn () => $field('LinkedIn', AuthoringType::Url),
    'a field key is lower case: the key is the import header, the template token and the API property at once');
throws(InvalidArgumentException::class, static fn () => $field('9lives', AuthoringType::Text),
    'and starts with a letter');
throws(InvalidArgumentException::class, static fn () => $field('x', AuthoringType::Text),
    'and is not one character');

$linkedin = $field('linkedin_profile', AuthoringType::Url);
same("employee.custom('linkedin_profile')", $linkedin->spxSymbol(),
    'the same key is the SPX symbol a rule reads it through, so a custom field is type-checked at publish');
same('LINKEDIN PROFILE', $linkedin->columnHeader(),
    'and the import header and the export column are the same string, derived rather than maintained twice');

throws(
    InvalidArgumentException::class,
    static fn () => $field('aadhaar', AuthoringType::Text, ['piiClass' => 'restricted']),
    'a restricted field with no masking rule is refused: the class is a label unless a rule is attached to it',
);

throws(
    InvalidArgumentException::class,
    static fn () => $field('marital_status', AuthoringType::Dropdown),
    'and a dropdown with no value set has no allowed values to check against, so it accepts anything',
);

// =============================================================================
// SURFACE RULE RESOLUTION — R-X-030, FAILING CLOSED AT EVERY STEP
// =============================================================================

$salary = $field('previous_salary', AuthoringType::Currency, ['piiClass' => 'sensitive', 'maskingRule' => 'last4']);

$rules = new SurfaceRuleResolver([
    new SurfaceRule('previous_salary', Surface::Edit, 'HR', Visibility::Write, Requiredness::Always),
    new SurfaceRule('previous_salary', Surface::Edit, 'RECRUITER', Visibility::Read),
    new SurfaceRule('previous_salary', Surface::Ess, null, Visibility::Hidden),
    new SurfaceRule('previous_salary', Surface::Edit, 'PAYROLL', Visibility::Write, changeWorkflowKey: 'SALARY-CHANGE'),
]);

$hr = $rules->resolve($salary, Surface::Edit, ['HR']);
same(Visibility::Write, $hr->visibility, 'step 1: the role row wins');
same(Requiredness::Always, $hr->requiredness, 'with its requirement');

$both = $rules->resolve($salary, Surface::Edit, ['HR', 'RECRUITER', 'PAYROLL']);
same(Visibility::Write, $both->visibility,
    'across several roles the MOST PERMISSIVE visibility applies — access follows the role that grants it');
same(Requiredness::Always, $both->requiredness,
    'and the STRICTEST requirement — obligation follows the role that imposes it');
ok($both->requiresApproval(), 'and any one role requiring approval means approval');
same(['SALARY-CHANGE'], $both->changeWorkflowKeys, 'the workflows union rather than the last one winning');

same(Visibility::Hidden, $rules->resolve($salary, Surface::Ess, ['HR'])->visibility,
    'step 2: the all-roles row applies where no role row does — HR does not see salary on ESS');

$noRule = $rules->resolve($salary, Surface::Mobile, ['HR']);
same(Visibility::Hidden, $noRule->visibility,
    'step 4: absence of a rule NEVER grants access. SmartPRS 2 catches Throwable here and returns "allowed"');
ok(str_contains($noRule->why, 'Absence of a rule never grants access'),
    'and the resolution says which step produced the answer, so "why can I not edit this" has an answer');

same(Visibility::Hidden, $rules->resolve(null, Surface::Edit, ['HR'])->visibility,
    'an unresolvable field is hidden, and is indistinguishable from one the actor may not see');
same(Visibility::Hidden, $rules->resolve($salary, Surface::Edit, [])->visibility,
    'an actor with no roles is not "every role": the all-roles row is what every role may do');

throws(
    InvalidArgumentException::class,
    static fn () => new SurfaceRule('x', Surface::Directory, null, Visibility::Hidden, isFilterable: true),
    'R-X-032: a hidden field that can be filtered on is a field whose values are discoverable by guessing',
);

// =============================================================================
// THE WRITE PIPELINE
// =============================================================================

$fields = [
    'linkedin_profile' => $linkedin,
    'previous_salary' => $salary,
    'nickname' => $field('nickname', AuthoringType::Text, ['constraints' => ['max_length' => 12]]),
    'joined_on' => $field('joined_on', AuthoringType::Date),
    'is_fresher' => $field('is_fresher', AuthoringType::Boolean),
];

$writeRuleRows = [
    new SurfaceRule('linkedin_profile', Surface::Edit, null, Visibility::Write),
    new SurfaceRule('nickname', Surface::Edit, null, Visibility::Write),
    new SurfaceRule('joined_on', Surface::Edit, null, Visibility::Write, Requiredness::Always),
    new SurfaceRule('is_fresher', Surface::Edit, null, Visibility::Write),
    new SurfaceRule('previous_salary', Surface::Edit, 'HR', Visibility::Write, changeWorkflowKey: 'SALARY-CHANGE'),
    new SurfaceRule('nickname', Surface::Ess, null, Visibility::Read),
];

$writeRules = new SurfaceRuleResolver($writeRuleRows);

$service = new EntityWriteService(
    $writeRules,
    $fields,
    [],
    static fn (array $roles, string $permission): bool => in_array('HR', $roles, true),
);

$command = new WriteCommand(
    entityKey: 'employee',
    entityUuid: 'e-1',
    surface: Surface::Edit,
    actorUserId: '201',
    actorRoles: ['HR'],
    changes: [
        'linkedin_profile' => ChangeValue::set('https://in.linkedin.com/in/ejaz'),
        'nickname' => ChangeValue::set('EJ'),
        'is_fresher' => ChangeValue::set('No'),
    ],
    effectiveFrom: new DateTimeImmutable('2026-09-01'),
);

$result = $service->write($command, ['nickname' => 'EJ'], static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 2]);

ok($result->applied, 'the write applies');
same(3, count($result->outcomes), 'R-X-038: one outcome per field in the command, without exception');
same(FieldOutcome::APPLIED, $result->outcomeFor('linkedin_profile')->status, 'a new value is applied');
same(FieldOutcome::UNCHANGED, $result->outcomeFor('nickname')->status,
    'a supplied value that matches what is stored is UNCHANGED, which is a different fact from having omitted it');
same(false, $result->outcomeFor('is_fresher')->newValue,
    "'No' parses to the boolean false — a yes/no field never stores the string 'No'");

// R-X-036: PATCH. Nothing else moved.
same(null, $result->outcomeFor('joined_on'),
    'a field absent from the command has no outcome because it was not touched. The SmartPRS 2 save NULLs it');

// =============================================================================
// WHAT THE PIPELINE REFUSES
// =============================================================================

$readOnly = $service->write(
    new WriteCommand('employee', 'e-1', Surface::Ess, '201', ['HR'],
        ['nickname' => ChangeValue::set('Boss')], new DateTimeImmutable('2026-09-01')),
    [],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 3],
);

ok(!$readOnly->applied, 'R-X-031: a value for a read-only field is REJECTED, not ignored');
ok(
    str_contains($readOnly->outcomeFor('nickname')->detail, 'rejected rather than discarded'),
    'and the reason says so, because a user told nothing believes it saved',
);

$tooLong = $service->write(
    new WriteCommand('employee', 'e-1', Surface::Edit, '201', ['HR'],
        ['nickname' => ChangeValue::set('a name far too long for the declared limit')],
        new DateTimeImmutable('2026-09-01')),
    [],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 3],
);
ok(!$tooLong->applied, 'a validation failure fails the whole command');
ok(str_contains($tooLong->outcomeFor('nickname')->detail, 'at most 12'), 'naming the limit and the actual length');

$clearRequired = $service->write(
    new WriteCommand('employee', 'e-1', Surface::Edit, '201', ['HR'],
        ['joined_on' => ChangeValue::clear()], new DateTimeImmutable('2026-09-01')),
    ['joined_on' => new DateTimeImmutable('2020-01-06')],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 3],
);
ok(!$clearRequired->applied, 'clearing a required field is refused');
ok(
    str_contains($clearRequired->outcomeFor('joined_on')->detail, 'cannot be cleared'),
    'R-X-036: clearing is explicit precisely so the refusal can be too',
);

$denied = $service->write(
    new WriteCommand('employee', 'e-1', Surface::Edit, '999', ['INTERN'],
        ['nickname' => ChangeValue::set('X')], new DateTimeImmutable('2026-09-01')),
    [],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 3],
);
ok(!$denied->applied, 'an actor without the permission writes nothing');
ok(
    str_contains($denied->summary, 'nothing about the supplied values is disclosed'),
    'and is told nothing about whether their values would have been valid',
);

$noAuthoriser = new EntityWriteService($writeRules, $fields);
ok(
    !$noAuthoriser->write(
        new WriteCommand('employee', 'e-1', Surface::Edit, '201', ['HR'],
            ['nickname' => ChangeValue::set('X')], new DateTimeImmutable('2026-09-01')),
        [],
        static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 3],
    )->applied,
    'a service built with no authoriser DENIES — the safe reading of a missing check is that it failed',
);

// R-X-039: an approval-routed field does not reach persistence.
$routedResult = $service->write(
    new WriteCommand('employee', 'e-1', Surface::Edit, '201', ['HR'],
        ['previous_salary' => ChangeValue::set('750000.00')], new DateTimeImmutable('2026-09-01')),
    [],
    static function ($prepared): array {
        same([], array_keys($prepared->values), 'a routed field carries NO value into persistence (R-X-039)');

        return ['uuid' => 'e-1', 'row_version' => 3];
    },
);
same(FieldOutcome::ROUTED, $routedResult->outcomeFor('previous_salary')->status, 'it is held for approval');
same('SALARY-CHANGE', $routedResult->outcomeFor('previous_salary')->workflowKey, 'by the declared workflow');

// The preview is the same call with no persist.
$preview = $service->write($command, ['nickname' => 'EJ']);
ok(!$preview->applied, 'a preview writes nothing');
ok(
    str_contains($preview->summary, 'the same code the commit runs'),
    'and is the same code the commit runs, so what it shows is what will happen',
);

// =============================================================================
// COMMAND-SHAPE REFUSALS
// =============================================================================

throws(InvalidArgumentException::class,
    static fn () => new WriteCommand('employee', null, Surface::Directory, '1', ['HR'],
        ['x' => ChangeValue::set(1)], new DateTimeImmutable('2026-09-01')),
    'a write naming a read surface is a type error rather than a discovery');

throws(InvalidArgumentException::class,
    static fn () => new WriteCommand('employee', null, Surface::Api, '1', ['HR'],
        ['x' => ChangeValue::set(1)], new DateTimeImmutable('2026-09-01')),
    'R-X-044: an API write with no idempotency key is refused — a retried timeout would apply it twice');

throws(InvalidArgumentException::class,
    static fn () => new WriteCommand('employee', null, Surface::Edit, '1', ['HR'],
        ['x' => 'a bare value'], new DateTimeImmutable('2026-09-01')),
    'a bare value cannot say whether null means "clear this" or "I am not touching it"');

throws(InvalidArgumentException::class,
    static fn () => new WriteCommand('employee', 'e-1', Surface::Edit, '1', ['HR'],
        ['x' => ChangeValue::set(1)], new DateTimeImmutable('2026-09-01'),
        childOperations: [['collection' => 'refs', 'op' => 'replace_all', 'key' => null, 'values' => []]]),
    'R-X-041: delete-all-then-reinsert is not a child operation. SmartPRS 2 wipes references on every save');

// =============================================================================
// R-X-026 — THE FILE ROUND TRIP
// =============================================================================

$date = $field('probation_ends_on', AuthoringType::Date);
$parsed = ValueCoercion::coerce($date, '03-04-2026');
ok($parsed['ok'], 'DD-MM-YYYY parses');
same('2026-04-03', $parsed['value']->format('Y-m-d'), '03-04-2026 is 3 April, never 4 March — nothing is guessed');
same('03-04-2026', ValueCoercion::toCell($date, $parsed['value']),
    'and export emits exactly what its header declares. SmartPRS 2 exports Y-m-d under a (DD-MM-YYYY) header');

same('2026-04-03', ValueCoercion::coerce($date, '2026-04-03')['value']->format('Y-m-d'),
    'ISO is accepted on the way IN, because a file arrives however it arrives');
same('2026-04-03', ValueCoercion::coerce($date, '46115')['value']->format('Y-m-d'),
    'and so is an Excel serial number, which is what a spreadsheet hands over whether anybody wanted it or not');
ok(!ValueCoercion::coerce($date, 'next Tuesday')['ok'], 'an unparseable date is an error, never a null');

$money = $field('last_drawn', AuthoringType::Currency);
same('150000.00', ValueCoercion::toCell($money, Money::of('150000.00')), 'money exports as an exact decimal');
ok(!ValueCoercion::coerce($money, 150000.55)['ok'], 'and a float is refused at the boundary (Law 7)');

$scaled = $field('bonus_pct', AuthoringType::Decimal, ['constraints' => ['decimal_scale' => 2]]);
ok(!ValueCoercion::coerce($scaled, '12.345')['ok'], 'a value needing more scale than declared is REFUSED');
ok(
    str_contains(ValueCoercion::coerce($scaled, '12.345')['error'], 'rather than rounded'),
    'rather than rounded, because a figure that was checked must match the figure that was stored',
);

$dropdown = $field('marital_status', AuthoringType::Dropdown, ['valueSetKey' => 'marital_status']);
$members = ['MARRIED' => 'Married', 'SINGLE' => 'Single'];
same('MARRIED', ValueCoercion::coerce($dropdown, 'MARRIED', $members)['value'], 'a code is accepted');
same('MARRIED', ValueCoercion::coerce($dropdown, 'Married', $members)['value'],
    'and so is the label, which is what the export cell carries — or a file this system produced would not import back');
same('Married', ValueCoercion::toCell($dropdown, 'MARRIED', $members), 'the cell carries the label');
ok(!ValueCoercion::coerce($dropdown, 'DIVORCED', $members)['ok'],
    'R-X-027: an unknown code is a row error, never a silent skip');

$bool = $field('is_fresher', AuthoringType::Boolean);
foreach (['Yes' => true, 'y' => true, 'TRUE' => true, '1' => true, 'No' => false, 'n' => false, '0' => false] as $in => $out) {
    same($out, ValueCoercion::coerce($bool, $in)['value'], "'{$in}' reads as " . ($out ? 'true' : 'false'));
}
ok(!ValueCoercion::coerce($bool, 'maybe')['ok'], 'and a blank or unknown is NOT false: tri-state is a code field');

// The formula-injection guard, and its inverse.
$notes = $field('notes', AuthoringType::Text);
same("'=SUM(A1)", ValueCoercion::toCell($notes, '=SUM(A1)'),
    'a cell starting = + - or @ is neutralised on export, because a spreadsheet opens exported files');
same('=SUM(A1)', ValueCoercion::fromCell($notes, "'=SUM(A1)")['value'],
    'and reads back as what the person typed — the guard is a file concern, not a stored-value one');

// The property the exit gate turns into a per-type sweep.
foreach ([
    [$date, '03-04-2026'],
    [$money, '150000.00'],
    [$bool, 'Yes'],
    [$notes, 'plain text'],
    [$field('team_size', AuthoringType::Number), '42'],
] as [$definition, $cell]) {
    $back = ValueCoercion::fromCell($definition, $cell);
    ok($back['ok'], "{$definition->fieldKey} reads its own export cell back");
    same($cell, ValueCoercion::toCell($definition, $back['value']),
        "and re-emits it identically — a formatter with no parser is a one-way trip");
}

// =============================================================================
// R-X-025's REMAINING ARTEFACTS — the mask, the diff, and the three projections
// =============================================================================
//
// These exist because the first cut of the type catalogue said they did. Nine
// artefacts were declared present for twenty-five types while five of the nine
// had no code behind them — a claim standing in for a check, which is the one
// failure this codebase keeps catching in itself. The Phase 3 exit gate now
// exercises each artefact for every available type rather than reading the
// table.

same('••••3210', SmartPRS\Platform\Entity\Masking::apply('9876543210', 'last4'),
    'last4 is a bank statement\'s rule: it leaks length on purpose so a person recognises their own account');
same('••••', SmartPRS\Platform\Entity\Masking::apply('9876543210', 'full'),
    'full hides the length too — a mask whose width tracks the secret has told you about the secret');
same('••••', SmartPRS\Platform\Entity\Masking::apply('1234567890123456789012345', 'full'),
    'and renders identically whatever it hides');
same('E••••z', SmartPRS\Platform\Entity\Masking::apply('Ejaz', 'first_last'),
    'first_last confirms you have the right record without letting you read it');
same('••••@ametecsindia.com', SmartPRS\Platform\Entity\Masking::apply('ejaz@ametecsindia.com', 'domain_only'),
    'domain_only keeps the fact somebody is usually checking and hides the identity');
same('', SmartPRS\Platform\Entity\Masking::apply(null, 'full'),
    'a NULL is not masked: "there is no value" and "there is a value you may not see" are different facts');
same('••••', SmartPRS\Platform\Entity\Masking::apply('abc', 'last4'),
    'a value shorter than the mask window is hidden entirely rather than shown');
throws(InvalidArgumentException::class,
    static fn () => SmartPRS\Platform\Entity\Masking::apply('x', 'redact-ish'),
    'an unknown rule is refused rather than falling through to "none"');

$aadhaar = $field('gov_id', AuthoringType::Text, ['piiClass' => 'restricted', 'maskingRule' => 'last4']);
same(
    'Gov id: ••••3333 → ••••6666',
    SmartPRS\Platform\Entity\FieldArtefacts::diff($aadhaar, '111122223333', '444455556666'),
    'a diff of a PII field is masked on BOTH sides — the approval screen is exactly where a diff would unmask it',
);
same(
    'Gov id: changed (both values are masked under last4, so the difference is not shown here)',
    SmartPRS\Platform\Entity\FieldArtefacts::diff($aadhaar, '111122223333', '444422223333'),
    'and does not render "•••• → ••••", which reads as "nothing changed" — the opposite of true',
);
ok(
    str_contains(SmartPRS\Platform\Entity\FieldArtefacts::diff($linkedin, null, 'https://x.example/y'), '(empty)'),
    'an unset value diffs as (empty) rather than as a blank half a sentence',
);

$editRule = (new SurfaceRuleResolver([
    new SurfaceRule('nickname', Surface::Edit, null, Visibility::Read),
]))->resolve($fields['nickname'], Surface::Edit, ['HR']);

$client = SmartPRS\Platform\Entity\FieldArtefacts::clientValidator($fields['nickname'], $editRule);
same(true, $client['readonly'], 'the client spec carries the resolved surface answer, not the field default');
same(true, $client['reject_if_submitted'],
    'R-X-031: and tells the browser the value would be REJECTED, so it cannot helpfully submit it anyway');
same(12, $client['max_length'], 'with the declared constraints, which the server also enforces');

$dateField = $field('probation_ends_on', AuthoringType::Date);
$cell = SmartPRS\Platform\Entity\FieldArtefacts::templateCell($dateField);
ok(str_contains($cell['header'], 'd-m-Y'), 'R-X-026: the template header NAMES the format the export emits');
same(ValueCoercion::FILE_DATE_FORMAT, $cell['format'],
    'and it is the same constant the formatter uses. SmartPRS 2 writes (DD-MM-YYYY) and emits Y-m-d');

$apiRule = (new SurfaceRuleResolver([
    new SurfaceRule('previous_salary', Surface::Api, null, Visibility::Read),
]))->resolve($salary, Surface::Api, ['HR']);
$schema = SmartPRS\Platform\Entity\FieldArtefacts::apiSchema($salary, $apiRule);
same('string', $schema['type'],
    'money is a STRING over the API: a JSON number is a double in most parsers and Law 7 does not survive it');
same('sensitive', $schema['x-pii-class'],
    'and the schema declares the class, so a client knows the value may be masked rather than discovering it');
same(true, $schema['readOnly'], 'read-only on this surface for this actor, resolved rather than assumed');

// -----------------------------------------------------------------------------
// ROUND-TRIP SYMMETRY — Part XXXII §XXXII.7's import/export item.
// -----------------------------------------------------------------------------

group('round-trip');

$fields = ['code' => [], 'joined_on' => [], 'active' => [], 'gross' => [], 'bank_account' => []];
$stored = [
    'code' => 'E-1',
    'joined_on' => '2026-04-01',
    'active' => true,
    'gross' => '50000.00',
    'bank_account' => '123456789012',
];

$faithfulExport = static fn (array $row): array => [
    'code' => $row['code'],
    'joined_on' => (new DateTimeImmutable($row['joined_on']))->format('d-m-Y'),
    'active' => $row['active'] ? 'Yes' : 'No',
    'gross' => $row['gross'],
    'bank_account' => '••••••••9012',
];

$faithfulImport = static fn (array $cells): array => [
    'code' => $cells['code'],
    'joined_on' => DateTimeImmutable::createFromFormat('d-m-Y', $cells['joined_on'])->format('Y-m-d'),
    'active' => $cells['active'] === 'Yes',
    'gross' => $cells['gross'],
    // A masked cell is REFUSED, never written back. Accepting it would overwrite
    // a real account number with dots and look like a successful import.
    'bank_account' => 'REFUSED: the cell is masked and would overwrite the stored value',
];

$clean = SmartPRS\Platform\Entity\RoundTrip::check($stored, $fields, $faithfulExport, $faithfulImport);

ok($clean['symmetric'], 'a faithful export and import round-trips every value unchanged');
same([], $clean['dropped'], 'and drops nothing');
same(['bank_account'], $clean['refused'],
    'a masked cell is REFUSED rather than counted as a difference — treating it as one pushes somebody to '
    . '"fix" it by accepting the mask, which writes dots over a real account number');

// A dropped column: the file looks right, and the re-import clears the field.
$dropping = SmartPRS\Platform\Entity\RoundTrip::check($stored, $fields,
    static function (array $row) use ($faithfulExport): array {
        $cells = $faithfulExport($row);
        unset($cells['joined_on']);

        return $cells;
    },
    static function (array $cells) use ($faithfulImport): array {
        $out = $faithfulImport($cells + ['joined_on' => '01-01-1970']);
        unset($out['joined_on']);

        return $out;
    });

ok(!$dropping['symmetric'], 'a column the exporter skipped breaks symmetry');
same(['joined_on'], $dropping['dropped'],
    'and it is NAMED — the customer opens the file, sees their data, re-imports it, and the missing field '
    . 'is now empty, which is indistinguishable from a deliberate clearing');

// An asymmetric date: exported one way, read back another. One cell, silent.
$asymmetric = SmartPRS\Platform\Entity\RoundTrip::check($stored, $fields, $faithfulExport,
    static function (array $cells) use ($faithfulImport): array {
        $out = $faithfulImport($cells);
        $out['joined_on'] = '2026-01-04';   // d-m-Y read as Y-m-d

        return $out;
    });

ok(!$asymmetric['symmetric'], 'a date that comes back in a different form breaks symmetry');
ok(str_contains(implode('', $asymmetric['changed']), '2026-04-01'),
    'and the harness names the before and the after, because one silently changed cell is discovered '
    . 'months later by somebody who cannot explain it');

// A boolean read back as the string 'Yes' rather than as true.
$loose = SmartPRS\Platform\Entity\RoundTrip::check($stored, $fields, $faithfulExport,
    static function (array $cells) use ($faithfulImport): array {
        $out = $faithfulImport($cells);
        $out['active'] = 'Yes';

        return $out;
    });

ok(!$loose['symmetric'],
    "'Yes' is not true — the comparison is strict because '0', 0 and false all survive a loose one and are "
    . 'three different things in a spreadsheet cell');
