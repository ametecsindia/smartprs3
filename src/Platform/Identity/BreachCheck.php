<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * The k-anonymity breach-corpus check of R-XXII-184.
 *
 * This is an abstract class and not an interface **on purpose**, and the reason
 * is the whole security property: the plaintext candidate must never leave this
 * file. `isBreached()` is `final` — it computes the SHA-1, splits off the first
 * five hex characters, and hands the implementation only that prefix. An
 * implementation therefore *cannot* see the password even if it wants to, which
 * is exactly what k-anonymity means and exactly what an interface with an
 * `isBreached(string $password)` method would have quietly given away to every
 * future HTTP client someone writes against a third-party service.
 *
 * SHA-1 is not a security choice here. It is the wire format of the
 * haveibeenpwned range API (`GET /range/{first5}` → `SUFFIX:count` lines), and
 * the corpus is public by construction — there is nothing to protect. Do not
 * "upgrade" it; it would stop matching every published corpus.
 *
 * ### Fail-open or fail-closed — the policy decision, stated
 *
 * R-XXII-184 splits the question in two, and so does this class:
 *
 *  - **At set time** (choosing or changing a password) the platform fails
 *    **closed**: an unreachable corpus produces `BREACH_CHECK_UNAVAILABLE` and
 *    the change is refused with a retry-later. This is the recommended default
 *    and it is `PasswordPolicy::$rejectWhenBreachCheckUnavailable = true`.
 *    Rationale: the cost of failing closed is that a user retries a password
 *    change in a few minutes; the cost of failing open is that the one password
 *    the outage let through is, by the base rates of credential stuffing,
 *    disproportionately likely to be the breached one — an attacker who can
 *    cause the outage chooses precisely that moment.
 *  - **At sign-in** the check is not consulted at all, so an outage can never
 *    lock a tenant out of its own installation. The existing password is
 *    already set; re-deciding it during an outage buys nothing.
 *
 * The knob is configurable because an air-gapped on-premise installation with
 * no corpus and no egress would otherwise be unable to set any password at all.
 * Turning it off is a tenant decision that is recorded in the auth policy
 * artefact and therefore visible in the maker-checker diff (R-XXII-182).
 */
abstract class BreachCheck
{
    /** The k-anonymity prefix length the range API is defined on. */
    public const PREFIX_LENGTH = 5;

    /**
     * @throws BreachCheckUnavailable when the corpus could not be consulted;
     *         the caller decides what an unavailable corpus means (see above).
     */
    final public function isBreached(#[\SensitiveParameter] string $candidate): bool
    {
        $digest = strtoupper(sha1($candidate));
        $prefix = substr($digest, 0, self::PREFIX_LENGTH);
        $suffix = substr($digest, self::PREFIX_LENGTH);

        $found = false;

        foreach ($this->suffixesFor($prefix) as $known) {
            // No early return: the corpus response is public data, but scanning
            // it in constant time costs nothing and removes the question.
            $found = hash_equals(strtoupper($known), $suffix) || $found;
        }

        return $found;
    }

    /**
     * The range response for one prefix: every 35-character SHA-1 suffix the
     * corpus holds under it. The implementation never sees the candidate.
     *
     * @param  string  $sha1Prefix5  uppercase hex, exactly PREFIX_LENGTH long
     * @return iterable<string>
     * @throws BreachCheckUnavailable
     */
    abstract protected function suffixesFor(string $sha1Prefix5): iterable;
}
