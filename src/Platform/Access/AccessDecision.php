<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * The outcome of one authorisation decision, with the trace that explains it.
 *
 * Spine S15 requires every derived value to explain itself, and R-XXII-270 says
 * an authorisation decision is a derived value. The trace is not decoration:
 * §XXII.13 specifies it, support lives on it, and R-XXII-274 requires a denial to
 * be reproducible from the audit ledger alone. SmartPRS 2 conflates "not
 * permitted", "not licensed", "subscription lapsed" and "demo write blocked"
 * into indistinguishable responses and returns raw exception messages on ~15
 * endpoints, so nobody can answer "why was I denied?" from either end.
 *
 * The `reason` is machine-readable and distinguishes the axis that failed
 * (R-XXII-051): permission absent, or permission present but record out of scope.
 * That distinction is the property SmartPRS 2 cannot offer at all.
 *
 * R-XXII-272: the trace names org units, counts and the actor's own assignments.
 * It never names another employee's attributes, and a cross-tenant subject
 * produces NOT_FOUND with no trace (R-XXII-097).
 */
final readonly class AccessDecision
{
    public const GRANTED = 'ACCESS_GRANTED';

    /** The operation names a code no catalogue row and no artefact provides. */
    public const PERMISSION_UNKNOWN = 'ACCESS_PERMISSION_UNKNOWN';

    /** The code is well-formed and catalogued; no in-force assignment carries it. */
    public const PERMISSION_DENIED = 'ACCESS_PERMISSION_DENIED';

    /** Held — but no assignment carrying it reaches this record. */
    public const SCOPE_DENIED = 'ACCESS_SCOPE_DENIED';

    /** An assignment names a role code that does not resolve (R-XXII-024). */
    public const ROLE_UNKNOWN = 'ACCESS_ROLE_UNKNOWN';

    /** An in-force assignment has no loadable scope. */
    public const SCOPE_MISSING = 'ACCESS_SCOPE_MISSING';

    /** A scope type with no resolver, or a reporting cycle (R-XXII-044). */
    public const SCOPE_ERROR = 'ACCESS_SCOPE_ERROR';

    /** The subject cannot be placed at all — no employee, no org unit. */
    public const SUBJECT_UNRESOLVABLE = 'ACCESS_SUBJECT_UNRESOLVABLE';

    /** No assignment in force. R-XXII-036: this user is not an administrator. */
    public const NO_ASSIGNMENT = 'ACCESS_NO_ASSIGNMENT';

    /** Cross-tenant. R-XXII-097: existence is not disclosable, so 404, not 403. */
    public const NOT_FOUND = 'ACCESS_NOT_FOUND';

    /** Anything thrown in evaluation. R-XXII-094, first row. */
    public const ERROR = 'ACCESS_ERROR';

    /** @param list<string> $trace */
    private function __construct(
        public bool $allowed,
        public string $reason,
        public string $summary,
        public array $trace,
    ) {
    }

    /** @param list<string> $trace */
    public static function allow(string $summary, array $trace = []): self
    {
        return new self(true, self::GRANTED, $summary, $trace);
    }

    /** @param list<string> $trace */
    public static function deny(string $reason, string $summary, array $trace = []): self
    {
        if ($reason === self::GRANTED) {
            throw new \LogicException('A denial cannot carry the granted reason code.');
        }

        return new self(false, $reason, $summary, $trace);
    }

    /** True when the denial should render as 404 rather than 403 (R-XXII-097). */
    public function hidesExistence(): bool
    {
        return $this->reason === self::NOT_FOUND;
    }

    /** True when R-XXII-094 requires an immediate operator alert. */
    public function alerts(): bool
    {
        return in_array(
            $this->reason,
            [self::ERROR, self::ROLE_UNKNOWN, self::PERMISSION_UNKNOWN, self::SCOPE_MISSING, self::SCOPE_ERROR],
            true,
        );
    }

    /** The §XXII.13 shape: decision, reason, what failed, what would change it. */
    public function render(): string
    {
        return implode("\n", [
            'Decision        ' . ($this->allowed ? 'ALLOW' : 'DENY  ·  ' . $this->reason),
            $this->summary,
            ...array_map(static fn (string $line): string => '  ' . $line, $this->trace),
        ]);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /** @return array<string, mixed> for the audit ledger row (R-XXII-274). */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'summary' => $this->summary,
            'trace' => $this->trace,
        ];
    }
}
