<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Actions;

use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\Values;

/**
 * What the engine returns (§6.1). A declarative instruction with its parameters
 * already resolved to values, its provenance, and its fingerprint.
 *
 * The engine constructs these and stops. The calling domain service applies them
 * inside its own transaction, records the fingerprint on the effect row, and
 * treats a repeated fingerprint as a no-op (R-VII-032) — so re-running a
 * derivation converges instead of duplicating, without deleting evidence to get
 * there.
 */
final readonly class AppliedAction
{
    /**
     * @param array<string, mixed>  $parameters resolved values, never expressions
     * @param array<string, string> $emittedBy  rule_code, rule_version_id, checksum
     * @param array<string, string> $subject    type, id, date
     */
    private function __construct(
        public string $actionCode,
        public array $parameters,
        public array $emittedBy,
        public array $subject,
        public string $fingerprint,
        public int $sequence,
        public string $effectClass,
        public string $decisionPoint,
    ) {
    }

    /**
     * §6.3 exactly:
     *
     *   fingerprint = SHA256(
     *         tenant_id ‖ decision_point ‖ subject_type ‖ subject_id ‖ subject_date
     *       ‖ rule_code ‖ rule_version_id
     *       ‖ action_code ‖ canonical_json(resolved_parameters))
     *
     * Parameters are RESOLVED before hashing, so an action whose amount changed
     * produces a different fingerprint and is correctly seen as a different
     * effect rather than a repeat of the old one.
     *
     * @param array<string, mixed> $parameters
     */
    public static function emit(
        string $decisionPoint,
        EvaluationContext $context,
        string $ruleCode,
        string $ruleVersionId,
        string $ruleChecksum,
        string $actionCode,
        array $parameters,
        int $sequence,
    ): self {
        $subject = [
            'type' => $context->subjectType,
            'id' => $context->subjectId,
            'date' => $context->subjectDate->format('Y-m-d'),
        ];

        $fingerprint = hash('sha256', implode('|', [
            $context->tenantId,
            $decisionPoint,
            $subject['type'],
            $subject['id'],
            $subject['date'],
            $ruleCode,
            $ruleVersionId,
            $actionCode,
            Values::json($parameters),
        ]));

        return new self(
            $actionCode,
            Values::canonical($parameters),
            ['rule_code' => $ruleCode, 'rule_version_id' => $ruleVersionId, 'checksum' => $ruleChecksum],
            $subject,
            $fingerprint,
            $sequence,
            ActionCatalogue::effectClass($actionCode),
            $decisionPoint,
        );
    }

    public function isMoneyAffecting(): bool
    {
        return ActionCatalogue::isMoneyAffecting($this->actionCode);
    }

    public function conflictClass(): string
    {
        return ActionCatalogue::conflictClass($this->actionCode);
    }

    public function reversalRule(): string
    {
        return ActionCatalogue::reversalRule($this->actionCode);
    }

    /**
     * The target an action contends for, when its conflict class is not
     * additive. `SET_STATUS` contends for the day's status; `SET_BASIS` for one
     * named component's basis. Two matched rules writing different values to the
     * same target is the §7.3 runtime conflict.
     */
    public function contendedTarget(): string
    {
        $key = match ($this->actionCode) {
            'SET_BASIS', 'CAP' => 'component',
            'GATE_PAYMENT' => 'event',
            default => null,
        };

        return $key === null
            ? $this->actionCode
            : $this->actionCode . ':' . Values::render($this->parameters[$key] ?? '');
    }

    public function render(): string
    {
        $parts = [];

        foreach ($this->parameters as $name => $value) {
            $parts[] = $name . ' = ' . Values::render($value);
        }

        return $this->actionCode . '(' . implode(', ', $parts) . ')';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_code' => $this->actionCode,
            'sequence' => $this->sequence,
            'fingerprint' => $this->fingerprint,
            'parameters' => $this->parameters,
            'emitted_by' => $this->emittedBy,
            'subject' => $this->subject,
            'effect_class' => $this->effectClass,
        ];
    }
}
