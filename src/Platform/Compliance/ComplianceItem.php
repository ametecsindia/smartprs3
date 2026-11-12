<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * ONE INSTANCE IN THE REGISTER — Part XXI §XXI.2.2.
 *
 * The state is DERIVED from the record's own dates and evidence at the date
 * asked about, never stored as a column somebody updates. A stored state is a
 * state that is right on the day it was written and drifts thereafter: an
 * unexpired certificate silently becomes an expired one at midnight, and only a
 * sweep that runs makes a stored column agree with reality.
 *
 * `waived` is a MARKER, not a state (§XXI.2.4). It can apply to several states,
 * and collapsing it into the vocabulary would make the gate ambiguous — a waiver
 * lets a payment through, it does not make a certificate valid, and conflating
 * the two loses the distinction the auditor cares about most.
 */
final readonly class ComplianceItem
{
    public function __construct(
        public string $id,
        public string $subjectRef,
        public string $itemTypeCode,
        public string $recordedState,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public string $evidenceState = 'none',
        public ?string $waiverReason = null,
        public ?string $waiverAuthorisedBy = null,
        public ?\DateTimeImmutable $waiverUntil = null,
    ) {
        ComplianceState::assertKnown($recordedState);

        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                "Compliance item {$id} expires before it begins, which is a data-entry error that would "
                . 'otherwise present as a permanently invalid subject nobody can fix.'
            );
        }

        if ($waiverReason !== null
            && ($waiverAuthorisedBy === null || $waiverUntil === null || trim($waiverReason) === '')
        ) {
            throw new \InvalidArgumentException(
                "The waiver on {$id} lacks a reason, an authorising officer or an end date. An unbounded "
                . 'waiver authorised by nobody is the compliance obligation deleted, with a word for it.'
            );
        }
    }

    /** Derived at the date asked about — R-XXI-055, never "now". */
    public function stateOn(\DateTimeImmutable $asOf): string
    {
        if ($this->validTo !== null && $asOf > $this->validTo) {
            // Expiry beats whatever was recorded. A verified certificate that
            // ran out last month is not verified today, and the record saying
            // `verified` is exactly how a stale column lies.
            return ComplianceState::OVERDUE;
        }

        if ($this->validFrom !== null && $asOf < $this->validFrom) {
            return ComplianceState::UPCOMING;
        }

        return $this->recordedState;
    }

    public function isWaived(\DateTimeImmutable $asOf = new \DateTimeImmutable()): bool
    {
        return $this->waiverReason !== null
            && $this->waiverUntil !== null
            && $asOf <= $this->waiverUntil;
    }

    public function reasonOn(\DateTimeImmutable $asOf, ItemType $type): string
    {
        $state = $this->stateOn($asOf);
        $why = match (true) {
            $state === ComplianceState::OVERDUE && $this->validTo !== null =>
                'the record expired on ' . $this->validTo->format('Y-m-d') . ' and was recorded as '
                . $this->recordedState,
            $state === ComplianceState::UPCOMING && $this->validFrom !== null =>
                'the record does not take effect until ' . $this->validFrom->format('Y-m-d'),
            default => "the record stands as {$state}, evidence {$this->evidenceState}",
        };

        return $why . ', and this type treats ' . implode('/', $type->validStateCodes) . ' as valid'
            . ($this->isWaived($asOf)
                ? "; WAIVED by {$this->waiverAuthorisedBy} until "
                    . $this->waiverUntil?->format('Y-m-d') . ': ' . $this->waiverReason
                : '');
    }
}
