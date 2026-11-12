<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * WHERE THE THREE SCREENS MEET — one payable-day figure, derived once.
 *
 * Attendance says which days were worked. Leave says which were approved
 * absence. Payroll needs ONE number, and the whole point of this class is that
 * the number is computed in one place from both sources rather than typed into
 * the payslip form by whoever is running payroll.
 *
 * THE ARITHMETIC, STATED, BECAUSE IT IS WHERE PAYROLL SYSTEMS GO WRONG:
 *
 *   payable = worked days + PAID leave days
 *
 * Paid leave is payable. UNPAID leave — loss of pay — is not, and the difference
 * is the single most common source of a wrong payslip. SmartPRS 2 pays
 * loss-of-pay leave in full (DEF-LVE-02), which is why `C-LOP` is the first
 * entry in the migration's Class C register: correcting it REDUCES net pay for
 * affected employees, and the customer has to be told before go-live rather
 * than discovering it in a reconciliation report.
 *
 * A DAY IS NOT COUNTED TWICE. Somebody who punched in and also has approved
 * leave for the same date is one payable day, not two — and which one it is, is
 * a question a person should answer, so the overlap is REPORTED rather than
 * silently resolved in either direction.
 */
final class PayrollPeriod
{
    /** Leave types that are paid. In the real product this is a flag on the type. */
    /**
     * ponytail: the fallback when no policy is injected. It exists so the class
     * still works standalone, and it is NAMED as a fallback rather than left
     * looking like the answer — the answer is config/leave-policy.json.
     */
    private const PAID_TYPES = ['EL', 'CL', 'SL'];

    public function __construct(
        private readonly Attendance $attendance,
        private readonly Leave $leave,
        private readonly ?LeavePolicy $policy = null,
        private readonly ?Calendar $calendar = null,
        // The published attendance rules. Optional so this class still runs
        // standalone — and a null one prices NO day, which shows on the screen
        // as unpriced days rather than as a quietly smaller payroll.
        private readonly ?DayRules $rules = null,
        // WHEN THIS EMPLOYMENT ACTUALLY EXISTED. `employees` has a joining date
        // and no exit date at all — leaving is a `status` flag — so nothing in
        // this class could tell a month somebody worked from a month after they
        // left. See the refusal in forPeriod().
        private readonly ?Timeline $timeline = null,
        // THE EMPLOYEE ROW, for the joining date, the exit date and the F&F last
        // working day: a weekly off before somebody joined or after they left is
        // not theirs to be paid for (7 Oct).
        private readonly ?RowStore $store = null,
    ) {
    }

    /** @var array<string, array<string, mixed>>|null code => employee row */
    private ?array $byCode = null;

    /** @var array<string, array<string, mixed>>|null id => company row (training settings) */
    private ?array $companies = null;

    /**
     * The days this employment existed in: [joined, left], either end null when
     * unknown. LEFT is the EARLIEST of the timeline's exit, the record's
     * exit_date and a recorded full & final's last working day — whichever was
     * written first is still the day they stopped working.
     *
     * @return array{0: ?string, 1: ?string, 2: string} joined, left, status
     */
    private function bounds(string $employmentId): array
    {
        if ($this->byCode === null) {
            $this->byCode = [];

            foreach ($this->store?->all('employees') ?? [] as $e) {
                $this->byCode[Employment::code($e)] = $e;
            }
        }

        $e = $this->byCode[$employmentId] ?? [];
        // PAID FROM, not just joined (owner, 15 Oct): a joiner in their company's UNPAID induction training is
        // paid from the first working day after it ends (Organization Configuration → Companies).
        if ($this->companies === null) {
            $this->companies = [];
            foreach ($this->store?->all('companies') ?? [] as $c) { $this->companies[(string) ($c['id'] ?? '')] = $c; }
        }
        $calendar = $this->calendar;
        $start = SalaryEffective::payStart($e, $this->companies[(string) ($e['employer_company_id'] ?? '')] ?? null,
            $calendar === null ? null : static fn (string $d): bool => $calendar->isWorkingDay($d))['date'];
        $joined = $start !== '' ? $start : null;
        $exits = array_filter([
            $this->timeline?->exitedOn($employmentId),
            preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($e['exit_date'] ?? '')) === 1 ? substr((string) $e['exit_date'], 0, 10) : null,
        ]);

        foreach ($this->store?->all('settlements') ?? [] as $st) {
            if ((string) ($st['employment_id'] ?? '') === $employmentId && (string) ($st['last_working_day'] ?? '') !== '') {
                $exits[] = substr((string) $st['last_working_day'], 0, 10);
            }
        }

        return [$joined, $exits === [] ? null : min($exits), strtolower((string) ($e['status'] ?? 'active'))];
    }

    /** @return list<string> which types are PAID — from the policy when there is one */
    private function paidCodes(): array
    {
        return $this->policy?->paidCodes() ?? self::PAID_TYPES;
    }

    /**
     * @return array{
     *     payable: Rational, payable_halves: int, unpriced: array<string, string>,
     *     penalties: list<array<string, mixed>>, penalty_halves: int, exited_on: ?string,
     *     period_days: int, worked: int, paid_leave: int, unpaid_leave: int,
     *     overlaps: list<string>, needing_attention: int, why: string
     * }
     */
    public function forPeriod(
        string $employmentId,
        string $from,
        string $to,
        string $shiftStart = '09:00',
        string $shiftEnd = '18:00',
    ): array {
        // The DENOMINATOR is a declared policy, not an arithmetic default: over
        // calendar days a weekly off is paid, over working days it is not, and
        // the same attendance gives two different payslips. The calendar says
        // which, and refuses a config that does not.
        $proration = $this->calendar?->prorationDays($from, $to);
        $periodDays = $proration['days']
            ?? (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;

        // WHAT A DAY IS WORTH COMES FROM THE PUBLISHED RULES, not from this file.
        // It used to be `workedMinutes > 0` — one comparison, in PHP, which paid
        // a full day for twenty minutes and paid nothing at all for an in-punch
        // whose out-punch never arrived. Both of those are now decisions in
        // config/attendance-rules.json, taken by the rule engine, with the
        // winning rule named on the attendance screen.
        //
        // HALVES, so the arithmetic stays in integers until the last step. A day
        // is 2, half a day is 1, and nothing here ever holds 0.5 — law 7.
        $workedHalves = [];
        // Dates the attendance rules settled as WEEKLY_OFF / HOLIDAY — the
        // Organization Configuration schedule, holiday scope included. Leave
        // on such a day is not a leave day (owner, 29 Sep).
        $offDates = [];
        $attention = 0;
        $unpriced = [];

        [$joined, $left, $status] = $this->bounds($employmentId);
        $inBounds = static fn (string $d): bool => ($joined === null || $d >= $joined) && ($left === null || $d <= $left);
        $settled = [];
        $absentDates = [];
        $offHalves = [];
        $holidayCount = 0;

        foreach ($this->attendance->settledDays($employmentId, $shiftStart, $shiftEnd, $from, $to) as $day) {
            if ($day['date'] < $from || $day['date'] > $to) {
                continue;
            }

            $settled[$day['date']] = $day;
        }

        // DAYS WITH NO PUNCH ARE DAYS TOO (7 Oct). settledDays() yields only
        // punched days, so a Sunday or a holiday nobody punched on — WEEKLY_OFF /
        // HOLIDAY, priced at a full day in config/attendance-rules.json — was
        // never counted, and every such day came off the pay of everyone paid over
        // calendar days. Each missing date is classified by the same rules the
        // muster uses. Only inside the employment, and not for an inactive record:
        // nobody is paid the Sundays of a month they did not belong to.
        for ($d = new \DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');

            if (!isset($settled[$date]) && $inBounds($date) && $status !== 'inactive') {
                $settled[$date] = $this->attendance->dayWithoutPunches($employmentId, $date, $shiftStart, $shiftEnd)
                    + ['unpunched' => true];
            }
        }

        ksort($settled);

        foreach ($settled as $day) {
            if (!$inBounds($day['date'])) {
                continue;
            }

            $status = (string) ($day['classification']['status'] ?? DayRules::UNCLASSIFIED);
            $halves = $this->rules?->payableHalves($status);

            if ($status === 'WEEKLY_OFF' || $status === 'HOLIDAY') {
                $offDates[$day['date']] = true;
                $holidayCount += $status === 'HOLIDAY' ? 1 : 0;
            }

            if ($halves === null) {
                // NAMED, NOT ASSUMED. A status the policy does not price is not
                // worth guessing at: guessing high pays for a month nobody
                // worked and guessing low withholds pay, and neither would
                // appear anywhere on a screen. It is reported and the day counts
                // for nothing until somebody prices it.
                $unpriced[$day['date']] = $status;
            } elseif ($halves > 0 && isset($offDates[$day['date']]) && isset($day['unpunched'])) {
                // A paid weekly off / holiday nobody worked on: payable, but not "worked".
                $offHalves[$day['date']] = $halves;
            } elseif ($halves > 0) {
                $workedHalves[$day['date']] = $halves;
            } elseif ($status === 'ABSENT') {
                $absentDates[$day['date']] = true;
            }

            if ($day['settlement']->needsRegularisation()) {
                $attention++;
            }
        }

        $paidLeaveDates = [];
        $unpaidLeave = 0;
        $unpaidLeaveDates = [];
        // Halves of each date on UNPAID leave, for the payslip (12 Oct): half a
        // day of LOP is half a day, not one and not none.
        $unpaidHalves = [];
        // Every date on approved leave of any kind — never a late mark.
        $leaveDates = [];

        foreach ($this->leave->requestsFor($employmentId) as $request) {
            if ($request['state'] !== Leave::APPROVED) {
                continue;
            }

            $paid = in_array($request['leave_type'], $this->paidCodes(), true);
            // A HALF-DAY leave is worth one half (10 Oct); every other day two.
            $leaveHalves = Leave::halfOf($request) === null ? 2 : 1;

            for ($day = new \DateTimeImmutable($request['from']);
                 $day <= new \DateTimeImmutable($request['to']);
                 $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');

                // THE COMPANY'S WEEKLY OFF, not Saturday+Sunday in PHP: a
                // Sunday-only company works Saturdays, and a paid leave taken on
                // one must be paid. A date attendance has not settled yet asks
                // the calendar; only a Period built without one falls back.
                //
                // THIS EMPLOYEE'S DAY, not the tenant's (8 Oct): a holiday scoped to
                // another branch is in the unscoped calendar, so asking the calendar
                // dropped a paid leave taken on that date here — the day came out
                // neither leave nor paid. Every in-bounds date is classified above,
                // per employment; only an unclassified date asks the calendar.
                $off = isset($settled[$date])
                    ? isset($offDates[$date])
                    : ($this->calendar !== null ? !$this->calendar->isWorkingDay($date) : (int) $day->format('N') > 5);

                if ($date < $from || $date > $to || $off || !$inBounds($date)) {
                    continue;
                }

                $leaveDates[$date] = true;

                if (!$paid) {
                    $unpaidHalves[$date] = max($unpaidHalves[$date] ?? 0, $leaveHalves);
                }

                if ($paid) {
                    $paidLeaveDates[$date] = max($paidLeaveDates[$date] ?? 0, $leaveHalves);
                } elseif (!isset($unpaidLeaveDates[$date]) && !isset($workedHalves[$date])) {
                    // A day worked is paid as worked; LOP counts only days not worked.
                    $unpaidLeaveDates[$date] = true;
                    $unpaidLeave++;
                }
            }
        }

        // Reported, never resolved silently. Which of the two a day is — worked
        // or on leave — changes the payslip AND the leave balance, and only a
        // person knows which record is the mistake.
        // A HALF DAY WORKED + A HALF DAY OF LEAVE IS ONE DAY, not an overlap (12 Oct):
        // only a day whose worked and leave halves come to MORE than a day is one.
        $overlaps = array_keys(array_filter(array_intersect_key($workedHalves, $paidLeaveDates),
            static fn (int $worked, string $date): bool => $worked + $paidLeaveDates[$date] > 2, ARRAY_FILTER_USE_BOTH));
        sort($overlaps);

        $leaveOnly = array_diff_key($paidLeaveDates, $workedHalves);
        // PAID LEAVE MAKES THE DAY WHOLE (8 Oct): a half day worked on a day of
        // approved paid leave is a full paid day — half worked + half leave —
        // never half (the leave ignored) and never one and a half (counted twice).
        $leaveTopUp = 0;

        foreach (array_intersect_key($workedHalves, $paidLeaveDates) as $date => $worth) {
            $leaveTopUp += max(0, min(2 - $worth, $paidLeaveDates[$date]));
        }

        // OVER WORKING DAYS, WEEKLY OFFS AND HOLIDAYS ARE NOT IN THE DENOMINATOR,
        // so they cannot be in the numerator either (8 Oct): paying them as well
        // gave somebody who attended every working day 30/21 of their salary.
        // Over calendar days they are paid, as before.
        $workingBasis = $this->calendar !== null && $this->calendar->prorationBasis() === 'working_days';
        $paidOffHalves = $workingBasis ? 0 : array_sum($offHalves);
        $halves = array_sum($workedHalves) + $paidOffHalves + array_sum($leaveOnly) + $leaveTopUp;
        // ABSENT = a working day with no attendance and no leave of either kind.
        $absent = count(array_diff_key($absentDates, $paidLeaveDates, $unpaidLeaveDates));

        // A PAYSLIP FOR SOMEBODY WHO LEFT IN MARCH.
        //
        // That is the defect `Platform\Lifecycle\EmploymentTimeline` names in its
        // own docblock, and it is what SmartPRS 2 does, because leaving there is
        // a flag and not an event with a date. `employees` in this database has
        // `doj` and `status` and NO EXIT DATE, so until the timeline existed this
        // class could not have told the difference either.
        //
        // A timeline with nothing on it answers NULL and is left alone: most
        // employees in a ported database have no events recorded yet, and
        // reading silence as "not employed" would stop every payslip in the
        // tenant on the day this shipped. Silence is not an answer, and treating
        // it as one is the failure mode in the opposite direction.
        $exitedOn = $left;
        $leaverNote = '';

        if ($exitedOn !== null && $exitedOn < $from) {
            // NOTHING, and the reason on the row. Returning a small figure would
            // be a payslip; returning zero with no explanation is a payslip
            // somebody queries. This is the one case where the answer is that
            // there is nothing to pay and the screen has to say why.
            $halves = 0;
            $leaverNote = sprintf(
                ' NOTHING IS PAYABLE: this employee exited on %s, before the period began. '
                . 'A payslip for a month after somebody left is the defect this product was '
                . 'rebuilt to remove, and it happens wherever leaving is a flag rather than a date.',
                $exitedOn,
            );
        } elseif ($exitedOn !== null && $exitedOn >= $from && $exitedOn <= $to) {
            // A MID-PERIOD LEAVER IS PRORATED, not paid the month. SmartPRS 2 pays
            // a full month here — it has no exit date to prorate against — and
            // that is the single largest recoverable overpayment in the migration
            // register. Days after the exit cannot have been worked, so anything
            // the attendance rules priced beyond it is removed.
            $afterExit = 0;

            foreach ($workedHalves as $date => $worth) {
                if ($date > $exitedOn) {
                    $afterExit += $worth;
                }
            }

            $halves -= $afterExit;
            $leaverNote = sprintf(
                ' Exited on %s: %s day(s) after the exit date are not payable, because they cannot '
                . 'have been worked.',
                $exitedOn,
                Rational::of($afterExit, 2)->toDecimalString(),
            );
        }

        // WHAT THE MONTH COST. `attendance.day.penalise` charges half a day for
        // the third and later late arrival, and until this line it charged it
        // onto a screen and nowhere else. Subtracted here rather than inside
        // Attendance because ONE class decides what a month is worth: two of
        // them doing the arithmetic is how they come to disagree about somebody's
        // pay, and the one somebody trusts is whichever screen they opened.
        //
        // FLOORED AT ZERO. A month of nothing but penalties would otherwise
        // produce a negative payable figure, which PayslipCalculator correctly
        // refuses — leaving a screen that cannot render at all instead of one
        // that says the month is worth nothing. Recovering more than the month
        // is a settlement question, not a proration.
        // ONLY A DAY PAID AS WORKED CAN BE CUT FOR LATENESS (8 Oct). A late
        // in-punch on a day that came out ABSENT, or a day covered by leave, is
        // already unpaid / paid as leave — cutting it again charged it twice.
        // And a cut never exceeds what the day was worth.
        $penalties = array_values(array_filter(
            $this->attendance->penalties($employmentId, $from, $to, $shiftStart, $shiftEnd, $leaveDates),
            static fn (array $p): bool => $inBounds((string) $p['date'])
                && isset($workedHalves[(string) $p['date']]) && !isset($leaveDates[(string) $p['date']])));
        $penaltyHalves = 0;

        foreach ($penalties as $penalty) {
            $penaltyHalves += min((int) $penalty['halves'], (int) $workedHalves[(string) $penalty['date']]);
        }

        $halves = max(0, $halves - $penaltyHalves);

        // LOSS OF PAY FROM AN ISSUED LETTER (owner, 23 Oct 2026) — a half-day deduction letter, an LOP
        // letter for N days: recorded in `letter_lop` when the letter is issued, taken off the days payable
        // of the pay period its date falls in, and counted as LOP everywhere this month's LOP is shown.
        $letterLop = $this->letterLop($employmentId, $from, $to, $inBounds);
        $letterLopHalves = array_sum(array_column($letterLop, 'halves'));
        $halves = max(0, $halves - $letterLopHalves);
        $payable = Rational::of($halves, 2);

        // PAID LEAVE IN DAYS AS PAID: leave-only halves plus the half that made a
        // half-worked day whole. LOP in days: each unpaid half not also worked.
        $paidLeaveHalves = array_sum($leaveOnly) + $leaveTopUp;
        $lopHalves = 0;

        foreach ($unpaidHalves as $date => $worth) {
            $lopHalves += max(0, min($worth, 2 - (int) ($workedHalves[$date] ?? 0) - (int) ($paidLeaveDates[$date] ?? 0)));
        }

        $lopHalves += $letterLopHalves;

        return [
            // EXACT, AND A FRACTION. `payable` was an int and is now a Rational
            // in days: 17 and a half days is 35/2 and never 17.5, because 17.5
            // is a float and this number multiplies somebody's gross.
            'payable' => $payable,
            'payable_halves' => $halves,
            'exited_on' => $exitedOn,
            // Reported beside the figure, always — a deduction a payslip does
            // not name is one nobody can dispute.
            'penalties' => $penalties,
            'penalty_halves' => $penaltyHalves,
            'letter_lop' => $letterLop,
            'letter_lop_halves' => $letterLopHalves,
            // Reported so a screen can print it, never resolved here: a day the
            // policy does not price is a question for a person.
            'unpriced' => $unpriced,
            'period_days' => $periodDays,
            'period_days_why' => $proration['why'] ?? 'calendar days, counted directly — no working '
                . 'calendar is configured, so weekly offs and holidays are not known',
            'worked' => count($workedHalves),
            'worked_days' => Rational::of((int) array_sum($workedHalves), 2)->toDecimalString(),
            'paid_leave' => count($leaveOnly),
            'unpaid_leave' => $unpaidLeave,
            // The same two in DAYS, halves included — what a payslip prints (12 Oct).
            'paid_leave_days' => Rational::of((int) $paidLeaveHalves, 2)->toDecimalString(),
            'unpaid_leave_days' => Rational::of((int) $lopHalves, 2)->toDecimalString(),
            // Paid weekly offs and holidays nobody worked on, and how many of them were holidays.
            'off_days' => Rational::of((int) $paidOffHalves, 2)->toDecimalString(),
            'holidays' => $holidayCount,
            'absent' => $absent,
            'joined_on' => $joined,
            'overlaps' => $overlaps,
            'needing_attention' => $attention,
            'why' => sprintf(
                '%s day(s) attended (%d day(s) classified by the published attendance rules) + %s paid weekly '
                . 'off / holiday day(s) + %s day(s) of paid leave%s = %s payable of %d in the period. Unpaid leave is NOT payable, and '
                . 'paying it is the defect the migration register calls C-LOP.%s',
                Rational::of((int) array_sum($workedHalves), 2)->toDecimalString(),
                count($workedHalves),
                Rational::of((int) $paidOffHalves, 2)->toDecimalString(),
                Rational::of((int) $paidLeaveHalves, 2)->toDecimalString(),
                $overlaps === [] ? ''
                    : ' (' . count($overlaps) . ' day(s) counted once, not twice — see below)',
                $payable->toDecimalString(),
                $periodDays,
                $unpriced === [] ? ''
                    : ' ' . count($unpriced) . ' day(s) carry a status the policy does not price and '
                        . 'count for NOTHING until it does: ' . implode(', ', array_unique($unpriced)) . '.',
            ) . $leaverNote . ($penaltyHalves === 0 ? '' : sprintf(
                ' LESS %s day(s) of penalty from %d day(s) the published attendance rules charged for: %s.',
                Rational::of($penaltyHalves, 2)->toDecimalString(),
                count($penalties),
                implode('; ', array_map(
                    static fn (array $p): string => $p['date'] . ' ' . ($p['penalties'][0]['rule'] ?? ''),
                    $penalties,
                )),
            )) . ($letterLopHalves === 0 ? '' : sprintf(
                ' LESS %s day(s) of loss of pay from issued letter(s): %s.',
                Rational::of($letterLopHalves, 2)->toDecimalString(),
                implode('; ', array_map(static fn (array $l): string => $l['date'] . ' ' . $l['reason'], $letterLop)),
            )),
        ];
    }

    /**
     * Active letter LOP inside this window, for this employee.
     *
     * @return list<array{date: string, halves: int, letter_id: string, reason: string}>
     */
    private function letterLop(string $employmentId, string $from, string $to, callable $inBounds): array
    {
        if ($this->store === null) {
            return [];
        }

        try {
            $rows = $this->store->all('letter_lop');
        } catch (\Throwable) {
            return [];   // the letter_lop table is not migrated yet on this install: no letter has charged LOP
        }

        $out = [];

        foreach ($rows as $r) {
            $on = substr((string) ($r['on_date'] ?? ''), 0, 10);

            if ((string) ($r['employee_code'] ?? '') === $employmentId && (string) ($r['status'] ?? '') === 'active'
                && $on >= $from && $on <= $to && $inBounds($on)) {
                $out[] = ['date' => $on, 'halves' => (int) ($r['lop_halves'] ?? 0), 'letter_id' => (string) ($r['letter_id'] ?? ''), 'reason' => (string) ($r['reason'] ?? '')];
            }
        }

        return $out;
    }
}
