<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * Who a node resolved to, and why (Part IX §IX.5.1, R-IX-028).
 *
 * "Every activation writes the resolved user ids, the spec that produced each,
 * and the substitutions applied into the instance trace." Because *why did this
 * come to me?* is unanswerable in SmartPRS 2 — and *why did this go to nobody?*
 * is the more expensive version of the same question.
 */
final readonly class ResolutionResult
{
    /**
     * @param list<string>          $assignees
     * @param array<string, string> $producedBy   user id => the spec that produced them
     * @param list<string>          $exclusions   one line per user dropped, with the reason
     * @param list<string>          $substitutions delegation and backup, as applied
     */
    public function __construct(
        public string $nodeKey,
        public array $assignees,
        public array $producedBy = [],
        public array $exclusions = [],
        public array $substitutions = [],
        public ?string $noParticipantPolicy = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->assignees === [];
    }

    /** The trace §IX.5.1 asks for, rendered. */
    public function render(): string
    {
        $lines = ["{$this->nodeKey}: " . ($this->assignees === [] ? 'NOBODY' : implode(', ', $this->assignees))];

        foreach ($this->producedBy as $user => $spec) {
            $lines[] = "    {$user} ← {$spec}";
        }

        foreach ($this->substitutions as $substitution) {
            $lines[] = '    ~ ' . $substitution;
        }

        foreach ($this->exclusions as $exclusion) {
            $lines[] = '    ✗ ' . $exclusion;
        }

        if ($this->noParticipantPolicy !== null) {
            $lines[] = '    → on_no_participant: ' . $this->noParticipantPolicy;
        }

        return implode("\n", $lines);
    }
}
