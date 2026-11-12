<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Attendance;

/**
 * A CORRECTION TO A LOCKED PERIOD BECOMES ARREARS — Part XI §XI.13, and the
 * Phase 4 exit gate's fourth criterion.
 *
 * Once a period is settled and paid, its attendance cannot simply change. A
 * February correction discovered in April does not rewrite February — February
 * has been paid, filed and reconciled — it produces an ARREARS RECORD with an
 * owner and a TARGET PERIOD, which is the period that will actually carry the
 * money.
 *
 * Both halves matter and SmartPRS 2 has neither:
 *
 *   THE LOCK. Its attendance is editable for any date at any time, so a
 *   correction to a paid month silently changes the number a payslip was
 *   computed from, and the payslip and the attendance record stop agreeing with
 *   no event anywhere recording that they diverged.
 *
 *   THE OWNER. An arrears record with no owner is a liability nobody is
 *   accountable for. It sits until somebody notices it in a reconciliation, and
 *   the person who notices is rarely the person who can act.
 *
 * The correction is NOT refused. That is the design decision worth stating: a
 * locked period does not mean "you may not fix this", it means "fixing it costs
 * money in a different month, and here is the record that says so".
 */
final readonly class PeriodLock
{
    public function __construct(
        public string $periodCode,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $isLocked,
        public ?\DateTimeImmutable $lockedOn = null,
        public ?string $lockedBy = null,
    ) {
        if ($to < $from) {
            throw new \InvalidArgumentException("Period {$periodCode} ends before it begins.");
        }

        if ($isLocked && ($lockedOn === null || $lockedBy === null)) {
            throw new \InvalidArgumentException(
                "Period {$periodCode} is locked and does not record who locked it or when. A lock nobody is "
                . 'named on is a lock nobody can lift, and the first question after a disputed correction is '
                . 'always who closed the month.'
            );
        }
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        return $date >= $this->from && $date <= $this->to;
    }
}
