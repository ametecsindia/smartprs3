<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * PHONE SIGN-IN AND FIRST-TIME ACTIVATION — SmartEPT's logic in SmartPRS3 (owner, 12 Nov 2026).
 *
 * The app opens on a sign-in screen (company address, login, password). A phone already registered to that login
 * signs straight in and shows the menus of that profile. Any other phone is asked once for the ACTIVATION CODE the
 * administrator issued (Administration → Mobile Devices, or QR Attendance → Phones): single-use, hashed, expiring.
 *
 * Codes live in one versioned customisation (key mobile-activation: subject => hash / expiry / who / when), so no
 * table is added. A subject is "e:<employee id>" or, for a login with no employee record, "u:<user id>".
 * The app enters the web session through a ONE-TIME LOGIN LINK minted here after the password was checked: a file in
 * storage/app-login named by the link's hash, good for a few seconds and for that phone only.
 */
final class MobileActivation
{
    public const KEY = 'mobile-activation';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I: read off a message without mistakes

    /** @param array<string, mixed> $rules config/mobile-devices.json "activation" */
    public function __construct(private readonly array $rules, private readonly string $dir)
    {
    }

    public static function load(string $root): self
    {
        $d = json_decode((string) @file_get_contents($root . '/config/mobile-devices.json'), true);

        return new self(is_array($d['activation'] ?? null) ? $d['activation'] : [], $root . '/storage/app-login');
    }

    private function rule(string $k, int $default): int
    {
        return max(1, (int) ($this->rules[$k] ?? $default));
    }

    /** A new code and the payload with it stored (replacing an older code for the same subject). @return array{0: string, 1: array<string, mixed>, 2: string} code, payload, expires */
    public function issue(array $saved, string $subject, string $by, int $now): array
    {
        $code = '';
        for ($i = 0, $n = $this->rule('code_length', 8); $i < $n; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $expires = date('Y-m-d H:i:s', $now + $this->rule('valid_hours', 72) * 3600);
        $saved[$subject] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => $expires, 'by' => $by, 'at' => date('Y-m-d H:i:s', $now)];

        return [$code, $saved, $expires];
    }

    /** Does this code open this subject now? Spaces, dashes and case are ignored. */
    public static function matches(array $saved, string $subject, string $code, int $now): bool
    {
        $row = $saved[$subject] ?? null;
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return is_array($row) && $code !== '' && (string) ($row['expires'] ?? '') >= date('Y-m-d H:i:s', $now)
            && password_verify($code, (string) ($row['hash'] ?? ''));
    }

    /** The payload without this subject's code — a code is used once. */
    public static function consume(array $saved, string $subject): array
    {
        unset($saved[$subject]);

        return $saved;
    }

    /** Wrong passwords / codes from one phone: refused after N tries for M minutes. Returns the wait in seconds (0 = may try). */
    public function locked(string $deviceId, int $now): int
    {
        $f = $this->file('try-' . $deviceId);
        $t = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];

        return (int) ($t['n'] ?? 0) >= $this->rule('attempts', 5) && (int) ($t['until'] ?? 0) > $now ? (int) $t['until'] - $now : 0;
    }

    public function failed(string $deviceId, int $now): void
    {
        $f = $this->file('try-' . $deviceId);
        $t = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
        $n = (int) ($t['until'] ?? 0) > $now ? (int) ($t['n'] ?? 0) + 1 : 1;
        $this->write($f, ['n' => $n, 'until' => $now + $this->rule('lock_minutes', 5) * 60]);
    }

    public function cleared(string $deviceId): void
    {
        @unlink($this->file('try-' . $deviceId)); // ponytail: a missing file is already cleared
    }

    /** A one-time link token that signs this user in on this phone. */
    public function mintLogin(string $userId, string $tenantId, string $deviceId, int $now): string
    {
        $token = bin2hex(random_bytes(24));
        $this->write($this->file('login-' . hash('sha256', $token)), ['user' => $userId, 'tenant' => $tenantId, 'device' => $deviceId,
            'until' => $now + $this->rule('login_link_seconds', 120)]);

        return $token;
    }

    /** The user id the token was minted for, once; null when unknown, used, expired or for another phone. */
    public function takeLogin(string $token, string $deviceId, int $now): ?array
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }
        $f = $this->file('login-' . hash('sha256', $token));
        $t = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
        @unlink($f); // used once, whatever the answer

        return $t !== [] && (int) ($t['until'] ?? 0) >= $now && hash_equals((string) ($t['device'] ?? ''), $deviceId) ? $t : null;
    }

    private function file(string $name): string
    {
        return $this->dir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', $name) . '.json';
    }

    private function write(string $f, array $data): void
    {
        is_dir($this->dir) || mkdir($this->dir, 0770, true);
        file_put_contents($f, json_encode($data), LOCK_EX);
    }
}
