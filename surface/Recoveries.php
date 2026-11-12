<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * LOANS AND ADVANCES, RECOVERED ON THE PAYSLIP — Part XVIII.
 *
 * `loans` and `advances` have existed since the port as two generic CRUD screens
 * and two "outstanding" reports. No instalment has ever reached a payslip, which
 * means a customer running loans is told the recovery is manual: somebody types a
 * deduction onto a payslip from a spreadsheet every month, and the month they
 * mistype it the employee is the one who has to notice.
 *
 * THE STORED `outstanding` / `balance` COLUMN IS AN OPENING FIGURE, NOT THE
 * TRUTH, AND NOTHING HERE WRITES TO IT. `Platform\Payroll\Recovery` exists
 * precisely to say why: the outstanding is a SUM OF ENTRIES, never a stored
 * figure decremented in place, because the fifth Phase 5 exit criterion is that
 * every recovery reverses cleanly. A column decremented from a controller — which
 * is what SmartPRS 2 does — leaves a reversed instalment as a balance that is
 * right by luck and a history saying the deduction never happened.
 *
 * So WHAT HAS ACTUALLY BEEN RECOVERED IS DERIVED FROM THE CLOSED RUNS. They
 * already store every line of every month, they are append-only, and they are
 * never recomputed — they are the ledger this class needed and did not have to
 * invent. The outstanding is the opening figure minus what the runs show
 * recovered, and a reversed run is not counted, which is the whole of "reverses
 * cleanly" for this path.
 *
 * WHAT THAT CANNOT SEE: an instalment recovered before this build ran — in
 * SmartPRS 2, or by hand onto a spreadsheet — is in no closed run here and is
 * invisible to this sum. That is exactly why the ported column is read as the
 * OPENING figure rather than ignored: whoever migrates sets `outstanding` /
 * `balance` to what was still owed at go-live, and every instalment after that is
 * a line in a run. `installments_paid` is read for the same reason and for
 * nothing else — see `openingInstalments()`.
 *
 * ponytail: the ported `outstanding`, `balance` and `installments_paid` columns
 * are left EXACTLY as the migration wrote them and are never updated by payroll.
 * They are the opening position; the runs are the movement. Upgrade path if a
 * screen ever needs the current balance without walking the runs itself: a
 * derived read-through method here, never a write back to the column — the moment
 * two places hold the balance, one of them is wrong and neither says so.
 */
final class Recoveries
{
    /** The component code an instalment appears under, so the runs can be read back. */
    public const LOAN = 'LOAN-';

    public const ADVANCE = 'ADV-';

    /** Statuses that recover this month. `active` is what the ported rows carry. */
    private const RECOVERING = ['active', 'disbursed', 'recovering'];

    /** Approved but not yet money owed through payroll. Silent. */
    private const NOT_YET = ['pending', 'requested', 'approved', 'rejected', 'declined'];

    /** Finished obligations. Silent — a settled loan is not a finding. */
    private const FINISHED = ['closed', 'recovered', 'settled', 'written off', 'cancelled'];

    /** @var array<string, array<string, array<string, array{minor: int, count: int}>>> */
    private array $ledger = [];

    /** Amounts recorded on Loans / Salary Advance Recovery (22 Sep). */
    private readonly RecoveryEntries $entries;

    public function __construct(private readonly RowStore $store, ?RecoveryEntries $entries = null)
    {
        $this->entries = $entries ?? RecoveryEntries::over($store);
    }

    /**
     * WHAT IS STILL OWED on one advance or loan, in paise, as of now: the opening
     * figure less every closed (not reversed, not deleted) run's instalments and
     * every amount collected outside payroll. Derived, never stored — see the
     * class docblock for why no column is decremented.
     *
     * @param array<string, mixed> $row the advances / loans row
     * @param array<string, mixed> $employment its employee
     */
    public function outstandingMinor(string $kind, array $row, array $employment): int
    {
        $o = self::obligation($kind, $row);

        try {
            $opening = trim((string) $o['opening']) === '' ? 0 : Amount::of($o['opening'])->minor;
        } catch (\InvalidArgumentException) {
            // An unreadable opening figure: nothing can be said to be owed here.
            // Payroll states the same row as a concern on the run (see due()).
            return 0;
        }

        $taken = $this->recoveredBefore(Employment::code($employment), '9999-99')[$o['code']]['minor'] ?? 0;

        return max(0, $opening - $taken - $this->entries->collectedThrough((string) $o['code'], '9999-99'));
    }

    /**
     * WHAT MAY STILL BE RECORDED against it: the outstanding less amounts already
     * booked against payroll months not generated yet (a generated month's entry
     * is already inside its run, or will be the moment it is recomputed).
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $employment
     */
    public function availableMinor(string $kind, array $row, array $employment): int
    {
        $o = self::obligation($kind, $row);
        $ran = $this->ranPeriods();
        $booked = 0;

        foreach ($this->entries->activeFor((string) $o['code']) as $e) {
            if ((string) ($e['mode'] ?? '') === RecoveryEntries::PAYROLL && !isset($ran[(string) ($e['period'] ?? '')])) {
                $booked += Amount::of((string) $e['amount'])->minor;
            }
        }

        return max(0, $this->outstandingMinor($kind, $row, $employment) - $booked);
    }

    /**
     * WHERE ONE EMPLOYEE STANDS on every loan and advance they hold, added up:
     * what was taken, what has come back, and what is still owed.
     *
     * Asked of this class rather than worked out by the caller, because the
     * caller would have to know three things this class already knows and keeps
     * consistent: which column holds a loan's opening figure and which holds an
     * advance's, that a reversed run gave its instalment back, and that money
     * collected outside payroll counts too. A report that re-derived any of
     * those would be a second opinion on a balance, and the month it disagreed
     * with the payslip is the month somebody is told they still owe money they
     * have already repaid.
     *
     * RECOVERED IS OPENING LESS OUTSTANDING, never a sum of instalments: an
     * instalment inside a reversed run was refunded, and `outstandingMinor()`
     * already excludes it. Taking the difference means the three figures always
     * add up, whatever the ledger turns out to contain.
     *
     * @param array<string, mixed> $employment the `employees` row
     * @return array{taken_minor: int, recovered_minor: int, balance_minor: int, count: int}
     */
    public function positionFor(array $employment): array
    {
        $employeeId = trim((string) ($employment['id'] ?? ''));
        $out = ['taken_minor' => 0, 'recovered_minor' => 0, 'balance_minor' => 0, 'count' => 0];

        if ($employeeId === '') {
            return $out;
        }

        foreach (['loan' => 'loans', 'advance' => 'advances'] as $kind => $table) {
            foreach ($this->store->all($table) as $row) {
                if (trim((string) ($row['employee_id'] ?? '')) !== $employeeId) {
                    continue;
                }

                $opening = trim((string) self::obligation($kind, $row)['opening']);

                try {
                    $taken = $opening === '' ? 0 : Amount::of($opening)->minor;
                } catch (\InvalidArgumentException) {
                    // An unreadable opening figure says nothing about what is
                    // owed — the same silence outstandingMinor() keeps, and the
                    // run states it as a concern on the payslip either way.
                    continue;
                }

                $balance = $this->outstandingMinor($kind, $row, $employment);
                $out['taken_minor'] += $taken;
                $out['balance_minor'] += $balance;
                $out['recovered_minor'] += max(0, $taken - $balance);
                $out['count']++;
            }
        }

        return $out;
    }

    /** @return array<string, true> the months that have a stored, live run */
    private function ranPeriods(): array
    {
        $out = [];

        foreach ($this->store->all('payroll_runs') as $run) {
            if ((string) ($run['status'] ?? '') !== Register::DISCARDED) {
                $out[(string) ($run['period'] ?? $run['cycle_label'] ?? '')] = true;
            }
        }

        return $out;
    }

    /**
     * The recovery deduction lines due for one employee in one period.
     *
     * Returns lines in the shape the run already stores every other line in, and
     * CONCERNS in the shape the statutory step already returns — anything that
     * cannot be computed is stated on the run row and reaches `Payslip::from()`,
     * never a silent skip and never a zero.
     *
     * @param array<string, mixed> $employment the `employees` row the run is paying
     * @return array{lines: list<array{code: string, kind: string, amount: string, minor: int,
     *     trace: string}>, concerns: list<string>}
     */
    public function forEmployment(array $employment, string $period): array
    {
        $employeeId = trim((string) ($employment['id'] ?? ''));
        $code = Employment::code($employment);

        if ($employeeId === '' || $code === '') {
            return ['lines' => [], 'concerns' => []];
        }

        $taken = $this->recoveredBefore($code, $period);
        $lines = [];
        $concerns = [];

        foreach ($this->obligationsOf($employeeId) as $obligation) {
            // NOT BEFORE THE START MONTH. A loan's `start_month` / an advance's
            // `emi_start` (YYYY-MM) says when recovery begins; a period before it
            // takes nothing and says nothing — the money is owed, just not yet due.
            // AN AMOUNT RECORDED ON LOANS / SALARY ADVANCE RECOVERY for this
            // month is this month's deduction, in place of the EMI — whatever the
            // start month says, and whichever recovery option the row uses.
            $entered = $this->entries->payrollMinor((string) $obligation['code'], $period);
            $starts = (string) ($obligation['starts'] ?? '');

            // OPTION 2, MANUAL: no EMI. A month nobody recorded an amount for takes
            // nothing, and that is not a finding — it is what manual means.
            if ($entered === null && ($obligation['manual'] || ($starts !== '' && $period < $starts))) {
                continue;
            }

            $sofar = $taken[$obligation['code']] ?? ['minor' => 0, 'count' => 0];
            // Cash collected outside payroll up to this month is recovered too.
            $collected = $this->entries->collectedThrough((string) $obligation['code'], $period);
            $sofar['minor'] += $collected;
            $obligation['collected'] = $collected;
            $due = $this->due($obligation, $sofar, $entered);

            if ($due['concern'] !== null) {
                $concerns[] = $due['concern'];

                continue;
            }

            if ($due['line'] !== null) {
                $lines[] = $due['line'];
            }
        }

        return ['lines' => $lines, 'concerns' => $concerns];
    }

    /**
     * The instalment due on one obligation, or the reason there isn't one.
     *
     * @param array<string, mixed> $obligation
     * @param array{minor: int, count: int} $taken what the closed runs show recovered
     * @return array{line: ?array<string, mixed>, concern: ?string}
     */
    private function due(array $obligation, array $taken, ?int $entered = null): array
    {
        $noun = (string) $obligation['noun'];
        $label = (string) $obligation['label'];
        $named = $noun . ' #' . (string) $obligation['id'];
        $status = strtolower(trim(str_replace('_', ' ', (string) $obligation['status'])));

        if (in_array($status, self::FINISHED, true) || in_array($status, self::NOT_YET, true)) {
            // SILENTLY NOTHING. A settled loan is a finished obligation, not a
            // finding, and an approved-but-not-disbursed one is not yet money the
            // employee owes. Raising a concern for either would put a paragraph on
            // every payslip of everyone who has ever had a loan.
            return ['line' => null, 'concern' => null];
        }

        if (!in_array($status, self::RECOVERING, true)) {
            return ['line' => null, 'concern' => sprintf(
                "%s has the status '%s', which this build does not understand, so NOTHING was deducted "
                . 'for it this period. The statuses that recover are %s; the ones that are finished are '
                . '%s. Until the status is one of those, whether this %s is being recovered is a '
                . 'question nobody has answered — which is not the same as an instalment of zero.',
                ucfirst($named), (string) $obligation['status'], self::listOf(self::RECOVERING),
                self::listOf(self::FINISHED), $noun,
            )];
        }

        try {
            // An EMPTY column is not a zero balance. `Amount::of('')` is zero by
            // design — a blank form field is nothing owed — but a live loan with no
            // figure at all is a row nobody finished entering, and settling it
            // silently is how it never gets recovered.
            $opening = trim((string) $obligation['opening']) === ''
                ? throw new \InvalidArgumentException('no figure recorded')
                : Amount::of($obligation['opening']);
        } catch (\InvalidArgumentException $e) {
            return ['line' => null, 'concern' => sprintf(
                '%s carries an opening balance this build cannot read as money (%s), so no instalment '
                . 'was computed and NOTHING was deducted for it this period. A recovery is not written '
                . 'from a figure that cannot be parsed exactly: a zero here looks exactly like a settled '
                . '%s on the payslip.',
                ucfirst($named), var_export((string) $obligation['opening'], true), $noun,
            )];
        }

        if ($opening->isNegative()) {
            return ['line' => null, 'concern' => sprintf(
                '%s has a NEGATIVE opening balance of %s. A negative outstanding is money owed TO the '
                . 'employee, which is an earning and not a recovery, so nothing was deducted. Making it '
                . 'a deduction would pay it out under a heading that says the opposite.',
                ucfirst($named), $opening->format(),
            )];
        }

        if ($opening->isZero()) {
            return ['line' => null, 'concern' => null];
        }

        if ($entered !== null) {
            return $this->dueAsEntered($obligation, $taken, $entered, $opening, $named, $noun);
        }

        try {
            $instalment = Amount::of($obligation['instalment']);
        } catch (\InvalidArgumentException $e) {
            $instalment = null;
        }

        if ($instalment === null || $instalment->isZero() || $instalment->isNegative()) {
            return ['line' => null, 'concern' => sprintf(
                '%s shows %s still outstanding and no %s to recover it by (%s), so NOTHING was deducted '
                . 'for it this period and the outstanding is unchanged. That is stated rather than '
                . 'skipped: a %s that quietly recovers nothing is one nobody chases until somebody '
                . 'writes it off. Set the %s on the %s and close the month again.',
                ucfirst($named), $opening->format(), $label,
                var_export((string) $obligation['instalment'], true), $noun, $label, $noun,
            )];
        }

        $outstanding = $opening->minus(Money::ofMinor($taken['minor']));

        if ($outstanding->isNegative()) {
            return ['line' => null, 'concern' => sprintf(
                '%s has already been OVER-RECOVERED: the closed runs show %s taken against an opening '
                . 'balance of %s, which is %s more than was ever owed. No further instalment was taken. '
                . 'The excess is money the employer holds with no instrument behind it and is refunded '
                . 'as an earning line, not by taking less next month.',
                ucfirst($named), Money::ofMinor($taken['minor'])->format(), $opening->format(),
                $outstanding->absolute()->format(),
            )];
        }

        if ($outstanding->isZero()) {
            // A FULLY RECOVERED OBLIGATION PRODUCES NOTHING, SILENTLY, even if
            // nobody has got round to setting its status to closed.
            return ['line' => null, 'concern' => null];
        }

        // NEVER MORE THAN IS OWED. The last instalment is the remainder, not the
        // standing amount: twelve instalments of ₹4,000 against a ₹47,500 balance
        // take ₹500 the employee never borrowed, and the payslip that does it
        // explains itself perfectly while doing it.
        $short = $outstanding->compareTo($instalment) < 0;
        $due = $short ? $outstanding : $instalment;
        $after = $outstanding->minus($due);
        $number = (int) $obligation['opening_instalments'] + $taken['count'] + 1;
        // Of HOW MANY: what is left, at this instalment, rounded up — the count the
        // figures on this payslip actually imply, not the declared `tenure`, which
        // is a number somebody typed at sanction and can disagree with the money.
        $total = $number - 1 + intdiv($outstanding->minor + $instalment->minor - 1, $instalment->minor);

        $trace = sprintf(
            'instalment %d of %d against %s, %s of %s, %s outstanding after this one',
            $number, $total, $named, $due->format(), $opening->format(), $after->format(),
        );

        if ((int) ($obligation['collected'] ?? 0) > 0) {
            $trace .= sprintf(' (%s of it collected outside payroll)', Money::ofMinor((int) $obligation['collected'])->format());
        }

        if ($short) {
            $trace .= sprintf(
                ' — the last instalment is the BALANCE, not the standing %s of %s: recovering the '
                . 'standing amount would take %s more than is owed',
                $label, $instalment->format(), $instalment->minus($outstanding)->format(),
            );
        }

        return ['concern' => null, 'line' => [
            'code' => (string) $obligation['code'],
            'kind' => 'deduction',
            'amount' => $due->format(),
            'minor' => $due->minor,
            'trace' => $trace,
        ]];
    }

    /**
     * THIS MONTH'S DEDUCTION AS RECORDED on Loans / Salary Advance Recovery —
     * the typed amount, never more than is still owed.
     *
     * @param array<string, mixed> $obligation
     * @param array{minor: int, count: int} $taken
     * @return array{line: ?array<string, mixed>, concern: ?string}
     */
    private function dueAsEntered(array $obligation, array $taken, int $entered, Money $opening, string $named, string $noun): array
    {
        $outstanding = $opening->minus(Money::ofMinor($taken['minor']));
        $asked = Money::ofMinor($entered);

        if ($outstanding->isZero() || $outstanding->isNegative()) {
            return ['line' => null, 'concern' => sprintf(
                '%s has %s recorded against this month\'s payroll on Loans / Salary Advance Recovery, but '
                . 'nothing is still owed on it (%s opening, %s already recovered), so NOTHING was deducted. '
                . 'Cancel the entry on that screen.',
                ucfirst($named), $asked->format(), $opening->format(), Money::ofMinor($taken['minor'])->format(),
            )];
        }

        $short = $outstanding->compareTo($asked) < 0;
        $due = $short ? $outstanding : $asked;
        $after = $outstanding->minus($due);
        $trace = sprintf(
            '%s recorded on Loans / Salary Advance Recovery against %s for this month (opening %s), %s outstanding after this one',
            $due->format(), $named, $opening->format(), $after->format(),
        );

        if ($short) {
            $trace .= sprintf(' — %s was recorded, but only %s was still owed, so only that was taken', $asked->format(), $outstanding->format());
        }

        if ((int) ($obligation['collected'] ?? 0) > 0) {
            $trace .= sprintf(' (%s collected outside payroll counted)', Money::ofMinor((int) $obligation['collected'])->format());
        }

        return ['concern' => null, 'line' => [
            'code' => (string) $obligation['code'],
            'kind' => 'deduction',
            'amount' => $due->format(),
            'minor' => $due->minor,
            'trace' => $trace,
        ]];
    }

    /**
     * One advances / loans row in the shape `due()` reads.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function obligation(string $kind, array $row): array
    {
        $manual = strtolower(trim((string) ($row['recovery_mode'] ?? ''))) === 'manual';

        if ($kind === 'loan') {
            return [
                'code' => self::LOAN . (string) $row['id'],
                'id' => (string) $row['id'],
                'noun' => 'loan',
                'label' => 'EMI',
                // The stored outstanding is the OPENING figure. `amount` stands in
                // when it was never set, which is the row of a loan sanctioned and
                // disbursed in this build: at disbursement the two are the same
                // number, and defaulting to zero would silently settle it.
                'opening' => self::figure($row, ['outstanding', 'amount']),
                'instalment' => (string) ($row['emi'] ?? ''),
                'opening_instalments' => self::openingInstalments($row),
                'status' => (string) ($row['status'] ?? ''),
                'starts' => substr((string) ($row['start_month'] ?? ''), 0, 7),
                'manual' => $manual,
            ];
        }

        return [
            'code' => self::ADVANCE . (string) $row['id'],
            'id' => (string) $row['id'],
            'noun' => 'advance',
            'label' => 'monthly recovery',
            'opening' => self::figure($row, ['balance', 'amount']),
            'instalment' => (string) ($row['per_month'] ?? ''),
            'opening_instalments' => 0,
            'status' => (string) ($row['status'] ?? ''),
            'starts' => substr((string) ($row['emi_start'] ?? ''), 0, 7),
            'manual' => $manual,
        ];
    }

    /**
     * The loans and advances of one employee, in one shape.
     *
     * @return list<array<string, mixed>>
     */
    private function obligationsOf(string $employeeId): array
    {
        $obligations = [];

        foreach (['loan' => 'loans', 'advance' => 'advances'] as $kind => $table) {
            foreach ($this->store->all($table) as $row) {
                if (trim((string) ($row['employee_id'] ?? '')) === $employeeId) {
                    $obligations[] = self::obligation($kind, $row);
                }
            }
        }

        return $obligations;
    }

    /**
     * How many instalments were taken BEFORE this build was recovering anything.
     *
     * The only thing `installments_paid` is read for, and it is read for counting
     * and never for money: it makes "instalment 6 of 12" say 6 on the first month
     * SmartPRS 3 recovers a loan that SmartPRS 2 had already taken five off. The
     * AMOUNT still comes from the opening balance, so a wrong count here misprints
     * a sentence and cannot misprice a deduction.
     *
     * @param array<string, mixed> $loan
     */
    private static function openingInstalments(array $loan): int
    {
        $paid = (int) ($loan['installments_paid'] ?? 0);

        return $paid > 0 ? $paid : 0;
    }

    /**
     * The first of these columns that actually holds a figure, as stored.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private static function figure(array $row, array $columns): string
    {
        foreach ($columns as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                return (string) $row[$column];
            }
        }

        return '';
    }

    /**
     * WHAT THE CLOSED RUNS SHOW RECOVERED for this employee before this period,
     * by obligation code: the amount in paise and how many months took one.
     *
     * A REVERSED RUN IS NOT COUNTED. `Register::reverse()` records a correction
     * with `new_fingerprint = 'reversal'` against a month whose payment was taken
     * back whole; an instalment inside it was given back too, so counting it would
     * leave the employee having repaid a month they were refunded.
     *
     * @return array<string, array{minor: int, count: int}>
     */
    private function recoveredBefore(string $employmentCode, string $period): array
    {
        $this->ledger[$period] ??= $this->build($period);

        return $this->ledger[$period][$employmentCode] ?? [];
    }

    /** @return array<string, array<string, array{minor: int, count: int}>> */
    private function build(string $period): array
    {
        $reversed = [];

        foreach ($this->store->all('payroll_corrections') as $correction) {
            if ((string) ($correction['new_fingerprint'] ?? '') === Register::REVERSED) {
                $reversed[(string) ($correction['period'] ?? '')] = true;
            }
        }

        $ledger = [];

        foreach ($this->store->all('payroll_runs') as $run) {
            // ponytail: the two ported column names are read here rather than
            // through `Register::ported()`, which is private. Two field names, not
            // a second reading of the run — make `ported()` public the day a third
            // caller needs it.
            $ran = (string) ($run['period'] ?? $run['cycle_label'] ?? '');

            // A payroll DELETED while testing (Register::discard) paid nobody and
            // recovered nothing; counting its lines would settle money never taken.
            if ($ran === '' || $ran >= $period || isset($reversed[$ran])
                || (string) ($run['status'] ?? '') === Register::DISCARDED) {
                continue;
            }

            foreach (self::rowsOf($run) as $employmentCode => $row) {
                foreach ((array) ($row['lines'] ?? []) as $line) {
                    $code = (string) ($line['code'] ?? '');

                    if (!str_starts_with($code, self::LOAN) && !str_starts_with($code, self::ADVANCE)) {
                        continue;
                    }

                    $entry = $ledger[$employmentCode][$code] ?? ['minor' => 0, 'count' => 0];
                    $entry['minor'] += (int) ($line['minor'] ?? 0);
                    $entry['count']++;
                    $ledger[(string) $employmentCode][$code] = $entry;
                }
            }
        }

        return $ledger;
    }

    /**
     * A run's per-employment rows. MySQL hands the JSON column back as text; the
     * file store hands back the array it was given, and both must read the same.
     *
     * @param array<string, mixed> $run
     * @return array<string, array<string, mixed>>
     */
    private static function rowsOf(array $run): array
    {
        $rows = $run['rows'] ?? $run['run_rows'] ?? [];

        if (is_string($rows)) {
            $rows = $rows === '' ? [] : (array) json_decode($rows, true);
        }

        return array_map(static fn (mixed $row): array => (array) $row, (array) $rows);
    }

    /** @param list<string> $words */
    private static function listOf(array $words): string
    {
        $quoted = array_map(static fn (string $word): string => "'" . $word . "'", $words);
        $last = array_pop($quoted);

        return implode(', ', $quoted) . ' and ' . $last;
    }
}
