<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

use SmartPRS\Platform\Support\Clock;

/**
 * RFC 6238 time-based one-time passwords — the default and required MFA method
 * of R-XXII-195. SmartPRS 2 has no MFA of any kind.
 *
 * The algorithm is `hash_hmac` plus RFC 4226 dynamic truncation and nothing
 * else; there is no cryptography written here and there must not be. What this
 * class owns is the three things a TOTP implementation actually gets wrong:
 *
 *  1. **The drift window is bounded and explicit.** One step either side of the
 *     current one, so a code is live for at most 90 seconds. A generous window
 *     is the commonest silent weakening of TOTP — it multiplies the attacker's
 *     guessing surface by exactly the number of steps accepted.
 *  2. **Replay is rejected** (R-XXII-195, the TOTP row). A code that is still
 *     inside its drift window is otherwise reusable by anyone who watched it
 *     being typed, which removes most of the point of the second factor.
 *     `verify()` cannot be called without a claim function; see below.
 *  3. **No `now()`.** The clock is injected, so the RFC's published vectors are
 *     ordinary assertions and the drift boundaries are testable exactly rather
 *     than approximately.
 *
 * ### The claim function
 *
 * `verify()` takes `callable(int $counter): bool` — "record that this factor has
 * consumed this counter, and tell me whether it was new". In the database that
 * is one `INSERT` against `UNIQUE(factor_id, counter)` and the return value is
 * whether the insert happened; the uniqueness constraint, not application logic,
 * is what makes two simultaneous submissions of the same code resolve to one
 * acceptance. It is a required parameter rather than an optional store because
 * an optional replay guard is a replay guard nobody passes.
 *
 * ponytail: a caller can defeat this with `fn () => true`, and no design
 * prevents that. Making it a required argument means doing so is a visible line
 * in a diff rather than the absence of one.
 */
final readonly class Totp
{
    /** RFC 4648 base32, the alphabet every authenticator app expects. */
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @param  string  $algorithm  sha1 | sha256 | sha512. SHA-1 is the default
     *         because it is what authenticator apps implement; HOTP's security
     *         rests on HMAC, which SHA-1's collision weakness does not reach.
     * @param  int     $driftSteps  steps accepted either side of the current one.
     *         A platform constant (R-XXII-195), not a tenant knob: nobody has
     *         ever asked to widen it for a reason that survived being written
     *         down.
     */
    public function __construct(
        public string $algorithm = 'sha1',
        public int $digits = 6,
        public int $period = 30,
        public int $driftSteps = 1,
    ) {
        if (!in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new \InvalidArgumentException("'{$algorithm}' is not an RFC 6238 algorithm (sha1, sha256, sha512).");
        }

        if ($digits < 6 || $digits > 10) {
            throw new \InvalidArgumentException('RFC 4226 defines 6 to 10 digits; 6 is what apps display, 8 is the vector length.');
        }

        if ($period < 1 || $driftSteps < 0) {
            throw new \InvalidArgumentException('The period must be positive and the drift window must not be negative.');
        }
    }

    /**
     * A new shared secret, base32-encoded for the provisioning URI.
     *
     * 160 bits, which is RFC 4226 §4 R6's recommendation and the HMAC-SHA1
     * block-independent size every app handles. `random_bytes` only — this is
     * the one value in the whole factor that must be unguessable.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('A TOTP secret below 128 bits is not worth enrolling.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    /** The RFC 6238 time step for an instant, with T0 = 0. */
    public function counterAt(\DateTimeImmutable $at): int
    {
        return intdiv($at->getTimestamp(), $this->period);
    }

    public function codeAt(#[\SensitiveParameter] string $secretBase32, \DateTimeImmutable $at): string
    {
        return $this->codeForCounter($secretBase32, $this->counterAt($at));
    }

    /** RFC 4226 §5.3 dynamic truncation over an HMAC of the 8-byte counter. */
    public function codeForCounter(#[\SensitiveParameter] string $secretBase32, int $counter): string
    {
        $mac = hash_hmac(
            $this->algorithm,
            pack('J', $counter),          // 64-bit big-endian, per RFC 4226 §5.1
            self::base32Decode($secretBase32),
            true,
        );

        $offset = ord($mac[strlen($mac) - 1]) & 0x0F;

        $truncated = ((ord($mac[$offset]) & 0x7F) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);

        return str_pad(
            (string) ($truncated % (10 ** $this->digits)),
            $this->digits,
            '0',
            STR_PAD_LEFT,
        );
    }

    /**
     * @param  callable(int): bool  $claimCounter  records the counter as consumed
     *         for this factor and returns false if it already was
     * @return ?int  the counter that matched, for the audit row; null on any
     *         failure — wrong code, outside the window, or a replay
     */
    public function verify(
        #[\SensitiveParameter] string $secretBase32,
        string $code,
        Clock $clock,
        callable $claimCounter,
    ): ?int {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (strlen($code) !== $this->digits) {
            return null;
        }

        $current = $this->counterAt($clock->now());
        $matched = null;

        // Every candidate is evaluated, with no early exit, so the response time
        // does not reveal which step matched — a code accepted at -1 and one
        // accepted at +1 must cost the same.
        for ($step = -$this->driftSteps; $step <= $this->driftSteps; $step++) {
            $counter = $current + $step;

            if (hash_equals($this->codeForCounter($secretBase32, $counter), $code) && $matched === null) {
                $matched = $counter;
            }
        }

        if ($matched === null) {
            return null;
        }

        // R-XXII-195: a code still inside its drift window is not usable twice.
        return $claimCounter($matched) ? $matched : null;
    }

    /**
     * The `otpauth://` URI an authenticator app scans (the de-facto Key URI
     * format; there is no RFC).
     *
     * The label is `issuer:account` AND the issuer is repeated as a parameter,
     * because the older apps read one and the newer ones read the other.
     */
    public function provisioningUri(string $secretBase32, string $account, string $issuer): string
    {
        if ($account === '' || $issuer === '') {
            throw new \InvalidArgumentException(
                'A provisioning URI without an issuer and an account produces an unlabelled '
                . 'entry in the user\'s app, which is how people delete the wrong factor.'
            );
        }

        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query([
            'secret' => rtrim($secretBase32, '='),   // apps reject the padding
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * RFC 4648 base32.
     *
     * ponytail: bit-string manipulation rather than bit arithmetic — a secret is
     * 20 to 64 bytes and this runs at enrolment and once per verification step.
     * If it ever shows in a profile, the arithmetic version is ten lines; it is
     * not ten lines anybody should read today.
     */
    public static function base32Encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out . str_repeat('=', (8 - (strlen($out) % 8)) % 8);
    }

    public static function base32Decode(#[\SensitiveParameter] string $encoded): string
    {
        $encoded = strtoupper(str_replace([' ', '-', '='], '', $encoded));

        if ($encoded === '') {
            throw new \InvalidArgumentException('An empty TOTP secret cannot verify anything.');
        }

        $bits = '';

        foreach (str_split($encoded) as $char) {
            $index = strpos(self::BASE32, $char);

            if ($index === false) {
                throw new \InvalidArgumentException(
                    "'{$char}' is not an RFC 4648 base32 character. A secret that decodes "
                    . 'partially would verify nothing and look like a wrong code forever.'
                );
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        // The trailing partial group is padding, not data — dropped, not zero-filled.
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $out .= chr(bindec($byte));
        }

        return $out;
    }
}
