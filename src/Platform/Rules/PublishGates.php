<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;
use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\Actions\ActionCatalogue;
use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Support\AmbiguousConfiguration;

/**
 * THE PUBLISH GATES FOR A RULE — Part VII §8.3, §11.4 and §15.13.
 *
 * §15.13 gives the storage (`check_code`, `severity`, `message`) but no numbered
 * list, because Part VII scatters its publish-time obligations across §4.1,
 * §7.7, §11.4 and R-VII-005/007/025/040/060/061/062. This is that list,
 * collected and named R01..R09 so an outcome can be stored per gate the way
 * Part VIII stores G1..G12 and Part IX stores W01..W18.
 *
 * Two rules govern the whole file, and they are the ones this codebase has
 * already been bitten by:
 *
 *   1. EVERY GATE RUNS. A block does not stop the ones after it. An author who
 *      fixes one error and immediately meets the next is §2's account of why
 *      nobody configures SmartPRS 2.
 *   2. A GATE THAT CANNOT CHECK RECORDS `NOT CHECKED`, NEVER `pass`. Four
 *      enforcement gates in tools/gates/ were green over zero files for weeks.
 *      Green over nothing is worse than missing, because it is evidence.
 *
 * What is NOT here, deliberately: everything `Rule`'s constructor already
 * refuses — code shape, module, status, severity, blank `when`, description
 * length, the 1..20 action count, stop_on_match's policy, first_match on
 * high_risk, and requires_approval's forcing. Those are block-level checks per
 * §8.3, and re-asserting them here would give two answers to one question. A
 * Rule that could not be published cannot be constructed.
 *
 * Nor is effective-range overlap here (§8.2 case 8). Config\Publisher already
 * asserts it, AFTER the write and against the real rows, so it sees the
 * auto-closing of the previous version that a gate reading a caller-supplied
 * list cannot. A gate that duplicated it would refuse the ordinary case of
 * superseding an open-ended version — a check that is wrong is worse than one
 * that is absent, and this one would have been both.
 */
final class PublishGates
{
    /** §7.7, statically checkable and so enforced at publish. */
    public const MAX_SOURCE_CHARS = 8000;
    public const MAX_NODES = 2000;
    public const MAX_AGG_CALLS = 4;

    /**
     * The type checker refuses anything past Sandbox::MAX_DEPTH, so the budget
     * is taken from there rather than restated — a budget above the parser's
     * own limit is a gate that cannot fire.
     */
    public const MAX_DEPTH = Sandbox::MAX_DEPTH;

    public static function run(Rule $rule, ?GateEnvironment $environment = null): PublishReport
    {
        $environment ??= new GateEnvironment();

        return new PublishReport($rule->describe(), [
            self::r01Budget($rule),
            self::r02DecisionPoint($rule),
            self::r03Expressions($rule),
            self::r04Actions($rule),
            self::r05Aggregations($rule),
            self::r06Reachable($rule),
            self::r07Conflicts($rule, $environment),
            self::r08Regression($rule, $environment),
            self::r09Approval($rule, $environment),
        ]);
    }

    // -----------------------------------------------------------------------
    // R01 — Static budgets (§7.7, R-VII-040)
    // -----------------------------------------------------------------------

    private static function r01Budget(Rule $rule): PublishCheck
    {
        $name = 'Static budgets';
        $issues = [];

        foreach (self::clauses($rule) as $label => $expression) {
            $length = mb_strlen($expression->source());

            if ($length > self::MAX_SOURCE_CHARS) {
                $issues[] = "`{$label}` is {$length} characters, over the budget of " . self::MAX_SOURCE_CHARS . '.';
            }

            $compiled = self::compiled($expression);

            if ($compiled === null) {
                continue;
            }

            if ($compiled->nodeCount() > self::MAX_NODES) {
                $issues[] = "`{$label}` has {$compiled->nodeCount()} AST nodes, over the budget of "
                    . self::MAX_NODES . '.';
            }

            if ($compiled->depth() > self::MAX_DEPTH) {
                $issues[] = "`{$label}` nests {$compiled->depth()} deep, over the budget of " . self::MAX_DEPTH . '.';
            }
        }

        return $issues === [] ? PublishCheck::pass('R01', $name) : PublishCheck::block('R01', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // R02 — The decision point exists and fixes the symbol table (R-VII-004/006)
    // -----------------------------------------------------------------------

    private static function r02DecisionPoint(Rule $rule): PublishCheck
    {
        $name = 'Decision point';

        if (!DecisionPoints::knows($rule->decisionPoint)) {
            return PublishCheck::block('R02', $name, [
                "'{$rule->decisionPoint}' is not a declared decision point. The catalogue is closed "
                . '(§3.2, R-VII-005): ' . implode(', ', DecisionPoints::all()),
            ]);
        }

        // §4.1: a rule may narrow its decision point's conflict policy but never
        // widen it. The engine takes the narrower of the two at runtime, so a
        // widening rule would not do what its author wrote — which is a worse
        // outcome than a refusal.
        $declared = DecisionPoints::conflictPolicy($rule->decisionPoint);

        if (!$rule->onConflict->narrows($declared)) {
            return PublishCheck::block('R02', $name, [
                "on_conflict `{$rule->onConflict->value}` WIDENS {$rule->decisionPoint}, which declares "
                . "`{$declared->value}` (§4.1). A rule may narrow the decision point's policy, never widen it.",
            ]);
        }

        return PublishCheck::pass('R02', $name);
    }

    // -----------------------------------------------------------------------
    // R03 — Expressions parse, type-check and read only declared symbols
    // -----------------------------------------------------------------------

    private static function r03Expressions(Rule $rule): PublishCheck
    {
        $name = 'Expressions';
        $issues = [];

        foreach (self::clauses($rule) as $label => $expression) {
            $compiled = self::compiled($expression);

            if ($compiled === null) {
                $issues[] = "`{$label}` is not an SPX expression, so it was not compiled or type-checked. "
                    . 'Only SpxExpression may be published (R-VII-009).';

                continue;
            }

            $check = $compiled->typeCheck();

            if (!$check->isBoolean()) {
                $issues[] = "`{$label}` must type-check to Boolean, not " . $check . '.';
            }
        }

        // An action parameter may be any declared type, so it is checked for
        // validity rather than for Boolean.
        foreach ($rule->then as $index => $action) {
            foreach ($action->parameters as $parameter => $value) {
                if (!$value instanceof SpxExpression) {
                    continue;
                }

                $check = $value->compiled()->typeCheck();

                if (!$check->isValid()) {
                    $issues[] = "action " . ($index + 1) . " {$action->code}.{$parameter}: " . $check;
                }
            }
        }

        return $issues === [] ? PublishCheck::pass('R03', $name) : PublishCheck::block('R03', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // R04 — Every action is emittable HERE (R-VII-005)
    // -----------------------------------------------------------------------

    private static function r04Actions(Rule $rule): PublishCheck
    {
        $name = 'Action allow-list';

        if (!DecisionPoints::knows($rule->decisionPoint)) {
            return PublishCheck::couldNotCheck('R04', $name,
                "'{$rule->decisionPoint}' is not a declared decision point, so it has no allow-list to check against");
        }

        $allowed = DecisionPoints::actions($rule->decisionPoint);
        $issues = [];

        foreach ($rule->then as $action) {
            if (!ActionCatalogue::knows($action->code)) {
                $issues[] = "{$action->code} is not in the action catalogue.";

                continue;
            }

            if (!in_array($action->code, $allowed, true)) {
                $issues[] = "{$action->code} may not be emitted at {$rule->decisionPoint}. That point declares: "
                    . implode(', ', $allowed) . ' (§3.2, R-VII-005).';
            }
        }

        return $issues === [] ? PublishCheck::pass('R04', $name) : PublishCheck::block('R04', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // R05 — Aggregations (R-VII-025, §5.9)
    // -----------------------------------------------------------------------

    private static function r05Aggregations(Rule $rule): PublishCheck
    {
        $name = 'Aggregations';
        $issues = [];
        $prepared = [];

        foreach (self::clauses($rule) as $label => $expression) {
            $compiled = self::compiled($expression);

            if ($compiled === null) {
                continue;
            }

            $aggregations = self::nodesOf($compiled->ast, AggCall::class);

            if (count($aggregations) > self::MAX_AGG_CALLS) {
                $issues[] = "`{$label}` makes " . count($aggregations) . ' aggregation calls, over the budget of '
                    . self::MAX_AGG_CALLS . ' (§7.7).';
            }

            // R-VII-025: an aggregate over an aggregate is a publish-time error.
            // The inner one has no window it could be evaluated in.
            foreach ($aggregations as $aggregation) {
                foreach ($aggregation->children() as $child) {
                    if (self::nodesOf($child, AggCall::class) !== []) {
                        $issues[] = "`{$label}` nests an aggregation inside another (R-VII-025).";

                        break 2;
                    }
                }
            }

            foreach ($compiled->preparedKeys() as $key) {
                $prepared[$key] = true;
            }
        }

        if ($issues !== []) {
            return PublishCheck::block('R05', $name, $issues);
        }

        if ($prepared === []) {
            return PublishCheck::pass('R05', $name);
        }

        // Not a defect — a contract. §5.9: the caller resolves these before the
        // engine is entered, and an unprepared key is a named ERR_AGG_UNAVAILABLE
        // rather than a silent zero. Recording them here is how the domain
        // service that calls this decision point learns what it now owes.
        return PublishCheck::lint('R05', $name,
            'the caller must prepare: ' . implode(', ', array_keys($prepared)));
    }

    // -----------------------------------------------------------------------
    // R06 — The rule can actually fire (§11.4's solver, used on one rule)
    // -----------------------------------------------------------------------

    private static function r06Reachable(Rule $rule): PublishCheck
    {
        $name = 'Reachability';
        $claims = $rule->when->claims();

        if ($claims === null) {
            return PublishCheck::couldNotCheck('R06', $name,
                '`when` is outside the solver\'s decidable fragment, so whether it can ever be true is '
                . 'not decided here (R-VII-060 takes the fail-safe direction at R07 instead)');
        }

        if (Satisfiability::witness($claims) === null) {
            return PublishCheck::block('R06', $name, [
                '`when` can never be true for any subject: ' . $rule->when->source()
                . '. A rule that cannot fire is not a decision, and it will sit in the trace of every '
                . 'evaluation at this decision point saying nothing.',
            ]);
        }

        // An `except` that provably covers every case `when` admits is the same
        // defect wearing a second clause.
        $exceptClaims = $rule->except?->claims();

        if ($exceptClaims !== null && Satisfiability::witness(array_merge($claims, $exceptClaims)) !== null) {
            // The two can both hold, which is the normal case — `except` carves
            // out part of `when`, not all of it.
            return PublishCheck::pass('R06', $name);
        }

        if ($exceptClaims !== null) {
            // $exceptClaims came from $rule->except?->claims() above: it is non-null
            // here only because $rule->except itself was non-null, so this is a plain
            // property/method access, not a second null check.
            return PublishCheck::lint('R06', $name,
                '`except` is provably disjoint from `when`, so it never suppresses anything: '
                . $rule->except->source());
        }

        return PublishCheck::pass('R06', $name);
    }

    // -----------------------------------------------------------------------
    // R07 — Conflicts with siblings (§11.4, R-VII-060)
    // -----------------------------------------------------------------------

    private static function r07Conflicts(Rule $rule, GateEnvironment $environment): PublishCheck
    {
        $name = 'Conflicts';

        if ($environment->siblings === null) {
            return PublishCheck::couldNotCheck('R07', $name,
                'no sibling rule set was supplied, so ties at this decision point were not tested');
        }

        try {
            (new ConflictDetector())->assertPublishable([...$environment->siblings, $rule]);
        } catch (AmbiguousConfiguration $e) {
            return PublishCheck::block('R07', $name, [$e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return PublishCheck::block('R07', $name, [$e->getMessage()]);
        }

        return PublishCheck::pass('R07', $name);
    }

    // -----------------------------------------------------------------------
    // R08 — The stored regression cases still pass (§11.6, R-VII-062)
    // -----------------------------------------------------------------------

    private static function r08Regression(Rule $rule, GateEnvironment $environment): PublishCheck
    {
        $name = 'Regression cases';

        if ($environment->tests === null) {
            return PublishCheck::couldNotCheck('R08', $name,
                'no stored test cases were supplied, so nothing was re-run');
        }

        $mine = array_values(array_filter(
            $environment->tests,
            static fn (RuleTestCase $case): bool => $case->ruleCode === $rule->code,
        ));

        if ($mine === []) {
            // R-VII-062 is about SEEDED rules; a tenant's own rule is not
            // refused for having no cases, but it is not called green either.
            return PublishCheck::warn('R08', $name, [
                'this rule has no stored test cases. R-VII-062 requires three on every seeded rule — '
                . 'one matching, one not matching, one boundary.',
            ]);
        }

        // §11.6: publishing runs every case at the affected decision point, not
        // only this rule's, because a new rule changes precedence for its
        // siblings. The set below is therefore siblings + candidate.
        $set = RuleSet::of($rule->decisionPoint, ...[...($environment->siblings ?? []), $rule]);
        $failures = [];

        foreach ($mine as $case) {
            $outcome = $case->run($rule->decisionPoint, $set);

            if (!$outcome['passed']) {
                $failures[] = $case->name . ': ' . $outcome['message'];
            }
        }

        return $failures === []
            ? PublishCheck::pass('R08', $name)
            : PublishCheck::block('R08', $name, $failures);
    }

    // -----------------------------------------------------------------------
    // R09 — Maker-checker (§4.1, spine S8, and the self-approval prohibition)
    // -----------------------------------------------------------------------

    private static function r09Approval(Rule $rule, GateEnvironment $environment): PublishCheck
    {
        $name = 'Approval';

        if (!$rule->requiresApproval) {
            return PublishCheck::pass('R09', $name);
        }

        $why = $rule->severity === 'high_risk' ? 'high_risk' : 'money-affecting';

        if ($environment->authorUserId === null || $environment->approverUserId === null) {
            return PublishCheck::couldNotCheck('R09', $name,
                "this rule is {$why} and so needs a second approver, but the author and approver were not "
                . 'supplied, so the self-approval prohibition was not tested');
        }

        if ($environment->authorUserId === $environment->approverUserId) {
            return PublishCheck::block('R09', $name, [
                "user {$environment->authorUserId} cannot approve their own {$why} rule (spine S8). "
                . 'Maker-checker on configuration is the same mechanism as maker-checker on payroll.',
            ]);
        }

        return PublishCheck::pass('R09', $name);
    }

    // -----------------------------------------------------------------------

    /** @return array<string, Expression> label => clause */
    private static function clauses(Rule $rule): array
    {
        return $rule->except === null
            ? ['when' => $rule->when]
            : ['when' => $rule->when, 'except' => $rule->except];
    }

    /** The compiled AST, or null for an Expression that is not SPX. */
    private static function compiled(Expression $expression): ?\SmartPRS\Platform\Spx\Compiled
    {
        return $expression instanceof SpxExpression ? $expression->compiled() : null;
    }

    /**
     * @param  class-string  $class
     * @return list<Node>
     */
    private static function nodesOf(Node $node, string $class): array
    {
        $found = $node instanceof $class ? [$node] : [];

        foreach ($node->children() as $child) {
            $found = [...$found, ...self::nodesOf($child, $class)];
        }

        return $found;
    }
}
