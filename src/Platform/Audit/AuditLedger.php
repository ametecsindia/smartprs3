<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Audit;

use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * THE ONLY WRITER TO `audit_entries` — Law 5 / spine S14.
 *
 * `HashChain` computes hashes; this puts them in a table in an order that
 * cannot be raced. Two properties have to hold at once, and both are why the
 * write is serialised per tenant:
 *
 *   1. `seq` is GAPLESS per tenant. A gap is indistinguishable from a deleted
 *      entry, so a ledger with gaps proves nothing.
 *   2. Each entry's `prev_hash` is the hash of the entry at `seq - 1`. Two
 *      concurrent writers reading the same head would produce two entries both
 *      claiming the same predecessor, which is a fork, not a chain.
 *
 * Both are satisfied by taking a row lock on the TENANT for the duration of the
 * append. A per-tenant hash chain is inherently serial — there is one head —
 * so this is not a shortcut around concurrency, it is what the data structure
 * means.
 *
 * ponytail: the head is read with ORDER BY seq DESC LIMIT 1 under that lock,
 * rather than from a counter column on `tenants`. One row lock, no migration,
 * and the index is already there. Ceiling: one audit write at a time per
 * tenant. If a tenant ever writes fast enough for that to matter, add
 * `next_audit_seq` to `tenants` and take the number from the lock you are
 * already holding — the shape of this class does not change.
 *
 * There is no update method and no delete method here, deliberately, and the
 * application database account holds no UPDATE or DELETE on this table either
 * (tools/gates/checks/db-privilege.php). Append-only twice: once because no
 * code could, once because the grant would refuse it if there were.
 */
final class AuditLedger
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Append one entry, allocating its seq and linking it to the chain head
     * under a lock on the tenant.
     *
     * MUST be called inside the transaction that performs the action being
     * recorded. An audit row committed separately from what it describes can be
     * present when the action rolled back, or absent when it did not — and both
     * are worse than no ledger, because both look like evidence.
     *
     * @return array{seq: int, hash: string, uuid: string}
     */
    public function append(AuditEntry $entry): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'An audit entry is appended inside the transaction that performs the action. Writing it in '
                . 'its own transaction records something that may not have happened (Law 5).'
            );
        }

        $head = $this->lockAndReadHead();
        $seq = $head === null ? 1 : (int) $head['seq'] + 1;
        $previousHash = $head === null ? null : (string) $head['hash'];
        $hash = HashChain::next($previousHash, $entry->payload());
        $uuid = random_bytes(16);

        TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_entries')->insert([
            'uuid' => $uuid,
            'seq' => $seq,
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            'business_on' => $entry->businessOn->format('Y-m-d'),
            'actor_user_id' => $entry->actorUserId,
            'actor_label' => $entry->actorLabel,
            'actor_ip' => $entry->actorIp,
            'actor_device' => $entry->actorDevice,
            'action' => $entry->action,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'old_value' => $entry->oldValue === null ? null : self::json($entry->oldValue),
            'new_value' => $entry->newValue === null ? null : self::json($entry->newValue),
            'reason' => $entry->reason,
            'workflow_instance_id' => $entry->workflowInstanceId,
            'rule_version_id' => $entry->ruleVersionId,
            'request_id' => $entry->requestId,
            'correlation_id' => $entry->correlationId,
            'prev_hash' => $previousHash,
            'hash' => $hash,
            'created_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
        ]);

        return ['seq' => $seq, 'hash' => $hash, 'uuid' => bin2hex($uuid)];
    }

    /**
     * Walk this tenant's chain and return the first break, or null.
     *
     * The payload is rebuilt through `AuditEntry` rather than from the stored
     * columns directly, so verification exercises the SAME construction the
     * write used. Reading the columns into a hand-built array here would give
     * two definitions of what was hashed, and the first divergence between them
     * would present itself as tampering.
     *
     * @return array{index: int, seq: int, reason: string}|null
     */
    public function verify(): ?array
    {
        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_entries')
            ->orderBy('seq')
            ->select();

        $expectedSeq = 1;

        foreach ($rows as $row) {
            if ((int) $row['seq'] !== $expectedSeq) {
                return [
                    'index' => $expectedSeq - 1,
                    'seq' => (int) $row['seq'],
                    'reason' => "Gap in the chain: expected seq {$expectedSeq}, found {$row['seq']}. "
                        . 'A gap is indistinguishable from a deleted entry.',
                ];
            }

            $expectedSeq++;
        }

        $break = HashChain::verify(array_map(
            static fn (array $row): array => [
                'payload' => self::entryOf($row)->payload(),
                'prev_hash' => $row['prev_hash'],
                'hash' => (string) $row['hash'],
            ],
            $rows,
        ));

        if ($break === null) {
            return null;
        }

        return $break + ['seq' => (int) ($rows[$break['index']]['seq'] ?? 0)];
    }

    /** @return array<string, mixed>|null the tenant's current chain head */
    public function head(): ?array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_entries')
            ->orderBy('seq', 'DESC')
            ->limit(1)
            ->first();
    }

    /**
     * Close a day: write the S14 anchor over that day's chain head.
     *
     * The anchor is what makes a break attributable to a DATE rather than to
     * "sometime in the last three years", and it is what a signature can later
     * be attached to. Writing it is idempotent per (tenant, date) by the unique
     * index; re-anchoring a day whose entries changed collides, which is the
     * detection working rather than failing.
     *
     * @return array{first_seq: int, last_seq: int, entry_count: int, anchor_hash: string}|null
     *         null when the tenant wrote nothing that day
     */
    public function anchor(\DateTimeImmutable $on, \DateTimeImmutable $at): ?array
    {
        $day = $on->format('Y-m-d');

        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_entries')
            ->where('business_on', '=', $day)
            ->orderBy('seq')
            ->select(['seq', 'hash']);

        if ($rows === []) {
            return null;
        }

        $first = (int) $rows[0]['seq'];
        $last = (int) $rows[count($rows) - 1]['seq'];
        $headHash = (string) $rows[count($rows) - 1]['hash'];

        $anchorHash = HashChain::anchor($this->tenant->tenantId(), $day, $headHash, count($rows));

        TenantScopedQuery::on($this->pdo, $this->tenant, 'audit_anchors')->insert([
            'anchor_on' => $day,
            'first_seq' => $first,
            'last_seq' => $last,
            'entry_count' => count($rows),
            'chain_head_hash' => $headHash,
            'anchor_hash' => $anchorHash,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);

        return [
            'first_seq' => $first,
            'last_seq' => $last,
            'entry_count' => count($rows),
            'anchor_hash' => $anchorHash,
        ];
    }

    /**
     * The head, under a lock on the tenant row.
     *
     * The lock is on `tenants`, not on the last audit row: locking the last row
     * does not stop a second writer that has not read it yet from inserting one
     * of its own. Locking the parent every writer must pass through does.
     */
    private function lockAndReadHead(): ?array
    {
        $tenant = TenantScopedQuery::on($this->pdo, $this->tenant, 'tenants')
            ->where('id', '=', (int) $this->tenant->tenantId())
            ->forUpdate()
            ->first(['id']);

        if ($tenant === null) {
            throw new \RuntimeException(
                'Tenant ' . $this->tenant->tenantId() . ' does not exist, so there is no chain to append to.'
            );
        }

        return $this->head();
    }

    /** Rebuild the value object a stored row came from. */
    private static function entryOf(array $row): AuditEntry
    {
        return new AuditEntry(
            action: (string) $row['action'],
            entityType: (string) $row['entity_type'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at']),
            businessOn: new \DateTimeImmutable((string) $row['business_on']),
            entityId: $row['entity_id'] === null ? null : (int) $row['entity_id'],
            actorUserId: $row['actor_user_id'] === null ? null : (string) $row['actor_user_id'],
            actorLabel: $row['actor_label'],
            actorIp: $row['actor_ip'],
            actorDevice: $row['actor_device'],
            oldValue: self::decode($row['old_value']),
            newValue: self::decode($row['new_value']),
            reason: $row['reason'],
            ruleVersionId: $row['rule_version_id'] === null ? null : (int) $row['rule_version_id'],
            workflowInstanceId: $row['workflow_instance_id'] === null ? null : (int) $row['workflow_instance_id'],
            requestId: $row['request_id'],
            correlationId: $row['correlation_id'],
        );
    }

    /** @return array<string, mixed>|null */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
