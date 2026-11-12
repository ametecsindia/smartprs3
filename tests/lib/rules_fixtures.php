<?php

declare(strict_types=1);

/**
 * FIXTURE BUILDERS FOR THE RULE ENGINE TESTS.
 *
 * There is no longer a test double for the SPX seam. `tests/lib/rules_spx_double.php`
 * has been deleted: `src/Platform/Spx/` is complete, so every `when`, every
 * `except` and every expression-valued action parameter below is REAL SPX —
 * parsed by `Compiled::parse()`, type-checked against the real decision-point
 * symbol table, and evaluated by `Evaluator::evaluate()`.
 *
 * That means these tests now also test the language and the blueprint's own §12
 * rule library: a rule whose source does not parse or does not type-check fails
 * here rather than being quietly stood in for by a PHP closure.
 *
 * The one remaining stub is `BlankExpression`, and it exists for exactly one
 * assertion — see its docblock.
 */

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\Evaluated;
use SmartPRS\Platform\Rules\Expression;
use SmartPRS\Platform\Rules\Rule;
use SmartPRS\Platform\Rules\SpxExpression;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;

/**
 * Parse real SPX against a decision point's real symbol table.
 *
 * @param bool $boolean false for an action parameter, which may be any declared type
 */
function expr(
    string $source,
    string $decisionPoint,
    bool $boolean = true,
    ?callable $toSpxContext = null,
): SpxExpression {
    return SpxExpression::parse($source, DecisionPoints::symbols($decisionPoint), $boolean, $toSpxContext);
}

/** R-VII-007: "a rule that always applies is written TRUE, explicitly". */
function always(string $decisionPoint): SpxExpression
{
    return expr('TRUE', $decisionPoint);
}

/**
 * The ONLY stub left, and it is not an expression language.
 *
 * `Rule` refuses a `when` whose source is blank (R-VII-007). No SPX expression
 * can ever reach that guard — a blank source is an SpxSyntaxError long before a
 * Rule is constructed — so the guard is unreachable from `SpxExpression` and
 * would go untested. Six lines of stub keep a real invariant covered; the
 * companion assertion checks that SPX refuses the same source first.
 */
final readonly class BlankExpression implements Expression
{
    public function source(): string
    {
        return '  ';
    }

    public function evaluate(EvaluationContext $context, ?Sandbox $sandbox = null): Evaluated
    {
        return new Evaluated(true, 'never evaluated');
    }

    public function claims(): ?array
    {
        return null;
    }
}

/** A rule with sensible defaults, so a fixture states only what it is about. */
function rule(
    string $code,
    string $decisionPoint,
    Expression $when,
    array $then,
    array $scope = [],
    int $priority = 0,
    string $from = '2026-04-01',
    ?string $to = null,
    ?Expression $except = null,
    ConflictPolicy $onConflict = ConflictPolicy::HighestPriorityOnly,
    string $module = 'attendance',
    string $status = 'published',
    int $versionNo = 1,
    string $severity = 'normal',
    bool $stopOnMatch = false,
    ?string $name = null,
    ?string $description = null,
    ?string $sourceRef = null,
): Rule {
    return new Rule(
        code: $code,
        decisionPoint: $decisionPoint,
        module: $module,
        name: $name ?? $code,
        businessDescription: $description ?? "Fixture rule {$code}, encoded from the Part VII worked library.",
        scope: $scope === [] ? Scope::tenantDefault() : Scope::of($scope),
        priority: $priority,
        range: EffectiveRange::fromInclusive($from, $to),
        versionNo: $versionNo,
        versionId: strtolower($code) . '-v' . $versionNo,
        status: $status,
        when: $when,
        then: $then,
        except: $except,
        onConflict: $onConflict,
        stopOnMatch: $stopOnMatch,
        severity: $severity,
        sourceRef: $sourceRef,
    );
}

function ruleAction(string $code, array $parameters = []): Action
{
    return new Action($code, $parameters);
}

function ctx(
    array $values = [],
    array $dimensions = [],
    string $date = '2026-08-12',
    string $employee = 'E-4471',
    ?string $joining = null,
    ?string $exit = null,
): EvaluationContext {
    return new EvaluationContext(
        tenantId: 'T-1',
        subjectType: 'employee_day',
        subjectId: $employee,
        subjectDate: new DateTimeImmutable($date),
        dimensions: $dimensions,
        values: $values,
        joiningDate: $joining === null ? null : new DateTimeImmutable($joining),
        exitDate: $exit === null ? null : new DateTimeImmutable($exit),
    );
}
