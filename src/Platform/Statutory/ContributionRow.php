<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

use SmartPRS\Platform\Support\Money;

/**
 * THE PAYSLIP THAT IS STILL DEFENSIBLE IN 2031 — Part XVII §XVII.9,
 * R-XVII-116 to R-XVII-119.
 *
 * Every statutory figure carries, with it and not beside it, everything needed
 * to re-derive it: the wage base and WHICH components composed it, the flag that
 * selected them, the resolved values actually used, the rounding, the pack
 * version and its content hash and signing key, the applicability determination
 * and its reason, and the rule's own source reference.
 *
 * R-XVII-119 is the test that makes this real rather than decorative: given the
 * snapshot and the pack version, an auditor re-derives the number WITHOUT the
 * database. That is why the composition is a list of component codes rather than
 * a total, and why the values are the resolved ones rather than a pointer to
 * wherever they live now.
 *
 * SmartPRS 2 recomputes an old month against the rates as they stand today. Its
 * March payslip is not wrong when you look at it in June; it is unreconstructable,
 * which is worse, because nobody can tell you whether it was ever right.
 */
final readonly class ContributionRow
{
    /**
     * @param list<string> $baseComposition
     * @param array<string, mixed> $resolvedValues
     */
    public function __construct(
        public string $head,
        public string $employmentId,
        public Money $wageBase,
        public array $baseComposition,
        public string $wageBaseFlag,
        public Money $amount,
        public string $ruleCode,
        public string $packKey,
        public string $packVersion,
        public string $packContentHash,
        public string $packKeyId,
        public \DateTimeImmutable $packEffectiveFrom,
        public array $resolvedValues,
        public string $rounding,
        public Binding $applicability,
        public string $sourceReference,
        public bool $synthetic = false,
        public bool $applicable = true,
    ) {
    }

    public static function notApplicable(string $head, string $employmentId, Binding $binding): self
    {
        return new self(
            head: $head,
            employmentId: $employmentId,
            wageBase: Money::zero(),
            baseComposition: [],
            wageBaseFlag: '',
            amount: Money::zero(),
            ruleCode: '',
            packKey: '',
            packVersion: '',
            packContentHash: '',
            packKeyId: '',
            packEffectiveFrom: new \DateTimeImmutable('@0'),
            resolvedValues: [],
            rounding: '',
            applicability: $binding,
            // Even a nil deduction states WHY it is nil. That sentence is the
            // difference between a statute that does not apply and a statute
            // nobody asked about, and only one of them is a finding.
            sourceReference: $binding->describe(),
            applicable: false,
        );
    }

    /** Is this figure re-derivable from what it carries? R-XVII-118 makes the answer structural. */
    public function isComplete(): bool
    {
        return !$this->applicable || (
            $this->packVersion !== ''
            && $this->packContentHash !== ''
            && $this->ruleCode !== ''
            && $this->sourceReference !== ''
            && $this->wageBaseFlag !== ''
        );
    }

    /** What the employee and the inspector read — §XVII.9.3. */
    public function explain(): string
    {
        if (!$this->applicable) {
            return sprintf('%-14s %14s   %s', $this->head, '—', $this->applicability->describe());
        }

        return sprintf(
            "%-14s %14s\n    base %s from %s (flag %s), %s rounding\n    rule %s of %s@%s (%s), %s\n"
            . "    applicability: %s%s",
            $this->head,
            $this->amount->format(),
            $this->wageBase->format(),
            $this->baseComposition === [] ? 'no components' : implode(' + ', $this->baseComposition),
            $this->wageBaseFlag,
            $this->rounding,
            $this->ruleCode,
            $this->packKey,
            $this->packVersion,
            substr($this->packContentHash, 0, 12),
            $this->sourceReference,
            $this->applicability->reason,
            $this->synthetic
                ? "\n    *** SYNTHETIC PACK — these values are machinery evidence, NOT law, and are not "
                    . 'verified against any primary source ***'
                : '',
        );
    }
}
