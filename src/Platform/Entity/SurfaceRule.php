<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * One row of the surface-rule matrix — Part X §X.4.2.
 *
 * `(field, surface, role)` with role nullable: a null role is the "all roles"
 * row, which is step 2 of resolution and is strictly less specific than a row
 * naming a role.
 *
 * `changeWorkflowKey` is how a field routes to maker-checker (spine S8) PER
 * SURFACE AND ROLE. Bank details changed by HR on the edit screen and the same
 * field changed by the employee on ESS are not the same risk, and one flag on
 * the field could not say so.
 */
final readonly class SurfaceRule
{
    public function __construct(
        public string $fieldKey,
        public Surface $surface,
        public ?string $roleKey,
        public Visibility $visibility,
        public Requiredness $requiredness = Requiredness::Never,
        public ?string $requiredExpression = null,
        public ?string $maskingOverride = null,
        public ?string $changeWorkflowKey = null,
        // R-X-032, the directory surface only.
        public bool $isListColumn = false,
        public ?int $listOrder = null,
        public bool $isFilterable = false,
        public bool $isSortable = false,
        public bool $isQuickSearch = false,
    ) {
        if ($requiredness === Requiredness::Conditional && $requiredExpression === null) {
            throw new \InvalidArgumentException(
                "A conditional requirement on '{$fieldKey}' at {$surface->value} names no expression. "
                . '"Required, sometimes" with nothing to evaluate is required never, and nobody would have '
                . 'written it down meaning that.'
            );
        }

        if ($maskingOverride !== null && !in_array($maskingOverride, FieldDefinition::MASKING_RULES, true)) {
            throw new \InvalidArgumentException("'{$maskingOverride}' is not a masking rule (X.2.6).");
        }

        // R-X-032: a field hidden on the directory cannot be filtered or searched
        // on. This is checked here as well as in the validator because a rule
        // object that cannot be true should not be constructible.
        if ($visibility === Visibility::Hidden && ($isFilterable || $isSortable || $isQuickSearch || $isListColumn)) {
            throw new \InvalidArgumentException(
                "'{$fieldKey}' is hidden on {$surface->value} and also declared filterable, sortable, "
                . 'searchable or a list column. A hidden field that can be filtered on is a field whose values '
                . 'can be discovered by whoever guesses the filter (R-X-032).'
            );
        }
    }

    /** Step 1 beats step 2: a rule naming a role is more specific than the all-roles row. */
    public function isRoleSpecific(): bool
    {
        return $this->roleKey !== null;
    }

    public function appliesTo(Surface $surface, array $actorRoles): bool
    {
        if ($surface !== $this->surface) {
            return false;
        }

        return $this->roleKey === null || in_array($this->roleKey, $actorRoles, true);
    }
}
