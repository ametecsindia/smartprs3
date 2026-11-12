<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\TaskState;

/**
 * THE ONLY PLACE A WORKFLOW DECISION IS WRITTEN — Part IX §IX.6.3 mechanism (1).
 *
 * "There is no code path that writes a decision without the
 * `status_code = 'pending'` predicate." That is this file, and the reason it is
 * one method rather than a helper anyone can assemble:
 *
 *     UPDATE workflow_tasks
 *        SET status_value_id = :outcome, decided_by_user_id = :actor, …
 *            row_version = row_version + 1
 *      WHERE id = :task AND tenant_id = :tenant
 *        AND status_value_id = :pending AND row_version = :expected
 *
 * Zero affected rows means somebody else decided first. The caller is handed
 * the decision that already stands, as a 409 — never a silent overwrite, and
 * never a second decision.
 *
 * §IX.6.3 asks for a static-analysis rule that fails the build on any UPDATE of
 * workflow_tasks' status outside this method. tools/gates/ does not carry one
 * yet; what stands in the meantime is that the column is only reachable through
 * TenantScopedQuery, and this is the only caller that names it.
 *
 * R-IX-107 (WC-08): "Every refused decision attempt MUST be audited — wrong
 * actor, wrong permission, already decided, segregation violation,
 * self-approval violation, cancelled instance." Who TRIED to approve a payroll
 * run they were not entitled to approve is exactly the fact an auditor asks
 * for, and it was unrecorded. `decide()` audits the two refusals it can see
 * itself (already decided, stale row version); `refuse()` is where every
 * upstream check — permission, segregation, self-approval, a cancelled
 * instance — records the attempt it turned away. A refusal that returns 403 and
 * writes nothing is the state this codebase is replacing.
 */
final class TaskRepository
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
        private readonly StatusLookup $states,
        private readonly AuditLedger $audit,
    ) {
    }

    /**
     * Record a decision on a pending task.
     *
     * @return array{decided: bool, task: array<string, mixed>|null}
     *         decided=false means somebody decided first; `task` is what stands.
     */
    public function decide(
        int $taskId,
        TaskState $outcome,
        string $actorUserId,
        \DateTimeImmutable $at,
        int $expectedRowVersion,
        ?string $comment = null,
    ): array {
        if (!TaskState::Pending->canMoveTo($outcome)) {
            throw new \InvalidArgumentException(
                "'{$outcome->value}' is not an outcome a pending task can take (§IX.6.2)."
            );
        }

        // ponytail: decide() opens its own transaction when the caller has not.
        // The decision and its audit entry commit together or not at all
        // (R-IX-106), and a REFUSAL keeps its own record even though nothing
        // else changed — which it would not if it rode inside a caller
        // transaction that then rolled back. Ceiling: a caller that wraps
        // decide() in its own transaction and rolls back loses the refusal
        // record with it. Acceptable, because such a caller is aborting the
        // whole request; if a rollback path ever needs to keep the attempt,
        // write it on a second connection here.
        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $affected = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_tasks')
                ->where('id', '=', $taskId)
                // The two predicates that make a second decision impossible rather
                // than discouraged. Both, not either: the status check refuses a
                // decided task, and the row_version check refuses a caller working
                // from a stale read of a task that is still pending.
                ->where('status_value_id', '=', $this->states->id('workflow_task_state', TaskState::Pending->value))
                ->where('row_version', '=', $expectedRowVersion)
                ->update([
                    'status_value_id' => $this->states->id('workflow_task_state', $outcome->value),
                    'decided_by_user_id' => $actorUserId,
                    'decided_at' => $at->format('Y-m-d H:i:s'),
                    'decision_comment' => $comment,
                    'row_version' => $expectedRowVersion + 1,
                    'updated_at' => $at->format('Y-m-d H:i:s'),
                ]);

            $task = $this->find($taskId);

            if ($affected === 1) {
                $this->write($taskId, $task, 'workflow.task_decided', $actorUserId, $at, $comment, [
                    'outcome' => $outcome->value,
                    'row_version' => $expectedRowVersion + 1,
                ]);
            } else {
                // Which of the two predicates refused is a materially different
                // fact: "somebody decided first" and "you were working from a
                // stale read of a task that is still open" send the caller to
                // different places, so the entry says which.
                $this->write($taskId, $task, 'workflow.task_decision_refused', $actorUserId, $at, $comment, [
                    'attempted_outcome' => $outcome->value,
                    'expected_row_version' => $expectedRowVersion,
                    'refusal' => $this->refusalOf($task, $expectedRowVersion),
                    'standing_decision_by' => $task['decided_by_user_id'] ?? null,
                ]);
            }

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['decided' => $affected === 1, 'task' => $task];
    }

    /**
     * Record an attempt that a check upstream of the conditional UPDATE turned
     * away — R-IX-107's wrong actor, wrong permission, segregation violation,
     * self-approval violation, cancelled instance.
     *
     * The reason is passed in because this class cannot see those checks. What
     * it can guarantee is that there is exactly one way to write the fact down,
     * so a new refusal reason is one call rather than a new audit shape.
     */
    public function refuse(
        int $taskId,
        string $reason,
        string $actorUserId,
        \DateTimeImmutable $at,
        ?TaskState $attempted = null,
    ): void {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A refused attempt is audited WITH its reason. "Refused" on its own tells an auditor nothing '
                . 'they did not already know from the absence of a decision (R-IX-107).'
            );
        }

        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->write($taskId, $this->find($taskId), 'workflow.task_decision_refused', $actorUserId, $at, null, [
                'attempted_outcome' => $attempted?->value,
                'refusal' => $reason,
            ]);

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Why the conditional UPDATE affected no rows.
     *
     * @param array<string, mixed>|null $task
     */
    private function refusalOf(?array $task, int $expectedRowVersion): string
    {
        if ($task === null) {
            return 'no such task in this tenant';
        }

        $pending = $this->states->id('workflow_task_state', TaskState::Pending->value);

        if ((int) $task['status_value_id'] !== $pending) {
            return 'already decided';
        }

        return (int) $task['row_version'] === $expectedRowVersion
            ? 'the predicate refused for a reason the row no longer shows'
            : 'stale row version: the caller read version ' . $expectedRowVersion
                . ' and the task is at ' . $task['row_version'];
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<string, mixed>      $detail
     */
    private function write(
        int $taskId,
        ?array $task,
        string $action,
        string $actorUserId,
        \DateTimeImmutable $at,
        ?string $comment,
        array $detail,
    ): void {
        $this->audit->append(new AuditEntry(
            action: $action,
            entityType: 'workflow_task',
            occurredAt: $at,
            businessOn: $at,
            entityId: $taskId,
            actorUserId: $actorUserId,
            newValue: $detail + ['node_key' => $task['node_key'] ?? null],
            reason: $comment,
            workflowInstanceId: $task === null ? null : (int) $task['workflow_instance_id'],
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $taskId): ?array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_tasks')
            ->where('id', '=', $taskId)
            ->first();
    }

    /**
     * Open a new task row. A task that changes hands is a NEW row pointing at
     * the one it replaces, so the history is a chain rather than a mutated
     * column (§IX.6.2).
     */
    public function open(
        int $instanceId,
        string $nodeKey,
        string $assigneeUserId,
        \DateTimeImmutable $at,
        ?\DateTimeImmutable $dueAt = null,
        ?int $supersedesTaskId = null,
    ): int {
        $id = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_tasks')->insert([
            'workflow_instance_id' => $instanceId,
            'status_value_id' => $this->states->id('workflow_task_state', TaskState::Pending->value),
            'node_key' => $nodeKey,
            'assignee_user_id' => $assigneeUserId,
            'row_version' => 1,
            'due_at' => $dueAt?->format('Y-m-d H:i:s'),
            'supersedes_task_id' => $supersedesTaskId,
            'created_at' => $at->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);

        return (int) $id;
    }

    /** @return list<array<string, mixed>> the open tasks of one node */
    public function openTasksOf(int $instanceId, string $nodeKey): array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_tasks')
            ->where('workflow_instance_id', '=', $instanceId)
            ->where('node_key', '=', $nodeKey)
            ->where('status_value_id', '=', $this->states->id('workflow_task_state', TaskState::Pending->value))
            ->select();
    }
}
