<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * A DAY WITH AN UNPAIRED PUNCH HAS A WAY OUT.
 *
 * The register reports days needing regularisation and refuses to pay them. That
 * is right — an unpaired punch is not a short day, and paying one pays a figure
 * nobody has confirmed. But a report with no remedy is a dead end, and a dead end
 * in a payroll system is where somebody starts editing the punch table.
 *
 * THE ORIGINAL PUNCH IS NEVER EDITED. A regularisation adds a NEW punch carrying
 * `source = regularised`, the request it came from, and who approved it. Editing
 * the device's punch destroys the only evidence of what the device actually saw,
 * and the difference between "the reader missed the exit" and "the exit time was
 * changed" is the entire question when it is disputed.
 *
 * IT IS REQUESTED AND THEN DECIDED, by two acts, so the person who benefits from
 * a corrected time is not the person who approves it. Self-approval is not
 * blocked here — this build has one role that can do both — and that is a
 * STATED ceiling rather than a silent one.
 *
 * A REJECTED REQUEST ADDS NO PUNCH AT ALL. Not a zero-minute one, not a
 * cancelled one: the day stays unsettled and keeps saying so, because a day that
 * looks resolved and pays nothing is worse than one that admits it is unresolved.
 *
 * THE APPROVAL CHAIN, once a second role existed to need one: a request goes to
 * the employee's own Reporting Manager and Team Lead (`recipientsFor()`), never
 * the whole tenant, and the approvers' queue is scoped the same way
 * (`visibleTo()`) — an administrator-tier account sees everything through the
 * SAME `Scope` every other screen already uses, a manager sees their direct
 * reports, and this class adds the one thing `Scope` does not know: a Team
 * Lead's own team, from `teams.leader_id`, never the free-text
 * `employees.team_leader` column.
 *
 * ponytail: no per-reason policy, no limit on how many days back.
 */
final class Regularisation
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    private const COLLECTION = 'attendance_corrections';   // the ported table

    public function __construct(
        private readonly RowStore $store,
        private readonly Attendance $attendance,
    ) {
    }

    /**
     * The people a regularization request for this employment goes to: their
     * Reporting Manager (`reporting_manager_id`, an `employees.id` FK) and
     * their Team Lead (`team_id` → `teams.leader_id`, another `employees.id`
     * FK) — never the free-text `employees.team_leader` column, which is
     * whatever string somebody typed and not a routable identity, and never
     * the whole tenant. Deduplicated: a manager who is also the team lead is
     * told once.
     *
     * @return list<string> employment codes
     */
    public function recipientsFor(array $employment): array
    {
        $byId = [];

        try {
            foreach ($this->store->all('employees') as $e) {
                $byId[(string) ($e['id'] ?? '')] = $e;
            }
        } catch (\Throwable) {
            return []; // no employees table on this store: nobody to route to
        }

        $codes = [];
        $managerId = (string) ($employment['reporting_manager_id'] ?? '');

        if ($managerId !== '' && isset($byId[$managerId])) {
            $code = Employment::code($byId[$managerId]);
            $code !== '' && $codes[$code] = true;
        }

        $teamId = (string) ($employment['team_id'] ?? '');

        if ($teamId !== '') {
            try {
                foreach ($this->store->all('teams') as $team) {
                    if ((string) ($team['id'] ?? '') !== $teamId) {
                        continue;
                    }

                    $leaderId = (string) ($team['leader_id'] ?? '');

                    if ($leaderId !== '' && isset($byId[$leaderId])) {
                        $code = Employment::code($byId[$leaderId]);
                        $code !== '' && $codes[$code] = true;
                    }

                    break;
                }
            } catch (\Throwable) {
                // gate:allow-swallow no teams table on this store: manager alone still routes
            }
        }

        return array_keys($codes);
    }

    /**
     * Every employee whose regularization requests this account may see and
     * decide: whatever `Scope` already reaches (self; direct reports for a
     * manager-tier role; the whole tenant for an administrator-tier role —
     * the SAME rule every other screen uses), UNION anybody whose Team Lead
     * this account is. `Scope` has no notion of team leadership at all — it
     * is about the `reporting_manager_id` graph only — so that half is added
     * here rather than inside `Scope` itself.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleTo(Scope $scope, bool $mayReadOthers): array
    {
        $all = $this->store->all('employees');
        $visible = $scope->only($all, $mayReadOthers);
        $ownId = $scope->own();

        if ($ownId === null) {
            return $visible;
        }

        $byCode = [];

        foreach ($visible as $e) {
            $byCode[Employment::code($e)] = $e;
        }

        foreach ($this->hierarchyBelow($ownId) as $e) {
            $byCode[Employment::code($e)] = $e;
        }

        return array_values($byCode);
    }

    /**
     * EVERYONE BELOW THIS EMPLOYEE, ALL THE WAY DOWN (21 Sep 2026, owner:
     * "their hierarchy, their immediate team members, or the team reporting to
     * their hierarchy team leads"): people whose Reporting Manager they are,
     * members of teams they lead — and then the same again for each of those,
     * until nobody new turns up. A reporting cycle in the data cannot loop:
     * each person is visited once. Not themselves.
     *
     * @return list<array<string, mixed>>
     */
    public function hierarchyBelow(string $employeeId): array
    {
        $all = [];

        foreach ($this->store->all('employees') as $e) {
            $all[(string) ($e['id'] ?? '')] = $e;
        }

        $teamsLedBy = [];

        try {
            foreach ($this->store->all('teams') as $team) {
                $leader = (string) ($team['leader_id'] ?? '');
                $leader !== '' && $teamsLedBy[$leader][] = (string) ($team['id'] ?? '');
            }
        } catch (\Throwable) {
            $teamsLedBy = []; // no teams table on this store: the reporting-manager line alone
        }

        $seen = [$employeeId => true];
        $queue = [$employeeId];
        $out = [];

        while ($queue !== []) {
            $boss = (string) array_shift($queue); // numeric ids come back as int array keys
            $teams = $teamsLedBy[$boss] ?? [];

            foreach ($all as $id => $e) {
                if (isset($seen[$id])) {
                    continue;
                }

                if ((string) ($e['reporting_manager_id'] ?? '') === $boss || in_array((string) ($e['team_id'] ?? ''), $teams, true)) {
                    $seen[$id] = true;
                    $queue[] = $id;
                    $out[] = $e;
                }
            }
        }

        return $out;
    }

    /**
     * Every request in the tenant, newest first — the queue an approver works from.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $state = null): array
    {
        $rows = array_map(self::ported(...), $this->store->all(self::COLLECTION));

        if ($state !== null) {
            $rows = array_filter($rows, static fn (array $r): bool => (string) $r['state'] === $state);
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['requested_at'] ?? ''), (string) ($a['requested_at'] ?? '')));

        return $rows;
    }

    /**
     * HELD PUNCHES RAISE THEIR OWN REQUEST (owner, 14 Oct). A device / import / API punch on a
     * W.OFF, holiday or day of approved full-day leave since $from is held until approved; this
     * raises the regularization for it — the day's first held IN and last held OUT — unless a
     * request for that day and direction already exists in ANY state (a rejected one stays
     * rejected). Returns what it raised, with the employee row, so the caller can notify.
     *
     * @param list<array<string, mixed>> $employees
     * @return list<array{request: array<string, mixed>, employee: array<string, mixed>, why: string}>
     */
    public function raiseForHeldPunches(array $employees, string $from): array
    {
        $raised = [];
        $asked = [];

        foreach (array_map(self::ported(...), $this->store->all(self::COLLECTION)) as $r) {
            $asked[$r['employment_id'] . '|' . $r['date'] . '|' . $r['direction']] = true;
        }

        foreach ($employees as $e) {
            $code = Employment::code($e);
            $byDay = [];

            foreach ($code === '' ? [] : $this->attendance->punchesFor($code) as $p) {
                $day = substr((string) $p['at'], 0, 10);
                $day >= $from && $day <= date('Y-m-d') && $byDay[$day][] = $p;
            }

            foreach ($byDay as $day => $punches) {
                $why = $this->attendance->offDayReason($code, (string) $day);

                if ($why === null) {
                    continue;
                }

                $held = array_values(array_filter($punches, static fn (array $p): bool => !in_array((string) $p['source'], Attendance::APPROVED_SOURCES, true)));
                $ins = array_values(array_filter($held, static fn (array $p): bool => $p['direction'] === 'in'));
                $outs = array_values(array_filter($held, static fn (array $p): bool => $p['direction'] === 'out'));

                foreach (['in' => $ins[0] ?? null, 'out' => $outs === [] ? null : $outs[count($outs) - 1]] as $dir => $p) {
                    if ($p === null || isset($asked["{$code}|{$day}|{$dir}"])) {
                        continue;
                    }

                    $reason = 'Worked on ' . ($why === 'leave' ? 'a day of approved leave' : 'a ' . $why) . ' — punched ' . $dir
                        . ' (' . (string) $p['source'] . '); raised automatically for approval';
                    $r = $this->request($code, (string) $day, (string) $p['at'], $dir, $reason);
                    $asked["{$code}|{$day}|{$dir}"] = true;
                    $r['ok'] && $raised[] = ['request' => $r['request'], 'employee' => $e, 'why' => $reason];
                }
            }
        }

        return $raised;
    }

    /** @return list<array<string, mixed>> */
    public function forEmployment(string $employmentId): array
    {
        return array_values(array_filter(
            array_map(self::ported(...), $this->store->all(self::COLLECTION)),
            static fn (array $r): bool => (string) $r['employment_id'] === $employmentId));
    }

    /**
     * A ported `attendance_corrections` row in the shape this class works in.
     *
     * Same defect as `leaves` and `attendance_logs`: this class was written
     * against `employment_id`, `date`, `at`, `direction`, `state` and `note`,
     * and the ported table has `emp_code`, `log_date`, `req_in`/`req_out`,
     * `sub_type`, `status` and `remarks`. Read-only; the old names win when a
     * row already carries them, so pre-port rows and fixtures still read.
     *
     * The requested time is split across two TIME columns by direction, which is
     * how SmartPRS 2 shapes it — an in-correction and an out-correction for the
     * same day are one row there. This build raises one request per missing
     * punch, so it fills the one column its direction names and reads back
     * whichever is set.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function ported(array $row): array
    {
        $row['employment_id'] ??= (string) ($row['emp_code'] ?? '');
        $row['date'] ??= (string) ($row['log_date'] ?? '');
        $row['direction'] ??= (string) ($row['sub_type'] ?? '');
        $row['state'] ??= (string) ($row['status'] ?? self::PENDING);
        $row['note'] ??= $row['remarks'] ?? null;
        $row['requested_at'] ??= (string) ($row['created_at'] ?? '');
        $row['decided_by'] ??= null;
        $row['decided_at'] ??= null;
        $time = (string) ($row['req_in'] ?? '') !== ''
            ? (string) $row['req_in'] : (string) ($row['req_out'] ?? '');
        $row['at'] ??= trim($row['date'] . ' ' . $time);

        return $row;
    }

    /**
     * The same row in the SCHEMA's names, which is the only shape a write may
     * take: `put()` refuses a column the table does not have, and that refusal
     * is what found this defect in the first place.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function row(array $request): array
    {
        $time = substr((string) $request['at'], 11, 8);

        return array_filter([
            'id' => $request['id'] ?? null,
            'emp_code' => $request['employment_id'],
            'log_date' => $request['date'],
            'sub_type' => $request['direction'],
            // The requested time in the column its direction names. `orig_*` and
            // `app_*` are left alone: what the device recorded and what was
            // applied are different facts from what was asked for.
            'req_in' => $request['direction'] === 'in' ? $time : null,
            'req_out' => $request['direction'] === 'out' ? $time : null,
            'reason' => $request['reason'],
            'status' => $request['state'],
            'decided_by' => $request['decided_by'],
            'decided_at' => $request['decided_at'],
            'remarks' => $request['note'],
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Ask for a missing punch to be added.
     *
     * @return array{ok: bool, why: string, request?: array<string, mixed>}
     */
    public function request(string $employmentId, string $date, string $at, string $direction,
        string $reason): array
    {
        if (trim($reason) === '') {
            return ['ok' => false, 'why' => 'A regularisation with no reason is a time change nobody '
                . 'can account for. The reason is what makes it a record rather than an edit.'];
        }

        if (!in_array($direction, ['in', 'out'], true)) {
            return ['ok' => false, 'why' => "'{$direction}' is not a punch direction."];
        }

        try {
            $instant = new \DateTimeImmutable($at);
        } catch (\Throwable) {
            return ['ok' => false, 'why' => "'{$at}' is not a time this system can read."];
        }

        foreach ($this->forEmployment($employmentId) as $existing) {
            if ((string) $existing['date'] === $date
                && (string) $existing['direction'] === $direction
                && (string) $existing['state'] === self::PENDING) {
                return ['ok' => false, 'why' => "A {$direction} regularisation for {$date} is already "
                    . 'pending. Two open requests for the same missing punch end as two punches, and '
                    . 'the day settles to twice the hours nobody worked.'];
            }
        }

        $request = [
            'employment_id' => $employmentId,
            'date' => $date,
            'at' => $instant->format('Y-m-d H:i:s'),
            'direction' => $direction,
            'reason' => $reason,
            'state' => self::PENDING,
            'requested_at' => date('c'),
            'decided_by' => null,
            'decided_at' => null,
            'note' => null,
        ];

        $request = self::ported($this->store->put(self::COLLECTION, self::row($request)));

        return ['ok' => true, 'request' => $request, 'why' => 'Requested. Nothing has changed on the '
            . 'attendance record yet — a request is not a punch, and the day still reports that it '
            . 'needs regularisation until somebody decides.'];
    }

    /**
     * A punch typed in on Manual Entry, kept on the Regularization register as
     * already APPROVED by whoever typed it (owner, 16 Oct: it should show there).
     * The punch itself was recorded by the caller — this writes no punch.
     */
    public function logManual(string $employmentId, string $at, string $direction, string $by): string
    {
        $instant = new \DateTimeImmutable($at);

        return (string) $this->store->put(self::COLLECTION, self::row([
            'employment_id' => $employmentId, 'date' => $instant->format('Y-m-d'),
            'at' => $instant->format('Y-m-d H:i:s'), 'direction' => $direction,
            'reason' => 'Manual entry', 'state' => self::APPROVED,
            'decided_by' => $by, 'decided_at' => date('c'), 'note' => null,
        ]))['id'];
    }

    /**
     * Approve or reject. Only an approval touches attendance, and only by ADDING.
     *
     * @return array{ok: bool, why: string, request?: array<string, mixed>}
     */
    public function decide(string $requestId, bool $approve, string $by, string $note): array
    {
        $raw = $this->store->find(self::COLLECTION, $requestId);

        if ($raw === null) {
            return ['ok' => false, 'why' => 'No such regularisation request.'];
        }

        // Ported on the way in: this read `state` off the raw row, which on
        // MySQL is `status` — so a decided request looked pending and could be
        // decided twice, adding the punch twice.
        $request = self::ported($raw);

        if ((string) $request['state'] !== self::PENDING) {
            return ['ok' => false, 'why' => 'That request was already ' . $request['state']
                . '. Deciding it twice would add the punch twice.'];
        }

        $request['state'] = $approve ? self::APPROVED : self::REJECTED;
        $request['decided_by'] = $by;
        $request['decided_at'] = date('c');
        $request['note'] = $note;

        if ($approve) {
            // ADDED, never an edit of the device's punch. What the reader saw
            // and what a person decided it should have been are two different
            // facts, and both are kept.
            $this->attendance->record((string) $request['employment_id'], (string) $request['at'],
                (string) $request['direction'], 'regularised', (string) $request['id']);
        }

        $this->store->put(self::COLLECTION, self::row($request));

        return ['ok' => true, 'request' => $request, 'why' => $approve
            ? 'Approved. A NEW punch was added carrying source=regularised and the id of this '
                . 'request — the original punch is untouched, so "the reader missed the exit" and '
                . '"the exit time was changed" remain distinguishable.'
            : 'Rejected. NO punch was added — not a zero-minute one, not a cancelled one. The day '
                . 'stays unsettled and keeps saying so, because a day that looks resolved and pays '
                . 'nothing is worse than one that admits it is unresolved.'];
    }
}
