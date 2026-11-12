<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE ABSCOND ESCALATION — a run of consecutive absent working days, staged.
 *
 * `Absence` tells somebody about ONE day, the morning after. This is the
 * different question the owner asked for: when unexplained absence becomes a
 * RUN, tell an escalating set of people as it crosses each day the tenant has
 * named — Abscond considered, First Warning, Second Warning, Final FnF — with
 * nothing about which day means what, or who is told, written in this file
 * (Law 1: config/unauthorized-absence.json owns every threshold and name).
 *
 * A DAY IS ABSENT BY THE SAME RULE EVERYTHING ELSE USES. Exactly like
 * `Absence`, a day is asked of `Attendance::dayWithoutPunches()` and the
 * published rules (config/attendance-rules.json) — never re-decided here.
 * WEEKLY_OFF and HOLIDAY do not break a run and do not extend it either: a
 * Saturday in the middle of an unexplained gap is neither an absence nor a
 * reason to forget the days either side of it. An approved leave, or a real
 * punch, ends the run outright.
 *
 * THE RUN'S OWN START DATE is what a stage's dedupe key is anchored to, not
 * the date the sweep happens to be run for. Two sweeps for two different
 * dates over the SAME unbroken run must not repeat a stage already sent, and
 * a NEW run — the person came back and then vanished again — must not be
 * silenced by a dedupe key left over from the last one.
 *
 * WHO IS TOLD, PER STAGE: the employee; every person in their reporting
 * chain (`employees.reporting_manager_id`, walked upward, exactly like
 * `ReportingLines` does it — the whole chain, unconditionally, with no
 * designation filter to configure); and every holder of a designation on
 * the tenant's always-cc list (Company Admin / Branch Admin / HR Admin / HR Manager,
 * shipped as the default, never hand-picked mid-code), preferring a holder
 * in the absent employee's own company and falling back tenant-wide when
 * this company has none — the same fallback `AttendanceReport::
 * latePolicyFor()` already uses for a company-scoped setting.
 *
 * ponytail: one date per run, invoked from the screen, exactly like
 * `Absence` — a scheduler entry is the same `Platform\Scheduler\TenantSweep`
 * job named in that class, not reinvented here.
 */
final class UnauthorizedAbsence
{
    /** How far back a run is ever walked, whatever a tenant sets Final FnF to — a safety ceiling, not a policy. */
    private const MAX_LOOKBACK_DAYS = 120;

    public const STAGES = ['abscond', 'first_warning', 'second_warning', 'final_fnf'];

    /** Each employee's own shift window (roster override, then scoped Working Shift) — see runEndingOn(). */
    private ?Shifts $shifts = null;

    public function __construct(
        private readonly string $file,
        private readonly Customisation $customisation,
        private readonly Attendance $attendance,
        private readonly Notifications $notifications,
        private readonly Leave $leave,
        private readonly RowStore $store,
    ) {
    }

    /** @return array<string, mixed> the declared file */
    public function declared(): array
    {
        $declared = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($declared) || !is_array($declared['defaults'] ?? null)
            || !is_string($declared['customisation_key'] ?? null) || !is_array($declared['events'] ?? null)) {
            throw new \RuntimeException("{$this->file} does not declare the unauthorized-absence escalation "
                . '(defaults, customisation_key, events). Without it nothing is swept and nothing is guessed.');
        }

        return $declared;
    }

    /** The live configuration: the tenant's saved choice over the declared defaults. */
    public function config(): array
    {
        $declared = $this->declared();
        $saved = $this->customisation->live()[(string) $declared['customisation_key']] ?? [];
        $config = array_merge($declared['defaults'], array_intersect_key($saved, $declared['defaults']));
        $config['always_cc_designations'] = self::cleanList($config['always_cc_designations'] ?? []);

        return $config;
    }

    /**
     * Save the tenant's choice. The four day-counts must be non-negative and
     * declared in ascending order — a "Second Warning" before "First
     * Warning" is a form somebody filled in wrong, not a schedule this
     * tenant actually wants, and saving it silently would escalate people in
     * the wrong order with nothing on screen to explain why.
     *
     * @return array{ok: bool, why: string, version?: int}
     */
    public function saveConfig(array $choice, string $byId, string $byLabel): array
    {
        $declared = $this->declared();
        $clean = ['enabled' => (bool) ($choice['enabled'] ?? false)];
        $labels = ['abscond_days' => 'Abscond considered for', 'first_warning_day' => 'First Warning at',
            'second_warning_day' => 'Second Warning at', 'final_fnf_day' => 'Final FnF at'];
        $previous = 0;

        foreach ($labels as $key => $label) {
            $days = filter_var($choice[$key] ?? null, FILTER_VALIDATE_INT);

            if ($days === false || $days < 1) {
                return ['ok' => false, 'why' => "\"{$label}\" needs a whole number of days, 1 or more."];
            }

            if ($days < $previous) {
                return ['ok' => false, 'why' => "\"{$label}\" ({$days} days) cannot come before an earlier "
                    . "stage ({$previous} days) — the stages fire in the order they are listed."];
            }

            $clean[$key] = $days;
            $previous = $days;
        }

        $clean['always_cc_designations'] = self::cleanList((array) ($choice['always_cc_designations'] ?? []));

        // RUNS BY ITSELF EVERY DAY AT THIS TIME (owner, 26 Oct), in the company's time zone.
        $runAt = trim((string) ($choice['run_at'] ?? $this->config()['run_at'] ?? ''));

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $runAt) !== 1) {
            return ['ok' => false, 'why' => '"Runs automatically every day at" needs a time, e.g. 09:00.'];
        }

        $clean['run_at'] = $runAt;

        return $this->customisation->saveRaw((string) $declared['customisation_key'], $clean, $byId, $byLabel,
            'Unauthorized absence escalation settings');
    }

    /**
     * Evaluate one date for the given employees. Nothing is written except
     * the notifications, and those only when the escalation is enabled.
     *
     * @param list<array<string, mixed>> $employments
     * @return array{date: string, enabled: bool, evaluated: int,
     *               escalated: list<array{code: string, run_days: int, run_start: string, stage: string}>,
     *               notified: int, already: int}
     */
    public function run(string $date, array $employments, string $shiftStart, string $shiftEnd,
        string $today): array
    {
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false
            || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("'{$date}' is not a date.");
        }

        if ($date >= $today) {
            throw new \InvalidArgumentException("{$date} is not over yet — the same rule `Absence` "
                . 'applies, for the same reason: a day whose punches have not all arrived cannot be '
                . 'called an absence yet.');
        }

        $config = $this->config();
        $declared = $this->declared();
        $events = (array) $declared['events'];
        $byId = [];

        foreach ($employments as $e) {
            $byId[(string) ($e['id'] ?? '')] = $e;
        }

        $stageDays = ['abscond' => (int) $config['abscond_days'], 'first_warning' => (int) $config['first_warning_day'],
            'second_warning' => (int) $config['second_warning_day'], 'final_fnf' => (int) $config['final_fnf_day']];
        $stageLabels = (array) ($declared['stage_labels'] ?? []);
        $escalated = [];
        $notified = 0;
        $already = 0;

        foreach ($employments as $employment) {
            $code = Employment::code($employment);
            $status = strtolower((string) ($employment['status'] ?? 'active'));

            // Empty or missing status counts as active — the same rule Absence::run() applies.
            if ($code === '' || ($status !== '' && $status !== 'active')) {
                continue;
            }

            $runInfo = $this->runEndingOn($employment, $code, $date, $shiftStart, $shiftEnd, Employment::joinedOn($employment));

            if ($runInfo === null) {
                continue;
            }

            [$runDays, $runStart] = $runInfo;
            $recipients = null;

            foreach ($stageDays as $stage => $atDays) {
                if ($runDays < $atDays) {
                    continue;
                }

                $escalated[] = ['code' => $code, 'run_days' => $runDays, 'run_start' => $runStart, 'stage' => $stage];

                if (!$config['enabled']) {
                    continue;
                }

                $values = ['code' => $code, 'name' => (string) ($employment['name'] ?? ''), 'date' => $date,
                    'run_days' => (string) $runDays, 'run_start' => $runStart,
                    'stage_label' => (string) ($stageLabels[$stage] ?? ucfirst(str_replace('_', ' ', $stage)))];
                // Computed once per employee (not per stage): the recipient set
                // does not change as a run crosses each later stage.
                $recipients ??= $this->recipients($employment, $byId, $config);

                foreach ($recipients as $recipientCode => $why) {
                    $event = (string) ($events[$why === 'self' ? 'employee' : ($why === 'cc' ? 'cc' : 'hierarchy')] ?? '');

                    if ($event === '') {
                        continue;
                    }

                    if ($this->notifications->queue($event, $recipientCode,
                        "unauth-absence:{$code}:{$runStart}:{$stage}:{$recipientCode}", $values)) {
                        $notified++;
                    } else {
                        $already++;
                    }
                }
            }
        }

        return ['date' => $date, 'enabled' => (bool) $config['enabled'], 'evaluated' => count($employments),
            'escalated' => $escalated, 'notified' => $notified, 'already' => $already];
    }

    /** The outbox rows this escalation produced, newest first. */
    public function log(int $limit = 200): array
    {
        $events = array_values((array) ($this->declared()['events'] ?? []));
        $rows = array_values(array_filter($this->notifications->queued(10000),
            static fn (array $r): bool => in_array((string) ($r['kind'] ?? ''), $events, true)));

        return array_slice($rows, 0, $limit);
    }

    /**
     * The length, in working days, of the unbroken ABSENT run ending on
     * $date, and the date it started — or null when $date itself is not
     * absent (present, on leave, or not a working day), so there is no run
     * to report at all.
     *
     * @return array{0: int, 1: string}|null [run_days, run_start_date]
     */
    private function runEndingOn(array $employment, string $code, string $date, string $shiftStart, string $shiftEnd,
        string $joinedOn): ?array
    {
        if (!$this->isAbsentWorkingDay($employment, $code, $date, $shiftStart, $shiftEnd, $joinedOn)) {
            return null;
        }

        $runDays = 1;
        $runStart = $date;
        $cursor = $date;

        for ($step = 0; $step < self::MAX_LOOKBACK_DAYS; $step++) {
            $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));

            if ($cursor < $joinedOn) {
                break;
            }

            $verdict = $this->dayVerdict($employment, $code, $cursor, $shiftStart, $shiftEnd);

            if ($verdict === 'ABSENT') {
                $runDays++;
                $runStart = $cursor;

                continue;
            }

            if ($verdict === 'WEEKLY_OFF' || $verdict === 'HOLIDAY') {
                // A non-working day inside the run neither counts nor breaks it —
                // the run's start just keeps walking past it.
                continue;
            }

            // PRESENT, ON_LEAVE, or anything else the rules classified: the run ends here.
            break;
        }

        return [$runDays, $runStart];
    }

    private function isAbsentWorkingDay(array $employment, string $code, string $date, string $shiftStart, string $shiftEnd,
        string $joinedOn): bool
    {
        if ($joinedOn !== '' && $date < $joinedOn) {
            return false;
        }

        return $this->dayVerdict($employment, $code, $date, $shiftStart, $shiftEnd) === 'ABSENT';
    }

    /**
     * The published rule's verdict for one day — PRESENT when there is a
     * punch, else the rule engine's classification. Resolved against THIS
     * EMPLOYEE'S OWN shift window for the date (a roster override, then the
     * scoped Working Shift), not the tenant-wide default a run started with —
     * the same per-employee resolution AttendanceReport::muster() already
     * does; $shiftStart/$shiftEnd here are only the fallback when neither
     * applies.
     */
    private function dayVerdict(array $employment, string $code, string $date, string $shiftStart, string $shiftEnd): string
    {
        $this->shifts ??= new Shifts($this->store);
        [$shiftStart, $shiftEnd] = $this->shifts->times($employment, $shiftStart, $shiftEnd, $date);

        foreach ($this->attendance->settledDays($code, $shiftStart, $shiftEnd) as $day) {
            if ($day['date'] === $date) {
                return 'PRESENT';
            }
        }

        foreach ($this->leave->requestsFor($code) as $request) {
            if (($request['state'] ?? '') === Leave::APPROVED
                && (string) $request['from'] <= $date && (string) $request['to'] >= $date) {
                return 'ON_LEAVE';
            }
        }

        $day = $this->attendance->dayWithoutPunches($code, $date, $shiftStart, $shiftEnd);

        return (string) ($day['classification']['status'] ?? DayRules::UNCLASSIFIED);
    }

    /**
     * Every recipient for one escalated employee: their own code ('self'),
     * every member of their reporting chain, unconditionally ('hierarchy'
     * — the whole chain, no designation filter: that was the only thing a
     * tenant could ever configure here, and the screen no longer offers
     * it), and every always_cc_designations holder, company-preferred
     * ('cc'). A code is never listed twice — the FIRST reason it qualifies
     * is the one kept, so nobody gets two copies of the same stage because
     * they are both the manager and on the CC list.
     *
     * @return array<string, string> employee code => 'self'|'hierarchy'|'cc'
     */
    private function recipients(array $employment, array $byId, array $config): array
    {
        $out = [Employment::code($employment) => 'self'];

        $managerId = (string) ($employment['reporting_manager_id'] ?? '');
        $depth = 0;

        while ($managerId !== '' && isset($byId[$managerId]) && $depth < 20) {
            $manager = $byId[$managerId];
            $managerCode = Employment::code($manager);

            if ($managerCode !== '' && !isset($out[$managerCode])) {
                $out[$managerCode] = 'hierarchy';
            }

            $managerId = (string) ($manager['reporting_manager_id'] ?? '');
            $depth++;
        }

        if ((array) $config['always_cc_designations'] !== []) {
            $wanted = array_map(static fn (string $d): string => strtolower($d), (array) $config['always_cc_designations']);
            $companyId = (string) ($employment['employer_company_id'] ?? '');
            $sameCompany = [];
            $anyCompany = [];

            // ponytail: a full employee scan per escalated person, fine at the
            // scale a "who is CC'd" list runs at (a handful of admin-designation
            // holders); index by designation once per run() call if a tenant's
            // headcount and escalation volume ever make this measurable.
            foreach ($this->activeEmployees() as $row) {
                if (!in_array(strtolower($this->designationOf($row)), $wanted, true)) {
                    continue;
                }

                $rowCode = Employment::code($row);

                if ($rowCode === '' || isset($out[$rowCode])) {
                    continue;
                }

                if ($companyId !== '' && (string) ($row['employer_company_id'] ?? '') === $companyId) {
                    $sameCompany[$rowCode] = true;
                } else {
                    $anyCompany[$rowCode] = true;
                }
            }

            // Prefer a holder in the absent employee's own company; only when
            // this company has none does the always-cc reach tenant-wide —
            // the same fallback AttendanceReport::latePolicyFor() already uses.
            foreach (($sameCompany !== [] ? $sameCompany : $anyCompany) as $ccCode => $_) {
                $out[(string) $ccCode] = 'cc';
            }
        }

        return $out;
    }

    /** THE REGISTER FIRST (designation_id → Organization Setup's name), the legacy text column as fallback. */
    private function designationOf(array $employment): string
    {
        $id = (string) ($employment['designation_id'] ?? '');

        if ($id !== '') {
            foreach ($this->liveRows('designations') as $row) {
                if ((string) ($row['id'] ?? '') === $id) {
                    return trim((string) ($row['name'] ?? ''));
                }
            }
        }

        return trim((string) ($employment['designation'] ?? ''));
    }

    /** Active employees, retired rows excluded — same rule public/app.php's own liveRows() applies. */
    private function activeEmployees(): array
    {
        // Empty or missing status counts as active — the same rule Absence::run() applies.
        return array_values(array_filter($this->liveRows('employees'), static function (array $r): bool {
            $status = strtolower((string) ($r['status'] ?? 'active'));

            return $status === '' || $status === 'active';
        }));
    }

    /** A table's live rows, or [] when this installation does not have it yet — liveRows()'s own rule, unavailable to a surface class. */
    private function liveRows(string $table): array
    {
        try {
            return array_values(array_filter($this->store->all($table),
                static fn (array $r): bool => !Modules::isRetired($r)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<string> trimmed, de-duplicated, order kept */
    private static function cleanList(array $list): array
    {
        $out = [];

        foreach ($list as $v) {
            $v = trim((string) $v);

            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
