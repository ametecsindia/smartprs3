<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;

/**
 * ONE LINE ON A PAYSLIP — Part XIV §XIV.4.
 *
 * Every line carries the component VERSION it was computed from (RC-12) and a
 * trace (RC-13, Law 4). Both are structural rather than decorative: a re-run
 * that reproduces the original result has to know which version produced it, and
 * "how was this calculated?" is a question the payslip itself must be able to
 * answer.
 *
 * `amountBeforeProration` is kept alongside the amount, which is what makes
 * RC-08 checkable at all: the difference between the two, summed, must equal the
 * LOP lines. A slip that stores only the final amount cannot prove that its own
 * proration was applied consistently.
 */
final readonly class PayslipLine
{
    public function __construct(
        public string $componentCode,
        public string $componentVersionId,
        public string $kind,
        public Money $amount,
        public Money $amountBeforeProration,
        public string $trace,
    ) {
        if (trim($trace) === '') {
            throw new \InvalidArgumentException(
                "The line for '{$componentCode}' carries no trace. RC-13 requires one on every derived line, "
                . 'because a figure on a payslip that cannot explain itself is a figure somebody has to '
                . 'reconstruct from the configuration as it stands today rather than as it stood then.'
            );
        }
    }

    public function withAmount(Money $amount): self
    {
        return new self(
            $this->componentCode,
            $this->componentVersionId,
            $this->kind,
            $amount,
            $this->amountBeforeProration,
            $this->trace . ' [amount replaced]',
        );
    }

    /** What proration took off this line — the term RC-08 sums. */
    public function prorationDelta(): Money
    {
        return $this->amountBeforeProration->minus($this->amount);
    }
}
