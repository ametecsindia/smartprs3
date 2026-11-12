<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;
use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Binary;
use SmartPRS\Platform\Spx\Ast\FunctionCall;
use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Functions\Catalogue;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Types\TypeKind;

/**
 * THE TWELVE PUBLISH GATES — Part VIII §9.
 *
 * R-VIII-013: all twelve run on every publication, in order, and the outcome of
 * each is recorded. Not "until one fails" — an author who fixes one error and
 * immediately meets the next is the experience Part VIII §2 catalogues as the
 * reason nobody configures SmartPRS 2.
 *
 * A gate that cannot check what it exists to check records NOT CHECKED and a
 * warn, never a pass. That rule is here because this repository has already been
 * bitten by its absence once: four enforcement gates in tools/gates/ were green
 * over zero files for weeks, and green-over-nothing is worse than missing,
 * because it is evidence.
 */
final class PublishGates
{
    /** §9.1, all block. */
    public const MAX_SOURCE_CHARS = 8000;
    public const MAX_NODES = 2000;

    /**
     * §9.1 says 40. Part VII's type checker refuses anything past
     * Sandbox::MAX_DEPTH — 32 today — so an expression can never reach 40 and a
     * budget of 40 here would be a check that cannot fire, which is the same
     * dead-gate problem as one that reads no files.
     *
     * The tighter of the two governs, and this takes it from the constant rather
     * than restating it, so the two cannot drift. If the language's limit ever
     * rises above 40, §9.1's number starts binding and this still holds.
     */
    public const DECLARED_MAX_DEPTH = 40;
    public const MAX_DEPTH = Sandbox::MAX_DEPTH < self::DECLARED_MAX_DEPTH
        ? Sandbox::MAX_DEPTH
        : self::DECLARED_MAX_DEPTH;
    public const MAX_DEPENDENCIES = 64;
    public const MAX_AGG_CALLS = 4;
    public const MAX_LIST_ELEMENTS = 500;

    /** §9.9 R-VIII-007: rounding is declared, not written into the expression. */
    private const ROUNDING_FUNCTIONS = ['ROUND', 'CEIL', 'FLOOR', 'ROUND_TO', 'TRUNC'];

    /** §9.10: the shapes that make a test class reachable and so required. */
    private const BOUNDARY_FUNCTIONS = ['MIN', 'MAX', 'CLAMP', 'BANDS', 'SLAB', 'SLAB_MARGINAL_RELIEF'];

    /** @return PublishReport */
    public static function run(Formula $formula, ?GateEnvironment $environment = null): PublishReport
    {
        $environment ??= new GateEnvironment();

        return new PublishReport($formula->describe(), [
            self::g1ParseAndBudget($formula),
            self::g2Symbols($formula, $environment),
            self::g3Types($formula),
            self::g4Units($formula),
            self::g5Cycles($formula, $environment),
            self::g6DivisionByZero($formula, $environment),
            self::g7Tables($formula),
            self::g8Slices($formula, $environment),
            self::g9Rounding($formula),
            self::g10SampleTests($formula, $environment),
            self::g11Scope($formula, $environment),
            self::g12Approval($formula),
        ]);
    }

    // -----------------------------------------------------------------------
    // G1 — Parse and budget (§9.1)
    // -----------------------------------------------------------------------

    private static function g1ParseAndBudget(Formula $formula): PublishCheck
    {
        $name = 'Parse and budget';
        $issues = [];
        $source = $formula->expression->source;

        // Parsing already happened: Compiled::parse throws, so a formula that
        // did not parse could not have been constructed. The budgets are what
        // is left, and each one exists for a stated reason rather than a taste.
        if (mb_strlen($source) > self::MAX_SOURCE_CHARS) {
            $issues[] = 'source is ' . mb_strlen($source) . ' characters; the limit is '
                . self::MAX_SOURCE_CHARS . ' (Part VII §5.2)';
        }

        $nodes = $formula->expression->nodeCount();

        if ($nodes > self::MAX_NODES) {
            $issues[] = "the AST has {$nodes} nodes; the limit is " . self::MAX_NODES
                . ' — a formula longer than this is a structure, not a formula';
        }

        $depth = $formula->expression->depth();

        if ($depth > self::MAX_DEPTH) {
            $issues[] = "the expression nests {$depth} levels deep; the limit is " . self::MAX_DEPTH;
        }

        $dependencies = count($formula->dependsOn);

        if ($dependencies > self::MAX_DEPENDENCIES) {
            $issues[] = "it names {$dependencies} distinct dependencies; the limit is " . self::MAX_DEPENDENCIES
                . ' — beyond this the graph is unreadable and the trace unusable';
        }

        $aggregates = self::count($formula->expression->ast, static fn (Node $n): bool => $n instanceof AggCall);

        if ($aggregates > self::MAX_AGG_CALLS) {
            $issues[] = "it makes {$aggregates} agg.* calls; the limit is " . self::MAX_AGG_CALLS
                . ' (Part VII §5.9.4 cost model)';
        }

        foreach (self::nodesOf($formula->expression->ast, ListLiteral::class) as $list) {
            if (count($list->elements) > self::MAX_LIST_ELEMENTS) {
                $issues[] = 'a list literal on line ' . $list->line . ' has ' . count($list->elements)
                    . ' elements; the limit is ' . self::MAX_LIST_ELEMENTS;
            }
        }

        return $issues === [] ? PublishCheck::pass('G1', $name) : PublishCheck::block('G1', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // G2 — Symbols and references (§9.2)
    // -----------------------------------------------------------------------

    private static function g2Symbols(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Symbols and references';
        $issues = [];
        $unchecked = [];

        $symbols = $formula->expression->symbols;

        foreach (self::nodesOf($formula->expression->ast, VariableRef::class) as $reference) {
            if (!$symbols->hasNamespace($reference->namespace)) {
                $issues[] = "namespace '{$reference->namespace}' is not in this calculation point's table "
                    . "(line {$reference->line})";
            }
        }

        // §9.2: every keyed accessor's argument must resolve in this tenant, in
        // this scope, on effective_from. A registry the caller did not supply is
        // reported, never assumed empty and never assumed complete.
        foreach ($formula->dependsOn as $dependency) {
            $registry = $environment->registryFor($dependency->kind);

            if ($registry === null) {
                $unchecked[$dependency->kind] = true;

                continue;
            }

            if (!in_array($dependency->code, $registry, true)) {
                $issues[] = "{$dependency->kind} '{$dependency->code}' does not resolve in this tenant "
                    . 'on ' . $formula->range->from->format('d-M-Y');
            }
        }

        if ($issues !== []) {
            return PublishCheck::block('G2', $name, $issues);
        }

        if ($unchecked !== []) {
            return PublishCheck::couldNotCheck(
                'G2',
                $name,
                'no registry supplied for: ' . implode(', ', array_keys($unchecked))
                . '. The namespaces were checked; the codes were not.',
            );
        }

        return PublishCheck::pass('G2', $name);
    }

    // -----------------------------------------------------------------------
    // G3 — Type checking (§9.3)
    // -----------------------------------------------------------------------

    private static function g3Types(Formula $formula): PublishCheck
    {
        $name = 'Types';
        $check = $formula->expression->typeCheck();

        if (!$check->isValid()) {
            return PublishCheck::block('G3', $name, $check->messages());
        }

        // §9.3: the inferred root type must EQUAL result_type, not be coercible
        // to it. A formula that computes Days and is declared Number is a unit
        // error wearing a cast.
        if (!$check->type->equals($formula->resultType)) {
            return PublishCheck::block('G3', $name, [
                "the expression infers {$check->type}, and result_type declares {$formula->resultType}.",
                'Assigning ' . $check->type . ' to ' . $formula->resultType
                . ' is not permitted — the inferred type must equal the declared one, never be coerced to it.',
            ]);
        }

        return PublishCheck::pass('G3', $name);
    }

    // -----------------------------------------------------------------------
    // G4 — Unit checking (§9.4)
    // -----------------------------------------------------------------------

    private static function g4Units(Formula $formula): PublishCheck
    {
        $name = 'Units';

        // Most of §9.4 IS the coercion matrix, and G3 has already run it: a
        // Rate<Money/Days> multiplied by Hours, a Money in one currency combined
        // with another, a Days added to Hours — each is a type error before it
        // is a unit error, which is the design (Part VII §5.4.2).
        //
        // What §9.4 adds beyond the matrix is the DAY-LENGTH BASIS: two Days
        // values from different day-length policies must not combine without
        // TO_DAYS(x, '<policy>'). That basis is not carried in Type — Days has
        // no parameter — so this gate cannot see it, and says so rather than
        // reporting a pass it has not earned. Carrying the basis in the type is
        // the fix, and it is a language change, not a gate change.
        $usesDuration = false;

        foreach (self::nodesOf($formula->expression->ast, VariableRef::class) as $reference) {
            $symbol = $formula->expression->symbols->symbol($reference->symbol());

            if ($symbol !== null && $symbol->type->is(TypeKind::Days, TypeKind::Hours, TypeKind::Minutes)) {
                $usesDuration = true;

                break;
            }
        }

        if (!$usesDuration) {
            return PublishCheck::pass('G4', $name);
        }

        return PublishCheck::couldNotCheck(
            'G4',
            $name,
            'this formula combines durations, and the day-length basis that gives Days its meaning is not '
            . 'carried in the type (Days takes no parameter). The operation matrix has checked everything '
            . 'else §9.4 asks for; the basis mix it names cannot be seen from here.',
        );
    }

    // -----------------------------------------------------------------------
    // G5 — Cycles (§9.5, §7.2)
    // -----------------------------------------------------------------------

    private static function g5Cycles(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Cycles';
        $graph = new Graph();
        $graph->add($formula->node(), $formula->dependsOn, $formula->sequenceNo, $formula->label());

        // GateEnvironment declares `published` as list<Formula>, but nothing enforces
        // that at runtime for a caller assembling it from a checkout/repository — the
        // instanceof below is what actually protects g5Cycles() from that, so it is
        // widened locally rather than trusted as certain.
        /** @var list<mixed> $published */
        $published = $environment->published;

        foreach ($published as $other) {
            if ($other instanceof Formula) {
                $graph->add($other->node(), $other->dependsOn, $other->sequenceNo, $other->label());
            }
        }

        foreach ($environment->extraEdges as $from => $to) {
            $graph->addEdge($from, $to, 'structure');
        }

        try {
            $graph->evaluationOrder();
        } catch (CircularDependency $cycle) {
            return PublishCheck::block('G5', $name, $cycle->getMessage());
        }

        return PublishCheck::pass('G5', $name);
    }

    // -----------------------------------------------------------------------
    // G6 — Division-by-zero reachability (§9.6)
    // -----------------------------------------------------------------------

    private static function g6DivisionByZero(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Division by zero';
        $issues = [];

        foreach (self::nodesOf($formula->expression->ast, Binary::class) as $binary) {
            if ($binary->operator !== '/') {
                continue;
            }

            $verdict = self::classifyDenominator($binary->right, $environment);

            if ($verdict !== null) {
                $issues[] = $verdict;
            }
        }

        foreach (self::nodesOf($formula->expression->ast, FunctionCall::class) as $call) {
            if ($call->name !== 'MOD' || !isset($call->args[1])) {
                continue;
            }

            $verdict = self::classifyDenominator($call->args[1], $environment);

            if ($verdict !== null) {
                $issues[] = $verdict;
            }
        }

        return $issues === [] ? PublishCheck::pass('G6', $name) : PublishCheck::block('G6', $name, $issues);
    }

    /** §9.6's table. Null when the denominator is safe. */
    private static function classifyDenominator(Node $denominator, GateEnvironment $environment): ?string
    {
        // A non-zero literal.
        if ($denominator instanceof Literal) {
            return self::isZeroLiteral($denominator)
                ? "the denominator on line {$denominator->line} is the literal zero"
                : null;
        }

        // MAX(x, <positive literal>) or COALESCE(x, <positive literal>) — guarded.
        if ($denominator instanceof FunctionCall
            && in_array($denominator->name, ['MAX', 'COALESCE'], true)
        ) {
            foreach ($denominator->args as $argument) {
                if ($argument instanceof Literal && self::isPositiveLiteral($argument)) {
                    return null;
                }
            }
        }

        // A symbol the slice schema declares positive and not-null.
        if ($denominator instanceof VariableRef
            && in_array($denominator->symbol(), $environment->positiveSymbols, true)
        ) {
            return null;
        }

        $shown = $denominator instanceof VariableRef ? $denominator->symbol() : 'the expression';

        return "denominator `{$shown}` on line {$denominator->line} can be zero. "
            . 'Guard it with MAX(…, 1) or IF(… = 0, …, …), or declare the symbol positive in the slice schema.';
    }

    // -----------------------------------------------------------------------
    // G7 — Table well-formedness (§9.7)
    // -----------------------------------------------------------------------

    private static function g7Tables(Formula $formula): PublishCheck
    {
        $name = 'Bands, slabs and steps';
        $issues = [];

        foreach (self::nodesOf($formula->expression->ast, FunctionCall::class) as $call) {
            if ($call->name === 'STEP') {
                // §9.7: a STEP declares a mode and a positive block.
                $mode = isset($call->args[3]) ? Catalogue::literalText($call->args[3]) : null;

                if ($mode === null || !in_array($mode, ['ceil', 'floor', 'round'], true)) {
                    $issues[] = "STEP() on line {$call->line} must declare mode ceil, floor or round, as a literal";
                }

                if (isset($call->args[1]) && $call->args[1] instanceof Literal
                    && !self::isPositiveLiteral($call->args[1])
                ) {
                    $issues[] = "STEP() on line {$call->line} needs a positive block size";
                }
            }

            if ($call->name === 'SLAB' || $call->name === 'SLAB_MARGINAL_RELIEF') {
                // §9.7: the table must be named as a literal so the pack can be
                // resolved and its bands validated before anything evaluates.
                if (!isset($call->args[1]) || Catalogue::literalText($call->args[1]) === null) {
                    $issues[] = "{$call->name}() on line {$call->line} must name a slab table as a literal code, "
                        . 'so the resolved pack can be validated at publish rather than walked in stored order';
                }
            }
        }

        // BANDS well-formedness — ascending, contiguous, equal lengths — is
        // validated by the catalogue during type checking, so a malformed table
        // has already blocked at G3. Repeating it here would be a second source
        // of truth for the same rule.
        return $issues === [] ? PublishCheck::pass('G7', $name) : PublishCheck::block('G7', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // G8 — Context slices (§9.8)
    // -----------------------------------------------------------------------

    private static function g8Slices(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Context slices';
        $read = $formula->readSlices();
        $declared = $formula->declaredSlices;

        $undeclared = array_values(array_diff($read, $declared));
        $unused = array_values(array_diff($declared, $read));

        if ($declared === [] && $read !== []) {
            return PublishCheck::block('G8', $name, [
                'the expression reads ' . implode(', ', $read) . ' and the version declares no slices at all.',
                'The compiler derives the true set; a declared set that does not contain it is refused (§8.3).',
            ]);
        }

        if ($undeclared !== []) {
            return PublishCheck::block('G8', $name,
                'read but not declared: ' . implode(', ', $undeclared)
                . ' — the context would not be assembled with what this formula needs');
        }

        if ($environment->offeredSlices !== null) {
            $notOffered = array_values(array_diff($declared, $environment->offeredSlices));

            if ($notOffered !== []) {
                return PublishCheck::block('G8', $name,
                    'not offered by this calculation point: ' . implode(', ', $notOffered));
            }
        }

        if ($unused !== []) {
            return PublishCheck::lint('G8', $name,
                'declared but not read: ' . implode(', ', $unused) . ' — assembly cost with no consumer');
        }

        return PublishCheck::pass('G8', $name);
    }

    // -----------------------------------------------------------------------
    // G9 — Rounding declaration (§9.9)
    // -----------------------------------------------------------------------

    private static function g9Rounding(Formula $formula): PublishCheck
    {
        $name = 'Rounding declaration';

        // Most of §9.9 is enforced by Formula's constructor, so an unpublishable
        // rounding declaration cannot be constructed. What is left is the one
        // check that needs the AST: R-VIII-007, no rounding at the root.
        $root = $formula->expression->ast;

        if ($root instanceof FunctionCall && in_array($root->name, self::ROUNDING_FUNCTIONS, true)) {
            return PublishCheck::block('G9', $name, [
                "{$root->name}() is applied to the whole expression (R-VIII-007).",
                'Rounding is declared on the formula, once, so that it is visible in configuration and in the '
                . 'trace. A ROUND in the expression rounds a second time and is invisible to both.',
            ]);
        }

        return PublishCheck::pass('G9', $name);
    }

    // -----------------------------------------------------------------------
    // G10 — The mandatory sample-employee test set (§9.10)
    // -----------------------------------------------------------------------

    private static function g10SampleTests(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Sample cases';
        $cases = count($formula->sampleTests);

        if ($cases < 3) {
            return PublishCheck::block('G10', $name,
                "a formula version needs at least three named sample cases, and has {$cases} (R-VIII-048)");
        }

        // §9.10: the three are not arbitrary. The formula's own shape makes
        // certain classes reachable, and a reachable class with no case is a
        // number nobody has ever checked at the boundary where it goes wrong.
        $required = self::requiredCaseClasses($formula);

        // R-VIII-047: the cases are RUN. Counting names was all this gate could
        // do while `sampleTests` was a list of strings, and a gate that counts
        // names checks nothing — a formula could be published with three case
        // names none of which had ever been evaluated.
        if ($environment->sampleCases === null) {
            return PublishCheck::couldNotCheck('G10', $name,
                "{$cases} sample case name(s) are declared and no runnable cases were supplied, so none was "
                . 'evaluated. Counting names is not asserting values (§9.10).');
        }

        $mine = array_values(array_filter(
            $environment->sampleCases,
            static fn (SampleCase $c): bool => $c->formulaCode === $formula->code,
        ));

        if ($mine === []) {
            return PublishCheck::block('G10', $name,
                "this formula declares {$cases} sample case name(s) and none of the supplied runnable cases "
                . 'belongs to it. A named case nobody can run is a name.');
        }

        $failures = [];

        foreach ($mine as $case) {
            $outcome = $case->run($environment->graph === [] ? [$formula->code => $formula] : $environment->graph);

            if (!$outcome['passed']) {
                $failures[] = "{$case->name} ({$case->class}): {$outcome['message']}";
            }
        }

        if ($failures !== []) {
            return PublishCheck::block('G10', $name, $failures);
        }

        $covered = array_unique(array_map(static fn (SampleCase $c): string => $c->class, $mine));
        $missing = array_values(array_diff($required, $covered));

        if ($missing !== []) {
            return PublishCheck::warn('G10', $name,
                count($mine) . ' case(s) pass, and the shape of this formula makes these classes reachable '
                . 'with no case in each: ' . implode(', ', $missing));
        }

        return PublishCheck::pass('G10', $name);
    }

    /** @return list<string> */
    private static function requiredCaseClasses(Formula $formula): array
    {
        $classes = ['nominal'];
        $ast = $formula->expression->ast;

        foreach (self::nodesOf($ast, FunctionCall::class) as $call) {
            if (in_array($call->name, self::BOUNDARY_FUNCTIONS, true)) {
                $classes[] = 'boundary';

                break;
            }
        }

        foreach (self::nodesOf($ast, Binary::class) as $binary) {
            if ($binary->operator === '/') {
                $classes[] = 'zero';

                break;
            }
        }

        foreach ($formula->dependsOn as $dependency) {
            if ($dependency->kind === 'settlement') {
                $classes[] = 'proration';
            }

            if ($dependency->kind === 'statutory') {
                $classes[] = 'statutory boundary';
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes === ['nominal'] ? [] : $classes;
    }

    // -----------------------------------------------------------------------
    // G11 — Scope, overlap and ties (§9.11)
    // -----------------------------------------------------------------------

    private static function g11Scope(Formula $formula, GateEnvironment $environment): PublishCheck
    {
        $name = 'Scope, overlap and ties';
        $issues = [];
        $siblings = [];

        // Same widening as g5Cycles(): `published` is declared list<Formula> but is
        // not runtime-enforced, so the instanceof stays live rather than assumed.
        /** @var list<mixed> $published */
        $published = $environment->published;

        foreach ($published as $other) {
            if ($other instanceof Formula
                && $other->code === $formula->code
                && $other->versionId !== $formula->versionId
                && $other->isPublished()
            ) {
                $siblings[] = $other;
            }
        }

        foreach ($siblings as $other) {
            if (!$formula->range->overlaps($other->range)) {
                continue;   // different periods govern; no contest to resolve
            }

            $sameScope = $other->scope->toJson() === $formula->scope->toJson();

            if ($sameScope) {
                $issues[] = "its effective range overlaps {$other->label()} in the same scope "
                    . "({$formula->range} vs {$other->range})";

                continue;
            }

            // R-VIII-010: a genuine tie BLOCKS. Two versions the resolver cannot
            // order are two possible answers, and picking one by insertion order
            // is how a payslip stops being reproducible. Scope::tiesWith is the
            // one place that judgement lives — spine S3.2's weights, and the
            // rule engine uses the same call.
            if ($formula->scope->tiesWith($other->scope, $formula->priority, $other->priority)) {
                $issues[] = "it ties with {$other->label()}: the same specificity ({$formula->specificity()}) "
                    . "and the same priority ({$formula->priority}), with overlapping dates. "
                    . 'Give one a higher priority, or narrow one scope.';
            }
        }

        return $issues === [] ? PublishCheck::pass('G11', $name) : PublishCheck::block('G11', $name, $issues);
    }

    // -----------------------------------------------------------------------
    // G12 — Approval (§9.12)
    // -----------------------------------------------------------------------

    private static function g12Approval(Formula $formula): PublishCheck
    {
        $name = 'Approval';

        if (!$formula->requiresApproval) {
            return PublishCheck::pass('G12', $name);
        }

        $approver = $formula->auditMetadata['approved_by'] ?? null;
        $author = $formula->auditMetadata['created_by'] ?? null;

        if ($approver === null || $approver === '') {
            return PublishCheck::block('G12', $name,
                'this formula feeds a statutory head, a net-pay line or an employer contribution, '
                . 'so a second human must approve it before publication (spine S4, S8)');
        }

        if ($author !== null && $approver === $author) {
            return PublishCheck::block('G12', $name,
                "'{$approver}' both wrote and approved this version. Maker-checker means two people.");
        }

        if (($formula->auditMetadata['reason'] ?? '') === '' && $formula->severity === 'high_risk') {
            return PublishCheck::block('G12', $name,
                'a high_risk version needs a stated reason: it is printed in the diff the approver reads');
        }

        return PublishCheck::pass('G12', $name);
    }

    // -----------------------------------------------------------------------
    // AST helpers
    // -----------------------------------------------------------------------

    /**
     * @template T of Node
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function nodesOf(Node $node, string $class): array
    {
        $found = [];

        if ($node instanceof $class) {
            $found[] = $node;
        }

        foreach ($node->children() as $child) {
            foreach (self::nodesOf($child, $class) as $descendant) {
                $found[] = $descendant;
            }
        }

        return $found;
    }

    /** @param callable(Node): bool $matches */
    private static function count(Node $node, callable $matches): int
    {
        $total = $matches($node) ? 1 : 0;

        foreach ($node->children() as $child) {
            $total += self::count($child, $matches);
        }

        return $total;
    }

    /**
     * Both of these read the literal's SOURCE TEXT, with no float anywhere: the
     * question is "is this written as zero", and a numeric literal is written as
     * zero exactly when every digit in it is a zero. Law 7 applies to the gates
     * too, and the gate that enforces it scans this file.
     */
    private static function isZeroLiteral(Literal $literal): bool
    {
        return self::isNumeric($literal) && preg_match('/[1-9]/', $literal->text) !== 1;
    }

    private static function isPositiveLiteral(Literal $literal): bool
    {
        return self::isNumeric($literal)
            && !self::isZeroLiteral($literal)
            && !str_starts_with(ltrim($literal->text), '-');
    }

    private static function isNumeric(Literal $literal): bool
    {
        return $literal->literal === LiteralKind::Number
            || $literal->literal === LiteralKind::Money;
    }
}
