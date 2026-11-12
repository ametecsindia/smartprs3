<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * WHAT HAPPENED TO ONE FIELD — Part X §X.5.1, R-X-038.
 *
 * "`WriteResult` always carries a field-level outcome list: for every field in
 * `changes`, one of applied | unchanged | cleared | rejected(reason) |
 * routed_for_approval(workflow_instance). A caller can therefore always render
 * exactly what happened, and the import preview is the same structure."
 *
 * Every field in the command gets one of these — including the ones that were
 * refused, which is the half SmartPRS 2 has nothing of. Its importers drop
 * unknown columns by four different mechanisms and none of them tells anybody;
 * its ESS writes ten fields chosen by a hard-coded list and silently ignores the
 * rest. "It saved" and "it saved the six of your nine fields it recognised" look
 * identical from the outside unless the write says which.
 */
final readonly class FieldOutcome
{
    public const APPLIED = 'applied';
    public const UNCHANGED = 'unchanged';
    public const CLEARED = 'cleared';
    public const REJECTED = 'rejected';
    public const ROUTED = 'routed_for_approval';

    private function __construct(
        public string $fieldKey,
        public string $status,
        public string $detail = '',
        public mixed $oldValue = null,
        public mixed $newValue = null,
        public ?string $workflowKey = null,
    ) {
    }

    public static function applied(string $fieldKey, mixed $oldValue, mixed $newValue): self
    {
        return new self($fieldKey, self::APPLIED, '', $oldValue, $newValue);
    }

    public static function unchanged(string $fieldKey, mixed $value): self
    {
        return new self($fieldKey, self::UNCHANGED, 'the supplied value is the value already stored', $value, $value);
    }

    public static function cleared(string $fieldKey, mixed $oldValue): self
    {
        return new self($fieldKey, self::CLEARED, '', $oldValue, null);
    }

    public static function rejected(string $fieldKey, string $reason): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                "A rejected field states why. '{$fieldKey}: rejected' tells the caller to guess, and the guess "
                . 'is usually "the system is broken".'
            );
        }

        return new self($fieldKey, self::REJECTED, $reason);
    }

    public static function routed(string $fieldKey, string $workflowKey, mixed $newValue): self
    {
        return new self(
            $fieldKey,
            self::ROUTED,
            "the change is held for approval through {$workflowKey} and is not yet applied (R-X-039)",
            null,
            $newValue,
            $workflowKey,
        );
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function changedSomething(): bool
    {
        return $this->status === self::APPLIED || $this->status === self::CLEARED;
    }

    public function describe(): string
    {
        return $this->fieldKey . ': ' . $this->status . ($this->detail === '' ? '' : ' — ' . $this->detail);
    }
}
