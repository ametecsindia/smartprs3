<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Attendance;

/**
 * WHAT A CORRECTION TO A CLOSED MONTH PRODUCES — Part XI §XI.13.
 *
 * An owner, a target period, the diff that caused it, and the period it belongs
 * to. Every one of the four is load-bearing:
 *
 *   THE ORIGIN PERIOD says which month was actually wrong, which is the month a
 *   statutory return may have to be revised for.
 *   THE TARGET PERIOD says which month will carry the money. They are different
 *   months and conflating them is how an arrears payment gets filed against the
 *   period it was paid in rather than the period it was earned in.
 *   THE OWNER is a person, because an unowned liability waits for a
 *   reconciliation and the person who finds it is rarely the person who can act.
 *   THE DIFF is what changed, so an approver is deciding about something rather
 *   than authorising an amount.
 */
final readonly class ArrearsRecord
{
    public const OPEN = 'open';
    public const APPROVED = 'approved';
    public const SETTLED = 'settled';
    public const REJECTED = 'rejected';

    /** @param list<string> $diff */
    public function __construct(
        public string $employmentId,
        public \DateTimeImmutable $attendanceDate,
        public string $originPeriodCode,
        public string $targetPeriodCode,
        public string $ownerUserId,
        public array $diff,
        public string $reason,
        public string $status = self::OPEN,
        public ?string $raisedBy = null,
        public ?\DateTimeImmutable $raisedOn = null,
    ) {
        if (trim($ownerUserId) === '') {
            throw new \InvalidArgumentException(
                'An arrears record names an owner. A liability nobody is accountable for sits until a '
                . 'reconciliation finds it, and whoever finds it is rarely whoever can act on it.'
            );
        }

        if ($targetPeriodCode === $originPeriodCode) {
            throw new \InvalidArgumentException(
                "The target period is the same as the origin ({$originPeriodCode}). If the money can be paid "
                . 'in the period it was earned, the period was not locked and this is a correction, not '
                . 'arrears.'
            );
        }

        if ($diff === []) {
            throw new \InvalidArgumentException(
                'An arrears record with an empty diff carries no reason to exist. An approver would be '
                . 'authorising an amount rather than deciding about a change.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An arrears record states why the correction was made.');
        }
    }

    /**
     * Raise arrears for a correction that lands in a locked period.
     *
     * Returns NULL when the period is open — which is the ordinary case, and the
     * correction simply applies. A locked period does not refuse the fix; it
     * makes the fix cost money in a different month and records that it did.
     */
    public static function forCorrection(
        PeriodLock $origin,
        string $targetPeriodCode,
        DaySettlement $before,
        DaySettlement $after,
        string $ownerUserId,
        string $reason,
        ?string $raisedBy = null,
        ?\DateTimeImmutable $raisedOn = null,
    ): ?self {
        if (!$origin->isLocked) {
            return null;
        }

        if (!$origin->covers($after->attendanceDate)) {
            throw new \InvalidArgumentException(
                'The corrected day is not inside the period being treated as its origin. An arrears record '
                . 'filed against the wrong month is worse than none: it moves the revision to a return that '
                . 'was correct.'
            );
        }

        $diff = $before->diff($after);

        if ($diff === []) {
            // Nothing changed, so nothing is owed. A re-derivation that reaches
            // the same answer must not raise a liability, or every recalculation
            // sweep would file arrears for the whole workforce.
            return null;
        }

        return new self(
            employmentId: $after->employmentId,
            attendanceDate: $after->attendanceDate,
            originPeriodCode: $origin->periodCode,
            targetPeriodCode: $targetPeriodCode,
            ownerUserId: $ownerUserId,
            diff: $diff,
            reason: $reason,
            raisedBy: $raisedBy,
            raisedOn: $raisedOn,
        );
    }

    public function describe(): string
    {
        return "Arrears for {$this->employmentId} on {$this->attendanceDate->format('j M Y')}: "
            . implode('; ', $this->diff)
            . ". {$this->originPeriodCode} is closed, so this is carried into {$this->targetPeriodCode}"
            . " and owned by {$this->ownerUserId}. {$this->reason}";
    }
}
