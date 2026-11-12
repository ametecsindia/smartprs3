<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * What an actor may do with a field on a surface — Part X §X.4.2.
 *
 * Three values, ordered, and the order is the whole point: when an actor holds
 * several roles with rules on the same field, the MOST PERMISSIVE visibility
 * applies (X.4.2 step 1). That is a max() over this ordering, and it is written
 * here rather than at the call site so there is one definition of "more
 * permissive" in the codebase.
 *
 * R-X-031: `Read` on a write surface means the field is rendered disabled AND a
 * supplied value is REJECTED with an explicit error. It does not mean the value
 * is ignored. Silently discarding a submitted value is forbidden anywhere in
 * SmartPRS 3 — a user who typed something and was told nothing believes it
 * saved.
 */
enum Visibility: string
{
    case Hidden = 'hidden';
    case Read = 'read';
    case Write = 'write';

    public function rank(): int
    {
        return match ($this) {
            self::Hidden => 0,
            self::Read => 1,
            self::Write => 2,
        };
    }

    public function mostPermissiveOf(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    public function allowsWrite(): bool
    {
        return $this === self::Write;
    }

    public function allowsRead(): bool
    {
        return $this !== self::Hidden;
    }

    /** Why a write was refused, in words an API caller can act on. */
    public function refusalFor(string $fieldKey, Surface $surface): string
    {
        return match ($this) {
            self::Hidden => "'{$fieldKey}' is not a field on {$surface->value}. It was not ignored: a value was "
                . 'supplied for a field this surface does not expose, and that is an error rather than a no-op.',
            self::Read => "'{$fieldKey}' is read-only on {$surface->value} for this actor (R-X-031). The supplied "
                . 'value is rejected rather than discarded, so nobody is told a change saved when it did not.',
            self::Write => '',
        };
    }
}
