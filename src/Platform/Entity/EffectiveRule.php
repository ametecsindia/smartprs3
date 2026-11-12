<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * The one rule that applies to `(field, surface, actor)` after resolution —
 * Part X §X.4.2.
 *
 * `why` is not a debugging aid. "Why can I not edit this field?" is a support
 * ticket in every HR system ever built, and the answer here is a sentence naming
 * which step of the four produced this answer and which role's row won. A
 * resolver that returns only the verdict makes that ticket a code-reading
 * exercise.
 */
final readonly class EffectiveRule
{
    /** @param list<string> $changeWorkflowKeys the UNION across the actor's roles */
    public function __construct(
        public string $fieldKey,
        public Surface $surface,
        public Visibility $visibility,
        public Requiredness $requiredness,
        public string $masking,
        public array $changeWorkflowKeys,
        public string $why,
        public ?string $requiredExpression = null,
    ) {
    }

    /** Any one role requiring approval means approval (X.4.2 step 1). */
    public function requiresApproval(): bool
    {
        return $this->changeWorkflowKeys !== [];
    }

    public function describe(): string
    {
        return "{$this->fieldKey} on {$this->surface->value}: {$this->visibility->value}, "
            . "{$this->requiredness->value}, masked {$this->masking}"
            . ($this->requiresApproval() ? ', routed to ' . implode(' + ', $this->changeWorkflowKeys) : '')
            . " — {$this->why}";
    }
}
