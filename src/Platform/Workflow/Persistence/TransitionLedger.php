<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * THE APPEND-ONLY TRANSITION LEDGER — Part IX §IX.6.3 mechanism (3).
 *
 * `workflow_transitions` carries UNIQUE (tenant_id, instance_id, seq), and seq
 * is allocated under a row lock on the instance. A replayed decision cannot
 * obtain a second seq for the same instance, so a duplicate write collides with
 * the unique index rather than appending a second history.
 *
 * R-IX-050: a decision that arrives after its node was already decided is still
 * written, with was_attempt = true. That a second approver disagreed is
 * material even when it does not change the outcome — and SmartPRS 2 keeps no
 * record of it at all.
 *
 * There is no update method and no delete method on this class, deliberately.
 * The application database account has no DELETE on this table either
 * (tools/gates/checks/db-privilege.php), so append-only is enforced twice: once
 * because there is no code that could, and once because the grant would refuse
 * the code if there were.
 *
 * R-IX-105/106 (WC-08). The transition ledger and the AUDIT ledger were
 * unconnected until now: this file contained no reference to audit at all, so
 * "every transition is audited" was a sentence in a blueprint and nothing else.
 * The AuditLedger is a REQUIRED constructor dependency rather than an optional
 * one, because an optional audit is an audit that is null in exactly the
 * deployment nobody checked. R-IX-105 is satisfied by construction — this class
 * calls the audit service and has no INSERT of its own into `audit_entries` —
 * and R-IX-106 by the shared transaction: append() already refuses to run
 * outside one, and AuditLedger::append() throws rather than swallowing, so an
 * audit failure takes the transition down with it. Auditing is not best-effort.
 */
final class TransitionLedger
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly AuditLedger $audit,
    ) {
    }

    /**
     * Append one transition, allocating its seq under a lock on the instance.
     *
     * MUST be called inside the transaction that also writes the decision, so
     * the lock covers both. R-IX-049: node completion is evaluated under a row
     * lock on the instance and that is the only place outcomes are computed.
     *
     * @param array<string, mixed>|null $resolution R-IX-028's record of who was
     *        resolved, from which spec, and every exclusion applied
     */
    public function append(
        int $instanceId,
        string $outcome,
        \DateTimeImmutable $at,
        ?string $fromNodeKey = null,
        ?string $toNodeKey = null,
        ?int $taskId = null,
        ?string $actorUserId = null,
        ?string $comment = null,
        bool $wasAttempt = false,
        ?array $resolution = null,
    ): int {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'A transition is appended inside the transaction that decides it. Allocating a seq outside '
                . 'one takes a lock that is released before the decision is written, which is no lock at all.'
            );
        }

        $seq = $this->nextSeq($instanceId);

        TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_transitions')->insert([
            'workflow_instance_id' => $instanceId,
            'seq' => $seq,
            'from_node_key' => $fromNodeKey,
            'outcome_code' => $outcome,
            'to_node_key' => $toNodeKey,
            'workflow_task_id' => $taskId,
            'actor_user_id' => $actorUserId,
            'comment' => $comment,
            'was_attempt' => $wasAttempt ? 1 : 0,
            'resolution_json' => $resolution === null
                ? null
                : json_encode($resolution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at->format('Y-m-d H:i:s'),
        ]);

        // Same transaction, no try/catch: if this throws, the transition it
        // describes is rolled back with it (R-IX-106).
        $this->audit->append(new AuditEntry(
            action: $wasAttempt ? 'workflow.transition_attempted' : 'workflow.transitioned',
            entityType: 'workflow_instance',
            occurredAt: $at,
            businessOn: $at,
            entityId: $instanceId,
            actorUserId: $actorUserId,
            newValue: [
                'seq' => $seq,
                'from_node_key' => $fromNodeKey,
                'outcome_code' => $outcome,
                'to_node_key' => $toNodeKey,
                'workflow_task_id' => $taskId,
                'was_attempt' => $wasAttempt,
                'resolution' => $resolution,
            ],
            reason: $comment,
            workflowInstanceId: $instanceId,
        ));

        return $seq;
    }

    /** @return list<array<string, mixed>> the instance's history, in order */
    public function history(int $instanceId): array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_transitions')
            ->where('workflow_instance_id', '=', $instanceId)
            ->orderBy('seq')
            ->select();
    }

    /**
     * The next seq, taken under a lock on the instance row.
     *
     * The counter lives on the instance rather than being derived with MAX(seq),
     * because MAX over the ledger under READ COMMITTED can hand the same number
     * to two transactions. Two transactions cannot hold this row's lock at once.
     */
    private function nextSeq(int $instanceId): int
    {
        $instance = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_instances')
            ->where('id', '=', $instanceId)
            ->forUpdate()
            ->first(['id', 'next_seq']);

        if ($instance === null) {
            throw new \RuntimeException("Workflow instance {$instanceId} does not exist in this tenant.");
        }

        $seq = (int) $instance['next_seq'];

        TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_instances')
            ->where('id', '=', $instanceId)
            ->update(['next_seq' => $seq + 1]);

        return $seq;
    }
}
