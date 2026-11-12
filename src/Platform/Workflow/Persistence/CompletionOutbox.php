<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * THE COMPLETION OUTBOX — Part IX §IX.6.3 mechanism (4), R-IX-026.
 *
 * `finalise` emits through an outbox with UNIQUE (tenant_id, instance_id,
 * completion_event_key), "so even a hypothetical double-finalise cannot produce
 * two transfer orders".
 *
 * That is not a hypothetical. SmartPRS 2's transfer-order and increment-letter
 * side effects are unguarded and re-fire (RequestController.php:1470-1492): the
 * approval writes the effect inline, so a retried request writes it twice, and
 * nothing downstream can tell the difference between two approvals and one
 * approval delivered twice.
 *
 * The row is written in the SAME TRANSACTION as the finalise it belongs to, so
 * an instance cannot complete without its event, and an event cannot exist for
 * an instance that did not complete. Dispatch is a separate, later, retryable
 * step — which is the whole point of an outbox.
 */
final class CompletionOutbox
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Enqueue the completion event, once.
     *
     * @param array<string, mixed> $payload
     * @return bool true when this call enqueued it, false when it was already there
     */
    public function enqueue(
        int $instanceId,
        string $eventKey,
        array $payload,
        \DateTimeImmutable $availableAt,
    ): bool {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'The completion event is written in the transaction that finalises the instance. Outside one, '
                . 'an instance can complete with no event, or an event can outlive a rolled-back completion.'
            );
        }

        try {
            TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_completion_outbox')->insert([
                'workflow_instance_id' => $instanceId,
                'completion_event_key' => $eventKey,
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'attempts' => 0,
            ]);

            return true;
        } catch (\PDOException $e) {
            // 23000 is the integrity-constraint class; the unique index did its
            // job. Anything else is not this method's to interpret.
            if (!str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            return false;
        }
    }

    /** @return list<array<string, mixed>> events due for dispatch */
    public function due(\DateTimeImmutable $now, int $limit = 100): array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_completion_outbox')
            ->whereNull('dispatched_at')
            ->where('available_at', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit($limit)
            ->select();
    }

    public function markDispatched(int $id, \DateTimeImmutable $at): void
    {
        TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_completion_outbox')
            ->where('id', '=', $id)
            ->update(['dispatched_at' => $at->format('Y-m-d H:i:s')]);
    }
}
