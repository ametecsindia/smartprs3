<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;

/**
 * ONE EMPLOYEE'S PAY FOR ONE PERIOD — Part XIV §XIV.4.
 *
 * A list of lines and three totals, and the totals are DERIVED rather than
 * stored independently. That is the whole of RC-01 to RC-03 made structural:
 * `grossEarnings()` sums the earning lines because there is nowhere else for it
 * to come from, so a line rejected later cannot leave a total behind.
 *
 * SmartPRS 2 writes `payroll_runs.net_total` once at generate and never corrects
 * it; a line later rejected or held still contributes. And `computeSlip` clamps
 * `net = max(0, gross − deductions)` while leaving `total_deductions` at full
 * value, so on a heavily prorated slip the stored figures no longer satisfy
 * their own equation and the PDF prints arithmetic the employee can see is
 * wrong.
 *
 * So: NET IS NOT CLAMPED. A negative net is the answer — it means the month's
 * fixed deductions exceeded a prorated salary, which is a real situation with a
 * real remedy (a recovery, carried forward, decided by a person). Hiding it
 * behind a zero destroys the evidence and breaks RC-03 at the same time.
 */
final readonly class Payslip
{
    /** @param list<PayslipLine> $lines */
    public function __construct(
        public string $employmentId,
        public string $periodCode,
        public array $lines,
        public string $trace = '',
    ) {
    }

    public function grossEarnings(): Money
    {
        return $this->sumOf(SalaryComponent::EARNING);
    }

    /** Deductions and recoveries both reduce net; employer contributions do not. */
    public function totalDeductions(): Money
    {
        return $this->sumOf(SalaryComponent::DEDUCTION)->plus($this->sumOf(SalaryComponent::RECOVERY));
    }

    public function employerCost(): Money
    {
        return $this->sumOf(SalaryComponent::EMPLOYER_CONTRIBUTION);
    }

    /** RC-03, by construction: there is no other definition of net in this codebase. */
    public function netPay(): Money
    {
        return $this->grossEarnings()->minus($this->totalDeductions());
    }

    /** @return list<PayslipLine> */
    public function linesOfKind(string $kind): array
    {
        return array_values(array_filter($this->lines, static fn (PayslipLine $l): bool => $l->kind === $kind));
    }

    public function lineFor(string $componentCode): ?PayslipLine
    {
        foreach ($this->lines as $line) {
            if ($line->componentCode === $componentCode) {
                return $line;
            }
        }

        return null;
    }

    /** A copy with one line's amount moved — for R-XIV-069's perturbation test, and nothing else. */
    public function withPerturbation(string $componentCode, int $paise): self
    {
        return new self(
            $this->employmentId,
            $this->periodCode,
            array_map(
                static fn (PayslipLine $l): PayslipLine => $l->componentCode === $componentCode
                    ? $l->withAmount($l->amount->plus(Money::ofMinor($paise)))
                    : $l,
                $this->lines,
            ),
            $this->trace,
        );
    }

    private function sumOf(string $kind): Money
    {
        $total = Money::zero();

        foreach ($this->linesOfKind($kind) as $line) {
            $total = $total->plus($line->amount);
        }

        return $total;
    }

    public function render(): string
    {
        $lines = ["Payslip {$this->employmentId} · {$this->periodCode}"];

        foreach ($this->lines as $line) {
            $lines[] = sprintf('  %-12s %-22s %14s', $line->kind, $line->componentCode, $line->amount->format());
        }

        $lines[] = sprintf('  %-35s %14s', 'gross earnings', $this->grossEarnings()->format());
        $lines[] = sprintf('  %-35s %14s', 'total deductions', $this->totalDeductions()->format());
        $lines[] = sprintf('  %-35s %14s', 'NET PAY', $this->netPay()->format());

        return implode("\n", $lines);
    }
}
