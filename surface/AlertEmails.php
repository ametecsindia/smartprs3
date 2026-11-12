<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Notification\Redactor;

/**
 * ALERT EMAIL DRAFTS (owner, 8 Oct): "for all employees whose DRA or PCC or any
 * certificate is expiring in the next 15 days, 7 days and 1 day should get
 * automatic alert emails … the customisation and default text under
 * Organization Configuration → Alert Email Drafts … list of all the email
 * alerts that can be sent automatically, with draft text and the option to
 * include fields like Emp Name, ID, Designation … also Appraisal letter,
 * Promotion letter, etc. with when to send."
 *
 * WHAT GOES OUT IS DECIDED HERE, ONCE A DAY (due()): certificate expiries and
 * probation ends by their day milestones, birthdays and work anniversaries on
 * the day, appraisal / promotion / increment / confirmation letters for what was
 * recorded. Each message carries a dedupe key, and a message already SENT under
 * that key is never sent again — re-running the day, or opening the app twice,
 * tells nobody twice.
 *
 * MILESTONES CATCH UP: a certificate 13 days from expiry gets its "15 days"
 * mail even if nobody opened the app on the 15th day — the smallest milestone
 * the expiry has crossed is sent, once.
 *
 * SENT FOR REAL, OR NOT AT ALL: through the mail server the tenant saves on the
 * screen (Smtp). Without one nothing is marked
 * sent — Notifications' rule. Every attempt is logged in `notification_log`
 * as SENT or FAILED with the server's reason.
 */
final class AlertEmails
{
    private const LOG = 'notification_log';

    /** @var array<string, mixed>|null */
    private ?array $declared = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $file,
    ) {
    }

    /** @return array<string, mixed> config/alert-emails.json */
    public function declared(): array
    {
        if ($this->declared === null) {
            $d = json_decode((string) file_get_contents($this->file), true);

            if (!is_array($d) || !is_array($d['alerts'] ?? null)) {
                throw new \RuntimeException('config/alert-emails.json is missing or has no alerts.');
            }

            $this->declared = $d;
        }

        return $this->declared;
    }

    /** Every alert: the shipped draft with the tenant's saved version over it. @return array<string, array<string, mixed>> */
    public function alerts(): array
    {
        $saved = (array) ($this->customisation->live()[(string) $this->declared()['customisation_key']]['alerts'] ?? []);
        $out = [];

        foreach ((array) $this->declared()['alerts'] as $key => $shipped) {
            $mine = (array) ($saved[$key] ?? []);
            $out[$key] = array_replace($shipped, $mine, ['recipients' => array_replace((array) $shipped['recipients'], (array) ($mine['recipients'] ?? []))]);
            $out[$key]['customised'] = $mine !== [];
        }

        return $out;
    }

    /** Mail server settings (the password is never returned). @return array<string, mixed> */
    public function smtp(bool $withPassword = false): array
    {
        $saved = (array) ($this->customisation->live()[(string) $this->declared()['smtp_customisation_key']] ?? []);
        $s = array_replace((array) $this->declared()['smtp_defaults'], $saved);
        $s['has_password'] = (string) ($saved['password'] ?? '') !== '';

        if (!$withPassword) {
            unset($s['password']);
        }

        return $s;
    }

    /** Save one alert's draft and settings as a new version. */
    public function saveAlert(string $key, array $posted, array $knownRoles, string $byId, string $byLabel): array
    {
        $shipped = $this->declared()['alerts'][$key] ?? null;

        if (!is_array($shipped)) {
            return ['ok' => false, 'why' => 'No such alert.'];
        }

        $subject = trim((string) ($posted['subject'] ?? ''));
        $body = rtrim(str_replace("\r\n", "\n", (string) ($posted['body'] ?? '')));

        if ($subject === '' || trim($body) === '') {
            return ['ok' => false, 'why' => 'The subject and the text cannot be blank.'];
        }

        $unknown = array_diff($this->placeholdersIn($subject . ' ' . $body), array_keys((array) $this->declared()['placeholders']));

        if ($unknown !== []) {
            return ['ok' => false, 'why' => 'Unknown field(s) {' . implode('}, {', $unknown) . '} — pick fields from the list under the text.'];
        }

        $mine = ['enabled' => !empty($posted['enabled']), 'subject' => $subject, 'body' => $body];

        if (($shipped['trigger'] ?? '') === 'days_before') {
            $days = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', (string) ($posted['days'] ?? ''))), 'is_numeric'))));
            rsort($days);

            if ($days === [] || min($days) < 0 || max($days) > 365) {
                return ['ok' => false, 'why' => 'Days before must be numbers between 0 and 365, e.g. 15, 7, 1.'];
            }

            $mine['days'] = $days;
        }

        if (isset($shipped['sources'])) {
            $mine['sources'] = array_values(array_intersect(array_keys((array) $this->declared()['certificate_sources']), (array) ($posted['sources'] ?? [])));
        }

        if (isset($shipped['when'])) {
            $mine['when'] = isset($this->declared()['when_options'][(string) ($posted['when'] ?? '')]) ? (string) $posted['when'] : (string) $shipped['when'];
        }

        if (isset($shipped['lookback_days'])) {
            $mine['lookback_days'] = max(0, min(90, (int) ($posted['lookback_days'] ?? $shipped['lookback_days'])));
        }

        $cc = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($posted['cc'] ?? '')) ?: [])));

        foreach ($cc as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'why' => "'{$address}' in CC is not an email address."];
            }
        }

        $mine['recipients'] = ['employee' => !empty($posted['to_employee']), 'manager' => !empty($posted['to_manager']),
            'roles' => array_values(array_intersect($knownRoles, (array) ($posted['roles'] ?? []))), 'cc' => implode(', ', $cc)];

        $ck = (string) $this->declared()['customisation_key'];
        $all = (array) ($this->customisation->live()[$ck] ?? []);
        $all['alerts'][$key] = $mine;
        $saved = $this->customisation->saveRaw($ck, $all, $byId, $byLabel, 'Alert email "' . $shipped['label'] . '" saved');

        return ($saved['ok'] ?? false) ? ['ok' => true, 'why' => '"' . $shipped['label'] . '" saved.'] : $saved;
    }

    /** Back to the shipped draft (a new version, nothing deleted). */
    public function resetAlert(string $key, string $byId, string $byLabel): array
    {
        $ck = (string) $this->declared()['customisation_key'];
        $all = (array) ($this->customisation->live()[$ck] ?? []);
        unset($all['alerts'][$key]);
        $all['alerts'] = (array) ($all['alerts'] ?? []);
        $saved = $this->customisation->saveRaw($ck, $all, $byId, $byLabel, "Alert email {$key} back to the shipped draft");

        return ($saved['ok'] ?? false) ? ['ok' => true, 'why' => 'Back to the shipped draft.'] : $saved;
    }

    public function saveSmtp(array $posted, string $byId, string $byLabel): array
    {
        $current = $this->smtp(true);
        $s = [
            'enabled' => !empty($posted['enabled']),
            'host' => trim((string) ($posted['host'] ?? '')),
            'port' => (int) ($posted['port'] ?? 0),
            'encryption' => in_array($posted['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? (string) $posted['encryption'] : 'tls',
            'username' => trim((string) ($posted['username'] ?? '')),
            // A BLANK PASSWORD BOX KEEPS THE SAVED ONE: the screen never shows it back.
            'password' => (string) ($posted['password'] ?? '') !== '' ? (string) $posted['password'] : (string) ($current['password'] ?? ''),
            'from_email' => trim((string) ($posted['from_email'] ?? '')),
            'from_name' => trim((string) ($posted['from_name'] ?? '')),
            'reply_to' => trim((string) ($posted['reply_to'] ?? '')),
            'send_after' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($posted['send_after'] ?? '')) === 1 ? (string) $posted['send_after'] : '09:00',
            'max_per_run' => max(1, min(1000, (int) ($posted['max_per_run'] ?? 200))),
        ];

        if ($s['enabled'] && ($s['host'] === '' || $s['port'] < 1 || $s['port'] > 65535)) {
            return ['ok' => false, 'why' => 'Give the mail server and its port before switching sending on.'];
        }

        foreach (['from_email' => 'From email', 'reply_to' => 'Reply-to'] as $k => $label) {
            if ($s[$k] !== '' && filter_var($s[$k], FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'why' => "{$label} is not an email address."];
            }
        }

        if ($s['enabled'] && $s['from_email'] === '') {
            return ['ok' => false, 'why' => 'Give the From email before switching sending on.'];
        }

        $saved = $this->customisation->saveRaw((string) $this->declared()['smtp_customisation_key'], $s, $byId, $byLabel, 'Mail server settings saved');

        return ($saved['ok'] ?? false) ? ['ok' => true, 'why' => 'Mail server settings saved' . ($s['enabled'] ? ' — sending is ON.' : ' — sending is OFF.')] : $saved;
    }

    /** {name} → value; an unknown field is left visible rather than silently blanked. */
    public static function render(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $template);
    }

    /** @return list<string> */
    public function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * The smallest milestone a date has crossed (days_left ≤ milestone), or
     * null when none yet or the date has passed. [15,7,1] with 13 days left → 15.
     *
     * @param list<int> $days
     */
    public static function milestone(array $days, int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }

        $hit = null;

        foreach ($days as $d) {
            if ($daysLeft <= (int) $d && ($hit === null || (int) $d < $hit)) {
                $hit = (int) $d;
            }
        }

        return $hit;
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    /**
     * EVERYTHING DUE TODAY, before recipients: list of [alert, dedupe, employee, values].
     *
     * @param list<array<string, mixed>> $employees live employees
     * @param callable(array<string, mixed>): array<string, string> $describe the employee's own placeholders
     * @param callable(array<string, mixed>): bool $onProbation
     * @param callable(array<string, mixed>): ?string $probationEnd
     * @return list<array{alert: string, key: string, employee: array<string, mixed>, values: array<string, string>}>
     */
    public function due(array $employees, string $today, callable $describe, callable $onProbation, callable $probationEnd,
        ?callable $emisFor = null, ?callable $absentOn = null): array
    {
        $alerts = $this->alerts();
        $byId = [];

        foreach ($employees as $e) {
            if (trim((string) ($e['exit_date'] ?? '')) === '' || substr((string) $e['exit_date'], 0, 10) >= $today) {
                $byId[(string) ($e['id'] ?? '')] = $e;
            }
        }

        $out = [];
        $add = static function (string $alert, string $key, array $e, array $values) use (&$out, $describe, $today): void {
            $out[] = ['alert' => $alert, 'key' => $key, 'employee' => $e, 'values' => $values + $describe($e) + ['today' => Dates::dmy($today)]];
        };

        $cert = $alerts['certificate_expiry'] ?? null;

        if (is_array($cert) && !empty($cert['enabled'])) {
            $sources = (array) ($cert['sources'] ?? []);
            $found = []; // [employee id, certificate, expiry]

            if (in_array('employee_columns', $sources, true)) {
                // A DATE HELD BESIDE THE RECORD COUNTS TOO (owner, 12 Oct: "including Passport"):
                // Passport Expiry Date has no employees column; it lives in employee_field_values.
                $held = [];

                try {
                    foreach ($this->store->all(EmployeeExtras::COLLECTION) as $h) {
                        $held[(string) ($h['employment_code'] ?? '')][(string) ($h['field_code'] ?? '')] = (string) ($h['value'] ?? '');
                    }
                } catch (\Throwable) { // no held-fields register on this installation: the columns alone are read
                    $held = [];
                }

                foreach ($byId as $id => $e) {
                    foreach ((array) ($this->declared()['certificate_sources']['employee_columns']['columns'] ?? []) as $col => $label) {
                        $date = (string) ($e[$col] ?? '') ?: (string) ($held[Employment::code($e)][$col] ?? '');
                        $found[] = [$id, (string) $label, substr($date, 0, 10), 'emp:' . $col];
                    }
                }
            }

            $tables = ['documents' => ['expiry', static fn (array $r): string => trim((string) ($r['kind'] ?? '')) ?: 'Document'],
                'agent_authorizations' => ['valid_to', static fn (array $r): string => trim('Bank authorisation ' . ($r['bank'] ?? '') . ' ' . ($r['auth_no'] ?? ''))],
                'training_records' => ['expiry', static fn (array $r): string => 'Training certificate' . (trim((string) ($r['program'] ?? '')) !== '' ? ' — ' . $r['program'] : '')]];

            foreach ($tables as $table => [$col, $label]) {
                if (!in_array($table, $sources, true)) {
                    continue;
                }

                try {
                    $rows = $this->store->all($table);
                } catch (\Throwable) {
                    continue; // tolerated: a register this installation does not have
                }

                foreach ($rows as $r) {
                    if (trim((string) ($r['deleted_at'] ?? '')) !== '' || in_array(strtolower((string) ($r['status'] ?? '')), ['revoked', 'rejected', 'renewed'], true)) {
                        continue;
                    }

                    $found[] = [(string) ($r['employee_id'] ?? ''), $label($r), substr((string) ($r[$col] ?? ''), 0, 10), $table . ':' . ($r['id'] ?? '')];
                }
            }

            foreach ($found as [$id, $label, $expiry, $ref]) {
                if (!isset($byId[$id]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) !== 1) {
                    continue;
                }

                $left = self::daysBetween($today, $expiry);
                $m = self::milestone((array) $cert['days'], $left);

                if ($m !== null) {
                    $add('certificate_expiry', "cert:{$ref}:{$id}:{$expiry}:{$m}", $byId[$id],
                        ['certificate' => $label, 'expiry_date' => Dates::dmy($expiry), 'days_left' => (string) $left]);
                }
            }
        }

        // PREVIOUS-DAY ABSENCE (owner, 12 Oct: moved here from the separate Absence Alerts tab).
        // $absentOn(date) answers with the employee codes the attendance rules call ABSENT that
        // day — Absence::run(), the muster's own verdict; nothing is classified here.
        $absAlert = $alerts['absent_yesterday'] ?? null;

        if (is_array($absAlert) && !empty($absAlert['enabled']) && $absentOn !== null) {
            $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
            $codes = array_flip(array_map('strval', (array) $absentOn($yesterday)));

            foreach ($byId as $id => $e) {
                if (isset($codes[Employment::code($e)])) {
                    $add('absent_yesterday', "absent:{$id}:{$yesterday}", $e, ['absent_date' => Dates::dmy($yesterday)]);
                }
            }
        }

        // PROBATION ENDING (advance reminders) and PROBATION COMPLETION (owner,
        // 8 Oct) — the same end date, two separately worded and timed emails.
        foreach (['probation_ending' => 'prob', 'probation_completed' => 'probdone'] as $probAlert => $probKey) {
            $prob = $alerts[$probAlert] ?? null;

            if (!is_array($prob) || empty($prob['enabled'])) {
                continue;
            }

            foreach ($byId as $id => $e) {
                $end = $onProbation($e) ? $probationEnd($e) : null;

                if ($end === null || ($m = self::milestone((array) ($prob['days'] ?? [0]), $left = self::daysBetween($today, $end))) === null) {
                    continue;
                }

                $add($probAlert, "{$probKey}:{$id}:{$end}:{$m}", $e, ['probation_end' => Dates::dmy($end), 'days_left' => (string) $left]);
            }
        }

        // LOAN / SALARY ADVANCE EMI (owner, 8 Oct): N days before the month ends,
        // one email per instalment this month's payroll will deduct — the figures
        // come from the same recovery engine the payroll close uses.
        $emi = $alerts['emi_deduction'] ?? null;

        if (is_array($emi) && !empty($emi['enabled']) && $emisFor !== null) {
            $period = substr($today, 0, 7);
            $monthEnd = date('Y-m-t', (int) strtotime($period . '-01'));
            $m = self::milestone((array) ($emi['days'] ?? [5]), self::daysBetween($today, $monthEnd));

            if ($m !== null) {
                foreach ($byId as $id => $e) {
                    foreach ($emisFor($e, $period) as $line) {
                        $add('emi_deduction', "emi:{$id}:{$period}:" . (string) ($line['code'] ?? '') . ":{$m}", $e, [
                            'emi_label' => (string) ($line['label'] ?? 'loan / advance'),
                            'emi_amount' => (string) ($line['amount'] ?? ''),
                            'emi_month' => date('F Y', (int) strtotime($period . '-01')),
                            'emi_detail' => (string) ($line['detail'] ?? ''),
                        ]);
                    }
                }
            }
        }

        foreach (['birthday' => 'dob', 'work_anniversary' => 'doj'] as $alert => $col) {
            if (empty($alerts[$alert]['enabled'])) {
                continue;
            }

            foreach ($byId as $id => $e) {
                $d = substr((string) ($e[$col] ?? ''), 0, 10);

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1 || substr($d, 5) !== substr($today, 5)) {
                    continue;
                }

                $years = (int) substr($today, 0, 4) - (int) substr($d, 0, 4);

                if ($alert === 'work_anniversary' && $years < 1) {
                    continue;
                }

                $add($alert, "{$alert}:{$id}:" . substr($today, 0, 4), $e, ['years' => (string) $years]);
            }
        }

        // LETTERS FOR WHAT WAS RECORDED on Increment / Appraisal.
        $kindOf = static fn (array $i): string => str_contains((string) ($i['remarks'] ?? ''), '[PA:') ? 'appraisal_letter'
            : (trim((string) ($i['new_designation'] ?? '')) !== '' ? 'promotion_letter' : 'increment_letter');

        try {
            $increments = $this->store->all('increments');
        } catch (\Throwable) {
            $increments = []; // tolerated: no increments register in this store
        }

        foreach ($increments as $i) {
            $alert = $kindOf($i);
            $cfg = $alerts[$alert] ?? null;
            $e = $byId[(string) ($i['employee_id'] ?? '')] ?? null;

            if (!is_array($cfg) || empty($cfg['enabled']) || $e === null || (string) ($i['status'] ?? '') !== 'Effected') {
                continue;
            }

            $effective = substr((string) ($i['effective'] ?? ''), 0, 10);
            $recorded = substr((string) ($i['decided_at'] ?? $i['created_at'] ?? ''), 0, 10);
            $anchor = ($cfg['when'] ?? 'on_record') === 'on_effective' ? $effective : ($recorded !== '' ? $recorded : $effective);

            if ($anchor === '' || $anchor > $today || self::daysBetween($anchor, $today) > (int) ($cfg['lookback_days'] ?? 7)) {
                continue;
            }

            $old = trim((string) ($i['old_ctc'] ?? ''));
            $new = trim((string) ($i['new_ctc'] ?? ''));
            preg_match('/\[PA:[^:\]]+:([^\]]+)\] rating (\S+)/', (string) ($i['remarks'] ?? ''), $pm);
            $add($alert, "{$alert}:" . ($i['id'] ?? ''), $e, [
                'old_ctc' => $old === '' ? '' : Amount::of($old)->format(), 'new_ctc' => $new === '' ? '' : Amount::of($new)->format(),
                'increment_amount' => $old !== '' && $new !== '' ? Amount::of($new)->minus(Amount::of($old))->format() : '',
                'increment_pct' => trim((string) ($i['pct'] ?? '')), 'effective_date' => $effective === '' ? '' : Dates::dmy($effective),
                'rating' => trim((string) ($i['rating'] ?? '')) ?: (string) ($pm[2] ?? ''), 'review_period' => trim((string) ($i['review_period'] ?? '')) ?: (string) ($pm[1] ?? ''),
                'new_designation' => trim((string) ($i['new_designation'] ?? ''))]);
        }

        $conf = $alerts['confirmation_letter'] ?? null;

        if (is_array($conf) && !empty($conf['enabled'])) {
            foreach ($byId as $id => $e) {
                $on = substr((string) ($e['confirmation_date'] ?? ''), 0, 10);

                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) === 1 && $on <= $today && self::daysBetween($on, $today) <= (int) ($conf['lookback_days'] ?? 7)) {
                    $add('confirmation_letter', "confirm:{$id}:{$on}", $e, ['confirmation_date' => Dates::dmy($on)]);
                }
            }
        }

        return $out;
    }

    /**
     * Who one message goes to: the employee, their reporting manager, every
     * enabled login holding a chosen role, and the CC list — de-duplicated.
     *
     * @param array<string, mixed> $recipients
     * @param array<string, array<string, mixed>> $employeesById
     * @param list<array<string, mixed>> $users Users::all()
     * @return array{to: list<string>, cc: list<string>}
     */
    public static function addresses(array $recipients, array $employee, array $employeesById, array $users): array
    {
        $mail = static fn (array $e): string => trim((string) (($e['email'] ?? '') ?: ($e['personal_email'] ?? '')));
        $to = [];
        $cc = [];

        $manager = !empty($recipients['manager']) && isset($employeesById[(string) ($employee['reporting_manager_id'] ?? '')])
            ? $mail($employeesById[(string) $employee['reporting_manager_id']]) : '';

        // THE EMPLOYEE IS THE ADDRESSEE when they are told; the manager is then copied.
        if (!empty($recipients['employee'])) {
            $to[] = $mail($employee);
            $cc[] = $manager;
        } else {
            $to[] = $manager;
        }

        $roles = array_map('strtolower', (array) ($recipients['roles'] ?? []));

        foreach ($users as $u) {
            if (!($u['disabled'] ?? false) && in_array(strtolower((string) ($u['role'] ?? '')), $roles, true)) {
                $cc[] = trim((string) ($u['email'] ?? ''));
            }
        }

        foreach (preg_split('/[,;\s]+/', (string) ($recipients['cc'] ?? '')) ?: [] as $c) {
            $cc[] = trim($c);
        }

        $valid = static fn (array $list): array => array_values(array_unique(array_filter(array_map('strtolower', $list),
            static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false)));
        $to = $valid($to);
        $cc = array_values(array_diff($valid($cc), $to));

        // NOBODY IN "TO" (no employee email, or the employee is not an addressee): the CC list becomes the addressees.
        return $to === [] ? ['to' => $cc, 'cc' => []] : ['to' => $to, 'cc' => $cc];
    }

    public function alreadySent(string $dedupe): bool
    {
        foreach ($this->store->all(self::LOG) as $r) {
            if ((string) ($r['dedupe_key'] ?? '') === 'mail:' . $dedupe) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> the email log, newest first */
    public function log(int $limit = 100): array
    {
        $rows = array_values(array_filter($this->store->all(self::LOG), static fn (array $r): bool => str_starts_with((string) ($r['kind'] ?? ''), 'email.')));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    /** Has today's automatic run happened? (A marker row, never shown in an inbox.) */
    public function ranOn(string $today): bool
    {
        foreach ($this->store->all(self::LOG) as $r) {
            if ((string) ($r['dedupe_key'] ?? '') === 'alert-sweep:' . $today) {
                return true;
            }
        }

        return false;
    }

    public function markRan(string $today, string $summary): void
    {
        $this->store->put(self::LOG, ['kind' => 'system.alert-sweep', 'title' => 'Alert emails ' . $today, 'body' => $summary, 'channels' => '',
            'email_status' => Outbox::SUPPRESSED, 'dedupe_key' => 'alert-sweep:' . $today, 'meta' => json_encode(['recipient' => '']),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Send one email through the saved server. Throws nothing: [ok, why].
     *
     * @param list<string> $to @param list<string> $cc
     */
    public function send(array $to, array $cc, string $subject, string $body, array $attachments = []): array
    {
        $s = $this->smtp(true);

        if (!$s['enabled'] || (string) $s['host'] === '') {
            return ['ok' => false, 'why' => 'Sending is switched off (Alert Email Drafts → Mail server).'];
        }

        if ($to === []) {
            return ['ok' => false, 'why' => 'Nobody to send to: no email address on the employee, manager or chosen roles.'];
        }

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.55;color:#1f2937">'
            . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</div>';

        return (new Smtp())->send($s, $to, $cc, $subject, $body, $html, 20 + (int) (array_sum(array_map(static fn ($a): int => strlen((string) $a[1]), $attachments)) / 200000), $attachments);
    }

    /**
     * Send everything due, log each attempt, at most $limit messages.
     *
     * @param list<array{alert: string, key: string, employee: array<string, mixed>, values: array<string, string>}> $due
     * @param array<string, array<string, mixed>> $employeesById
     * @param list<array<string, mixed>> $users
     * @return array{sent: int, failed: int, skipped: int, problems: list<string>}
     */
    public function dispatch(array $due, array $employeesById, array $users, ?int $limit = null): array
    {
        $alerts = $this->alerts();
        $limit ??= (int) $this->smtp()['max_per_run'];
        $sent = $failed = $skipped = 0;
        $problems = [];

        foreach ($due as $d) {
            if ($this->alreadySent($d['key'])) {
                $skipped++;
                continue;
            }

            if ($sent + $failed >= $limit) {
                $problems[] = 'Stopped at ' . $limit . ' emails for this run; the rest go on the next run.';
                break;
            }

            $cfg = $alerts[$d['alert']];
            $addr = self::addresses((array) $cfg['recipients'], $d['employee'], $employeesById, $users);
            $subject = self::render((string) $cfg['subject'], $d['values']);
            $body = self::render((string) $cfg['body'], $d['values']);
            $r = $this->send($addr['to'], $addr['cc'], $subject, $body);
            $now = date('Y-m-d H:i:s');
            $this->store->put(self::LOG, ['kind' => 'email.' . $d['alert'], 'title' => $subject, 'body' => Redactor::redact($body),
                'channels' => 'email', 'email_status' => $r['ok'] ? Outbox::SENT : Outbox::FAILED,
                // A FAILED attempt gets its own key, so the next run tries again.
                'dedupe_key' => $r['ok'] ? 'mail:' . $d['key'] : 'mailfail:' . $d['key'] . ':' . $now,
                'meta' => json_encode(['recipient' => Employment::code($d['employee']), 'to' => $addr['to'], 'cc' => $addr['cc'], 'result' => $r['why']], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now]);
            $r['ok'] ? $sent++ : $failed++;
            $r['ok'] || $problems[] = Employment::code($d['employee']) . ' — ' . $cfg['label'] . ': ' . $r['why'];
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'problems' => $problems];
    }
}
