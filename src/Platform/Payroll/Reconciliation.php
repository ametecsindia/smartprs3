<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Payroll;

use SmartPRS\Platform\Support\Money;

/**
 * THE RECONCILIATION ASSERTION — Part XIV §XIV.5.6, R-XIV-068.
 *
 * "Any failure ABORTS the run — the run enters `failed`, no payslip is issued,
 * and the failure names the check, the employment and the difference to the
 * paise."
 *
 * Not a warning, not a status column, not a report somebody reads afterwards.
 * The run stops. That distinction is the whole of §XIV.1.7: SmartPRS 2 writes
 * `payroll_runs.net_total` once at generate and never corrects it, no query
 * anywhere compares it to `SUM(payslips.net)`, and no check asserts that an
 * employee's earnings minus deductions equals their net. Its bank-file total is
 * computed twice by two different float paths with no cross-check.
 *
 * Every check is exact integer paise. A difference of one paisa fails, because
 * a tolerance is how a systematic error hides: an error that is always under the
 * tolerance is an error that is always there.
 *
 * R-XIV-069 makes that testable rather than believed: a deliberate ₹0.01
 * perturbation injected into any line MUST cause the corresponding check to
 * fail, and the Phase 5 exit gate injects one.
 */
final class Reconciliation
{
    /**
     * Run the assertion over a whole payroll run.
     *
     * @param  list<Payslip>  $payslips
     * @param  array{earnings: Money, deductions: Money, net: Money, employee_count: int}|null $declared
     *         the run header's own totals, where one exists. Comparing the
     *         header against the slips is RC-04 to RC-06 and RC-11, and it is
     *         the comparison SmartPRS 2 has nowhere.
     * @return list<array{check: string, employment: ?string, expected: string, actual: string, difference: string}>
     *         empty means the run may proceed
     */
    public static function check(array $payslips, ?array $declared = null): array
    {
        $failures = [];

        $runEarnings = Money::zero();
        $runDeductions = Money::zero();
        $runNet = Money::zero();
        $runEmployerCost = Money::zero();

        foreach ($payslips as $slip) {
            // RC-01: the earning lines sum to the gross. Structural here, and
            // asserted anyway — a derived total that is never checked is a
            // derived total somebody eventually caches.
            $earnings = Money::zero();

            foreach ($slip->linesOfKind(SalaryComponent::EARNING) as $line) {
                $earnings = $earnings->plus($line->amount);
            }

            self::assert($failures, 'RC-01', $slip->employmentId, $earnings, $slip->grossEarnings());

            $deductions = Money::zero();

            foreach ([SalaryComponent::DEDUCTION, SalaryComponent::RECOVERY] as $kind) {
                foreach ($slip->linesOfKind($kind) as $line) {
                    $deductions = $deductions->plus($line->amount);
                }
            }

            self::assert($failures, 'RC-02', $slip->employmentId, $deductions, $slip->totalDeductions());

            // RC-03: gross − deductions = net, WITHOUT a clamp. SmartPRS 2
            // clamps net at zero while leaving total_deductions at full value,
            // so the stored figures stop satisfying their own equation and the
            // PDF prints arithmetic that is wrong on the employee's document.
            self::assert(
                $failures,
                'RC-03',
                $slip->employmentId,
                $slip->grossEarnings()->minus($slip->totalDeductions()),
                $slip->netPay(),
            );

            // RC-08: what proration took off, summed, is the LOP.
            $prorationDelta = Money::zero();

            foreach ($slip->lines as $line) {
                if ($line->kind === SalaryComponent::EARNING) {
                    $prorationDelta = $prorationDelta->plus($line->prorationDelta());
                }
            }

            $unprorated = Money::zero();

            foreach ($slip->linesOfKind(SalaryComponent::EARNING) as $line) {
                $unprorated = $unprorated->plus($line->amountBeforeProration);
            }

            self::assert(
                $failures,
                'RC-08',
                $slip->employmentId,
                $unprorated->minus($slip->grossEarnings()),
                $prorationDelta,
            );

            // RC-12 and RC-13: every line names its version and carries a trace.
            foreach ($slip->lines as $line) {
                if (trim($line->componentVersionId) === '') {
                    $failures[] = self::failure('RC-12', $slip->employmentId,
                        'a component version', 'none', 'the line cannot say which version computed it');
                }

                if (trim($line->trace) === '') {
                    $failures[] = self::failure('RC-13', $slip->employmentId,
                        'a trace', 'none', 'the figure cannot explain itself (Law 4)');
                }
            }

            // RC-17: one currency per payslip. A cross-currency sum is not a
            // rounding error, it is a number with no meaning.
            $currencies = [];

            foreach ($slip->lines as $line) {
                $currencies[$line->amount->currency] = true;
            }

            if (count($currencies) > 1) {
                $failures[] = self::failure('RC-17', $slip->employmentId, 'one currency',
                    implode(', ', array_keys($currencies)), 'a cross-currency sum occurred');
            }

            $runEarnings = $runEarnings->plus($slip->grossEarnings());
            $runDeductions = $runDeductions->plus($slip->totalDeductions());
            $runNet = $runNet->plus($slip->netPay());
            $runEmployerCost = $runEmployerCost->plus($slip->employerCost());
        }

        if ($declared !== null) {
            self::assert($failures, 'RC-04', null, $runEarnings, $declared['earnings']);
            self::assert($failures, 'RC-05', null, $runDeductions, $declared['deductions']);
            self::assert($failures, 'RC-06', null, $runNet, $declared['net']);

            if (count($payslips) !== $declared['employee_count']) {
                $failures[] = self::failure('RC-11', null, (string) $declared['employee_count'],
                    (string) count($payslips), 'the run header and the payslips disagree about who is in it');
            }
        }

        return $failures;
    }

    /**
     * @param  list<Payslip>  $payslips
     * @throws RunAborted when any check fails — the run does not continue
     */
    public static function assertOrAbort(array $payslips, ?array $declared = null): void
    {
        $failures = self::check($payslips, $declared);

        if ($failures !== []) {
            throw new RunAborted($failures);
        }
    }

    /** @param list<array<string, string|null>> $failures */
    private static function assert(
        array &$failures,
        string $check,
        ?string $employmentId,
        Money $expected,
        Money $actual,
    ): void {
        if (!$expected->equals($actual)) {
            $failures[] = self::failure(
                $check,
                $employmentId,
                $expected->format(),
                $actual->format(),
                // To the paisa. A reconciliation that reports "a small
                // difference" has told nobody which line to look at.
                Money::ofMinor($actual->minor - $expected->minor)->format(),
            );
        }
    }

    /** @return array{check: string, employment: ?string, expected: string, actual: string, difference: string} */
    private static function failure(
        string $check,
        ?string $employmentId,
        string $expected,
        string $actual,
        string $difference,
    ): array {
        return [
            'check' => $check,
            'employment' => $employmentId,
            'expected' => $expected,
            'actual' => $actual,
            'difference' => $difference,
        ];
    }
}
