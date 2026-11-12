<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * One human task (Part IX §IX.6.2, §IX.6.3).
 *
 * A decision does not mutate this object — it produces a new one. That mirrors
 * the storage rule §IX.6.3 mechanism (1) enforces with a conditional UPDATE
 * whose WHERE clause carries `status_code = 'pending'`, so a second decision
 * affects zero rows and is refused 409.
 *
 * R-IX-046: the comment lives on the transition, not as an overwritable column
 * on the item. SmartPRS 2 writes `'remarks' => $v['remarks'] ?? null`
 * unconditionally on every decide (RequestController.php:1408,
 * LeaveController.php:422), so a blank re-decide erases the prior decision's
 * stated reason.
 */
final readonly class Task
{
    public function __construct(
        public string $id,
        public string $nodeKey,
        public string $assigneeUserId,
        public TaskState $state = TaskState::Pending,
        public ?string $decidedByUserId = null,
        public ?\DateTimeImmutable $decidedAt = null,
        public ?string $comment = null,
        public int $rowVersion = 1,
        public ?\DateTimeImmutable $dueAt = null,
    ) {
    }

    /**
     * Record a decision, producing the next row.
     *
     * @throws TaskAlreadyDecided when this row is not pending
     */
    public function decide(
        TaskState $outcome,
        string $actor,
        \DateTimeImmutable $at,
        ?string $comment = null,
    ): self {
        if (!$this->state->canMoveTo($outcome)) {
            // §IX.6.3: the storage layer refuses this with zero affected rows
            // and a 409. The domain refuses it with a type. Both are required —
            // R-IX-045 says the four mechanisms are each independently
            // sufficient and all four are needed.
            throw new TaskAlreadyDecided($this, $outcome, $actor);
        }

        return new self(
            $this->id,
            $this->nodeKey,
            $this->assigneeUserId,
            $outcome,
            $actor,
            $at,
            $comment,
            $this->rowVersion + 1,
            $this->dueAt,
        );
    }

    /** A task that changes hands closes here and opens as a new row. */
    public function reassignTo(string $user, string $actor, \DateTimeImmutable $at, string $reason): array
    {
        if ($reason === '') {
            // R-IX-040: reassignment requires a reason, writes a transition row
            // and an audit row naming both users.
            throw new \InvalidArgumentException('Reassignment requires a reason (R-IX-040).');
        }

        $closed = $this->decide(TaskState::Reassigned, $actor, $at, $reason);

        return [$closed, new self(
            $this->id . ':' . ($this->rowVersion + 1),
            $this->nodeKey,
            $user,
            TaskState::Pending,
            null,
            null,
            null,
            1,
            // R-IX-040: reassignment does not reset the SLA clock unless the
            // node declares it. A resettable clock makes SLA reporting
            // meaningless, and reassignment is the obvious lever.
            $this->dueAt,
        )];
    }

    public function isOpen(): bool
    {
        return $this->state === TaskState::Pending;
    }
}
