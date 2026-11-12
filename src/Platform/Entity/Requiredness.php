<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * When a field must carry a value — Part X §X.4.2.
 *
 * Ordered the other way from Visibility, and for the mirror reason: across an
 * actor's several roles the STRICTEST requiredness applies. A role that does not
 * require a field cannot excuse a role that does.
 *
 * `Conditional` carries an SPX expression, evaluated in the entity's own
 * context. R-X-030: an evaluation error there DENIES — it does not fall back to
 * "not required", because a validator that fails open is the one nobody notices
 * has stopped working.
 */
enum Requiredness: string
{
    case Never = 'never';
    case Create = 'create';
    case Conditional = 'conditional';
    case Always = 'always';

    public function rank(): int
    {
        return match ($this) {
            self::Never => 0,
            self::Create => 1,
            self::Conditional => 2,
            self::Always => 3,
        };
    }

    public function strictestOf(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /**
     * Whether a value must be present, given what the write is doing.
     *
     * `Conditional` is answered by the CALLER, which has evaluated the
     * expression: this enum will not guess. Passing null for a conditional field
     * is treated as required, because an unanswered condition is not a licence
     * to skip the check.
     */
    public function demandsValue(bool $isCreate, ?bool $conditionMet = null): bool
    {
        return match ($this) {
            self::Never => false,
            self::Create => $isCreate,
            self::Always => true,
            self::Conditional => $conditionMet ?? true,
        };
    }
}
