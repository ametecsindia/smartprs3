<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Sla;

/**
 * One durable timed obligation — Part IX §IX.7.3.
 *
 * "Every timed obligation is a durable row, not an in-memory schedule."
 *
 * That sentence is the whole design. An in-memory schedule is lost when the
 * worker restarts, and what is lost is invisible: nobody is notified that a
 * notification will not arrive. A row survives, and a sweep that comes back
 * after an outage processes the backlog in `fire_at` order (§IX.7.6).
 *
 * `fireAt` is computed ONCE, at activation, from the calendar. It is never
 * recomputed at sweep time, so a breach is reported as having happened when it
 * happened rather than when somebody noticed — which is what makes an SLA
 * report survive a scheduler outage.
 */
final readonly class Timer
{
    public const KINDS = ['due', 'reminder', 'escalation', 'expiry', 'stale_check'];
    public const STATUSES = ['scheduled', 'fired', 'cancelled', 'superseded'];

    public function __construct(
        public string $kind,
        public \DateTimeImmutable $fireAt,
        public string $nodeKey,
        public ?string $taskId = null,
        public int $sequenceNo = 0,
        public string $status = 'scheduled',
        public ?int $offsetMinutes = null,
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("'{$kind}' is not a timer kind. One of: " . implode(', ', self::KINDS) . '.');
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("'{$status}' is not a timer status.");
        }

        if ($sequenceNo < 0) {
            throw new \InvalidArgumentException('A timer sequence is 0 or a positive nth.');
        }
    }

    /**
     * R-IX-060: reminders are idempotent per (task, kind, sequence), enforced by
     * a unique constraint. This is that key, spelled once, so the row and any
     * in-memory deduplication cannot disagree about what "the same reminder"
     * means.
     */
    public function idempotencyKey(): string
    {
        return ($this->taskId ?? 'node:' . $this->nodeKey) . '|' . $this->kind . '|' . $this->sequenceNo;
    }

    public function withStatus(string $status): self
    {
        return new self(
            $this->kind,
            $this->fireAt,
            $this->nodeKey,
            $this->taskId,
            $this->sequenceNo,
            $status,
            $this->offsetMinutes,
        );
    }

    /**
     * How late this firing is — the number §IX.7.6 calls the stale margin.
     *
     * Measured against the stored `fireAt`, never against a recomputed due
     * date, because the point of the measurement is to detect that the sweep
     * itself was not running.
     */
    public function latenessMinutes(\DateTimeImmutable $now): int
    {
        return $now <= $this->fireAt ? 0 : intdiv($now->getTimestamp() - $this->fireAt->getTimestamp(), 60);
    }

    public function describe(): string
    {
        return $this->kind
            . ($this->sequenceNo > 0 ? " #{$this->sequenceNo}" : '')
            . ' at ' . $this->fireAt->format('Y-m-d H:i')
            . ' on ' . ($this->taskId === null ? 'node ' . $this->nodeKey : 'task ' . $this->taskId);
    }
}
