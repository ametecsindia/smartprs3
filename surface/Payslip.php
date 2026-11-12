<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * THE DOCUMENT THE EMPLOYEE KEEPS.
 *
 * A payslip is the one thing a payroll system produces that leaves the building.
 * It is shown to a bank for a loan, to a landlord, to the next employer, and to a
 * tribunal when something has gone wrong. So the rules around it are stricter
 * than the rules around a screen.
 *
 * IT IS READ FROM THE CLOSED RUN, NEVER RECOMPUTED. The register already refuses
 * to recompute a closed month; a payslip that recomputed would be worse, because
 * the recomputation would happen months later against a structure that has since
 * changed, and the employee's copy and the company's copy would differ with
 * nobody able to say which was issued. Every figure here comes out of the stored
 * run.
 *
 * A PAYSLIP FROM AN UNAPPROVED RUN SAYS SO, ON ITS FACE. The figures may be
 * complete and still be nobody's responsibility — approval is the moment a person
 * signs. So an unapproved run produces a payslip watermarked as not authorised
 * rather than no payslip at all: HR needs to check the draft, and the employee
 * must never be handed one that looks final and is not.
 *
 * EVERY LINE CARRIES ITS TRACE. The run stored, for each component, the sentence
 * explaining how that number was reached — "40% of C-BASIC (₹18,000) = ₹7,200".
 * SmartPRS 2's payslip prints amounts; this one prints the arithmetic, because
 * the question an employee actually asks is not what the HRA is, it is why it
 * changed.
 *
 * ponytail: renders as a screen, printable through the browser. A PDF writer is
 * the next step and does not change any of the above — the text has to be right
 * before it is worth typesetting.
 */
final class Payslip
{
    /**
     * The engine's own kind for the company's share — see
     * SmartPRS\Platform\Payroll\SalaryComponent::EMPLOYER_CONTRIBUTION. Named
     * once here so the one place that sorts a line into a bucket reads the same
     * word the run wrote.
     */
    public const EMPLOYER = 'employer_contribution';

    /**
     * One employee's payslip for one closed period.
     *
     * Takes the RUN, not the register that found it. A payslip is a reading of
     * one stored month and nothing else — handing it the whole register would
     * give it the ability to recompute, which is the one thing it must not do.
     * It also means this can be tested against a run without a database, a
     * structure, an attendance engine and a leave policy standing behind it.
     *
     * @param array<string, mixed>|null $run the closed run, or null if there is none
     * @return array{ok: bool, why: string, slip?: array<string, mixed>}
     */
    public static function from(?array $run, string $period, string $code): array
    {
        if ($run === null) {
            // NOT an empty payslip. A blank document with somebody's name on it is
            // the one thing worse than no document: it looks like a month in which
            // they earned nothing.
            return ['ok' => false, 'why' => sprintf(
                'No payroll has been closed for %s, so there is nothing to issue. A payslip is read '
                . 'from a closed run and never computed on the way to the printer — a figure worked '
                . 'out at the moment somebody asks for a copy is a figure that can differ from the '
                . 'one that was paid.', $period)];
        }

        $rows = (array) ($run['rows'] ?? []);

        if (!isset($rows[$code])) {
            return ['ok' => false, 'why' => sprintf(
                '%s is not in the %s run. That is reported rather than shown as a payslip with '
                . 'nothing on it: "not in this month\'s payroll" and "paid nothing this month" are '
                . 'different facts, and only one of them is somebody\'s mistake.', $code, $period)];
        }

        $row = (array) $rows[$code];
        $earnings = [];
        $deductions = [];
        $employer = [];

        foreach ((array) ($row['lines'] ?? []) as $line) {
            $entry = [
                'code' => (string) $line['code'],
                'amount' => (string) $line['amount'],
                'minor' => (int) $line['minor'],
                // The sentence the RUN wrote when it computed this number, stored
                // with it. Not regenerated here: a fresh explanation of an old
                // number is a guess about what the rules used to be.
                'trace' => (string) ($line['trace'] ?? ''),
            ];

            // AN EMPLOYER CONTRIBUTION IS NOT A DEDUCTION. It is the company's own
            // cost — the employer's half of PF, gratuity, group insurance — and it
            // is never withheld from anybody's pay.
            //
            // This used to read `kind === 'earning' ? earnings : deductions`, so
            // every employer line landed in the deduction list. The TOTAL was
            // right, because the run computed it without them, which made the
            // defect worse rather than better: the printed deduction lines did not
            // add up to the printed total deductions, on the one document in this
            // product that leaves the building and gets shown to a bank.
            //
            // Three buckets, and the third is printed apart and left out of every
            // sum — which is the same rule Surface\PayrollReports keeps, and the
            // engine's own `SalaryComponent::EMPLOYER_CONTRIBUTION` is what decides
            // it here rather than a list of component codes typed into this file.
            if ((string) $line['kind'] === 'earning') {
                $earnings[] = $entry;
            } elseif ((string) $line['kind'] === self::EMPLOYER) {
                $employer[] = $entry;
            } else {
                $deductions[] = $entry;
            }
        }

        $approved = (string) ($run['status'] ?? '') === 'approved';

        return ['ok' => true, 'why' => '', 'slip' => [
            'period' => $period,
            'code' => $code,
            'name' => (string) ($row['name'] ?? ''),
            'earnings' => $earnings,
            'deductions' => $deductions,
            // Printed in its own block, in no total, and NEVER in the net.
            'employer' => $employer,
            'employer_total' => Money::ofMinor(
                (int) array_sum(array_column($employer, 'minor')))->format(),
            // WHAT THIS MONTH COST THE COMPANY: the gross it contracted to pay
            // plus everything it pays on top of that gross. It is NOT the net,
            // and it is NOT the gross — it is the third figure, and this market
            // quotes salaries in it, so a payslip that prints gross and net and
            // never names it leaves the employee to work it out from a number
            // they were never shown.
            //
            // SUMMED FROM THE LINES rather than taken from any stored total,
            // because no stored total holds it: the run records earnings,
            // deductions and net, and the employer's share sits outside all
            // three by design.
            'company_cost' => Money::ofMinor(
                (int) array_sum(array_column($earnings, 'minor'))
                + (int) array_sum(array_column($employer, 'minor')))->format(),
            'earnings_total' => (string) ($row['earnings'] ?? '₹0.00'),
            'deductions_total' => (string) ($row['deductions'] ?? '₹0.00'),
            'net' => (string) ($row['net'] ?? '₹0.00'),
            'net_minor' => (int) ($row['net_minor'] ?? 0),
            'net_in_words' => self::words((int) ($row['net_minor'] ?? 0)),
            // A STRING, NOT AN INT, because a day can be a half. This read is
            // what a payslip prints, and `(int) '17.5'` is 17 with nothing said.
            'payable_days' => (string) ($row['payable_days'] ?? '0'),
            'period_days' => (int) ($row['period_days'] ?? 0),
            // How the payable days were made up (runs closed from 7 Oct on).
            'days' => (array) ($row['days'] ?? []),
            'concerns' => (array) ($row['concerns'] ?? []),
            // The statutory heads the employee's own record switched off (PF
            // Applicable: No, …) — so a missing PF line reads as a decision on
            // the record, not as a deduction the run forgot.
            'statutory_not_applicable' => array_values(array_map('strval', (array) ($row['statutory_not_applicable'] ?? []))),
            'approved' => $approved,
            'approved_by' => (string) ($run['approved_by'] ?? ''),
            'approved_at' => (string) ($run['approved_at'] ?? ''),
            'closed_at' => (string) ($run['closed_at'] ?? ''),
            'structure_from' => (string) ($run['structure_effective_from'] ?? ''),
            'fingerprint' => (string) ($run['fingerprint'] ?? ''),
        ]];
    }

    /**
     * The net, written out.
     *
     * A payslip states the figure twice for the same reason a cheque does: the
     * words are what a dispute is settled by. Through `Money`, so it is the Indian
     * scale — lakh and crore — like every other amount in this product.
     */
    public static function words(int $minor): string
    {
        return Money::ofMinor($minor)->toWords();
    }

    /**
     * Which periods this employee actually has a payslip for.
     *
     * Asked of the runs rather than of a date range: a month somebody was not
     * employed in has no payslip, and offering one that renders "not in this run"
     * is a dead end with a person's name on it.
     *
     * @param list<array<string, mixed>> $runs
     * @return list<string>
     */
    public static function periodsIn(array $runs, string $code): array
    {
        $periods = [];

        foreach ($runs as $run) {
            if (isset(((array) ($run['rows'] ?? []))[$code])) {
                $periods[] = (string) $run['period'];
            }
        }

        rsort($periods);

        return $periods;
    }
}
