<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * ONE ROW OF THE EMPLOYMENT STATUS TRANSITION TABLE — Part X §X.7.2.
 *
 * "A transition not present in the table is impossible."
 *
 * That sentence is the whole state machine. SmartPRS 2 has no transition guards
 * at all, so an archived employee can be reactivated and then soft-deleted,
 * producing rows with both `archived_at` and `deleted_at` set — a state nobody
 * designed and no screen can explain. And `storeEmployee()` rewrites `status`
 * unconditionally on every save, so an exited employee is silently reset to
 * `active` by an edit to their phone number.
 *
 * `from` may be `*`, which is the one wildcard and exists because archiving
 * applies from every status. It is still a ROW: a wildcard in the table is a
 * declaration, where a wildcard in code is an exception nobody can find.
 */
final readonly class StatusTransition
{
    public const ANY = '*';

    public function __construct(
        public string $from,
        public string $to,
        public string $requiredPermission,
        public bool $requiresReason = false,
        public bool $requiresEffectiveDate = true,
        public ?string $guardExpression = null,
        public ?string $workflowArtefactKey = null,
    ) {
        if ($from === '' || $to === '') {
            throw new \InvalidArgumentException('A transition names both ends.');
        }

        if ($from === $to) {
            throw new \InvalidArgumentException(
                "A transition from '{$from}' to itself is not a transition. If the intent is to re-record a "
                . 'status with a new effective date, that is a correction to the period, not a change of state.'
            );
        }

        if ($to === self::ANY) {
            throw new \InvalidArgumentException(
                'The wildcard is only valid as the FROM end. A transition to anything is not a transition table.'
            );
        }

        if (trim($requiredPermission) === '') {
            throw new \InvalidArgumentException(
                "The transition {$from} → {$to} names no permission. Every status change is permissioned; "
                . 'a blank one would be the change anybody can make.'
            );
        }
    }

    public function matches(string $from, string $to): bool
    {
        return $this->to === $to && ($this->from === $from || $this->from === self::ANY);
    }

    /** A row naming a status beats the wildcard row, so `*` is a fallback rather than an override. */
    public function isWildcard(): bool
    {
        return $this->from === self::ANY;
    }

    public function describe(): string
    {
        return "{$this->from} → {$this->to} (needs {$this->requiredPermission}"
            . ($this->requiresReason ? ', a reason' : '')
            . ($this->guardExpression === null ? '' : ', guard: ' . $this->guardExpression)
            . ($this->workflowArtefactKey === null ? '' : ', via ' . $this->workflowArtefactKey)
            . ')';
    }
}
