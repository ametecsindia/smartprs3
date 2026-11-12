<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Leave;

use SmartPRS\Platform\Support\Rational;

/**
 * ONE MOVEMENT IN THE LEAVE LEDGER — Part XIII §XIII.4.
 *
 * Immutable, and every entry says WHY. "Balance adjusted" is not a reason, and
 * an entry without one is the row somebody has to reconstruct from a calendar of
 * emails three months later.
 *
 * `sourceReference` is what links a debit back to the leave request that caused
 * it and a comp-off credit back to the attendance day that earned it. Without
 * it a ledger is a list of numbers that add up; with it, every number is
 * traceable to an event somebody can look at.
 */
final readonly class LeaveLedgerEntry
{
    public function __construct(
        public string $id,
        public string $employmentId,
        public string $leaveTypeCode,
        public string $kind,
        public Rational $days,
        public \DateTimeImmutable $effectiveOn,
        public string $reason,
        public ?\DateTimeImmutable $expiresOn = null,
        public ?string $sourceReference = null,
        public ?string $consumesEntryId = null,
        public ?string $reversesEntryId = null,
    ) {
        if ($days->isNegative()) {
            throw new \InvalidArgumentException(
                'Days on a ledger entry are positive; the KIND decides the direction. A negative credit and '
                . 'a positive debit are the same movement written two ways, and a ledger that allows both '
                . 'cannot be summed without knowing which convention each row used.'
            );
        }

        if ($days->isZero() && $kind !== LeaveLedger::ADJUSTMENT) {
            throw new \InvalidArgumentException(
                "A {$kind} entry of zero days moves nothing. Only an adjustment may be zero, and only to "
                . 'record a correction that turned out to net out.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A ledger entry states why. "Balance adjusted" is what SmartPRS 2 leaves behind, and it is '
                . 'the row somebody reconstructs from emails three months later.'
            );
        }

        if ($expiresOn !== null && $expiresOn < $effectiveOn) {
            throw new \InvalidArgumentException(
                'A credit that expires before it is granted is never usable. If the intent is that it has '
                . 'already lapsed, write the lapse — do not encode it as a date nobody will read.'
            );
        }
    }

    /**
     * A comp-off credit — Part XIII §XIII.9.
     *
     * Earned by working a day the calendar says was an off, and it EXPIRES,
     * which is the half that makes comp-off a real obligation rather than an
     * unbounded liability. The attendance day that earned it is the source
     * reference, so "which Sunday was this for?" is answerable.
     */
    public static function compOff(
        string $id,
        string $employmentId,
        Rational $days,
        \DateTimeImmutable $earnedOn,
        \DateTimeImmutable $expiresOn,
        string $reason,
    ): self {
        return new self(
            id: $id,
            employmentId: $employmentId,
            leaveTypeCode: 'COMP-OFF',
            kind: LeaveLedger::ACCRUAL,
            days: $days,
            effectiveOn: $earnedOn,
            reason: $reason,
            expiresOn: $expiresOn,
            sourceReference: 'attendance:' . $earnedOn->format('Y-m-d'),
        );
    }

    /** A debit against a specific credit, so FIFO consumption is visible rather than implied. */
    public static function consume(
        string $id,
        self $credit,
        Rational $days,
        \DateTimeImmutable $takenOn,
        string $reason,
        ?string $sourceReference = null,
    ): self {
        return new self(
            id: $id,
            employmentId: $credit->employmentId,
            leaveTypeCode: $credit->leaveTypeCode,
            kind: LeaveLedger::CONSUMED,
            days: $days,
            effectiveOn: $takenOn,
            reason: $reason,
            sourceReference: $sourceReference,
            consumesEntryId: $credit->id,
        );
    }

    /** A credit that expired unclaimed. Written down, not filtered out. */
    public static function lapse(self $credit, \DateTimeImmutable $on): self
    {
        return new self(
            id: $credit->id . ':lapse',
            employmentId: $credit->employmentId,
            leaveTypeCode: $credit->leaveTypeCode,
            kind: LeaveLedger::LAPSED,
            days: $credit->days,
            effectiveOn: $on,
            reason: "Earned {$credit->effectiveOn->format('j M Y')} and expired unclaimed on "
                . $on->format('j M Y') . '. Recorded rather than filtered, so the employee can see when the '
                . 'day went and why.',
            consumesEntryId: $credit->id,
        );
    }
}
