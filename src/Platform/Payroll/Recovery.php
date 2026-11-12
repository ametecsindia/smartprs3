<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;

/**
 * AN ADVANCE, AND WHAT IS STILL OWED — Part XIV §XIV.7, R-XIV-081.
 *
 * The fifth Phase 5 exit criterion is one sentence: every recovery reverses
 * cleanly. Which means the outstanding after taking an instalment and then
 * reversing it is EXACTLY what it was before — not approximately, and not
 * because the row was deleted.
 *
 * So this is a LEDGER, like every balance in this build. The outstanding is a
 * SUM of entries, never a stored figure decremented in place, and a reversal is a
 * compensating entry that says a recovery was taken and then given back. Deleting
 * the instalment would produce the same number and destroy the only evidence that
 * anything happened — and the employee whose February payslip showed a deduction
 * that no longer exists anywhere is the one who has to prove it did.
 *
 * SmartPRS 2 updates `loan.outstanding` in place from a controller, so a reversed
 * instalment leaves a balance that is right by luck and a history that says the
 * deduction never occurred.
 */
final class Recovery
{
    /** @var list<array{period: string, amount: Money, kind: string, reverses: ?string, note: string}> */
    private array $entries = [];

    public function __construct(
        public readonly string $employmentId,
        public readonly string $componentCode,
        public readonly Money $principal,
        public readonly Money $instalment,
    ) {
        if ($principal->isNegative() || $instalment->isNegative()) {
            throw new \InvalidArgumentException(
                'A recovery with a negative principal or instalment is a payment, and a payment is an earning '
                . 'line. Making it a recovery hides which of the two it was.'
            );
        }
    }

    /** What is still owed: principal minus what has been recovered, net of reversals. */
    public function outstanding(): Money
    {
        $recovered = Money::zero();

        foreach ($this->entries as $entry) {
            $recovered = $entry['kind'] === 'reversal'
                ? $recovered->minus($entry['amount'])
                : $recovered->plus($entry['amount']);
        }

        return $this->principal->minus($recovered);
    }

    /**
     * The instalment due this period — never more than what is left.
     *
     * A final instalment is the remainder, not the standing amount. Taking the
     * full instalment on the last month is how an outstanding goes negative and a
     * loan quietly becomes a credit balance.
     */
    public function dueFor(string $periodCode): Money
    {
        $outstanding = $this->outstanding();

        if ($outstanding->isZero() || $outstanding->isNegative()) {
            return Money::zero();
        }

        return $outstanding->compareTo($this->instalment) < 0 ? $outstanding : $this->instalment;
    }

    public function take(string $periodCode, ?Money $amount = null): Money
    {
        $taken = $amount ?? $this->dueFor($periodCode);

        if ($taken->isZero()) {
            return $taken;
        }

        if ($taken->compareTo($this->outstanding()) > 0) {
            throw new \InvalidArgumentException(
                "Recovering {$taken->format()} against an outstanding of {$this->outstanding()->format()} "
                . 'would take more than is owed. An over-recovery is money the employer holds with no '
                . 'instrument behind it.'
            );
        }

        $this->entries[] = [
            'period' => $periodCode,
            'amount' => $taken,
            'kind' => 'instalment',
            'reverses' => null,
            'note' => "instalment for {$periodCode}",
        ];

        return $taken;
    }

    /**
     * Give back the instalment taken in a period — as an ENTRY, not a deletion.
     *
     * @throws \InvalidArgumentException when there is nothing to reverse, or it was already reversed
     */
    public function reverse(string $periodCode, string $reason): Money
    {
        $taken = Money::zero();
        $alreadyReversed = Money::zero();

        foreach ($this->entries as $entry) {
            if ($entry['period'] !== $periodCode) {
                continue;
            }

            if ($entry['kind'] === 'reversal') {
                $alreadyReversed = $alreadyReversed->plus($entry['amount']);
            } else {
                $taken = $taken->plus($entry['amount']);
            }
        }

        $reversible = $taken->minus($alreadyReversed);

        if ($reversible->isZero() || $reversible->isNegative()) {
            throw new \InvalidArgumentException(
                "Nothing left to reverse for {$periodCode} on {$this->componentCode}: "
                . "{$taken->format()} was taken and {$alreadyReversed->format()} has already been given back. "
                . 'A second reversal of one instalment is a refund the employee did not earn.'
            );
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A reversal with no reason is a correction nobody can review. Law 4 applies to money moving '
                . 'back as much as to money moving out.'
            );
        }

        $this->entries[] = [
            'period' => $periodCode,
            'amount' => $reversible,
            'kind' => 'reversal',
            'reverses' => $periodCode,
            'note' => $reason,
        ];

        return $reversible;
    }

    /** @return list<array{period: string, amount: Money, kind: string, reverses: ?string, note: string}> */
    public function history(): array
    {
        return $this->entries;
    }

    /** @return array<string, Money> the shape PayslipCalculator takes */
    public function asRecoveryInput(string $periodCode): array
    {
        $due = $this->dueFor($periodCode);

        return $due->isZero() ? [] : [$this->componentCode => $due];
    }
}
