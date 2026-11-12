<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

/**
 * ONE REGISTERED SERVER OPERATION — Part XXII §XXII.4.2, R-XXII-073.
 *
 * "Every route MUST declare an operation — a registry entry naming its required
 * permission, its subject resolver, its scope requirement, its assurance
 * requirement, its idempotency behaviour and its audit class."
 *
 * R-XXII-074: a route with no operation binding FAILS THE BUILD. There is no
 * default operation and no "public unless stated" — Law 5, and spine S13's
 * first non-negotiable. SmartPRS 2 has 78 authenticated routes with no
 * server-side role or self check, and an unauthenticated surface of 78
 * declarations spread across four route files and three conditional blocks that
 * nobody can state from the code.
 *
 * R-XXII-077: this registry is also the generator input for the front-end
 * capability payload, the OpenAPI security blocks, the route × role matrix test
 * and the permissions editor's screen list. One registry, four consumers, no
 * drift — where SmartPRS 2's client permission map covers 120 of 146 screens,
 * leaving the platform, licence and configuration screens unmapped entirely.
 */
final readonly class Operation
{
    public const SUBJECT_KINDS = ['employee', 'org_unit', 'tenant', 'none'];

    public const SCOPE_REQUIREMENTS = ['required', 'not_applicable'];

    public const AUDIT_CLASSES = ['none', 'write', 'sensitive_read', 'privileged'];

    public const RATE_LIMIT_KEYS = ['identity', 'tenant', 'api_key', 'ip', 'device'];

    /** `none`, `mfa`, or `reauth(<seconds>)`. */
    private const ASSURANCE_PATTERN = '/^(none|mfa|reauth\(\d+\))$/';

    /**
     * @param list<string> $httpBindings  'POST /api/v3/payroll/runs/{uuid}/approve'
     */
    public function __construct(
        public string $code,
        public array $httpBindings,
        public string $requiredPermission,
        public string $subjectKind = 'none',
        public ?string $subjectResolver = null,
        public string $scopeRequirement = 'not_applicable',
        public string $assurance = 'none',
        public string $auditClass = 'write',
        public ?string $makerChecker = null,
        public string $rateLimitKey = 'identity',
        public ?string $csrfExemptReason = null,
        public bool $csrfExempt = false,
        public bool $unauthenticated = false,
        public bool $impersonationForbidden = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/', $code)) {
            throw new \InvalidArgumentException("Operation code '{$code}' must be dotted lower_snake_case.");
        }

        if ($httpBindings === []) {
            throw new \InvalidArgumentException("Operation '{$code}': an operation binds at least one route.");
        }

        if (!in_array($subjectKind, self::SUBJECT_KINDS, true)) {
            throw new \InvalidArgumentException("Operation '{$code}': '{$subjectKind}' is not a subject kind.");
        }

        if (!in_array($scopeRequirement, self::SCOPE_REQUIREMENTS, true)) {
            throw new \InvalidArgumentException("Operation '{$code}': '{$scopeRequirement}' is not a scope requirement.");
        }

        if (!in_array($auditClass, self::AUDIT_CLASSES, true)) {
            throw new \InvalidArgumentException("Operation '{$code}': '{$auditClass}' is not an audit class.");
        }

        if (!in_array($rateLimitKey, self::RATE_LIMIT_KEYS, true)) {
            throw new \InvalidArgumentException("Operation '{$code}': '{$rateLimitKey}' is not a rate-limit key.");
        }

        if (preg_match(self::ASSURANCE_PATTERN, $assurance) !== 1) {
            throw new \InvalidArgumentException(
                "Operation '{$code}': assurance is none, mfa or reauth(<seconds>), got '{$assurance}'."
            );
        }

        if ($requiredPermission === '' && !$unauthenticated) {
            throw new \InvalidArgumentException(
                "Operation '{$code}': an authenticated operation names a required permission. "
                . 'There is no default and no "public unless stated" (R-XXII-074).'
            );
        }
    }

    public function requiresReauth(): bool
    {
        return str_starts_with($this->assurance, 'reauth(');
    }

    public function reauthSeconds(): ?int
    {
        return preg_match('/^reauth\((\d+)\)$/', $this->assurance, $m) === 1 ? (int) $m[1] : null;
    }

    public function describe(): string
    {
        return $this->code . ' → ' . $this->requiredPermission
            . ' [' . implode(', ', $this->httpBindings) . ']';
    }
}
