<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Lifecycle\EmploymentTimeline;

/**
 * THE PROBATION BOARD: who ends when, confirm, extend, and the history of both.
 *
 * SmartPRS 2's `ProbationService` (F7/F6) does this off `employees.employment_stage
 * = 'probation'`, an end date derived from doj + a configured number of months
 * (or `probation_end` as an override), CONFIRM that clears the stage and EXTEND
 * that moves the date, with history in `probation_events`. All of that is kept —
 * the columns and the table are ported — with one change that is the point:
 *
 * CONFIRMATION IS A TIMELINE EVENT. It is recorded through `Surface\Timeline`, so
 * the same `EmploymentTimeline` that decides whether a payslip exists and what
 * the full-and-final covers knows the day somebody was confirmed. SmartPRS 2
 * keeps it as a flag on the employee row, which is why its payroll cannot say
 * when statutory deductions should have resumed. An employee with no events
 * yet has its joining recorded first, from `doj`, because a timeline cannot
 * start with a confirmation — and that is said on the screen, not done quietly.
 *
 * Nothing here sends a reminder: the milestones are declared so the board can
 * bucket by them (overdue, due within N days), and a notification transport is
 * still not configured in this build. What would be sent is what the board shows.
 */
final class Probation
{
    public const EVENTS = 'probation_events';   // the ported table

    /**
     * Where a company's own settings live INSIDE the one probation record.
     * A key per company (`probation.company.7`) would have been a customisation
     * record per company, each versioned separately, and no way to read "what
     * does this tenant do" without knowing every company id first.
     */
    public const PER_COMPANY = 'companies';

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly Customisation $customisation,
        private readonly Timeline $timeline,
    ) {
    }

    public function declared(): array
    {
        $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

        if (!is_array($d) || !is_array($d['defaults'] ?? null) || !isset($d['defaults']['default_months'])) {
            throw new \RuntimeException("{$this->file} does not declare probation defaults.");
        }

        return $d;
    }

    /**
     * PROBATION IS A COMPANY'S RULE, NOT A TENANT'S (owner, 23 Sep: "The
     * probation settings to be part of Company table ... have it part of each
     * company added"). A group running three companies runs three probation
     * policies — six months in one, three in another — and asking a single
     * number to be right for all of them is asking somebody to confirm an
     * employee on the wrong day.
     *
     * THREE LAYERS, EACH ONLY OVERRIDING WHAT IT SETS: the declared defaults in
     * config/probation.json, then the tenant's own saved answer, then the
     * company's. A company that has never been opened here inherits, silently
     * and correctly — and it keeps inheriting when the default moves, which is
     * the whole difference between inheriting and having the same numbers
     * typed in. Nothing is written for a company until somebody chooses
     * something different for it.
     *
     * THE COMPANY'S LAYER LIVES ON THE COMPANY ROW (owner, 23 Sep: "The
     * probation settings to be part of Company table ... as we have other
     * settings of the companies including time zone"), in the columns added by
     * 2026_09_23_000100_probation_on_companies. That is what lets the same six
     * settings be edited on the company's own form beside Time zone AND in the
     * boxes under the Companies list without becoming two answers: there is one
     * place they are stored, and both screens write it.
     *
     * THE OLD OVERRIDE MAP IS STILL READ, and only read. An install that saved
     * per-company settings before the migration ran keeps them until somebody
     * saves that company again, at which point they land in the columns. It is
     * a fallback with an end, not a second home.
     *
     * @param ?string $companyId `employees.employer_company_id`; null or unknown
     *     means the tenant-wide answer, which is also the inherited one.
     */
    public function config(?string $companyId = null): array
    {
        $d = $this->declared();
        $record = (array) ($this->customisation->live()[(string) $d['customisation_key']] ?? []);
        $saved = array_intersect_key($record, $d['defaults']);

        if ($companyId !== null && $companyId !== '') {
            $own = $this->companySettings($companyId)
                ?? (array) (($record[self::PER_COMPANY] ?? [])[$companyId] ?? []);
            $saved = array_merge($saved, array_intersect_key($own, $d['defaults']));
        }

        $config = array_merge($d['defaults'], $saved);
        $config['default_months'] = max(0, (int) $config['default_months']);
        $config['default_days'] = max(0, min(30, (int) ($config['default_days'] ?? 0)));
        $config['reminder_days'] = array_values(array_unique(array_map('intval', (array) $config['reminder_days'])));
        rsort($config['reminder_days']);

        return $config;
    }

    /**
     * THE COMPANY'S OWN SIX, READ OFF ITS ROW — or null when it has none and is
     * therefore inheriting.
     *
     * `probation_months` IS THE MARKER, deliberately, rather than six
     * independent overrides: the form saves all six together, so months set
     * means this company has a policy and months blank means it follows the
     * default. Six separate overrides would allow a company with its own
     * reminder days and the tenant's months — a combination nobody chose and
     * nobody could see on the screen.
     *
     * NULL IS NOT ZERO here, and the distinction is the point: a null
     * `probation_notify_hr` on a company that HAS its own months means "the
     * form saved it off", while a null on a company with no months means
     * "inheriting". Only the first is reached, because the second returns null
     * from this method before the flags are looked at.
     *
     * @return ?array<string, mixed>
     */
    private function companySettings(string $companyId): ?array
    {
        if ($companyId === '') {
            return null;
        }

        $row = $this->store->find('companies', $companyId);

        if ($row === null || trim((string) ($row['probation_months'] ?? '')) === '') {
            return null;
        }

        return [
            'default_months' => (int) $row['probation_months'],
            'default_days' => (int) ($row['probation_days'] ?? 0),
            'reminder_days' => array_values(array_filter(array_map('intval', array_filter(
                preg_split('/[\s,]+/', trim((string) ($row['probation_reminder_days'] ?? ''))) ?: [],
                static fn (string $piece): bool => preg_match('/^\d+$/', $piece) === 1)),
                static fn (int $day): bool => $day >= 0 && $day <= 365)),
            'notify_hr' => (bool) ($row['probation_notify_hr'] ?? false),
            'notify_manager' => (bool) ($row['probation_notify_manager'] ?? false),
            'congratulate_employee' => (bool) ($row['probation_congratulate'] ?? false),
        ];
    }

    /**
     * STATUTORY HEADS DURING PROBATION (owner, 8 Oct: "The statutory deductions,
     * earnings like PF, ESIC, etc. are not considered during Probation. Some
     * companies may not consider this rule ... add this criteria with all the
     * Statutory fields to be selected as applicable or not applicable during
     * probation").
     *
     * head => true when the head APPLIES while the employee is on probation.
     * Three layers like the rest of probation: config/probation.json's
     * `statutory_during_probation`, the tenant default, the company's own. Kept
     * in the probation customisation record (not company columns), so no
     * migration is needed.
     *
     * @param list<string> $heads every declared head (StatutoryApplicability::heads())
     * @return array<string, bool>
     */
    public function statutoryDuringProbation(array $heads, ?string $companyId = null): array
    {
        $declared = (array) ($this->declared()['statutory_during_probation'] ?? []);
        $record = (array) ($this->customisation->live()[(string) $this->declared()['customisation_key']] ?? []);
        $tenant = (array) ($record['statutory_during_probation'] ?? []);
        $own = $companyId === null || $companyId === ''
            ? [] : (array) (((array) ($record['statutory_during_probation_companies'] ?? []))[$companyId] ?? []);
        $out = [];

        foreach ($heads as $head) {
            $out[$head] = (bool) ($own[$head] ?? $tenant[$head] ?? $declared[$head] ?? false);
        }

        return $out;
    }

    /** Has this company chosen its own statutory-during-probation answers? */
    public function statutoryOverridden(string $companyId): bool
    {
        $record = (array) ($this->customisation->live()[(string) $this->declared()['customisation_key']] ?? []);

        return $companyId !== '' && isset(((array) ($record['statutory_during_probation_companies'] ?? []))[$companyId]);
    }

    /**
     * Save which heads apply during probation — the tenant default when
     * $companyId is null/'', else that company's own. $clear drops the company's
     * own so it follows the default again. Read, merge, write: the rest of the
     * probation record is carried through untouched.
     *
     * @param array<string, bool> $applies head => applies during probation
     * @return array{ok: bool, why: string}
     */
    public function saveStatutory(array $applies, string $byId, string $byLabel, ?string $companyId = null, bool $clear = false): array
    {
        $key = (string) $this->declared()['customisation_key'];
        $record = (array) ($this->customisation->live()[$key] ?? []);
        $applies = array_map(static fn ($v): bool => (bool) $v, $applies);

        if ($companyId === null || $companyId === '') {
            $record['statutory_during_probation'] = $applies;
        } else {
            $per = (array) ($record['statutory_during_probation_companies'] ?? []);

            if ($clear) {
                unset($per[$companyId]);
            } else {
                $per[$companyId] = $applies;
            }

            $record['statutory_during_probation_companies'] = $per;
        }

        $on = array_keys(array_filter($applies));
        $saved = $this->customisation->saveRaw($key, $record, $byId, $byLabel, 'Statutory during probation'
            . ($companyId ? ' (one company)' : ' (default)'));

        return ($saved['ok'] ?? false)
            ? ['ok' => true, 'why' => $clear ? 'That company follows the default statutory-during-probation answers again.'
                : 'Saved. During probation: ' . ($on === [] ? 'no statutory head applies' : implode(', ', $on) . ' apply')
                . ' — payroll, salary and payslips follow from the next generation.']
            : $saved;
    }

    /**
     * Was this employee on probation ON a date (the last day of a payroll
     * month)? On probation now, or confirmed only AFTER that date — so closing
     * September after an October confirmation still treats September as a
     * probation month. Confirmed on or before the date: not on probation.
     *
     * @param array<string, mixed> $employment
     */
    public function onProbationOn(array $employment, string $asOf): bool
    {
        $confirmed = substr(trim((string) ($employment['confirmation_date'] ?? '')), 0, 10);

        if ($confirmed !== '') {
            return $confirmed > $asOf;
        }

        return $this->onProbation($employment);
    }

    /** Has this company had settings of its own saved, or is it inheriting? */
    public function isOverridden(string $companyId): bool
    {
        if ($this->companySettings($companyId) !== null) {
            return true;
        }

        // Pre-migration installs, and installs whose columns exist but whose
        // settings were saved before they did.
        $record = (array) ($this->customisation->live()[(string) $this->declared()['customisation_key']] ?? []);

        return $companyId !== '' && isset(((array) ($record[self::PER_COMPANY] ?? []))[$companyId]);
    }

    /**
     * Stop overriding: this company goes back to inheriting the tenant's answer.
     * The entry is REMOVED rather than rewritten with the current defaults —
     * writing the values back would look identical today and stop tracking the
     * tenant default tomorrow, which is the whole difference this is for.
     *
     * @return array{ok: bool, why: string}
     */
    public function clearCompany(string $companyId, string $byId, string $byLabel): array
    {
        $cleared = false;

        // The columns first: blanking `probation_months` is what makes the row
        // inherit again, and the other five are blanked with it so a later
        // reader cannot find half a policy left behind.
        if ($this->companySettings($companyId) !== null) {
            $this->store->put('companies', [
                'id' => $companyId,
                'probation_months' => null,
                'probation_days' => null,
                'probation_reminder_days' => null,
                'probation_notify_hr' => null,
                'probation_notify_manager' => null,
                'probation_congratulate' => null,
            ]);
            $cleared = true;
        }

        $key = (string) $this->declared()['customisation_key'];
        $record = (array) ($this->customisation->live()[$key] ?? []);
        $per = (array) ($record[self::PER_COMPANY] ?? []);

        // And the old map, so a company carrying both a pre-migration entry and
        // columns does not quietly fall back to the entry once the columns go.
        if (isset($per[$companyId])) {
            unset($per[$companyId]);
            $record[self::PER_COMPANY] = $per;
            $saved = $this->customisation->saveRaw($key, $record, $byId, $byLabel,
                'Probation settings follow the default again');

            if (!($saved['ok'] ?? false)) {
                return $saved;
            }

            $cleared = true;
        }

        return ['ok' => true, 'why' => $cleared
            ? 'That company follows the default again.'
            : 'That company was already following the default.'];
    }

    /**
     * Has the probation-on-companies migration run on this install?
     *
     * Public because the Companies screen asks it too: once the columns are
     * there, probation is edited on the company's own form like Time zone, and
     * the separate section below the list has nothing left to do.
     */
    public function companyColumnsExist(): bool
    {
        try {
            return in_array('probation_months', $this->store->columnsOf('companies'), true);
        } catch (\Throwable) {
            // gate:allow-swallow a store that cannot describe its columns (the
            // file store) has none of them, and the old map is the answer.
            return false;
        }
    }

    /**
     * @param ?string $companyId when given, only THAT company's override is
     *     written; the tenant default and every other company are carried
     *     through untouched (Law 3 — the record is saved whole, so a save that
     *     built a fresh array would silently delete the other companies).
     * @return array{ok: bool, why: string}
     */
    public function saveConfig(array $choice, string $byId, string $byLabel, ?string $companyId = null): array
    {
        $months = (int) ($choice['default_months'] ?? 0);

        if ($months < 1 || $months > 36) {
            return ['ok' => false, 'why' => 'A default probation is 1 to 36 months.'];
        }

        $extraDays = (int) ($choice['default_days'] ?? 0);

        if ($extraDays < 0 || $extraDays > 30) {
            return ['ok' => false, 'why' => 'The extra days on top of the default months is 0 to 30.'];
        }

        // THE PIECES ARE FILTERED BEFORE THEY ARE CAST, not after. Splitting an
        // EMPTY box on /[\s,]+/ gives [''], and intval('') is 0 — a valid
        // milestone meaning "the day itself" — so the "at least one milestone"
        // guard below could never fire on a blank box, and clearing the field
        // silently saved a single same-day reminder. Same for any other
        // non-numeric typing: "soon" became 0 rather than being refused.
        $days = array_values(array_filter(array_map('intval', array_filter(
            preg_split('/[\s,]+/', trim((string) ($choice['reminder_days'] ?? ''))) ?: [],
            static fn (string $piece): bool => preg_match('/^\d+$/', $piece) === 1)),
            static fn (int $d): bool => $d >= 0 && $d <= 365));

        if ($days === []) {
            return ['ok' => false, 'why' => 'At least one reminder milestone (days before the end) is needed, '
                . 'as whole numbers of days — 0 means the day itself.'];
        }

        $settings = [
            'default_months' => $months,
            'default_days' => $extraDays,
            'reminder_days' => $days,
            'notify_hr' => (bool) ($choice['notify_hr'] ?? false),
            'notify_manager' => (bool) ($choice['notify_manager'] ?? false),
            'congratulate_employee' => (bool) ($choice['congratulate_employee'] ?? false),
        ];

        $key = (string) $this->declared()['customisation_key'];

        // A COMPANY'S SETTINGS GO ON THE COMPANY ROW, so this screen and the
        // company's own Edit form write the same six columns rather than two
        // copies of one policy. `put` touches only the keys given, so the
        // company's name, code, time zone and everything else are untouched.
        if ($companyId !== null && $companyId !== '' && $this->companyColumnsExist()) {
            if ($this->store->find('companies', $companyId) === null) {
                return ['ok' => false, 'why' => 'No such company.'];
            }

            $this->store->put('companies', [
                'id' => $companyId,
                'probation_months' => $months,
                'probation_days' => $extraDays,
                'probation_reminder_days' => implode(', ', $days),
                'probation_notify_hr' => $settings['notify_hr'] ? 1 : 0,
                'probation_notify_manager' => $settings['notify_manager'] ? 1 : 0,
                'probation_congratulate' => $settings['congratulate_employee'] ? 1 : 0,
            ]);

            return ['ok' => true, 'why' => 'Saved on the company. Probation for its employees now runs '
                . $months . ' month(s)' . ($extraDays > 0 ? " and {$extraDays} day(s)" : '') . '.'];
        }

        // READ, MERGE, WRITE — never build the record from scratch. saveRaw()
        // replaces the whole record, so saving one company's months from a
        // fresh array would delete the tenant default and every other company's
        // settings in the same breath, and it would look like it worked.
        $record = (array) ($this->customisation->live()[$key] ?? []);

        if ($companyId !== null && $companyId !== '') {
            // The migration has not run on this install yet: keep the old
            // per-company map working rather than refusing the save.
            $per = (array) ($record[self::PER_COMPANY] ?? []);
            $per[$companyId] = $settings;
            $record[self::PER_COMPANY] = $per;
        } else {
            // The tenant default changes; every company's override survives it.
            $record = array_merge($record, $settings);
        }

        return $this->customisation->saveRaw($key, $record, $byId, $byLabel,
            $companyId === null || $companyId === ''
                ? 'Probation settings (default for every company)'
                : 'Probation settings for one company');
    }

    /**
     * THE REMINDER SWEEP — SmartPRS 2's ProbationService::remind(). For every
     * board row inside a milestone (days left <= milestone) one message per
     * employee per milestone is queued to HR and, when the settings say so, to
     * the reporting manager; an overdue row gets one nudge per end date. Queue()
     * dedupes, so running this on every visit is safe. Confirmations recorded
     * today get the congratulations note when that is turned on.
     *
     * @return array{queued: int, recipients: int}
     */
    public function remind(Notifications $notifications, array $employments, string $today): array
    {
        $roleRecipients = array_map(static fn (string $r): string => Notifications::ROLE_PREFIX . $r, $this->notifyRoles());
        $queued = 0;
        $byId = [];

        foreach ($employments as $e) {
            $byId[(string) ($e['id'] ?? '')] = $e;
        }

        // WHO IS TOLD IS ALSO THE COMPANY'S CHOICE, not just how long probation
        // runs: one company copies the reporting manager in, another does not.
        // So the settings are resolved per employee here too, rather than once
        // above — a closure reading one $config would have quietly applied the
        // first company's notification policy to everybody.
        $recipients = function (array $e) use ($roleRecipients, $byId): array {
            $for = $this->configFor($e);
            // "Tell HR" is the company's switch; WHO counts as HR is the
            // tenant's list of roles (notifyRoles()).
            $to = ($for['notify_hr'] ?? true) ? $roleRecipients : [];
            $mgr = $byId[(string) ($e['reporting_manager_id'] ?? '')] ?? null;

            if (($for['notify_manager'] ?? false) && $mgr !== null && Employment::code($mgr) !== '') {
                $to[] = Employment::code($mgr);
            }

            return array_values(array_unique($to));
        };

        foreach ($this->board($employments, $today)['rows'] as $row) {
            if ($row['days'] === null) {
                continue;
            }

            $e = $byId[$row['employment_id']] ?? [];
            $values = ['name' => $row['name'], 'code' => $row['code'], 'end' => (string) $row['end'], 'days' => (string) $row['days'],
                'company' => (string) ($row['company'] ?? ''), 'department' => $row['department'], 'designation' => $row['designation']];

            if ($row['days'] < 0) {
                foreach ($recipients($e) as $to) {
                    $queued += $notifications->queue('probation.overdue', $to, 'probation.overdue:' . $row['code'] . ':' . $row['end'] . ':' . $to, $values) ? 1 : 0;
                }

                continue;
            }

            // THE MILESTONE JUST CROSSED, not every one already behind it.
            // With 30, 15, 7 and 0, somebody found five days out is told
            // "7 days" — not handed three messages at once for 30, 15 and 7,
            // which reads as the system shouting and buries the one that
            // matters. Each milestone is still its own dedupe key, so the next
            // one fires when it is crossed, and the end date is in the key so
            // an extension starts the countdown again.
            $crossed = array_values(array_filter(array_map('intval', $this->configFor($e)['reminder_days']),
                static fn (int $m): bool => $row['days'] <= $m));

            if ($crossed === []) {
                continue;
            }

            $milestone = min($crossed);
            $event = $row['days'] === 0 ? 'probation.last_day' : 'probation.reminder';

            foreach ($recipients($e) as $to) {
                $queued += $notifications->queue($event, $to,
                    $event . ':' . $row['code'] . ':' . $row['end'] . ':' . $milestone . ':' . $to, $values) ? 1 : 0;
            }
        }

        // CONGRATULATIONS ARE THE COMPANY'S CHOICE TOO, so the flag is read for
        // the employee being confirmed rather than once for the sweep.
        foreach ($this->store->all(self::EVENTS) as $ev) {
            if ((string) ($ev['action'] ?? '') !== 'confirm'
                || !str_starts_with((string) ($ev['at'] ?? ''), $today)
                || !isset($byId[(string) ($ev['employee_id'] ?? '')])) {
                continue;
            }

            $e = $byId[(string) $ev['employee_id']];

            if (!($this->configFor($e)['congratulate_employee'] ?? false)) {
                continue;
            }

            $queued += $notifications->queue('probation.confirmed', Employment::code($e),
                'probation.confirmed:' . Employment::code($e) . ':' . $ev['effective'],
                ['name' => trim((string) ($e['name'] ?? '')), 'code' => Employment::code($e),
                    'on' => (string) $ev['effective']]) ? 1 : 0;
        }

        return ['queued' => $queued, 'recipients' => count($employments)];
    }

    /**
     * The settings that govern ONE employee: their company's, or the tenant's
     * when their company has none of its own — or when the employee is not
     * attached to a company at all, which a record imported before the column
     * was mandatory can still be.
     *
     * @param array<string, mixed> $employment
     */
    public function configFor(array $employment): array
    {
        return $this->config(trim((string) ($employment['employer_company_id'] ?? '')) ?: null);
    }

    /**
     * Is this employee on probation?
     *
     * BY EITHER OF THE TWO FIELDS THAT SAY SO. The ported `employment_stage`
     * (what the hire form and SmartPRS 2 set) and the Directory's own "Employee
     * Type" (`employment_type`, whose list carries "Probationary"). Reading only
     * the stage meant an employee added on the Directory as Probationary never
     * reached this board, never had a reminder, and could never be confirmed —
     * two fields answering one question, and the board asking the other one.
     * Which Employee Type values mean probation is declared
     * (config/probation.json `probation_types`), not typed here.
     */
    public function onProbation(array $employment): bool
    {
        if (trim((string) ($employment['confirmation_date'] ?? '')) !== '') {
            return false;
        }

        $stage = strtolower(trim((string) ($employment['employment_stage'] ?? '')));

        if ($stage === strtolower((string) ($this->declared()['on_probation_stage'] ?? 'probation'))) {
            return true;
        }

        $type = strtolower(trim((string) ($employment['employment_type'] ?? '')));

        return $type !== '' && in_array($type,
            array_map(static fn ($t): string => strtolower((string) $t), (array) ($this->declared()['probation_types'] ?? [])), true);
    }

    /**
     * WHICH ROLES ARE TOLD (owner, 28 Sep: "the Admin and HR Admins get
     * notifications in their logins"). Role names, not people: a message
     * queued for a role is seen by every login holding it, including one
     * with no employee record of its own — the administrator, typically.
     * The shipped list is config/probation.json's; the tenant's own choice
     * on the Probation screen replaces it.
     *
     * @return list<string>
     */
    public function notifyRoles(): array
    {
        $record = (array) ($this->customisation->live()[(string) $this->declared()['customisation_key']] ?? []);
        $roles = is_array($record['notify_roles'] ?? null)
            ? $record['notify_roles']
            : (array) ($this->declared()['notify_roles'] ?? []);

        return array_values(array_unique(array_filter(array_map(
            static fn ($r): string => trim((string) $r), $roles), static fn (string $r): bool => $r !== '')));
    }

    /**
     * Save the roles that are told. Tenant-wide — who in the head office hears
     * about probation is not a per-company policy the way the months are.
     *
     * @param list<string> $roles
     * @param list<string> $known every role this install declares
     * @return array{ok: bool, why: string}
     */
    public function saveNotifyRoles(array $roles, array $known, string $byId, string $byLabel): array
    {
        $roles = array_values(array_unique(array_filter(array_map(
            static fn ($r): string => trim((string) $r), $roles), static fn (string $r): bool => $r !== '')));

        foreach ($roles as $role) {
            if (!in_array($role, $known, true)) {
                return ['ok' => false, 'why' => "'{$role}' is not a role on this install."];
            }
        }

        if ($roles === []) {
            return ['ok' => false, 'why' => 'Pick at least one role to be told when a probation is ending — '
                . 'otherwise nobody hears about it.'];
        }

        $key = (string) $this->declared()['customisation_key'];
        $record = (array) ($this->customisation->live()[$key] ?? []);
        $record['notify_roles'] = $roles;

        $saved = $this->customisation->saveRaw($key, $record, $byId, $byLabel, 'Probation reminders go to: ' . implode(', ', $roles));

        return ($saved['ok'] ?? false)
            ? ['ok' => true, 'why' => 'Probation reminders now go to: ' . implode(', ', $roles) . '.']
            : $saved;
    }

    /** The end date: the override on the row, else doj + the configured months. */
    public function endDate(array $employment, array $config): ?string
    {
        $override = trim((string) ($employment['probation_end'] ?? ''));

        if ($override !== '') {
            return substr($override, 0, 10);
        }

        $doj = Employment::joinedOn($employment);

        if ($doj === '') {
            return null;
        }

        return (new \DateTimeImmutable($doj))->modify('+' . (int) $config['default_months'] . ' months +'
            . (int) ($config['default_days'] ?? 0) . ' days')->format('Y-m-d');
    }

    /**
     * Everybody on probation, bucketed by how close the end is.
     *
     * @param list<array<string, mixed>> $employments
     * @return array{rows: list<array<string, mixed>>, buckets: array<string, int>}
     */
    public function board(array $employments, string $today): array
    {
        $rows = [];
        $buckets = ['overdue' => 0, 'today' => 0, 'due' => 0, 'later' => 0, 'no_end' => 0];
        $companies = [];

        try {
            foreach ($this->store->all('companies') as $co) {
                $companies[(string) ($co['id'] ?? '')] = trim((string) ($co['name'] ?? ''));
            }
        } catch (\Throwable) {
            // gate:allow-swallow a store with no companies table names no company; the row says '' rather than failing the board.
        }

        foreach ($employments as $e) {
            if (!$this->onProbation($e)) {
                continue;
            }

            // EACH EMPLOYEE AGAINST THEIR OWN COMPANY'S RULE. One config read
            // for the whole board was right while probation was a tenant-wide
            // number; now it would end everybody's probation on the date the
            // first company's policy gives, which is a wrong date that looks
            // like a right one. configFor() reads from the same already-loaded
            // customisation record, so this is a merge per row, not a query.
            $config = $this->configFor($e);
            $window = $config['reminder_days'] === [] ? 0 : max($config['reminder_days']);
            $end = $this->endDate($e, $config);
            $days = $end === null ? null
                : (int) (new \DateTimeImmutable($today))->diff(new \DateTimeImmutable($end))->format('%r%a');
            $bucket = $end === null ? 'no_end'
                : ($days < 0 ? 'overdue' : ($days === 0 ? 'today' : ($days <= $window ? 'due' : 'later')));
            $buckets[$bucket]++;

            $rows[] = [
                'employment_id' => (string) ($e['id'] ?? ''),
                'code' => Employment::code($e),
                'name' => trim((string) ($e['name'] ?? '')),
                'company' => $companies[(string) ($e['employer_company_id'] ?? '')] ?? '',
                'type' => trim((string) ($e['employment_type'] ?? '')),
                'department' => trim((string) ($e['department'] ?? '')),
                'designation' => trim((string) ($e['designation'] ?? '')),
                'joined' => Employment::joinedOn($e),
                'end' => $end,
                'days' => $days,
                'bucket' => $bucket,
                'extended' => trim((string) ($e['probation_end'] ?? '')) !== '',
                'extensions' => count(array_filter($this->history(Employment::code($e)),
                    static fn (array $h): bool => (string) $h['action'] === 'extend')),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['end'] ?? '9999', $a['code']] <=> [$b['end'] ?? '9999', $b['code']]);

        return ['rows' => $rows, 'buckets' => $buckets];
    }

    /**
     * Confirm: the timeline event, the row's stage and date, and a history row.
     *
     * @return array{ok: bool, why: string}
     */
    public function confirm(array $employment, string $on, string $by, string $remarks = ''): array
    {
        $code = Employment::code($employment);

        if ($code === '' || !$this->onProbation($employment)) {
            return ['ok' => false, 'why' => "{$code} is not on probation."];
        }

        if (!self::isDate($on)) {
            return ['ok' => false, 'why' => "'{$on}' is not a date."];
        }

        // REMARKS ARE MANDATORY FOR A CONFIRMATION TOO (owner, 28 Sep:
        // "Remarks mandatory for both confirm and extend"). A confirmation is
        // the decision the confirmation letter and the next appraisal read.
        $remarks = trim($remarks);

        if ($remarks === '') {
            return ['ok' => false, 'why' => 'A confirmation needs remarks — the basis for confirming, which the '
                . 'confirmation letter and the next review read.'];
        }

        $note = '';

        try {
            if ($this->timeline->events($code) === []) {
                $doj = Employment::joinedOn($employment);

                if ($doj === '') {
                    return ['ok' => false, 'why' => "{$code} has no joining date and no timeline, so there is nothing "
                        . 'for a confirmation to follow. Record the joining first.'];
                }

                $this->timeline->record($code, EmploymentTimeline::JOINED, $doj, 'Recorded from doj when the '
                    . 'first timeline event was needed', [], $by);
                $note = " {$code}'s timeline was empty, so joining on {$doj} was recorded first — a "
                    . 'confirmation cannot be the first thing that happened to an employee.';
            }

            $this->timeline->record($code, EmploymentTimeline::CONFIRMED, $on, $remarks, [], $by);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        // PERMANENT ON THE DIRECTORY (owner, 28 Sep): both fields that say
        // "probation" are moved on, so neither the Employee Type column nor
        // the stage keeps showing it after confirmation.
        $employment['employment_stage'] = (string) ($this->declared()['confirmed_stage'] ?? 'confirmed');
        $employment['employment_type'] = (string) ($this->declared()['confirmed_type'] ?? 'Permanent');
        $employment['confirmation_date'] = $on;
        $this->store->put('employees', $employment);
        $this->store->put(self::EVENTS, ['employee_id' => (string) $employment['id'], 'action' => 'confirm',
            'details' => mb_substr($remarks, 0, 255), 'effective' => $on, 'by' => $by, 'at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => "{$code} confirmed with effect from {$on}. Statutory applicability that "
            . "waits on confirmation reads the same event payroll does.{$note}"];
    }

    /** @return array{ok: bool, why: string} */
    public function extend(array $employment, string $newEnd, string $by, string $remarks): array
    {
        $code = Employment::code($employment);

        if ($code === '' || !$this->onProbation($employment)) {
            return ['ok' => false, 'why' => "{$code} is not on probation."];
        }

        if (!self::isDate($newEnd)) {
            return ['ok' => false, 'why' => "'{$newEnd}' is not a date."];
        }

        if (trim($remarks) === '') {
            return ['ok' => false, 'why' => 'An extension needs a reason. It is what the employee is told and '
                . 'what the next review reads.'];
        }

        // Their company's months, not the tenant's — otherwise "not after the
        // current end" is measured against a date this employee never had.
        $current = $this->endDate($employment, $this->configFor($employment));

        if ($current !== null && $newEnd <= $current) {
            return ['ok' => false, 'why' => "{$newEnd} is not after the current end ({$current}). An extension "
                . 'moves the end later; to end probation, confirm.'];
        }

        $employment['probation_end'] = $newEnd;
        $this->store->put('employees', $employment);
        $this->store->put(self::EVENTS, ['employee_id' => (string) $employment['id'], 'action' => 'extend',
            'details' => mb_substr(($current ?? '?') . ' → ' . $newEnd . ': ' . trim($remarks), 0, 255),
            'effective' => $newEnd, 'by' => $by, 'at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => "{$code}'s probation now ends {$newEnd}."];
    }

    /** @return list<array<string, mixed>> */
    public function history(string $code): array
    {
        $id = null;

        foreach ($this->store->all('employees') as $e) {
            if (Employment::code($e) === $code) {
                $id = (string) $e['id'];

                break;
            }
        }

        if ($id === null) {
            return [];
        }

        $rows = array_values(array_filter($this->store->all(self::EVENTS),
            static fn (array $r): bool => (string) ($r['employee_id'] ?? '') === $id));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

        return $rows;
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
