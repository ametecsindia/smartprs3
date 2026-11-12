<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * A thin wrapper over `password_hash` / `password_verify` (R-XXII-188).
 *
 * **Why the wrapper exists at all**, since it adds no cryptography and must not:
 *
 *  1. To pin the parameters in one place. SmartPRS 2's hashing is correct
 *     (bcrypt cost 12) but the cost lives in framework configuration, so
 *     "what cost is this installation actually using?" is answered by reading a
 *     deployed `.env` rather than by reading the product.
 *  2. To make **rehash-on-verify** a rule instead of a good intention. A
 *     parameter bump is worthless if nothing rehashes, and nothing rehashes
 *     unless the verify path returns the fact. `verify()` therefore hands back
 *     the answer AND `needsRehash()` is on the same object, so the sign-in
 *     handler cannot forget which hasher's parameters it is comparing against.
 *  3. To make a **user with no password hash** cost the same as a user with a
 *     wrong one. R-XXII-220 creates provisioned accounts with no password at
 *     all, and R-XXII-193 forbids an account-existence oracle. A `null` hash
 *     that short-circuits is a timing oracle for "this account is not yet
 *     activated", so `verify(null)` burns a real verification against a dummy
 *     hash of the same parameters and returns false.
 *
 * Everything else is the standard library's, deliberately. Argon2id and bcrypt
 * are in PHP; writing either here would be the single worst decision available
 * in this file.
 */
final class PasswordHasher
{
    /** R-XXII-188: retained from SmartPRS 2, which got this one right. */
    public const BCRYPT_COST = 12;

    /** OWASP's Argon2id floor at time of writing: 19 MiB, 2 passes, 1 lane. */
    public const ARGON2_MEMORY_KIB = 19_456;
    public const ARGON2_TIME_COST = 2;
    public const ARGON2_THREADS = 1;

    private ?string $dummyHash = null;

    /** @param array<string, int> $options */
    private function __construct(
        public readonly string $algorithm,
        public readonly array $options,
    ) {
    }

    /**
     * Argon2id where the deployment supports it, bcrypt at the platform cost
     * where it does not. The fallback is a *supported deployment* concession
     * (R-XXII-188), not a preference: a PHP built without libargon2 is a real
     * on-premise situation and refusing to start is not a better answer than
     * bcrypt cost 12.
     */
    public static function platformDefault(): self
    {
        return defined('PASSWORD_ARGON2ID')
            ? self::argon2id()
            : self::bcrypt();
    }

    public static function argon2id(
        int $memoryKib = self::ARGON2_MEMORY_KIB,
        int $timeCost = self::ARGON2_TIME_COST,
        int $threads = self::ARGON2_THREADS,
    ): self {
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException(
                'Argon2id is not available in this PHP build. Use PasswordHasher::bcrypt() '
                . 'explicitly so the deployment records the decision, rather than '
                . 'discovering it at the first sign-in.'
            );
        }

        return new self(PASSWORD_ARGON2ID, [
            'memory_cost' => $memoryKib,
            'time_cost' => $timeCost,
            'threads' => $threads,
        ]);
    }

    public static function bcrypt(int $cost = self::BCRYPT_COST): self
    {
        return new self(PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        // bcrypt truncates at 72 bytes and PHP 8.4 throws rather than truncating
        // silently. R-XXII-183 prohibits silent truncation, so let it throw: a
        // policy max_length below 72 is what keeps this unreachable in practice.
        return password_hash($password, $this->algorithm, $this->options);
    }

    /**
     * Timing-safe by way of `password_verify`, which is `hash_equals` inside.
     *
     * @param  ?string  $hash  NULL for an account with no password set. Costs
     *         the same as a real verification, on purpose (see the class note).
     */
    public function verify(#[\SensitiveParameter] string $candidate, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($candidate, $this->dummyHash());

            return false;
        }

        return password_verify($candidate, $hash);
    }

    /** True when this hash was made with weaker parameters than we now use. */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /**
     * The rehash-on-login rule, in one call, so the sign-in handler has nothing
     * to decide: a correct password whose hash is stale comes back with a
     * replacement to store, and everything else comes back null.
     *
     * @return ?string the new hash to persist, or null (wrong password, or the
     *         stored hash is already at current parameters)
     */
    public function verifyAndRehash(#[\SensitiveParameter] string $candidate, ?string $hash): ?string
    {
        if (!$this->verify($candidate, $hash) || $hash === null || $hash === '') {
            return null;
        }

        return $this->needsRehash($hash) ? $this->hash($candidate) : null;
    }

    /** Same algorithm, same cost, so a null-hash verify takes a real verify's time. */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hash(bin2hex(random_bytes(32)));
    }
}
