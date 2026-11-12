<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Access;

use SmartPRS\Platform\Support\EffectiveRange;

/**
 * The assignment triple of R-XXII-028: user → role → data scope, effective-dated.
 *
 * A user MAY hold several (R-XXII-030) — SmartPRS 2 enforces exactly one role per
 * user via `syncRoles([$role])` at both assignment surfaces, which is why the
 * commission chain "Agent claims → Accounts confirms → Manager approves" cannot
 * be expressed for a person who does both jobs.
 *
 * `$role` and `$scope` are nullable **on purpose**. They model the two rows of
 * R-XXII-094 that are deployment defects rather than user errors: an assignment
 * naming a role code that does not resolve, and an assignment whose scope cannot
 * be loaded. Both must produce a denial with an alert. Making them nullable puts
 * that state inside the type instead of leaving it to a caller who might pass a
 * role-less user through — which is exactly SmartPRS 2's D-05, where
 * `RoleDoesNotExist` is swallowed, `{"ok":true}` is returned, and the SPA renders
 * the resulting role-less account as Administrator.
 */
final readonly class Assignment
{
    public function __construct(
        public string $roleCode,
        public ?Role $role,
        public string $scopeKey,
        public ?DataScope $scope,
        public EffectiveRange $range,
        public string $label = '',
    ) {
    }

    /** Convenience for a resolved, open-ended grant. */
    public static function of(Role $role, DataScope $scope, string $from = '2000-01-01', string $label = ''): self
    {
        return new self(
            $role->code,
            $role,
            $scope->key,
            $scope,
            EffectiveRange::openEndedFrom($from),
            $label,
        );
    }

    /** R-XXII-029: a revocation closes effective_to; it never deletes the row. */
    public function isInForce(\DateTimeImmutable $on): bool
    {
        return $this->range->contains($on);
    }

    public function describe(): string
    {
        return ($this->label !== '' ? $this->label . ' · ' : '')
            . 'role ' . ($this->role?->__toString() ?? "'{$this->roleCode}' UNRESOLVED")
            . ' · scope ' . ($this->scope?->__toString() ?? "'{$this->scopeKey}' UNRESOLVED");
    }
}
