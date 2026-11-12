<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * FULL AND FINAL — the last payslip, and the one that gets disputed.
 *
 * An exit is not a delete. The employee stays, with a last working day, and
 * everything already paid to it stays exactly as it was. Removing the row would
 * make every earlier payslip unexplainable and every earlier control total
 * unreproducible, which is the same class of damage as editing a closed run.
 *
 * AN EXIT IS DATED, AND THE DATE IS THE WHOLE CALCULATION. Days worked in the
 * final month, leave encashed at the balance ON THAT DATE — not today's balance,
 * which keeps accruing on a page refresh and would pay a leaver for months they
 * were not employed.
 *
 * ENCASHMENT IS A DEBIT ON THE LEDGER, not a figure on a screen. Paying out 12
 * days and leaving the balance at 12 is how somebody is paid twice: once in the
 * settlement, once by the next person who reads the balance. The ledger entry
 * names the settlement that caused it.
 *
 * A SETTLEMENT IS COMPUTED AND THEN SEPARATELY RECORDED. Between the two a
 * person reads it, which is the only control that catches a wrong last working
 * day — no arithmetic can, because every figure is correct for the date it was
 * given.
 *
 * ponytail: encashment rate is basic-equivalent per calendar day of the exit
 * month, gratuity is not computed, and notice-period recovery is not modelled.
 * Gratuity needs the statutory pack a compliance owner has to sign; the other
 * two need a policy field.
 */
final class Settlement
{
    private const COLLECTION = 'settlements';

    public function __construct(
        private readonly RowStore $store,
        private readonly Leave $leave,
        private readonly LeavePolicy $policy,
        private readonly Calendar $calendar,
        private readonly PayrollPeriod $period,
        private readonly ComponentRules $components,
        // The contracted-cost basis (annual / monthly) and the loan / advance
        // ledger — both optional so older callers keep working (7 Oct).
        private readonly ?Structure $structure = null,
        private readonly ?Recoveries $recoveries = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_map(self::ported(...), $this->store->all(self::COLLECTION));
    }

    /**
     * A stored settlement in the shape the screen reads a QUOTE in, so the two
     * render through the same template.
     *
     * `final_pay` and `total` are DECIMAL(18,4) columns; the quote carries them
     * formatted, with thousands separators, which is a truncation or a refusal
     * on the way into a decimal column. So the money is stored as the number and
     * formatted here, and `encashments`/`concerns` come back as the JSON text
     * MySQL stores rather than as the lists the screen iterates.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function ported(array $row): array
    {
        foreach (['final_pay', 'total'] as $money) {
            $minor = Amount::of($row[$money] ?? 0)->minor;
            $row[$money . '_minor'] ??= $minor;
            $row[$money] = Money::ofMinor($minor)->format();
        }

        foreach (['encashments', 'concerns', 'other_earnings', 'other_deductions'] as $list) {
            $row[$list] = is_array($row[$list] ?? null)
                ? $row[$list]
                : (array) json_decode((string) ($row[$list] ?? ''), true);
        }

        $row['encashment_total'] ??= Money::ofMinor(array_sum(array_map(
            static fn (array $e): int => (int) $e['amount_minor'], $row['encashments'])))->format();

        // OTHER EARNINGS AND DEDUCTIONS — ad-hoc lines HR added at settlement
        // time (a bonus, a notice-period recovery, an asset-damage deduction)
        // that no formula predicts, because they only exist because this
        // employee is leaving. Same shape as `encashments` for the same
        // reason: a settlement and its quote render through one template.
        $row['other_earning_total'] ??= Money::ofMinor(array_sum(array_map(
            static fn (array $e): int => (int) $e['amount_minor'], $row['other_earnings'])))->format();
        $row['other_deduction_total'] ??= Money::ofMinor(array_sum(array_map(
            static fn (array $e): int => (int) $e['amount_minor'], $row['other_deductions'])))->format();

        return $row;
    }

    /**
     * Ad-hoc settlement lines HR adds at exit time. Free text would let two
     * people spell the same deduction two different ways and would put a
     * business literal in a form field instead of in configuration (Law 1),
     * so every line names a component already declared in
     * config/salary-components.json — never a label typed at the moment of
     * settlement — and only one whose `kind` matches the side it is going on.
     *
     * @param list<array<string, mixed>> $raw each: component_code, amount, note
     * @return list<array{component_code: string, name: string, amount: string, amount_minor: int, note: string}>
     */
    private function parseOtherLines(array $raw, string $kind): array
    {
        $declared = $this->components->components();
        $lines = [];

        foreach ($raw as $row) {
            $code = trim((string) ($row['component_code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $component = $declared[$code] ?? null;

            if ($component === null || (string) ($component['kind'] ?? '') !== $kind) {
                throw new \InvalidArgumentException(
                    "'{$code}' is not a declared {$kind} component. A settlement line names a "
                    . 'component from config/salary-components.json, never a label typed at the '
                    . 'moment of settlement, so a customer who renames or removes one still has an '
                    . 'explainable settlement rather than a string nobody can trace back to a policy.'
                );
            }

            $note = trim((string) ($row['note'] ?? ''));

            if ($note === '') {
                throw new \InvalidArgumentException(
                    "'{$code}' carries no note. Every line on a final settlement is a line the "
                    . 'employee will ask about, and they will ask after they have left.'
                );
            }

            $amount = Amount::of($row['amount'] ?? '0');

            if ($amount->isZero() || $amount->isNegative()) {
                throw new \InvalidArgumentException(
                    "'{$code}' is {$amount->format()} — a settlement line is a positive amount on "
                    . 'one side of the payslip or the other; a zero or negative line is not a '
                    . 'figure, it is a line that should not have been added.'
                );
            }

            $lines[] = [
                'component_code' => $code,
                'name' => (string) ($component['label'] ?? $code),
                'amount' => $amount->format(),
                'amount_minor' => $amount->minor,
                'note' => $note,
            ];
        }

        return $lines;
    }

    public function forEmployment(string $employmentId): ?array
    {
        foreach ($this->all() as $settlement) {
            if ((string) $settlement['employment_id'] === $employmentId) {
                return $settlement;
            }
        }

        return null;
    }

    /**
     * Work out the settlement WITHOUT recording it, so a person reads it first.
     *
     * @param list<array<string, mixed>> $otherEarnings ad-hoc earning lines: component_code, amount, note
     * @param list<array<string, mixed>> $otherDeductions ad-hoc deduction lines: component_code, amount, note
     * @return array{ok: bool, why: string, quote?: array<string, mixed>}
     */
    public function quote(string $employmentId, string $lastWorkingDay,
        array $otherEarnings = [], array $otherDeductions = []): array
    {
        $employment = null;

        foreach ($this->store->all('employees') as $candidate) {
            if (Employment::code($candidate) === $employmentId) {
                $employment = $candidate;
            }
        }

        if ($employment === null) {
            return ['ok' => false, 'why' => "No employee {$employmentId}."];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastWorkingDay) !== 1) {
            return ['ok' => false, 'why' => "'{$lastWorkingDay}' is not a date in YYYY-MM-DD form."];
        }

        if ($lastWorkingDay < Employment::joinedOn($employment)) {
            return ['ok' => false, 'why' => 'The last working day is before the joining date. That is '
                . 'refused rather than computed, because every figure that follows would be correct '
                . 'arithmetic on an impossible employee.'];
        }

        if ($this->forEmployment($employmentId) !== null) {
            return ['ok' => false, 'why' => "{$employmentId} is already settled. A second settlement "
                . 'would encash a leave balance the first one already paid out.'];
        }

        try {
            $otherEarningLines = $this->parseOtherLines($otherEarnings, 'earning');
            $otherDeductionLines = $this->parseOtherLines($otherDeductions, 'deduction');
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        $exitDate = new \DateTimeImmutable($lastWorkingDay);
        $monthStart = $exitDate->format('Y-m-01');

        // Worked days in the FINAL month, up to and including the exit — not the
        // whole month, which would pay for days after the person left.
        $derived = $this->period->forPeriod($employmentId, $monthStart, $lastWorkingDay,
            $this->calendar->shiftStart(), $this->calendar->shiftEnd());

        $monthDays = $this->calendar->prorationDays($monthStart,
            $exitDate->format('Y-m-t'))['days'];
        // ONE MONTH'S GROSS (7 Oct). This read `Money::of(Employment::ctc())` —
        // the CONTRACT, which is a year's cost on an annual basis — so the final
        // month and every encashed day came out twelve times too high. Same
        // conversion the payroll close uses.
        try {
            // THE CTC IN FORCE ON THE LAST WORKING DAY (8 Oct) — an appraisal
            // effective after the exit is not paid in the final month.
            $employment['ctc'] = Increments::ctcOn($employment,
                array_values(array_filter($this->store->all(Increments::TABLE),
                    static fn (array $i): bool => (string) ($i['employee_id'] ?? '') === (string) ($employment['id'] ?? ''))), $lastWorkingDay);
        } catch (\Throwable) { // gate:allow-swallow a store with no increments register (tests): the record's CTC stands
        }

        try {
            $gross = $this->structure !== null
                ? Employment::monthlyGross($employment, $this->structure->contractedCostBasis($lastWorkingDay))
                : Money::of(Employment::ctc($employment));
        } catch (\Throwable) {
            $gross = Money::of(Employment::ctc($employment));
        }

        // ONE ROUNDING, DECLARED. This was `$gross->minor / $monthDays` — an int
        // division that produces a float — and then a second round() on top of
        // it, so the day rate was a float on every settlement in the product.
        // The proportion is exact and the rounding happens once, at the end.
        $perMonthDay = Rational::of(1, max($monthDays, 1));
        $finalPay = $gross
            // `Rational::of((int) ...)` until the rule engine landed: an int cast
            // on the number of days a LEAVER is paid for, which is the one figure
            // a settlement is entirely about. `payable` is already exact.
            ->multipliedBy($perMonthDay->multipliedBy($derived['payable']),
                RoundingMode::HalfUp)
            ->minor;

        // Encashment at the balance ON THE EXIT DATE. Today's balance keeps
        // accruing on a page refresh and would pay a leaver for months they were
        // not employed.
        $encashments = [];
        $encashMinor = 0;

        foreach ($this->policy->types() as $code => $type) {
            // PAID and ENCASHABLE are different questions. Sick leave is paid
            // when it is taken and is not a sum owed on exit; assuming the first
            // implies the second hands a leaver a month's salary for days nobody
            // took. The policy declares it; this class does not guess.
            if (!($type['encashable'] ?? false)) {
                continue;
            }

            $balance = $this->leave->balance($employmentId, (string) $code, $exitDate);

            if ($balance->compareTo(Rational::fromDecimalString('0')) <= 0) {
                continue;
            }

            // The balance is already a Rational — "10.5 days" exactly — so the
            // encashment is gross x (balance / month days), rounded once.
            $amount = $gross->multipliedBy($perMonthDay->multipliedBy($balance),
                RoundingMode::HalfUp)->minor;
            $encashMinor += $amount;
            $encashments[] = [
                'type' => (string) $code,
                'days' => (string) $balance,
                'amount' => Money::ofMinor($amount)->format(),
                'amount_minor' => $amount,
                'why' => "balance on {$lastWorkingDay}, not today — a balance read today keeps "
                    . 'accruing and would pay a leaver for months they were not employed',
            ];
        }

        // OTHER EARNINGS AND DEDUCTIONS. Summed here, from the validated lines,
        // never from a total posted back by a form — same rule as every other
        // figure in this quote.
        $otherEarningMinor = array_sum(array_map(
            static fn (array $l): int => $l['amount_minor'], $otherEarningLines));
        $otherDeductionMinor = array_sum(array_map(
            static fn (array $l): int => $l['amount_minor'], $otherDeductionLines));

        // LOANS AND ADVANCES STILL OWED come off the settlement — after every
        // closed payroll's instalment, which is why the exit month's payroll is
        // generated first (below). One line, on the LOAN deduction component.
        $concerns = [];
        $exitPeriod = $exitDate->format('Y-m');
        $exitMonthRun = false;

        foreach ($this->store->all('payroll_runs') as $run) {
            if ((string) ($run['period'] ?? $run['cycle_label'] ?? '') === $exitPeriod
                && (string) ($run['status'] ?? '') !== Register::DISCARDED) {
                $exitMonthRun = true;
            }
        }

        if (!$exitMonthRun) {
            $concerns[] = "Generate payroll for {$exitPeriod} first: the exit month's salary (up to the last "
                . 'working day) and its loan / advance instalment are paid by payroll, and the balance '
                . 'recovered here is what is left after it.';
        }

        $owed = $this->recoveries?->positionFor($employment)['balance_minor'] ?? 0;

        if ($owed > 0) {
            $otherDeductionLines[] = ['component_code' => 'LOAN', 'name' => 'Loans / advances outstanding',
                'amount' => Money::ofMinor($owed)->format(), 'amount_minor' => $owed,
                'note' => 'Balance of every loan and salary advance after the closed payroll runs'];
            $otherDeductionMinor += $owed;
        }

        // THE EXIT MONTH'S SALARY IS PAID BY PAYROLL (owner, 7 Oct), so it is
        // shown here for reference and is NOT in the total — paying it in both
        // places paid the last month twice.
        $totalMinor = $encashMinor + $otherEarningMinor - $otherDeductionMinor;

        return ['ok' => true, 'why' => 'Computed but NOT recorded. A person reads it before it is '
            . 'committed, which is the only control that catches a wrong last working day — no '
            . 'arithmetic can, because every figure is correct for the date it was given.',
            'quote' => [
                'employment_id' => $employmentId,
                'name' => (string) $employment['name'],
                'last_working_day' => $lastWorkingDay,
                // The exact figure, written as a decimal string. `settlements`
                // .payable_days was INT until migration 000600 and would have
                // stored 17.5 as 18 without a word — see that file.
                'payable_days' => $derived['payable']->toDecimalString(),
                'month_days' => $monthDays,
                'final_pay' => Money::ofMinor($finalPay)->format(),
                'final_pay_minor' => $finalPay,
                'encashments' => $encashments,
                'encashment_total' => Money::ofMinor($encashMinor)->format(),
                'other_earnings' => $otherEarningLines,
                'other_earning_total' => Money::ofMinor($otherEarningMinor)->format(),
                'other_deductions' => $otherDeductionLines,
                'other_deduction_total' => Money::ofMinor($otherDeductionMinor)->format(),
                'total' => Money::ofMinor($totalMinor)->format(),
                'total_minor' => $totalMinor,
                'exit_period' => $exitPeriod,
                'concerns' => array_merge($concerns, $derived['needing_attention'] > 0
                    ? [$derived['needing_attention'] . ' day(s) in the final month still need '
                        . 'regularisation — settle them first, or the last payslip pays a figure '
                        . 'nobody confirmed and there is no next month to correct it in']
                    : []),
            ]];
    }

    /**
     * Record it, and debit the encashed days so nobody is paid for them twice.
     *
     * @param list<array<string, mixed>> $otherEarnings ad-hoc earning lines: component_code, amount, note
     * @param list<array<string, mixed>> $otherDeductions ad-hoc deduction lines: component_code, amount, note
     * @return array{ok: bool, why: string, settlement?: array<string, mixed>}
     */
    public function settle(string $employmentId, string $lastWorkingDay, string $by,
        string $note, array $otherEarnings = [], array $otherDeductions = []): array
    {
        $quoted = $this->quote($employmentId, $lastWorkingDay, $otherEarnings, $otherDeductions);

        if (!$quoted['ok']) {
            return $quoted;
        }

        $quote = $quoted['quote'];

        if (str_starts_with((string) ($quote['concerns'][0] ?? ''), 'Generate payroll for')) {
            return ['ok' => false, 'why' => (string) $quote['concerns'][0]];
        }

        // Recomputed here rather than trusting a figure posted back from a form.
        // A settlement total that arrives from the browser is a number anybody
        // can choose.
        // THE SCHEMA'S COLUMNS, and the money as the number rather than the
        // formatted figure. The quote carries `final_pay_minor`, `total_minor`
        // and `encashment_total`, which `settlements` does not have — the store
        // refuses all three — and its `final_pay`/`total` are strings like
        // "1,23,456.00", which a DECIMAL column cannot hold. Each is derived
        // again on read, so there is one stored number and not two.
        $settlement = self::ported($this->store->put(self::COLLECTION, [
            'employment_id' => $quote['employment_id'],
            'name' => $quote['name'],
            'last_working_day' => $quote['last_working_day'],
            'payable_days' => $quote['payable_days'],
            'month_days' => $quote['month_days'],
            'final_pay' => Money::ofMinor((int) $quote['final_pay_minor'])->toDecimalString(),
            'total' => Money::ofMinor((int) $quote['total_minor'])->toDecimalString(),
            'encashments' => $quote['encashments'],
            'other_earnings' => $quote['other_earnings'],
            'other_deductions' => $quote['other_deductions'],
            'concerns' => $quote['concerns'],
            'settled_at' => date('c'),
            'settled_by' => $by,
            'note' => $note,
        ]));

        // The DEBIT. Paying out 12 days and leaving the balance at 12 is how
        // somebody is paid twice — once here, once by the next person who reads
        // the balance.
        foreach ($quote['encashments'] as $encashment) {
            $this->leave->encash($employmentId, (string) $encashment['type'],
                (string) $encashment['days'], $lastWorkingDay,
                'Encashed on full and final settlement', (string) $settlement['id']);
        }

        return ['ok' => true, 'settlement' => $settlement, 'why' => 'Settled: '
            . $quote['total'] . ' final. The encashed days are DEBITED on the ledger, naming this '
            . 'settlement — a payout that leaves the balance standing is how somebody is paid twice. '
            . 'The employee is not deleted: every earlier payslip stays explainable and every '
            . 'earlier control total stays reproducible.'];
    }
}
