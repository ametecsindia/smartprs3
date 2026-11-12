<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Persistence;

use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\Sla\SweepDecision;
use SmartPRS\Platform\Workflow\Sla\Timer;

/**
 * The durable half of §IX.7 — reading and writing `workflow_timers`.
 *
 * Two things here are the design rather than plumbing:
 *
 *   1. `claim()` selects `FOR UPDATE SKIP LOCKED` (§IX.7.6 step 1). Two sweep
 *      workers must be able to run at once without either waiting for the
 *      other and without both processing the same timer. SKIP LOCKED gives
 *      each worker a disjoint batch; `FOR UPDATE` without it would serialise
 *      them, and no lock at all would double-send.
 *   2. `schedule()` writes with the R-IX-060 unique key and swallows exactly
 *      one error: a duplicate. A catch-up sweep re-scheduling a timer that
 *      already exists is the NORMAL case after an outage, and it must be a
 *      no-op rather than a crash — while any other database error still
 *      propagates.
 */
final class TimerRepository
{
    /** MySQL/MariaDB duplicate-key SQLSTATE. */
    private const DUPLICATE = '23000';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Write a node activation's timers.
     *
     * @param  list<Timer>  $timers
     * @return int how many were newly scheduled; the rest already existed
     */
    public function schedule(int $instanceId, array $timers, \DateTimeImmutable $at): int
    {
        $written = 0;

        foreach ($timers as $timer) {
            try {
                TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')->insert([
                    'workflow_instance_id' => $instanceId,
                    'workflow_task_id' => $timer->taskId === null ? null : (int) $timer->taskId,
                    'node_key' => $timer->nodeKey,
                    'timer_kind' => $timer->kind,
                    'fire_at' => $timer->fireAt->format('Y-m-d H:i:s'),
                    'sequence_no' => $timer->sequenceNo,
                    'offset_minutes' => $timer->offsetMinutes,
                    'status' => $timer->status,
                    'created_at' => $at->format('Y-m-d H:i:s'),
                    'updated_at' => $at->format('Y-m-d H:i:s'),
                ]);

                $written++;
            } catch (\PDOException $e) {
                // R-IX-060 doing its job. Re-scheduling after a catch-up is
                // routine; anything else is not ours to absorb.
                if ($e->getCode() !== self::DUPLICATE) {
                    throw $e;
                }
            }
        }

        return $written;
    }

    /**
     * Claim a batch of due timers for this worker (§IX.7.6 step 1).
     *
     * MUST be called inside a transaction: SKIP LOCKED holds the rows only for
     * as long as the transaction that took them, and a batch claimed outside
     * one is a batch every other worker is free to claim too.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(\DateTimeImmutable $now, int $batch = 200): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'Timers are claimed inside a transaction. SKIP LOCKED holds a row for the life of the '
                . 'transaction that locked it, so claiming outside one hands the same batch to every worker.'
            );
        }

        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')
            ->where('status', '=', 'scheduled')
            ->where('fire_at', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('fire_at')
            ->limit($batch)
            ->forUpdate(skipLocked: true)
            ->select();
    }

    /** Record what the sweep decided about one claimed timer. */
    public function settle(int $timerId, SweepDecision $decision, \DateTimeImmutable $at): void
    {
        TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')
            ->where('id', '=', $timerId)
            ->update([
                'status' => $decision->action === SweepDecision::CANCELLED ? 'cancelled' : 'fired',
                'fired_at' => $at->format('Y-m-d H:i:s'),
                'outcome' => $decision->action,
                'outcome_reason' => $decision->reason,
                'updated_at' => $at->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Cancel a task's outstanding timers, because the task was decided.
     *
     * Cancelled, not deleted: "this reminder never went out because the task
     * was already approved" must stay answerable, and a deleted row answers
     * nothing.
     */
    public function cancelForTask(int $taskId, \DateTimeImmutable $at, string $why = 'the task was decided'): int
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')
            ->where('workflow_task_id', '=', $taskId)
            ->where('status', '=', 'scheduled')
            ->update([
                'status' => 'cancelled',
                'outcome_reason' => $why,
                'updated_at' => $at->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * What the ops dashboard shows (§IX.7.6): how far behind the sweep is.
     *
     * R-IX-068 is the companion rule — SLA state is derivable WITHOUT the
     * scheduler, because the inbox computes overdue from `due_at` against now
     * at read time. A stopped scheduler delays reminders; it does not make an
     * overdue item look on time. This method is about the scheduler's own
     * health, not about whether anything is overdue.
     *
     * @return array{overdue: int, oldest_overdue_at: ?string}
     */
    public function backlog(\DateTimeImmutable $now): array
    {
        $due = TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')
            ->where('status', '=', 'scheduled')
            ->where('fire_at', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('fire_at')
            ->select(['fire_at']);

        return [
            'overdue' => count($due),
            'oldest_overdue_at' => $due === [] ? null : (string) $due[0]['fire_at'],
        ];
    }

    /** @return list<array<string, mixed>> every timer of an instance, in fire order */
    public function forInstance(int $instanceId): array
    {
        return TenantScopedQuery::on($this->pdo, $this->tenant, 'workflow_timers')
            ->where('workflow_instance_id', '=', $instanceId)
            ->orderBy('fire_at')
            ->orderBy('id')
            ->select();
    }
}
