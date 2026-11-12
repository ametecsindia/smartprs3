<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Attendance\DaySettlement;

/**
 * THE MUSTER: EVERY EMPLOYMENT, EVERY DAY OF A PERIOD, ONE CELL EACH.
 *
 * SmartPRS 2's `AttendanceReportController` builds a daily report from raw
 * punches — first in, last out, worked, break, in/out counts, shift variance,
 * late flags, a 1–10 rating with remarks per employee-day, and a punch-by-punch
 * drill-down. This is that report, with three differences that are the whole
 * point of the rebuild.
 *
 * 1. IT DOES NOT COMPUTE ANYTHING ITSELF. Every figure in a cell comes from
 *    `Attendance::settledDays()` — the same `DaySettlement` and the same
 *    published rules the payroll register pays from. SmartPRS 2 has a second
 *    pairing algorithm here (`pairPunches`) and a second late policy
 *    (`attendanceFlags`) with a second hard-coded shift (`09:30`–`18:30`), so
 *    the report and the payslip disagree, and the one somebody trusts is
 *    whichever they opened first.
 *
 * 2. A DAY WITH NO PUNCHES IS A DAY, not a missing row. SmartPRS 2 lists only
 *    days that have logs, so an absent fortnight is invisible on the report that
 *    exists to show it. Here every calendar day in the range is a cell, and an
 *    empty one is classified by the same rule engine — WEEKLY_OFF, HOLIDAY,
 *    ABSENT — or marked ON_LEAVE from an approved request. A day in the future
 *    is blank and says so; it is not ABSENT, because it has not happened.
 *
 * 3. IT NEVER INVENTS DATA. SmartPRS 2 generates DEMO PUNCHES for any day in
 *    range with no logs ("so the screen always shows data") — into the same
 *    table payroll reads. That is Part I's `/app/data` defect in a second place:
 *    fabricated attendance becoming pay. There is no demo branch here at all.
 *
 * ponytail: every employee is settled in full and the range filtered after,
 * because `settledDays()` is per employee and reads all its punches. One
 * month for one tenant on a laptop is fine; when a range is a year across a
 * thousand people the fix is a `punchesBetween()` on the store, not here.
 */
final class AttendanceReport
{
    public const RATINGS = 'attendance_ratings';   // the ported table

    /** The status a day inside an approved leave carries. Priced by the leave ledger, never here. */
    public const ON_LEAVE = 'ON_LEAVE';

    /** Approved leave of an UNPAID type (LOP) — shown apart, because payroll does not pay it (8 Oct). */
    public const UNPAID_LEAVE = 'UNPAID_LEAVE';

    /** Statuses that are a day of leave on the muster. */
    public const LEAVE_STATUSES = [self::ON_LEAVE, self::UNPAID_LEAVE];

    /** A day that has not happened. Reported as such, never as ABSENT. */
    public const NOT_YET = 'NOT_YET';

    /** A day before the employee joined: nothing to settle, nothing to count. */
    public const NOT_JOINED = 'NOT_JOINED';

    /** The shift the last muster was built with, so daily() drills down against the same window. */
    private ?string $shiftStart = null;
    private ?string $shiftEnd = null;
    private ?Shifts $shifts = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Attendance $attendance,
        private readonly ?Leave $leave = null,
    ) {
    }

    /** @return array{0: string, 1: string} this employee's shift start/end, else the defaults */
    private function shiftTimes(array $employment, string $defaultStart, string $defaultEnd): array
    {
        $this->shifts ??= new Shifts($this->store);

        return $this->shifts->times($employment, $defaultStart, $defaultEnd);
    }

    /**
     * THIS EMPLOYEE'S SHIFT ON THIS DAY, named and timed — "GEN · 09:00–18:00".
     *
     * The daily view used to stamp ONE label on every row: the string its caller
     * built from the working calendar's default. muster() has always resolved
     * each employee's real shift (Organization Setup → Shifts, by scope, and the
     * roster on top of that) and settled their day against it — so a night-shift
     * employee's row was settled 22:00–07:00 and captioned 09:00–18:00, and the
     * Late By beside it looked like a mistake in the figure rather than in the
     * caption. The report was disagreeing with itself in two adjacent columns.
     *
     * The CODE comes first because that is the word anybody says out loud — GEN,
     * NS — and the times follow because a code alone means nothing to somebody
     * reading their first attendance report.
     *
     * ponytail: resolved per employee-DAY, not per employee, because a roster
     * can move somebody between shifts mid-week and the caption has to follow.
     * That is one Shifts::for() per row; it sits beside a day() call that reads
     * punches, so it is not the thing to optimise. Memoise by employment+date
     * if a tenant with a year of rostered rows ever feels it.
     */
    private function shiftLabelFor(array $employment, string $date, string $fallback): string
    {
        $this->shifts ??= new Shifts($this->store);
        $shift = $this->shifts->for($employment, $date);

        if ($shift === null) {
            return $fallback;
        }

        $start = substr((string) ($shift['start_time'] ?? ''), 0, 5);
        $end = substr((string) ($shift['end_time'] ?? ''), 0, 5);
        $window = $start !== '' && $end !== '' ? $start . '–' . $end : $fallback;
        // The code is what a roster prints and what people say; the name is the
        // fallback for a tenant who never filled one in, and the window alone is
        // the fallback for one who filled in neither.
        $named = trim((string) ($shift['code'] ?? '')) ?: trim((string) ($shift['name'] ?? ''));

        return $named === '' ? $window : $named . ' · ' . $window;
    }

    /**
     * The dates of a range, inclusive, refusing a range too big to read.
     *
     * @param int $maxDays widest span allowed, in days; 0 means no ceiling at all
     *   (musterWide()'s callers, which fold to counts and never draw a per-day
     *   grid). The default of 62 is what every caller gets that does not pass
     *   its own — the attendance-report SCREEN instead passes
     *   config/attendance-report.json's own `max_range_days` (Law 1: the
     *   ceiling is a tenant-configurable number, never one picked here).
     */
    public static function dates(string $from, string $to, int $maxDays = 62): array
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);

        if ($a === false || $b === false || $a->format('Y-m-d') !== $from || $b->format('Y-m-d') !== $to) {
            throw new \InvalidArgumentException("'{$from}' to '{$to}' is not a date range (Y-m-d).");
        }

        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }

        if ($maxDays > 0 && (int) $a->diff($b)->days > $maxDays) {
            throw new \InvalidArgumentException(sprintf(
                'A muster is at most %d days wide here. A wider one is a report nobody reads on a '
                . 'screen — export in smaller ranges, or raise max_range_days in '
                . 'config/attendance-report.json.',
                $maxDays,
            ));
        }

        $out = [];

        for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /**
     * One row per employee, one cell per date.
     *
     * @param list<array<string, mixed>> $employments the rows the viewer may see — scope is
     *        the caller's job (Surface\Scope), because a report that decides who is in it
     *        is a second authorisation implementation
     * @return array{dates: list<string>, rows: list<array<string, mixed>>, legend: array<string, int>}
     */
    public function muster(array $employments, string $from, string $to, string $shiftStart,
        string $shiftEnd, string $today, int $maxDays = 62): array
    {
        $dates = self::dates($from, $to, $maxDays);
        $this->shiftStart = $shiftStart;
        $this->shiftEnd = $shiftEnd;
        [$defaultStart, $defaultEnd] = [$shiftStart, $shiftEnd];
        $first = $dates[0];
        $last = $dates[count($dates) - 1];
        $ratings = $this->ratingsBetween($first, $last);
        $rows = [];
        $legend = [];

        foreach ($employments as $employment) {
            $code = Employment::code($employment);

            if ($code === '') {
                continue;
            }

            // THE EMPLOYEE'S OWN WORKING SHIFT (Organization Setup → Shifts, by
            // scope), the same resolution the payroll register uses — the passed
            // times are only the fallback for someone no shift row covers. Using
            // the calendar default for everyone marked a 09:30 shift late every
            // day and an afternoon shift as never worked, while payroll, reading
            // the same punches, disagreed.
            [$shiftStart, $shiftEnd] = $this->shiftTimes($employment, $defaultStart, $defaultEnd);
            $settled = [];

            foreach ($this->attendance->settledDays($code, $shiftStart, $shiftEnd, $first, $last) as $day) {
                if ($day['date'] >= $first && $day['date'] <= $last) {
                    $settled[$day['date']] = $day;
                }
            }

            $onLeave = $this->leaveDates($code, $first, $last);
            $joinedOn = Employment::joinedOn($employment);
            // THE LAST DAY OF THE EMPLOYMENT, as payroll reads it (8 Oct): after it
            // nobody is absent — payroll pays nothing then and the muster says so.
            $leftOn = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($employment['exit_date'] ?? '')) === 1
                ? substr((string) $employment['exit_date'], 0, 10) : '';
            $leaveCell = function (string $date) use ($onLeave): array {
                [$type, $paid] = $onLeave[$date];

                return $paid === false
                    ? self::blank(self::UNPAID_LEAVE, 'Inside an approved UNPAID leave request (' . $type
                        . '). Loss of pay: not paid by payroll.')
                    : self::blank(self::ON_LEAVE, 'Inside an approved leave request (' . $type
                        . '). Paid leave: paid by payroll as ' . (str_ends_with($type, ' only') ? 'half a day, the other half as worked.' : 'a full day.'));
            };
            $cells = [];
            $totals = ['worked_minutes' => 0, 'late_days' => 0, 'late_minutes' => 0,
                'overtime_minutes' => 0, 'needing_regularisation' => 0, 'by_status' => []];

            foreach ($dates as $date) {
                if ($date > $today) {
                    $cells[$date] = self::blank(self::NOT_YET, 'This day has not happened yet.');

                    continue;
                }

                if ($joinedOn !== '' && $date < $joinedOn) {
                    // Before the date of joining there is nobody to be absent — a joiner in
                    // April is not four months absent in the year's summary.
                    $cells[$date] = self::blank(self::NOT_JOINED, 'Before the date of joining (' . $joinedOn . ').');

                    continue;
                }

                if ($leftOn !== '' && $date > $leftOn) {
                    $cells[$date] = self::blank(self::NOT_JOINED, 'After the exit date (' . $leftOn . ').');

                    continue;
                }

                if (isset($settled[$date])) {
                    $cell = self::cell($settled[$date]);

                    // Punched, but too little to count, on a day of approved leave:
                    // the leave decides the day — exactly as payroll pays it.
                    if ($cell['status'] === 'ABSENT' && isset($onLeave[$date])) {
                        $cell = $leaveCell($date);
                    }
                } elseif (isset($onLeave[$date])) {
                    // A LEAVE SPANNING A WEEKLY OFF OR HOLIDAY: the off day stays an
                    // off day (Organization Configuration's schedule, holiday scope
                    // included) — payroll pays it as W.OFF / holiday, not as leave.
                    $cell = self::cell($this->attendance->dayWithoutPunches($code, $date, $shiftStart, $shiftEnd));
                    $cell = in_array($cell['status'], ['WEEKLY_OFF', 'HOLIDAY'], true) ? $cell : $leaveCell($date);
                } elseif ($date === $today && self::shiftStillOpen($date, $shiftStart, $shiftEnd)) {
                    // TODAY, BEFORE THE SHIFT HAS EVEN ENDED, IS NOT YET AN ABSENCE — same
                    // reasoning as a future date above: a shift starting at 14:00 with nobody
                    // punched in at 11:00 has not happened yet either, and ATT-NO-PUNCH cannot
                    // know that, because it is only ever handed a day, never a clock.
                    $cells[$date] = self::blank(self::NOT_YET, "Today's shift ({$shiftStart}\u{2013}{$shiftEnd}) is still under way.");

                    continue;
                } else {
                    $cell = self::cell($this->attendance->dayWithoutPunches($code, $date,
                        $shiftStart, $shiftEnd));
                }

                $rating = $ratings[$code . '|' . $date] ?? null;
                $cell['rating'] = $rating === null ? null : $rating['rating'];
                $cell['remarks'] = $rating === null ? '' : (string) ($rating['remarks'] ?? '');

                $cells[$date] = $cell;
                $totals['worked_minutes'] += $cell['worked_minutes'];
                // A day on approved leave (half or whole) is not a late mark (12 Oct).
                $lateCounts = !isset($onLeave[$date]);
                $totals['late_minutes'] += $lateCounts ? $cell['late_minutes'] : 0;
                $totals['overtime_minutes'] += $cell['overtime_minutes'];
                $totals['late_days'] += $lateCounts && $cell['late_minutes'] > 0 ? 1 : 0;
                $totals['needing_regularisation'] += $cell['needs_regularisation'] ? 1 : 0;
                $totals['by_status'][$cell['status']] = ($totals['by_status'][$cell['status']] ?? 0) + 1;
                $legend[$cell['status']] = ($legend[$cell['status']] ?? 0) + 1;
            }

            ksort($totals['by_status']);

            // THE LATE-LOGIN CUT, as payroll takes it (8 Oct): only on a day paid as
            // worked and not covered by paid leave, never more than the day was worth.
            $totals['late_cut_halves'] = 0;

            foreach ($this->attendance->penalties($code, $first, $last, $shiftStart, $shiftEnd, $onLeave) as $p) {
                $pd = (string) $p['date'];
                $worth = isset($cells[$pd]) ? (int) ($this->attendance->worthOf((string) $cells[$pd]['status']) ?? 0) : 0;

                if ($worth > 0 && !in_array($cells[$pd]['status'], self::LEAVE_STATUSES, true) && !isset($onLeave[$pd])) {
                    $totals['late_cut_halves'] += min((int) $p['halves'], $worth);
                }
            }

            $rows[] = [
                'employment_id' => (string) ($employment['id'] ?? ''),
                'code' => $code,
                'name' => trim((string) ($employment['name'] ?? '')),
                'department' => trim((string) ($employment['department'] ?? '')),
                'branch' => trim((string) ($employment['branch'] ?? '')),
                'cells' => $cells,
                'totals' => $totals,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
        ksort($legend);

        return ['dates' => $dates, 'rows' => $rows, 'legend' => $legend];
    }

    /**
     * The muster over ANY width — a year, a financial year — for the summary that
     * folds days to counts and never draws the cells. Same code path as muster();
     * the per-screen range ceiling is lifted entirely (maxDays = 0), not just raised.
     *
     * @return array{dates: list<string>, rows: list<array<string, mixed>>, legend: array<string, int>}
     */
    public function musterWide(array $employments, string $from, string $to, string $shiftStart,
        string $shiftEnd, string $today): array
    {
        return $this->muster($employments, $from, $to, $shiftStart, $shiftEnd, $today, 0);
    }

    /**
     * One employee, one day, every punch — the drill-down behind a cell.
     *
     * @return array<string, mixed>
     */
    public function day(string $code, string $date, string $shiftStart, string $shiftEnd): array
    {
        foreach ($this->store->all('employees') as $candidate) {
            if (Employment::code($candidate) === $code) {
                [$shiftStart, $shiftEnd] = $this->shiftTimes($candidate, $shiftStart, $shiftEnd);

                break;
            }
        }

        $found = null;

        // BOUNDED TO THIS ONE DAY (plus settledDays()'s own day-either-side
        // margin for a cross-midnight shift) — not the employee's whole
        // history. daily() below calls this once per employee-day that has a
        // punch, so an unbounded settledDays() here resettled every punch the
        // employee ever made, once per day in the report's range: a 50-person,
        // one-year tenant turned a ~17-day Daily Attendance view into hundreds
        // of thousands of DaySettlement runs and the screen never finished
        // loading. Bounding it is exactly the fix settledDays()'s own $from/$to
        // parameters were added for ("settling a whole year to read one month
        // is the other half of the wait") — this call was the one that never
        // adopted it.
        foreach ($this->attendance->settledDays($code, $shiftStart, $shiftEnd, $date, $date) as $day) {
            if ($day['date'] === $date) {
                $found = $day;

                break;
            }
        }

        $day = $found ?? $this->attendance->dayWithoutPunches($code, $date, $shiftStart, $shiftEnd);
        $cell = self::cell($day);
        /** @var DaySettlement $settlement */
        $settlement = $day['settlement'];

        $sessions = [];
        $previousOut = null;

        foreach ($settlement->sessions as $session) {
            $sessions[] = [
                'in' => $session['in']->at->format('H:i'),
                'out' => $session['out']->at->format('H:i'),
                'minutes' => (int) $session['minutes'],
                // The gap BEFORE this session — the break somebody took between
                // coming back and the last time they left.
                'break_before' => $previousOut === null ? null
                    : (int) (($session['in']->at->getTimestamp() - $previousOut->getTimestamp()) / 60),
                'in_source' => $session['in']->sourceCode,
                'out_source' => $session['out']->sourceCode,
            ];
            $previousOut = $session['out']->at;
        }

        $unpaired = array_map(static fn ($p): array => [
            'at' => $p->at->format('H:i'), 'direction' => $p->direction, 'source' => $p->sourceCode,
        ], $settlement->unpaired);

        // THE THIRD BUCKET, which this method used to drop on the floor.
        //
        // ShiftWindow::assign() splits punches into `inside` and `outside`, and
        // says why in terms: "A punch outside the window is NOT dropped: it
        // comes back in `outside`... Silently discarding a swipe is how
        // somebody's overtime disappears." The engine kept them and then the
        // report threw them away, which is the same disappearance one layer up.
        //
        // Most of these are honest: on a cross-midnight shift a 01:00 punch is
        // outside today's window and inside yesterday's, so it is settled and
        // shown THERE. The ones that matter are the punches no window covers —
        // a 04:00 swipe on a 09:00–18:00 day — which were, until this line,
        // visible nowhere in the product. They are listed apart from the day's
        // own sessions because they are not part of what it settled.
        $outsideWindow = array_map(static fn ($p): array => [
            'at' => $p->at->format('H:i'), 'direction' => $p->direction, 'source' => $p->sourceCode,
            'on' => $p->at->format('Y-m-d'),
        ], $settlement->outsideWindow);

        $rating = $this->ratingsBetween($date, $date)[$code . '|' . $date] ?? null;

        // array_merge, not `+`: the union keeps the LEFT key, and the cell's
        // `sessions` is a count where this one is the list.
        return array_merge($cell, [
            'code' => $code,
            'date' => $date,
            'sessions' => $sessions,
            'unpaired' => $unpaired,
            'outside_window' => $outsideWindow,
            // PUNCHES HELD FOR APPROVAL (owner, 14 Oct): on a W.OFF, holiday or approved leave day.
            'held' => array_map(static fn (array $p): array => ['at' => substr((string) $p['at'], 11, 5), 'direction' => (string) $p['direction'],
                'source' => (string) $p['source']], $this->attendance->heldPunches($code, $date)),
            'held_why' => $this->attendance->offDayReason($code, $date),
            'trace' => $settlement->trace,
            'rule' => $day['classification']['rule'] ?? null,
            'why' => (string) ($day['classification']['why'] ?? ''),
            'rating' => $rating === null ? null : $rating['rating'],
            'remarks' => $rating === null ? '' : (string) ($rating['remarks'] ?? ''),
        ]);
    }

    /**
     * A manager's 1–10 rating and remarks on one employee-day.
     *
     * One row per employee-day, as the ported unique index says; a second rating
     * on the same day REPLACES the first and the audit trail keeps both, because
     * this is an opinion about a day and not a figure anything is paid from.
     *
     * @return array{ok: bool, why: string}
     */
    public function rate(string $code, string $date, ?int $rating, string $remarks, string $by): array
    {
        if ($rating !== null && ($rating < 1 || $rating > 10)) {
            return ['ok' => false, 'why' => "A rating is 1 to 10, or none. {$rating} is neither."];
        }

        if (self::dates($date, $date) === []) {
            return ['ok' => false, 'why' => "'{$date}' is not a date."];
        }

        if ($rating === null && trim($remarks) === '') {
            return ['ok' => false, 'why' => 'Nothing to record: no rating and no remark.'];
        }

        $row = ['emp_code' => $code, 'log_date' => $date, 'rating' => $rating,
            'remarks' => trim($remarks), 'rated_by' => $by];
        $existing = $this->ratingsBetween($date, $date)[$code . '|' . $date] ?? null;

        if ($existing !== null) {
            $row['id'] = $existing['id'];
        }

        $this->store->put(self::RATINGS, $row);

        return ['ok' => true, 'why' => $existing === null ? "Rated {$code} on {$date}."
            : "Re-rated {$code} on {$date}; the earlier rating is in the audit trail."];
    }

    /**
     * The declared columns of the daily view, with the tenant's choice laid over —
     * and the declared range ceiling (`max_range_days`) alongside them, since both
     * live in the same file and the report screen needs both before it can run.
     *
     * @return array{declared: array<string, array{label: string, default: bool}>, chosen: list<string>,
     *               labels: array<string, string>, key: string, max_range_days: int}
     */
    public static function columns(string $file, ?Customisation $customisation): array
    {
        $declared = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($declared) || !is_array($declared['columns'] ?? null) || $declared['columns'] === []) {
            throw new \RuntimeException("{$file} declares no columns for the daily attendance report.");
        }

        $key = (string) ($declared['customisation_key'] ?? 'attendance-report');
        $saved = $customisation?->live()[$key]['columns'] ?? null;
        $chosen = [];

        foreach ($declared['columns'] as $code => $column) {
            // "always" BEATS A SAVED CHOICE, and it has to.
            //
            // A saved list is a snapshot of the columns that existed the day
            // somebody pressed Save. Nothing reconciles it afterwards, so a
            // column added or promoted later is invisible to every tenant who
            // has ever opened the Columns screen — they cannot even tick it,
            // because a list they saved before it existed is what the screen
            // shows them. That is how Shift stayed missing from a report whose
            // Late By, Early Exit and Overtime are all measured AGAINST the
            // shift: the row quoted "late by 42" and never said late for what.
            //
            // So a column the report cannot honestly be read without is marked
            // `always` here and is not the tenant's to drop. Everything else
            // stays theirs. Use this sparingly — it is a column that ignores
            // somebody's explicit choice, which needs to be worth it.
            $on = ($column['always'] ?? false)
                || (is_array($saved) ? in_array($code, $saved, true) : (bool) ($column['default'] ?? true));

            if ($on) {
                $chosen[] = (string) $code;
            }
        }

        // The widest span the screen (and its export) will settle in one go — a
        // tenant-configurable ceiling declared in this same file (Law 1), 366 days
        // (a full year) if the file leaves it unset or sets something nonsensical.
        $maxRangeDays = (int) ($declared['max_range_days'] ?? 366);
        $maxRangeDays = $maxRangeDays > 0 ? $maxRangeDays : 366;

        return ['declared' => $declared['columns'], 'chosen' => $chosen,
            'labels' => (array) ($declared['status_labels'] ?? []), 'key' => $key,
            'max_range_days' => $maxRangeDays];
    }

    /**
     * The muster as SmartPRS 2 prints it: one row per employee per day.
     *
     * Days that have not happened are left out; every other day is a row, so a
     * fortnight of absence is fourteen rows and not a gap. Values are the cells';
     * the org columns come from `$lookups` (company id => name, employee id =>
     * label) that the screen resolves once.
     *
     * @param array{companies?: array<string, string>, employees?: array<string, string>} $lookups
     * @return list<array<string, mixed>>
     */
    /**
     * @param list<array<string, mixed>> $latePolicies live rows from the
     *   `late-policy` module (Organization Setup → Attendance → Late & Comp-Off
     *   Policy) — the daily view colours late arrivals and over-budget breaks
     *   ONLY off numbers a tenant typed there, never a number in this file
     *   (Law 1). A tenant that never sets a tier or a break budget sees no
     *   colouring at all — the shipped default is "off", not an invented one.
     */
    public function daily(array $muster, array $employmentsById, array $lookups, string $shiftLabel,
        array $statusLabels = [], array $latePolicies = []): array
    {
        $rows = [];

        foreach ($muster['rows'] as $row) {
            $employment = $employmentsById[(string) $row['employment_id']] ?? [];
            $policy = self::latePolicyFor($latePolicies, (string) ($employment['employer_company_id'] ?? ''));
            // ONCE PER EMPLOYMENT, NOT PER DAY — neither changes date to date, same
            // reason $policy above is resolved outside the date loop.
            $employeeStatus = trim((string) ($employment['status'] ?? ''));
            $employeeStatus = $employeeStatus === '' ? 'Active' : ucfirst(strtolower($employeeStatus));
            $dateOfJoining = Employment::joinedOn($employment);

            foreach ($muster['dates'] as $date) {
                $cell = $row['cells'][$date];

                if ($cell['status'] === self::NOT_YET || $cell['status'] === self::NOT_JOINED) {
                    continue;
                }

                $status = (string) $cell['status'];
                $logs = '';

                if ($cell['punches'] > 0) {
                    $drill = $this->day($row['code'], $date, $this->shiftStart ?? '09:00', $this->shiftEnd ?? '18:00');
                    $parts = [];

                    foreach ($drill['sessions'] as $sn) {
                        $parts[] = $sn['in'] . ' in';
                        $parts[] = $sn['out'] . ' out';
                    }

                    foreach ($drill['unpaired'] as $up) {
                        $parts[] = $up['at'] . ' ' . $up['direction'] . ' (unpaired)';
                    }

                    $logs = implode(' · ', $parts);
                    $inCount = count($drill['sessions']) + count(array_filter($drill['unpaired'],
                        static fn (array $u): bool => $u['direction'] === 'in'));
                    $outCount = count($drill['sessions']) + count(array_filter($drill['unpaired'],
                        static fn (array $u): bool => $u['direction'] === 'out'));
                } else {
                    $inCount = 0;
                    $outCount = 0;
                }

                $rows[] = [
                    'emp_code' => $row['code'],
                    'name' => $row['name'],
                    'company' => (string) ($lookups['companies'][(string) ($employment['employer_company_id'] ?? '')] ?? ''),
                    // THE REGISTERS FIRST (branch_id → Organization Setup's name), the legacy text column as fallback.
                    'branch' => (string) ($lookups['branches'][(string) ($employment['branch_id'] ?? '')] ?? trim((string) ($employment['branch'] ?? ''))),
                    'department' => $row['department'] !== '' ? $row['department'] : (string) ($lookups['departments'][(string) ($employment['department_id'] ?? '')] ?? ''),
                    'designation' => (string) ($lookups['designations'][(string) ($employment['designation_id'] ?? '')] ?? trim((string) ($employment['designation'] ?? ''))),
                    'team' => (string) ($lookups['teams'][(string) ($employment['team_id'] ?? '')] ?? trim((string) ($employment['team'] ?? ''))),
                    'team_leader' => self::reportingManagerOf($lookups, $employment),
                    'reporting_manager' => (string) ($lookups['employments'][(string) ($employment['reporting_manager_id'] ?? '')] ?? ''),
                    'date' => $date,
                    // $shiftLabel is now only the FALLBACK — see shiftLabelFor().
                    'shift' => $this->shiftLabelFor($employment, $date, $shiftLabel),
                    'status' => (string) ($statusLabels[$status] ?? $status),
                    'status_code' => $status,
                    'first_in' => (string) ($cell['first_in'] ?? ''),
                    'last_out' => (string) ($cell['last_out'] ?? ''),
                    'total_time' => $cell['punches'] > 0 ? self::hm((int) $cell['worked_minutes']) : '',
                    'break_time' => $cell['punches'] > 0 ? self::hm((int) $cell['break_minutes']) : '',
                    'in_count' => $inCount,
                    'out_count' => $outCount,
                    'logs' => $logs,
                    'late_minutes' => (int) $cell['late_minutes'],
                    // THE BADGE COLOUR, AND WHETHER THERE IS ONE AT ALL, IS THE
                    // POLICY'S CALL — a tier whose "after" minute is left blank
                    // or zero never fires, so a tenant who has not configured
                    // tiers gets the plain amber "late Nm" chip this screen
                    // always showed, not a guessed threshold.
                    'late_tier_badge' => self::lateTierBadge($policy, (int) $cell['late_minutes']),
                    // BREAK ALLOWED LIVES ON THE SHIFT (owner, 14 Oct); the policy's figure is the fallback.
                    'break_over_badge' => self::breakOverBadge($policy, (int) $cell['break_minutes'],
                        (int) (($this->shifts ??= new Shifts($this->store))->for($employment, $date)['break_budget'] ?? 0)),
                    'needs_regularisation' => (bool) $cell['needs_regularisation'],
                    'employment_id' => (string) $row['employment_id'],
                    // ADDITIONAL, OPT-IN COLUMNS — every value already exists on the
                    // same settlement/employment record the fixed columns above read;
                    // nothing new is computed here, only exposed as something a tenant
                    // can choose to print (config/attendance-report.json's `columns`).
                    'early_exit_minutes' => (int) $cell['early_exit_minutes'],
                    'overtime_minutes' => (int) $cell['overtime_minutes'],
                    'regularisation' => $cell['needs_regularisation'] ? 'Yes' : 'No',
                    'rating' => $cell['rating'] !== null ? (string) $cell['rating'] : '',
                    'remarks' => (string) ($cell['remarks'] ?? ''),
                    'employee_status' => $employeeStatus,
                    'date_of_joining' => $dateOfJoining,
                ];
            }
        }

        return $rows;
    }

    /** The live late-policy row for this company, or the blank/ALL row, or none. */
    private static function latePolicyFor(array $latePolicies, string $companyId): ?array
    {
        $fallback = null;

        foreach ($latePolicies as $p) {
            $pc = (string) ($p['employer_company_id'] ?? '');

            if ($pc !== '' && $pc === $companyId) {
                return $p;
            }

            if ($pc === '') {
                $fallback ??= $p;
            }
        }

        return $fallback;
    }

    /**
     * Highest tier this many late minutes reaches, as the badge colour word
     * the tenant picked for it (l1_min/l1_badge … l3_min/l3_badge, lowercased
     * to match the badge-<word> CSS classes) — null when no policy applies,
     * nothing is late, or every tier's "after" is left unset.
     */
    private static function lateTierBadge(?array $policy, int $lateMinutes): ?string
    {
        if ($policy === null || $lateMinutes <= 0) {
            return null;
        }

        $badge = null;

        foreach (['l1', 'l2', 'l3'] as $tier) {
            $after = (int) ($policy["{$tier}_min"] ?? 0);

            if ($after > 0 && $lateMinutes >= $after) {
                $badge = strtolower((string) ($policy["{$tier}_badge"] ?? 'amber'));
            }
        }

        return $badge;
    }

    /**
     * The colour word the tenant picked for an over-budget break
     * (break_over_badge), or null when the break is within budget, no
     * budget is set, or no policy applies.
     */
    private static function breakOverBadge(?array $policy, int $breakMinutes, int $shiftBudget = 0): ?string
    {
        if ($policy === null && $shiftBudget <= 0) {
            return null;
        }

        $budget = $shiftBudget > 0 ? $shiftBudget : (int) ($policy['break_budget'] ?? 0);

        if ($budget <= 0 || $breakMinutes <= $budget) {
            return null;
        }

        return strtolower((string) ($policy['break_over_badge'] ?? 'red'));
    }

    /** The daily rows as CSV, in the chosen columns. */
    public static function dailyCsv(array $rows, array $chosen, array $declared): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        fputcsv($out, array_map(static fn (string $c): string => (string) ($declared[$c]['label'] ?? $c), $chosen), ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $c): string => str_ends_with($c, '_minutes') ? self::hm((int) ($row[$c] ?? 0)) : (string) ($row[$c] ?? ''), $chosen), ',', '"', '\\'); // durations: minutes under 1 hour, else hours (owner, 23 Oct)
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * WHO AN EMPLOYEE REPORTS TO — the reporting line first, the teams register
     * only as a fallback.
     *
     * The owner's hierarchy (23 Sep) is Employee → Team Lead → Manager →
     * Director, and EVERY step of it is `reporting_manager_id`: "the Team Lead
     * is configured the Reporting Manager". An agent's reporting manager is
     * therefore the person a team-leader column was trying to name all along,
     * which is why this column now prints as "Reporting Manager" — one heading
     * that is true for an agent, a team lead and a manager alike, instead of one
     * that is right for the bottom row and wrong for every row above it.
     *
     * The reporting line leads BECAUSE of that heading: a column saying
     * "Reporting Manager" must not quietly show a team's leader instead. The
     * teams register and the legacy text column stay on as fallbacks so a tenant
     * who does use teams, and rows written before the register existed, still
     * show somebody rather than nothing.
     *
     * `?:` AND NOT `??`, which is the whole reason this column printed blank on
     * every row. attendanceLookups() maps a team with no `leader_id` to an
     * EMPTY STRING, not null — so the old `??` chain saw a value, stopped, and
     * printed nothing, silently skipping every fallback below it. `??` asks "is
     * it missing"; the question here is "is it useful".
     *
     * No designation is tested anywhere — "Team Lead" is a word this tenant
     * chose for a job, not one this file is allowed to know (Law 1).
     *
     * @param array<string, array<string, string>> $lookups from attendanceLookups()
     * @param array<string, mixed> $employment
     */
    public static function reportingManagerOf(array $lookups, array $employment): string
    {
        return (string) ($lookups['employments'][(string) ($employment['reporting_manager_id'] ?? '')] ?? '')
            ?: (string) ($lookups['team_leaders'][(string) ($employment['team_id'] ?? '')] ?? '')
            // The record's own Team Leader is now picked from the directory and
            // holds the leader's id (28 Sep); an old free-text value still reads.
            ?: (string) ($lookups['employments'][trim((string) ($employment['team_leader'] ?? ''))]
                ?? trim((string) ($employment['team_leader'] ?? '')));
    }

    /**
     * ONE EMPLOYEE, ONE DAY, EVERY PUNCH — the export behind the Log button.
     *
     * The screen already shows this day in full; until now it was the only view
     * in the report with nothing to export, so the one thing somebody actually
     * attaches to a regularisation email had to be retyped or screenshotted.
     *
     * Sessions first, in order, each with the break BEFORE it — a break is the
     * gap between two sessions, so it belongs to the session it precedes rather
     * than to a row of its own. Unpaired punches are listed after, not silently
     * dropped: a missing OUT is the usual reason somebody opens this at all.
     * The summary line is last so a reader who only wants the totals finds them
     * without scrolling past four pairs.
     *
     * @param array<string, mixed> $day the array day() returns
     */
    public static function dayCsv(array $day, string $code, string $date): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        fputcsv($out, ['emp_code', 'date', 'entry', 'in', 'out', 'worked',
            'break_before', 'in_source', 'out_source'], ',', '"', '\\');

        $n = 0;

        foreach ((array) ($day['sessions'] ?? []) as $session) {
            fputcsv($out, [$code, $date, 'session ' . (++$n), $session['in'], $session['out'],
                self::hm((int) $session['minutes']),
                $session['break_before'] === null ? '' : self::hm((int) $session['break_before']),
                $session['in_source'], $session['out_source']], ',', '"', '\\');
        }

        foreach ((array) ($day['unpaired'] ?? []) as $punch) {
            fputcsv($out, [$code, $date, 'unpaired',
                $punch['direction'] === 'in' ? $punch['at'] : '',
                $punch['direction'] === 'out' ? $punch['at'] : '',
                '', '', $punch['direction'] === 'in' ? $punch['source'] : '',
                $punch['direction'] === 'out' ? $punch['source'] : ''], ',', '"', '\\');
        }

        // Outside the shift window — kept so the export cannot be quieter than
        // the screen, and marked so nobody adds it to the day's worked minutes.
        foreach ((array) ($day['outside_window'] ?? []) as $punch) {
            fputcsv($out, [$code, $date, 'outside shift window',
                $punch['direction'] === 'in' ? $punch['at'] : '',
                $punch['direction'] === 'out' ? $punch['at'] : '',
                '', '', $punch['direction'] === 'in' ? $punch['source'] : '',
                $punch['direction'] === 'out' ? $punch['source'] : ''], ',', '"', '\\');
        }

        fputcsv($out, [], ',', '"', '\\');
        fputcsv($out, ['status', 'first_in', 'last_out', 'worked', 'break',
            'late', 'early_exit', 'overtime', 'why'], ',', '"', '\\');
        fputcsv($out, [   // durations: under 1 hour in minutes, else hours (owner, 23 Oct)
            (string) ($day['status'] ?? ''),
            (string) ($day['first_in'] ?? ''),
            (string) ($day['last_out'] ?? ''),
            self::hm((int) ($day['worked_minutes'] ?? 0)),
            self::hm((int) ($day['break_minutes'] ?? 0)),
            self::hm((int) ($day['late_minutes'] ?? 0)),
            self::hm((int) ($day['early_exit_minutes'] ?? 0)),
            self::hm((int) ($day['overtime_minutes'] ?? 0)),
            (string) ($day['why'] ?? ''),
        ], ',', '"', '\\');

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * The muster as CSV — one line per employee, one column per date, then totals.
     * Cells carry the status; the figures are in the totals, where a reader wants them.
     */
    /**
     * The grid as CSV. The employee columns come first, in the order Attendance
     * Logs uses (owner, 12 Oct): Emp. Code, Name, Company, Branch, Department,
     * Reporting Manager, Designation. $org = employment id => those cells
     * (app.php's employeeOrgCells); without it the muster's own department and
     * branch are used and the rest are blank.
     *
     * @param array<string, array<string, string>> $org
     */
    public function csv(array $muster, array $org = []): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a buffer for the export.');
        }

        fputcsv($out, array_merge(['Emp. Code', 'Name', 'Company', 'Branch', 'Department', 'Reporting Manager', 'Designation'], $muster['dates'],
            ['worked_minutes', 'late_days', 'late_minutes', 'overtime_minutes', 'needing_regularisation']), ',', '"', '\\');

        foreach ($muster['rows'] as $row) {
            $o = $org[(string) ($row['employment_id'] ?? '')] ?? [];
            $line = [$row['code'], $row['name'], $o['company'] ?? '', ($o['branch'] ?? '') ?: $row['branch'], ($o['department'] ?? '') ?: $row['department'],
                $o['reports_to'] ?? '', $o['designation'] ?? ''];

            foreach ($muster['dates'] as $date) {
                $line[] = self::abbreviation((string) $row['cells'][$date]['status']);
            }

            $t = $row['totals'];
            $line[] = $t['worked_minutes'];
            $line[] = $t['late_days'];
            $line[] = $t['late_minutes'];
            $line[] = $t['overtime_minutes'];
            $line[] = $t['needing_regularisation'];
            fputcsv($out, $line, ',', '"', '\\');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * The letter a status prints as in a cell — DERIVED from the status name,
     * never a table of them, so a tenant who publishes a new status (`WFH`,
     * `TRAINING`) gets a letter without a code change. Two statuses sharing a
     * first letter share a cell letter; the full status is on the cell itself.
     */
    public static function abbreviation(string $status): string
    {
        return match ($status) {
            self::NOT_YET, self::NOT_JOINED => '',
            DayRules::UNCLASSIFIED => '?',
            // Leave reads as L on the grid (owner, 29 Sep) — not OL.
            self::ON_LEAVE => 'L',
            self::UNPAID_LEAVE => 'LOP',
            default => implode('', array_map(
                static fn (string $word): string => mb_substr($word, 0, 1),
                array_slice(explode('_', $status), 0, 2),
            )),
        };
    }

    /** `HH:MM` for a minute count. */
    public static function hm(int $minutes): string
    {
        // UNDER AN HOUR IN MINUTES, AN HOUR OR MORE IN HOURS (owner, 23 Oct) — "45m", "1h 05m"; every
        // duration in the product is printed through here.
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        return $sign . ($minutes < 60 ? $minutes . 'm' : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60));
    }

    /** @param array{date: string, settlement: DaySettlement, classification: array<string, mixed>} $day */
    private static function cell(array $day): array
    {
        /** @var DaySettlement $s */
        $s = $day['settlement'];
        $firstIn = null;
        $lastOut = null;
        $sessionMinutes = 0;

        foreach ($s->sessions as $session) {
            $firstIn ??= $session['in']->at;
            $lastOut = $session['out']->at;
            $sessionMinutes += (int) $session['minutes'];
        }

        // An unpaired in-punch is still a first in; an unpaired out is still a last out.
        foreach ($s->unpaired as $punch) {
            if ($punch->isIn() && ($firstIn === null || $punch->at < $firstIn)) {
                $firstIn = $punch->at;
            }

            if (!$punch->isIn() && ($lastOut === null || $punch->at > $lastOut)) {
                $lastOut = $punch->at;
            }
        }

        // Time between first in and last out that was not inside a session:
        // the breaks. Zero when there is no complete pair to measure between.
        $span = $firstIn !== null && $lastOut !== null && $lastOut > $firstIn
            ? (int) (($lastOut->getTimestamp() - $firstIn->getTimestamp()) / 60) : 0;

        return [
            'status' => (string) ($day['classification']['status'] ?? DayRules::UNCLASSIFIED),
            'why' => (string) ($day['classification']['why'] ?? ''),
            'first_in' => $firstIn?->format('H:i'),
            'last_out' => $lastOut?->format('H:i'),
            'worked_minutes' => $s->workedMinutes,
            'break_minutes' => max(0, $span - $sessionMinutes),
            'late_minutes' => $s->lateMinutes,
            'early_exit_minutes' => $s->earlyExitMinutes,
            'overtime_minutes' => $s->overtimeMinutes,
            'sessions' => count($s->sessions),
            'punches' => count($s->sessions) * 2 + count($s->unpaired),
            'needs_regularisation' => $s->needsRegularisation(),
            'crosses_midnight' => (bool) ($day['crosses_midnight'] ?? false),
            'rating' => null,
            'remarks' => '',
        ];
    }

    /**
     * Whether $date's own shift — an Afternoon shift starting at 14:00 is just
     * as valid a "has not happened yet" as tomorrow is — has not ended yet, so
     * zero punches so far proves nothing. Real time, real shift window, not a
     * date comparison: `$date === $today` alone cannot tell a shift that has
     * not started from one that finished hours ago.
     */
    private static function shiftStillOpen(string $date, string $shiftStart, string $shiftEnd): bool
    {
        $ends = new \DateTimeImmutable("{$date} {$shiftEnd}");

        if ($shiftEnd <= $shiftStart) {
            $ends = $ends->modify('+1 day'); // crosses midnight: today's shift ends tomorrow
        }

        return new \DateTimeImmutable() < $ends;
    }

    private static function blank(string $status, string $why): array
    {
        return ['status' => $status, 'why' => $why, 'first_in' => null, 'last_out' => null,
            'worked_minutes' => 0, 'break_minutes' => 0, 'late_minutes' => 0, 'early_exit_minutes' => 0,
            'overtime_minutes' => 0, 'sessions' => 0, 'punches' => 0, 'needs_regularisation' => false,
            'crosses_midnight' => false, 'rating' => null, 'remarks' => ''];
    }

    /** @return array<string, array{0: string, 1: ?bool}> date => [leave type, paid?], for approved requests touching the range */
    private function leaveDates(string $code, string $from, string $to): array
    {
        if ($this->leave === null) {
            return [];
        }

        $out = [];

        foreach ($this->leave->requestsFor($code) as $request) {
            if (($request['state'] ?? '') !== Leave::APPROVED) {
                continue;
            }

            for ($d = new \DateTimeImmutable((string) $request['from']);
                 $d <= new \DateTimeImmutable((string) $request['to']);
                 $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');

                if ($date >= $from && $date <= $to) {
                    $type = (string) ($request['leave_type'] ?? 'leave');
                    $half = Leave::halfOf($request);
                    $out[$date] = [$type . ($half === null ? '' : ', ' . strtolower(Leave::HALVES[$half]) . ' only'), $this->leave->isPaidType($type)];
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> "code|date" => rating row */
    private function ratingsBetween(string $from, string $to): array
    {
        $out = [];

        foreach ($this->store->all(self::RATINGS) as $row) {
            $date = substr((string) ($row['log_date'] ?? ''), 0, 10);

            if ($date >= $from && $date <= $to) {
                $out[(string) $row['emp_code'] . '|' . $date] = $row;
            }
        }

        return $out;
    }
}
