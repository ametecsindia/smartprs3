<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * MFA recovery codes (R-XXII-195, recovery-codes row; R-XXII-225).
 *
 * Generated at enrolment, shown once, hashed at rest, single-use, count
 * configurable, regeneration audited. There is no "email a bypass" path — these
 * codes and an administrator-assisted reset under maker-checker are the only two
 * ways back in.
 *
 * ### Why SHA-256 and not Argon2id
 *
 * A recovery code is **not a password**. It is 80 bits generated here from
 * `random_bytes`, so there is no dictionary to attack and no user-chosen
 * structure to exploit; stretching it would slow an attacker's search of a
 * 2^80 space by a constant factor that is irrelevant against a space nothing
 * can search. A fast digest plus `hash_equals` is the correct tool, and it is
 * exactly the mechanism Part XXII §XXII.8 singles out as the best-engineered
 * component in SmartPRS 2 — the API-key check. Same reasoning, same code shape.
 *
 * Passwords go through PasswordHasher. Do not cross the two over.
 *
 * ### Single use, made structural
 *
 * `redeem()` returns the **remaining set** rather than a boolean. There is no
 * call that says "this code is valid" without also handing back the set with
 * that code gone, so a caller cannot accept a code and forget to burn it — the
 * only way to ignore the consumption is to discard the return value it needed
 * anyway.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    /** 10 bytes → 16 base32 characters → 80 bits. */
    private const SECRET_BYTES = 10;

    private const ALGORITHM = 'sha256';

    /**
     * @param  int  $count  R-XXII-195: configurable. Too few and a user who
     *         loses a phone mid-holiday is stuck with the administrator path;
     *         too many and they are printed and left in a drawer.
     * @return array{codes: list<string>, hashes: list<string>}  the codes are
     *         the ONLY time the plaintext exists — show them once and drop them.
     *         `hashes` is what is persisted, index-aligned.
     */
    public static function issue(int $count = self::COUNT): array
    {
        if ($count < 1 || $count > 64) {
            throw new \InvalidArgumentException(
                "recovery_code_count must be between 1 and 64; {$count} was asked for."
            );
        }

        $codes = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = self::format(Totp::base32Encode(random_bytes(self::SECRET_BYTES)));
            $codes[] = $code;
            $hashes[] = self::hash($code);
        }

        return ['codes' => $codes, 'hashes' => $hashes];
    }

    /** Normalises first, so the dashes and the case a user retypes do not matter. */
    public static function hash(#[\SensitiveParameter] string $code): string
    {
        return hash(self::ALGORITHM, self::normalise($code));
    }

    /**
     * Consume one code.
     *
     * @param  list<string>  $remainingHashes  the codes not yet used
     * @return ?list<string>  the set with the consumed code removed, or NULL
     *         when nothing matched. A previously-redeemed code is simply not in
     *         the set any more, so a second attempt returns NULL — single use,
     *         by construction rather than by a flag someone must check.
     */
    public static function redeem(#[\SensitiveParameter] string $candidate, array $remainingHashes): ?array
    {
        $wanted = self::hash($candidate);
        $matchedAt = null;

        // Every entry is compared, with no early exit: the number of comparisons
        // must not depend on which code was presented, or on whether one matched.
        foreach ($remainingHashes as $index => $hash) {
            if (hash_equals($hash, $wanted) && $matchedAt === null) {
                $matchedAt = $index;
            }
        }

        if ($matchedAt === null) {
            return null;
        }

        unset($remainingHashes[$matchedAt]);

        return array_values($remainingHashes);
    }

    /** `ABCD-EFGH-IJKL-MNOP` — grouped so a human can read it off a printout. */
    private static function format(string $base32): string
    {
        return implode('-', str_split(rtrim($base32, '='), 4));
    }

    private static function normalise(#[\SensitiveParameter] string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
