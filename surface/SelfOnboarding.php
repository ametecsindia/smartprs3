<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SELF-ONBOARDING: THE JOINER FILLS IN THE REGISTER, HR VERIFIES IT, THEN IT BECOMES
 * AN EMPLOYMENT.
 *
 * SmartPRS 2's SelfOnboardingController (15 routes, 58 KB) keeps a `self_onboarding`
 * row per invitation: a token link the candidate opens without an account, the
 * form they fill, the documents they upload, and an HR console that verifies each
 * field and "injects" the record into `employees`. The table is ported as it was;
 * what this class refuses to port is the SECOND FIELD LIST. SmartPRS 2 hard-coded
 * the candidate form's fields in the controller, so a field HR added under
 * settings never reached the joiner. Here the form is `EmployeeFields::inUse()` —
 * the same list the Add form, the import sample and the ID card read — so the
 * customer's Organization Setup IS the onboarding form.
 *
 * WHAT IS HELD BACK FROM THE CANDIDATE: the employee code (HR assigns it at
 * injection), the contracted cost, the status and the pay basis. A joiner who can
 * type their own CTC is a payroll defect waiting for its first month.
 *
 * NOTHING IS DELETED. A withdrawn invitation is `disabled`; a submission HR sends
 * back is `correction` with the note kept in `flags`, and the link opens again.
 *
 * ponytail: no OTP. SmartPRS 2 verified email and mobile with codes it sent through
 * a transport; this build has none, so an OTP would be a code nobody receives. The
 * token IS the credential — 32 random bytes, expiring — and HR's "verified" tick
 * per field is the human check the OTP was standing in for. Add OTP when a
 * transport exists; `self_onboarding_otps` is already there for it.
 */
final class SelfOnboarding
{
    public const COLLECTION = 'self_onboarding';

    public const INVITED = 'invited';
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const CORRECTION = 'correction';
    public const INJECTED = 'injected';
    public const DISABLED = 'disabled';

    /** Fields the joiner never sets — HR does, at injection or on the record. */
    public const HELD_BACK = ['emp_code', 'ctc', 'status', 'salary_type', 'comm_pct'];

    /** The codes HR keeps for itself (config `hr_fields`, always including HELD_BACK): never on the joiner's page. */
    public function hrFields(): array
    {
        return array_values(array_unique(array_merge(self::HELD_BACK, array_map('strval', (array) ($this->config()['hr_fields'] ?? [])))));
    }

    /** The joiner's own fields: everything enabled, minus HR's. @param list<array<string, mixed>> $fields */
    public function joinerFields(array $fields): array
    {
        $hr = $this->hrFields();

        return array_values(array_filter($fields, static fn (array $f): bool => !in_array((string) $f['code'], $hr, true)));
    }

    /** HR's fields, in register order. @param list<array<string, mixed>> $fields */
    public function hrOnly(array $fields): array
    {
        $hr = $this->hrFields();

        return array_values(array_filter($fields, static fn (array $f): bool => in_array((string) $f['code'], $hr, true)
            && !in_array((string) $f['code'], self::HELD_BACK, true)));
    }

    /**
     * HR corrects the name, email or mobile on an invitation (a typo is the
     * usual reason the link never arrived) and, when asked, issues a NEW link —
     * the old token stops opening, so a link sent to the wrong address dies.
     */
    public function update(array $row, string $name, string $email, string $mobile, string $by, bool $newLink): array
    {
        $name = trim($name);
        $email = trim($email);
        $mobile = trim($mobile);

        if (in_array((string) $row['status'], [self::INJECTED, self::DISABLED], true)) {
            return ['ok' => false, 'why' => 'An injected or disabled invitation is not edited; invite again.'];
        }

        if ($name === '') {
            return ['ok' => false, 'why' => 'The joiner needs a name.'];
        }

        if ($email === '' && $mobile === '') {
            return ['ok' => false, 'why' => 'Keep an email or a mobile number — it is how the link reaches them.'];
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'why' => "'{$email}' is not an email address."];
        }

        $config = $this->config();
        $changes = ['id' => (string) $row['id'], 'name' => $name, 'email' => $email !== '' ? $email : null, 'mobile' => $mobile !== '' ? $mobile : null];

        if ($newLink) {
            $changes['token'] = bin2hex(random_bytes(32));
            $changes['link_expires_at'] = date('Y-m-d H:i:s', strtotime('+' . (int) $config['link_days'] . ' days'));
        }

        $flags = self::flags($row);
        $flags['edits'][] = ['by' => $by, 'on' => date('c'), 'name' => $name, 'email' => $email, 'mobile' => $mobile, 'new_link' => $newLink];
        $changes['flags'] = json_encode($flags);
        $saved = $this->store->put(self::COLLECTION, $changes);

        return ['ok' => true, 'row' => $saved, 'why' => "Updated {$name}'s details" . ($newLink ? ' and issued a new link; the old one no longer opens.' : '.')];
    }

    public function __construct(
        private readonly RowStore $store,
        // Days a link stays open. From config/self-onboarding.json, never a literal here.
        private readonly string $configFile,
    ) {
    }

    /** @return array{link_days: int, temp_code_prefix: string} */
    public function config(): array
    {
        $decoded = is_file($this->configFile) ? json_decode((string) file_get_contents($this->configFile), true) : null;

        if (!is_array($decoded) || !isset($decoded['link_days'], $decoded['temp_code_prefix'])) {
            throw new \RuntimeException("config/self-onboarding.json is missing or unreadable at {$this->configFile}.");
        }

        return ['link_days' => (int) $decoded['link_days'], 'temp_code_prefix' => (string) $decoded['temp_code_prefix'],
            'hr_fields' => array_map('strval', (array) ($decoded['hr_fields'] ?? []))];
    }

    /** @return list<array<string, mixed>> newest first */
    public function all(?string $status = null): array
    {
        $rows = array_values(array_filter($this->store->all(self::COLLECTION),
            static fn (array $r): bool => ($status === null || (string) ($r['status'] ?? '') === $status)
                && (string) ($r['deleted_at'] ?? '') === ''));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $rows;
    }

    public function find(string $id): ?array
    {
        $row = $this->store->find(self::COLLECTION, $id);

        return $row === null || (string) ($row['deleted_at'] ?? '') !== '' ? null : $row;
    }

    /** The row a link opens, or null when the token is unknown, disabled or expired. */
    public function byToken(string $token): ?array
    {
        if (strlen($token) < 32) {
            return null;
        }

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if (hash_equals((string) ($row['token'] ?? ''), $token)) {
                if ((string) ($row['status'] ?? '') === self::DISABLED || (string) ($row['deleted_at'] ?? '') !== '') {
                    return null;
                }

                if ((string) ($row['link_expires_at'] ?? '') !== '' && (string) $row['link_expires_at'] < date('Y-m-d H:i:s')) {
                    return null;
                }

                return $row;
            }
        }

        return null;
    }

    /**
     * Invite one joiner (mode `new`) or an existing employee (mode `existing`).
     *
     * @return array{ok: bool, why: string, row?: array<string, mixed>}
     */
    public function invite(string $name, string $email, string $mobile, string $by, string $mode = 'new',
        ?string $employeeId = null, string $today = '', array $link = []): array
    {
        $name = trim($name);
        $email = trim($email);
        $mobile = trim($mobile);

        if ($name === '') {
            return ['ok' => false, 'why' => 'An invitation needs the joiner\'s name.'];
        }

        if ($email === '' && $mobile === '') {
            return ['ok' => false, 'why' => 'An invitation needs an email or a mobile number — it is how the link reaches them.'];
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'why' => "'{$email}' is not an email address."];
        }

        foreach ($this->all() as $open) {
            if (in_array((string) $open['status'], [self::INVITED, self::DRAFT, self::SUBMITTED, self::CORRECTION], true)
                && (($email !== '' && strcasecmp((string) ($open['email'] ?? ''), $email) === 0)
                    || ($mobile !== '' && (string) ($open['mobile'] ?? '') === $mobile))) {
                return ['ok' => false, 'why' => "{$open['name']} already has an open invitation ({$open['temp_emp_code']}, {$open['status']}). "
                    . 'Disable it first if this is a fresh start; two open links for one person end with two records.'];
            }
        }

        $config = $this->config();
        $today = $today === '' ? date('Y-m-d') : $today;
        $row = $this->store->put(self::COLLECTION, [
            'uuid' => bin2hex(random_bytes(16)),
            'token' => bin2hex(random_bytes(32)),
            'temp_emp_code' => $this->nextTempCode($config['temp_code_prefix'], $today),
            'employee_id' => $mode === 'existing' && $employeeId !== null && $employeeId !== '' ? $employeeId : null,
            'mode' => $mode === 'existing' ? 'existing' : 'new',
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'email_verified' => 0,
            'mobile' => $mobile !== '' ? $mobile : null,
            'mobile_verified' => 0,
            'data' => json_encode(['columns' => [], 'extras' => []]),
            // THE CANDIDATE IT CAME FROM (owner, 21 Oct flow): `candidate_id` in the flags, so approval
            // marks that candidate hired and nothing is created twice.
            'flags' => json_encode(['invited_by' => $by] + $link),
            'progress' => 0,
            'status' => self::INVITED,
            'link_expires_at' => date('Y-m-d H:i:s', strtotime($today . ' +' . $config['link_days'] . ' days')),
        ]);

        return ['ok' => true, 'row' => $row, 'why' => "Invited {$name} as {$row['temp_emp_code']}; the link is open for {$config['link_days']} days."];
    }

    /**
     * Invite from a CSV with a header row naming `name`, `email`, `mobile`.
     *
     * @return array{invited: int, notes: list<string>}
     */
    public function bulkInvite(string $csv, string $by): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $header = array_map(static fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv((string) array_shift($lines)));
        $at = static fn (array $cells, string $col): string => trim((string) ($cells[array_search($col, $header, true)] ?? ''));
        $invited = 0;
        $notes = [];

        if (!in_array('name', $header, true)) {
            return ['invited' => 0, 'notes' => ['The first row must name the columns: name, email, mobile.']];
        }

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = str_getcsv($line);
            $r = $this->invite($at($cells, 'name'), $at($cells, 'email'), $at($cells, 'mobile'), $by);
            $r['ok'] ? $invited++ : $notes[] = 'Line ' . ($i + 2) . ': ' . $r['why'];
        }

        return ['invited' => $invited, 'notes' => $notes];
    }

    /**
     * The candidate saves (draft) or submits their form.
     *
     * @param array<string, string> $columns  employees-table values, by column
     * @param array<string, string> $extras   extra-field values, by field code
     * @param list<array<string, mixed>> $fields the fields in use, to measure progress
     * @return array{ok: bool, why: string, progress: int}
     */
    public function save(array $row, array $columns, array $extras, array $fields, bool $submit): array
    {
        if (!in_array((string) $row['status'], [self::INVITED, self::DRAFT, self::CORRECTION], true)) {
            return ['ok' => false, 'why' => 'This form is ' . $row['status'] . ' and no longer accepts changes.', 'progress' => (int) ($row['progress'] ?? 0)];
        }

        $data = self::data($row);
        // MERGE, NOT REPLACE: a file uploaded on the first visit survives a
        // second visit that fills in the address.
        $data['columns'] = array_filter($columns, static fn ($v): bool => (string) $v !== '') + $data['columns'];
        $data['extras'] = array_filter($extras, static fn ($v): bool => (string) $v !== '') + $data['extras'];

        $missing = [];
        $askable = 0;

        foreach ($fields as $f) {
            if (in_array((string) $f['code'], self::HELD_BACK, true)) {
                continue;
            }

            $askable++;
            $value = ($f['storage'] ?? 'column') === 'column' ? ($data['columns'][(string) $f['column']] ?? '') : ($data['extras'][(string) $f['code']] ?? '');

            if (($f['required'] ?? false) && (string) $value === '') {
                $missing[] = (string) $f['label'];
            }
        }

        $filled = 0;
        foreach ($fields as $f) {
            if (in_array((string) $f['code'], self::HELD_BACK, true)) { continue; }
            $value = ($f['storage'] ?? 'column') === 'column' ? ($data['columns'][(string) $f['column']] ?? '') : ($data['extras'][(string) $f['code']] ?? '');
            $filled += (string) $value === '' ? 0 : 1;
        }
        $progress = $askable === 0 ? 100 : intdiv($filled * 100, $askable);

        if ($submit && $missing !== []) {
            $this->store->put(self::COLLECTION, ['id' => $row['id'], 'data' => json_encode($data), 'progress' => $progress, 'status' => self::DRAFT]);

            return ['ok' => false, 'why' => 'Saved as a draft, not submitted: still needed — ' . implode(', ', $missing) . '.', 'progress' => $progress];
        }

        $this->store->put(self::COLLECTION, ['id' => $row['id'], 'data' => json_encode($data), 'progress' => $progress,
            'status' => $submit ? self::SUBMITTED : self::DRAFT,
            'submitted_at' => $submit ? date('Y-m-d H:i:s') : ($row['submitted_at'] ?? null)]);

        return ['ok' => true, 'why' => $submit ? 'Submitted. HR will verify what you entered and tell you if anything needs correcting.'
            : "Saved ({$progress}% filled). Come back to this link to finish.", 'progress' => $progress];
    }

    /** HR ticks fields as verified and marks email / mobile verified. */
    public function setFlags(array $row, array $verified, bool $emailVerified, bool $mobileVerified, string $by): array
    {
        $flags = self::flags($row);
        $flags['verified'] = array_values(array_map('strval', $verified));
        $flags['verified_by'] = $by;
        $flags['verified_at'] = date('Y-m-d H:i:s');
        $this->store->put(self::COLLECTION, ['id' => $row['id'], 'flags' => json_encode($flags),
            'email_verified' => $emailVerified ? 1 : 0, 'mobile_verified' => $mobileVerified ? 1 : 0]);

        return ['ok' => true, 'why' => count($flags['verified']) . ' field(s) marked verified.'];
    }

    /** Send it back: the link opens again, the note travels with it. */
    public function correction(array $row, string $note, string $by): array
    {
        if ((string) $row['status'] !== self::SUBMITTED) {
            return ['ok' => false, 'why' => 'Only a submitted form can be sent back for correction.'];
        }

        if (trim($note) === '') {
            return ['ok' => false, 'why' => 'Say what needs correcting — a form sent back with no note comes back unchanged.'];
        }

        $flags = self::flags($row);
        $flags['correction'] = trim($note);
        $flags['correction_by'] = $by;
        $config = $this->config();
        $this->store->put(self::COLLECTION, ['id' => $row['id'], 'status' => self::CORRECTION, 'flags' => json_encode($flags),
            'link_expires_at' => date('Y-m-d H:i:s', strtotime('+' . $config['link_days'] . ' days'))]);

        return ['ok' => true, 'why' => 'Sent back for correction; the link is open again for ' . $config['link_days'] . ' days.'];
    }

    public function disable(array $row, string $by): array
    {
        if ((string) $row['status'] === self::INJECTED) {
            return ['ok' => false, 'why' => 'An injected record is an employee now; disable the employee, not the invitation.'];
        }

        $flags = self::flags($row);
        $flags['disabled_by'] = $by;
        $this->store->put(self::COLLECTION, ['id' => $row['id'], 'status' => self::DISABLED, 'flags' => json_encode($flags),
            'link_disabled_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => 'Invitation disabled; the link no longer opens.'];
    }

    /**
     * Mark injected. The employee itself is created by the caller (the same
     * path the Add form uses), because that path owns the code, the opening
     * leave balances and the audit line; this records which employee came of it.
     */
    public function markInjected(array $row, string $employeeId, string $by): void
    {
        $flags = self::flags($row);
        $flags['injected_by'] = $by;
        $this->store->put(self::COLLECTION, ['id' => $row['id'], 'status' => self::INJECTED, 'employee_id' => $employeeId,
            'flags' => json_encode($flags), 'approved_at' => date('Y-m-d H:i:s')]);
    }

    /** The latest invitation (not disabled) made from a recruitment candidate, or null. */
    public function forCandidate(string $candidateId): ?array
    {
        foreach ($this->all() as $r) {
            if ((string) $r['status'] !== self::DISABLED && (string) (self::flags($r)['candidate_id'] ?? '') === $candidateId) {
                return $r;
            }
        }

        return null;
    }

    /** @return array{columns: array<string, mixed>, extras: array<string, mixed>} */
    public static function data(array $row): array
    {
        $d = json_decode((string) ($row['data'] ?? ''), true);

        return ['columns' => (array) ($d['columns'] ?? []), 'extras' => (array) ($d['extras'] ?? [])];
    }

    /** @return array<string, mixed> */
    public static function flags(array $row): array
    {
        $f = json_decode((string) ($row['flags'] ?? ''), true);

        return is_array($f) ? $f : [];
    }

    /** How many documents (file-typed fields) the candidate has uploaded. */
    public static function documentCount(array $row, array $fields): int
    {
        $data = self::data($row);
        $n = 0;

        foreach ($fields as $f) {
            if ((string) ($f['type'] ?? '') !== 'Image/PDF/Doc') {
                continue;
            }

            $v = ($f['storage'] ?? 'column') === 'column' ? ($data['columns'][(string) $f['column']] ?? '') : ($data['extras'][(string) $f['code']] ?? '');
            $n += (string) $v === '' ? 0 : 1;
        }

        return $n;
    }

    private function nextTempCode(string $prefix, string $today): string
    {
        $year = substr($today, 0, 4);
        $max = 0;

        foreach ($this->store->all(self::COLLECTION) as $r) {
            if (preg_match('/^' . preg_quote($prefix . '-' . $year . '-', '/') . '(\d+)$/', (string) ($r['temp_emp_code'] ?? ''), $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $max + 1);
    }
}
