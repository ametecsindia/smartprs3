<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * EXPLORE DEMO — every visitor gets their OWN demo company (owner, 12 Nov 2026; SmartPRS 2 rev 185's passkey gate kept).
 *
 * A visitor fills the form on /demo (or taps Explore Demo on the sign-in page / in the mobile app) → a 6-digit passkey
 * by email + WhatsApp → the passkey makes their own company (tenant code DEMO-<request id>) and signs them in as its
 * administrator → surface/DemoSeed.php fills it (one company, two branches, people, logins, two months of attendance,
 * leaves and payroll). It lasts `days` days (config/demo-seed.json; Super Admin → Demo Requests can change it and extend
 * one request). When it ends or is revoked the visitor is signed out (partials/boot-hooks.php) and sweep() deletes that
 * company's rows — every table with a tenant_id — and stamps wiped_at.
 *
 * THE GOOGLE PLAY REVIEWER: one fixed company (workspace slug in config `reviewer`) whose administrator login the Super
 * Admin sets on Demo Requests. No passkey, no activation code, no phone approval; sweep() empties it once a night and
 * the next sign-in rebuilds it.
 */
final class DemoGate
{
    public const CODE_PREFIX = 'DEMO-';

    /** @var array<string, mixed> */
    private readonly array $cfg;

    public function __construct(private readonly SqlStore $platform, private readonly PlatformSettings $settings, private readonly string $envFile)
    {
        $this->cfg = (array) json_decode((string) @file_get_contents(dirname($envFile) . '/config/demo-seed.json'), true);
    }

    /** @return array<string, mixed> config/demo-seed.json */
    public function config(): array
    {
        return $this->cfg;
    }

    public function days(): int
    {
        $d = (int) ($this->settings->get('demo_gate')['days'] ?? 0);

        return $d >= 1 ? min($d, $this->maxDays()) : max(1, (int) ($this->cfg['days'] ?? 7));
    }

    public function maxDays(): int
    {
        return max(1, (int) ($this->cfg['max_days'] ?? 30));
    }

    public function setDays(int $days): void
    {
        $this->settings->put('demo_gate', ['days' => max(1, min($days, $this->maxDays()))] + (array) $this->settings->get('demo_gate'));
    }

    /** Push one request's end date out by `days` from now (or from its current end, whichever is later), and re-open it. */
    public function extend(array $row, int $days): string
    {
        $base = max(time(), (int) strtotime((string) $row['expires_at']));
        $until = date('Y-m-d H:i:s', $base + max(1, min($days, $this->maxDays())) * 86400);
        $this->platform->put('demo_requests', ['id' => $row['id'], 'status' => 'active', 'expires_at' => $until, 'updated_at' => date('Y-m-d H:i:s')]);

        return $until;
    }

    public static function isDemoTenant(?array $tenant): bool
    {
        return $tenant !== null && str_starts_with((string) ($tenant['code'] ?? ''), self::CODE_PREFIX);
    }

    /** The visitor's own demo company, if it has been made. @param array<string, mixed> $row */
    public function tenantOf(array $row): ?array
    {
        return $this->tenantByCode(self::CODE_PREFIX . $row['id']);
    }

    private function tenantByCode(string $code): ?array
    {
        foreach ($this->platform->all('tenants') as $t) {
            if ((string) ($t['code'] ?? '') === $code) {
                return $t;
            }
        }

        return null;
    }

    /** The reviewer company and its administrator login (null when the Super Admin has not set it up). @return array{tenant: array<string, mixed>, user: ?array<string, mixed>}|null */
    public function reviewer(): ?array
    {
        $t = $this->tenantByCode(self::CODE_PREFIX . 'PLAY');

        return $t === null ? null : ['tenant' => $t, 'user' => $this->adminOf((string) $t['id'])];
    }

    private function adminOf(string $tenantId): ?array
    {
        $users = array_filter($this->platform->all('users'), static fn (array $u): bool => (string) ($u['tenant_id'] ?? '') === $tenantId
            && (string) ($u['role'] ?? $u['status'] ?? '') === 'administrator' && trim((string) ($u['employee_id'] ?? '')) === '');
        usort($users, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $users[0] ?? null;
    }

    /**
     * Make (or find) a demo company: the tenant, its company row, QR attendance with phones approved automatically, and an
     * administrator login. Seeding happens after sign-in (surface/actions/demo-setup.php), inside the application.
     *
     * @return array{tenant: array<string, mixed>, user: array<string, mixed>}
     */
    private function shell(string $code, string $subdomain, string $name, string $adminEmail, string $password): array
    {
        $tenant = $this->tenantByCode($code) ?? $this->platform->put('tenants', ['code' => $code, 'name' => $name, 'state' => (string) ($this->cfg['state'] ?? ''),
            'subdomain' => $subdomain, 'status_code' => 'active', 'onboarded_on' => date('Y-m-d')]);
        $tid = (string) $tenant['id'];
        $store = $this->platform->forTenant($tid);
        $company = $store->all('companies')[0] ?? $store->put('companies', ['name' => $name]);
        $custom = new Customisation($store);
        $qr = (array) ($custom->live()['qr-attendance'] ?? []);

        if ((string) (((array) ($qr['phone_approval'] ?? []))[(string) $company['id']] ?? '') !== 'auto') {
            $qr['sources'] = [(string) $company['id'] => QrAttendance::SOURCE] + (array) ($qr['sources'] ?? []);
            $qr['phone_approval'] = [(string) $company['id'] => 'auto'] + (array) ($qr['phone_approval'] ?? []);
            $custom->saveRaw('qr-attendance', $qr, 'demo', 'Explore Demo', 'Demo company: QR attendance, phones approved automatically');
        }

        $user = $this->adminOf($tid);

        if ($user === null) {
            $made = (new Users($store, new Authorisation(), \SmartPRS\Platform\Identity\PasswordHasher::platformDefault()))->create($adminEmail, $password, 'administrator');

            if (!$made['ok']) {
                throw new \RuntimeException('The demo administrator was not created: ' . $made['why']);
            }

            $user = $made['user'];
        }

        return ['tenant' => $tenant, 'user' => $user];
    }

    /** The visitor's own company, made on their first sign-in. @param array<string, mixed> $row a demo_requests row @return array{tenant: array<string, mixed>, user: array<string, mixed>} */
    public function provision(array $row): array
    {
        $id = (string) $row['id'];
        $name = trim((string) ($row['company'] ?? '')) !== '' ? (string) $row['company'] : (string) $row['name'] . ' (demo)';

        return $this->shell(self::CODE_PREFIX . $id, 'demo' . $id, substr($name, 0, 150), 'admin@c' . $id . '.' . (string) ($this->cfg['email_domain'] ?? 'smartprs.demo'),
            bin2hex(random_bytes(16))); // ponytail: nobody types this password — the passkey is the way in
    }

    /** Set the Google Play reviewer's login (made on first use; the password is replaced on later saves). @return array{ok: bool, why: string} */
    public function setReviewer(string $email, string $password, string $actorId): array
    {
        $rcfg = (array) ($this->cfg['reviewer'] ?? []);
        $current = $this->reviewer();

        if ($current !== null && $current['user'] !== null) {
            $users = new Users($this->platform->forTenant((string) $current['tenant']['id']), new Authorisation(), \SmartPRS\Platform\Identity\PasswordHasher::platformDefault());
            $set = $users->setPassword((string) $current['user']['id'], $password, $actorId);

            if (!$set['ok']) {
                return $set;
            }

            strcasecmp((string) $current['user']['email'], $email) !== 0 && $this->platform->put('users', ['id' => $current['user']['id'], 'email' => $email]);

            return ['ok' => true, 'why' => 'Reviewer login updated.'];
        }

        if (strlen($password) < Session::PASSWORD_FLOOR) {
            return ['ok' => false, 'why' => 'The password needs at least ' . Session::PASSWORD_FLOOR . ' characters.'];
        }

        try {
            $this->shell(self::CODE_PREFIX . 'PLAY', (string) ($rcfg['subdomain'] ?? 'playreview'), (string) ($rcfg['company'] ?? 'SmartPRS Demo'), $email, $password);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'why' => $e->getMessage()];
        }

        return ['ok' => true, 'why' => 'Reviewer company made. Its demo data is built on the first sign-in.'];
    }

    /**
     * DELETE A DEMO COMPANY'S DATA: every row of every table that has a tenant_id. With $keepShell the tenant row, its company
     * settings shell and the administrator login stay (the reviewer company is emptied, not removed).
     */
    public function wipe(\PDO $pdo, string $tenantId, bool $keepShell = false): void
    {
        $t = $this->platform->find('tenants', $tenantId);

        if (!self::isDemoTenant($t)) {
            throw new \RuntimeException('Refused: tenant ' . $tenantId . ' is not a demo company.'); // a real company is never wiped here
        }

        $keepUser = $keepShell ? (string) ($this->adminOf($tenantId)['id'] ?? '') : '';
        $tables = $pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id'")->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $sql = 'DELETE FROM `' . str_replace('`', '', (string) $table) . '` WHERE tenant_id = ?' . ($table === 'users' && $keepUser !== '' ? ' AND id <> ?' : '');
                $pdo->prepare($sql)->execute($table === 'users' && $keepUser !== '' ? [$tenantId, $keepUser] : [$tenantId]);
            }

            $keepShell || $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        if ($keepShell) {
            $this->shell((string) $t['code'], (string) $t['subdomain'], (string) $t['name'], '', '');
        }
    }

    /**
     * CONVERT A DEMO INTO A REAL CLIENT COMPANY (owner, 12 Nov 2026). The company gets a real code and sign-in address — so
     * the sweep never deletes it and it is no longer treated as a demo — a real administrator login, and phones need approval
     * again. With $removeSample the sample data goes and everything the visitor added stays.
     *
     * @param array<string, mixed> $row a demo_requests row
     * @return array{ok: bool, why: string}
     */
    public function convert(\PDO $pdo, array $row, string $code, string $slug, string $adminEmail, string $password, bool $removeSample, string $actorId): array
    {
        $t = $this->tenantOf($row);
        $code = strtoupper(trim($code));
        $slug = (string) preg_replace('/[^a-z0-9]/', '', strtolower($slug));

        if ($t === null) {
            return ['ok' => false, 'why' => 'This visitor never opened their demo company, so there is nothing to convert.'];
        }
        if ($code === '' || str_starts_with($code, self::CODE_PREFIX) || $slug === '') {
            return ['ok' => false, 'why' => 'Give the company a code (not starting DEMO-) and a sign-in address.'];
        }
        foreach ($this->platform->all('tenants') as $o) {
            if ((string) $o['id'] !== (string) $t['id'] && (strcasecmp((string) $o['code'], $code) === 0 || (string) ($o['subdomain'] ?? '') === $slug)) {
                return ['ok' => false, 'why' => 'Code ' . $code . ' or address /c/' . $slug . ' is already used by ' . $o['name'] . '.'];
            }
        }

        $tid = (string) $t['id'];
        $store = $this->platform->forTenant($tid);
        $users = new Users($store, new Authorisation(), \SmartPRS\Platform\Identity\PasswordHasher::platformDefault());
        $demoAdmin = $this->adminOf($tid);
        $made = $users->create($adminEmail, $password, 'administrator');

        if (!$made['ok']) {
            return $made;
        }

        $removeSample && $this->removeSample($pdo, $tid);
        $demoAdmin !== null && $users->setDisabled((string) $demoAdmin['id'], true, $actorId);
        $this->platform->put('tenants', ['id' => $tid, 'code' => $code, 'subdomain' => $slug, 'onboarded_on' => date('Y-m-d')]);

        $custom = new Customisation($store);
        $qr = (array) ($custom->live()['qr-attendance'] ?? []);
        if (isset($qr['phone_approval'])) {
            $qr['phone_approval'] = array_diff_key((array) $qr['phone_approval'], array_flip(array_map(static fn (array $c): string => (string) $c['id'], $store->all('companies'))));
            $custom->saveRaw('qr-attendance', $qr, $actorId, 'Super Admin', 'Converted from Explore Demo: phones need approval again');
        }

        $this->platform->put('demo_requests', ['id' => $row['id'], 'status' => 'converted', 'updated_at' => date('Y-m-d H:i:s')]);

        return ['ok' => true, 'why' => (string) $t['name'] . ' is now a client company — sign-in /c/' . $slug . ' as ' . $adminEmail . ($removeSample ? '; the sample data was removed, the visitor\'s own data kept.' : '; the sample data was kept.')];
    }

    /**
     * REMOVE ONLY THE SAMPLE DATA of a demo company: the sample people (and every row that is theirs — punches, leaves, ledger,
     * logins, phones, events), the sample payroll months, and the sample branches, departments, designations and teams that
     * none of the visitor's own employees uses. Whatever the visitor added stays.
     */
    public function removeSample(\PDO $pdo, string $tenantId): void
    {
        $store = $this->platform->forTenant($tenantId);
        $done = (array) ((new Customisation($store))->live()[DemoSeed::DONE_KEY] ?? []);
        $s = (array) ($done['sample'] ?? []);
        $domain = '@c' . $tenantId . '.' . (string) ($this->cfg['email_domain'] ?? 'smartprs.demo');

        if (($s['employees'] ?? []) === []) { // a demo built before the sample list was kept: its people are known by their email domain
            foreach ($store->all('employees') as $e) {
                if (str_ends_with(strtolower((string) ($e['email'] ?? '')), $domain)) {
                    $s['employees'][] = (string) $e['id'];
                    $s['codes'][] = (string) ($e['emp_code'] ?? '');
                }
            }
        }

        $keys = array_values(array_filter(array_unique(array_merge((array) ($s['employees'] ?? []), (array) ($s['codes'] ?? []))), static fn (string $k): bool => $k !== ''));
        $in = static fn (array $v): string => implode(',', array_fill(0, max(1, count($v)), '?'));

        if ($keys !== []) {
            $cols = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND COLUMN_NAME IN ('emp_code','employment_id','employee_id','employee_code','employment_code')
                AND TABLE_NAME IN (SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id')")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                $pdo->prepare('DELETE FROM `' . str_replace('`', '', (string) $c['TABLE_NAME']) . '` WHERE tenant_id = ? AND `' . str_replace('`', '', (string) $c['COLUMN_NAME']) . '` IN (' . $in($keys) . ')')
                    ->execute(array_merge([$tenantId], $keys));
            }
            $pdo->prepare('DELETE FROM employees WHERE tenant_id = ? AND id IN (' . $in($s['employees']) . ')')->execute(array_merge([$tenantId], (array) $s['employees']));
            // The visitor's own people who reported to a sample manager, and teams led by one, are left without one, not pointing at nobody.
            $ids = array_merge([$tenantId], (array) $s['employees']);
            $pdo->prepare('UPDATE employees SET reporting_manager_id = NULL WHERE tenant_id = ? AND reporting_manager_id IN (' . $in($s['employees']) . ')')->execute($ids);
            foreach (['leader_id', 'manager_id'] as $tc) {
                $pdo->prepare('UPDATE teams SET ' . $tc . ' = NULL WHERE tenant_id = ? AND ' . $tc . ' IN (' . $in($s['employees']) . ')')->execute($ids);
            }
        }

        if (($s['payroll'] ?? []) !== []) {
            $pdo->prepare('DELETE FROM payroll_runs WHERE tenant_id = ? AND cycle_label IN (' . $in($s['payroll']) . ')')->execute(array_merge([$tenantId], (array) $s['payroll']));
        }

        // Organisation rows go only when none of the visitor's own employees still uses them.
        $used = ['teams' => 'team_id', 'branches' => 'branch_id', 'departments' => 'department_id', 'designations' => 'designation_id'];
        $left = $store->all('employees');
        foreach ($used as $table => $col) {
            $inUse = array_flip(array_map(static fn (array $e): string => (string) ($e[$col] ?? ''), $left));
            $gone = array_values(array_filter((array) ($s[$table] ?? []), static fn (string $id): bool => !isset($inUse[$id])));
            $gone !== [] && $pdo->prepare('DELETE FROM `' . $table . '` WHERE tenant_id = ? AND id IN (' . $in($gone) . ')')->execute(array_merge([$tenantId], $gone));
        }
    }

    /**
     * THE SWEEP (tools/cron.php, and every /demo page load): ended or revoked demos are deleted; the reviewer company is
     * emptied once a day after reset_hour. @return list<string> what it did
     */
    public function sweep(\PDO $pdo): array
    {
        $did = [];

        foreach ($this->requests() as $r) {
            if (!in_array((string) $r['status'], ['active', 'converted'], true) && trim((string) ($r['wiped_at'] ?? '')) === '') {
                $t = $this->tenantOf($r);
                $t !== null && $this->wipe($pdo, (string) $t['id']);
                $this->platform->put('demo_requests', ['id' => $r['id'], 'wiped_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $did[] = 'demo ' . $r['id'] . ' deleted';
            }
        }

        $rv = $this->reviewer();
        $gate = (array) $this->settings->get('demo_gate');

        if ($rv !== null && (int) date('G') >= (int) ($this->cfg['reviewer']['reset_hour'] ?? 2) && (string) ($gate['reviewer_reset_on'] ?? '') !== date('Y-m-d')) {
            $this->wipe($pdo, (string) $rv['tenant']['id'], true);
            $this->settings->put('demo_gate', ['reviewer_reset_on' => date('Y-m-d')] + $gate);
            $did[] = 'reviewer company emptied';
        }

        return $did;
    }

    /** @return list<array<string, mixed>> newest first, overdue ones marked expired */
    public function requests(): array
    {
        $rows = $this->platform->all('demo_requests');
        $now = date('Y-m-d H:i:s');

        foreach ($rows as &$r) {
            if ((string) $r['status'] === 'active' && (string) $r['expires_at'] < $now) {
                $this->platform->put('demo_requests', ['id' => $r['id'], 'status' => 'expired', 'updated_at' => $now]);
                $r['status'] = 'expired';
            }
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

        return $rows;
    }

    /** Ten Indian digits from whatever the visitor typed, or null. */
    public static function mobile(string $raw): ?string
    {
        if (preg_match('/[^0-9+() -]/', trim($raw)) === 1) {
            return null;
        }

        $d = (string) preg_replace('/\D+/', '', $raw);
        $d = match (true) {
            strlen($d) === 13 && str_starts_with($d, '091') => substr($d, 3),
            strlen($d) === 12 && str_starts_with($d, '91') => substr($d, 2),
            strlen($d) === 11 && $d[0] === '0' => substr($d, 1),
            default => $d,
        };

        return preg_match('/^[6-9]\d{9}$/', $d) === 1 ? $d : null;
    }

    /**
     * A still-valid passkey for this mobile, or a new one (and a lead row for the sales desk).
     *
     * @return array<string, mixed>
     */
    public function request(string $name, string $mobile, string $email, string $company): array
    {
        foreach ($this->requests() as $r) {
            if ((string) $r['phone'] === $mobile && (string) $r['status'] === 'active') {
                return $r;
            }
        }

        $active = array_column(array_filter($this->requests(), static fn (array $r): bool => (string) $r['status'] === 'active'), 'pin');

        do {
            $pin = (string) random_int(100000, 999999);
        } while (in_array($pin, $active, true));

        $now = date('Y-m-d H:i:s');
        $row = $this->platform->put('demo_requests', ['name' => $name, 'phone' => $mobile, 'email' => $email, 'company' => $company ?: null,
            'entry' => 'demo', 'pin' => $pin, 'status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() + $this->days() * 86400),
            'created_at' => $now, 'updated_at' => $now]);

        try {
            $this->platform->put('leads', ['name' => $name, 'company' => $company ?: '-', 'city' => '-', 'mobile' => $mobile, 'email' => $email,
                'challenges' => 'Requested a live-demo passkey (/demo)', 'source' => 'live_demo', 'created_at' => date('c')]);
        } catch (\Throwable $e) {
            error_log('Demo lead not recorded: ' . $e->getMessage()); // the passkey still goes out
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return list<string> the channels it went out on */
    public function sendPin(array $row): array
    {
        $channels = [];
        $mail = (new PlatformMail($this->settings, $this->envFile))->send([(string) $row['email']], $row['pin'] . ' is your SmartPRS demo passkey',
            'Enter this passkey on the demo page to open your own demo company. It works until the date below; after that the demo company and everything you added to it are deleted.',
            ['Passkey' => (string) $row['pin'], 'Valid until' => Dates::dmy(substr((string) $row['expires_at'], 0, 10)) . ' ' . substr((string) $row['expires_at'], 11, 5)]);

        if ($mail['ok']) {
            $channels[] = 'email';
        }

        $wa = new WhatsApp($this->platform, $this->settings, $this->envFile);

        if ($wa->ready() && $wa->send((string) $row['phone'], $wa->templateFor('otp'), [(string) $row['pin']], 'demo.pin')['ok']) {
            $channels[] = 'WhatsApp';
        }

        return $channels;
    }

    /** The active, unexpired request this passkey opens, or null. @return array<string, mixed>|null */
    public function byPin(string $pin): ?array
    {
        foreach ($this->requests() as $r) {
            if ((string) $r['status'] === 'active' && hash_equals((string) $r['pin'], $pin)) {
                return $r;
            }
        }

        return null;
    }

    /** Is the demo pass in this session over (time up or revoked)? Marks it expired when time is up. */
    public function passOver(array $pass): bool
    {
        $row = $this->platform->find('demo_requests', (string) ($pass['id'] ?? ''));

        if ($row === null || (string) $row['status'] !== 'active') {
            return true;
        }

        if (time() > (int) strtotime((string) $row['expires_at'])) { // read from the row, so a Super Admin extension counts at once
            $this->platform->put('demo_requests', ['id' => $row['id'], 'status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);

            return true;
        }

        return false;
    }
}
