<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * The instance state model — Part IX §IX.6.1.
 *
 * R-IX-043: a terminal instance is never reopened. A correction is a NEW
 * instance carrying `supersedes_instance_id`, so both the original decision and
 * the correction are in the ledger.
 *
 * SmartPRS 2's commission `reopen` sets status='pending', decided_by=NULL,
 * decided_at=NULL (RequestController.php:1403-1410) — erasing the fact that a
 * decision was ever made. There is no transition out of a terminal state here,
 * so the equivalent write has nowhere to go.
 */
enum InstanceState: string
{
    case Running = 'running';
    case AwaitingEdit = 'awaiting_edit';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Rejected, self::Cancelled, self::Expired => true,
            default => false,
        };
    }

    /**
     * §IX.6.1's permitted transitions. `blocked → running` and `failed →
     * running` additionally require `workflow.instance.resume` and a reason —
     * carried by the caller, asserted by Instance.
     *
     * @return list<self>
     */
    public function permittedNext(): array
    {
        return match ($this) {
            self::Running => [self::AwaitingEdit, self::Blocked, self::Failed,
                self::Completed, self::Rejected, self::Cancelled, self::Expired],
            self::AwaitingEdit => [self::Running, self::Cancelled, self::Expired, self::Blocked, self::Failed],
            self::Blocked, self::Failed => [self::Running, self::Cancelled],
            // No outgoing edges. Reopening is a new instance, never a resurrection.
            self::Completed, self::Rejected, self::Cancelled, self::Expired => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->permittedNext(), true);
    }

    /** Moving out of blocked or failed is an operator act and needs a reason. */
    public function needsResumeAuthority(self $next): bool
    {
        return ($this === self::Blocked || $this === self::Failed) && $next === self::Running;
    }
}
