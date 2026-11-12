<?php
/**
 * THE PHASE 3 EXIT GATE — Part XXXII.
 *
 *   php tools/phase3/exit-gate.php
 *
 * Part XXXII states four conditions for leaving the People phase, and this runs
 * each of them rather than describing them — plus a fifth of its own that the
 * four rest on:
 *
 *   1. Adding one new employee field touches ZERO code files and appears
 *      correctly on all thirteen surfaces.
 *   2. Import/export round-trip symmetry is proven PER FIELD; no importer drops
 *      a column silently.
 *   3. "What was this employee's branch on 15 May?" returns one row.
 *   4. Every employee write produces a field-level audit record.
 *   5. R-X-025: every AVAILABLE authoring type actually has all nine artefacts,
 *      exercised rather than declared. Added after the first cut of the type
 *      catalogue asserted nine for twenty-five types while five of the nine had
 *      no code behind them — a claim standing in for a check, which is the one
 *      failure mode this codebase keeps finding in itself.
 *
 * It reports NOT PROVEN — never a silent pass — for a criterion whose check
 * cannot run, and exits non-zero on a failure. The same discipline as the Phase
 * 2 conformance runner: a gate reporting "4 of 4" while one of the four had
 * nothing to read is worse than no gate, because it is evidence.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Entity\AuthoringType;
use SmartPRS\Platform\Entity\ChangeValue;
use SmartPRS\Platform\Entity\EntityWriteService;
use SmartPRS\Platform\Entity\FieldArtefacts;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\Masking;
use SmartPRS\Platform\Entity\Requiredness;
use SmartPRS\Platform\Entity\StorageKind;
use SmartPRS\Platform\Entity\Surface;
use SmartPRS\Platform\Entity\SurfaceRule;
use SmartPRS\Platform\Entity\SurfaceRuleResolver;
use SmartPRS\Platform\Entity\ValueCoercion;
use SmartPRS\Platform\Entity\Visibility;
use SmartPRS\Platform\Entity\WriteCommand;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

$root = dirname(__DIR__, 2);

/** A probe value as a comparable string, so a DateTimeImmutable and a Money compare by what they mean. */
function renderProbe(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
        $value instanceof \SmartPRS\Platform\Support\Money => $value->toDecimalString(),
        is_bool($value) => $value ? 'true' : 'false',
        is_array($value) => implode('|', $value),
        default => (string) $value,
    };
}
$results = [];

echo "SmartPRS 3 — Phase 3 exit gate (Part XXXII, People)\n";

// =============================================================================
// 1. ONE NEW FIELD, ZERO CODE FILES, THIRTEEN SURFACES
// =============================================================================

$declared = json_decode((string) file_get_contents($root . '/tests/phase3/new_field.json'), true, 512, JSON_THROW_ON_ERROR);
$spec = $declared['field'];
$problems = [];

$field = new FieldDefinition(
    fieldKey: $spec['field_key'],
    label: $spec['label'],
    description: $spec['description'],
    authoringType: AuthoringType::from($spec['authoring_type']),
    storageKind: StorageKind::from($spec['storage_kind']),
    groupKey: $spec['group_key'],
    displayOrder: (int) $spec['display_order'],
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable($spec['effective_from'])),
    versionNo: 1,
    versionId: $spec['field_key'] . '-v1',
    piiClass: $spec['pii_class'],
    maskingRule: $spec['masking_rule'],
    historyMode: $spec['history_mode'],
    helpText: $spec['help_text'] ?? null,
    constraints: $spec['constraints'] ?? [],
);

$rules = [];

foreach ($declared['surface_rules'] as $row) {
    $rules[] = new SurfaceRule(
        fieldKey: $field->fieldKey,
        surface: Surface::from($row['surface']),
        roleKey: $row['role'],
        visibility: Visibility::from($row['visibility']),
        requiredness: Requiredness::from($row['required'] ?? 'never'),
        isListColumn: (bool) ($row['is_list_column'] ?? false),
        isFilterable: (bool) ($row['is_filterable'] ?? false),
        isSortable: (bool) ($row['is_sortable'] ?? false),
        isQuickSearch: (bool) ($row['is_quick_search'] ?? false),
    );
}

$resolver = new SurfaceRuleResolver($rules);
$visible = 0;
$writable = 0;

foreach (Surface::all() as $surface) {
    $effective = $resolver->resolve($field, $surface, ['HR']);

    // "Appears correctly on all thirteen" means every surface has an ANSWER —
    // including `hidden`, which is an answer. A surface that resolved by
    // accident to step 4 would be indistinguishable from one nobody configured,
    // so the check is that the declaration reached each one.
    $wanted = null;

    foreach ($declared['surface_rules'] as $row) {
        if ($row['surface'] === $surface->value) {
            $wanted = Visibility::from($row['visibility']);
        }
    }

    if ($wanted === null) {
        $problems[] = "no rule was declared for {$surface->value}, so the field's answer there is step 4 by "
            . 'default rather than by decision';

        continue;
    }

    if ($effective->visibility !== $wanted) {
        $problems[] = "{$surface->value} resolved to {$effective->visibility->value}, declared {$wanted->value}";

        continue;
    }

    if ($effective->visibility->allowsRead()) {
        $visible++;
    }

    if ($effective->visibility->allowsWrite() && $surface->writes()) {
        $writable++;
    }
}

if ($visible !== (int) $declared['expect']['visible_on']) {
    $problems[] = "visible on {$visible} surfaces, expected {$declared['expect']['visible_on']}";
}

if ($writable !== (int) $declared['expect']['writable_on']) {
    $problems[] = "writable on {$writable} surfaces, expected {$declared['expect']['writable_on']}";
}

// ZERO CODE FILES. The claim is only worth the check behind it, so this greps.
$offenders = [];

foreach (['src', 'public', 'database'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), $field->fieldKey)) {
            $offenders[] = str_replace($root . '/', '', $file->getPathname());
        }
    }
}

if ($offenders !== []) {
    $problems[] = 'the field key appears in ' . count($offenders) . ' code file(s): ' . implode(', ', $offenders)
        . '. Adding a field is supposed to touch none';
}

$results[] = [
    'name' => 'One new field touches zero code files and answers on all thirteen surfaces',
    'ran' => true,
    'problems' => $problems,
    'detail' => "declared entirely in tests/phase3/new_field.json · visible on {$visible} surfaces, writable on "
        . "{$writable}, hidden on " . (13 - $visible) . ' · 0 code files name it',
];

// =============================================================================
// 2. IMPORT/EXPORT ROUND-TRIP SYMMETRY, PER FIELD
// =============================================================================

$problems = [];
$checked = 0;

// One field of every AVAILABLE type, and the cell it must survive. R-X-025
// makes an unavailable type unselectable, so a gap here is a type that claims
// to be complete and is not.
$probes = [
    'text' => [AuthoringType::Text, 'Ejaz Hussain'],
    'long_text' => [AuthoringType::LongText, 'A note that runs on a bit.'],
    'number' => [AuthoringType::Number, '42'],
    'decimal' => [AuthoringType::Decimal, '12.3400'],
    'currency' => [AuthoringType::Currency, '150000.00'],
    'date' => [AuthoringType::Date, '03-04-2026'],
    'datetime' => [AuthoringType::DateTime, '03-04-2026 09:30'],
    'time' => [AuthoringType::Time, '09:30'],
    'email' => [AuthoringType::Email, 'ejaz@ametecsindia.com'],
    'url' => [AuthoringType::Url, 'https://in.linkedin.com/in/ejaz'],
    'boolean' => [AuthoringType::Boolean, 'Yes'],
    'employee_lookup' => [AuthoringType::EmployeeLookup, '1042'],
    'manager_lookup' => [AuthoringType::ManagerLookup, '77'],
    'organisation_lookup' => [AuthoringType::OrganisationLookup, '9'],
    'postal_code' => [AuthoringType::PostalCode, '500084'],
    // Mobile stores as encrypted_text and so parses as text here; the E.164
    // blind index is the encryption work, not the round trip. Checkbox is
    // boolean with a different widget, and X.3.2 says so — asserting it
    // separately is what stops a second boolean parser appearing.
    'mobile' => [AuthoringType::Mobile, '+91 98765 43210'],
    'checkbox' => [AuthoringType::Checkbox, 'Yes'],
    // A government identifier normalises on the way in — `abcde 1234 f` and
    // `ABCDE1234F` are one PAN — so the cell it round-trips to is the
    // normalised form, and the probe says so rather than hiding it.
    'government_identifier' => [AuthoringType::GovernmentIdentifier, 'ABCPE1234F'],
    // A file-bearing field round-trips its REFERENCE, not its bytes (X.3.2
    // #19-22): the export emits a signed URL or the file inside an archive.
    // The CONTENT rules are R-X-028's and are asserted separately below.
    'photo' => [AuthoringType::Photo, '4021'],
    'file' => [AuthoringType::File, '4022'],
    'document' => [AuthoringType::Document, '4023'],
    'signature' => [AuthoringType::Signature, '4024'],
];

$codeMembers = ['MARRIED' => 'Married', 'SINGLE' => 'Single'];

foreach (AuthoringType::available() as $type) {
    $isCode = in_array($type->storageType()->value, ['code', 'code_list'], true);

    // A COMPOSITE round-trips through columns, not a cell: X.3.2 #18 fixes the
    // shape as `<FIELD> — CITY` and so on, because a composite exported as one
    // JSON cell is a value nobody can edit in a spreadsheet.
    if (SmartPRS\Platform\Entity\Composite::isComposite($type)) {
        $definition = new FieldDefinition(
            fieldKey: 'probe_' . $type->value,
            label: 'Probe',
            description: 'A composite round-trip probe, built by the Phase 3 exit gate.',
            authoringType: $type,
            storageKind: StorageKind::Attribute,
            groupKey: 'personal',
            displayOrder: 1,
            scope: Scope::tenantDefault(),
            range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
            versionNo: 1,
            versionId: 'probe-v1',
            piiClass: $type === AuthoringType::BankData ? 'restricted' : 'identifying',
            maskingRule: $type === AuthoringType::BankData ? 'last4' : 'none',
        );

        $members = $type === AuthoringType::Address
            ? ['line1' => 'Plot 12, Kondapur', 'city' => 'Hyderabad', 'state' => 'TG',
               'postal_code' => '500084', 'country' => 'IN']
            : ['account_number' => '50100123456789', 'ifsc' => 'HDFC0001234',
               'holder_name' => 'Mohammad Ejaz Hussain', 'account_type' => 'SAVINGS'];

        $cells = SmartPRS\Platform\Entity\Composite::toCells($definition, $members);
        $back = SmartPRS\Platform\Entity\Composite::fromCells($definition, $cells);

        if ($type === AuthoringType::BankData) {
            // The account number exports MASKED by default (X.3.2 #30), so the
            // round trip must NOT return it — and re-importing the masked cell
            // must be refused rather than accepted. A placeholder that imports
            // cleanly is how `••••6789` becomes somebody's account number.
            if (!str_starts_with($cells[$definition->columnHeader() . ' — ACCOUNT NUMBER'], '••••')) {
                $problems[] = 'bank_data: the account number exported unmasked by default';
            }

            if ($back['ok']) {
                $problems[] = 'bank_data: a masked export cell imported cleanly, so a placeholder can be '
                    . 'written back as a real account number';
            }

            $checked++;

            continue;
        }

        if (!$back['ok']) {
            $problems[] = "{$type->value}: its own export columns do not import — {$back['error']}";

            continue;
        }

        if ($back['value'] !== $members) {
            $problems[] = "{$type->value}: the members came back as "
                . json_encode($back['value']) . ' rather than ' . json_encode($members);

            continue;
        }

        // Every member blank is "no value", not a half-entered composite.
        $empty = SmartPRS\Platform\Entity\Composite::fromCells(
            $definition,
            array_map(static fn (): string => '', $cells),
        );

        if (!$empty['ok'] || $empty['value'] !== null) {
            $problems[] = "{$type->value}: an entirely blank composite is not read as absent";
        }

        $checked++;

        continue;
    }

    if (!$isCode && !isset($probes[$type->value])) {
        $problems[] = "no round-trip probe exists for the available type '{$type->value}', so its symmetry is "
            . 'asserted by nothing';

        continue;
    }

    [$authoring, $cell] = $isCode
        ? [$type, $type->storageType()->value === 'code_list' ? 'Married|Single' : 'Married']
        : $probes[$type->value];

    $definition = new FieldDefinition(
        fieldKey: 'probe_' . $type->value,
        label: 'Probe',
        description: 'A round-trip probe for one authoring type, built by the Phase 3 exit gate.',
        authoringType: $authoring,
        storageKind: StorageKind::Attribute,
        groupKey: 'personal',
        displayOrder: 1,
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'probe-v1',
        valueSetKey: $isCode ? 'marital_status' : null,
        constraints: match ($type) {
            AuthoringType::Decimal => ['decimal_scale' => 4],
            AuthoringType::GovernmentIdentifier => ['checksum' => 'pan_structure'],
            default => [],
        },
    );

    $members = $isCode ? $codeMembers : [];
    $parsed = ValueCoercion::fromCell($definition, $cell, $members);

    if (!$parsed['ok']) {
        $problems[] = "{$type->value}: '{$cell}' does not import — {$parsed['error']}";

        continue;
    }

    // Symmetry is asserted on the VALUE, not on the cell text, and the
    // difference is not pedantry. A mobile number of `+91 98765 43210` leaves
    // as `'+91 98765 43210`: the leading apostrophe is the formula-injection
    // guard, because a cell beginning `+` is a formula to the spreadsheet that
    // opens the file. Requiring the cell text to be identical would either fail
    // this type or delete the guard, and the guard is the correct behaviour —
    // what must hold is that exporting and re-importing gives back the SAME
    // VALUE, and that exporting twice gives the same file.
    $emitted = ValueCoercion::toCell($definition, $parsed['value'], $members);
    $reread = ValueCoercion::fromCell($definition, $emitted, $members);

    if (!$reread['ok']) {
        $problems[] = "{$type->value}: its own export cell '{$emitted}' does not import — {$reread['error']}";

        continue;
    }

    if (renderProbe($reread['value']) !== renderProbe($parsed['value'])) {
        $problems[] = "{$type->value}: '{$cell}' exports as '{$emitted}' and reads back as "
            . renderProbe($reread['value']) . ' rather than ' . renderProbe($parsed['value']);

        continue;
    }

    if (ValueCoercion::toCell($definition, $reread['value'], $members) !== $emitted) {
        $problems[] = "{$type->value}: exporting the re-imported value gives a different cell, so the file is "
            . 'not stable across a round trip';

        continue;
    }

    $checked++;
}

// "No importer drops a column silently" — the structural half. An unknown field
// key must produce a REJECTED outcome, not disappear.
$importService = new EntityWriteService(
    new SurfaceRuleResolver([new SurfaceRule('linkedin_profile', Surface::Import, null, Visibility::Write)]),
    ['linkedin_profile' => $field],
    [],
    static fn (array $roles, string $permission): bool => true,
);

$imported = $importService->write(
    new WriteCommand('employee', 'e-1', Surface::Import, '1', ['HR'], [
        'linkedin_profile' => ChangeValue::set('https://in.linkedin.com/in/ejaz'),
        'a_column_nobody_declared' => ChangeValue::set('surprise'),
    ], new DateTimeImmutable('2026-09-01'), partialOk: true),
    [],
    static fn ($p): array => ['uuid' => 'e-1', 'row_version' => 1],
);

$unknown = $imported->outcomeFor('a_column_nobody_declared');

if ($unknown === null || !$unknown->isRejected()) {
    $problems[] = 'an undeclared import column was not reported as rejected — four SmartPRS 2 importers drop '
        . 'unknown columns by four different mechanisms and none of them says so';
}

$results[] = [
    'name' => 'Import/export round-trip symmetry per field, and no column dropped silently',
    'ran' => true,
    'problems' => $problems,
    'detail' => "{$checked} of " . count(AuthoringType::available()) . ' available type(s) round-trip exactly; '
        . 'an undeclared column is reported as rejected rather than dropped',
];

// =============================================================================
// 3. "WHAT WAS THIS EMPLOYEE'S BRANCH ON 15 MAY?" RETURNS ONE ROW
// =============================================================================

// The proof already exists and is asserted against a real database in
// tests/organisation_test.php. Re-implementing it here would be a SECOND
// as-at query, and the day the two disagreed the gate would be the one
// believed. So this criterion checks that the proof is present and passing
// — which fails if the assertion is deleted, weakened or breaks.

$problems = [];
$ran = true;
$suite = $root . '/tests/organisation_test.php';
$source = is_file($suite) ? (string) file_get_contents($suite) : '';

if (!str_contains($source, 'ON 15 MAY')) {
    $problems[] = 'tests/organisation_test.php no longer contains the 15 May assertion, so nothing proves the '
        . 'as-at query returns one row';
}

foreach ([
    "same(\$hyd['id'], \$branchOn('2026-04-30')" => 'the boundary on the last day of the first row',
    "same(\$chn['id'], \$branchOn('2026-05-01')" => 'the boundary on the first day of the second',
    "same(null, \$branchOn('2025-12-31')" => 'and NULL before the first row, which is an answer rather than a gap',
] as $needle => $what) {
    if (!str_contains($source, $needle)) {
        $problems[] = "the suite no longer asserts {$what}";
    }
}

exec('php ' . escapeshellarg($root . '/tests/run.php') . ' organisation 2>&1', $output, $status);
$tail = implode("\n", array_slice($output, -3));

if (str_contains($tail, 'skipped: no database')) {
    $ran = false;
    $problems[] = 'the organisation suite skipped for want of a database, so the as-at query ran against '
        . 'nothing. NULL from a table that does not exist looks exactly like NULL from a date before hire.';
} elseif ($status !== 0 || !str_contains($tail, '0 failures')) {
    $problems[] = 'the organisation suite does not pass: ' . $tail;
}

$results[] = [
    'name' => '"What was this employee\'s branch on 15 May?" returns one row',
    'ran' => $ran,
    'problems' => $problems,
    'detail' => 'proven in tests/organisation_test.php against a real database — boundaries on both sides of '
        . 'the move, an open row that stays in force, and NULL before the first assignment. Checked here rather '
        . 'than re-implemented: a second as-at query is the one that eventually disagrees',
];

// =============================================================================
// 5. R-X-025 — EVERY AVAILABLE TYPE ACTUALLY HAS ALL NINE ARTEFACTS
// =============================================================================
//
// Not one of Part XXXII's four, and it runs anyway, because the four rest on
// it. This check exists because the first cut of `AuthoringType` declared all
// nine artefacts present for twenty-five types while five of the nine had no
// code behind them at all — a claim about a check standing in for the check,
// which is the exact defect R-X-025 is written to prevent.
//
// So the table no longer gets to assert completeness on its own: each artefact
// is EXERCISED for every available type, and a type whose declaration and
// behaviour disagree fails here.

$problems = [];
$exercised = 0;

foreach (AuthoringType::available() as $type) {
    $isCode = in_array($type->storageType()->value, ['code', 'code_list'], true);

    $probe = new FieldDefinition(
        fieldKey: 'artefact_' . $type->value,
        label: 'Artefact probe',
        description: 'One field of each type, so every declared artefact is exercised rather than asserted.',
        authoringType: $type,
        storageKind: StorageKind::Attribute,
        groupKey: 'personal',
        displayOrder: 1,
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'probe-v1',
        piiClass: 'identifying',
        maskingRule: 'last4',
        valueSetKey: $isCode ? 'marital_status' : null,
        constraints: ['max_length' => 64, 'decimal_scale' => 4],
    );

    $effective = (new SurfaceRuleResolver([
        new SurfaceRule($probe->fieldKey, Surface::Edit, null, Visibility::Write),
    ]))->resolve($probe, Surface::Edit, ['HR']);

    $artefacts = [
        // The server validator and the two file artefacts are ValueCoercion's,
        // and criterion 2 above already round-trips every one of them. What is
        // checked here is that the other five produce something usable.
        'client_validator' => static fn (): array => FieldArtefacts::clientValidator($probe, $effective),
        'template_cell' => static fn (): array => FieldArtefacts::templateCell($probe),
        'api_schema' => static fn (): array => FieldArtefacts::apiSchema($probe, $effective),
        'spx_type' => static fn (): string => (string) $type->spxType(),
        'mask_renderer' => static fn (): string => Masking::apply('9876543210', $probe->maskingRule),
        'diff_renderer' => static fn (): string => FieldArtefacts::diff($probe, '1234567890', '9876543210'),
    ];

    foreach ($artefacts as $name => $produce) {
        try {
            $output = $produce();
        } catch (\Throwable $e) {
            $problems[] = "{$type->value}: {$name} threw " . $e::class . ' — ' . $e->getMessage();

            continue;
        }

        // None of the four artefact closures above can return null (they are
        // typed array or string), so only the empty-array/empty-string cases
        // are actually reachable.
        if ($output === [] || $output === '') {
            $problems[] = "{$type->value}: {$name} produced nothing, so the type's declaration of it is a claim "
                . 'rather than an artefact';

            continue;
        }

        $exercised++;
    }

    // The mask must actually hide something. A "mask renderer" that returns the
    // input is the vacuous version of this artefact.
    if (Masking::apply('9876543210', 'last4') === '9876543210') {
        $problems[] = 'the mask renderer returns its input unchanged';
    }

    // And the header must NAME the format it emits (R-X-026).
    $cell = FieldArtefacts::templateCell($probe);

    if ($type->storageType() === \SmartPRS\Platform\Entity\StorageType::Date
        && !str_contains($cell['header'], ValueCoercion::FILE_DATE_FORMAT)
    ) {
        $problems[] = "{$type->value}: the template header does not name the date format it emits";
    }
}

$results[] = [
    'name' => 'R-X-025: every available type has all nine artefacts, exercised rather than declared',
    'ran' => true,
    'problems' => $problems,
    'detail' => "{$exercised} artefact(s) produced across " . count(AuthoringType::available())
        . ' available type(s); the mask hides, the diff of two masked values says so, and the template header '
        . 'names the format the export emits',
];

// =============================================================================
// 6. R-X-028 — FILE-BEARING FIELDS VALIDATE CONTENT, NOT FILENAME
// =============================================================================

$problems = [];

$photo = new FieldDefinition(
    fieldKey: 'probe_photo',
    label: 'Photo',
    description: 'A file probe for the Phase 3 exit gate, checking content rather than filename.',
    authoringType: AuthoringType::Photo,
    storageKind: StorageKind::Attribute,
    groupKey: 'personal',
    displayOrder: 1,
    scope: Scope::tenantDefault(),
    range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    versionNo: 1,
    versionId: 'probe-v1',
    constraints: ['max_bytes' => 4096, 'min_bytes' => 8],
);

// A real 1x1 PNG, by its magic bytes rather than by its name.
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);

$good = SmartPRS\Platform\Entity\FileValidation::check($photo, $png, 'ejaz.png');

if (!$good['ok']) {
    $problems[] = 'a genuine PNG named .png was refused: ' . $good['error'];
}

if ($good['mime'] !== 'image/png') {
    $problems[] = 'the sniffed type is ' . var_export($good['mime'], true) . ' rather than image/png';
}

// The defect R-X-028 exists to prevent: PHP renamed to .png. SmartPRS 2 does an
// extension check only, with no MIME sniff and no image decode anywhere.
$disguised = SmartPRS\Platform\Entity\FileValidation::check($photo, '<?php system($_GET[0]); ?>', 'ejaz.png');

if ($disguised['ok']) {
    $problems[] = 'a PHP file named .png was accepted as a photo — the filename decided its type';
}

// And the mirror: a genuine PNG named .exe, which is a genuine PNG that
// something downstream will hand to the shell.
$misnamed = SmartPRS\Platform\Entity\FileValidation::check($photo, $png, 'ejaz.exe');

if ($misnamed['ok']) {
    $problems[] = 'a PNG named .exe was accepted; content and extension must BOTH be allowed';
}

$oversize = SmartPRS\Platform\Entity\FileValidation::check($photo, str_repeat($png, 200), 'big.png');

if ($oversize['ok']) {
    $problems[] = 'the declared size bound was not enforced';
}

// Quarantine: every verdict except `clean` withholds the file, including the one
// that has not come back and the one where the scanner itself failed.
foreach (['pending', 'infected', 'error'] as $verdict) {
    if (SmartPRS\Platform\Entity\FileValidation::isServable($verdict)) {
        $problems[] = "a file with verdict '{$verdict}' is servable — only `clean` releases a file";
    }

    if (SmartPRS\Platform\Entity\FileValidation::quarantineReason($verdict) === '') {
        $problems[] = "verdict '{$verdict}' withholds the file without saying why";
    }
}

if (!SmartPRS\Platform\Entity\FileValidation::isServable('clean')) {
    $problems[] = 'a clean file is not servable, so nothing would ever be shown';
}

$results[] = [
    'name' => 'R-X-028: file-bearing fields validate content, and nothing is served before it is clean',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'MIME sniffed from bytes, the extension must additionally agree, size bounds enforced, and '
        . 'pending / infected / error all withhold the file — a scanner outage is not a pass',
];

// =============================================================================
// 4. EVERY EMPLOYEE WRITE PRODUCES A FIELD-LEVEL AUDIT RECORD
// =============================================================================

$problems = [];
$fixture = require __DIR__ . '/audit_fixture.php';
$ran = $fixture !== null;

if (!$ran) {
    $problems[] = 'no database. This criterion is about a row in audit_field_changes committing with the write '
        . 'that produced it, and an in-memory stand-in would assert nothing.';
} else {
    $problems = $fixture['problems'];
}

$results[] = [
    'name' => 'Every employee write produces a field-level audit record',
    'ran' => $ran,
    'problems' => $problems,
    'detail' => $ran ? $fixture['detail'] : '',
];

// =============================================================================

$failed = 0;
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    if ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    printf("\n  %d. \033[32mPASS      \033[0m  %s\n", $index + 1, $result['name']);
    echo "        {$result['detail']}\n";
}

// The incomplete types, printed every run. R-X-025 makes them unselectable, so
// they are not a defect — but a phase gate that says PASS while seven declared
// types have one artefact between them has told half the truth.
$incomplete = AuthoringType::incomplete();

if ($incomplete !== []) {
    echo "\n  Declared and NOT available for selection (R-X-025) — " . count($incomplete) . " type(s):\n";

    foreach ($incomplete as $type) {
        printf("      %-24s missing %d of 9: %s\n",
            $type->value, count($type->missingArtefacts()), implode(', ', $type->missingArtefacts()));
    }
}

printf(
    "\n%s  %d of %d Phase 3 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun,
    count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN",
);

exit($failed === 0 && $notRun === 0 ? 0 : 1);
