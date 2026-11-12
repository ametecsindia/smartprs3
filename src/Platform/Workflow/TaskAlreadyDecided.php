<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * A second decision on a decided task (Part IX §IX.6.3, R-IX-045).
 *
 * "Re-deciding a decided task MUST be impossible, not discouraged."
 *
 * R-IX-050 makes the loser's attempt evidence rather than nothing: the actor,
 * the timestamp, the intended outcome and the comment are all carried here, so
 * the caller writes an attempted-transition row. That a second approver
 * disagreed is material even when it does not change the outcome.
 */
final class TaskAlreadyDecided extends \RuntimeException
{
    public function __construct(
        public readonly Task $task,
        public readonly TaskState $attempted,
        public readonly string $actor,
    ) {
        parent::__construct(sprintf(
            "workflow.task_already_decided: task %s on node '%s' is already %s%s; %s attempted %s. "
            . 'The existing decision stands and this attempt is recorded (R-IX-050).',
            $task->id,
            $task->nodeKey,
            $task->state->value,
            $task->decidedByUserId === null ? '' : " by {$task->decidedByUserId}",
            $actor,
            $attempted->value,
        ));
    }

    /** The HTTP shape spine S17 declares for this. */
    public function statusCode(): int
    {
        return 409;
    }
}
