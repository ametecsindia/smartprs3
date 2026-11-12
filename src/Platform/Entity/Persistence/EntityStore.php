<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity\Persistence;

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Entity\FieldDefinition;
use SmartPRS\Platform\Entity\PreparedWrite;
use SmartPRS\Platform\Entity\StorageType;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * STAGES 7, 8 AND 9 — Part X §X.5.2.
 *
 * The dated-row handling, the persist and the field-level audit, in ONE
 * transaction. That is the whole reason they are one class: R-X-038 and stage 9
 * both hinge on the audit write being inside the transaction the values are
 * written in, and a design where the audit is a listener, a job or an observer
 * is a design where an audit row can be missing for a write that happened, or
 * present for one that rolled back. Both are worse than no ledger, because both
 * look like evidence.
 *
 * SmartPRS 2's `storeEmployee()` writes no audit row at all. So "who changed
 * this employee's bank account, when, from which screen, and to what" is a
 * question its database cannot answer, and the recovery for a disputed salary
 * change is to ask people what they remember.
 *
 * R-X-005: exactly one `v_*` column is populated per value row, chosen by the
 * field's storage type. `column()` on StorageType is the single definition, so
 * the migration's CHECK constraint and this writer cannot disagree about which
 * column a Date lives in.
 */
final class EntityStore
{
    /** @param array<string, FieldDefinition> $definitions field key => definition */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly AuditLedger $audit,
        private readonly array $definitions,
        /** @var array<string, int> field key => entity_field_definitions.id */
        private readonly array $definitionIds,
    ) {
    }

    /**
     * Apply a prepared write.
     *
     * @return array{uuid: string, row_version: int}
     */
    public function persist(PreparedWrite $prepared, int $entityId, \DateTimeImmutable $at): array
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException(
                'EntityStore opens its own transaction, because stage 9 must commit with stages 7 and 8 or not '
                . 'at all. A caller that had already opened one could commit the values and roll back the audit.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $rowVersion = 1;

            foreach ($prepared->values as $fieldKey => $value) {
                $rowVersion = max($rowVersion, $this->writeValue($prepared, $fieldKey, $value, $entityId, $at));
            }

            // Stage 9, INSIDE the transaction. One audit entry for the write,
            // one audit_field_changes row per changed field. The entry carries
            // the actor, the surface and the reason once; the field rows carry
            // what actually moved.
            $entry = $this->audit->append(new AuditEntry(
                action: $prepared->command->isCreate() ? 'employee.created' : 'employee.updated',
                entityType: $prepared->command->entityKey,
                occurredAt: $at,
                businessOn: $prepared->command->effectiveFrom,
                entityId: $entityId,
                actorUserId: $prepared->command->actorUserId,
                newValue: [
                    'surface' => $prepared->command->surface->value,
                    'fields' => array_keys($prepared->values),
                    'routed' => array_keys($prepared->routed),
                    'source_reference' => $prepared->command->sourceReference,
                ],
                reason: $prepared->command->reasonText,
                requestId: $prepared->command->idempotencyKey,
            ));

            $entryId = $this->auditEntryId($entry['seq']);

            foreach ($prepared->outcomes as $outcome) {
                if (!$outcome->changedSomething() && $outcome->status !== 'routed_for_approval') {
                    continue;
                }

                $this->writeFieldChange($prepared, $outcome, $entryId, $entityId, $at);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['uuid' => $prepared->command->entityUuid ?? (string) $entityId, 'row_version' => $rowVersion];
    }

    /** One typed row, exactly one populated `v_*` column (R-X-005). */
    private function writeValue(
        PreparedWrite $prepared,
        string $fieldKey,
        mixed $value,
        int $entityId,
        \DateTimeImmutable $at,
    ): int {
        $definition = $this->definitions[$fieldKey] ?? null;
        $definitionId = $this->definitionIds[$fieldKey] ?? null;

        if ($definition === null || $definitionId === null) {
            throw new \RuntimeException(
                "No stored definition for '{$fieldKey}'. A prepared write reached persistence for a field the "
                . 'store cannot identify, which means the definitions the service resolved against and the ones '
                . 'this store was built with are two different sets.'
            );
        }

        $storage = $definition->authoringType->storageType();
        $table = TenantScopedQuery::on($this->pdo, $this->tenant, 'entity_field_values');

        $existing = TenantScopedQuery::on($this->pdo, $this->tenant, 'entity_field_values')
            ->where('entity_key', '=', $prepared->command->entityKey)
            ->where('entity_id', '=', $entityId)
            ->where('entity_field_definition_id', '=', $definitionId)
            ->first();

        $columns = array_fill_keys(StorageType::columns(), null);
        $columns[$storage->column()] = self::store($storage, $value);

        $row = $columns + [
            'entity_field_definition_id' => $definitionId,
            'entity_key' => $prepared->command->entityKey,
            'entity_id' => $entityId,
            'data_type_code' => $storage->value,
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $table->insert($row + ['row_version' => 1, 'created_at' => $at->format('Y-m-d H:i:s')]);

            return 1;
        }

        $next = (int) $existing['row_version'] + 1;

        TenantScopedQuery::on($this->pdo, $this->tenant, 'entity_field_values')
            ->where('id', '=', (int) $existing['id'])
            // R-X-043: optimistic concurrency. A write from a stale read affects
            // zero rows rather than overwriting somebody's change.
            ->where('row_version', '=', (int) $existing['row_version'])
            ->update($row + ['row_version' => $next]);

        return $next;
    }

    private function writeFieldChange(
        PreparedWrite $prepared,
        \SmartPRS\Platform\Entity\FieldOutcome $outcome,
        int $entryId,
        int $entityId,
        \DateTimeImmutable $at,
    ): void {
        // $this->definitions is keyed by field, but PHPStan optimistically treats a
        // dynamic-key lookup into array<string, FieldDefinition> as never missing.
        // It can genuinely miss: writeValue()'s own guard above documents that the
        // definitions this store was built with and the ones the service resolved
        // outcomes against can be two different sets. Falling back to 'none' here
        // (rather than throwing, as writeValue does) means a missing definition is
        // treated as non-restricted for audit purposes, so the null case below is
        // spelled out (PHPStan flags `?->` immediately before `??` as unnecessary
        // purely on the shape of the expression, regardless of real nullability).
        /** @var FieldDefinition|null $definition */
        $definition = $this->definitions[$outcome->fieldKey] ?? null;
        $piiClass = $definition === null ? 'none' : $definition->piiClass;

        // X.2.6: a restricted field records THAT it changed and a hash proving
        // what to, never the value. An audit trail that leaks the thing it is
        // protecting is a second copy of the secret.
        $keepPlaintext = !in_array($piiClass, ['sensitive', 'restricted'], true);

        TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_field_changes')->insert([
            'audit_entry_id' => $entryId,
            'entity_key' => $prepared->command->entityKey,
            'entity_id' => $entityId,
            'field_key' => $outcome->fieldKey,
            'surface_code' => $prepared->command->surface->value,
            'old_value' => $keepPlaintext ? self::render($outcome->oldValue) : null,
            'new_value' => $keepPlaintext ? self::render($outcome->newValue) : null,
            'old_value_hash' => self::hash($outcome->oldValue),
            'new_value_hash' => self::hash($outcome->newValue),
            'pii_class_code' => $piiClass,
            'outcome_code' => $outcome->status,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }

    private function auditEntryId(int $seq): int
    {
        $row = TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_entries')
            ->where('seq', '=', $seq)
            ->first(['id']);

        if ($row === null) {
            throw new \RuntimeException("The audit entry at seq {$seq} was written and cannot be read back.");
        }

        return (int) $row['id'];
    }

    private static function store(StorageType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            StorageType::Date => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
            StorageType::DateTime => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : (string) $value,
            StorageType::Bool => $value ? 1 : 0,
            StorageType::Money => $value instanceof Money ? $value->toDecimalString() : (string) $value,
            StorageType::CodeList, StorageType::Json, StorageType::Composite
                => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            default => $value,
        };
    }

    private static function render(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Money => $value->toDecimalString(),
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            default => (string) $value,
        };
    }

    /**
     * The hash is written for EVERY class, not only the restricted ones.
     *
     * For a masked field it is the only proof of what the value was; for an
     * ordinary one it is what lets a later export be checked against the ledger
     * without reading either. A hash present on some rows and absent on others
     * is a hash nobody can write a query against.
     */
    private static function hash(mixed $value): ?string
    {
        $rendered = self::render($value);

        return $rendered === null ? null : hash('sha256', $rendered);
    }
}
