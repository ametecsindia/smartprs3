<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE MORNING-AFTER ABSENCE SWEEP.
 *
 * SmartPRS 2's `AbsenceService` (F10) evaluates yesterday for every active
 * employee and notifies the ones who were absent — no punch, no approved leave,
 * not a holiday, not a weekly off — with a link to regularise. This is that
 * sweep, with the one change that matters: IT DOES NOT DECIDE WHAT ABSENT IS.
 * SmartPRS 2 re-implements the classification here (a fourth copy, after the
 * report, the register and the payroll generator) with its own `working_days`
 * list; here a day with no punches is put through `Attendance::dayWithoutPunches()`
 * and the published rules say ABSENT or they do not. The sweep, the muster and the
 * payslip cannot disagree about who was absent, because they are one call.
 *
 * WHO IS TOLD IS CONFIGURATION, versioned through `Customisation` under the key
 * `config/absence.json` names. It ships OFF. Every message goes through
 * `Notifications`, which dedupes on the employee and the date, so running the
 * sweep twice for the same morning tells nobody twice — and the "log" SmartPRS 2
 * keeps in `notification_log` is simply the outbox filtered to these two events.
 *
 * ponytail: one date per run, invoked from the screen. A scheduler entry that
 * runs it at eleven is `Platform\Scheduler\TenantSweep`'s job when there is one.
 */
final class Absence
{
    public function __construct(
        private readonly string $file,
        private readonly Customisation $customisation,
        private readonly Attendance $attendance,
        private readonly Notifications $notifications,
        private readonly ?Leave $leave = null,
        // Resolves each employee's OWN shift window (roster override, then
        // scoped Working Shift) instead of the one tenant-wide default passed
        // into run() — the same Shifts::times() AttendanceReport::muster()
        // already calls per employee. Null (a caller that predates this) keeps
        // the old tenant-wide-default behaviour exactly as before.
        private readonly ?Shifts $shifts = null,
    ) {
    }

    /** @return array<string, mixed> the declared file */
    public function declared(): array
    {
        $declared = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($declared) || !is_array($declared['defaults'] ?? null)
            || !is_string($declared['customisation_key'] ?? null)) {
            throw new \RuntimeException("{$this->file} does not declare the absence sweep (defaults, "
                . 'customisation_key). Without it nothing is swept and nothing is guessed.');
        }

        return $declared;
    }

    /** The live configuration: the tenant's saved choice over the declared defaults. */
    public function config(): array
    {
        $declared = $this->declared();
        $saved = $this->customisation->live()[(string) $declared['customisation_key']] ?? [];

        return array_merge($declared['defaults'], array_intersect_key($saved, $declared['defaults']));
    }

    /** @return array{ok: bool, why: string, version?: int} */
    public function saveConfig(array $choice, string $byId, string $byLabel): array
    {
        $declared = $this->declared();
        $clean = [];

        foreach (array_keys($declared['defaults']) as $key) {
            $clean[$key] = (bool) ($choice[$key] ?? false);
        }

        return $this->customisation->saveRaw((string) $declared['customisation_key'], $clean, $byId,
            $byLabel, 'Absence notification settings');
    }

    /**
     * Sweep one date for the given employees. Nothing is written except the
     * notifications, and those only when the sweep is enabled.
     *
     * @param list<array<string, mixed>> $employments
     * @return array{date: string, enabled: bool, evaluated: int, absent: list<string>,
     *               notified: int, already: int, skipped: array<string, int>}
     */
    public function run(string $date, array $employments, string $shiftStart, string $shiftEnd,
        string $today, bool $notify = true): array
    {
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date) === false
            || \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("'{$date}' is not a date.");
        }

        if ($date >= $today) {
            throw new \InvalidArgumentException("{$date} is not over yet. The sweep is for a day whose "
                . 'punches have all arrived — telling somebody at 11:00 they are absent today is how '
                . 'SmartPRS 2 earned its grace-hour setting.');
        }

        $config = $this->config();
        $declared = $this->declared();
        $events = (array) ($declared['events'] ?? []);
        $byId = [];

        foreach ($employments as $e) {
            $byId[(string) ($e['id'] ?? '')] = $e;
        }

        $absent = [];
        $notified = 0;
        $already = 0;
        $skipped = ['present' => 0, 'on_leave' => 0, 'non_working' => 0, 'no_code' => 0, 'inactive' => 0];

        foreach ($employments as $employment) {
            $code = Employment::code($employment);

            if ($code === '') {
                $skipped['no_code']++;

                continue;
            }

            $status = strtolower((string) ($employment['status'] ?? 'active'));

            if ($status !== '' && $status !== 'active') {
                $skipped['inactive']++;

                continue;
            }

            [$empStart, $empEnd] = $this->shifts === null ? [$shiftStart, $shiftEnd]
                : $this->shifts->times($employment, $shiftStart, $shiftEnd, $date);

            if ($this->punchedOn($code, $date, $empStart, $empEnd)) {
                $skipped['present']++;

                continue;
            }

            if ($this->onLeave($code, $date)) {
                $skipped['on_leave']++;

                continue;
            }

            $day = $this->attendance->dayWithoutPunches($code, $date, $empStart, $empEnd);
            $verdict = (string) ($day['classification']['status'] ?? DayRules::UNCLASSIFIED);

            if ($verdict !== 'ABSENT') {
                // WEEKLY_OFF, HOLIDAY, UNCLASSIFIED — none of them is an absence,
                // and UNCLASSIFIED is reported as such on the muster rather than
                // mailed to somebody as a fact.
                $skipped['non_working']++;

                continue;
            }

            $absent[] = $code;

            // $notify false: only WHO was absent (the Alert Email Drafts absence alert asks this).
            if (!$config['enabled'] || !$notify) {
                continue;
            }

            $values = ['code' => $code, 'name' => (string) ($employment['name'] ?? ''), 'date' => $date,
                'status' => $verdict];

            if ($config['notify_employee']) {
                if ($this->notifications->queue((string) $events['employee'], $code,
                    "absence:{$code}:{$date}", $values)) {
                    $notified++;
                } else {
                    $already++;
                }
            }

            $managerId = (string) ($employment['reporting_manager_id'] ?? '');

            if ($config['notify_manager'] && $managerId !== '' && isset($byId[$managerId])) {
                $manager = Employment::code($byId[$managerId]);

                if ($manager !== '' && $this->notifications->queue((string) $events['manager'], $manager,
                    "absence:{$code}:{$date}:manager", $values)) {
                    $notified++;
                }
            }
        }

        return ['date' => $date, 'enabled' => (bool) $config['enabled'], 'evaluated' => count($employments),
            'absent' => $absent, 'notified' => $notified, 'already' => $already, 'skipped' => $skipped];
    }

    /** The outbox rows this sweep produced, newest first. */
    public function log(int $limit = 200): array
    {
        $events = array_values((array) ($this->declared()['events'] ?? []));
        $rows = array_values(array_filter($this->notifications->queued(10000),
            static fn (array $r): bool => in_array((string) ($r['kind'] ?? ''), $events, true)));

        return array_slice($rows, 0, $limit);
    }

    private function punchedOn(string $code, string $date, string $shiftStart, string $shiftEnd): bool
    {
        foreach ($this->attendance->settledDays($code, $shiftStart, $shiftEnd) as $day) {
            if ($day['date'] === $date) {
                return true;
            }
        }

        return false;
    }

    private function onLeave(string $code, string $date): bool
    {
        if ($this->leave === null) {
            return false;
        }

        foreach ($this->leave->requestsFor($code) as $request) {
            if (($request['state'] ?? '') === Leave::APPROVED
                && (string) $request['from'] <= $date && (string) $request['to'] >= $date) {
                return true;
            }
        }

        return false;
    }
}
