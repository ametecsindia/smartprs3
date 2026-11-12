<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Compliance;

/**
 * A DOCUMENT THAT ASSERTS ONLY WHAT IT CHECKED — Part XXI §XXI.7, R-XXI-056.
 *
 * The Phase 7 exit criterion, in its own words: *a generated compliance document
 * contains no assertion that was not derived from stored evidence, and anything
 * unchecked reports as "not assessed"*.
 *
 * SmartPRS 2's RBI audit pack asserts facts it never checked: `'0'` for
 * disciplinary actions because nothing counts them, six quarters marked "Filed"
 * that nobody filed, and `score => 92` hard-coded on a customer-branded sample.
 * Each of those is a sentence a regulator reads as a statement by the company.
 *
 * So this class cannot produce a finding that is not backed by a
 * `ComplianceStatus`, and every line prints its `assessment_basis` and its source
 * item id. `notAssessedFindings()` is not a footnote — it is a first-class
 * section, because a pack that quietly omits what it could not check is a pack
 * that reads as clean.
 *
 * And the pack is re-issuable: it resolves as at the period end (R-XXI-055), so
 * re-issuing March's pack in July reproduces the March verdict exactly rather
 * than re-judging March by July's records.
 */
final class AuditPack
{
    /** @param array<string, ItemType> $types */
    public function __construct(
        private readonly ComplianceService $compliance,
        private readonly array $types,
    ) {
    }

    /**
     * @param list<string> $subjectRefs
     * @param list<string> $itemTypeCodes
     * @return array{
     *     period: string, as_of: string, findings: list<array<string, mixed>>,
     *     not_assessed: list<array<string, mixed>>, score: string, complete: bool
     * }
     */
    public function generate(
        string $periodLabel,
        \DateTimeImmutable $periodEnd,
        array $subjectRefs,
        array $itemTypeCodes,
    ): array {
        $statuses = $this->compliance->statusMany($subjectRefs, $itemTypeCodes, $periodEnd);
        $findings = [];
        $notAssessed = [];

        foreach ($statuses as $status) {
            $row = [
                'subject' => $status->subjectRef,
                'item_type' => $status->itemTypeCode,
                'state' => $status->state,
                'is_valid' => $status->isValid,
                // Every assertion carries how it was reached and what record
                // decided it. A finding with no source id is an opinion.
                'assessment_basis' => $status->assessmentBasis,
                'source_item_id' => $status->itemId,
                'reason' => $status->reason,
                'type_version' => $status->typeVersionId,
            ];

            if ($status->isNotAssessed()) {
                $notAssessed[] = $row;

                continue;
            }

            $findings[] = $row;
        }

        $score = ComplianceScore::of($statuses, $this->types);

        return [
            'period' => $periodLabel,
            'as_of' => $periodEnd->format('Y-m-d'),
            'findings' => $findings,
            'not_assessed' => $notAssessed,
            'score' => $score->render(),
            'complete' => $notAssessed === [],
        ];
    }

    /**
     * Does every assertion in a generated pack resolve to a stored record?
     *
     * The golden test R-XXI-056 asks for, as a method rather than as a test, so
     * the pack can check ITSELF before it is issued: a document that asserts
     * something no record supports must not reach a regulator, whatever the test
     * suite happened to cover.
     *
     * @param array{findings: list<array<string, mixed>>, not_assessed: list<array<string, mixed>>} $pack
     * @return list<string> empty means every sentence in the document is backed
     */
    public static function unbackedAssertions(array $pack): array
    {
        $problems = [];

        foreach ($pack['findings'] as $finding) {
            if ($finding['assessment_basis'] === ComplianceState::BASIS_NOT_ASSESSED) {
                $problems[] = "{$finding['subject']} / {$finding['item_type']}: a NOT ASSESSED verdict is "
                    . 'printed among the findings rather than in its own section, where a reader takes it '
                    . 'for a check that ran';
            }

            if ($finding['assessment_basis'] === ComplianceState::BASIS_EVIDENCE
                && $finding['source_item_id'] === null
                && $finding['state'] !== ComplianceState::DUE
            ) {
                $problems[] = "{$finding['subject']} / {$finding['item_type']}: claims to be evidence-based "
                    . 'and names no record. This is the shape of "0 disciplinary actions" asserted because '
                    . 'nothing counts them';
            }

            if (trim((string) $finding['reason']) === '') {
                $problems[] = "{$finding['subject']} / {$finding['item_type']}: a finding with no reason";
            }
        }

        return $problems;
    }
}
