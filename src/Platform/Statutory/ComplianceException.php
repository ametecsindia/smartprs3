<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * A RECORDED DEPARTURE FROM THE BASELINE — Part XVII §XVII.8.3.
 *
 * Every field is mandatory and the constructor refuses without it, because the
 * question an auditor asks is one sentence — *"where have you departed from the
 * statutory baseline, on what basis, authorised by whom, until when?"* — and an
 * exception missing any part of it cannot answer.
 *
 * TWO THINGS ARE STRUCTURAL RATHER THAN POLICY:
 *
 *   THE RANGE IS BOUNDED. There is no open-ended exception. An exception with no
 *   end date is a permanent policy that nobody ever reviews, which is how a
 *   temporary departure becomes the way things are done.
 *
 *   IT EXPIRES, AND EXPIRY RE-RAISES (R-XVII-113). `isActive()` is asked at the
 *   moment of use, not stamped at creation, so an exception that has run out
 *   stops authorising anything and the finding comes back.
 *
 * SmartPRS 2's nearest analogue is `esi_applicable = 'no'`: a free choice with no
 * reason, no evidence, no authoriser, no end, and no effect.
 */
final readonly class ComplianceException
{
    public const MINIMUM_BASIS_LENGTH = 40;

    public function __construct(
        public string $ruleCode,
        public string $legalBasis,
        public string $statutoryReference,
        public string $evidenceReference,
        public string $authorisedBy,
        public \DateTimeImmutable $effectiveFrom,
        public \DateTimeImmutable $effectiveTo,
        public \DateTimeImmutable $reviewOn,
        public string $approvedBy,
    ) {
        $missing = [];

        foreach ([
            'ruleCode' => $ruleCode,
            'statutoryReference' => $statutoryReference,
            'evidenceReference' => $evidenceReference,
            'authorisedBy' => $authorisedBy,
            'approvedBy' => $approvedBy,
        ] as $field => $value) {
            if (trim($value) === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'A compliance exception is missing ' . implode(', ', $missing) . '. Every field is required '
                . 'because the auditor\'s question is one sentence — where did you depart, on what basis, '
                . 'authorised by whom, until when — and an exception that cannot answer it is a checkbox.'
            );
        }

        if (mb_strlen(trim($legalBasis)) < self::MINIMUM_BASIS_LENGTH) {
            throw new \InvalidArgumentException(
                'The legal basis is ' . mb_strlen(trim($legalBasis)) . ' characters; at least '
                . self::MINIMUM_BASIS_LENGTH . ' are required. The minimum is crude on purpose: it does not '
                . 'make a basis good, it makes "n/a" and "approved by MD" impossible.'
            );
        }

        if ($effectiveTo <= $effectiveFrom) {
            throw new \InvalidArgumentException(
                'A compliance exception must be BOUNDED and end after it begins. An open-ended exception is '
                . 'a permanent departure nobody reviews, which is how a temporary measure becomes the way '
                . 'things are done.'
            );
        }

        if ($reviewOn > $effectiveTo) {
            throw new \InvalidArgumentException(
                'The review date falls after the exception expires, so the review would happen to something '
                . 'that has already lapsed. A review scheduled for after the end is not a review.'
            );
        }

        if ($approvedBy === $authorisedBy) {
            throw new \InvalidArgumentException(
                'The authorising officer and the approver are the same person. Maker-checker on a departure '
                . 'from the statutory baseline is the same control as maker-checker on payroll.'
            );
        }
    }

    public function coversRule(string $ruleCode): bool
    {
        return $this->ruleCode === $ruleCode;
    }

    /** Asked at the moment of use, never stamped at creation. */
    public function isActive(\DateTimeImmutable $asOf): bool
    {
        return $asOf >= $this->effectiveFrom && $asOf <= $this->effectiveTo;
    }

    public function needsReview(\DateTimeImmutable $asOf): bool
    {
        return $asOf >= $this->reviewOn;
    }

    /** The row on the statutory audit pack (R-XVII-113). */
    public function describe(): string
    {
        return sprintf(
            "%s — departed from %s (%s)\n  basis: %s\n  evidence: %s\n  authorised by %s, approved by %s, "
            . '%s to %s, review %s',
            $this->ruleCode,
            $this->statutoryReference,
            $this->ruleCode,
            $this->legalBasis,
            $this->evidenceReference,
            $this->authorisedBy,
            $this->approvedBy,
            $this->effectiveFrom->format('Y-m-d'),
            $this->effectiveTo->format('Y-m-d'),
            $this->reviewOn->format('Y-m-d'),
        );
    }
}
