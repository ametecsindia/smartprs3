<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * ONE VERDICT, AND HOW IT WAS REACHED — Part XXI §XXI.5.1.
 *
 * `assessmentBasis` is the field that makes this structure worth having. A
 * verdict of "not valid" reached from stored evidence and a verdict of "not
 * valid" reached because nothing could be evaluated are the same red dot on a
 * dashboard and completely different facts, and R-XXI-054 requires the second to
 * PROPAGATE: a score containing a `not_assessed` contribution reads as "72 of a
 * possible 85 assessed", never as 72 out of 100.
 *
 * `isValid` is never true on an absent record. There are exactly three
 * absent-record cases (R-XXI-053) and none of them returns true — against
 * SmartPRS 2, where one of five validity implementations treats a blank
 * free-text field as valid.
 */
final readonly class ComplianceStatus
{
    public function __construct(
        public string $subjectRef,
        public string $itemTypeCode,
        public string $state,
        public bool $isValid,
        public string $assessmentBasis,
        public string $reason,
        public string $typeVersionId,
        public ?string $itemId = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validTo = null,
        public ?int $daysToExpiry = null,
        public string $evidenceState = 'none',
        public bool $waived = false,
    ) {
        ComplianceState::assertKnown($state);

        if ($isValid && $assessmentBasis === ComplianceState::BASIS_NOT_ASSESSED) {
            throw new \LogicException(
                "compliance_status returned is_valid = TRUE with basis not_assessed for {$subjectRef} / "
                . "{$itemTypeCode}. A check that could not run has not passed. This is the exact "
                . 'degradation R-XXI-053 forbids: when data is missing the answer is "unknown", never a '
                . 'weaker check that happens to succeed.'
            );
        }

        if (trim($reason) === '') {
            throw new \LogicException(
                "compliance_status returned no reason for {$subjectRef} / {$itemTypeCode}. Every assertion "
                . 'carries why it says what it says (R-XXI-056), because the alternative is a screen an '
                . 'auditor cannot question.'
            );
        }
    }

    public function isNotAssessed(): bool
    {
        return $this->assessmentBasis === ComplianceState::BASIS_NOT_ASSESSED;
    }

    public function isApplicable(): bool
    {
        return $this->state !== ComplianceState::NOT_APPLICABLE;
    }

    /** Spine S15: every status explains itself. */
    public function explain(): string
    {
        return sprintf(
            '%s · %s · %s (%s, basis %s%s) — %s [type %s%s]',
            $this->subjectRef,
            $this->itemTypeCode,
            $this->state,
            $this->isValid ? 'valid' : 'not valid',
            $this->assessmentBasis,
            $this->waived ? ', WAIVED' : '',
            $this->reason,
            $this->typeVersionId,
            $this->itemId === null ? '' : ', item ' . $this->itemId,
        );
    }
}
