<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

use SmartPRS\Platform\Support\Money;

/**
 * THE BASELINE IS A FLOOR — Part XVII §XVII.8.
 *
 * Four layers, and only the top one belongs to the customer:
 *
 *   1  central statutory baseline      pack  IN-<statute>
 *   2  state rule                      pack  IN-<subdivision>-<statute>
 *   3  establishment applicability     tenant declaration + evidence
 *   4  company enhanced policy         tenant artefact, floor-checked
 *
 * A company policy may IMPROVE on the statute and may never reduce it. That
 * direction is declared per rule by the pack — contributing on full wages rather
 * than on the ceiling is an improvement; contributing less than the rate is not —
 * so the check is a comparison against a declared direction rather than a
 * hard-coded "bigger is better", which is wrong for a deduction and right for a
 * contribution.
 *
 * SEVERITY IS THE PACK'S, NOT THE TENANT'S (R-XVII-112). A customer who believes
 * a `block` is wrong for their circumstances resolves it through APPLICABILITY —
 * an establishment genuinely outside coverage declares itself outside coverage,
 * with a basis and evidence, and that path is auditable. Softening a floor is not
 * available at any permission level, so there is no method here that takes a
 * severity from the caller.
 *
 * SmartPRS 2's nearest analogue is `esi_applicable = 'no'`: a free choice with no
 * reason, no evidence, and — being inert — no effect either.
 */
final class StatutoryFloor
{
    public const BLOCK = 'block';
    public const EXCEPTION_REQUIRED = 'exception_required';
    public const WARN = 'warn';

    public const HIGHER_IS_BETTER = 'higher_is_better';
    public const LOWER_IS_BETTER = 'lower_is_better';

    /**
     * Check a company policy value against the statutory floor.
     *
     * @param array{floor: Money, direction: string, severity: string, rule_code: string, source_reference: string} $declared
     *        exactly as the pack declares it — none of these four is a caller's choice
     * @return array{ok: bool, severity: ?string, why: string}
     */
    public static function check(Money $policyValue, array $declared, ?ComplianceException $exception = null): array
    {
        $floor = $declared['floor'];
        $comparison = $policyValue->compareTo($floor);

        $improves = match ($declared['direction']) {
            self::HIGHER_IS_BETTER => $comparison >= 0,
            self::LOWER_IS_BETTER => $comparison <= 0,
            default => throw new \InvalidArgumentException(
                "'{$declared['direction']}' is not a floor direction. A floor with no direction is a "
                . 'comparison that is right for a contribution and wrong for a deduction, which is the same '
                . 'as having no floor at all.'
            ),
        };

        if ($improves) {
            return ['ok' => true, 'severity' => null, 'why' => sprintf(
                'policy %s is at or above the statutory floor of %s for %s (%s)',
                $policyValue->format(),
                $floor->format(),
                $declared['rule_code'],
                $declared['source_reference'],
            )];
        }

        $severity = $declared['severity'];

        if ($severity === self::WARN) {
            return ['ok' => true, 'severity' => self::WARN, 'why' => sprintf(
                'policy %s is below the floor of %s for %s, and the pack declares this a WARNING',
                $policyValue->format(),
                $floor->format(),
                $declared['rule_code'],
            )];
        }

        if ($severity === self::EXCEPTION_REQUIRED) {
            if ($exception === null) {
                return ['ok' => false, 'severity' => $severity, 'why' => sprintf(
                    'policy %s is below the floor of %s for %s and the pack requires a RECORDED EXCEPTION: '
                    . 'a legal basis, the statutory reference, evidence, an authorising officer, a bounded '
                    . 'effective range and a review date. A checkbox is not an exception.',
                    $policyValue->format(),
                    $floor->format(),
                    $declared['rule_code'],
                )];
            }

            if (!$exception->coversRule($declared['rule_code'])) {
                return ['ok' => false, 'severity' => $severity, 'why' => 'the recorded exception covers '
                    . "'{$exception->ruleCode}', not '{$declared['rule_code']}' — an exception is authorised "
                    . 'for a specific departure, not as a general permission'];
            }

            return ['ok' => true, 'severity' => $severity, 'why' => sprintf(
                'policy %s is below the floor of %s for %s, authorised by %s until %s: %s',
                $policyValue->format(),
                $floor->format(),
                $declared['rule_code'],
                $exception->authorisedBy,
                $exception->effectiveTo->format('Y-m-d'),
                $exception->legalBasis,
            )];
        }

        // BLOCK. No exception path, at any permission level.
        return ['ok' => false, 'severity' => self::BLOCK, 'why' => sprintf(
            'policy %s is below the statutory floor of %s for %s and the pack declares this a BLOCK. There '
            . 'is no exception and no permission that softens it. If this establishment is genuinely outside '
            . 'coverage, the path is an APPLICABILITY declaration with a basis and evidence, which is '
            . 'auditable — not a severity somebody turned down.',
            $policyValue->format(),
            $floor->format(),
            $declared['rule_code'],
        )];
    }
}
