<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

use SmartPRS\Platform\Support\Clock;

/**
 * The lockout and throttling half of `auth_policy` (Part XXII §XXII.7.3).
 *
 * SmartPRS 2 has **no lockout of any kind** — no `failed_login` table, no
 * `locked_until` column, no `Failed` event listener — and throttles at 5/min
 * per IP, which is a control an attacker with a hundred source addresses never
 * encounters. Only *successful* logins are audited, so the failures that would
 * have shown the attack are not recorded either.
 *
 * ### Two keys, and the order between them (R-XXII-189, R-XXII-190, R-XXII-192)
 *
 * Per-**source** and per-**account** limiting answer different attacks and must
 * both exist:
 *
 *  - the source key stops one machine grinding through one account's passwords,
 *  - the account key stops a distributed attempt on one account, which no
 *    per-source limit can see at all.
 *
 * They are evaluated **source first**, deliberately. Account lockout on its own
 * is a denial-of-service primitive: anyone who knows a colleague's email address
 * can lock them out before a meeting, forever, for free. Blocking the source
 * before the account means a single-origin attack is stopped without the victim
 * ever being locked, and account lockout is reserved for genuinely distributed
 * failure — which is the case where locking is the only remaining control. The
 * ordering is configuration (`sourceFirst`) with the secure default, because a
 * customer whose users all sit behind one NAT gateway may need the other one.
 *
 * ### The uniform response
 *
 * Nothing in here produces a message. The block is enforced server-side — a
 * blocked key never reaches the password hasher — while the caller receives
 * `LockoutState::publicResponse()`, which is identical for a locked account, a
 * throttled source, a wrong password and an account that does not exist. See
 * LockoutState.
 */
final readonly class LockoutPolicy
{
    /**
     * @param  int   $threshold          failures within $windowMinutes that lock the account
     * @param  int   $windowMinutes      the counting window, ending at the most recent failure
     * @param  int   $lockMinutes        the first lock's duration
     * @param  bool  $progressiveBackoff each consecutive lock doubles the duration
     * @param  int   $maxLockMinutes     the cap on that doubling — an unbounded backoff
     *                                   is a permanent lock wearing a friendly name
     * @param  int   $sourceThreshold    failures from one source that block the source
     * @param  int   $sourceWindowMinutes
     * @param  int   $sourceBlockMinutes
     * @param  bool  $sourceFirst        R-XXII-192; see the class note
     * @param  list<string> $unlockPaths R-XXII-191: how a lock clears, besides expiry
     * @param  int   $uniformDelayMilliseconds  R-XXII-193 requires uniformity in
     *              *timing* as well as wording. The handler sleeps to this floor on
     *              every failure so a cheap refusal (blocked, no hash computed) does
     *              not return measurably sooner than an expensive one (Argon2id).
     */
    private function __construct(
        public int $threshold,
        public int $windowMinutes,
        public int $lockMinutes,
        public bool $progressiveBackoff,
        public int $maxLockMinutes,
        public int $sourceThreshold,
        public int $sourceWindowMinutes,
        public int $sourceBlockMinutes,
        public bool $sourceFirst,
        public array $unlockPaths,
        public int $uniformDelayMilliseconds,
        public bool $notifyUser,
        public bool $notifyAdmin,
    ) {
        if ($threshold < 1 || $sourceThreshold < 1) {
            throw new \InvalidArgumentException('A lockout threshold below 1 locks every account on its first attempt.');
        }

        if ($maxLockMinutes < $lockMinutes) {
            throw new \InvalidArgumentException('max_lock_minutes must not be below lock_minutes.');
        }
    }

    /**
     * The shipped secure default.
     *
     * The source threshold is deliberately below the account threshold so that
     * a single-origin attack trips the source key first (R-XXII-192) and the
     * account is never locked by it.
     */
    public static function platformDefault(): self
    {
        return new self(
            threshold: 10,
            windowMinutes: 15,
            lockMinutes: 15,
            progressiveBackoff: true,
            maxLockMinutes: 240,
            sourceThreshold: 5,
            sourceWindowMinutes: 5,
            sourceBlockMinutes: 15,
            sourceFirst: true,
            unlockPaths: ['expiry', 'administrator', 'verified_self_service'],
            uniformDelayMilliseconds: 500,
            notifyUser: true,
            notifyAdmin: false,
        );
    }

    /** The floor a tenant may tighten but not loosen (R-XXII-180). */
    public static function platformFloor(): self
    {
        return new self(
            threshold: 20,
            windowMinutes: 15,
            lockMinutes: 5,
            progressiveBackoff: false,
            maxLockMinutes: 60,
            sourceThreshold: 20,
            sourceWindowMinutes: 5,
            sourceBlockMinutes: 5,
            sourceFirst: true,
            unlockPaths: ['expiry'],
            uniformDelayMilliseconds: 250,
            notifyUser: true,
            notifyAdmin: false,
        );
    }

    /** @param array<string, mixed> $overrides */
    public static function forTenant(array $overrides = []): self
    {
        $floor = self::platformFloor();
        $default = self::platformDefault();

        $v = static fn (string $key, mixed $fallback): mixed => $overrides[$key] ?? $fallback;

        $policy = new self(
            threshold: (int) $v('threshold', $default->threshold),
            windowMinutes: (int) $v('windowMinutes', $default->windowMinutes),
            lockMinutes: (int) $v('lockMinutes', $default->lockMinutes),
            progressiveBackoff: (bool) $v('progressiveBackoff', $default->progressiveBackoff),
            maxLockMinutes: (int) $v('maxLockMinutes', $default->maxLockMinutes),
            sourceThreshold: (int) $v('sourceThreshold', $default->sourceThreshold),
            sourceWindowMinutes: (int) $v('sourceWindowMinutes', $default->sourceWindowMinutes),
            sourceBlockMinutes: (int) $v('sourceBlockMinutes', $default->sourceBlockMinutes),
            sourceFirst: (bool) $v('sourceFirst', $default->sourceFirst),
            unlockPaths: array_values((array) $v('unlockPaths', $default->unlockPaths)),
            uniformDelayMilliseconds: (int) $v('uniformDelayMilliseconds', $default->uniformDelayMilliseconds),
            notifyUser: (bool) $v('notifyUser', $default->notifyUser),
            notifyAdmin: (bool) $v('notifyAdmin', $default->notifyAdmin),
        );

        $weak = [];

        if ($policy->threshold > $floor->threshold) {
            $weak[] = "failure_threshold {$policy->threshold} is above the floor of {$floor->threshold}";
        }

        if ($policy->lockMinutes < $floor->lockMinutes) {
            $weak[] = "lock_duration_minutes {$policy->lockMinutes} is below the floor of {$floor->lockMinutes}";
        }

        if ($policy->sourceThreshold > $floor->sourceThreshold) {
            $weak[] = "throttle.per_ip threshold {$policy->sourceThreshold} is above the floor of {$floor->sourceThreshold}";
        }

        if ($policy->uniformDelayMilliseconds < $floor->uniformDelayMilliseconds) {
            $weak[] = 'the uniform failure delay is below the floor, which reopens the timing oracle';
        }

        if ($weak !== []) {
            throw new \DomainException(
                "This lockout policy loosens the platform floor (R-XXII-180):\n  · " . implode("\n  · ", $weak)
            );
        }

        return $policy;
    }

    /**
     * @param  list<\DateTimeImmutable>  $accountFailures  failed attempts on this
     *         account **since its last success**, in any order
     * @param  list<\DateTimeImmutable>  $sourceFailures   failed attempts from this
     *         source address, across all accounts
     * @param  int   $priorConsecutiveLocks  how many times this account has already
     *         been locked without an intervening success — the backoff exponent
     * @param  bool  $accountExists  operator-only; it changes nothing about the
     *         decision or the response, and is carried solely so the audit row
     *         (R-XXII-211) can say what happened
     */
    public function evaluate(
        array $accountFailures,
        array $sourceFailures,
        int $priorConsecutiveLocks,
        Clock $clock,
        bool $accountExists = true,
    ): LockoutState {
        $source = $this->assess(
            $sourceFailures,
            $this->sourceThreshold,
            $this->sourceWindowMinutes,
            $this->sourceBlockMinutes,
            LockoutState::KEY_SOURCE,
            $clock,
            $accountExists,
        );

        if ($this->sourceFirst && $source->blocked) {
            return $source;
        }

        $account = $this->assess(
            $accountFailures,
            $this->threshold,
            $this->windowMinutes,
            $this->lockDurationMinutes($priorConsecutiveLocks),
            LockoutState::KEY_ACCOUNT,
            $clock,
            $accountExists,
        );

        if ($account->blocked) {
            return $account;
        }

        if ($source->blocked) {
            return $source;
        }

        return $accountExists
            ? $account
            // Same shape, same cost, no distinguishing field outside toAudit().
            : LockoutState::unknownAccount();
    }

    /** Progressive backoff (R-XXII-189), doubling and capped. */
    public function lockDurationMinutes(int $priorConsecutiveLocks): int
    {
        if (!$this->progressiveBackoff || $priorConsecutiveLocks < 1) {
            return $this->lockMinutes;
        }

        // Cap the exponent before the shift, not after: 2 ** 64 is not a number
        // this platform should ever compute to then throw away.
        $exponent = min($priorConsecutiveLocks, 16);

        return min($this->maxLockMinutes, $this->lockMinutes * (2 ** $exponent));
    }

    /**
     * One key's verdict.
     *
     * The window ends at the **most recent failure**, not at `now`. Ending it at
     * `now` would mean a 15-minute window and a 30-minute lock quietly release
     * the account at minute 15, when the failures aged out — the lock would
     * expire early and nobody would notice, because the only symptom is an
     * attacker getting more attempts.
     *
     * @param  list<\DateTimeImmutable>  $failures
     */
    private function assess(
        array $failures,
        int $threshold,
        int $windowMinutes,
        int $blockMinutes,
        string $key,
        Clock $clock,
        bool $accountExists,
    ): LockoutState {
        if ($failures === []) {
            return LockoutState::clear(0, $threshold, $accountExists);
        }

        $newest = max(array_map(static fn (\DateTimeImmutable $d): int => $d->getTimestamp(), $failures));
        $windowOpens = $newest - ($windowMinutes * 60);

        $inWindow = count(array_filter(
            $failures,
            static fn (\DateTimeImmutable $d): bool => $d->getTimestamp() >= $windowOpens,
        ));

        if ($inWindow < $threshold) {
            return LockoutState::clear($inWindow, $threshold - $inWindow, $accountExists);
        }

        $until = (new \DateTimeImmutable('@' . $newest))->modify('+' . $blockMinutes . ' minutes');

        return $clock->now() < $until
            ? LockoutState::blocked($key, $until, $inWindow, $accountExists)
            // The block ran its course. The failures stay on the record — they
            // are the audit trail and the backoff exponent — but they no longer
            // block, which is R-XXII-191's "clearable by expiry".
            : LockoutState::clear($inWindow, 0, $accountExists);
    }
}
