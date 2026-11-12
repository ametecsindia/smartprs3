<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * The task state model — Part IX §IX.6.2.
 *
 * `pending` is the only non-terminal state. Every other is terminal for that
 * task ROW; a task that changes hands produces a NEW row — `reassigned` closes
 * the old one and opens the next — so the history is a chain of rows rather
 * than a mutated column.
 *
 * R-IX-044: a task row never moves from a terminal state to any state.
 */
enum TaskState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case Reassigned = 'reassigned';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function canMoveTo(self $next): bool
    {
        return $this === self::Pending && $next !== self::Pending;
    }
}
