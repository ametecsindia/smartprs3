<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * The formula model of Part VIII §4.1.
 *
 * A formula is a `config_artefact` of type `formula` with a `config_versions`
 * row per spine S4 — so it carries a scope, a priority, an effective range, a
 * version number and a status, and resolves through the same
 * `resolve(type, key, scope, as_of)` as every other configurable artefact.
 *
 * Every validation §4.1 marks as blocking runs in the constructor, so a formula
 * that could not be published cannot be constructed either. That is the same
 * stance Rule takes, and for the same reason: an object that exists but is not
 * publishable is a state someone will eventually evaluate.
 *
 * `dependsOn` is NOT a constructor parameter. R-VIII-012: the dependency set is
 * derived by the compiler from the AST, never authored, because a hand-kept list
 * is a second source of truth and a stale one silently breaks both cycle
 * detection and the topological order payroll correctness rests on.
 */
final readonly class Formula
{
    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_-]{2,63}$/';

    /** §4.1. Only `published` is evaluated. */
    public const STATUSES = ['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'];

    public const MODULES = ['payroll', 'leave', 'attendance', 'statutory', 'platform'];

    /**
     * §4.1. `component` produces a payslip or ledger line; `intermediate` is a
     * named sub-expression that is never a line; `rate` produces a Rate.
     */
    public const KINDS = ['component', 'intermediate', 'rate'];

    /** §4.1: what a DEPENDENT formula reads. */
    public const REFERENCE_VALUES = ['rounded', 'exact'];

    public const MIN_DESCRIPTION = 20;

    /** @var list<Dependency> derived from the AST, never authored */
    public array $dependsOn;

    public bool $requiresApproval;

    /**
     * @param list<string>          $declaredSlices
     * @param list<string>          $sampleTests    named sample-employee cases
     * @param array<string, string> $auditMetadata
     */
    public function __construct(
        public string $code,
        public string $calculationPoint,
        public string $module,
        public string $name,
        public string $businessDescription,
        public Compiled $expression,
        public Type $resultType,
        public Rounding $rounding,
        public Scope $scope,
        public EffectiveRange $range,
        public int $versionNo,
        public string $versionId,
        public string $status,
        public string $kind = 'component',
        public string $referenceValue = 'rounded',
        /**
         * R-VIII-030. A bound the engine applies at a NAMED step that appears
         * in the trace. Null means no bound was declared — which is different
         * from a bound that never binds, and the trace says which.
         */
        public ?Clamp $clamp = null,
        public ?string $unit = null,
        public int $priority = 0,
        public int $sequenceNo = 0,
        public array $declaredSlices = [],
        public array $sampleTests = [],
        public string $severity = 'normal',
        ?bool $requiresApproval = null,
        public ?string $sourceRef = null,
        public array $auditMetadata = [],
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(
                "Formula code '{$code}' must match " . self::CODE_PATTERN . ' (§4.1).'
            );
        }

        if (!in_array($module, self::MODULES, true)) {
            throw new \InvalidArgumentException(
                "Formula {$code}: '{$module}' is not a module. One of: " . implode(', ', self::MODULES) . '.'
            );
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Formula {$code}: '{$status}' is not a formula status.");
        }

        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "Formula {$code}: '{$kind}' is not a formula kind. One of: " . implode(', ', self::KINDS) . '.'
            );
        }

        if (!in_array($referenceValue, self::REFERENCE_VALUES, true)) {
            throw new \InvalidArgumentException("Formula {$code}: reference_value is 'rounded' or 'exact' (§4.1).");
        }

        if (!in_array($severity, ['normal', 'high_risk'], true)) {
            throw new \InvalidArgumentException("Formula {$code}: severity is 'normal' or 'high_risk'.");
        }

        // §4.1: printed in the trace, the impact preview and the generated
        // calculation guide (R-VIII-062). A blank one makes all three useless.
        if (mb_strlen(trim($businessDescription)) < self::MIN_DESCRIPTION) {
            throw new \InvalidArgumentException(
                "Formula {$code}: business_description is mandatory and must be at least "
                . self::MIN_DESCRIPTION . ' characters. It is printed in every trace.'
            );
        }

        if (trim($expression->source) === '') {
            throw new \InvalidArgumentException("Formula {$code}: expression_src may not be empty (R-VIII-011).");
        }

        // R-VIII-011: a component-kind formula may not be a bare literal. A
        // constant amount is a `fixed` component on the structure line, so that
        // "this is a fixed ₹1,600" is visible in the structure rather than
        // buried in an expression nobody opens.
        if ($kind === 'component' && self::isBareLiteral($expression)) {
            throw new \InvalidArgumentException(
                "Formula {$code}: a component formula may not be a bare literal (R-VIII-011). "
                . 'A constant amount is a fixed component on the structure line, where it is visible.'
            );
        }

        // R-VIII-025 / §9.9: `none` is permitted only on an intermediate.
        if ($rounding->isNone() && $kind === 'component') {
            throw new \InvalidArgumentException(
                "Formula {$code}: rounding_mode 'none' is not permitted on a component formula (R-VIII-025). "
                . 'Every component declares its own mode and unit; there is no default rounding anywhere.'
            );
        }

        // §4.1: `exact` requires an intermediate. A component's published number
        // IS the number, so a dependant reading its pre-rounding rational would
        // be reading something no payslip ever showed.
        if ($referenceValue === 'exact' && $kind !== 'intermediate') {
            throw new \InvalidArgumentException(
                "Formula {$code}: reference_value 'exact' requires formula_kind 'intermediate' (§4.1)."
            );
        }

        // R-VIII-027: rounding a Rate is forbidden by default — a rate is an
        // intermediate and the money it produces is what rounds.
        if ($kind === 'rate' && !$rounding->isNone()) {
            throw new \InvalidArgumentException(
                "Formula {$code}: a rate does not declare rounding (R-VIII-027). The money it produces rounds; "
                . 'a published rate needs rate_is_published, which this version does not carry.'
            );
        }

        // §4.1: mandatory when the result is Money, a duration or a Rate.
        if ($unit === null && self::needsUnit($resultType)) {
            throw new \InvalidArgumentException(
                "Formula {$code}: unit is mandatory for result_type {$resultType} (§4.1) — "
                . 'a currency for Money, or the day-length policy code that gives a duration meaning.'
            );
        }

        // R-VIII-047: publication is refused with zero sample cases. Held here
        // rather than at publish because a formula with no cases has nothing to
        // re-run when a dependency changes (R-VIII-039).
        if ($status === 'published' && count($sampleTests) < 3) {
            throw new \InvalidArgumentException(
                "Formula {$code}: publication needs at least 3 named sample cases, got "
                . count($sampleTests) . ' (R-VIII-047).'
            );
        }

        $this->dependsOn = Dependencies::of($expression);

        // §4.1: forced high_risk when the formula feeds a statutory head, a
        // net-pay line or an employer contribution. Detectable from the derived
        // set, so it is not left to the author to remember.
        $forced = $severity === 'high_risk' || $this->readsStatutory();

        if ($requiresApproval === false && $forced) {
            throw new \InvalidArgumentException(
                "Formula {$code}: requires_approval cannot be false — it is "
                . ($severity === 'high_risk' ? 'high_risk' : 'statutory-feeding') . '.'
            );
        }

        $this->requiresApproval = $forced || ($requiresApproval ?? false);
    }

    /**
     * §4.1 `checksum`: SHA-256 over the canonical AST, the ROUNDING
     * DECLARATION, the scope, the dates and the DERIVED dependency set.
     *
     * Rounding is in the hash because it is part of what the formula computes,
     * not decoration on it: the same expression rounded half_up and half_down
     * are two different numbers, and a checksum that could not tell them apart
     * would let one be swapped for the other with the cache none the wiser.
     *
     * The dependency set is in it for the same reason — a formula whose
     * dependencies resolved differently produced a different answer, even with
     * identical source.
     */
    public function checksum(): string
    {
        return hash('sha256', json_encode([
            'code' => $this->code,
            'calculation_point' => $this->calculationPoint,
            'expression' => $this->expression->source,
            'ast' => $this->expression->toArray(),
            'result_type' => (string) $this->resultType,
            'unit' => $this->unit,
            'rounding' => [
                'mode' => $this->rounding->declaredMode,
                'unit' => $this->rounding->declaredUnit,
                'point' => $this->rounding->point,
            ],
            'reference_value' => $this->referenceValue,
            'kind' => $this->kind,
            'scope' => $this->scope->toJson(),
            'priority' => $this->priority,
            'effective_from' => $this->range->from->format('Y-m-d'),
            'effective_to' => $this->range->effectiveTo()?->format('Y-m-d'),
            'version_no' => $this->versionNo,
            'depends_on' => array_map(
                static fn (Dependency $d): string => $d->node(),
                $this->dependsOn,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** The graph node identity: `formula:F-HRA-PCT-BASIC`. */
    public function node(): string
    {
        return 'formula:' . $this->code;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isInForce(\DateTimeImmutable $asOf): bool
    {
        return $this->range->contains($asOf);
    }

    /** Spine S3.2 step 1, from Support\Scope. The weights live there, once. */
    public function specificity(): int
    {
        return $this->scope->specificity();
    }

    public function readsStatutory(): bool
    {
        foreach ($this->dependsOn as $dependency) {
            if ($dependency->kind === 'statutory') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the codes of the given dependency kind */
    public function dependencyCodes(string $kind): array
    {
        $codes = [];

        foreach ($this->dependsOn as $dependency) {
            if ($dependency->kind === $kind) {
                $codes[] = $dependency->code;
            }
        }

        return $codes;
    }

    /**
     * §8.3: the slices the AST actually reads. The compiler derives the true set
     * and rejects a declared set that does not contain it (gate G8) — a declared
     * slice list that has drifted from the expression is an assembly that either
     * costs too much or is missing what the formula needs at evaluation.
     *
     * @return list<string>
     */
    public function readSlices(): array
    {
        $slices = [];

        foreach ($this->dependsOn as $dependency) {
            $slices[] = match ($dependency->kind) {
                'settlement' => 'settlement',
                'ledger' => 'ledger',
                'policy' => 'policy',
                'input' => 'variable.inputs',
                'statutory' => 'statutory.pack',
                default => 'structure',
            };
        }

        $slices = array_values(array_unique($slices));
        sort($slices);

        return $slices;
    }

    public function label(): string
    {
        return $this->code . ' v' . $this->versionNo;
    }

    /** "F-HRA-PCT-BASIC v3 · comp.earning · Money(INR), half_up to 0.01" */
    public function describe(): string
    {
        return $this->label()
            . ' · ' . $this->calculationPoint
            . ' · ' . $this->resultType . ($this->unit === null ? '' : "({$this->unit})")
            . ', ' . $this->rounding->describe();
    }

    private static function needsUnit(Type $type): bool
    {
        return (bool) preg_match('/^(Money|Days|Hours|Minutes|Rate)/', (string) $type);
    }

    /**
     * A constant: an expression that reads nothing.
     *
     * Not just a single literal node — `MONEY(1600, 'INR')` is a function call
     * and is exactly the case R-VIII-011 is about. What makes it a constant is
     * that it names no symbol and no aggregate, so it produces the same number
     * for every employee in every period.
     */
    private static function isBareLiteral(Compiled $expression): bool
    {
        return !self::readsAnything($expression->ast);
    }

    private static function readsAnything(\SmartPRS\Platform\Spx\Ast\Node $node): bool
    {
        if ($node instanceof \SmartPRS\Platform\Spx\Ast\VariableRef
            || $node instanceof \SmartPRS\Platform\Spx\Ast\AggCall
        ) {
            return true;
        }

        foreach ($node->children() as $child) {
            if (self::readsAnything($child)) {
                return true;
            }
        }

        return false;
    }
}
