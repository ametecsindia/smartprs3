<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Identity;

/**
 * The outcome of one password-policy evaluation, carrying **every** rule the
 * candidate failed rather than the first.
 *
 * This is a deliberate correction of an interaction defect the SmartPRS 2
 * importer has everywhere: validate, stop at the first failure, tell the user,
 * let them fix it, fail on the next one. A user setting a password is at the
 * least patient moment in the product's whole lifecycle, and a policy that
 * reveals itself one rule at a time is a policy people work around by choosing
 * something they will write down.
 *
 * Failures are keyed by machine-readable code so the presentation layer can
 * order and translate them; the message is the operator-readable fallback. The
 * codes are stable — a client may branch on them.
 */
final readonly class PolicyResult
{
    public const TOO_SHORT = 'PASSWORD_TOO_SHORT';
    public const TOO_LONG = 'PASSWORD_TOO_LONG';
    public const MISSING_CLASS = 'PASSWORD_MISSING_CHARACTER_CLASS';
    public const DENYLISTED = 'PASSWORD_DENYLISTED';
    public const IDENTITY_TERM = 'PASSWORD_CONTAINS_IDENTITY_TERM';
    public const BREACHED = 'PASSWORD_BREACHED';

    /** R-XXII-184: the corpus was unreachable and the policy fails closed on the change. */
    public const BREACH_CHECK_UNAVAILABLE = 'PASSWORD_BREACH_CHECK_UNAVAILABLE';

    public const REUSED = 'PASSWORD_REUSED';
    public const CHANGED_TOO_RECENTLY = 'PASSWORD_CHANGED_TOO_RECENTLY';

    /** Not a set-time failure: the answer to "must this user change it now?" (R-XXII-186). */
    public const EXPIRED = 'PASSWORD_EXPIRED';

    /** @param array<string, string> $failures code => operator-readable message, in policy order */
    private function __construct(public array $failures)
    {
    }

    /** @param array<string, string> $failures */
    public static function of(array $failures): self
    {
        return new self($failures);
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }

    /** @return list<string> the stable codes, in the order the policy evaluated them */
    public function codes(): array
    {
        return array_keys($this->failures);
    }

    public function failed(string $code): bool
    {
        return isset($this->failures[$code]);
    }

    /**
     * Everything wrong with the candidate, in one message.
     *
     * R-XXII-193's uniformity rule governs *authentication* responses, not
     * *set-password* responses: at set time the user is already authenticated,
     * is choosing their own secret, and telling them exactly what is wrong
     * discloses nothing about anyone else.
     */
    public function render(): string
    {
        return $this->ok()
            ? 'The password satisfies the policy.'
            : implode("\n", array_map(
                static fn (string $code, string $message): string => '  · ' . $message . '  [' . $code . ']',
                array_keys($this->failures),
                array_values($this->failures),
            ));
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
