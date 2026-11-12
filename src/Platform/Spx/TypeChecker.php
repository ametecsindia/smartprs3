<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Between;
use SmartPRS\Platform\Spx\Ast\Binary;
use SmartPRS\Platform\Spx\Ast\CaseExpr;
use SmartPRS\Platform\Spx\Ast\FunctionCall;
use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\PredicateBlock;
use SmartPRS\Platform\Spx\Ast\Unary;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Ast\WindowSpec;
use SmartPRS\Platform\Spx\Functions\Catalogue;
use SmartPRS\Platform\Spx\Types\Coercion;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;

/**
 * PUBLISH-TIME type checking (Part VII §5.4, §8.3).
 *
 * Runs against a decision point's symbol table before a rule is published,
 * never during evaluation. Two properties matter:
 *
 *  1. It reports every error with a position, not just the first.
 *  2. A namespace the decision point does not declare is an error HERE — a rule
 *     at attendance.day.classify cannot read salary.* even if the context
 *     happened to contain it.
 *
 * This is where the unit-confusion defect family dies: `Days / calendar_days`,
 * `Money * Money`, `Hours + Minutes` do not type, so the rule that contains one
 * cannot be published, so the wrong number is never computed.
 */
final class TypeChecker
{
    /** Part VII §7.7: rejected at publish, because it can be checked statically. */
    public const MAX_DEPTH = Sandbox::MAX_DEPTH;
    public const MAX_AGG_CALLS = 8;

    /** §5.9.3: what a predicate block may NOT read of the outer subject. */
    private const PREDICATE_DENIED = ['attendance', 'leave', 'salary', 'settlement', 'ytd', 'claim', 'loan', 'prior'];

    /** @var list<TypeIssue> */
    private array $issues = [];

    private int $aggCalls = 0;

    private bool $inPredicate = false;

    private function __construct(private readonly SymbolTable $symbols)
    {
    }

    public static function check(Node $ast, SymbolTable $symbols): TypeCheckResult
    {
        $checker = new self($symbols);

        if ($ast->depth() > self::MAX_DEPTH) {
            $checker->report($ast, 'Expression nests ' . $ast->depth() . ' levels deep; the limit is ' . self::MAX_DEPTH);
        }

        $type = $checker->visit($ast);

        if ($checker->aggCalls > self::MAX_AGG_CALLS) {
            $checker->report($ast, 'Expression makes ' . $checker->aggCalls . ' aggregation calls; the limit is ' . self::MAX_AGG_CALLS);
        }

        return new TypeCheckResult($type, $checker->issues);
    }

    private function visit(Node $node): Type
    {
        return match (true) {
            $node instanceof Literal => $this->literal($node),
            $node instanceof ListLiteral => $this->listLiteral($node),
            $node instanceof VariableRef => $this->variable($node),
            $node instanceof FunctionCall => $this->call($node),
            $node instanceof AggCall => $this->agg($node),
            $node instanceof Unary => $this->unary($node),
            $node instanceof Binary => $this->binary($node),
            $node instanceof Between => $this->between($node),
            $node instanceof CaseExpr => $this->caseExpression($node),
            $node instanceof PredicateBlock => $this->predicate($node),
            $node instanceof WindowSpec => Type::code('window'),
            // The node set is closed (§10.1a-3). Anything else is a platform bug,
            // never a tenant input, and it fails loudly rather than evaluating.
            default => throw new \LogicException('Unknown SPX node kind ' . $node->kind()),
        };
    }

    private function literal(Literal $node): Type
    {
        return match ($node->literal) {
            LiteralKind::Number => Type::number(),
            LiteralKind::Text => Type::text(),
            LiteralKind::Date => Type::date(),
            LiteralKind::Time => Type::time(),
            LiteralKind::Money => Type::money(),
            LiteralKind::Boolean => Type::boolean(),
            LiteralKind::Null => Type::null(),
            LiteralKind::Duration => match ($node->unit) {
                'd' => Type::days(),
                'h' => Type::hours(),
                'm' => Type::minutes(),
                default => Type::duration(),
            },
        };
    }

    private function listLiteral(ListLiteral $node): Type
    {
        $types = array_map($this->visit(...), $node->elements);
        $element = Coercion::unify($types);

        if ($element === null) {
            $this->report($node, 'A list is homogeneous; this one mixes '
                . implode(', ', array_unique(array_map(strval(...), $types))));

            return Type::listOf(Type::null());
        }

        return Type::listOf($element);
    }

    /**
     * The whole data-access boundary, checked statically (§10.1c-4).
     */
    /** The declared value type of the metric whose predicate we are inside. */
    private ?Type $eventValue = null;

    private function variable(VariableRef $node): Type
    {
        $namespace = $node->namespace;

        if ($namespace === 'event') {
            if (!$this->inPredicate) {
                $this->report($node, 'event.* is available inside an aggregation predicate block only (§5.9.3)');

                return Type::null();
            }

            if ($node->symbol() === 'event.value' && $this->eventValue !== null) {
                return $this->eventValue;
            }
        } elseif ($this->inPredicate && in_array($namespace, self::PREDICATE_DENIED, true)) {
            $this->report($node, "An aggregation predicate may not read {$namespace}.* of the outer subject (§5.9.3)");

            return Type::null();
        }

        if (!$this->symbols->hasNamespace($namespace)) {
            $known = in_array($namespace, SymbolTable::KNOWN_NAMESPACES, true);

            $this->report($node, $known
                ? "Namespace '{$namespace}' is not declared available at this decision point"
                : "Unknown namespace '{$namespace}'");

            return Type::null();
        }

        $symbol = $this->symbols->symbol($node->symbol());

        if ($symbol === null) {
            $this->report($node, "Unknown symbol '" . $node->symbol() . "'");

            return Type::null();
        }

        if ($symbol->params === null && $node->args !== null) {
            $this->report($node, "'" . $node->symbol() . "' is not a keyed accessor; it takes no arguments");

            return $symbol->type;
        }

        if ($symbol->params !== null && $node->args === null) {
            $this->report($node, "'" . $node->symbol() . "' is a keyed accessor; it needs "
                . count($symbol->params) . ' argument(s), e.g. ' . $node->symbol() . "('CODE')");

            return $symbol->type;
        }

        // The two checks above already guarantee params === null iff args === null
        // (each rules out the case where exactly one of them is null), so checking
        // params alone covers "no accessor, no args" here; $node->args === null
        // can no longer be true without $symbol->params also being null.
        if ($symbol->params === null) {
            return $symbol->type;
        }

        if (count($node->args) !== count($symbol->params)) {
            $this->report($node, "'" . $node->symbol() . "' takes " . count($symbol->params)
                . ' argument(s), ' . count($node->args) . ' given');
        }

        foreach ($node->args as $index => $argument) {
            $type = $this->visit($argument);
            $expected = $symbol->params[$index] ?? null;

            if ($expected === null) {
                continue;
            }

            // A Code parameter accepts a string literal, and checks it against
            // the declared value set when one is declared.
            if ($expected->is(TypeKind::Code) && $type->is(TypeKind::Text)) {
                $this->checkSetMembership($argument, $expected);

                continue;
            }

            if (!Coercion::assignable($type, $expected)) {
                $this->report($argument, "'" . $node->symbol() . "' argument " . ($index + 1)
                    . ' must be ' . $expected . ', not ' . $type);
            }
        }

        // The first argument, when it is a literal, is the KEY: it selects the
        // declared type of `policy.value('late_grace_min')` versus
        // `policy.value('basic_pct_of_gross')`.
        $key = isset($node->args[0]) ? Catalogue::literalText($node->args[0]) : null;

        if ($key !== null && $symbol->keyedTypes !== [] && !isset($symbol->keyedTypes[$key])) {
            $this->report($node, "Key '{$key}' is not declared for '" . $node->symbol() . "'");
        }

        return $symbol->resultFor($key);
    }

    private function call(FunctionCall $node): Type
    {
        $signature = Catalogue::signature($node->name);

        if ($signature === null) {
            // No dynamic resolution, no fallback to a host function of the same
            // name. The catalogue is the whole set (§10.1a-2).
            $this->report($node, "Unknown function '{$node->name}()'. The catalogue is closed");

            foreach ($node->args as $argument) {
                $this->visit($argument);
            }

            return Type::null();
        }

        $types = array_map($this->visit(...), $node->args);
        $arity = $signature->arityMessage(count($node->args));

        if ($arity !== null) {
            $this->report($node, $arity);

            return Type::null();
        }

        $resolved = ($signature->resolve)($types, $node->args);

        if (is_string($resolved)) {
            $this->report($node, $resolved);

            return Type::null();
        }

        return $resolved;
    }

    /**
     * `agg.*` (§5.9). Subject-scoped by construction — the signature has no
     * subject parameter — and never nested (R-VII-025), which is what bounds the
     * cost of any rule to O(events in window).
     */
    private function agg(AggCall $node): Type
    {
        $this->aggCalls++;

        if ($this->inPredicate) {
            $this->report($node, 'Nested aggregation is not expressible: an aggregate over aggregates (R-VII-025)');
        }

        if (!$this->symbols->hasNamespace('agg')) {
            $this->report($node, 'Namespace \'agg\' is not declared available at this decision point');
        }

        $arities = [
            'count' => [2, 3], 'sum' => [2, 3], 'occurrence_index' => [2, 3],
            'nth' => [4, 4], 'collapse_repeats' => [2, 2], 'streak' => [2, 2], 'balance' => [2, 2],
        ];
        [$min, $max] = $arities[$node->function] ?? [0, 99];
        $given = count($node->args);

        if ($given < $min || $given > $max) {
            $this->report($node, "agg.{$node->function}() takes {$min}"
                . ($max === $min ? '' : " to {$max}") . " argument(s), {$given} given");

            return Type::number();
        }

        $metric = $node->args === [] ? null : Catalogue::literalText($node->args[0]);

        // The metric decides what `event.value` MEANS inside the predicate: one
        // late_arrival's value is minutes, one absent_day's is days. Without
        // this the predicate compares a Number with whatever the rule author
        // meant, which is the unit confusion the type system exists to stop.
        $outerEventValue = $this->eventValue;
        $this->eventValue = $metric === null ? null : $this->symbols->aggMetric($metric);

        if ($metric === null) {
            $this->report($node, "agg.{$node->function}()'s first argument is an event, measure or "
                . 'ledger code from the platform catalogue, written as a bare code');
        }

        foreach ($node->args as $index => $argument) {
            if ($index === 0) {
                continue;
            }

            $this->visit($argument);
        }

        $this->eventValue = $outerEventValue;

        // A window is required where the signature declares one.
        if (in_array($node->function, ['count', 'sum', 'occurrence_index'], true)
            && !($node->args[1] ?? null) instanceof WindowSpec
        ) {
            $this->report($node, "agg.{$node->function}()'s second argument must be a window: "
                . implode(', ', array_keys(Parser::WINDOWS)));
        }

        return match ($node->function) {
            'sum', 'balance' => $metric === null
                ? Type::number()
                : ($this->symbols->aggMetric($metric)
                    ?? $this->reportAnd($node, "Measure or ledger '{$metric}' is not declared with a type", Type::number())),
            'nth' => Type::dateTime(),
            default => Type::number(),
        };
    }

    private function predicate(PredicateBlock $node): Type
    {
        $outer = $this->inPredicate;
        $this->inPredicate = true;
        $type = $this->visit($node->expression);
        $this->inPredicate = $outer;

        if (!$type->is(TypeKind::Boolean, TypeKind::Null)) {
            $this->report($node, 'An aggregation predicate must be Boolean, not ' . $type);
        }

        return Type::boolean();
    }

    private function unary(Unary $node): Type
    {
        $type = $this->visit($node->operand);

        if ($node->operator === 'NOT') {
            if (!$type->is(TypeKind::Boolean, TypeKind::Null)) {
                $this->report($node, 'NOT takes a Boolean, not ' . $type);
            }

            return Type::boolean();
        }

        // Unary minus: only on the signed quantities (§5.5 level 2).
        if (!$type->base()->is(TypeKind::Number, TypeKind::Money, TypeKind::Days, TypeKind::Hours, TypeKind::Minutes, TypeKind::Duration, TypeKind::Rate)) {
            $this->report($node, 'Unary minus takes a Number, Money, Days, Hours or Minutes, not ' . $type);

            return $type;
        }

        return $type->base()->is(TypeKind::Number) ? Type::number() : $type;
    }

    private function binary(Binary $node): Type
    {
        $left = $this->visit($node->left);
        $right = $this->visit($node->right);

        if (in_array($node->operator, Binary::LOGICAL, true)) {
            foreach ([[$node->left, $left], [$node->right, $right]] as [$operand, $type]) {
                if (!$type->is(TypeKind::Boolean, TypeKind::Null)) {
                    $this->report($operand, $node->operator . ' takes Boolean operands, not ' . $type);
                }
            }

            return Type::boolean();
        }

        if (in_array($node->operator, Binary::ARITHMETIC, true)) {
            $result = Coercion::arithmetic($left, $node->operator, $right);

            if ($result === null) {
                $this->report($node, $this->arithmeticMessage($left, $node->operator, $right));

                return Type::null();
            }

            return $result;
        }

        if (in_array($node->operator, Binary::RELATIONAL, true)) {
            $this->comparison($node, $left, $right);

            return Type::boolean();
        }

        return $this->membership($node, $left, $right);
    }

    private function comparison(Binary $node, Type $left, Type $right): void
    {
        // Comparing a Code with a string literal is legal only when the literal
        // is a member of the declared value set — checked here, at publish.
        if ($left->is(TypeKind::Code) && $right->is(TypeKind::Text)) {
            $this->checkSetMembership($node->right, $left);

            return;
        }

        if ($right->is(TypeKind::Code) && $left->is(TypeKind::Text)) {
            $this->checkSetMembership($node->left, $right);

            return;
        }

        if (!Coercion::comparable($left, $right)) {
            $this->report($node, "Cannot compare {$left} with {$right} using {$node->operator}");

            return;
        }

        if (in_array($node->operator, ['<', '<=', '>', '>='], true)
            && (!Coercion::orderable($left) || !Coercion::orderable($right))
        ) {
            $this->report($node, "{$left} has no ordering; {$node->operator} is not defined on it");
        }
    }

    private function membership(Binary $node, Type $left, Type $right): Type
    {
        if ($node->operator === 'MATCHES') {
            // §10.3: MATCHES is not permitted on a Code, so it cannot be used to
            // rebuild identity-from-name semantics — SmartPRS 2's DA regex on a
            // component NAME (AppDataController:2269) is inexpressible.
            if (!$left->is(TypeKind::Text, TypeKind::Null)) {
                $this->report($node, $left->is(TypeKind::Code)
                    ? 'MATCHES may not be applied to a Code: identity comes from the code, never from a pattern over a name'
                    : 'MATCHES takes Text on the left, not ' . $left);
            }

            $pattern = $node->right instanceof Literal ? $node->right->text : null;
            $problem = $pattern === null ? 'MATCHES requires a literal pattern' : Sandbox::lintPattern($pattern);

            if ($problem !== null) {
                $this->report($node->right, $problem);
            }

            return Type::boolean();
        }

        if (in_array($node->operator, ['CONTAINS', 'NOT CONTAINS'], true)) {
            if ($left->is(TypeKind::Code) || $right->is(TypeKind::Code)) {
                $this->report($node, 'CONTAINS may not be applied to a Code (§5.6): identity is by code, not by substring');

                return Type::boolean();
            }

            if ($left->is(TypeKind::ListOf)) {
                if ($left->element !== null && !Coercion::comparable($left->element, $right)) {
                    $this->report($node, "A {$left} does not contain a {$right}");
                }

                return Type::boolean();
            }

            if (!$left->is(TypeKind::Text, TypeKind::Null) || !$right->is(TypeKind::Text, TypeKind::Null)) {
                $this->report($node, "CONTAINS takes Text or a List on the left; got {$left} and {$right}");
            }

            return Type::boolean();
        }

        // IN / NOT IN — the right operand is a list, never a scalar.
        if (!$right->is(TypeKind::ListOf, TypeKind::Null)) {
            $this->report($node, "The right operand of {$node->operator} must be a List, not {$right}; write IN ['X']");

            return Type::boolean();
        }

        $element = $right->element;

        if ($element === null || $element->is(TypeKind::Null)) {
            return Type::boolean();
        }

        if ($left->is(TypeKind::Code) && $element->is(TypeKind::Text) && $node->right instanceof ListLiteral) {
            foreach ($node->right->elements as $member) {
                $this->checkSetMembership($member, $left);
            }

            return Type::boolean();
        }

        if (!Coercion::comparable($left, $element)) {
            $this->report($node, "{$left} cannot be a member of {$right}");
        }

        return Type::boolean();
    }

    private function between(Between $node): Type
    {
        $value = $this->visit($node->value);
        $low = $this->visit($node->low);
        $high = $this->visit($node->high);

        foreach ([[$node->low, $low], [$node->high, $high]] as [$operand, $type]) {
            if (!Coercion::comparable($value, $type)) {
                $this->report($operand, "BETWEEN compares {$value} with {$type}");
            } elseif (!Coercion::orderable($value) || !Coercion::orderable($type)) {
                $this->report($operand, "{$value} has no ordering; BETWEEN is not defined on it");
            }
        }

        return Type::boolean();
    }

    private function caseExpression(CaseExpr $node): Type
    {
        $results = [];

        foreach ($node->whens as [$condition, $result]) {
            $conditionType = $this->visit($condition);

            if (!$conditionType->is(TypeKind::Boolean, TypeKind::Null)) {
                $this->report($condition, 'A WHEN condition must be Boolean, not ' . $conditionType);
            }

            $results[] = $this->visit($result);
        }

        $results[] = $this->visit($node->else);
        $unified = Coercion::unify($results);

        if ($unified === null) {
            $this->report($node, 'Every branch of a CASE must have the same type; got '
                . implode(', ', array_unique(array_map(strval(...), $results))));

            return Type::null();
        }

        return $unified;
    }

    /**
     * A string literal standing in for a Code must be in the declared value set.
     * When the set is not declared to this symbol table the check is skipped and
     * ERR_SET_MEMBER is left to the runtime (§5.8) — the value set may change
     * after publication.
     */
    private function checkSetMembership(Node $node, Type $code): void
    {
        $literal = Catalogue::literalText($node);
        $set = $code->parameter === null ? null : $this->symbols->valueSet($code->parameter);

        if ($literal === null) {
            if (!($node instanceof Literal)) {
                $this->report($node, 'A Code compares against a literal from its value set, not a computed Text');
            }

            return;
        }

        if ($set !== null && !in_array($literal, $set, true)) {
            $this->report($node, "'{$literal}' is not a member of the value set '{$code->parameter}'");
        }
    }

    private function arithmeticMessage(Type $left, string $operator, Type $right): string
    {
        $hint = match (true) {
            $left->is(TypeKind::Money) && $right->is(TypeKind::Money) && in_array($operator, ['*', '/'], true)
                => ' — a money/money ratio needs MONEY_AS_NUMBER() on both, deliberately',
            $left->is(TypeKind::Money) !== $right->is(TypeKind::Money) && in_array($operator, ['+', '-'], true)
                => ' — Money never coerces to Number; use MONEY(amount, currency) or MONEY_AS_NUMBER()',
            $left->base()->is(TypeKind::Days) && $right->is(TypeKind::Hours, TypeKind::Minutes)
                => " — write TO_HOURS() or TO_MINUTES() and name the day-length policy",
            $left->is(TypeKind::Hours) && $right->is(TypeKind::Minutes)
                => ' — write TO_MINUTES(h) + m',
            $left->is(TypeKind::Date) && $right->is(TypeKind::Date)
                => ' — use DATEDIFF(a, b, unit)',
            $operator === '%' => ' — `%` is percent-of; its right operand is a Number or Percentage',
            default => '',
        };

        return "{$left} {$operator} {$right} is not a valid operation{$hint}";
    }

    private function reportAnd(Node $node, string $message, Type $type): Type
    {
        $this->report($node, $message);

        return $type;
    }

    private function report(Node $node, string $message): void
    {
        $this->issues[] = new TypeIssue($message, $node->line, $node->column);
    }
}
