<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * ONE FILING, ONE PERIOD, ONE OWNER — Part XVII §XVII.11.3.
 *
 *   due ──► prepared ──► filed ──► acknowledged
 *    │         │           │            │
 *    │         │           └──► revised ──► filed(n) ──► acknowledged
 *    │         └──► prepared_failed
 *    └──► overdue                    └──► waived
 *
 * The rule that matters most is R-XVII-129: **`filed` and `acknowledged` are
 * reachable only by a recorded human act or an integration response, each
 * carrying evidence** — an acknowledgement number, a receipt, a challan
 * reference. There is no computed, inferred or default `filed`, which is what
 * makes SmartPRS 2's fabricated "six quarters Filed" (DEFECT-F-07) structurally
 * unreachable here rather than merely unlikely: `file()` demands evidence, and no
 * other method reaches the state.
 *
 * And every obligation names an OWNER (R-XVII-128). SmartPRS 2's compliance
 * digest resolves recipients through role pivots, correctly sends to nobody when
 * they resolve to nothing, and never tells anyone that it sent to nobody.
 */
final class Obligation
{
    public const DUE = 'due';
    public const OVERDUE = 'overdue';
    public const PREPARED = 'prepared';
    public const PREPARED_FAILED = 'prepared_failed';
    public const FILED = 'filed';
    public const REVISED = 'revised';
    public const ACKNOWLEDGED = 'acknowledged';
    public const WAIVED = 'waived';

    private string $state = self::DUE;

    /** @var list<string> */
    private array $history = [];

    private ?string $evidence = null;

    public function __construct(
        public readonly string $code,
        public readonly string $head,
        public readonly string $registration,
        public readonly string $periodCode,
        public readonly string $ownerUserId,
        private \DateTimeImmutable $dueOn,
        public readonly string $dueRule,
        public readonly string $packVersion,
    ) {
        if (trim($ownerUserId) === '') {
            throw new \InvalidArgumentException(
                "Obligation {$code} for {$periodCode} names no owner. An unassigned obligation is itself an "
                . 'exception on the dashboard, not a row that quietly sits there — a reminder that resolves '
                . 'to nobody is a filing nobody makes.'
            );
        }
    }

    public function state(): string
    {
        return $this->state;
    }

    public function dueOn(): \DateTimeImmutable
    {
        return $this->dueOn;
    }

    /**
     * A due date is RECOMPUTED, not frozen, until the obligation is prepared
     * (R-XVII-127). A pack that changes a due rule moves future undue
     * obligations and leaves past ones with the rule that applied then.
     */
    public function recomputeDue(\DateTimeImmutable $newDue, string $newRule): void
    {
        if ($this->state !== self::DUE && $this->state !== self::OVERDUE) {
            return;
        }

        $this->history[] = "due date moved from {$this->dueOn->format('Y-m-d')} to "
            . "{$newDue->format('Y-m-d')} ({$this->dueRule} → {$newRule})";
        $this->dueOn = $newDue;
    }

    public function markOverdue(\DateTimeImmutable $asOf): bool
    {
        if ($this->state === self::DUE && $asOf > $this->dueOn) {
            $this->state = self::OVERDUE;
            $this->history[] = 'became overdue on ' . $asOf->format('Y-m-d');

            return true;
        }

        return false;
    }

    /** @param list<string> $assertionFailures from the return's own reconciliation */
    public function prepare(array $assertionFailures = []): void
    {
        if ($assertionFailures !== []) {
            $this->state = self::PREPARED_FAILED;
            $this->history[] = 'preparation FAILED its reconciliation: ' . implode('; ', $assertionFailures);

            return;
        }

        $this->state = self::PREPARED;
        $this->history[] = 'prepared';
    }

    /**
     * The only route to `filed`, and it demands evidence.
     *
     * @throws \InvalidArgumentException when nobody acted, or acted with nothing to show for it
     */
    public function file(string $byUserId, string $acknowledgementEvidence, \DateTimeImmutable $on): void
    {
        if ($this->state !== self::PREPARED && $this->state !== self::REVISED) {
            throw new \InvalidArgumentException(
                "Obligation {$this->code} is '{$this->state}' and cannot be filed. A return is filed from a "
                . 'prepared state, and a prepared state is one whose reconciliation passed.'
            );
        }

        if (trim($byUserId) === '' || trim($acknowledgementEvidence) === '') {
            throw new \InvalidArgumentException(
                'Filing requires a person and evidence — an acknowledgement number, a receipt, a challan '
                . 'reference. There is no computed, inferred or default `filed`: a status that can be '
                . 'reached without evidence is a status that eventually says six quarters were filed when '
                . 'none were.'
            );
        }

        $this->state = self::FILED;
        $this->evidence = $acknowledgementEvidence;
        $this->history[] = "filed by {$byUserId} on {$on->format('Y-m-d')} — evidence "
            . $acknowledgementEvidence;
    }

    public function acknowledge(string $acknowledgementNumber, \DateTimeImmutable $on): void
    {
        if ($this->state !== self::FILED) {
            throw new \InvalidArgumentException(
                "Only a filed obligation can be acknowledged; {$this->code} is '{$this->state}'."
            );
        }

        if (trim($acknowledgementNumber) === '') {
            throw new \InvalidArgumentException('An acknowledgement with no number acknowledges nothing.');
        }

        $this->state = self::ACKNOWLEDGED;
        $this->history[] = "acknowledged {$acknowledgementNumber} on " . $on->format('Y-m-d');
    }

    public function waive(string $reason, string $authorisedBy): void
    {
        if (trim($reason) === '' || trim($authorisedBy) === '') {
            throw new \InvalidArgumentException(
                'A waiver needs a reason and an authorising officer, and it appears on the compliance '
                . 'exceptions register. A filing waived by nobody for nothing is a filing skipped.'
            );
        }

        $this->state = self::WAIVED;
        $this->history[] = "waived by {$authorisedBy}: {$reason}";
    }

    public function evidence(): ?string
    {
        return $this->evidence;
    }

    /** @return list<string> */
    public function history(): array
    {
        return $this->history;
    }
}
