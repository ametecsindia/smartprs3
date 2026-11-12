<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Spx\Ast\AggCall;
use SmartPRS\Platform\Spx\Ast\Between;
use SmartPRS\Platform\Spx\Ast\Binary;
use SmartPRS\Platform\Spx\Ast\CaseExpr;
use SmartPRS\Platform\Spx\Ast\Codec;
use SmartPRS\Platform\Spx\Ast\FunctionCall;
use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\PredicateBlock;
use SmartPRS\Platform\Spx\Ast\Unary;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Ast\WindowSpec;
use SmartPRS\Platform\Spx\Functions\Runtime;
use SmartPRS\Platform\Spx\Types\Coercion;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * THE TREE-WALKING EVALUATOR — Part VII §7.
 *
 * A switch over a closed node set, against a prepared context. Deterministic,
 * side-effect-free and bounded:
 *
 *   - no clock except context.evaluation_date (R-VII-019), so a recalculation
 *     of March run in November produces March's answer;
 *   - no randomness, no I/O, no database, no filesystem, no network;
 *   - no mutation: evaluation returns a Value and changes nothing, including
 *     the context, the AST and this object;
 *   - every node ticks the sandbox, so a node bomb ends in ERR_BUDGET_NODES
 *     rather than a hang.
 *
 * ponytail: a tree walk, not a bytecode VM. Part VII §14 says a cached AST walk
 * is fast enough for 10,000 employees, and a VM would be a second language
 * implementation to keep in step with the first. Revisit only if a measured
 * batch says so.
 */
final class Evaluator
{
    private function __construct(
        private readonly Compiled $expression,
        private readonly Context $context,
        private readonly Sandbox $sandbox,
    ) {
    }

    /**
     * Evaluate a type-checked expression. The signature the rule engine depends
     * on; the optional sandbox is how a decision point tightens its budgets.
     */
    public static function evaluate(Compiled $expr, Context $context, ?Sandbox $sandbox = null): Value
    {
        return (new self($expr, $context, $sandbox ?? new Sandbox()))->visit($expr->ast);
    }

    private function visit(Node $node): Value
    {
        $breach = $this->sandbox->tick();

        if ($breach !== null) {
            return $breach;
        }

        return match (true) {
            $node instanceof Literal => self::literalValue($node),
            $node instanceof ListLiteral => $this->listLiteral($node),
            $node instanceof VariableRef => $this->variable($node),
            $node instanceof FunctionCall => $this->call($node),
            $node instanceof AggCall => $this->agg($node),
            $node instanceof Unary => $this->unary($node),
            $node instanceof Binary => $this->binary($node),
            $node instanceof Between => $this->between($node),
            $node instanceof CaseExpr => $this->caseExpression($node),
            $node instanceof PredicateBlock => $this->visit($node->expression),
            $node instanceof WindowSpec => Value::text($node->window),
            // The node set is closed (§10.1a-3). There is no dynamic dispatch
            // here and no arm that could reach host code.
            default => Value::error(ErrorCode::Type, 'Unknown SPX node kind ' . $node->kind()),
        };
    }

    /** The literal forms, exactly as written. No literal becomes a float. */
    public static function literalValue(Literal $node): Value
    {
        return match ($node->literal) {
            LiteralKind::Number => Value::number(Rational::fromDecimalString($node->text)),
            LiteralKind::Text => Value::text($node->text),
            LiteralKind::Boolean => Value::boolean(strtoupper($node->text) === 'TRUE'),
            LiteralKind::Null => Value::null(),
            LiteralKind::Money => Value::money(Money::of($node->text)),
            LiteralKind::Date => Value::date(new \DateTimeImmutable($node->text, new \DateTimeZone('UTC'))),
            LiteralKind::Time => Value::time(self::secondsOfDay($node->text)),
            LiteralKind::Duration => self::durationValue($node),
        };
    }

    private static function durationValue(Literal $node): Value
    {
        $amount = Rational::fromDecimalString($node->text);

        return match ($node->unit) {
            'd' => Value::quantity(TypeKind::Days, $amount),
            'h' => Value::quantity(TypeKind::Hours, $amount),
            'm' => Value::quantity(TypeKind::Minutes, $amount),
            default => Value::quantity(TypeKind::Duration, $amount),
        };
    }

    private static function secondsOfDay(string $time): int
    {
        [$hours, $minutes, $seconds] = array_map(intval(...), explode(':', $time));

        return $hours * 3600 + $minutes * 60 + $seconds;
    }

    private function listLiteral(ListLiteral $node): Value
    {
        $elements = [];

        foreach ($node->elements as $element) {
            $value = $this->visit($element);

            if ($value->isError()) {
                return $value;
            }

            $elements[] = $value;
        }

        return Value::list($elements);
    }

    /**
     * A symbol read. The second of the two gates on data access: the type
     * checker refused an undeclared namespace at publish, and there is no
     * binding for one here either.
     */
    private function variable(VariableRef $node): Value
    {
        $symbol = $this->expression->symbols->symbol($node->symbol());

        if ($symbol === null) {
            return Value::error(ErrorCode::Type, "Symbol '" . $node->symbol() . "' is not declared here");
        }

        $arguments = [];

        foreach ($node->args ?? [] as $argument) {
            $value = $this->visit($argument);

            if ($value->isError()) {
                return $value;
            }

            $arguments[] = $value;
        }

        $key = Context::key($node->symbol(), $arguments);
        $value = $this->context->symbol($key) ?? Value::null();
        $declared = $symbol->resultFor(
            isset($arguments[0]) && is_string($arguments[0]->payload) ? $arguments[0]->payload : null,
        );

        if ($value->isNull()) {
            // R-VII-015: a declared not-null symbol that is null FAILS the
            // evaluation. It does not quietly not-match, which is how
            // SmartPRS 2's unknown leave type becomes PAID.
            return $symbol->notNullRequired
                ? Value::error(ErrorCode::MissingInput, "{$key} is required here and is null")
                : Value::null();
        }

        if ($value->isError()) {
            return $value;
        }

        if (!self::satisfies($value->type, $declared)) {
            // Should be impossible after publish-time checking; it means the
            // context supplied the wrong type, which is a platform bug (§5.8).
            return Value::error(
                ErrorCode::Type,
                "{$key} is declared {$declared} but the context supplied {$value->type}",
            );
        }

        return $value;
    }

    /** Lenient shape check: the kind must agree, and a currency must not differ. */
    private static function satisfies(Type $actual, Type $declared): bool
    {
        if ($declared->is(TypeKind::Null) || $actual->base()->kind === $declared->base()->kind) {
            return !$declared->is(TypeKind::Money) || $actual->parameter === $declared->parameter;
        }

        return Coercion::assignable($actual, $declared);
    }

    private function call(FunctionCall $node): Value
    {
        // IF and COALESCE are lazy: an unevaluated branch must not be able to
        // poison the result with an error it would never have produced.
        if ($node->name === 'IF') {
            return $this->conditional($node);
        }

        if ($node->name === 'COALESCE') {
            return $this->coalesce($node);
        }

        $arguments = [];

        foreach ($node->args as $argument) {
            $value = $this->visit($argument);

            if ($value->isError()) {
                return $value;
            }

            $arguments[] = $value;
        }

        return Runtime::call($node->name, $arguments, $node->args, $this->context);
    }

    private function conditional(FunctionCall $node): Value
    {
        $condition = $this->visit($node->args[0]);

        if ($condition->isError()) {
            return $condition;
        }

        // UNKNOWN takes the else branch, and the trace records why (§5.6).
        return $this->visit($condition->isTrue() ? $node->args[1] : $node->args[2]);
    }

    private function coalesce(FunctionCall $node): Value
    {
        $last = Value::null();

        foreach ($node->args as $argument) {
            $last = $this->visit($argument);

            // COALESCE absorbs a Null. It does NOT absorb an error (§5.8).
            if ($last->isError() || !$last->isNull()) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * `agg.*` reads a result the caller resolved before evaluation began, keyed
     * canonically. The evaluator itself never queries anything, so an
     * unavailable aggregate is a named failure and never a truncated count.
     */
    private function agg(AggCall $node): Value
    {
        $key = self::aggKey($node);
        $answer = $this->context->prepared($key);

        return $answer ?? Value::error(
            ErrorCode::AggUnavailable,
            "{$key} was not prepared for this evaluation",
        );
    }

    /** The canonical key of an aggregation call — stable across processes. */
    public static function aggKey(AggCall $node): string
    {
        $parts = [];

        foreach ($node->args as $argument) {
            $parts[] = match (true) {
                $argument instanceof Literal => self::literalKey($argument),
                $argument instanceof WindowSpec => self::windowKey($argument),
                // The predicate's identity is its canonical AST, hashed — the
                // qualifier_hash of the counter definition (§5.9.4).
                $argument instanceof PredicateBlock => '{'
                    . substr(hash('sha256', self::canonical($argument->expression)), 0, 16) . '}',
                default => self::canonical($argument),
            };
        }

        return 'agg.' . $node->function . '(' . implode(',', $parts) . ')';
    }

    private static function windowKey(WindowSpec $window): string
    {
        if ($window->args === []) {
            return $window->window;
        }

        $arguments = array_map(
            static fn (Node $argument): string => $argument instanceof Literal
                ? self::literalKey($argument)
                : self::canonical($argument),
            $window->args,
        );

        return $window->window . '(' . implode(',', $arguments) . ')';
    }

    /** Same shape Context::key() uses, so counters and prepared answers agree. */
    private static function literalKey(Literal $literal): string
    {
        return match ($literal->literal) {
            LiteralKind::Date, LiteralKind::Time => '#' . $literal->text . '#',
            default => $literal->text,
        };
    }

    /** Position-free, so the same predicate in two rules is the same counter. */
    private static function canonical(Node $node): string
    {
        return json_encode(Codec::encodeCanonical($node), JSON_THROW_ON_ERROR);
    }

    private function unary(Unary $node): Value
    {
        $value = $this->visit($node->operand);

        if ($value->isError()) {
            return $value;
        }

        if ($node->operator === 'NOT') {
            // NOT UNKNOWN is UNKNOWN.
            return $value->isNull() ? Value::null() : Value::boolean(!$value->isTrue());
        }

        if ($value->isNull()) {
            return Value::null();
        }

        $rational = $value->rational();

        return Value::quantityOfType($value->type, Rational::of(-$rational->numerator, $rational->denominator));
    }

    private function binary(Binary $node): Value
    {
        if (in_array($node->operator, Binary::LOGICAL, true)) {
            return $this->logical($node);
        }

        $left = $this->visit($node->left);

        if ($left->isError()) {
            return $left;
        }

        $right = $this->visit($node->right);

        if ($right->isError()) {
            return $right;
        }

        return match (true) {
            in_array($node->operator, Binary::ARITHMETIC, true) => $this->arithmetic($node->operator, $left, $right),
            in_array($node->operator, Binary::RELATIONAL, true) => $this->relational($node->operator, $left, $right),
            $node->operator === 'MATCHES' => $this->matches($node, $left),
            in_array($node->operator, ['IN', 'NOT IN'], true) => $this->membership($node->operator, $left, $right),
            default => $this->contains($node->operator, $left, $right),
        };
    }

    /**
     * Three-valued logic with short-circuiting (§5.4.3, §5.5).
     * UNKNOWN AND FALSE = FALSE. UNKNOWN OR TRUE = TRUE. Otherwise UNKNOWN
     * spreads. A skipped branch is not evaluated at all, which is what makes
     * "why did this rule not fire?" answerable.
     */
    private function logical(Binary $node): Value
    {
        $left = $this->visit($node->left);

        if ($left->isError()) {
            return $left;
        }

        if ($node->operator === 'AND' && $left->isFalse()) {
            return Value::boolean(false);
        }

        if ($node->operator === 'OR' && $left->isTrue()) {
            return Value::boolean(true);
        }

        $right = $this->visit($node->right);

        if ($right->isError()) {
            return $right;
        }

        if ($node->operator === 'AND') {
            if ($right->isFalse()) {
                return Value::boolean(false);
            }

            return $left->isTrue() && $right->isTrue() ? Value::boolean(true) : Value::null();
        }

        if ($right->isTrue()) {
            return Value::boolean(true);
        }

        return $left->isFalse() && $right->isFalse() ? Value::boolean(false) : Value::null();
    }

    /**
     * The arithmetic of Part VIII §5.4, executed in exact rationals. Money is
     * minor units throughout, so `BASIC % 40` on ₹15,000.00 is
     * 1,500,000 × 40 / 100 = 600,000 paise — ₹6,000.00, exactly, with no float
     * on any path.
     */
    private function arithmetic(string $operator, Value $left, Value $right): Value
    {
        if ($left->isNull() || $right->isNull()) {
            return Value::null();
        }

        $type = Coercion::arithmetic($left->type, $operator, $right->type);

        if ($type === null) {
            return Value::error(
                ErrorCode::Type,
                "{$left->type} {$operator} {$right->type} is not a valid operation",
            );
        }

        // DateTime - DateTime is the one arithmetic on instants (→ Duration).
        if ($left->type->is(TypeKind::DateTime)) {
            return Value::quantity(
                TypeKind::Duration,
                Rational::of($left->dateValue()->getTimestamp() - $right->dateValue()->getTimestamp()),
            );
        }

        $a = $left->rational();
        $b = $right->rational();

        if ($operator === '/' && $b->isZero()) {
            // R-VII-014: never infinity, never null, never zero.
            return Value::error(ErrorCode::DivZero, 'Division by zero');
        }

        $result = match ($operator) {
            '+' => Arith::add($a, $b),
            '-' => Arith::subtract($a, $b),
            '*' => Arith::multiply($a, $b),
            '/' => Arith::divide($a, $b),
            default => Arith::percentOf($a, $b),
        };

        return $result === null
            ? Value::error(ErrorCode::Precision, 'Exact arithmetic exceeded the representable range')
            : Value::quantityOfType($type, $result);
    }

    private function relational(string $operator, Value $left, Value $right): Value
    {
        // Any relational comparison with a Null operand yields UNKNOWN. This is
        // why IS_NULL exists: `x = NULL` is not a null test.
        if ($left->isNull() || $right->isNull()) {
            return Value::null();
        }

        $order = Runtime::compare($left, $right);

        if ($order === null) {
            return Value::error(ErrorCode::Type, "Cannot compare {$left->type} with {$right->type}");
        }

        return Value::boolean(match ($operator) {
            '=' => $order === 0,
            '<>' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            default => $order >= 0,
        });
    }

    private function membership(string $operator, Value $left, Value $right): Value
    {
        if ($left->isNull() || $right->isNull()) {
            return Value::null();
        }

        $unknown = false;

        foreach ($right->elements() as $element) {
            if ($element->isError()) {
                return $element;
            }

            if ($element->isNull()) {
                $unknown = true;

                continue;
            }

            if (Runtime::compare($left, $element) === 0) {
                return Value::boolean($operator === 'IN');
            }
        }

        // A null in the list means "might have matched" — UNKNOWN, not FALSE.
        return $unknown ? Value::null() : Value::boolean($operator !== 'IN');
    }

    private function contains(string $operator, Value $left, Value $right): Value
    {
        if ($left->isNull() || $right->isNull()) {
            return Value::null();
        }

        $negated = $operator === 'NOT CONTAINS';

        if ($left->type->is(TypeKind::ListOf)) {
            $found = $this->membership('IN', $right, $left);

            return $found->isNull() ? $found : Value::boolean($found->isTrue() !== $negated);
        }

        $found = str_contains($left->string(), $right->string());

        return Value::boolean($found !== $negated);
    }

    private function matches(Binary $node, Value $subject): Value
    {
        if ($subject->isNull()) {
            return Value::null();
        }

        $pattern = $node->right instanceof Literal ? $node->right->text : null;

        if ($pattern === null) {
            return Value::error(ErrorCode::Arg, 'MATCHES requires a literal pattern');
        }

        return Sandbox::matches($pattern, $subject->string());
    }

    private function between(Between $node): Value
    {
        $value = $this->visit($node->value);

        if ($value->isError()) {
            return $value;
        }

        $low = $this->visit($node->low);

        if ($low->isError()) {
            return $low;
        }

        $high = $this->visit($node->high);

        if ($high->isError()) {
            return $high;
        }

        if ($value->isNull() || $low->isNull() || $high->isNull()) {
            return Value::null();
        }

        $fromLow = Runtime::compare($value, $low);
        $toHigh = Runtime::compare($value, $high);

        if ($fromLow === null || $toHigh === null) {
            return Value::error(ErrorCode::Type, 'BETWEEN operands are not comparable');
        }

        return Value::boolean($fromLow >= 0 && $toHigh <= 0);
    }

    /** First true branch; the ELSE is mandatory, so there is always an answer. */
    private function caseExpression(CaseExpr $node): Value
    {
        foreach ($node->whens as [$condition, $result]) {
            $value = $this->visit($condition);

            if ($value->isError()) {
                return $value;
            }

            if ($value->isTrue()) {
                return $this->visit($result);
            }
        }

        return $this->visit($node->else);
    }
}
