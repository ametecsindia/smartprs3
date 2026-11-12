<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * What the lockout policy concluded, split into the two audiences that must
 * never see the same thing.
 *
 *  - **The operator and the audit ledger** get everything: which key tripped,
 *    how many failures, until when, whether the account even exists.
 *  - **The caller** gets `publicResponse()`, which takes no arguments, reads no
 *    property, and is therefore identical for a wrong password, a locked
 *    account, a throttled source and an account that does not exist.
 *
 * That asymmetry is R-XXII-193 made structural. SmartPRS 2's forgot-password
 * path gets this right — same message either way — and its **sign-in** path
 * gets it wrong: a second lookup returns a distinct message for a disabled
 * account, which is a free account-existence oracle on the one endpoint an
 * attacker is already pointed at. The fix is not "remember to return the same
 * string"; it is that the method a caller may render cannot branch, because it
 * has nothing to branch on.
 */
final readonly class LockoutState
{
    /** The only reason code an unauthenticated caller ever receives. */
    public const UNIFORM_FAILURE = 'AUTH_FAILED';

    public const KEY_ACCOUNT = 'account';
    public const KEY_SOURCE = 'source';

    /** No key tripped: an ordinary wrong password, or an account that is not there. */
    public const KEY_NONE = 'none';

    private function __construct(
        public bool $blocked,
        public string $key,
        public ?\DateTimeImmutable $blockedUntil,
        public int $failuresInWindow,
        public int $remainingBeforeBlock,
        /** Operator-only. Never reaches publicResponse(); see the class note. */
        public bool $accountExists,
    ) {
    }

    public static function clear(int $failuresInWindow, int $remainingBeforeBlock, bool $accountExists = true): self
    {
        return new self(false, self::KEY_NONE, null, $failuresInWindow, $remainingBeforeBlock, $accountExists);
    }

    public static function blocked(
        string $key,
        \DateTimeImmutable $until,
        int $failuresInWindow,
        bool $accountExists = true,
    ): self {
        return new self(true, $key, $until, $failuresInWindow, 0, $accountExists);
    }

    /**
     * An account the sign-in could not find.
     *
     * It is a state and not a null, because "there is no such user" must travel
     * through exactly the same code path, and cost the same, as every other
     * failure. A caller that can write `if ($state === null)` has an oracle.
     */
    public static function unknownAccount(): self
    {
        return new self(false, self::KEY_NONE, null, 0, 0, false);
    }

    /**
     * The complete response an unauthenticated caller may be given, for every
     * failure mode there is.
     *
     * No parameters and no property reads — that is the point, not an oversight.
     * The HTTP status is here too because a 429 for a locked account and a 401
     * for an unknown one is the same oracle wearing a different hat, and so is a
     * `Retry-After` header, which is why there is not one.
     *
     * @return array{reason: string, message: string, status: int}
     */
    public function publicResponse(): array
    {
        return [
            'reason' => self::UNIFORM_FAILURE,
            'message' => 'The email address or password is incorrect, or this account is not available.',
            'status' => 401,
        ];
    }

    /**
     * The operator-facing view. Goes to the audit ledger and the security
     * dashboard (R-XXII-211); never to an unauthenticated response body.
     *
     * @return array<string, mixed>
     */
    public function toAudit(): array
    {
        return [
            'blocked' => $this->blocked,
            'key' => $this->key,
            'blocked_until' => $this->blockedUntil?->format(\DateTimeInterface::ATOM),
            'failures_in_window' => $this->failuresInWindow,
            'remaining_before_block' => $this->remainingBeforeBlock,
            'account_exists' => $this->accountExists,
        ];
    }

    /**
     * True when the sign-in handler must NOT verify the password at all.
     *
     * The block is enforced here, server-side, while the response stays
     * uniform — a blocked account simply never reaches the hasher.
     */
    public function refusesVerification(): bool
    {
        return $this->blocked;
    }
}
