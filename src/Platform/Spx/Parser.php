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

/**
 * SPX parser — recursive descent over the EBNF of Part VII §5.3, with one
 * function per precedence level of the §5.5 table, lowest first:
 *
 *   9 OR · 8 AND · 7 NOT · 6 comparison (non-associative) · 5 + - · 4 * /
 *   3 % (percent-of) · 2 unary - · 1 primary, call, keyed accessor
 *
 * The shape of the file IS the precedence table, which is the point: a
 * precedence bug in a table-driven parser is invisible, and a precedence bug in
 * a payroll rule is money.
 *
 * `%` is percent-of, not modulo (spine S5.2). `X % 40` is forty percent of X.
 * Modulo is `MOD(a, b)`.
 *
 * There is no error recovery. Parsing produces one AST or one SpxSyntaxError
 * naming a line and a column.
 */
final class Parser
{
    /** Part VII §5.2/§7.7. A longer list is rejected at publish, not truncated. */
    public const MAX_LIST_ELEMENTS = 500;

    /** Part VII §5.3 agg_fn. Closed set. */
    public const AGG_FUNCTIONS = [
        'count', 'sum', 'nth', 'occurrence_index', 'collapse_repeats', 'streak', 'balance',
    ];

    /** Part VII §5.3 window_spec. `windows with arguments` take a parenthesised tail. */
    public const WINDOWS = [
        'day' => 0,
        'payroll_period' => 0,
        'calendar_month' => 0,
        'quarter' => 0,
        'financial_year' => 0,
        'service_year' => 0,
        'week' => 1,
        'rolling' => 2,
        'since' => 1,
    ];

    /**
     * A hard nesting limit inside the parser itself, well above the publish-time
     * AST depth limit of 32. Recursive descent uses the PHP stack, so a hostile
     * 100,000-deep parenthesis string must be refused BEFORE it is descended —
     * a segfault is not a named failure.
     */
    public const MAX_NESTING = 128;

    private int $position = 0;

    private int $nesting = 0;

    /** @param list<Token> $tokens */
    private function __construct(private readonly array $tokens)
    {
    }

    /** @throws SpxSyntaxError */
    public static function parse(string $source): Node
    {
        $parser = new self(Lexer::tokenise($source));
        $expression = $parser->expression();

        if (!$parser->peek()->is(TokenKind::End)) {
            $parser->fail('Unexpected ' . $parser->peek() . ' after the end of the expression');
        }

        return $expression;
    }

    // ---- level 9: OR ------------------------------------------------------

    private function expression(): Node
    {
        $left = $this->andExpr();

        while ($this->peek()->is(TokenKind::Keyword, 'OR')) {
            $token = $this->take();
            $left = new Binary('OR', $left, $this->andExpr(), $token->line, $token->column);
        }

        return $left;
    }

    // ---- level 8: AND -----------------------------------------------------

    private function andExpr(): Node
    {
        $left = $this->notExpr();

        while ($this->peek()->is(TokenKind::Keyword, 'AND')) {
            $token = $this->take();
            $left = new Binary('AND', $left, $this->notExpr(), $token->line, $token->column);
        }

        return $left;
    }

    // ---- level 7: NOT (right-associative) ---------------------------------

    private function notExpr(): Node
    {
        if ($this->peek()->is(TokenKind::Keyword, 'NOT')) {
            $token = $this->take();

            return new Unary('NOT', $this->notExpr(), $token->line, $token->column);
        }

        return $this->comparison();
    }

    // ---- level 6: comparison (NON-associative, R-VII-016) -----------------

    private function comparison(): Node
    {
        $left = $this->additive();
        $node = $this->comparisonTail($left);

        if ($node === null) {
            return $left;
        }

        // `a < b < c` is a syntax error, not a silent truth.
        if ($this->comparisonAhead()) {
            $this->fail(
                'Comparison operators are non-associative: '
                . $this->peek() . ' cannot follow another comparison. Use AND with parentheses'
            );
        }

        return $node;
    }

    private function comparisonTail(Node $left): ?Node
    {
        $token = $this->peek();

        if ($token->kind === TokenKind::Operator && in_array($token->text, Binary::RELATIONAL, true)) {
            $this->take();

            return new Binary($token->text, $left, $this->additive(), $token->line, $token->column);
        }

        if ($token->is(TokenKind::Keyword, 'NOT')) {
            $next = $this->peek(1);

            if ($next->is(TokenKind::Keyword, 'IN')) {
                $this->take();
                $this->take();

                return new Binary('NOT IN', $left, $this->listOperand(), $token->line, $token->column);
            }

            if ($next->is(TokenKind::Keyword, 'CONTAINS')) {
                $this->take();
                $this->take();

                return new Binary('NOT CONTAINS', $left, $this->additive(), $token->line, $token->column);
            }

            return null;    // a trailing NOT belongs to an enclosing production
        }

        if ($token->is(TokenKind::Keyword, 'IN')) {
            $this->take();

            return new Binary('IN', $left, $this->listOperand(), $token->line, $token->column);
        }

        if ($token->is(TokenKind::Keyword, 'CONTAINS')) {
            $this->take();

            return new Binary('CONTAINS', $left, $this->additive(), $token->line, $token->column);
        }

        if ($token->is(TokenKind::Keyword, 'MATCHES')) {
            $this->take();
            $pattern = $this->peek();

            // R-VII-012: a literal pattern only, so it is known and lintable at publish.
            if (!$pattern->is(TokenKind::StringLit)) {
                $this->fail('MATCHES requires a string literal pattern, not a computed expression');
            }

            $this->take();

            return new Binary(
                'MATCHES',
                $left,
                new Literal(LiteralKind::Text, $pattern->text, null, $pattern->line, $pattern->column),
                $token->line,
                $token->column,
            );
        }

        if ($token->is(TokenKind::Keyword, 'BETWEEN')) {
            $this->take();
            $low = $this->additive();
            $this->expectKeyword('AND');
            $high = $this->additive();

            return new Between($left, $low, $high, $token->line, $token->column);
        }

        return null;
    }

    private function comparisonAhead(): bool
    {
        $token = $this->peek();

        if ($token->kind === TokenKind::Operator && in_array($token->text, Binary::RELATIONAL, true)) {
            return true;
        }

        if ($token->kind !== TokenKind::Keyword) {
            return false;
        }

        if (in_array($token->text, ['IN', 'CONTAINS', 'MATCHES', 'BETWEEN'], true)) {
            return true;
        }

        return $token->text === 'NOT'
            && in_array($this->peek(1)->text, ['IN', 'CONTAINS'], true);
    }

    /** `IN` takes a list, never a scalar: `x IN 'HYD'` is an error, `x IN ['HYD']` is not. */
    private function listOperand(): Node
    {
        $token = $this->peek();
        $operand = $this->primary();

        if ($operand instanceof ListLiteral || $operand instanceof VariableRef || $operand instanceof FunctionCall) {
            return $operand;
        }

        throw new SpxSyntaxError(
            'The right operand of IN must be a list literal, a variable or a function call; '
            . 'write IN [\'X\'] rather than IN \'X\'',
            $token->line,
            $token->column,
        );
    }

    // ---- level 5: + - -----------------------------------------------------

    private function additive(): Node
    {
        $left = $this->multiplicative();

        while ($this->peek()->kind === TokenKind::Operator
            && in_array($this->peek()->text, ['+', '-'], true)
        ) {
            $token = $this->take();
            $left = new Binary($token->text, $left, $this->multiplicative(), $token->line, $token->column);
        }

        return $left;
    }

    // ---- level 4: * / -----------------------------------------------------

    private function multiplicative(): Node
    {
        $left = $this->percent();

        while ($this->peek()->kind === TokenKind::Operator
            && in_array($this->peek()->text, ['*', '/'], true)
        ) {
            $token = $this->take();
            $left = new Binary($token->text, $left, $this->percent(), $token->line, $token->column);
        }

        return $left;
    }

    // ---- level 3: % (percent-of, binds tighter than * and /) --------------

    private function percent(): Node
    {
        $left = $this->unary();

        while ($this->peek()->is(TokenKind::Operator, '%')) {
            $token = $this->take();
            $left = new Binary('%', $left, $this->unary(), $token->line, $token->column);
        }

        return $left;
    }

    // ---- level 2: unary - (right-associative) -----------------------------

    private function unary(): Node
    {
        if ($this->peek()->is(TokenKind::Operator, '-')) {
            $token = $this->take();

            return new Unary('-', $this->unary(), $token->line, $token->column);
        }

        return $this->primary();
    }

    // ---- level 1: primaries -----------------------------------------------

    private function primary(): Node
    {
        if (++$this->nesting > self::MAX_NESTING) {
            $this->fail('Expression nests deeper than ' . self::MAX_NESTING . ' levels');
        }

        try {
            return $this->primaryInner();
        } finally {
            $this->nesting--;
        }
    }

    private function primaryInner(): Node
    {
        $token = $this->peek();

        return match (true) {
            $token->kind === TokenKind::Number => $this->literal(LiteralKind::Number),
            $token->kind === TokenKind::StringLit => $this->literal(LiteralKind::Text),
            $token->kind === TokenKind::DateLit => $this->literal(LiteralKind::Date),
            $token->kind === TokenKind::TimeLit => $this->literal(LiteralKind::Time),
            $token->kind === TokenKind::MoneyLit => $this->literal(LiteralKind::Money),
            $token->kind === TokenKind::DurationLit => $this->durationLiteral(),
            $token->is(TokenKind::Keyword, 'TRUE'), $token->is(TokenKind::Keyword, 'FALSE')
                => $this->literal(LiteralKind::Boolean),
            $token->is(TokenKind::Keyword, 'NULL') => $this->literal(LiteralKind::Null),
            $token->is(TokenKind::Keyword, 'CASE') => $this->caseExpression(),
            $token->is(TokenKind::Punctuation, '(') => $this->group(),
            $token->is(TokenKind::Punctuation, '[') => $this->listLiteral(),
            $token->kind === TokenKind::Identifier => $this->identifierExpression(),
            default => $this->fail('Expected a value but found ' . $token),
        };
    }

    private function literal(LiteralKind $kind): Literal
    {
        $token = $this->take();

        return new Literal($kind, $token->text, null, $token->line, $token->column);
    }

    private function durationLiteral(): Literal
    {
        $token = $this->take();

        return new Literal(
            LiteralKind::Duration,
            substr($token->text, 0, -1),
            substr($token->text, -1),
            $token->line,
            $token->column,
        );
    }

    private function group(): Node
    {
        $this->take();
        $inner = $this->expression();
        $this->expectPunctuation(')');

        return $inner;
    }

    private function listLiteral(): ListLiteral
    {
        $open = $this->take();
        $elements = [];

        if (!$this->peek()->is(TokenKind::Punctuation, ']')) {
            do {
                $elements[] = $this->expression();
            } while ($this->consumePunctuation(','));
        }

        $this->expectPunctuation(']');

        if (count($elements) > self::MAX_LIST_ELEMENTS) {
            throw new SpxSyntaxError(
                'A list literal may hold at most ' . self::MAX_LIST_ELEMENTS . ' elements',
                $open->line,
                $open->column,
            );
        }

        return new ListLiteral($elements, $open->line, $open->column);
    }

    /** R-VII-011: the ELSE is mandatory. There is no implicit null result. */
    private function caseExpression(): CaseExpr
    {
        $open = $this->take();
        $whens = [];

        while ($this->peek()->is(TokenKind::Keyword, 'WHEN')) {
            $this->take();
            $condition = $this->expression();
            $this->expectKeyword('THEN');
            $whens[] = [$condition, $this->expression()];
        }

        if ($whens === []) {
            $this->fail('CASE requires at least one WHEN … THEN branch');
        }

        if (!$this->peek()->is(TokenKind::Keyword, 'ELSE')) {
            $this->fail('CASE requires an ELSE branch (R-VII-011): there is no implicit null result');
        }

        $this->take();
        $else = $this->expression();
        $this->expectKeyword('END');

        return new CaseExpr($whens, $else, $open->line, $open->column);
    }

    /** A function call, an `agg.*` call, or a namespaced variable reference. */
    private function identifierExpression(): Node
    {
        $token = $this->peek();

        if ($this->peek(1)->is(TokenKind::Punctuation, '(')) {
            $this->take();
            $args = $this->argumentList();

            // Function names are case-insensitive; the catalogue is keyed upper-case.
            return new FunctionCall(strtoupper($token->text), $args, $token->line, $token->column);
        }

        if ($this->peek(1)->is(TokenKind::Punctuation, '.')) {
            return $token->text === 'agg' ? $this->aggCall() : $this->variableRef();
        }

        return $this->fail(
            "'{$token->text}' is not a value. A variable is namespaced (employee.code) "
            . 'and a function is called (MIN(a, b))'
        );
    }

    /**
     * `namespace . symbol_path`, with an optional keyed accessor tail.
     *
     * Part VII §5.3's `key_arg ::= string_lit` cannot express the two-argument
     * accessors its own §5.7 declares (`org.is_in(dimension, unit)`,
     * `credential.is_valid(type, as_of)`, `leave.on_date(d)`), so this accepts an
     * argument list. Safety is unaffected: the accessors take codes and dates,
     * and no accessor anywhere in the catalogue takes a subject.
     */
    private function variableRef(): VariableRef
    {
        $namespace = $this->take();
        $path = [];
        $args = null;

        do {
            $this->expectPunctuation('.');
            $path[] = $this->expectIdentifier();

            if ($this->peek()->is(TokenKind::Punctuation, '(')) {
                $args = $this->argumentList();

                break;
            }
        } while ($this->peek()->is(TokenKind::Punctuation, '.'));

        return new VariableRef($namespace->text, $path, $args, $namespace->line, $namespace->column);
    }

    private function aggCall(): AggCall
    {
        $agg = $this->take();
        $this->expectPunctuation('.');
        $name = $this->expectIdentifier();

        if (!in_array($name, self::AGG_FUNCTIONS, true)) {
            $this->fail("Unknown aggregation 'agg.{$name}'; the set is " . implode(', ', self::AGG_FUNCTIONS));
        }

        $this->expectPunctuation('(');
        $args = [];

        if (!$this->peek()->is(TokenKind::Punctuation, ')')) {
            do {
                $args[] = $this->aggArgument();
            } while ($this->consumePunctuation(','));
        }

        $this->expectPunctuation(')');

        return new AggCall($name, $args, $agg->line, $agg->column);
    }

    /** An expression, a window spec, a bare event/measure code, or a predicate block. */
    private function aggArgument(): Node
    {
        $token = $this->peek();

        if ($token->is(TokenKind::Punctuation, '{')) {
            $this->take();
            $expression = $this->expression();
            $this->expectPunctuation('}');

            return new PredicateBlock($expression, $token->line, $token->column);
        }

        if ($token->kind === TokenKind::Identifier && isset(self::WINDOWS[$token->text])) {
            return $this->windowSpec();
        }

        // A bare identifier in an agg argument is an event, measure or ledger
        // CODE — the form the rule library uses (§12, RL-ATT-001).
        if ($token->kind === TokenKind::Identifier && !$this->peek(1)->is(TokenKind::Punctuation, '.')) {
            $this->take();

            return new Literal(LiteralKind::Text, $token->text, null, $token->line, $token->column);
        }

        return $this->expression();
    }

    private function windowSpec(): WindowSpec
    {
        $token = $this->take();
        $arity = self::WINDOWS[$token->text];
        $args = [];

        if ($arity > 0) {
            $this->expectPunctuation('(');

            do {
                $inner = $this->peek();

                // `rolling(30, days)` — the unit is a bare word, not a variable.
                if ($inner->kind === TokenKind::Identifier && !$this->peek(1)->is(TokenKind::Punctuation, '.')) {
                    $this->take();
                    $args[] = new Literal(LiteralKind::Text, $inner->text, null, $inner->line, $inner->column);

                    continue;
                }

                $args[] = $this->expression();
            } while ($this->consumePunctuation(','));

            $this->expectPunctuation(')');
        }

        if (count($args) !== $arity) {
            throw new SpxSyntaxError(
                "Window '{$token->text}' takes {$arity} argument(s), " . count($args) . ' given',
                $token->line,
                $token->column,
            );
        }

        return new WindowSpec($token->text, $args, $token->line, $token->column);
    }

    /** @return list<Node> */
    private function argumentList(): array
    {
        $this->expectPunctuation('(');
        $args = [];

        if (!$this->peek()->is(TokenKind::Punctuation, ')')) {
            do {
                $args[] = $this->expression();
            } while ($this->consumePunctuation(','));
        }

        $this->expectPunctuation(')');

        return $args;
    }

    // ---- token plumbing ----------------------------------------------------

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[$this->position + $ahead] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function take(): Token
    {
        $token = $this->peek();

        if ($token->kind !== TokenKind::End) {
            $this->position++;
        }

        return $token;
    }

    private function consumePunctuation(string $text): bool
    {
        if ($this->peek()->is(TokenKind::Punctuation, $text)) {
            $this->take();

            return true;
        }

        return false;
    }

    private function expectPunctuation(string $text): void
    {
        if (!$this->peek()->is(TokenKind::Punctuation, $text)) {
            $this->fail("Expected '{$text}' but found " . $this->peek());
        }

        $this->take();
    }

    private function expectKeyword(string $keyword): void
    {
        if (!$this->peek()->is(TokenKind::Keyword, $keyword)) {
            $this->fail("Expected {$keyword} but found " . $this->peek());
        }

        $this->take();
    }

    private function expectIdentifier(): string
    {
        $token = $this->peek();

        if ($token->kind !== TokenKind::Identifier) {
            $this->fail('Expected a name but found ' . $token);
        }

        $this->take();

        return $token->text;
    }

    private function fail(string $reason): never
    {
        throw new SpxSyntaxError($reason, $this->peek()->line, $this->peek()->column);
    }
}
