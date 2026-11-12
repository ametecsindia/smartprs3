<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

use SmartPRS\Platform\Support\Clock;

/**
 * The session half of `auth_policy` (Part XXII §XXII.7.6), as a value the
 * Laravel layer reads. No framework call, no `$_COOKIE`, no session driver —
 * those arrive with the HTTP layer and read this.
 *
 * ### What this replaces
 *
 * SmartPRS 2 has a 120-minute idle timeout, **no absolute cap at all**, and
 * remember-me at Laravel's five-year default with no `rememberDuration()` call
 * anywhere in the codebase. `SESSION_SECURE_COOKIE` has no default in
 * `config/session.php` and is absent from `.env.example`, so a deployment built
 * from the shipped example transmits its session cookie over plain HTTP.
 * `SESSION_ENCRYPT` defaults to false.
 *
 * ### The five-year remember-me, and what replaced it
 *
 * It is gone, and nothing takes its place as a *session*. A five-year cookie is
 * not a convenience feature, it is a credential with a five-year expiry that
 * nobody can enumerate, nobody can revoke individually, and no password change
 * invalidates. What replaces it is three separate things, which is why it looked
 * like one thing and was not:
 *
 *  - **`rememberDevice`** — an optional, per-device, individually revocable
 *    token bounded by `rememberDeviceDays` (default 30, platform maximum 90).
 *    It suppresses the *MFA prompt* on a device the user has already proven,
 *    and nothing else. It is never available for a `platform` risk-class
 *    operation (R-XXII-197).
 *  - **`absoluteLifetimeMinutes`** — a hard cap the remember-me token cannot
 *    extend (R-XXII-210). Sign-in happens again; it just happens on a device
 *    that does not re-prompt for the second factor.
 *  - **`reauthSecondsForPrivileged`** — the thing a long session actually
 *    needed to be safe, which SmartPRS 2 configured (`AUTH_PASSWORD_TIMEOUT`
 *    at 10,800 s) and then used on **no route at all**.
 *
 * `rememberMeSatisfiesAssurance()` returns false and is not configurable
 * (R-XXII-210). It is a method rather than a comment so that a future caller
 * asking the question gets the answer from the code.
 */
final readonly class SessionPolicy
{
    public const ON_LIMIT_DENY_NEW = 'deny_new';
    public const ON_LIMIT_EVICT_OLDEST = 'evict_oldest';

    public const EXPIRED_IDLE = 'SESSION_IDLE_TIMEOUT';
    public const EXPIRED_ABSOLUTE = 'SESSION_ABSOLUTE_LIFETIME';

    /** R-XXII-197 — the ceiling on remember-device, whatever a tenant configures. */
    public const MAX_REMEMBER_DEVICE_DAYS = 90;

    private function __construct(
        public int $idleTimeoutMinutes,
        public int $absoluteLifetimeMinutes,
        public int $concurrentSessionLimit,
        public string $onLimit,
        public int $reauthSecondsForPrivileged,
        public bool $rememberDeviceEnabled,
        public int $rememberDeviceDays,
        public bool $encryptPayload,
        public bool $regenerateOnPrivilegeChange,
    ) {
        if ($absoluteLifetimeMinutes < $idleTimeoutMinutes) {
            throw new \InvalidArgumentException(
                'The absolute lifetime must not be shorter than the idle timeout, or the '
                . 'idle timeout is decoration.'
            );
        }

        if (!in_array($onLimit, [self::ON_LIMIT_DENY_NEW, self::ON_LIMIT_EVICT_OLDEST], true)) {
            throw new \InvalidArgumentException(
                "on_limit must be '" . self::ON_LIMIT_DENY_NEW . "' or '" . self::ON_LIMIT_EVICT_OLDEST . "'."
            );
        }

        if ($rememberDeviceDays > self::MAX_REMEMBER_DEVICE_DAYS) {
            throw new \InvalidArgumentException(
                "remember_device_days {$rememberDeviceDays} exceeds the platform maximum of "
                . self::MAX_REMEMBER_DEVICE_DAYS . '. The five-year remember-me is not coming back.'
            );
        }
    }

    /** The shipped secure default. */
    public static function tenantDefault(): self
    {
        return new self(
            idleTimeoutMinutes: 120,
            absoluteLifetimeMinutes: 720,          // 12 hours — one working day
            concurrentSessionLimit: 5,
            onLimit: self::ON_LIMIT_EVICT_OLDEST,
            reauthSecondsForPrivileged: 900,       // 15 minutes
            rememberDeviceEnabled: true,
            rememberDeviceDays: 30,
            encryptPayload: true,
            regenerateOnPrivilegeChange: true,
        );
    }

    /** R-XXII-180's floor: a tenant may tighten every value here, never loosen it. */
    public static function platformFloor(): self
    {
        return new self(
            idleTimeoutMinutes: 240,
            absoluteLifetimeMinutes: 1_440,        // 24 hours; the cap is not optional
            concurrentSessionLimit: 10,
            onLimit: self::ON_LIMIT_EVICT_OLDEST,
            reauthSecondsForPrivileged: 3_600,
            rememberDeviceEnabled: true,
            rememberDeviceDays: self::MAX_REMEMBER_DEVICE_DAYS,
            encryptPayload: true,
            regenerateOnPrivilegeChange: true,
        );
    }

    /** R-XXII-181 — strictly stricter for accounts in the platform tenant. */
    public static function platformAccountFloor(): self
    {
        return new self(
            idleTimeoutMinutes: 30,
            absoluteLifetimeMinutes: 480,
            concurrentSessionLimit: 3,
            onLimit: self::ON_LIMIT_DENY_NEW,
            reauthSecondsForPrivileged: 300,
            rememberDeviceEnabled: false,          // R-XXII-197: never for platform
            rememberDeviceDays: 0,
            encryptPayload: true,
            regenerateOnPrivilegeChange: true,
        );
    }

    /** @param array<string, mixed> $overrides */
    public static function forTenant(array $overrides = []): self
    {
        $floor = self::platformFloor();
        $default = self::tenantDefault();
        $v = static fn (string $key, mixed $fallback): mixed => $overrides[$key] ?? $fallback;

        $policy = new self(
            idleTimeoutMinutes: (int) $v('idleTimeoutMinutes', $default->idleTimeoutMinutes),
            absoluteLifetimeMinutes: (int) $v('absoluteLifetimeMinutes', $default->absoluteLifetimeMinutes),
            concurrentSessionLimit: (int) $v('concurrentSessionLimit', $default->concurrentSessionLimit),
            onLimit: (string) $v('onLimit', $default->onLimit),
            reauthSecondsForPrivileged: (int) $v('reauthSecondsForPrivileged', $default->reauthSecondsForPrivileged),
            rememberDeviceEnabled: (bool) $v('rememberDeviceEnabled', $default->rememberDeviceEnabled),
            rememberDeviceDays: (int) $v('rememberDeviceDays', $default->rememberDeviceDays),
            encryptPayload: (bool) $v('encryptPayload', $default->encryptPayload),
            regenerateOnPrivilegeChange: (bool) $v('regenerateOnPrivilegeChange', $default->regenerateOnPrivilegeChange),
        );

        $weak = [];

        if ($policy->idleTimeoutMinutes > $floor->idleTimeoutMinutes) {
            $weak[] = "idle_timeout_minutes {$policy->idleTimeoutMinutes} is above the floor of {$floor->idleTimeoutMinutes}";
        }

        if ($policy->absoluteLifetimeMinutes > $floor->absoluteLifetimeMinutes) {
            $weak[] = "absolute_lifetime_minutes {$policy->absoluteLifetimeMinutes} is above the floor of "
                . "{$floor->absoluteLifetimeMinutes} — R-XXII-205: the absolute cap is not optional";
        }

        if ($policy->concurrentSessionLimit > $floor->concurrentSessionLimit) {
            $weak[] = "concurrent_session_limit {$policy->concurrentSessionLimit} is above the floor of {$floor->concurrentSessionLimit}";
        }

        if ($policy->reauthSecondsForPrivileged > $floor->reauthSecondsForPrivileged) {
            $weak[] = 'reauth_seconds_for_privileged is longer than the floor allows';
        }

        if ($policy->rememberDeviceDays > $floor->rememberDeviceDays) {
            $weak[] = "remember_device_days {$policy->rememberDeviceDays} is above the floor of {$floor->rememberDeviceDays}";
        }

        if (!$policy->encryptPayload) {
            $weak[] = 'session payload encryption has been switched off (R-XXII-207)';
        }

        if (!$policy->regenerateOnPrivilegeChange) {
            $weak[] = 'session-id regeneration on privilege change has been switched off (R-XXII-206)';
        }

        if ($weak !== []) {
            throw new \DomainException(
                "This session policy loosens the platform floor (R-XXII-180):\n  · " . implode("\n  · ", $weak)
            );
        }

        return $policy;
    }

    /**
     * The cookie attributes the HTTP layer MUST set (R-XXII-206).
     *
     * `Secure` has exactly one off switch, and it is `$loopbackDevelopment` —
     * a declared local loopback, nothing else. There is no environment variable,
     * because `SESSION_SECURE_COOKIE` with no default in a shipped
     * `.env.example` is precisely how SmartPRS 2 sends session cookies over
     * plain HTTP on a deployment nobody misconfigured on purpose.
     *
     * The `__Host-` prefix is not decoration: it makes the browser refuse the
     * cookie unless it is Secure, path `/`, and has no `Domain`, which turns
     * three rules a reviewer has to check into one the browser enforces.
     *
     * @return array{name: string, secure: bool, httponly: bool, samesite: string, path: string, domain: ?string}
     */
    public function cookieFlags(string $cookieName = 'session', bool $loopbackDevelopment = false): array
    {
        return [
            // The prefix is only legal when Secure is set, so it is dropped
            // together with it rather than producing a cookie the browser
            // silently discards.
            'name' => $loopbackDevelopment ? $cookieName : '__Host-' . $cookieName,
            'secure' => !$loopbackDevelopment,
            'httponly' => true,
            'samesite' => 'Lax',
            'path' => '/',
            'domain' => null,
        ];
    }

    /**
     * @return ?string  EXPIRED_IDLE, EXPIRED_ABSOLUTE, or NULL when the session
     *         is still live. Absolute is checked first: it is the one that is
     *         not renewable, so it is the one whose reason code the audit row
     *         should carry when both have run out.
     */
    public function expiredReason(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $lastSeenAt,
        Clock $clock,
    ): ?string {
        $now = $clock->now();

        if ($now >= $startedAt->modify('+' . $this->absoluteLifetimeMinutes . ' minutes')) {
            return self::EXPIRED_ABSOLUTE;
        }

        if ($now >= $lastSeenAt->modify('+' . $this->idleTimeoutMinutes . ' minutes')) {
            return self::EXPIRED_IDLE;
        }

        return null;
    }

    /** R-XXII-198's `assurance = reauth(n)`, with this policy's n. */
    public function needsReauth(?int $secondsSinceReauth): bool
    {
        return $secondsSinceReauth === null || $secondsSinceReauth > $this->reauthSecondsForPrivileged;
    }

    /**
     * R-XXII-210, and it is not configurable.
     *
     * A remembered device suppresses the MFA prompt on a device the user has
     * already proven; it is not evidence that the person at the keyboard is
     * that user. Letting it satisfy an assurance requirement would make the
     * second factor optional for anyone holding the first.
     */
    public function rememberMeSatisfiesAssurance(): bool
    {
        return false;
    }

    /**
     * What to do when a sign-in would exceed the limit (R-XXII-208).
     *
     * @return array{allow: bool, evict: int}  how many existing sessions to end
     */
    public function admitSession(int $currentSessionCount): array
    {
        if ($currentSessionCount < $this->concurrentSessionLimit) {
            return ['allow' => true, 'evict' => 0];
        }

        return $this->onLimit === self::ON_LIMIT_EVICT_OLDEST
            ? ['allow' => true, 'evict' => $currentSessionCount - $this->concurrentSessionLimit + 1]
            : ['allow' => false, 'evict' => 0];
    }
}
