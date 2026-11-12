<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * THE PAYMENT INSTRUCTION — the point where a payroll stops being a report.
 *
 * Everything before this is arithmetic somebody can argue with. This is the file
 * the bank acts on, so the failure modes change: an employee silently missing
 * from the advice is a person who is not paid, and nothing on the payroll screen
 * will say so, because the payroll was right.
 *
 * SO NOBODY IS DROPPED. An employee with no bank details does not fall out of
 * a filter — the advice REFUSES to generate and names them. A file that pays 47
 * of 48 people balances perfectly against its own total and is wrong.
 *
 * THE CONTROL TOTAL IS CHECKED AGAINST THE RUN, not recomputed from the rows it
 * just wrote. Summing the file and comparing it to itself proves the addition,
 * which was never in doubt. The question is whether the file agrees with the
 * payroll it claims to pay, and that requires the run's own figure.
 *
 * A NEGATIVE NET IS NOT PAID AND NOT HIDDEN. A bank cannot take money back with
 * a credit instruction, so those lines are excluded from the payment — and
 * listed as recoveries, because a negative net that quietly disappears is a debt
 * nobody is tracking.
 *
 * ponytail: CSV in a generic shape, not a bank's own format. Real advices are
 * per-bank fixed-width or NEFT/RTGS XML; that is a format per customer, not a
 * different design.
 */
final class BankAdvice
{
    /**
     * @param array<string, mixed> $run a closed run
     * @param array<string, array<string, string>> $accounts employee code => account details
     * @return array{ok: bool, why: string, csv?: string, lines?: list<array<string, mixed>>,
     *     recoveries?: list<array<string, mixed>>, total?: string}
     */
    public static function generate(array $run, array $accounts): array
    {
        if (!isset($run['approved_by'])) {
            return ['ok' => false, 'why' => 'The run for ' . (string) $run['period'] . ' has not been '
                . 'APPROVED, so no payment file is produced. The approval is the moment a person '
                . 'takes responsibility for the figure; a bank file generated before it is a payment '
                . 'nobody authorised, and the bank cannot tell the difference.'];
        }

        $lines = [];
        $recoveries = [];
        $missing = [];
        $payMinor = 0;
        $checkMinor = 0;

        // The month's own nets, then the arrears — each employee's payment is
        // the sum of both, because a bank line per correction is a second credit
        // the employee has to reconcile themselves.
        $byEmployment = [];

        foreach ((array) $run['rows'] as $code => $row) {
            $byEmployment[(string) $code] = ['name' => (string) $row['name'],
                'minor' => (int) ($row['net_minor'] ?? 0), 'arrears' => 0];
        }

        foreach ((array) ($run['arrears'] ?? []) as $arrear) {
            $code = (string) $arrear['employment_id'];
            $byEmployment[$code] ??= ['name' => (string) $arrear['name'], 'minor' => 0, 'arrears' => 0];
            $byEmployment[$code]['arrears'] += (int) $arrear['amount_minor'];
        }

        foreach ($byEmployment as $code => $entry) {
            $total = $entry['minor'] + $entry['arrears'];
            // Every employee counts towards the check total, paid or not.
            // Otherwise the exclusions are exactly what the reconciliation
            // cannot see.
            $checkMinor += $total;

            if ($total < 0) {
                $recoveries[] = ['employment_id' => $code, 'name' => $entry['name'],
                    'amount' => Money::ofMinor($total)->format(),
                    'why' => 'a negative net cannot be paid by a credit instruction — it is a '
                        . 'recovery, listed here rather than dropped, because a negative that '
                        . 'quietly disappears is a debt nobody is tracking'];

                continue;
            }

            if ($total === 0) {
                $recoveries[] = ['employment_id' => $code, 'name' => $entry['name'],
                    'amount' => Money::ofMinor(0)->format(),
                    'why' => 'nothing is payable this period — stated, because an employee absent '
                        . 'from the advice and absent from this list is one nobody checked'];

                continue;
            }

            $account = $accounts[$code] ?? null;

            if ($account === null || ($account['account'] ?? '') === ''
                || ($account['ifsc'] ?? '') === '') {
                $missing[] = $code . ' (' . $entry['name'] . ')';

                continue;
            }

            $payMinor += $total;
            $lines[] = [
                'employment_id' => $code,
                'name' => $entry['name'],
                'account' => (string) $account['account'],
                'ifsc' => (string) $account['ifsc'],
                'amount' => Money::ofMinor($total)->format(),
                'amount_minor' => $total,
                'arrears' => Money::ofMinor($entry['arrears'])->format(),
                'arrears_minor' => $entry['arrears'],
            ];
        }

        if ($missing !== []) {
            return ['ok' => false, 'why' => count($missing) . ' employee(s) have no bank account on '
                . 'record, so NO advice was generated: ' . implode(', ', $missing) . '. They are not '
                . 'filtered out — a file that pays 47 of 48 people balances perfectly against its own '
                . 'total and is wrong, and nothing on the payroll screen would say so.'];
        }

        // Against the RUN, not against the rows just written. Summing the file
        // and comparing it to itself proves the addition, which was never in
        // doubt.
        $declared = (int) ($run['payable_minor'] ?? $run['net_minor'] ?? 0);

        if ($checkMinor !== $declared) {
            return ['ok' => false, 'why' => 'The advice does not reconcile to the run: the run '
                . 'declares ' . Money::ofMinor($declared)->format() . ' payable and the advice '
                . 'accounts for ' . Money::ofMinor($checkMinor)->format() . '. Nothing was generated. '
                . 'A payment file that does not agree with the payroll it claims to pay is the one '
                . 'artefact that must never be produced "for now".'];
        }

        $csv = "employee,name,account,ifsc,amount,of_which_arrears\n";

        foreach ($lines as $line) {
            // PLAIN decimals, never the display format, and never `minor / 100`
            // — which is an int division that produces a FLOAT, on the file the
            // bank pays people from. `toDecimalString()` is the same shape,
            // in integers.
            // A formatted amount
            // carries a thousands separator, and one stray comma in a CSV field
            // shifts every column after it. A bank file fails quietly.
            $csv .= implode(',', [$line['employment_id'], '"' . str_replace('"', '""',
                $line['name']) . '"', $line['account'], $line['ifsc'],
                Money::ofMinor((int) $line['amount_minor'])->toDecimalString(),
                Money::ofMinor((int) $line['arrears_minor'])->toDecimalString()]) . "\n";
        }

        return ['ok' => true, 'csv' => $csv, 'lines' => $lines, 'recoveries' => $recoveries,
            'total' => Money::ofMinor($payMinor)->format(),
            'why' => count($lines) . ' payment(s) totalling ' . Money::ofMinor($payMinor)->format()
                . '. The advice reconciles to the run: ' . Money::ofMinor($declared)->format()
                . ' payable, of which ' . Money::ofMinor($declared - $payMinor)->format()
                . ' is not paid by credit and is listed as recoveries or nil.'];
    }
}
