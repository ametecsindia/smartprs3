<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Migration;

use SmartPRS\Platform\Support\Money;

/**
 * ONE DIFFERENCE, CLASSIFIED — Part XXIX §XXIX.7.3.
 *
 * Three tolerance classes and nothing else:
 *
 *   E — EXACT. No tolerance. Not "within ₹1", not "within 0.01%". A migrated
 *       payslip is a COPY, not a recomputation, and there is no mechanism by
 *       which a faithful copy differs — so a variance means a row was lost,
 *       duplicated, mis-typed or mis-summed. It is also the customer's statutory
 *       record: PF, ESI, PT and TDS have been filed against these figures, and a
 *       migration that moves them by a rupee has changed a filed return.
 *
 *   X — EXPLAINED. Permitted only with a named cause, a REFERENCE, a quantity,
 *       and a named person who accepted it. R-XXIX-103 is unusually blunt about
 *       the failure mode: *a Class X variance with no reference is a Class E
 *       failure. "We think it is because…" is not an explanation.*
 *
 *   C — EXPECTED CORRECTION, reported as a SUCCESS. Admissible only if it
 *       satisfies all four of R-XXIX-106, and the second one is the guard that
 *       keeps the class honest: the difference must move in the direction the
 *       correction predicts. An LOP correction that INCREASES net pay is not a
 *       Class C at all, it is an unexplained difference wearing a label.
 *
 * The demotion is deliberate and one-way. Anything that fails its own class's
 * requirements becomes Class E — a failure — rather than falling to a weaker
 * class, because a classification that degrades under missing evidence is a
 * classification that everything eventually qualifies for.
 */
final readonly class Variance
{
    public const EXACT = 'E';
    public const EXPLAINED = 'X';
    public const CORRECTION = 'C';

    public string $effectiveClass;
    public string $demotionReason;

    public function __construct(
        public string $checkCode,
        public string $subject,
        public string $period,
        public Money $legacyValue,
        public Money $newValue,
        public string $declaredClass,
        public ?string $reference = null,
        public ?string $acceptedBy = null,
        public ?string $classCCode = null,
        public bool $disclosedBeforeGoLive = false,
        public bool $rulingResolved = false,
    ) {
        [$this->effectiveClass, $this->demotionReason] = $this->classify();
    }

    public function deltaMinor(): int
    {
        return $this->newValue->minor - $this->legacyValue->minor;
    }

    public function delta(): Money
    {
        return Money::ofMinor($this->deltaMinor());
    }

    public function isFailure(): bool
    {
        return $this->effectiveClass === self::EXACT && $this->deltaMinor() !== 0;
    }

    /** A Class C difference is a SUCCESS and is never counted as a failure. */
    public function isCorrection(): bool
    {
        return $this->effectiveClass === self::CORRECTION;
    }

    /** @return array{0: string, 1: string} */
    private function classify(): array
    {
        if ($this->deltaMinor() === 0) {
            return [self::EXACT, ''];
        }

        if ($this->declaredClass === self::EXPLAINED) {
            if ($this->reference === null || trim($this->reference) === '') {
                return [self::EXACT, 'R-XXIX-103: a Class X variance with no reference is a Class E '
                    . 'failure. "We think it is because..." is not an explanation'];
            }

            if ($this->acceptedBy === null || trim($this->acceptedBy) === '') {
                return [self::EXACT, 'the explanation names no person who accepted it, so nobody has '
                    . 'accepted it'];
            }

            return [self::EXPLAINED, ''];
        }

        if ($this->declaredClass === self::CORRECTION) {
            // All four of R-XXIX-106, and failing any one of them is not a
            // softer Class C — it is a difference nobody has explained.
            if ($this->classCCode === null || !ClassCRegister::knows($this->classCCode)) {
                return [self::EXACT, 'the correction names no entry in the Class C defect register ('
                    . ($this->classCCode ?? 'none') . '), so it cites no defect it is correcting'];
            }

            if (!ClassCRegister::directionHolds($this->classCCode, $this->deltaMinor())) {
                return [self::EXACT, sprintf(
                    '%s predicts the figure %s and it moved by %s. R-XXIX-106 clause 2: when the direction '
                    . 'is wrong the classification is wrong — this is an unexplained difference wearing a '
                    . 'label, not a correction',
                    $this->classCCode,
                    ClassCRegister::ENTRIES[$this->classCCode]['direction'],
                    $this->delta()->format(),
                )];
            }

            if (!$this->disclosedBeforeGoLive) {
                return [self::EXACT, 'the correction was not disclosed to the customer before go-live. '
                    . 'R-XXIX-106 clause 4 exists because the customer reads this report, and a correction '
                    . 'they meet for the first time in it is a surprise about their own payroll'];
            }

            if (ClassCRegister::blockedOnRuling($this->classCCode) && !$this->rulingResolved) {
                return [self::EXACT, "R-XXIX-108: {$this->classCCode} depends on an unresolved compliance "
                    . 'ruling and must not be applied until the ruling exists'];
            }

            return [self::CORRECTION, ''];
        }

        return [self::EXACT, 'a Class E check differs, and a Class E variance may not be waived, accepted, '
            . 'rounded away or netted against another variance (R-XXIX-102)'];
    }
}
