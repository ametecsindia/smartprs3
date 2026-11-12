<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Spx\Ast\Between;
use SmartPRS\Platform\Spx\Ast\Binary;
use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context as SpxContext;
use SmartPRS\Platform\Spx\ErrorCode;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * The production Expression: a thin adapter over the three published SPX
 * signatures, and nothing else.
 *
 *   Compiled::parse(string $source, SymbolTable $symbols) : Compiled
 *   Compiled::typeCheck()                                 : TypeCheckResult
 *   Evaluator::evaluate(Compiled $expr, Context $context, ?Sandbox) : Value
 *
 * The compiled AST is the evaluated artefact and the source is documentation
 * (R-VII-009), so this class holds both and hands the AST to the evaluator. The
 * rule engine never parses, never tokenises and never interprets — one opinion
 * about what a rule means, which is precisely what SmartPRS 2 lacks.
 *
 * ponytail: SPX owns its Context, and only the caller knows which calendars,
 * slabs and prepared aggregation results a decision point needs. The default
 * mapping below covers symbols and prepared answers; a decision point that also
 * needs a calendar or a slab table passes a closure that wraps `context()`. That
 * closure is the ONE line to change, and it is still the only one.
 */
final readonly class SpxExpression implements Expression
{
    private function __construct(
        private string $source,
        private Compiled $compiled,
        private \Closure $toSpxContext,
    ) {
    }

    /**
     * Parse and type-check at PUBLISH time, never at evaluation time (§8.3): a
     * rule that does not type-check cannot reach the engine at all.
     *
     * @param SymbolTable $symbols the decision point's table — the allow-list of
     *                             namespaces this rule may read (R-VII-006)
     * @param bool $boolean        true for a `when` or `except` clause, which
     *                             must type-check to Boolean; false for an
     *                             action parameter of any declared type
     */
    public static function parse(
        string $source,
        SymbolTable $symbols,
        bool $boolean = true,
        ?callable $toSpxContext = null,
    ): self {
        $compiled = Compiled::parse($source, $symbols);
        $check = $compiled->typeCheck();

        if ($boolean ? !$check->isBoolean() : !$check->isValid()) {
            throw new \InvalidArgumentException(
                'SPX type check failed for: ' . $source . ' — ' . $check
            );
        }

        return new self(
            $source,
            $compiled,
            $toSpxContext !== null
                ? $toSpxContext(...)
                : static fn (EvaluationContext $c): SpxContext => self::context($c, $symbols),
        );
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * The compiled artefact — the AST the evaluator runs (R-VII-009).
     *
     * The publish gates and the publisher both need it: the gates to measure and
     * inspect it, the publisher to store it. Handing out the same object all
     * three use is the point — a gate that measured a re-parse, or a publisher
     * that stored one, could disagree with what the engine will actually
     * evaluate.
     */
    public function compiled(): Compiled
    {
        return $this->compiled;
    }

    /**
     * Everything this expression will look up in the prepared context that the
     * caller must resolve first: aggregation counters and the data-backed
     * functions. A key that is not prepared becomes a named ERR_AGG_UNAVAILABLE,
     * never a query and never a zero.
     *
     * @return list<string>
     */
    public function preparedKeys(): array
    {
        return $this->compiled->preparedKeys();
    }

    public function evaluate(EvaluationContext $context, ?Sandbox $sandbox = null): Evaluated
    {
        $spx = ($this->toSpxContext)($context);
        $value = Evaluator::evaluate($this->compiled, $spx, $sandbox);
        $inputs = self::inputsRead($this->compiled->ast, $spx);
        $substituted = $this->source . self::renderInputs($inputs);

        // §5.8: an SPX error is a typed VALUE that propagates. It is never
        // caught, never absorbed and never read as false — it is handed to the
        // engine, which fails the evaluation and names the failing rule.
        if ($value->isError()) {
            return Evaluated::error(
                $value->error->value,
                $substituted . '  →  ' . $value->error->value . ' (' . $value->detail . ')',
                $this->source,
                $inputs,
            );
        }

        $plain = self::unwrap($value);

        return new Evaluated($plain, $substituted . '  →  ' . Values::render($plain), $inputs);
    }

    /**
     * §9.2's `substituted`: the leaf values this expression read, so the trace
     * shows "attendance.late_minutes = 23" and not just FALSE.
     *
     * ponytail: plain symbol paths only. A keyed accessor's context key needs
     * its arguments evaluated, and re-evaluating them here would be a second
     * evaluation of the same expression for a display string. If a trace ever
     * needs `policy.value('late_grace_min') = 10`, the place to get it is a
     * reader on the evaluator, not a second walk here.
     *
     * @return array<string, mixed>
     */
    private static function inputsRead(Node $node, SpxContext $spx): array
    {
        $inputs = [];
        self::collectInputs($node, $spx, $inputs);
        ksort($inputs);     // §7.4: the trace must be byte-identical across runs

        return $inputs;
    }

    /** @param array<string, mixed> $inputs */
    private static function collectInputs(Node $node, SpxContext $spx, array &$inputs): void
    {
        if ($node instanceof VariableRef && $node->args === null && $spx->hasSymbol($node->symbol())) {
            $read = $spx->symbol($node->symbol());
            $inputs[$node->symbol()] = $read->isError() ? $read->error->value : self::unwrap($read);
        }

        foreach ($node->children() as $child) {
            self::collectInputs($child, $spx, $inputs);
        }
    }

    /** @param array<string, mixed> $inputs */
    private static function renderInputs(array $inputs): string
    {
        if ($inputs === []) {
            return '';
        }

        $parts = [];

        foreach ($inputs as $name => $value) {
            $parts[] = $name . ' = ' . Values::render($value);
        }

        return '   [' . implode(', ', $parts) . ']';
    }

    // =======================================================================
    // The context adapter (§10.1c).
    // =======================================================================

    /**
     * `EvaluationContext` → `Spx\Context`.
     *
     * SPX requires every context entry to be an `Spx\Value` carrying its type,
     * because a bare PHP scalar has no SPX type and guessing one is exactly the
     * coercion the type system exists to prevent. The rule engine's
     * `EvaluationContext::$values` is `array<string, mixed>`, so the wrapping
     * happens HERE, once, against the DECLARED type of each symbol — never
     * against the shape of the incoming PHP value.
     *
     * Three outcomes, and no fourth:
     *   - a declared symbol whose value wraps    → a typed Value in `symbols`
     *   - a value that is already an Spx\Value   → passed through untouched,
     *     which is how an aggregation counter or a prepared answer supplies its
     *     own type (they are keyed by call, not by symbol, so nothing declares
     *     them here)
     *   - anything else                          → a NAMED error Value stored
     *     under that key. It is not dropped and it is not guessed: if a rule
     *     reads that symbol the evaluation fails with ERR_TYPE naming the key,
     *     and if no rule reads it nothing unrelated breaks.
     */
    public static function context(EvaluationContext $context, SymbolTable $symbols): SpxContext
    {
        $bound = [];
        $prepared = [];

        // §5.7 / R-VII-019: `context.evaluation_date` is the only clock, and it
        // is the subject date the caller already fixed. Supplying it here is not
        // a guess — the symbol is declared Date and this IS the date.
        if ($symbols->symbol('context.evaluation_date') !== null) {
            $bound['context.evaluation_date'] = Value::date($context->subjectDate);
        }

        foreach ($context->values as $key => $raw) {
            $symbol = $symbols->symbol(self::symbolNameOf($key));

            if ($raw instanceof Value) {
                // A caller-typed value. A declared symbol still goes to the
                // symbol bag; anything else is a prepared call answer.
                if ($symbol !== null) {
                    $bound[$key] = $raw;
                } else {
                    $prepared[$key] = $raw;
                }

                continue;
            }

            if ($symbol === null) {
                $prepared[$key] = Value::error(
                    ErrorCode::Type,
                    "'{$key}' is not a declared symbol at this decision point, so its SPX type is unknown. "
                    . 'Supply an Spx\\Value (a prepared aggregation or call answer carries its own type).',
                );

                continue;
            }

            $bound[$key] = self::wrap($key, $raw, $symbol->resultFor(self::firstKeyOf($key)));
        }

        return (new SpxContext($context->subjectDate))
            ->withSymbols($bound)
            ->withPrepared($prepared);
    }

    /** `policy.value('late_grace_min')` → `policy.value`; a plain path is itself. */
    private static function symbolNameOf(string $key): string
    {
        $open = strpos($key, '(');

        return $open === false ? $key : substr($key, 0, $open);
    }

    /**
     * The first argument of a keyed accessor, which selects the declared type
     * (`policy.value('late_grace_min')` is Minutes, `…('ot_enabled')` Boolean).
     *
     * ponytail: single-quoted first argument only, which is every keyed symbol
     * whose type is narrowed by key. A date- or number-keyed accessor falls back
     * to the symbol's own type, which is what `Symbol::resultFor(null)` returns.
     */
    private static function firstKeyOf(string $key): ?string
    {
        return preg_match("/^[^(]+\\('([^']*)'/", $key, $m) === 1 ? $m[1] : null;
    }

    /**
     * Wrap one PHP value as the DECLARED type. Every branch is a widening that
     * loses nothing; a float is refused outright because a float has no exact
     * value and Law 7 forbids one anywhere near money or a unit quantity.
     */
    private static function wrap(string $key, mixed $raw, Type $type): Value
    {
        if ($raw === null) {
            return Value::null();
        }

        if (is_float($raw)) {
            return self::mistyped($key, $type, $raw, 'a float has no exact value; '
                . 'supply an int, a Support\\Rational or a decimal string');
        }

        $value = match ($type->kind) {
            TypeKind::Number => self::exact($raw) === null ? null : Value::number(self::exact($raw)),
            TypeKind::Percentage => self::exact($raw) === null ? null : Value::percentage(self::exact($raw)),
            TypeKind::Money => self::money($raw, $type),
            TypeKind::Days, TypeKind::DayFraction, TypeKind::Hours,
            TypeKind::Minutes, TypeKind::Duration, TypeKind::Rate
                => self::exact($raw) === null ? null : Value::quantityOfType($type, self::exact($raw)),
            TypeKind::Boolean => is_bool($raw) ? Value::boolean($raw) : null,
            TypeKind::Text => is_string($raw) ? Value::text($raw) : null,
            TypeKind::Code => is_string($raw) ? Value::code($raw, $type->parameter) : null,
            TypeKind::Date => self::date($raw, false),
            TypeKind::DateTime => self::date($raw, true),
            TypeKind::Time => self::time($raw),
            TypeKind::ListOf => self::list($key, $raw, $type),
            TypeKind::Null => Value::null(),
        };

        return $value ?? self::mistyped($key, $type, $raw, 'no declared wrapping exists for a '
            . get_debug_type($raw));
    }

    private static function exact(mixed $raw): ?Rational
    {
        return match (true) {
            $raw instanceof Rational => $raw,
            is_int($raw) => Rational::of($raw),
            is_string($raw) && preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1 => Rational::fromDecimalString($raw),
            default => null,
        };
    }

    private static function money(mixed $raw, Type $type): ?Value
    {
        if ($raw instanceof Money) {
            return Value::money($raw);
        }

        $exact = self::exact($raw);

        // An int is MINOR units (paise), which is how Money is stored everywhere
        // in SmartPRS 3 (Law 7). There is no rupees-as-int reading.
        return $exact === null ? null : Value::moneyOfMinor($exact, $type->parameter ?? 'INR');
    }

    private static function date(mixed $raw, bool $withTime): ?Value
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $withTime ? Value::dateTime($raw) : Value::date($raw);
        }

        if ($raw instanceof \DateTimeInterface) {
            $immutable = \DateTimeImmutable::createFromInterface($raw);

            return $withTime ? Value::dateTime($immutable) : Value::date($immutable);
        }

        if (!is_string($raw)) {
            return null;
        }

        // Exact formats only, round-trip checked. `new DateTimeImmutable($s)`
        // would happily read 'next friday', '31-02-2026' and '' — a context
        // entry that is nearly a date must be a named ERR_TYPE upstream, never a
        // silently wrong date, and there is no clock here to resolve 'next' with.
        $formats = $withTime ? ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] : ['Y-m-d'];

        foreach ($formats as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw, new \DateTimeZone('UTC'));

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $withTime ? Value::dateTime($parsed) : Value::date($parsed);
            }
        }

        return null;
    }

    private static function time(mixed $raw): ?Value
    {
        if (is_int($raw)) {
            return Value::time($raw);
        }

        if (!is_string($raw) || preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m) !== 1) {
            return null;
        }

        return Value::time((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0));
    }

    private static function list(string $key, mixed $raw, Type $type): ?Value
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $element = $type->element ?? Type::null();
        $elements = [];

        foreach ($raw as $index => $item) {
            $wrapped = $item instanceof Value ? $item : self::wrap($key . "[{$index}]", $item, $element);

            if ($wrapped->isError()) {
                return $wrapped;
            }

            $elements[] = $wrapped;
        }

        return Value::list($elements, $element);
    }

    private static function mistyped(string $key, Type $type, mixed $raw, string $why): Value
    {
        return Value::error(
            ErrorCode::Type,
            "Context entry '{$key}' is declared {$type} and cannot be wrapped from "
            . get_debug_type($raw) . ': ' . $why,
        );
    }

    // =======================================================================
    // The claim reader (§11.4).
    // =======================================================================

    /**
     * Read the type-checked AST as a conjunction of atomic constraints, for the
     * publish-time satisfiability check.
     *
     * The fragment is exactly what `Satisfiability` models: AND, the six
     * relational operators, BETWEEN, and IN/NOT IN over a list of literals,
     * where one side is a plain symbol and the other is a literal. `TRUE`
     * asserts nothing, so it reads as the empty conjunction.
     *
     * Anything else — an OR, a NOT, an `agg.*` call, a MATCHES, arithmetic, a
     * keyed accessor whose key is computed — answers NULL, and the detector then
     * treats the pair as conflicting rather than assuming a disjointness it
     * cannot prove. R-VII-060 forbids the false negative and permits the false
     * positive, which costs one `priority` keystroke.
     *
     * @return list<Claim>|null
     */
    public function claims(): ?array
    {
        return self::claimsOf($this->compiled->ast);
    }

    /** @return list<Claim>|null */
    private static function claimsOf(Node $node): ?array
    {
        if ($node instanceof Literal && $node->literal === LiteralKind::Boolean) {
            // `TRUE` constrains nothing; `FALSE` never matches, and pretending it
            // is unconstrained would be the false negative R-VII-060 forbids.
            return strtoupper($node->text) === 'TRUE' ? [] : null;
        }

        if ($node instanceof Binary && $node->operator === 'AND') {
            $left = self::claimsOf($node->left);
            $right = self::claimsOf($node->right);

            return $left === null || $right === null ? null : [...$left, ...$right];
        }

        // A bare symbol in a claims position is Boolean — the type checker has
        // already refused anything else in a `when` or under an AND — so
        // `day.is_weekly_off` asserts that it is TRUE.
        if ($node instanceof VariableRef) {
            $symbol = self::symbolOf($node);

            return $symbol === null ? null : [new Claim($symbol, 'in', ['TRUE'])];
        }

        if ($node instanceof Between) {
            $symbol = self::symbolOf($node->value);
            $low = self::integerOf($node->low);
            $high = self::integerOf($node->high);

            return $symbol === null || $low === null || $high === null
                ? null
                : Claim::between($symbol, $low, $high);
        }

        return $node instanceof Binary ? self::comparisonClaim($node) : null;
    }

    /** @return list<Claim>|null */
    private static function comparisonClaim(Binary $node): ?array
    {
        if (in_array($node->operator, ['IN', 'NOT IN'], true)) {
            $symbol = self::symbolOf($node->left);
            $members = self::membersOf($node->right);

            return $symbol === null || $members === null
                ? null
                : [new Claim($symbol, $node->operator === 'IN' ? 'in' : 'not_in', $members)];
        }

        if (!in_array($node->operator, Claim::NUMERIC, true)) {
            return null;    // arithmetic, CONTAINS, MATCHES — outside the fragment
        }

        // The symbol may sit on either side; `30 < x` is `x > 30`.
        $symbol = self::symbolOf($node->left);
        $operand = $node->right;
        $operator = $node->operator;

        if ($symbol === null) {
            $symbol = self::symbolOf($node->right);
            $operand = $node->left;
            $operator = self::mirror($node->operator);
        }

        if ($symbol === null) {
            return null;
        }

        $integer = self::integerOf($operand);

        if ($integer !== null) {
            return [new Claim($symbol, $operator, $integer)];
        }

        // Equality on a Code, a Text or a Boolean literal, which the solver
        // models as a one-member set.
        $member = match (true) {
            !$operand instanceof Literal => null,
            $operand->literal === LiteralKind::Text => $operand->text,
            $operand->literal === LiteralKind::Boolean => strtoupper($operand->text),
            default => null,
        };

        return $member !== null && in_array($operator, ['=', '<>'], true)
            ? [new Claim($symbol, $operator === '=' ? 'in' : 'not_in', [$member])]
            : null;
    }

    private static function mirror(string $operator): string
    {
        return match ($operator) {
            '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<=',
            default => $operator,
        };
    }

    /** A plain, unkeyed symbol path — the only thing the solver has a variable for. */
    private static function symbolOf(Node $node): ?string
    {
        return $node instanceof VariableRef && $node->args === null ? $node->symbol() : null;
    }

    /**
     * An exact integer literal. Minutes, days, counts and money-in-paise are all
     * integers (Law 7), and the solver is integer-only, so a fractional literal
     * takes the whole expression out of the fragment rather than being rounded.
     */
    private static function integerOf(Node $node): ?int
    {
        if (!$node instanceof Literal
            || !in_array($node->literal, [LiteralKind::Number, LiteralKind::Duration, LiteralKind::Money], true)
        ) {
            return null;
        }

        $exact = $node->literal === LiteralKind::Money
            ? Money::of($node->text)->minor
            : Rational::fromDecimalString($node->text);

        if (is_int($exact)) {
            return $exact;
        }

        return $exact->denominator === 1 ? $exact->numerator : null;
    }

    /**
     * The members of an `IN` list, when every element is a text literal.
     *
     * @return list<string>|null
     */
    private static function membersOf(Node $node): ?array
    {
        if (!$node instanceof ListLiteral) {
            return null;    // `IN policy.value('…')` is a runtime list; not decidable here
        }

        $members = [];

        foreach ($node->elements as $element) {
            if (!$element instanceof Literal || $element->literal !== LiteralKind::Text) {
                return null;
            }

            $members[] = $element->text;
        }

        return $members;
    }

    /** Spx\Value → a PHP value the trace and the fingerprint can render exactly. */
    private static function unwrap(Value $value): mixed
    {
        if ($value->isNull()) {
            return null;
        }

        return is_array($value->payload)
            ? array_map(self::unwrap(...), $value->payload)
            : $value->payload;
    }
}
