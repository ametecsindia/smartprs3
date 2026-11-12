<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Sla;

/**
 * What the sweep decided to do about one timer, and why.
 *
 * Returned rather than performed: `Sweep` computes, the caller writes. Same
 * reason the rule engine returns an AppliedAction list instead of applying it —
 * a decision you can inspect without side effects is a decision you can test,
 * simulate and explain, and §IX.7.6's most important case is one where the
 * right answer is to do LESS than the configuration says.
 */
final readonly class SweepDecision
{
    /** The task stays; the breach is recorded and reportable (§IX.7.5, `none`). */
    public const RECORD_ONLY = 'record_only';
    public const NOTIFY = 'notify';
    public const REASSIGN = 'reassign';
    public const ROUTE_TO_NODE = 'route_to_node';
    public const AUTO_DECIDE = 'auto_decide';

    /** R-IX-066. The automatic decision is withheld and the instance blocked. */
    public const SUPPRESSED = 'suppressed';

    /** A timer whose task was already decided; nothing to do but close it. */
    public const CANCELLED = 'cancelled';

    /**
     * @param list<string> $events domain events to emit, e.g. workflow.sla.breached
     */
    public function __construct(
        public Timer $timer,
        public string $action,
        public string $reason,
        public array $events = [],
        public ?string $target = null,
        public ?string $outcome = null,
        public int $latenessMinutes = 0,
        public int $breachMinutes = 0,
    ) {
    }

    public function isSuppressed(): bool
    {
        return $this->action === self::SUPPRESSED;
    }

    /** Whether the instance must be flagged `blocked` (R-IX-066). */
    public function blocksInstance(): bool
    {
        return $this->action === self::SUPPRESSED;
    }

    public function describe(): string
    {
        return $this->timer->describe() . ' → ' . $this->action . ': ' . $this->reason;
    }
}
