<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

use SmartPRS\Platform\Support\Money;

/**
 * WHAT WE ACTUALLY FILED — Part XVII §XVII.11.4, R-XVII-131 to R-XVII-133.
 *
 * The seven steps of R-XVII-131, and step 2 is the one that matters:
 *
 *   SELECT STORED CONTRIBUTION ROWS. Never a recomputation, never from CTC,
 *   never over an unfiltered employee set. A return recomputed at filing time is
 *   a return that can disagree with the payslips it is meant to summarise, and
 *   the employee holds the payslip.
 *
 * So this class takes `ContributionRow` objects that were STORED when payroll
 * ran, and there is no code path here that computes an amount. The population is
 * exactly the rows for that registration and period, which naturally includes a
 * leaver who was paid in the period and naturally excludes one who was not —
 * against SmartPRS 2, which selects all non-deleted employees with no status,
 * company or period filter and labels the output with today's month
 * (DEFECT-F-04).
 *
 * And step 6: IF ANY ASSERTION FAILS, DO NOT PRODUCE THE FILE. A return that is
 * generated with a warning is a return that gets filed with a warning.
 */
final class ReturnFile
{
    /**
     * @param list<ContributionRow> $stored every contribution row the payroll run wrote
     * @param list<array{code: string, from: Money, to: Money}> $declaredTotals
     *        an independently-stated control total, where the filing carries one
     * @return array{ok: bool, file: ?string, hash: ?string, rows: int, failures: list<string>}
     */
    public static function generate(
        string $formCode,
        string $registration,
        string $periodCode,
        string $head,
        array $stored,
        ?Money $declaredTotal = null,
    ): array {
        // Step 2: the population is the stored rows for THIS registration and
        // period, filtered here and nowhere else.
        $rows = array_values(array_filter(
            $stored,
            static fn (ContributionRow $r): bool => $r->applicable
                && $r->head === $head
                && $r->applicability->establishmentCode === $registration,
        ));

        $failures = [];

        if ($rows === []) {
            $failures[] = "no stored contribution rows exist for {$registration} in {$periodCode}. An empty "
                . 'return is a filing that says nobody was paid, which is a statement — not a blank';
        }

        $total = Money::zero();

        foreach ($rows as $row) {
            $total = $total->plus($row->amount);

            // Step 5, per row: every figure must still carry its snapshot, or
            // the file cannot be defended against the payslips it summarises.
            if (!$row->isComplete()) {
                $failures[] = "{$row->employmentId}: the stored figure carries an incomplete snapshot, so "
                    . 'what was filed could not be re-derived from what was paid';
            }

            if ($row->synthetic) {
                $failures[] = "{$row->employmentId}: the figure came from a SYNTHETIC pack. A synthetic "
                    . 'value must never reach a statutory filing — it states no law and cites no authority';
            }
        }

        if ($declaredTotal !== null && !$declaredTotal->equals($total)) {
            $failures[] = sprintf(
                'the control total is %s and the rows sum to %s, a difference of %s. To the paisa: a return '
                . 'that reconciles "approximately" is a return whose difference is somebody else\'s finding',
                $declaredTotal->format(),
                $total->format(),
                Money::ofMinor($total->minor - $declaredTotal->minor)->format(),
            );
        }

        if ($failures !== []) {
            // Step 6. No file, and the obligation goes to prepared_failed naming
            // the assertion — not a file with a warning attached to it.
            return ['ok' => false, 'file' => null, 'hash' => null, 'rows' => count($rows),
                'failures' => $failures];
        }

        // Steps 3, 4 and 7: bind to the form's columns, then hash and store.
        $lines = ["# {$formCode} · {$registration} · {$periodCode} · {$head}"];

        foreach ($rows as $row) {
            $lines[] = implode('|', [
                $row->employmentId,
                $row->wageBase->toDecimalString(),
                $row->amount->toDecimalString(),
                $row->packKey . '@' . $row->packVersion,
                $row->ruleCode,
            ]);
        }

        $lines[] = '# total ' . $total->toDecimalString() . ' over ' . count($rows) . ' employment(s)';
        $file = implode("\n", $lines);

        // R-XVII-132: stored with its hash, so "what exactly did we file?" is
        // answerable years later and a re-generation that differs is detectable.
        return ['ok' => true, 'file' => $file, 'hash' => hash('sha256', $file), 'rows' => count($rows),
            'failures' => []];
    }
}
