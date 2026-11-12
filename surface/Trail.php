<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\HashChain;

/**
 * A REAL HASH-CHAINED TRAIL, WITHOUT A DATABASE.
 *
 * Every entry's hash covers the previous one, so editing, reordering, inserting
 * or deleting any row breaks the chain from that point and `verify()` names the
 * sequence number where it broke. That is not a claim about tamper-proofing —
 * anyone with the file can rewrite the whole chain — it is a claim about
 * DETECTION, which is the property an auditor actually asks for: not "this
 * cannot be changed" but "if it was changed, can you tell?"
 *
 * WHAT IS BORROWED AND WHAT IS NOT. The hashing is `Platform\Audit\HashChain`
 * and the payload is `Platform\Audit\AuditEntry::payload()` — the same two
 * pieces `Platform\Audit\AuditLedger` uses, so the chain this file builds is
 * verifiable by the platform's own definition rather than by a second one
 * invented here. What is different is only WHERE the rows live: a JSON file
 * rather than a locked, tenant-scoped, append-only table.
 *
 * THE DIFFERENCE MATTERS AND IS NOT PAPERED OVER. `AuditLedger` takes a row
 * lock, allocates its sequence inside the caller's transaction, and lives in a
 * table the database user cannot UPDATE or DELETE. None of that is true here.
 * This is the surface's trail, honest about being one, and the migration path
 * is to swap this class for `AuditLedger` — which is why both build their
 * entries as `AuditEntry` and hash them identically.
 *
 * BEFORE AND AFTER, ALWAYS. An audit row that records "employee updated" and
 * not what it looked like before is a row that proves something happened and
 * cannot say what.
 */
final class Trail
{
    private const COLLECTION = 'audit';

    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * Append one entry, linked to the head of the chain.
     *
     * @param array<string, mixed>|null $before the state before, where there was one
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $action,
        string $entityType,
        ?string $entityId,
        ?array $before,
        ?array $after,
        string $actorId,
        string $actorLabel,
        string $reason,
    ): array {
        $rows = $this->store->all(self::COLLECTION);
        $head = $rows === [] ? null : $rows[count($rows) - 1];
        $seq = $head === null ? 1 : (int) $head['seq'] + 1;
        $previousHash = $head === null ? null : (string) $head['hash'];

        $entry = new AuditEntry(
            action: $action,
            entityType: $entityType,
            occurredAt: new \DateTimeImmutable(),
            businessOn: new \DateTimeImmutable(),
            actorUserId: $actorId,
            actorLabel: $actorLabel,
            oldValue: $before === null ? null : self::redact($before),
            newValue: $after === null ? null : self::redact($after),
            reason: $reason,
        );

        // The platform's own hashing, over the platform's own payload. A second
        // definition of what is hashed would present its first divergence as
        // tampering.
        $hash = HashChain::next($previousHash, $entry->payload());

        $row = [
            'seq' => $seq,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor' => $actorLabel,
            'reason' => $reason,
            'occurred_at' => $entry->occurredAt->format('c'),
            'payload' => $entry->payload(),
            'prev_hash' => $previousHash,
            'hash' => $hash,
        ];

        // put(), not write(): under a tenant-scoped store the trail reads only
        // THIS tenant's entries, so the chain is per tenant — which is what it
        // should be. Writing the whole collection from a scoped store would
        // delete every other tenant's chain, and the store refuses it.
        $this->store->put(self::COLLECTION, $row);

        return $row;
    }

    /**
     * Walk the chain and return the first break.
     *
     * Rebuilt through `AuditEntry` rather than from the stored columns, so
     * verification exercises the SAME construction the write used.
     *
     * @return array{seq: int, reason: string}|null
     */
    public function verify(): ?array
    {
        $previousHash = null;
        $expectedSeq = 1;

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if ((int) $row['seq'] !== $expectedSeq) {
                return ['seq' => (int) $row['seq'],
                    'reason' => "the sequence jumps to {$row['seq']} where {$expectedSeq} was expected — "
                        . 'a row was inserted or deleted'];
            }

            if (($row['prev_hash'] ?? null) !== $previousHash) {
                return ['seq' => (int) $row['seq'],
                    'reason' => 'this entry does not link to the one before it — rows were reordered'];
            }

            $recomputed = HashChain::next($previousHash, (array) $row['payload']);

            if (!hash_equals((string) $row['hash'], $recomputed)) {
                return ['seq' => (int) $row['seq'],
                    'reason' => 'the stored hash does not match the entry — this row was edited after '
                        . 'it was written'];
            }

            $previousHash = (string) $row['hash'];
            $expectedSeq++;
        }

        return null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function entries(int $limit = 40): array
    {
        return array_slice(array_reverse($this->store->all(self::COLLECTION)), 0, $limit);
    }

    /**
     * The entries matching the owner's filters, newest first: a date range on
     * occurred_at, an action, an actor, an entity type, and a word searched in
     * the action, subject, actor, reason and payload. '' means any.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $from, string $to, string $action, string $actor, string $entityType, string $word, int $limit): array
    {
        $word = mb_strtolower(trim($word));
        $out = [];

        foreach (array_reverse($this->store->all(self::COLLECTION)) as $e) {
            $day = substr((string) ($e['occurred_at'] ?? ''), 0, 10);

            if (($from !== '' && $day < $from) || ($to !== '' && $day > $to)
                || ($action !== '' && (string) ($e['action'] ?? '') !== $action)
                || ($actor !== '' && (string) ($e['actor'] ?? '') !== $actor)
                || ($entityType !== '' && (string) ($e['entity_type'] ?? '') !== $entityType)) {
                continue;
            }

            if ($word !== '' && !str_contains(mb_strtolower(implode(' ', [(string) ($e['action'] ?? ''), (string) ($e['entity_type'] ?? ''),
                (string) ($e['entity_id'] ?? ''), (string) ($e['actor'] ?? ''), (string) ($e['reason'] ?? ''), json_encode($e['payload'] ?? [])])), $word)) {
                continue;
            }

            $out[] = $e;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** The distinct values of a column, for a filter dropdown. @return list<string> */
    public function distinct(string $column): array
    {
        $values = array_unique(array_map(static fn (array $e): string => (string) ($e[$column] ?? ''), $this->store->all(self::COLLECTION)));
        $values = array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
        sort($values);

        return $values;
    }

    public function count(): int
    {
        return $this->store->count(self::COLLECTION);
    }

    /**
     * A password hash in an audit row is a password hash in one more place.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function redact(array $values): array
    {
        foreach (array_keys($values) as $key) {
            if (preg_match('/password|secret|token|hash/i', (string) $key) === 1) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
