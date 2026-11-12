<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Config;

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * THE WRITE SIDE OF SPINE S4 — the only way a version becomes `published`.
 *
 * PublishGates and Validator are things you CAN call. This is what makes them
 * things that MUST have been called: there is no path to a published version
 * that does not run a report, store its outcome per gate, and refuse on a block.
 *
 * Five things happen here, and each is a defect in
 * SmartPRS 2 when it does not:
 *
 *   1. The gates run and their outcomes are STORED — R-VIII-013, R-IX-018.
 *      A blocked publication is explainable and a passed one is evidenced.
 *      "The usual checks were run" is not an answer about a payslip three
 *      years later.
 *   2. version_no is allocated under a lock on the artefact. Two publications
 *      racing must not both become v4; MAX(version_no) + 1 outside a lock is
 *      exactly how they would.
 *   3. The previous version IN THE SAME SCOPE is closed the day before the new
 *      one starts. Not every previous version — a Hyderabad version does not
 *      supersede the tenant default, and closing it would silently widen what
 *      the new one governs.
 *   4. Overlap is refused after the write, inside the transaction, by
 *      re-reading what is now published. Checking before the write tests a
 *      state that another transaction can change; checking after and rolling
 *      back tests the state that would actually exist.
 *   5. A ledger entry is appended — `config.published` or, for a refusal,
 *      `config.publish_blocked` — inside the same transaction as the outcome it
 *      describes. Law 5: who changed the configuration, when, and why. A
 *      publication with a stored gate report but nothing in the ledger tells
 *      you the checks passed and not who ran them.
 */
final class Publisher
{
    /**
     * The ledger is optional so a checkout with no audit tables can still
     * publish — but its absence is a decision the CALLER makes and can be seen
     * in the constructor, not a silent skip inside a method. Every path that
     * has a ledger writes to it; the only way not to write is not to have one.
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly StatusLookup $statuses,
        private readonly ConfigVersionRepository $versions,
        private readonly ?AuditLedger $ledger = null,
    ) {
    }

    /**
     * Publish a new version of an artefact.
     *
     * @param array<string, mixed> $payload   the typed payload row, without ids
     * @param array<string, string> $audit    created_by, approved_by, reason, change_note
     * @param ?callable(int): void $children  writes the rest of a multi-table payload,
     *                                        inside this method's own transaction,
     *                                        given the new payload row's id
     * @return int the new config_versions id
     *
     * @throws PublicationRefused when any gate blocks
     */
    public function publish(
        string $artefactType,
        string $artefactKey,
        string $payloadTable,
        array $payload,
        Scope $scope,
        EffectiveRange $range,
        PublishReport $report,
        \DateTimeImmutable $now,
        int $priority = 0,
        string $checksum = '',
        array $audit = [],
        ?callable $children = null,
    ): int {
        if (!in_array($payloadTable, ConfigVersionRepository::PAYLOAD_TABLES, true)) {
            throw new \InvalidArgumentException(
                "'{$payloadTable}' is not a registered payload table. A new configurable concept adds an "
                . 'artefact_type and a typed payload table, never a second versioning mechanism (R-V-050).'
            );
        }

        if ($this->pdo->inTransaction()) {
            throw new \LogicException(
                'Publisher opens its own transaction: the gate outcomes must be written even when the '
                . 'publication is refused, and a caller-owned transaction would roll them back with it.'
            );
        }

        $artefactId = $this->artefactId($artefactType, $artefactKey);

        // The outcomes are stored WHETHER OR NOT the publication proceeds, and
        // committed separately, so a refusal leaves its twelve rows behind.
        $this->pdo->beginTransaction();
        $versionId = $this->reserveVersion($artefactId, $scope, $range, $priority, $checksum, $audit, $now);
        $this->recordChecks($versionId, $report, $now);

        // A refusal is recorded HERE, with the checks, because that is the
        // transaction whose outcome it describes. Recorded after the throw it
        // would be a separate write that a crash could lose, and a refusal
        // nobody can find is how "the system just wouldn't let me" becomes a
        // support ticket instead of an answer.
        if (!$report->isPublishable()) {
            $this->record('config.publish_blocked', $artefactType, $artefactKey, $versionId, $scope, $range, $report, $audit, $now);
            $this->pdo->commit();

            throw new PublicationRefused($report, $artefactType, $artefactKey);
        }

        $this->pdo->commit();

        $this->pdo->beginTransaction();

        try {
            $payloadId = TenantScopedQuery::on($this->pdo, $this->tenant, $payloadTable)
                ->insert($payload + ['config_version_id' => $versionId]);

            // A payload that spans more than one table writes the rest HERE,
            // inside the same transaction, so a crash cannot leave a published
            // workflow with a header and no nodes. A rule and a formula are one
            // row and pass nothing; a workflow is four tables and passes a
            // closure. The alternative — a second transaction afterwards — is
            // how SmartPRS 2 ends up with approval chains that have no steps.
            if ($children !== null) {
                $children((int) $payloadId);
            }

            TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
                ->where('id', '=', $versionId)
                ->update([
                    'payload_table' => $payloadTable,
                    'payload_id' => (int) $payloadId,
                    'status_value_id' => $this->statuses->id('config_version_status', 'published'),
                    'published_at' => $now->format('Y-m-d H:i:s'),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);

            $this->closePrevious($artefactId, $versionId, $scope, $range, $now);

            // Point 4: assert against what is now published, then roll back if
            // it does not hold. Checking beforehand tests a state another
            // transaction can change between the check and the write.
            (new ArtefactResolver($this->versions->candidates($artefactType, $artefactKey, $range->from)))
                ->assertNoOverlap();

            $this->record('config.published', $artefactType, $artefactKey, $versionId, $scope, $range, $report, $audit, $now);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $versionId;
    }

    /**
     * Append the ledger entry for a publication or a refusal.
     *
     * `new_value` is the version as it now stands plus a per-gate summary, not
     * the payload: the payload is already an immutable row this entry points
     * at, and copying it would make the ledger a second, divergent copy of the
     * configuration. What the ledger adds is WHO, WHEN, WHY, and what the gates
     * said at the moment it happened.
     *
     * @param array<string, string> $audit
     */
    private function record(
        string $action,
        string $artefactType,
        string $artefactKey,
        int $versionId,
        Scope $scope,
        EffectiveRange $range,
        PublishReport $report,
        array $audit,
        \DateTimeImmutable $now,
    ): void {
        if ($this->ledger === null) {
            return;
        }

        $this->ledger->append(new AuditEntry(
            action: $action,
            entityType: 'config_versions',
            occurredAt: $now,
            // The tenant business date is the date `$now` falls on in the
            // tenant's own timezone. Until tenant timezone is modelled the
            // caller's date is used, and the guess is HERE, at the call site,
            // rather than hidden inside the ledger where nobody would find it.
            businessOn: $now,
            entityId: $versionId,
            actorUserId: $audit['created_by'] ?? null,
            actorLabel: $audit['created_by_label'] ?? null,
            newValue: [
                'artefact' => $artefactType . ':' . $artefactKey,
                'scope' => $scope->toJson(),
                'effective_from' => $range->from->format('Y-m-d'),
                'effective_to' => $range->effectiveTo()?->format('Y-m-d'),
                'approved_by' => $audit['approved_by'] ?? null,
                'gates' => $report->outcomes(),
            ],
            reason: $audit['reason'] ?? null,
            ruleVersionId: $versionId,
        ));
    }

    /**
     * Reserve the next version number under a lock on the artefact.
     *
     * The row is written as `draft` and only becomes `published` once the
     * payload is in place, so a crash between the two leaves a draft rather
     * than a published version pointing at nothing.
     *
     * @param array<string, string> $audit
     */
    private function reserveVersion(
        int $artefactId,
        Scope $scope,
        EffectiveRange $range,
        int $priority,
        string $checksum,
        array $audit,
        \DateTimeImmutable $now,
    ): int {
        // The lock: two publications racing must not both become v4.
        $artefact = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_artefacts')
            ->where('id', '=', $artefactId)
            ->forUpdate()
            ->first(['id']);

        if ($artefact === null) {
            throw new \RuntimeException("Config artefact {$artefactId} does not exist in this tenant.");
        }

        $existing = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
            ->where('config_artefact_id', '=', $artefactId)
            ->orderBy('version_no', 'DESC')
            ->limit(1)
            ->first(['version_no']);

        $versionNo = $existing === null ? 1 : (int) $existing['version_no'] + 1;

        $id = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')->insert([
            'config_artefact_id' => $artefactId,
            'status_value_id' => $this->statuses->id('config_version_status', 'draft'),
            'uuid' => random_bytes(16),
            'version_no' => $versionNo,
            'scope_json' => $scope->toJson(),
            'specificity' => $scope->specificity(),
            'priority' => $priority,
            'effective_from' => $range->from->format('Y-m-d'),
            'effective_to' => $range->effectiveTo()?->format('Y-m-d'),
            'payload_table' => '',
            'payload_id' => 0,
            'checksum' => $checksum === '' ? str_repeat('0', 64) : $checksum,
            'reason' => $audit['reason'] ?? null,
            'change_note' => $audit['change_note'] ?? null,
            'created_by_user_id' => $audit['created_by'] ?? null,
            'approved_by_user_id' => $audit['approved_by'] ?? null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $versionId = (int) $id;

        foreach ($scope->predicates as $dimension => $values) {
            foreach ($values as $value) {
                TenantScopedQuery::on($this->pdo, $this->tenant, 'config_version_scopes')->insert([
                    'config_version_id' => $versionId,
                    'dimension' => $dimension,
                    'operator' => 'IN',
                    'value' => (string) $value,
                    'weight' => Scope::WEIGHTS[$dimension] ?? 0,
                ]);
            }
        }

        return $versionId;
    }

    /** One row per gate, whatever it said (§14.5). */
    private function recordChecks(int $versionId, PublishReport $report, \DateTimeImmutable $now): void
    {
        foreach ($report->checks as $check) {
            TenantScopedQuery::on($this->pdo, $this->tenant, 'config_publication_checks')->insert([
                'config_version_id' => $versionId,
                'gate_code' => $check->gate,
                'gate_name' => $check->name,
                'severity' => $check->severity,
                'messages_json' => $check->messages === []
                    ? null
                    : json_encode($check->messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'checked_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Close the open version THIS ONE SUPERSEDES — same artefact, same scope.
     *
     * Scope-aware on purpose. A Hyderabad version does not supersede the tenant
     * default: they govern different employees, and closing the default would
     * silently leave everyone outside Hyderabad with no configuration at all.
     */
    private function closePrevious(
        int $artefactId,
        int $newVersionId,
        Scope $scope,
        EffectiveRange $range,
        \DateTimeImmutable $now,
    ): void {
        $published = $this->statuses->id('config_version_status', 'published');
        $dayBefore = $range->from->modify('-1 day')->format('Y-m-d');

        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
            ->where('config_artefact_id', '=', $artefactId)
            ->where('status_value_id', '=', $published)
            ->where('id', '<>', $newVersionId)
            ->whereNull('effective_to')
            ->select(['id', 'scope_json']);

        foreach ($rows as $row) {
            if ((string) $row['scope_json'] !== $scope->toJson()) {
                continue;
            }

            TenantScopedQuery::on($this->pdo, $this->tenant, 'config_versions')
                ->where('id', '=', (int) $row['id'])
                ->update([
                    'effective_to' => $dayBefore,
                    'status_value_id' => $this->statuses->id('config_version_status', 'superseded'),
                    'superseded_by_version_id' => $newVersionId,
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
        }
    }

    private function artefactId(string $type, string $key): int
    {
        $row = TenantScopedQuery::on($this->pdo, $this->tenant, 'config_artefacts')
            ->where('artefact_type', '=', $type)
            ->where('artefact_key', '=', $key)
            ->first(['id']);

        if ($row === null) {
            throw new \RuntimeException(
                "No config artefact {$type}:{$key} in this tenant. An artefact is created before its first "
                . 'version, so that a version always has something to be a version OF.'
            );
        }

        return (int) $row['id'];
    }
}
