<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE LICENCE SERVER — SmartPRS 2's LicenseService + LicenseSigner +
 * UpdateServerController, over SmartPRS 3's platform store. Runs on the SaaS
 * install (app.smartprs.com): the Super Admin's On-Prem desk issues keys and
 * .lic files here, and every on-prem install activates / checks in here.
 *
 * Keys: SPRS-XXXX-XXXX-XXXX-XXXX, stored as a sha256 hash (lookup) plus an
 * encrypted copy (Secret, so the Super Admin can re-show it to the installing
 * engineer). A .lic is RSA-SHA256 signed with storage/keys/license_private.pem —
 * the PRIVATE key never ships; installs verify with Licence::PUBLIC_KEY.
 *
 * Licence statuses (SmartPRS 2): pending → active → suspended | revoked.
 */
final class LicenceAuthority
{
    private const CHARSET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    public const LIVE = ['pending', 'active'];
    private const NEUTRAL = 'Licence not recognised. Please check the key, or contact ' . Licence::CONTACT . '.';

    public function __construct(private readonly RowStore $platform, private readonly string $root)
    {
    }

    // ---------------------------------------------------------------- keys

    public static function generateKey(): string
    {
        $block = static function (): string {
            $s = '';

            for ($i = 0; $i < 4; $i++) {
                $s .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
            }

            return $s;
        };

        return 'SPRS-' . $block() . '-' . $block() . '-' . $block() . '-' . $block();
    }

    public static function normalize(string $key): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
    }

    public static function hashKey(string $key): string
    {
        return hash('sha256', self::normalize($key));
    }

    public function privateKeyPath(): string
    {
        return $this->root . '/storage/keys/license_private.pem';
    }

    public function canSign(): bool
    {
        return is_readable($this->privateKeyPath());
    }

    /** @param array<string, mixed> $data */
    public function sign(array $data): string
    {
        $priv = openssl_pkey_get_private((string) @file_get_contents($this->privateKeyPath()));

        if ($priv === false) {
            throw new \RuntimeException('Licence signing key missing at storage/keys/license_private.pem — copy it to this server (see deploy/DEPLOY.md).');
        }

        $fp = trim((string) ($data['fingerprint'] ?? ''));
        $payload = ['v' => 1, 'product' => 'smartprs3', 'key' => (string) ($data['key'] ?? ''), 'company' => $data['company'] ?? null,
            'device_limit' => !empty($data['device_limit']) ? (int) $data['device_limit'] : null,
            'kind' => $data['kind'] ?? 'perpetual', 'deployment' => 'onprem', 'expires_at' => $data['expires_at'] ?? null,
            'grace_days' => (int) ($data['grace_days'] ?? 7), 'features' => $data['features'] ?? [],
            'fingerprint' => $fp !== '' ? $fp : null, 'issued_at' => date('c')];
        $b64 = self::b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);

        return $b64 . '.' . self::b64url((string) $sig);
    }

    public static function filename(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $key) . '.lic';
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------- licences

    /** @return array<string, mixed>|null */
    public function findByKey(string $key): ?array
    {
        $h = self::hashKey($key);

        foreach ($this->platform->all('licences') as $l) {
            if (hash_equals((string) ($l['key_hash'] ?? ''), $h)) {
                return $l;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null the client's live (pending/active) licence, newest first */
    public function liveFor(string $clientId): ?array
    {
        $live = array_values(array_filter($this->platform->all('licences'), static fn (array $l): bool
            => (string) $l['client_id'] === $clientId && in_array((string) $l['status'], self::LIVE, true)));
        usort($live, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

        return $live[0] ?? null;
    }

    public function reveal(array $licence): ?string
    {
        return Secret::decrypt((string) ($licence['key_enc'] ?? ''), $this->root . '/.env');
    }

    /** The plain SPRS key a licence is indexed by, even when the stored copy is a whole .lic. */
    public function plainKey(array $licence): ?string
    {
        $v = (string) ($this->reveal($licence) ?? '');

        if (Licence::looksLikeLicenceFile($v)) {
            $v = Licence::keyInsideToken($v);
        }

        return $v !== '' ? $v : null;
    }

    /**
     * Issue (or re-issue over the live row) a licence for a client.
     * $stored is what the panel can re-show: the plain key, or the whole .lic.
     *
     * @return array<string, mixed> the licence row
     */
    public function issue(string $clientId, string $key, ?string $expiresOn, string $expiryMode, ?int $seats, string $stored, string $by): array
    {
        $vals = ['edition' => 'full', 'key_hash' => self::hashKey($key), 'key_enc' => Secret::encrypt($stored, $this->root . '/.env'),
            'key_last4' => substr(self::normalize($key), -4), 'amc_expires_on' => $expiresOn,
            'expiry_mode' => in_array($expiryMode, ['renew', 'notify'], true) ? $expiryMode : 'renew',
            'seats' => $seats !== null && $seats > 0 ? $seats : null];
        $live = $this->liveFor($clientId);

        if ($live !== null) {
            $row = $this->platform->put('licences', ['id' => $live['id']] + $vals);
            $this->event((string) $live['id'], 'generated', 'Re-issued by ' . $by);

            return $row;
        }

        $row = $this->platform->put('licences', $vals + ['client_id' => $clientId, 'status' => 'pending',
            'reactivations_used' => 0, 'server_limit' => 1]);
        $this->event((string) $row['id'], 'generated', 'Issued by ' . $by);

        return $row;
    }

    public function event(string $licenceId, string $type, string $detail = '', ?string $ip = null): void
    {
        $this->platform->put('licence_events', ['licence_id' => $licenceId, 'type' => $type,
            'detail' => mb_substr($detail, 0, 2000), 'ip' => $ip, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function amcActive(array $licence): bool
    {
        $e = (string) ($licence['amc_expires_on'] ?? '');

        return $e !== '' && substr($e, 0, 10) >= date('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function certificate(array $lic, string $key, string $fp): array
    {
        $payload = ['edition' => 'full', 'amc_expires_on' => $lic['amc_expires_on'] ?? null,
            'expiry_mode' => (string) ($lic['expiry_mode'] ?? 'renew'), 'seats' => isset($lic['seats']) ? (int) $lic['seats'] : null,
            'fingerprint' => $fp, 'issued' => date('Y-m-d H:i:s')];
        $payload['sig'] = hash_hmac('sha256', (string) json_encode($payload), self::normalize($key));

        return $payload;
    }

    // --------------------------------------------------- server seats (devices)

    /** @return array{ok: bool, reason: ?string} */
    public function recordDevice(string $licenceId, string $uid, string $hostname = ''): array
    {
        $uid = mb_substr(trim($uid), 0, 190);

        if ($uid === '') {
            return ['ok' => false, 'reason' => 'no_device'];
        }

        $now = date('Y-m-d H:i:s');
        $active = 0;

        foreach ($this->platform->all('licence_devices') as $d) {
            if ((string) $d['licence_id'] !== $licenceId) {
                continue;
            }

            if ((string) $d['device_uid'] === $uid) {
                $this->platform->put('licence_devices', ['id' => $d['id'], 'status' => 'active', 'hostname' => $hostname ?: $d['hostname'],
                    'last_seen_at' => $now, 'deactivated_at' => null]);

                return ['ok' => true, 'reason' => null];
            }

            $active += (string) $d['status'] === 'active' ? 1 : 0;
        }

        $limit = max(1, (int) ($this->platform->find('licences', $licenceId)['server_limit'] ?? 1));

        if ($active >= $limit) {
            return ['ok' => false, 'reason' => 'device_limit_reached'];
        }

        $this->platform->put('licence_devices', ['licence_id' => $licenceId, 'device_uid' => $uid, 'fingerprint' => $uid,
            'hostname' => $hostname, 'status' => 'active', 'activated_at' => $now, 'last_seen_at' => $now]);

        return ['ok' => true, 'reason' => null];
    }

    public function releaseDevices(string $licenceId): void
    {
        foreach ($this->platform->all('licence_devices') as $d) {
            if ((string) $d['licence_id'] === $licenceId && (string) $d['status'] === 'active') {
                $this->platform->put('licence_devices', ['id' => $d['id'], 'status' => 'deactivated', 'deactivated_at' => date('Y-m-d H:i:s')]);
            }
        }
    }

    /** Once a day a 'verified' history line, so hourly check-ins never flood it. */
    private function verifyOnce(array $lic, string $fp): void
    {
        foreach ($this->platform->all('licence_events') as $e) {
            if ((string) $e['licence_id'] === (string) $lic['id'] && (string) $e['type'] === 'verified'
                && substr((string) $e['created_at'], 0, 10) === date('Y-m-d')) {
                return;
            }
        }

        $this->event((string) $lic['id'], 'verified', 'Verified by bound server ' . $fp);
    }

    private function rejection(array $lic, string $reason, string $attempted, string $ip): void
    {
        foreach ($this->platform->all('licence_events') as $e) {
            if ((string) $e['licence_id'] === (string) $lic['id'] && (string) $e['type'] === 'rejected'
                && str_contains((string) $e['detail'], $reason) && strtotime((string) $e['created_at']) > time() - 86400) {
                return;
            }
        }

        $this->event((string) $lic['id'], 'rejected', $reason . ' — attempted=' . ($attempted ?: '(none)') . ' bound=' . ((string) ($lic['fingerprint'] ?? '') ?: '(unbound)'), $ip);

        if ($reason === 'server_mismatch') {
            $this->platform->put('licences', ['id' => $lic['id'], 'last_mismatch_at' => date('Y-m-d H:i:s')]);
        }
    }

    // ------------------------------------------------- the /update/* endpoints

    /**
     * One request from an on-prem install. Returns [http status, json body].
     *
     * @param array<string, string> $in
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function handle(string $op, array $in, string $ip): array
    {
        $key = (string) ($in['key'] ?? '');
        $lic = strlen(self::normalize($key)) >= 16 ? $this->findByKey($key) : null;
        $fp = mb_substr(trim((string) ($in['fingerprint'] ?? '')), 0, 190);
        $server = mb_substr((string) ($in['server_name'] ?? ''), 0, 190);

        if ($lic === null) {
            return [422, ['ok' => false, 'error' => self::NEUTRAL, 'reason' => 'unknown_key', 'update_available' => false]];
        }

        $id = (string) $lic['id'];
        $status = (string) $lic['status'];
        $bound = (string) ($lic['fingerprint'] ?? '');
        $now = date('Y-m-d H:i:s');

        switch ($op) {
            case 'activate':
                if ($fp === '') {
                    return [422, ['ok' => false, 'error' => self::NEUTRAL]];
                }

                if (in_array($status, ['revoked', 'suspended'], true)) {
                    $this->event($id, 'denied', 'Activation attempt on ' . $status . ' licence', $ip);

                    return [422, ['ok' => false, 'error' => 'This licence is not active. Please contact ' . Licence::CONTACT . '.']];
                }

                if ($bound !== '' && !hash_equals($bound, $fp)) {
                    $this->rejection($lic, 'server_mismatch', $fp, $ip);

                    return [422, ['ok' => false, 'error' => 'This licence is already activated on another machine. To move it, contact ' . Licence::CONTACT . ' and we will release it.']];
                }

                $this->platform->put('licences', ['id' => $id, 'status' => 'active', 'fingerprint' => $fp, 'server_name' => $server,
                    'activated_at' => (string) ($lic['activated_at'] ?? '') ?: $now, 'last_seen_at' => $now]);
                $this->event($id, 'activated', 'Activated on ' . ($server ?: 'unknown server'), $ip);

                if ($bound === '') {
                    $this->event($id, 'machine_bound', 'Bound to server ' . $fp, $ip);
                }

                $this->recordDevice($id, $fp, $server);
                $this->verifyOnce($lic, $fp);
                $client = $this->platform->find('onprem_clients', (string) $lic['client_id']);

                return [200, ['ok' => true, 'cert' => $this->certificate($lic, $key, $fp), 'company' => (string) ($client['company'] ?? '')]];

            case 'heartbeat':
                if (in_array($status, ['revoked', 'suspended'], true)) {
                    $this->rejection($lic, 'licence_' . $status, $fp, $ip);

                    return [200, ['ok' => false, 'reason' => 'licence_' . $status, 'status' => $status]];
                }

                if ($fp !== '' && $bound !== '' && !hash_equals($bound, $fp)) {
                    $this->rejection($lic, 'server_mismatch', $fp, $ip);

                    return [200, ['ok' => false, 'reason' => 'server_mismatch', 'status' => $status]];
                }

                $upd = ['id' => $id, 'last_seen_at' => $now];

                if ($fp !== '' && $bound === '') {
                    $upd += ['fingerprint' => $fp, 'server_name' => $server, 'activated_at' => (string) ($lic['activated_at'] ?? '') ?: $now];
                    $this->event($id, 'machine_bound', 'Bound to server ' . $fp, $ip);
                }

                $this->platform->put('licences', $upd);

                if ($fp !== '') {
                    $this->recordDevice($id, $fp, $server);
                    $this->verifyOnce($lic, $fp);
                }

                return [200, ['ok' => true, 'status' => $status, 'amc_expires_on' => $lic['amc_expires_on'] ?? null,
                    'amc_active' => self::amcActive($lic), 'expiry_mode' => (string) ($lic['expiry_mode'] ?? 'renew'),
                    'seats' => isset($lic['seats']) ? (int) $lic['seats'] : null]];

            case 'device/activate':
                if ($status !== 'active') {
                    return [422, ['ok' => false, 'error' => self::NEUTRAL]];
                }

                $res = $this->recordDevice($id, (string) ($in['device_uid'] ?? ''), (string) ($in['hostname'] ?? ''));

                if (!$res['ok']) {
                    $this->event($id, 'denied', 'Server seat refused: ' . (string) $res['reason'], $ip);
                }

                return [200, $res + ['limit' => max(1, (int) ($lic['server_limit'] ?? 1))]];

            case 'device/deactivate':
                $uid = mb_substr(trim((string) ($in['device_uid'] ?? '')), 0, 190);
                $ok = false;

                foreach ($this->platform->all('licence_devices') as $d) {
                    if ((string) $d['licence_id'] === $id && (string) $d['device_uid'] === $uid && (string) $d['status'] === 'active') {
                        $this->platform->put('licence_devices', ['id' => $d['id'], 'status' => 'deactivated', 'deactivated_at' => $now]);
                        $ok = true;
                    }
                }

                return [200, ['ok' => $ok]];

            case 'check':
                return $this->check($lic, (string) ($in['current_version'] ?? $in['version'] ?? '0'));

            // THE CLIENT REPORTS WHAT IT DID with a release (12 Nov 2026): downloaded | applied | failed.
            case 'report':
                $action = in_array((string) ($in['action'] ?? ''), ['download', 'applied', 'failed'], true) ? (string) $in['action'] : 'failed';
                $this->platform->put('client_updates', ['client_id' => $lic['client_id'] ?? null, 'licence_id' => $id,
                    'version' => mb_substr((string) ($in['version'] ?? ''), 0, 30), 'action' => $action,
                    'detail' => mb_substr((string) ($in['detail'] ?? ''), 0, 1000) ?: null]);

                return [200, ['ok' => true]];
        }

        return [404, ['ok' => false, 'error' => 'No such licence operation.']];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function check(array $lic, string $current): array
    {
        $status = (string) $lic['status'];

        if ($status !== 'active') {
            $msg = in_array($status, ['revoked', 'suspended'], true)
                ? 'Updates need an active licence. This licence is ' . $status . '. Please contact ' . Licence::CONTACT . '.' : self::NEUTRAL;

            return [422, ['ok' => false, 'update_available' => false, 'reason' => 'licence_' . $status, 'message' => $msg, 'error' => $msg]];
        }

        $this->platform->put('licences', ['id' => $lic['id'], 'last_seen_at' => date('Y-m-d H:i:s')]);
        $base = ['ok' => true, 'amc_expires_on' => $lic['amc_expires_on'] ?? null, 'expiry_mode' => (string) ($lic['expiry_mode'] ?? 'renew')];

        if (!self::amcActive($lic)) {
            return [200, $base + ['update_available' => false, 'reason' => 'amc_expired', 'amc_active' => false,
                'message' => 'Your AMC ended on ' . ((string) ($lic['amc_expires_on'] ?? '') ?: '—') . '. Renew to receive updates.']];
        }

        $granted = [];

        foreach ($this->platform->all('release_grants') as $g) {
            if ((string) $g['client_id'] === (string) $lic['client_id']) {
                $granted[(string) $g['release_id']] = true;
            }
        }

        $best = null;

        foreach ($this->platform->all('releases') as $r) {
            if (isset($granted[(string) $r['id']]) && (string) ($r['published_at'] ?? '') !== ''
                && ($best === null || version_compare((string) $r['version'], (string) $best['version'], '>'))) {
                $best = $r;
            }
        }

        if ($best === null || version_compare((string) $best['version'], $current, '<=')) {
            return [200, $base + ['update_available' => false, 'amc_active' => true, 'reason' => $best ? 'up_to_date' : 'not_granted',
                'message' => $best ? 'You are on the latest version granted to you.' : 'No update has been released for your licence yet.']];
        }

        return [200, $base + ['update_available' => true, 'amc_active' => true, 'product' => 'smartprs3', 'version' => $best['version'],
            'notes' => $best['notes'] ?? '', 'size_bytes' => (int) ($best['size'] ?? 0), 'package_hash' => $best['checksum'] ?? null,
            'signature' => $this->signatureOf($best), 'downloadable' => $this->packagePath($best) !== null,
            'released_at' => $best['published_at']]];
    }

    /** The release's package on this (central) server, or null when it has none. */
    public function packagePath(array $release): ?string
    {
        $rel = (string) ($release['file_path'] ?? '');
        $path = $rel === '' ? '' : (str_starts_with($rel, '/') || preg_match('/^[A-Za-z]:/', $rel) === 1 ? $rel : $this->root . '/' . $rel);

        return $path !== '' && is_file($path) ? $path : null;
    }

    /** The signature kept beside the package (smartprs3-<version>.sig). */
    public function signatureOf(array $release): string
    {
        $zip = $this->packagePath($release);

        return $zip === null ? '' : trim((string) @file_get_contents(preg_replace('/\.zip$/i', '.sig', $zip)));
    }

    /**
     * THE PACKAGE A CLIENT MAY DOWNLOAD (/update/download): the licence must be active, its AMC current,
     * and that version granted to its client. Returns the file path, or [status, error body].
     *
     * @return array{0: int, 1: array<string, mixed>|string}
     */
    public function download(array $in, string $ip): array
    {
        $lic = strlen(self::normalize((string) ($in['key'] ?? ''))) >= 16 ? $this->findByKey((string) $in['key']) : null;

        if ($lic === null || (string) $lic['status'] !== 'active') {
            return [422, ['ok' => false, 'error' => self::NEUTRAL]];
        }

        if (!self::amcActive($lic)) {
            return [422, ['ok' => false, 'error' => 'Your AMC has ended. Renew to receive updates.']];
        }

        $want = (string) ($in['version'] ?? '');

        foreach ($this->platform->all('releases') as $r) {
            if ((string) $r['version'] !== $want || (string) ($r['published_at'] ?? '') === '') {
                continue;
            }

            foreach ($this->platform->all('release_grants') as $g) {
                if ((string) $g['release_id'] === (string) $r['id'] && (string) $g['client_id'] === (string) $lic['client_id']) {
                    $path = $this->packagePath($r);
                    $this->platform->put('client_updates', ['client_id' => $lic['client_id'], 'licence_id' => $lic['id'], 'version' => $want,
                        'action' => 'download', 'detail' => $path === null ? 'package missing on central' : 'from ' . $ip]);

                    return $path === null ? [404, ['ok' => false, 'error' => 'The package for ' . $want . ' is not on the update server yet.']] : [200, $path];
                }
            }
        }

        return [404, ['ok' => false, 'error' => 'Version ' . $want . ' has not been released to this licence.']];
    }

    // ------------------------------------------------------- the sale (client)

    /**
     * Price + GST (SaasPlatform::tax, so the split follows config/gst.json) and the balance.
     *
     * @return array{price: \SmartPRS\Platform\Support\Money, tax: \SmartPRS\Platform\Support\Money, total: \SmartPRS\Platform\Support\Money, balance: \SmartPRS\Platform\Support\Money, mode: string}
     */
    public static function totals(array $client, SaasPlatform $saas): array
    {
        $price = Amount::of($client['price'] ?? '0');
        $gst = $saas->tax($price, (string) ($client['state'] ?? ''));
        $balance = $gst['total']->minus(Amount::of($client['paid_total'] ?? '0'));

        return ['price' => $price, 'tax' => $gst['tax'], 'total' => $gst['total'],
            'balance' => $balance->isNegative() ? \SmartPRS\Platform\Support\Money::zero() : $balance, 'mode' => $gst['mode']];
    }

    /** The licence end date the Super Admin chose: a fixed date, else N months, else one year. */
    public static function resolveExpiry(array $client): string
    {
        $exp = substr((string) ($client['licence_expires_on'] ?? ''), 0, 10);

        if ($exp !== '' && $exp >= date('Y-m-d')) {
            return $exp;
        }

        $months = (int) ($client['licence_term_months'] ?? 0);

        return (new \DateTimeImmutable('today'))->modify('+' . ($months > 0 ? $months : 12) . ' months')->format('Y-m-d');
    }
}
