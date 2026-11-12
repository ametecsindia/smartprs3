<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

/**
 * §9.2 `CandidateTrace`: one rule's showing, whether it won, lost, or was never
 * reached.
 *
 * The `reason` is the point of the whole structure. Support asked for "why did
 * this NOT happen?" and §11.3 records it as the majority of the support load on
 * any rules product. Five ways to lose, each named:
 *
 *   not_in_scope           the rule does not govern this employee
 *   not_in_force           the rule was not effective on this date
 *   not_matched            the rule governs them and its condition was false
 *   not_evaluated_outranked lost on specificity, or on priority within one
 *                           specificity — the losing arithmetic is in the detail
 *   not_evaluated_stopped   an earlier rule declared stop_on_match
 *
 * plus `matched`, `matched_then_excepted` (§7.5 — "the rule applied to you and
 * then let you off", which is a different sentence from "it did not apply") and
 * `error`.
 *
 * ponytail: §9.2's NodeTrace is a tree of one entry per evaluated AST node.
 * Nodes belong to SPX, which owns the AST; this carries the substituted line the
 * expression produced and its input leaves. When SPX publishes a node tree,
 * `whenSubstituted` becomes its rendering and nothing else here changes.
 */
final readonly class CandidateTrace
{
    public const MATCHED = 'matched';
    public const MATCHED_THEN_EXCEPTED = 'matched_then_excepted';
    public const NOT_MATCHED = 'not_matched';
    public const NOT_IN_SCOPE = 'not_in_scope';
    public const NOT_IN_FORCE = 'not_in_force';
    public const NOT_PUBLISHED = 'not_published';
    public const NOT_EVALUATED_OUTRANKED = 'not_evaluated_outranked';
    public const NOT_EVALUATED_STOPPED = 'not_evaluated_stopped';
    public const ERROR = 'error';

    /** @param array<string, mixed> $whenInputs */
    public function __construct(
        public string $ruleCode,
        public string $ruleVersionId,
        public int $versionNo,
        public string $checksum,
        public string $name,
        public string $businessDescription,
        public string $scopeSummary,
        public int $specificity,
        public int $priority,
        public int $orderIndex,
        public string $outcome,
        public string $reason,
        public ?string $whenSource = null,
        public ?string $whenSubstituted = null,
        public array $whenInputs = [],
        public ?string $exceptSource = null,
        public ?string $exceptSubstituted = null,
        public ?string $sourceRef = null,
        public ?string $effectiveRange = null,
    ) {
    }

    public function matched(): bool
    {
        return $this->outcome === self::MATCHED;
    }

    public function lost(): bool
    {
        return !$this->matched();
    }

    public function render(): string
    {
        $lines = [];

        if ($this->matched() || $this->outcome === self::MATCHED_THEN_EXCEPTED || $this->outcome === self::ERROR) {
            $lines[] = sprintf(
                '  RULE  %s  v%d   "%s"',
                $this->ruleCode,
                $this->versionNo,
                $this->name,
            );
            $lines[] = sprintf(
                '        effective %s   ·   scope: %s   ·   spec %d, priority %d',
                $this->effectiveRange ?? '',
                $this->scopeSummary,
                $this->specificity,
                $this->priority,
            );
            $lines[] = '        "' . $this->businessDescription . '"';

            if ($this->sourceRef !== null) {
                $lines[] = '        replaces: ' . $this->sourceRef;
            }

            $lines[] = '';
            $lines[] = '        WHEN   ' . ($this->whenSubstituted ?? $this->whenSource ?? '');

            if ($this->exceptSubstituted !== null) {
                $lines[] = '        EXCEPT ' . $this->exceptSubstituted;
            }

            $lines[] = '        → ' . strtoupper(str_replace('_', ' ', $this->outcome));

            return implode("\n", $lines);
        }

        return sprintf(
            '  RULE  %s  v%d — %s (%s)',
            $this->ruleCode,
            $this->versionNo,
            str_replace('_', ' ', $this->outcome),
            $this->reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'rule_code' => $this->ruleCode,
            'rule_version_id' => $this->ruleVersionId,
            'version_no' => $this->versionNo,
            'checksum' => $this->checksum,
            'name' => $this->name,
            'business_description' => $this->businessDescription,
            'source_ref' => $this->sourceRef,
            'scope_summary' => $this->scopeSummary,
            'effective' => $this->effectiveRange,
            'specificity' => $this->specificity,
            'priority' => $this->priority,
            'order_index' => $this->orderIndex,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'when' => array_filter([
                'source' => $this->whenSource,
                'substituted' => $this->whenSubstituted,
                'inputs' => Values::canonical($this->whenInputs),
            ], static fn (mixed $v): bool => $v !== null && $v !== []),
            'except' => array_filter([
                'source' => $this->exceptSource,
                'substituted' => $this->exceptSubstituted,
            ], static fn (mixed $v): bool => $v !== null),
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
