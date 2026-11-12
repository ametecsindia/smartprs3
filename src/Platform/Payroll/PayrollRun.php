<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;

/**
 * ONE PERIOD, ONE RUN — Part XIV §XIV.5.
 *
 * The whole pipeline in one object: inputs in, payslips out, and the
 * reconciliation assertion between the two. The ordering is the point —
 * `Reconciliation::assertOrAbort()` runs BEFORE this constructor returns, so
 * there is no reachable state in which a run exists and has not been reconciled.
 * A check you have to remember to call is a check somebody eventually does not.
 *
 * Two properties this class exists to guarantee:
 *
 *   REPRODUCIBLE. `rerun()` recomputes from the inputs and compares — the
 *   payslips must be identical to the paisa, and the run's own totals are
 *   presented to the reconciliation as the DECLARED header, so a re-run that
 *   drifts aborts rather than quietly replacing the first answer.
 *
 *   HONEST ABOUT DRIFT. If an input changed between the two — a revised gross, a
 *   different payable-day count, a superseded component version — the re-run says
 *   so, naming each change. Part XIV §XIV.6: reproducing a different number
 *   because the question changed is fine; not saying the question changed is not.
 */
final class PayrollRun
{
    /** @var list<Payslip> */
    public readonly array $payslips;

    /** @var array<string, InputSnapshot> */
    public readonly array $snapshots;

    /**
     * @param list<SalaryComponent> $components
     * @param list<array{employment_id: string, gross: Money, payable_days: int, period_days: int, recoveries?: array<string, Money>}> $inputs
     * @param array{earnings: Money, deductions: Money, net: Money, employee_count: int}|null $declared
     *        an independently-stated header to reconcile against, where one
     *        exists — an imported total, or a previous run's. Reconciling against
     *        a header this run derived from its own payslips would check nothing.
     *
     * @throws RunAborted when any reconciliation check fails; no payslip is issued
     */
    public function __construct(
        public readonly string $periodCode,
        private readonly array $components,
        array $inputs,
        ?array $declared = null,
    ) {
        $calculator = new PayslipCalculator($components);
        $payslips = [];
        $snapshots = [];

        foreach ($inputs as $input) {
            $recoveries = $input['recoveries'] ?? [];

            $payslips[] = $calculator->calculate(
                $input['employment_id'],
                $periodCode,
                $input['gross'],
                $input['payable_days'],
                $input['period_days'],
                $recoveries,
            );

            $snapshots[$input['employment_id']] = InputSnapshot::of(
                $input['employment_id'],
                $periodCode,
                $input['gross'],
                $input['payable_days'],
                $input['period_days'],
                $components,
                $recoveries,
            );
        }

        // Before the run exists, not after it is read.
        Reconciliation::assertOrAbort($payslips, $declared);

        $this->payslips = $payslips;
        $this->snapshots = $snapshots;
    }

    /** @return array{earnings: Money, deductions: Money, net: Money, employee_count: int} */
    public function totals(): array
    {
        $earnings = Money::zero();
        $deductions = Money::zero();
        $net = Money::zero();

        foreach ($this->payslips as $slip) {
            $earnings = $earnings->plus($slip->grossEarnings());
            $deductions = $deductions->plus($slip->totalDeductions());
            $net = $net->plus($slip->netPay());
        }

        return [
            'earnings' => $earnings,
            'deductions' => $deductions,
            'net' => $net,
            'employee_count' => count($this->payslips),
        ];
    }

    public function payslipFor(string $employmentId): ?Payslip
    {
        foreach ($this->payslips as $slip) {
            if ($slip->employmentId === $employmentId) {
                return $slip;
            }
        }

        return null;
    }

    /** Every figure on every slip, to the paisa — the identity a re-run must reproduce. */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->payslips);
    }

    /**
     * The same identity, for payslips gathered from OUTSIDE one run — a month
     * closed as several grouped runs (one per employment's own resolved
     * component set) still owes the register ONE fingerprint across
     * everybody, and it has to be this exact algorithm or a month closed
     * before the grouping existed would verify as changed for no real reason.
     *
     * @param list<Payslip> $payslips
     */
    public static function fingerprintOf(array $payslips): string
    {
        $rows = [];

        foreach ($payslips as $slip) {
            foreach ($slip->lines as $line) {
                $rows[] = $slip->employmentId . '|' . $line->componentCode . '|'
                    . $line->componentVersionId . '|' . $line->amount->minor;
            }
        }

        sort($rows);

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * Recompute this period and compare, in both directions.
     *
     * @param list<array{employment_id: string, gross: Money, payable_days: int, period_days: int, recoveries?: array<string, Money>}> $inputs
     * @param list<SalaryComponent>|null $components the definitions as they stand NOW; null re-uses this run's
     * @return array{run: self, reproduced: bool, changed_inputs: array<string, list<string>>, differences: list<string>}
     */
    public function rerun(array $inputs, ?array $components = null): array
    {
        // The original run's totals become the DECLARED header of the re-run, so
        // a drift in any figure aborts inside the constructor rather than
        // returning a second, quieter answer.
        $again = new self($this->periodCode, $components ?? $this->components, $inputs, $this->totals());

        $changed = [];

        foreach ($again->snapshots as $employmentId => $snapshot) {
            $before = $this->snapshots[$employmentId] ?? null;

            $changed[$employmentId] = $before === null
                ? ['the employment was not in the original run']
                : $before->diff($snapshot);

            if ($changed[$employmentId] === []) {
                unset($changed[$employmentId]);
            }
        }

        foreach ($this->snapshots as $employmentId => $_) {
            if (!isset($again->snapshots[$employmentId])) {
                $changed[$employmentId] = ['the employment is no longer in the run'];
            }
        }

        $differences = [];

        foreach ($this->payslips as $slip) {
            $now = $again->payslipFor($slip->employmentId);

            if ($now === null) {
                $differences[] = "{$slip->employmentId} has no payslip in the re-run";

                continue;
            }

            foreach ($slip->lines as $line) {
                $line2 = $now->lineFor($line->componentCode);

                if ($line2 === null) {
                    $differences[] = "{$slip->employmentId}: {$line->componentCode} is gone from the re-run";
                } elseif (!$line2->amount->equals($line->amount)) {
                    $differences[] = "{$slip->employmentId}: {$line->componentCode} was "
                        . $line->amount->format() . ' and is now ' . $line2->amount->format();
                }
            }
        }

        return [
            'run' => $again,
            'reproduced' => $differences === [] && $again->fingerprint() === $this->fingerprint(),
            'changed_inputs' => $changed,
            'differences' => $differences,
        ];
    }
}
