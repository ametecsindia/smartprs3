<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * THE CLOSED NODE CATALOGUE — Part IX §IX.4, spine S8.
 *
 * R-IX-020: the catalogue is closed and versioned. Adding a node type is an
 * Ametecs release activity — a migration, a params schema, an executor, a trace
 * renderer and a conformance test — and never a tenant action. A definition
 * naming an unknown type fails publication and, if one is somehow persisted,
 * fails instance start. It never degrades to a default behaviour, because a
 * default behaviour for "I do not know what this step is" is how an unreviewed
 * item gets approved.
 *
 * The outcome set per type is declared here rather than per definition, so W03
 * can check that a node's transitions cover the outcomes its type can produce.
 * An outcome with no declared transition is an instance that strands.
 */
enum NodeType: string
{
    case Submit = 'submit';
    case Approval = 'approval';
    case ConditionBranch = 'condition_branch';
    case ParallelAll = 'parallel_all';
    case ParallelAny = 'parallel_any';
    case Quorum = 'quorum';
    case AutoApprove = 'auto_approve';
    case Notify = 'notify';
    case Escalate = 'escalate';
    case ReturnForEdit = 'return_for_edit';
    case Reject = 'reject';
    case Cancel = 'cancel';
    case Finalise = 'finalise';

    /**
     * §IX.4.14. A `condition_branch` produces a branch label declared per
     * definition rather than a fixed set, so it returns an empty list and W03
     * checks it against its declared branches instead.
     *
     * @return list<string>
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::Submit => ['submitted', 'blocked'],
            self::Approval => ['approved', 'rejected', 'returned', 'escalated', 'auto_decided'],
            self::ConditionBranch => [],
            self::ParallelAll => ['joined', 'rejected'],
            self::ParallelAny => ['first_completed'],
            self::Quorum => ['approved', 'rejected', 'escalated', 'parked'],
            self::AutoApprove => ['auto_approved', 'not_applicable'],
            self::Notify => ['notified'],
            self::Escalate => ['escalated'],
            self::ReturnForEdit => ['returned', 'resubmitted', 'exhausted', 'abandoned'],
            self::Reject => ['rejected'],
            self::Cancel => ['cancelled'],
            self::Finalise => ['completed'],
        };
    }

    /** W13: terminal means terminal — no outgoing transitions at all. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Reject, self::Cancel, self::Finalise => true,
            default => false,
        };
    }

    /** Creates human tasks, so it needs participants and can carry an SLA. */
    public function createsTasks(): bool
    {
        return match ($this) {
            self::Approval, self::Quorum, self::Escalate, self::ReturnForEdit => true,
            default => false,
        };
    }

    /** Waits for a human decision before the instance can advance. */
    public function waitsForHuman(): bool
    {
        return match ($this) {
            self::Approval, self::Quorum, self::ReturnForEdit => true,
            default => false,
        };
    }

    /**
     * W05: a cycle is legal only where it passes through a node a human can
     * see and act on. An approval loop with no human-visible return is an
     * infinite loop wearing a workflow's clothes.
     */
    public function breaksCycles(): bool
    {
        return $this === self::ReturnForEdit || $this === self::Escalate;
    }
}
