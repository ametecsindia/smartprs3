<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * The rule model of spine S6 and Part VII §4.1.
 *
 * A rule is a `config_artefact` of type `rule` with a `config_versions` row per
 * spine S4 — so it carries a scope, a priority, an effective range, a version
 * number and a status, exactly like every other configurable artefact, and is
 * resolved by the same `resolve(type, key, scope, as_of)`.
 *
 * R-VII-008: a published version is immutable in every field. This class is
 * readonly, and the validations that §8.3 marks "Block" run in the constructor,
 * so a rule that could not be published cannot be constructed either.
 */
final readonly class Rule
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9-]{2,63}$/';

    /** §4.1. Only `published` is evaluated. */
    public const STATUSES = ['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'];

    public const MODULES = ['attendance', 'leave', 'payroll', 'compliance', 'platform'];

    /** §7.7: actions per rule. Statically checkable, so enforced at publish. */
    public const MAX_ACTIONS = 20;

    public const MIN_DESCRIPTION = 20;

    public bool $requiresApproval;

    /** @var list<Action> ordered, 1..20 */
    public array $then;

    /**
     * @param list<mixed>           $then          ordered, 1..20; declared wide because this
     *        constructor is the boundary a caller hands actions to, and the loop below is
     *        what actually proves each one is an Action before it is stored
     * @param list<string>          $tags
     * @param array<string, string> $auditMetadata created_by, approved_by, reason, change_note …
     */
    public function __construct(
        public string $code,
        public string $decisionPoint,
        public string $module,
        public string $name,
        public string $businessDescription,
        public Scope $scope,
        public int $priority,
        public EffectiveRange $range,
        public int $versionNo,
        public string $versionId,
        public string $status,
        public Expression $when,
        array $then,
        public ?Expression $except = null,
        public ConflictPolicy $onConflict = ConflictPolicy::HighestPriorityOnly,
        public bool $stopOnMatch = false,
        public string $severity = 'normal',
        ?bool $requiresApproval = null,
        public array $tags = [],
        public ?string $sourceRef = null,
        public array $auditMetadata = [],
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(
                "Rule code '{$code}' must match " . self::CODE_PATTERN . ' (§4.1).'
            );
        }

        if (!in_array($module, self::MODULES, true)) {
            throw new \InvalidArgumentException(
                "Rule {$code}: '{$module}' is not a module. One of: " . implode(', ', self::MODULES) . '.'
            );
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Rule {$code}: '{$status}' is not a rule status.");
        }

        if (!in_array($severity, ['normal', 'high_risk'], true)) {
            throw new \InvalidArgumentException("Rule {$code}: severity is 'normal' or 'high_risk'.");
        }

        // R-VII-007 in spirit: a rule that always applies is written TRUE,
        // explicitly. An empty source is never an authored decision.
        if (trim($when->source()) === '') {
            throw new \InvalidArgumentException(
                "Rule {$code}: `when` may not be empty. A rule with no condition is written TRUE (R-VII-007)."
            );
        }

        // §4.1: business_description is mandatory, no blank — because SmartPRS 2's
        // `l1_cut` fall-through is a business rule nobody can find or explain.
        if (mb_strlen(trim($businessDescription)) < self::MIN_DESCRIPTION) {
            throw new \InvalidArgumentException(
                "Rule {$code}: business_description is mandatory and must be at least "
                . self::MIN_DESCRIPTION . ' characters. It is printed in every trace.'
            );
        }

        if ($then === [] || count($then) > self::MAX_ACTIONS) {
            throw new \InvalidArgumentException(
                "Rule {$code}: `then` holds 1 to " . self::MAX_ACTIONS . ' actions (§7.7), got ' . count($then) . '.'
            );
        }

        foreach ($then as $action) {
            if (!$action instanceof Action) {
                throw new \InvalidArgumentException("Rule {$code}: `then` holds Action values only.");
            }
        }

        /** @var list<Action> $then */
        $this->then = $then;

        // §4.1: stop_on_match is only permitted when on_conflict = all_match.
        // Under the single-outcome policies evaluation already stops.
        if ($stopOnMatch && $onConflict !== ConflictPolicy::AllMatch) {
            throw new \InvalidArgumentException(
                "Rule {$code}: stop_on_match is only permitted with all_match (§4.1)."
            );
        }

        // §8.3: first_match on a high_risk rule blocks — use highest_priority_only
        // so the trace shows the alternatives.
        if ($severity === 'high_risk' && $onConflict === ConflictPolicy::FirstMatch) {
            throw new \InvalidArgumentException(
                "Rule {$code}: first_match is not permitted on a high_risk rule. "
                . 'Use highest_priority_only so the trace shows the alternatives (§8.3).'
            );
        }

        // §4.1: forced true when severity is high_risk or the rule emits a
        // money-affecting action. Maker-checker on configuration is the same
        // mechanism as maker-checker on payroll (spine S4, S8).
        $forced = $severity === 'high_risk' || self::anyMoneyAffecting($this->then);

        if ($requiresApproval === false && $forced) {
            throw new \InvalidArgumentException(
                "Rule {$code}: requires_approval cannot be false — it is "
                . ($severity === 'high_risk' ? 'high_risk' : 'money-affecting') . '.'
            );
        }

        $this->requiresApproval = $forced || ($requiresApproval ?? false);
    }

    /** Spine S3.2 step 1, from Support\Scope. The weights live there, once. */
    public function specificity(): int
    {
        return $this->scope->specificity();
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isInForce(\DateTimeImmutable $asOf): bool
    {
        return $this->range->contains($asOf);
    }

    public function isMoneyAffecting(): bool
    {
        return self::anyMoneyAffecting($this->then);
    }

    /**
     * PHP 8.4's array_any() would say this in one line, but the platform targets
     * PHP >= 8.3: on-premise customers run whatever their host ships, and Part
     * XXVI is explicit that a lower prerequisite bar means more installs that
     * work. `tools/gates/checks/min-php-version.php` keeps that promise honest.
     *
     * @param list<Action> $actions
     */
    private static function anyMoneyAffecting(array $actions): bool
    {
        foreach ($actions as $action) {
            if ($action->isMoneyAffecting()) {
                return true;
            }
        }

        return false;
    }

    /**
     * §4.1 `checksum`: SHA-256 over the canonical AST + actions + scope + dates.
     * Tamper evidence, and the cache key. Pinned into every action's provenance.
     */
    public function checksum(): string
    {
        return Values::digest([
            'code' => $this->code,
            'decision_point' => $this->decisionPoint,
            'when' => $this->when->source(),
            'except' => $this->except?->source(),
            'then' => array_map(static fn (Action $a): string => $a->sourceForm(), $this->then),
            'scope' => $this->scope->toJson(),
            'priority' => $this->priority,
            'effective_from' => $this->range->from->format('Y-m-d'),
            'effective_to' => $this->range->effectiveTo()?->format('Y-m-d'),
            'version_no' => $this->versionNo,
            'on_conflict' => $this->onConflict->value,
            'catalogue_version' => ActionCatalogue::VERSION,
        ]);
    }

    /** "branch = HYD, grade = G3" — the trace's scope_summary (§9.2). */
    public function scopeSummary(): string
    {
        if ($this->scope->isTenantDefault()) {
            return 'tenant default';
        }

        $parts = [];

        foreach ($this->scope->predicates as $dimension => $values) {
            $parts[] = $dimension . ' = ' . implode('|', $values);
        }

        return implode(', ', $parts);
    }

    public function label(): string
    {
        return $this->code . ' v' . $this->versionNo;
    }

    /** "LATE-HYD v4 · spec 180, priority 0 · branch = HYD · 2026-04-01 → " */
    public function describe(): string
    {
        return $this->label()
            . ' · spec ' . $this->specificity() . ', priority ' . $this->priority
            . ' · ' . $this->scopeSummary()
            . ' · ' . $this->range;
    }
}
