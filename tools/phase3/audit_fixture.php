<?php

declare(strict_types=1);

/**
 * CRITERION 4 — every employee write produces a field-level audit record.
 *
 * Against a real MariaDB, because what is being asserted is that a row in
 * `audit_field_changes` COMMITS WITH the value it describes. An in-memory
 * stand-in cannot fail that: the interesting failure is an audit written in its
 * own transaction, or by a listener, or after the commit — each of which
 * produces a ledger that is right almost always, and the almost is the part an
 * auditor is asking about.
 *
 * Returns null when there is no database, and the gate reports NOT PROVEN. It
 * does not report PASS.
 *
 * @return array{problems: list<string>, detail: string}|null
 */

use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Entity\AuthoringType;
use SmartPRS\Platform\Entity\ChangeValue;
use SmartPRS\Platform\Entity\EntityWriteService;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\Persistence\EntityStore;
use SmartPRS\Platform\Entity\PreparedWrite;
use SmartPRS\Platform\Entity\StorageKind;
use SmartPRS\Platform\Entity\Surface;
use SmartPRS\Platform\Entity\SurfaceRule;
use SmartPRS\Platform\Entity\SurfaceRuleResolver;
use SmartPRS\Platform\Entity\Visibility;
use SmartPRS\Platform\Entity\WriteCommand;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_phase3_gate';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException) {
    return null;
}

require_once __DIR__ . '/../../tests/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();
$files = glob(__DIR__ . '/../../database/migrations/*.php');
sort($files);

foreach ($files as $file) {
    (require $file)->up();
}

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

foreach (Illuminate\Support\Facades\Schema::$statements as $statement) {
    $pdo->exec($statement);
}

$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
            VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Ametecs', 'active', NOW(), NOW())");
$tenantId = (int) $pdo->lastInsertId();

$tenant = new TenantContext();
$tenant->establish($tenantId);

// The field, stored as data. Two of them, so the check can tell "one audit row"
// from "one audit row per changed field".
$definitions = [];
$definitionIds = [];

$range = EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01'));

foreach ([
    ['linkedin_profile', AuthoringType::Url, 'identifying', 'none'],
    ['emergency_contact', AuthoringType::Text, 'sensitive', 'last4'],
] as [$key, $type, $pii, $mask]) {
    $definitions[$key] = new FieldDefinition(
        fieldKey: $key,
        label: ucfirst(str_replace('_', ' ', $key)),
        description: 'A field the Phase 3 exit gate declares as data, exactly as a tenant would.',
        authoringType: $type,
        storageKind: StorageKind::Attribute,
        groupKey: 'personal',
        displayOrder: 10,
        scope: Scope::tenantDefault(),
        range: $range,
        versionNo: 1,
        versionId: $key . '-v1',
        piiClass: $pii,
        maskingRule: $mask,
    );

    $pdo->prepare(
        'INSERT INTO entity_field_definitions
           (tenant_id, uuid, entity_key, field_key, label, description, group_key, display_order,
            authoring_type_code, data_type_code, storage_kind_code, pii_class_code, masking_rule_code,
            effective_from, version_no, created_at, updated_at)
         VALUES (:t, UNHEX(:u), :e, :k, :l, :d, :g, 10, :a, :dt, :sk, :pii, :mask, :from, 1, NOW(), NOW())'
    )->execute([
        't' => $tenantId,
        'u' => str_pad(dechex(crc32($key)), 32, '0', STR_PAD_LEFT),
        'e' => 'employee',
        'k' => $key,
        'l' => $definitions[$key]->label,
        'd' => $definitions[$key]->description,
        'g' => 'personal',
        'a' => $type->value,
        'dt' => $type->storageType()->value,
        'sk' => 'attribute',
        'pii' => $pii,
        'mask' => $mask,
        'from' => '2026-04-01',
    ]);

    $definitionIds[$key] = (int) $pdo->lastInsertId();
}

$audit = new AuditLedger($pdo, $tenant);
$store = new EntityStore($pdo, $tenant, $audit, $definitions, $definitionIds);

$service = new EntityWriteService(
    new SurfaceRuleResolver([
        new SurfaceRule('linkedin_profile', Surface::Edit, null, Visibility::Write),
        new SurfaceRule('emergency_contact', Surface::Edit, null, Visibility::Write),
    ]),
    $definitions,
    [],
    static fn (array $roles, string $permission): bool => in_array('HR', $roles, true),
);

$at = new DateTimeImmutable('2026-09-04 11:00:00');
$employmentId = 4242;

$result = $service->write(
    new WriteCommand(
        entityKey: 'employee',
        entityUuid: 'emp-4242',
        surface: Surface::Edit,
        actorUserId: '201',
        actorRoles: ['HR'],
        changes: [
            'linkedin_profile' => ChangeValue::set('https://in.linkedin.com/in/ejaz'),
            'emergency_contact' => ChangeValue::set('+91 98765 43210'),
        ],
        effectiveFrom: new DateTimeImmutable('2026-09-01'),
        reasonText: 'Details supplied at onboarding.',
    ),
    [],
    static fn (PreparedWrite $prepared): array => $store->persist($prepared, $employmentId, $at),
);

$problems = [];

if (!$result->applied) {
    $problems[] = 'the write did not apply: ' . $result->summary;
}

$changes = TenantScopedQuery::on($pdo, $tenant, 'audit_field_changes')->orderBy('field_key')->select();
$entries = TenantScopedQuery::on($pdo, $tenant, 'audit_entries')->select();

if (count($entries) !== 1) {
    $problems[] = 'expected one audit entry for the write, found ' . count($entries);
}

if (count($changes) !== 2) {
    $problems[] = 'expected one audit_field_changes row PER CHANGED FIELD (2), found ' . count($changes);
}

foreach ($changes as $change) {
    if ((string) $change['surface_code'] !== 'edit') {
        $problems[] = "the field change for {$change['field_key']} does not record which surface wrote it";
    }

    if ($change['new_value_hash'] === null) {
        $problems[] = "the field change for {$change['field_key']} carries no hash of the new value";
    }
}

$sensitive = null;
$ordinary = null;

foreach ($changes as $change) {
    if ((string) $change['field_key'] === 'emergency_contact') {
        $sensitive = $change;
    }

    if ((string) $change['field_key'] === 'linkedin_profile') {
        $ordinary = $change;
    }
}

if ($sensitive !== null && $sensitive['new_value'] !== null) {
    $problems[] = 'a field classed sensitive wrote its plaintext into the audit row — an audit trail that '
        . 'leaks the thing it protects is a second copy of the secret (X.2.6)';
}

if ($sensitive !== null && $sensitive['new_value_hash'] === null) {
    $problems[] = 'and recorded no hash either, so THAT it changed is provable and WHAT it changed to is not';
}

if ($ordinary !== null && (string) $ordinary['new_value'] !== 'https://in.linkedin.com/in/ejaz') {
    $problems[] = 'an ordinary field did not record its new value in the audit row';
}

// The values themselves landed, in exactly one v_* column each (R-X-005).
$values = TenantScopedQuery::on($pdo, $tenant, 'entity_field_values')->select();

if (count($values) !== 2) {
    $problems[] = 'expected two entity_field_values rows, found ' . count($values);
}

foreach ($values as $row) {
    $populated = [];

    foreach (['v_text', 'v_long_text', 'v_number', 'v_decimal', 'v_date', 'v_datetime', 'v_bool', 'v_code', 'v_json'] as $column) {
        if ($row[$column] !== null) {
            $populated[] = $column;
        }
    }

    if (count($populated) !== 1) {
        $problems[] = 'R-X-005: a value row populates exactly one v_* column and this one populates '
            . count($populated) . ' (' . implode(', ', $populated) . ')';
    }
}

// R-X-004: no DDL happened. Adding two fields added no columns and no tables.
$tables = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();

if ((int) $tables !== count(Illuminate\Support\Facades\Schema::$blueprints)) {
    $problems[] = 'the schema changed while two fields were added — R-X-004 forbids DDL at runtime, and '
        . 'SmartPRS 2 creates 34 employee columns and six tables at request time';
}

// And the chain still verifies, so the audit rows are inside the chain that
// protects them rather than beside it.
if ($audit->verify() !== null) {
    $problems[] = 'the audit chain does not verify after the write';
}

$detail = count($changes) . ' field-level audit row(s) for one write, each naming the surface; the sensitive '
    . 'field recorded a hash and no plaintext; 2 value rows, one populated column each; no DDL';

$pdo->exec("DROP DATABASE `{$database}`");

return ['problems' => $problems, 'detail' => $detail];
