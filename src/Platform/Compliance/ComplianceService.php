<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * THE ONE DEFINITION OF VALIDITY — Part XXI §XXI.5, R-XXI-049.
 *
 * "This section is the direct answer to DEFECT-F-08 and is the most important
 * requirement in this part." SmartPRS 2 has FIVE implementations of "is the DRA
 * valid?", each individually reasonable, and nothing prevented the second. So
 * there is exactly one `status()` in this codebase, the Phase 7 exit gate greps
 * for a second, and the consumer list is closed: the register, every dashboard
 * widget, the score, the audit pack, the money gate, operational gates, the
 * alert ladder, every report and export, the SPX `credential.*` and
 * `compliance.*` namespaces, and the public API all call THIS.
 *
 * THE ABSENT-RECORD RULE HAS EXACTLY THREE CASES AND NONE RETURNS VALID
 * (R-XXI-053):
 *
 *   applicable, no instance          → due,            basis evidence,      not valid
 *   not applicable                   → not_applicable, basis derived,       excluded
 *   applicability cannot be evaluated → due,           basis not_assessed,  not valid
 *
 * There is no fourth case. One of SmartPRS 2's five implementations treats a
 * blank free-text field as valid, and its compliance score awards 20 points when
 * the `complaints` table does not exist — the fix is not to choose a better
 * direction to guess in, it is to make absence impossible to read as a pass.
 *
 * VALIDITY IS EVALUATED AS AT THE DATE THE DECISION BELONGS TO (R-XXI-055),
 * never "now", which is why `$asOf` is a required parameter with no default: a
 * commission approved for September resolves as at September, and re-issuing an
 * audit pack for March in July reproduces the March verdict exactly.
 */
final class ComplianceService
{
    /**
     * @param callable(string, string, \DateTimeImmutable): ?bool $applicability
     *        returns null when applicability CANNOT be evaluated — the third case.
     *        A predicate that cannot answer must say so rather than defaulting,
     *        because both defaults are wrong: "applies" invents an obligation and
     *        "does not apply" hides one.
     * @param callable(string, string, \DateTimeImmutable): ?ComplianceItem $instances
     */
    public function __construct(
        private readonly array $types,
        private $applicability,
        private $instances,
    ) {
    }

    public function status(string $subjectRef, string $itemTypeCode, \DateTimeImmutable $asOf): ComplianceStatus
    {
        $type = $this->types[$itemTypeCode] ?? throw new \InvalidArgumentException(
            "No compliance item type '{$itemTypeCode}'. An unknown type is a refusal rather than a pass: a "
            . 'gate asked about a type nobody declared must not answer "fine".'
        );

        // Case 3, checked FIRST: a predicate that could not run has not decided.
        try {
            $applies = ($this->applicability)($subjectRef, $itemTypeCode, $asOf);
        } catch (\Throwable $e) {
            // The failure becomes a typed verdict rather than escaping into the
            // answer. SmartPRS 2's equivalent lets it become "compliant".
            return $this->notAssessed($subjectRef, $type, 'the applicability expression could not be '
                . 'evaluated: ' . $e->getMessage());
        }

        if ($applies === null) {
            return $this->notAssessed($subjectRef, $type,
                'applicability could not be determined for this subject on ' . $asOf->format('Y-m-d'));
        }

        // Case 2: not applicable is COMPUTED, with its reason, never a blank row.
        if ($applies === false) {
            return new ComplianceStatus(
                subjectRef: $subjectRef,
                itemTypeCode: $type->code,
                state: ComplianceState::NOT_APPLICABLE,
                isValid: false,
                assessmentBasis: ComplianceState::BASIS_DERIVED,
                reason: $type->appliesReasonTemplate,
                typeVersionId: $type->versionId,
            );
        }

        $item = ($this->instances)($subjectRef, $itemTypeCode, $asOf);

        // Case 1: applicable and no instance is DUE, not a gap and not a pass.
        if ($item === null) {
            return new ComplianceStatus(
                subjectRef: $subjectRef,
                itemTypeCode: $type->code,
                state: ComplianceState::DUE,
                isValid: false,
                assessmentBasis: ComplianceState::BASIS_EVIDENCE,
                reason: 'the type applies to this subject and no record exists. Absence of a record is '
                    . 'never a compliant state',
                typeVersionId: $type->versionId,
            );
        }

        $state = $item->stateOn($asOf);
        $daysToExpiry = $item->validTo === null
            ? null
            : (int) $asOf->diff($item->validTo)->format('%r%a');

        return new ComplianceStatus(
            subjectRef: $subjectRef,
            itemTypeCode: $type->code,
            state: $state,
            // R-XXI-052: valid is `valid_state_codes` and nothing else. A waiver
            // does not make a record valid; it makes the gate let it through,
            // which is a different decision recorded in a different place.
            isValid: $type->treatsAsValid($state),
            assessmentBasis: ComplianceState::BASIS_EVIDENCE,
            reason: $item->reasonOn($asOf, $type),
            typeVersionId: $type->versionId,
            itemId: $item->id,
            validFrom: $item->validFrom,
            validTo: $item->validTo,
            daysToExpiry: $daysToExpiry,
            evidenceState: $item->evidenceState,
            waived: $item->isWaived(),
        );
    }

    /**
     * The ONLY permitted way to evaluate more than one subject, so no consumer
     * can produce an N+1 query over the register.
     *
     * @param list<string> $subjectRefs
     * @param list<string> $itemTypeCodes
     * @return list<ComplianceStatus>
     */
    public function statusMany(array $subjectRefs, array $itemTypeCodes, \DateTimeImmutable $asOf): array
    {
        $statuses = [];

        foreach ($subjectRefs as $subject) {
            foreach ($itemTypeCodes as $code) {
                $statuses[] = $this->status($subject, $code, $asOf);
            }
        }

        return $statuses;
    }

    private function notAssessed(string $subjectRef, ItemType $type, string $why): ComplianceStatus
    {
        return new ComplianceStatus(
            subjectRef: $subjectRef,
            itemTypeCode: $type->code,
            state: ComplianceState::DUE,
            isValid: false,
            assessmentBasis: ComplianceState::BASIS_NOT_ASSESSED,
            reason: $why . '. A check that could not run has not passed, and this verdict PROPAGATES: the '
                . 'score reports it as unassessed rather than absorbing it, and the gate follows the '
                . "type's on_not_assessed setting, which defaults to block",
            typeVersionId: $type->versionId,
        );
    }
}
