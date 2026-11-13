<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Platform\Attendance\ShiftWindow;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Rational;

/**
 * PUNCHES IN, PAYABLE DAYS OUT.
 *
 * The screen records raw punches and nothing else. Every derived figure — worked
 * minutes, lateness, overtime, whether a day counts as payable — comes from
 * `Platform\Attendance\DaySettlement`, which is the same code the Phase 4 golden
 * matrix drives.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO.
 *
 * It does not store the settled figures. A stored `worked_minutes` is right on
 * the day it is written and drifts from the punches the moment a correction
 * lands; deriving it on read means a regularised punch changes the payslip
 * without anyone remembering to re-run a job.
 *
 * It does not decide the shift by the punch's calendar date. The attendance day
 * is a WINDOW labelled by the date the shift STARTED, so a 22:00–06:00 night is
 * one day and not two — which is the case SmartPRS 2 gets structurally wrong,
 * splitting one night across two payroll months when the 30th is a month end.
 */
final class Attendance
{
    // The PORTED table. A second punches table beside attendance_logs would be
    // two homes for the same data, which is the defect this rebuild removes.
    private const COLLECTION = 'attendance_logs';

    /** @var array<string, list<array<string, mixed>>>|null employment code => its punches, read once per request */
    private ?array $punchesByEmployment = null;

    /**
     * The grace used when no calendar was given. NAMED rather than written into
     * the constructor call, so that reading this class tells you a default is
     * being applied — a literal five in an argument list looks like the policy
     * itself, which is how it survived being one for months.
     */
    private const GRACE_WITHOUT_A_CALENDAR = 5;

    /**
     * PUNCHES THAT COUNT ON A DAY OFF (owner, 14 Oct). On a weekly off, a holiday, or a
     * day of approved full-day leave, a punch from a device or the web app is HELD — kept
     * on record, never counted — until the reporting line approves it as a regularization
     * (which adds a `regularised` punch). Punches entered by someone with authority
     * (Manual Entry, an approved bulk upload) count as before.
     */
    public const APPROVED_SOURCES = ['regularised', 'manual', 'bulk'];

    private ?Leave $leaveForHolds = null;
    /** @var array<string, array<string, true>> code => full-day approved leave dates */
    private array $leaveDays = [];

    /** Give the leave register, so a punch on a day of approved leave is held too. */
    public function holdPunchesOnLeave(Leave $leave): void
    {
        $this->leaveForHolds = $leave;
        $this->leaveDays = [];
    }

    /**
     * The punches HELD on a calendar date (a day off and not yet approved) — kept on record,
     * not counted. Empty on an ordinary working day.
     *
     * @return list<array<string, mixed>>
     */
    public function heldPunches(string $employmentId, string $date): array
    {
        if ($this->offDayReason($employmentId, $date) === null) {
            return [];
        }

        return array_values(array_filter($this->punchesFor($employmentId), static fn (array $p): bool => substr((string) $p['at'], 0, 10) === $date
            && !in_array((string) $p['source'], self::APPROVED_SOURCES, true)));
    }

    /** Why a punch on this day needs approval: 'weekly off', 'holiday', 'leave' — or null when it does not. */
    public function offDayReason(string $employmentId, string $day): ?string
    {
        if ($this->calendar !== null) {
            if ($this->calendar->isWeeklyOff($day, $this->employmentFor($employmentId), $this->designationRoles())) {
                return 'weekly off';
            }

            if (isset($this->calendar->holidaysForEmployment($this->employmentFor($employmentId), $this->companyNames(), $this->designationRoles())[$day])) {
                return 'holiday';
            }
        }

        if ($this->leaveForHolds !== null) {
            if (!isset($this->leaveDays[$employmentId])) {
                $this->leaveDays[$employmentId] = [];

                foreach ($this->leaveForHolds->requestsFor($employmentId) as $r) {
                    // A HALF-DAY leave leaves the other half to work: its punches count.
                    if (($r['state'] ?? '') !== Leave::APPROVED || Leave::halfOf($r) !== null) {
                        continue;
                    }

                    for ($d = (string) $r['from']; $d !== '' && $d <= (string) $r['to']; $d = date('Y-m-d', (int) strtotime($d . ' +1 day'))) {
                        $this->leaveDays[$employmentId][$d] = true;
                    }
                }
            }

            if (isset($this->leaveDays[$employmentId][$day])) {
                return 'leave';
            }
        }

        return null;
    }

    /** @var array<string, array<string, mixed>>|null employee code => row, read once */
    private ?array $employeeByCode = null;
    private ?Shifts $shifts = null;

    /** @var array<string, string>|null company id => name, read once — for holiday scope matching */
    private ?array $companyNames = null;

    /** @var array<string, string>|null designation id => role name, read once — for holiday scope matching */
    private ?array $designationRoles = null;

    /** The employee row an employment code names, or `[]` when nobody by that code exists here. */
    private function employmentFor(string $employmentId): array
    {
        if ($this->employeeByCode === null) {
            $this->employeeByCode = [];

            try {
                foreach ($this->store->all('employees') as $e) {
                    $this->employeeByCode[Employment::code($e)] = $e;
                }
            } catch (\Throwable) {
                $this->employeeByCode = []; // no employees table on this store: nobody has a shift row
            }
        }

        return $this->employeeByCode[$employmentId] ?? [];
    }

    /** @return array<string, string> company id => name — same shape app.php's orgNames() builds, for holiday scope matching */
    private function companyNames(): array
    {
        if ($this->companyNames !== null) {
            return $this->companyNames;
        }

        $names = [];

        try {
            foreach ($this->store->all('companies') as $c) {
                $names[(string) ($c['id'] ?? '')] = trim((string) ($c['name'] ?? ''));
            }
        } catch (\Throwable) {
            // gate:allow-swallow no companies table on a file store: company scope matches nobody
        }

        return $this->companyNames = $names;
    }

    /**
     * @return array<string, string> designation id => its Role — Role/
     * Designation Setup's own Role column, never `employees.role` (the RBAC
     * access tier). Same source app.php's orgNames() reads.
     */
    private function designationRoles(): array
    {
        if ($this->designationRoles !== null) {
            return $this->designationRoles;
        }

        $roles = [];

        try {
            foreach ($this->store->all('designations') as $d) {
                $roles[(string) ($d['id'] ?? '')] = trim((string) ($d['role'] ?? ''));
            }
        } catch (\Throwable) {
            // gate:allow-swallow no designations table on a file store: role scope matches nobody
        }

        return $this->designationRoles = $roles;
    }

    /**
     * THE WORKING SHIFT ROW an employee is on (Organization Setup → Working
     * Shifts, by scope), for its Full Day / Half Day hours and Break Budget.
     * Null when no row covers the person or the installation has no shifts table.
     */
    private function shiftRowFor(string $employmentId, ?string $day = null): ?array
    {
        $e = $this->employmentFor($employmentId);

        if ($e === []) {
            return null;
        }

        $this->shifts ??= new Shifts($this->store);

        return $this->shifts->for($e, $day);
    }

    /**
     * THE SHIFT THIS PERSON WORKED ON THIS DATE (12 Oct): a Roster row for the
     * date wins, then the scoped Working Shift, then the times the caller
     * passed. Every caller used to settle a whole month against ONE shift, so a
     * rostered afternoon came out 4½ hours late with an orphan out-punch and
     * payroll paid it as half a day.
     *
     * @return array{0: string, 1: string}
     */
    private function timesOn(string $employmentId, string $day, string $defaultStart, string $defaultEnd): array
    {
        $row = $this->shiftRowFor($employmentId, $day);

        if ($row === null || (string) ($row['start_time'] ?? '') === '' || (string) ($row['end_time'] ?? '') === '') {
            return [$defaultStart, $defaultEnd];
        }

        return [substr((string) $row['start_time'], 0, 5), substr((string) $row['end_time'], 0, 5)];
    }

    /**
     * Minutes after the shift start before an arrival is marked LATE: the
     * shift's own Grace (Working Shifts) when it declares one, else the
     * calendar's. The shift row is the more specific setting; ignoring it
     * marked people late that the Shifts screen said were on time.
     */
    private function graceOn(string $employmentId, string $day): int
    {
        $grace = trim((string) ($this->shiftRowFor($employmentId, $day)['grace_min'] ?? ''));

        return preg_match('/^\d+$/', $grace) === 1 ? (int) $grace
            : ($this->calendar?->graceMinutes() ?? self::GRACE_WITHOUT_A_CALENDAR);
    }

    public function __construct(
        private readonly RowStore $store,
        // WHAT A DAY MEANS IS NOT DECIDED HERE ANY MORE. Optional because the
        // punch-recording half of this class has nothing to do with
        // classification and every tool that only records should not need a
        // rules file to do it — but a null one produces the word UNCLASSIFIED on
        // the screen, never a silent PRESENT. See DayRules.
        private readonly ?DayRules $rules = null,
        // THE WORKING CALENDAR, so a rule can ask whether a day was a weekly off
        // or a holiday. config/attendance-rules.json declares WEEKLY_OFF and
        // HOLIDAY as worth a full day of pay — and until this argument NOTHING
        // could ever produce either status, because the two symbols that decide
        // it were never supplied. A status priced in the policy that no rule can
        // reach is the same defect as a permission in the catalogue that no role
        // holds: it reads as configured and it is unreachable, and every check
        // that walks past it sees a table with an entry in it.
        private readonly ?Calendar $calendar = null,
        // What a day EARNS, as opposed to what it is worth. Optional for the
        // same reason the others are; absent, `earnings()` reports that nothing
        // was evaluated rather than that nothing was earned.
        private readonly ?EarnRules $earnRules = null,
        // What a day COSTS, as opposed to what it earns. Absent, `penalties()`
        // reports that nothing was evaluated rather than that nothing was owed.
        private readonly ?PenaltyRules $penaltyRules = null,
        // Organization Configuration → Late Login Policy. When a row applies to
        // the employee it decides the late cut instead of $penaltyRules (7 Oct).
        private readonly ?LatePolicy $latePolicy = null,
    ) {
    }

    /** What a status is worth in halves of a day under the published rules — null when unpriced. */
    public function worthOf(string $status): ?int
    {
        return $this->rules?->payableHalves($status);
    }

    /**
     * What each settled day in a range was penalised, and why.
     *
     * THE COUNTER IS ASSEMBLED HERE, once for the range, and handed to every
     * day's evaluation under the canonical key the rule asked for (§5.9). The
     * engine never queries anything — that is what makes a trace reproducible —
     * so a rule counting late arrivals over a payroll month reads a number this
     * method computed, and a key it did not fill is a named ERR_AGG_UNAVAILABLE
     * rather than a zero that quietly forgives the month.
     *
     * @return list<array{date: string, halves: int, penalties: list<array{days: string,
     *     reason: string, rule: string}>, why: ?string, trace: ?string}>
     */
    public function penalties(string $employmentId, string $from, string $to,
        string $shiftStart, string $shiftEnd, array $leaveDates = []): array
    {
        if ($this->penaltyRules === null && $this->latePolicy === null) {
            return [];
        }

        // A DATE ON APPROVED LEAVE (whole or half) IS NOT A LATE MARK (12 Oct):
        // somebody on first-half leave walks in at mid-shift by arrangement.
        // Counting it made the NEXT genuine late the chargeable one.
        $days = array_values(array_filter(
            $this->settledDays($employmentId, $shiftStart, $shiftEnd, $from, $to),
            static fn (array $d): bool => $d['date'] >= $from && $d['date'] <= $to && !isset($leaveDates[$d['date']]),
        ));

        $employee = null;

        foreach ($this->latePolicy === null ? [] : $this->store->all('employees') as $e) {
            if (Employment::code($e) === $employmentId) {
                $employee = $e;
                break;
            }
        }

        $chosen = $employee === null ? null : $this->latePolicy?->for($employee);

        if ($chosen !== null) {
            return LatePolicy::penalties($chosen, array_map(static fn (array $d): array => [
                'date' => (string) $d['date'],
                // Minutes past the shift start: the policy's grace is the only grace.
                'late' => (int) ($d['late_raw'] ?? $d['settlement']->lateMinutes),
                'off' => in_array((string) ($d['classification']['status'] ?? ''), ['WEEKLY_OFF', 'HOLIDAY'], true),
            ], $days));
        }

        if ($this->penaltyRules === null) {
            return [];
        }

        $policy = $this->penaltyRules->policy();
        $grace = (int) ($policy['late_grace_min'] ?? 0);

        // THE COUNT OVER THE WHOLE RANGE, not up to each day. The rule says
        // "three late arrivals in a payroll month cost half a day", and counting
        // only the days BEFORE each one would penalise the third, fourth and
        // fifth while sparing the first two — which is a different rule, and one
        // nobody wrote down. Whether a month's third late mark reaches back and
        // penalises the first is a real policy question; this reading is the one
        // the blueprint's own §12 library states, and the count is on the screen
        // beside the answer so it can be argued with.
        // THE COUNT SO FAR, per day (7 Oct): "the third and every later late
        // arrival" — so the 1st and 2nd are free. The month total used to be
        // handed to every day, which charged the 1st and 2nd too once a month
        // reached three.
        $lateCount = 0;
        $countSoFar = [];

        foreach ($days as $day) {
            if ((int) ($day['late_raw'] ?? $day['settlement']->lateMinutes) > $grace) {
                $lateCount++;
            }

            $countSoFar[$day['date']] = $lateCount;
        }

        // A PREPARED COUNTER CARRIES ITS OWN SPX TYPE. It is a call answer, not
        // a symbol the table declares, so a bare int arrives with no type and
        // the rule fails ERR_TYPE — which the engine reports rather than reading
        // as false, and which is exactly the behaviour that made this a
        // five-minute fix instead of a month of forgiven late marks.
        $preparedKeys = $this->penaltyRules->preparedKeys();

        $policyValues = [];

        foreach ($policy as $name => $value) {
            if (is_int($value) || is_string($value)) {
                $policyValues["policy.value('{$name}')"] = $value;
            }
        }

        $out = [];

        foreach ($days as $day) {
            $values = array_diff_key($day['classification']['values'] ?? [], ['__subject' => null]);
            // late_grace_min is minutes past the SHIFT START (see the rule's own
            // note); the marking grace must not be taken off first.
            isset($day['late_raw']) && $values['attendance.late_minutes'] = (int) $day['late_raw'];
            $prepared = array_fill_keys($preparedKeys, Value::number(Rational::of($countSoFar[$day['date']] ?? 0)));
            $answer = $this->penaltyRules->penalisedOn($employmentId, $day['date'],
                $values + $prepared + $policyValues);

            if ($answer['halves'] === 0 && $answer['why'] === null) {
                continue;
            }

            $out[] = ['date' => $day['date']] + $answer;
        }

        return $out;
    }

    /**
     * What each settled day in a range earned, and why.
     *
     * READ ONLY. Nothing here writes; `postEarnings()` does that and is invoked
     * by a person pressing a button. Classification runs on every page load, and
     * a screen that credited a comp-off while rendering would credit one per
     * refresh — the accrual bug this codebase has already fixed once, and much
     * harder to notice the second time because nobody is watching a comp-off
     * balance the way they watch a leave balance.
     *
     * @return list<array{date: string, comp_offs: list<array{days: string, reason: string,
     *     rule: string}>, overtime: list<array{minutes: int, rule: string}>, why: ?string,
     *     trace: ?string}>
     */
    public function earnings(string $employmentId, string $from, string $to,
        string $shiftStart, string $shiftEnd): array
    {
        if ($this->earnRules === null) {
            return [];
        }

        $earned = [];

        foreach ($this->settledDays($employmentId, $shiftStart, $shiftEnd) as $day) {
            if ($day['date'] < $from || $day['date'] > $to) {
                continue;
            }

            $answer = $this->earnRules->earnedOn($employmentId, $day['date'],
                // `__subject` is this class's own carrier for the employee and
                // is not a symbol in anybody's table. Left in, SPX would refuse
                // the whole evaluation for an unknown name.
                array_diff_key($day['classification']['values'] ?? [], ['__subject' => null]));

            if ($answer['comp_offs'] === [] && $answer['overtime'] === [] && $answer['why'] === null) {
                continue;
            }

            $earned[] = ['date' => $day['date']] + $answer;
        }

        return $earned;
    }

    /**
     * Credit what those days earned to the leave ledger. Once.
     *
     * Idempotent by the day's own identity — `comp-off:2026-09-06` — so pressing
     * the button twice, or a month overlapping a month already posted, adds
     * nothing. That is not a nicety: the whole reason the credit is a ledger
     * entry rather than a computed figure is that somebody can dispute it, and a
     * balance that grows on a double-click is one nobody can.
     *
     * @return list<string> what was posted, for the screen to show
     */
    public function postEarnings(Leave $leave, string $employmentId, string $from, string $to,
        string $shiftStart, string $shiftEnd): array
    {
        $type = $this->earnRules?->compOffType();

        if ($type === null) {
            throw new \RuntimeException(
                'No comp-off leave type is declared in config/attendance-earn-rules.json, so there is '
                . 'nowhere to credit an earned day. Refused rather than posted to a guess: a credit '
                . 'against a type nobody declared is a day somebody earned and cannot find.'
            );
        }

        $posted = [];

        foreach ($this->earnings($employmentId, $from, $to, $shiftStart, $shiftEnd) as $day) {
            foreach ($day['comp_offs'] as $compOff) {
                $done = $leave->creditEarned($employmentId, $type, $compOff['days'],
                    $compOff['reason'] . ' (' . $day['date'] . ', rule ' . $compOff['rule'] . ')',
                    'comp-off:' . $day['date'], $day['date']);

                if ($done) {
                    $posted[] = $day['date'] . ': ' . $compOff['days'] . ' day(s) '
                        . $type . ' — ' . $compOff['reason'];
                }
            }
        }

        return $posted;
    }

    public function record(string $employmentId, string $at, string $direction,
        string $source = 'manual', ?string $reference = null): bool
    {
        // Validated here because this is the trust boundary. A punch stored with
        // an unparseable instant is a row that breaks a settlement months later,
        // by which time nobody knows which device sent it.
        $instant = new \DateTimeImmutable($at);

        if (!in_array($direction, [Punch::IN, Punch::OUT], true)) {
            throw new \InvalidArgumentException("'{$direction}' is not a punch direction.");
        }

        // THE SCHEMA'S NAMES. This wrote `employment_id`, `at`, `source_reference`
        // and `recorded_at`; `attendance_logs` has `emp_code`, `punch_at`,
        // `external_id` and `created_at`, and had none of the four. Recording a
        // punch against MySQL was refused by the store, which is the store doing
        // its job — nothing had ever asked it.
        // THE SAME PUNCH TWICE IS ONE PUNCH (owner, 16 Oct): same employee, same
        // instant, same direction is already on file — nothing is written and the
        // caller is told, so a double-clicked Add or an approval of a punch somebody
        // already typed in never doubles the day's hours.
        if ($this->has($employmentId, $at, $direction)) {
            return false;
        }

        $this->punchesByEmployment = null;   // a write invalidates the per-request read
        $this->store->put(self::COLLECTION, [
            'emp_code' => $employmentId,
            // The DATE the punch carries, not the attendance day it settles into
            // — that window is derived on read in `settledDays()`, and storing a
            // day here would be a second answer that drifts from the first.
            'log_date' => $instant->format('Y-m-d'),
            'punch_at' => $instant->format('Y-m-d H:i:s'),
            'direction' => $direction,
            'source' => $source,
            'external_id' => $reference,
        ]);

        return true;
    }

    /** Is this exact punch (employee, instant to the second, direction) already on file? */
    public function has(string $employmentId, string $at, string $direction): bool
    {
        $key = (new \DateTimeImmutable($at))->format('Y-m-d H:i:s');

        foreach ($this->punchesFor($employmentId) as $p) {
            if (substr((string) $p['at'], 0, 19) === $key && (string) ($p['direction'] ?? '') === $direction) {
                return true;
            }
        }

        return false;
    }

    /**
     * A ported `attendance_logs` row in the shape this class settles.
     *
     * Same defect as `leaves`: written before the port, and the file store
     * agreed with every wrong name. Read-only, and the old names win when a row
     * already carries them, so fixtures and pre-port rows are not lost.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function ported(array $row): array
    {
        $row['employment_id'] ??= (string) ($row['emp_code'] ?? '');
        $row['at'] ??= (string) ($row['punch_at'] ?? '');
        $row['source'] = (string) ($row['source'] ?? 'unknown');
        $row['source_reference'] ??= $row['external_id'] ?? null;

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function punchesFor(string $employmentId): array
    {
        // ONE READ OF THE TABLE PER REQUEST, grouped by employment. A muster over
        // forty people read the whole punch table forty times — a year of punches
        // made every attendance screen a thirty-second wait. Dropped on write.
        if ($this->punchesByEmployment === null) {
            $this->punchesByEmployment = [];

            foreach ($this->store->all(self::COLLECTION) as $row) {
                $p = self::ported($row);
                $this->punchesByEmployment[(string) $p['employment_id']][] = $p;
            }

            foreach ($this->punchesByEmployment as &$rows) {
                // Two punches in the same second (a quick IN then OUT) keep the order they were made in.
                usort($rows, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']) ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0)));
            }

            unset($rows);
        }

        return $this->punchesByEmployment[$employmentId] ?? [];
    }

    /**
     * Settle every day this employee has punches for.
     *
     * @return list<array{date: string, settlement: DaySettlement, crosses_midnight: bool}>
     */
    public function settledDays(string $employmentId, string $shiftStart, string $shiftEnd, string $from = '', string $to = ''): array
    {
        $punches = $this->punchesFor($employmentId);

        if ($from !== '' || $to !== '') {
            // Only the window asked for (a day either side, so a night shift's
            // out-punch stays with its night): settling a whole year to read one month is the other half of the wait.
            $lo = $from === '' ? '' : date('Y-m-d', strtotime($from . ' -1 day'));
            $hi = $to === '' ? '' : date('Y-m-d', strtotime($to . ' +1 day'));
            $punches = array_values(array_filter($punches, static fn (array $p): bool => ($lo === '' || substr((string) $p['at'], 0, 10) >= $lo) && ($hi === '' || substr((string) $p['at'], 0, 10) <= $hi)));
        }

        if ($punches === []) {
            return [];
        }

        // Group by the date the SHIFT started, not the date the punch carries.
        // An 06:22 out-punch belongs to the night before when the shift began at
        // 22:00, and putting it with its own calendar date is how one night
        // becomes two attendance days.
        // Each date's OWN shift (roster first): whether yesterday's window ran
        // past midnight is a question about yesterday's shift.
        $byDay = [];

        foreach ($punches as $punch) {
            $instant = new \DateTimeImmutable($punch['at']);
            $day = $instant->format('Y-m-d');
            $yesterday = $instant->modify('-1 day')->format('Y-m-d');
            [$prevStart, $prevEnd] = $this->timesOn($employmentId, $yesterday, $shiftStart, $shiftEnd);

            if ($prevEnd <= $prevStart && $instant->format('H:i') < $prevStart) {
                // Before the shift start time on its own date: it belongs to the
                // window that opened yesterday.
                $day = $yesterday;
            }

            $byDay[$day][] = $punch;
        }

        ksort($byDay);
        $days = [];

        foreach ($byDay as $day => $dayPunches) {
            // A DAY OFF COUNTS ONLY APPROVED PUNCHES (owner, 14 Oct) — see APPROVED_SOURCES.
            if ($this->offDayReason($employmentId, (string) $day) !== null) {
                $dayPunches = array_values(array_filter($dayPunches, static fn (array $p): bool => in_array((string) $p['source'], self::APPROVED_SOURCES, true)));

                if ($dayPunches === []) {
                    continue;
                }
            }

            [$dayStart, $dayEnd] = $this->timesOn($employmentId, (string) $day, $shiftStart, $shiftEnd);
            $start = new \DateTimeImmutable("{$day} {$dayStart}");
            $end = new \DateTimeImmutable("{$day} {$dayEnd}");

            if ($end <= $start) {
                $end = $end->modify('+1 day');
            }

            // GRACE IS A POLICY NUMBER AND IT WAS A LITERAL. Five minutes, in
            // PHP, deciding whether somebody is marked late — which in most
            // Indian attendance policies is the first step towards a day of loss
            // of pay. It comes from the calendar now, where a customer can
            // change it without a release.
            $window = self::windowFor((string) $day, $start, $end, $this->graceOn($employmentId, (string) $day));

            $dayPunchObjects = array_map(
                static fn (array $p): Punch => new Punch($p['employment_id'],
                    new \DateTimeImmutable($p['at']), $p['direction'], $p['source']),
                $dayPunches,
            );
            $settlement = DaySettlement::settle($window, $employmentId, $dayPunchObjects);
            // MINUTES PAST THE SHIFT START, grace not taken off: what a Late Login
            // Policy or a penalty rule compares with ITS OWN grace (12 Oct).
            $firstIn = null;

            foreach ($dayPunchObjects as $po) {
                $po->direction === Punch::IN && ($firstIn === null || $po->at < $firstIn->at) && $firstIn = $po;
            }

            $days[] = [
                'date' => $day,
                'late_raw' => $firstIn === null ? 0 : max(0, $window->latenessMinutes($firstIn)),
                'settlement' => $settlement,
                'crosses_midnight' => $window->crossesMidnight(),
                'classification' => $this->classify($day, $window, $settlement, $dayPunches),
            ];
        }

        return $days;
    }

    /**
     * THE WINDOW A DAY'S PUNCHES ARE READ IN (owner, 13 Nov 2026: "the punch … should be considered for attendance … late
     * login based on first punch for the day"). A day shift (start and end on the same date) takes EVERY punch of its
     * date — a QR scan at 06:30 or 23:55 is that day's punch, and the first IN decides late login. Before, a fixed window
     * of 2 h before / 4 h after the shift dropped such punches, so the day showed no First In. A night shift keeps the
     * standard tolerances, because its punches run into the next date.
     */
    public static function windowFor(string $day, \DateTimeImmutable $start, \DateTimeImmutable $end, int $grace): ShiftWindow
    {
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            $midnight = new \DateTimeImmutable("{$day} 00:00:00");
            $before = intdiv($start->getTimestamp() - $midnight->getTimestamp(), 60);
            $after = intdiv($midnight->modify('+1 day')->getTimestamp() - $end->getTimestamp() + 59, 60); // ponytail: only this date's punches reach the window

            return new ShiftWindow('GEN', new \DateTimeImmutable($day), $start, $end,
                openMinutesBefore: max(0, $before), closeMinutesAfter: max(0, $after), graceMinutes: $grace);
        }

        return new ShiftWindow('GEN', new \DateTimeImmutable($day), $start, $end, graceMinutes: $grace);
    }

    /**
     * A day this employee has NO punches for, classified by the same rules.
     *
     * `settledDays()` only yields days that have a punch, so the ABSENT rule in
     * config/attendance-rules.json — "a day with no punch in either direction" —
     * could never fire from it, and a muster listing every calendar day had to
     * invent the answer for the gaps. It does not: it asks here, and the same
     * engine that says WEEKLY_OFF for a Sunday with punches says it for a Sunday
     * without them. The shape returned is the shape `settledDays()` returns, so a
     * caller can mix the two without caring which was which.
     *
     * @return array{date: string, settlement: DaySettlement, crosses_midnight: bool,
     *               classification: array<string, mixed>}
     */
    public function dayWithoutPunches(string $employmentId, string $day, string $shiftStart,
        string $shiftEnd): array
    {
        [$shiftStart, $shiftEnd] = $this->timesOn($employmentId, $day, $shiftStart, $shiftEnd);
        $start = new \DateTimeImmutable("{$day} {$shiftStart}");
        $end = new \DateTimeImmutable("{$day} {$shiftEnd}");

        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        $window = self::windowFor($day, $start, $end, $this->graceOn($employmentId, $day));
        $settlement = DaySettlement::settle($window, $employmentId, []);

        return [
            'date' => $day,
            'settlement' => $settlement,
            'crosses_midnight' => $window->crossesMidnight(),
            'classification' => $this->classify($day, $window, $settlement, []),
        ];
    }

    /**
     * Hand one settled day to the rule engine and get a status with its reason.
     *
     * The values below are the decision point's own SPX symbols, and they are
     * assembled here because the engine holds no repository by design — it reads
     * exactly what the caller prepared and nothing else, which is what makes the
     * simulator safe and the trace reproducible.
     *
     * `shift.full_day_minutes` and `shift.half_day_minutes` come from the shift's
     * scheduled length rather than from a number in the rules file, so a six-hour
     * shift and a nine-hour shift need one rule between them and not two.
     *
     * @param list<array<string, mixed>> $dayPunches
     * @return array{status: string, rule: ?string, why: string, trace: ?string, flags: list<string>}
     */
    private function classify(string $day, ShiftWindow $window, DaySettlement $settlement,
        array $dayPunches): array
    {
        if ($this->rules === null) {
            return ['status' => DayRules::UNCLASSIFIED, 'rule' => null, 'trace' => null, 'flags' => [],
                'values' => [],
                'why' => 'This screen was built without a rules file, so no day here has been classified. '
                    . 'That is reported rather than defaulted: a day nobody classified is not a present day.'];
        }

        // NO CALENDAR, NO CLASSIFICATION — refused here rather than asked.
        //
        // The published rules ask whether the day was a weekly off or a holiday.
        // Passing null for those, or omitting them, both produce the same thing:
        // SPX answers UNKNOWN, the two rules that read them do not match, and
        // every rule BELOW them fires normally. So a Sunday with no punches
        // comes out ABSENT rather than WEEKLY_OFF — a full day of pay, gone,
        // with nothing anywhere saying the holiday list was never loaded.
        //
        // The same shape cost every day of every leave span full price on the
        // charge rules an hour ago, in the opposite direction. A question the
        // caller cannot supply the inputs for is not a question to ask.
        if ($this->calendar === null) {
            return ['status' => DayRules::UNCLASSIFIED, 'rule' => null, 'trace' => null, 'flags' => [],
                'values' => [],
                'why' => 'No working calendar was given, so whether this day was a weekly off or a '
                    . 'holiday is not known — and the published rules decide the status from exactly '
                    . 'that. Reported rather than guessed: classifying a Sunday as ABSENT because '
                    . 'nobody loaded the holiday list is a day of pay taken on no evidence.'];
        }

        $scheduled = $window->scheduledMinutes();

        // WHAT A FULL DAY TAKES. The shift row (Organization Setup → Working
        // Shifts) may declare Full Day (hours), Half Day (hours) and a Break
        // Budget (minutes): a declared full-day figure wins; else the scheduled
        // length less the break budget, so a 45-minute lunch inside a 45-minute
        // budget does not turn a nine-hour day into a half day. Nothing declared:
        // the scheduled length, as before. Half is declared or derived from full.
        $shiftRow = $this->shiftRowFor($settlement->employmentId, $day);
        $fullHours = (string) ($shiftRow['full_day_hours'] ?? '');
        $halfHours = (string) ($shiftRow['half_day_hours'] ?? '');
        $breakBudget = (int) ($shiftRow['break_budget'] ?? 0);
        $fullMinutes = is_numeric($fullHours) && $fullHours > 0 ? self::hoursToMinutes($fullHours) : max(0, $scheduled - $breakBudget);
        $halfMinutes = is_numeric($halfHours) && $halfHours > 0 ? self::hoursToMinutes($halfHours) : intdiv($fullMinutes, 2);

        // Real values, always: the no-calendar case returned above. A holiday
        // is looked up FOR THIS EMPLOYMENT — a holiday scoped to Pune branch
        // only is not a holiday for Hyderabad, so the same day of the same
        // calendar can classify differently for two employees.
        $values = [
            'day.is_weekly_off' => $this->calendar->isWeeklyOff($day, $this->employmentFor($settlement->employmentId), $this->designationRoles()),
            'day.is_holiday' => isset($this->calendar->holidaysForEmployment(
                $this->employmentFor($settlement->employmentId), $this->companyNames(), $this->designationRoles(),
            )[$day]),
            '__subject' => $settlement->employmentId,
            'day.date' => $day,
            'day.weekday' => strtoupper((new \DateTimeImmutable($day))->format('D')),
            'shift.code' => $window->shiftCode,
            'shift.full_day_minutes' => $fullMinutes,
            'shift.half_day_minutes' => $halfMinutes,
            'attendance.worked_minutes' => $settlement->workedMinutes,
            'attendance.late_minutes' => $settlement->lateMinutes,
            'attendance.early_exit_minutes' => $settlement->earlyExitMinutes,
            'attendance.shortfall_minutes' => $settlement->shortfallMinutes,
            'attendance.ot_minutes' => $settlement->overtimeMinutes,
            'attendance.punch_count' => count($dayPunches),
            'attendance.session_count' => count($settlement->sessions),
            'attendance.has_in' => self::hasDirection($dayPunches, Punch::IN),
            'attendance.has_out' => self::hasDirection($dayPunches, Punch::OUT),
            // A day carrying a hand-entered punch. Not currently read by any
            // rule; declared because "was this touched by a human" is the first
            // thing a tenant writing a review rule asks for, and the symbol
            // costs one line here against a code change there.
            'attendance.is_corrected' => self::hasSource($dayPunches, 'manual'),
        ];

        // THE VALUES TRAVEL WITH THE ANSWER. `earnings()` evaluates a SECOND
        // decision point over the same day, and assembling the symbols twice is
        // how the two decision points come to disagree about what the day was —
        // one of them reading a calendar the other did not get, and no test
        // anywhere comparing the two.
        return $this->rules->classify($day, $values) + ['values' => $values];
    }

    /** @param list<array<string, mixed>> $punches */
    /** "8.5" hours → 510 minutes, by string arithmetic — no float. */
    private static function hoursToMinutes(string $hours): int
    {
        [$whole, $frac] = array_pad(explode('.', $hours, 2), 2, '');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return (int) $whole * 60 + intdiv((int) $frac * 60, 100);
    }

    private static function hasDirection(array $punches, string $direction): bool
    {
        foreach ($punches as $punch) {
            if (($punch['direction'] ?? '') === $direction) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $punches */
    private static function hasSource(array $punches, string $source): bool
    {
        foreach ($punches as $punch) {
            if (($punch['source'] ?? '') === $source) {
                return true;
            }
        }

        return false;
    }

    /**
     * Payable days, derived — not stored, not typed in.
     *
     * "A day with any worked time counts" lived here, in PHP, with a comment
     * admitting it was policy. It is now whatever `config/attendance-rules.json`
     * says a status is worth, which is the same answer `PayrollPeriod` gets from
     * the same file — and that matters more than either answer being right: two
     * screens computing the same fact two ways is how they come to disagree, and
     * the one somebody trusts is whichever they opened first.
     *
     * `unpriced` is a REPORTED state, not a zero. A status the policy does not
     * price counts for nothing and says so, because a summary card quietly
     * getting smaller is the version of this nobody notices.
     *
     * @return array{payable: string, settled: int, needing_attention: int, unpriced: int}
     */
    public function payableDays(string $employmentId, string $shiftStart, string $shiftEnd): array
    {
        $halves = 0;
        $attention = 0;
        $unpriced = 0;
        $days = $this->settledDays($employmentId, $shiftStart, $shiftEnd);

        foreach ($days as $day) {
            $worth = $this->rules?->payableHalves((string) $day['classification']['status']);

            if ($worth === null) {
                $unpriced++;
            } else {
                $halves += $worth;
            }

            if ($day['settlement']->needsRegularisation()) {
                $attention++;
            }
        }

        return [
            // A STRING, because it can be a half and this is a display figure.
            // The exact value for arithmetic is PayrollPeriod's Rational; two
            // types for two jobs, rather than one type doing neither well.
            'payable' => Rational::of($halves, 2)->toDecimalString(),
            'settled' => count($days),
            'needing_attention' => $attention,
            'unpriced' => $unpriced,
        ];
    }
}
