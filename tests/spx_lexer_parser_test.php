<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Ast\Between;
use SmartPRS\Platform\Spx\Ast\Binary;
use SmartPRS\Platform\Spx\Ast\CaseExpr;
use SmartPRS\Platform\Spx\Ast\FunctionCall;
use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Ast\Unary;
use SmartPRS\Platform\Spx\Ast\VariableRef;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Lexer;
use SmartPRS\Platform\Spx\Parser;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\TokenKind;

group('SPX lexer and parser');

// --- lexer ------------------------------------------------------------------

/** @return list<array{0: string, 1: string}> kind name + text, without the End token */
function lex(string $source): array
{
    $tokens = Lexer::tokenise($source);
    array_pop($tokens);

    return array_map(static fn ($t): array => [$t->kind->name, $t->text], $tokens);
}

same([['Identifier', 'employee'], ['Punctuation', '.'], ['Identifier', 'code']], lex('employee.code'), 'a namespaced path lexes as three tokens');
same([['Number', '12'], ['Operator', '+'], ['Number', '0.25']], lex('12 + 0.25'), 'decimal numbers');
same([['StringLit', "O'Brien"]], lex("'O''Brien'"), "'' inside a string is one literal quote");
same([['DateLit', '2026-08-12']], lex('#2026-08-12#'), 'date literal');
same([['TimeLit', '09:30:00']], lex('#09:30#'), 'time literal normalises to HH:MM:SS');
same([['TimeLit', '09:30:15']], lex('#09:30:15#'), 'time literal with seconds');
same([['MoneyLit', '150000.00']], lex('₹1,50,000.00'), 'money literal strips Indian grouping');
same([['DurationLit', '0.5d'], ['DurationLit', '8.5h'], ['DurationLit', '90m'], ['DurationLit', '45s']], lex('0.5d 8.5h 90m 45s'), 'duration literals for all four units');
same([['Keyword', 'AND'], ['Keyword', 'AND'], ['Keyword', 'NOT']], lex('AND and Not'), 'keywords are case-insensitive and normalise to upper case');
same([['Identifier', 'basicPct'], ['Identifier', 'basicpct']], lex('basicPct basicpct'), 'identifiers are case-sensitive');
same([['Number', '1']], lex('1 -- a comment to end of line'), 'a -- comment runs to the end of the line');
same([['Number', '1'], ['Operator', '+'], ['Number', '2']], lex("1 -- comment\n + 2"), 'a comment does not eat the next line');
same([['Operator', '<>'], ['Operator', '<='], ['Operator', '>=']], lex('<> <= >='), 'two-character operators are not split');

// positions: line and column on every token
$tokens = Lexer::tokenise("employee.code\n  = 'X'");
same(1, $tokens[0]->line, 'first token is on line 1');
same(1, $tokens[0]->column, 'first token is at column 1');
same(2, $tokens[3]->line, 'the operator on the second line reports line 2');
same(3, $tokens[3]->column, 'the operator on the second line reports column 3');

// a multi-byte rupee sign must not skew the column of what follows
$tokens = Lexer::tokenise('₹100.00 + 1');
same(9, $tokens[1]->column, 'the ₹ counts as one column, not three bytes');

// --- lexical errors, each naming its position -------------------------------

/** @return array{0: string, 1: int, 2: int} */
function syntaxError(callable $parse): array
{
    try {
        $parse();
    } catch (SpxSyntaxError $e) {
        return [$e->reason, $e->sourceLine, $e->sourceColumn];
    }

    return ['(nothing was thrown)', 0, 0];
}

[$reason, $line, $column] = syntaxError(static fn () => Lexer::tokenise("1 +\n  'unterminated"));
ok(str_contains($reason, 'Unterminated string'), 'an unterminated string is an error');
same(2, $line, 'the unterminated string names its line');
same(3, $column, 'the unterminated string names its column');

[$reason, , $column] = syntaxError(static fn () => Lexer::tokenise('#2026-02-30#'));
ok(str_contains($reason, 'Not a calendar date'), '30 February is not a date');
same(1, $column, 'the bad date names its column');

[$reason] = syntaxError(static fn () => Lexer::tokenise('#25:00#'));
ok(str_contains($reason, 'Not a wall-clock time'), '25:00 is not a time');

[$reason] = syntaxError(static fn () => Lexer::tokenise('#2026-08#'));
ok(str_contains($reason, 'Malformed date or time'), 'a half-written date literal is named as such');

[$reason, , $column] = syntaxError(static fn () => Lexer::tokenise('1 + 2 ~ 3'));
ok(str_contains($reason, "Unexpected character '~'"), 'an unknown character is rejected');
same(7, $column, 'the unknown character names its column');

[$reason] = syntaxError(static fn () => Lexer::tokenise('1.1234567890'));
ok(str_contains($reason, 'exceeds scale 9'), 'a number literal beyond scale 9 is rejected');

[$reason] = syntaxError(static fn () => Lexer::tokenise('₹1,50,000.5'));
ok(str_contains($reason, 'Malformed money literal'), 'money needs exactly two paise digits');

[$reason] = syntaxError(static fn () => Lexer::tokenise(str_repeat('a', 8001)));
ok(str_contains($reason, 'maximum source length'), 'source longer than 8,000 characters is refused');

[$reason] = syntaxError(static fn () => Lexer::tokenise("'" . str_repeat('x', 513) . "'"));
ok(str_contains($reason, 'exceeds 512 characters'), 'a string literal is bounded to 512 characters');

// --- precedence, one level at a time ----------------------------------------
// Each case is an expression whose VALUE differs if the precedence is wrong.

function evaluateNumber(string $source): string
{
    // The test file is required inside a closure, so a top-level $var is not a
    // global; build the table here rather than reaching for one.
    static $symbols = null;
    $symbols ??= SymbolTable::forNamespaces('context');

    $compiled = Compiled::parse($source, $symbols);
    $result = $compiled->typeCheck();

    if (!$result->isValid()) {
        return 'TYPE ERROR: ' . implode('; ', $result->messages());
    }

    return Evaluator::evaluate($compiled, new Context(new DateTimeImmutable('2026-08-01')))->describe();
}

// level 5 (+ -) is looser than level 4 (* /)
same('7', evaluateNumber('1 + 2 * 3'), 'multiplication binds tighter than addition');
same('9', evaluateNumber('(1 + 2) * 3'), 'parentheses override it');

// level 4 (* /) is left-associative
same('2', evaluateNumber('12 / 3 / 2'), '/ is left-associative: (12/3)/2 = 2, not 8');
same('1', evaluateNumber('10 - 5 - 4'), '- is left-associative: (10-5)-4 = 1, not 9');

// level 3 (%) binds TIGHTER than * and /, and is percent-of, not modulo
same('100', evaluateNumber('100 / 2 % 50'), '% binds tighter than /: 100 / (50% of 2) = 100');
same('50', evaluateNumber('(100 / 2) % 100'), 'with parentheses it is 100 percent of 50');
same('20', evaluateNumber('50 % 40'), '50 % 40 is forty percent of fifty — percent-of, not modulo');
same('10', evaluateNumber('MOD(50, 40)'), 'modulo is MOD(a, b), and 50 mod 40 is 10');
same('30', evaluateNumber('100 % 20 + 10'), 'percent-of binds tighter than +');

// level 2 (unary -) right-associative, tighter than %
same('-20', evaluateNumber('-(50 % 40)'), 'unary minus over a percent-of');
same('5', evaluateNumber('- -5'), 'unary minus is right-associative and nests');

// level 6 comparison, looser than arithmetic
same('TRUE', evaluateNumber('1 + 1 = 2'), 'arithmetic binds tighter than =');

// level 7 NOT, right-associative and looser than comparison
same('FALSE', evaluateNumber('NOT 1 = 1'), 'NOT applies to the whole comparison, not to 1');
same('TRUE', evaluateNumber('NOT NOT TRUE'), 'NOT is right-associative and nests');

// level 8 AND is tighter than level 9 OR
same('TRUE', evaluateNumber('TRUE OR FALSE AND FALSE'), 'AND binds tighter than OR: TRUE OR (FALSE AND FALSE)');
same('FALSE', evaluateNumber('(TRUE OR FALSE) AND FALSE'), 'parentheses override AND/OR precedence');
same('TRUE', evaluateNumber('FALSE AND FALSE OR TRUE'), 'AND on the left of OR');

// --- comparison is non-associative (R-VII-016) ------------------------------

[$reason, , $column] = syntaxError(static fn () => Parser::parse('1 < 2 < 3'));
ok(str_contains($reason, 'non-associative'), 'a < b < c is a syntax error, not a silent truth');
same(7, $column, 'the second comparison operator names its column');

[$reason] = syntaxError(static fn () => Parser::parse("employee.code = 'A' = 'B'"));
ok(str_contains($reason, 'non-associative'), 'chained equality is refused too');

ok(Parser::parse('1 < 2 AND 2 < 3') instanceof Binary, 'the same intent with AND parses');

// --- deeply nested boolean groups -------------------------------------------

$nested = '(TRUE AND (FALSE OR (TRUE AND (FALSE OR (TRUE AND (FALSE OR TRUE))))))';
same('TRUE', evaluateNumber($nested), 'boolean groups nest to arbitrary depth');

$deep = str_repeat('(', 20) . 'TRUE' . str_repeat(')', 20);
ok(Parser::parse($deep) instanceof Literal, 'twenty levels of parentheses parse to the literal inside');

// --- the operator set --------------------------------------------------------

$ast = Parser::parse("org.branch IN ['HYD','BLR']");
ok($ast instanceof Binary && $ast->operator === 'IN', 'IN parses');
ok($ast->right instanceof ListLiteral && count($ast->right->elements) === 2, 'its right operand is a list');

$ast = Parser::parse("org.branch NOT IN ['HYD']");
ok($ast instanceof Binary && $ast->operator === 'NOT IN', 'NOT IN parses as one operator');

$ast = Parser::parse('service.years BETWEEN 1 AND 5');
ok($ast instanceof Between, 'BETWEEN parses to its own three-operand node');

$ast = Parser::parse("employee.code CONTAINS 'X'");
ok($ast instanceof Binary && $ast->operator === 'CONTAINS', 'CONTAINS parses');

$ast = Parser::parse("employee.code NOT CONTAINS 'X'");
ok($ast instanceof Binary && $ast->operator === 'NOT CONTAINS', 'NOT CONTAINS parses');

$ast = Parser::parse("employee.code MATCHES '[A-Z]+'");
ok($ast instanceof Binary && $ast->operator === 'MATCHES', 'MATCHES parses');

[$reason] = syntaxError(static fn () => Parser::parse('employee.code MATCHES employee.uuid'));
ok(str_contains($reason, 'string literal pattern'), 'R-VII-012: MATCHES takes a literal pattern only');

[$reason] = syntaxError(static fn () => Parser::parse("org.branch IN 'HYD'"));
ok(str_contains($reason, "IN ['X']"), 'a scalar right operand of IN is refused, with the fix in the message');

// --- CASE, and the mandatory ELSE (R-VII-011) --------------------------------

$ast = Parser::parse('CASE WHEN TRUE THEN 1 WHEN FALSE THEN 2 ELSE 3 END');
ok($ast instanceof CaseExpr && count($ast->whens) === 2, 'CASE parses with two WHEN branches');
same('1', evaluateNumber('CASE WHEN TRUE THEN 1 ELSE 2 END'), 'the first true branch wins');
same('2', evaluateNumber('CASE WHEN FALSE THEN 1 ELSE 2 END'), 'ELSE is taken when nothing matches');

[$reason] = syntaxError(static fn () => Parser::parse('CASE WHEN TRUE THEN 1 END'));
ok(str_contains($reason, 'R-VII-011'), 'CASE without ELSE is refused, citing the requirement');

[$reason] = syntaxError(static fn () => Parser::parse('CASE ELSE 1 END'));
ok(str_contains($reason, 'at least one WHEN'), 'CASE with no branch is refused');

// --- keyed accessors and function calls --------------------------------------

$ast = Parser::parse("salary.component('BASIC')");
ok($ast instanceof VariableRef && $ast->args !== null && count($ast->args) === 1, 'a keyed accessor carries its key');
same('salary.component', $ast->symbol(), 'the symbol name excludes the key');

$ast = Parser::parse("org.is_in('branch', 'HYD')");
ok($ast instanceof VariableRef && count($ast->args ?? []) === 2, 'a two-argument accessor parses');

$ast = Parser::parse('min(1, 2)');
ok($ast instanceof FunctionCall && $ast->name === 'MIN', 'function names are case-insensitive and normalise upward');

[$reason] = syntaxError(static fn () => Parser::parse('someBareWord'));
ok(str_contains($reason, 'is not a value'), 'a bare identifier is neither a variable nor a call');

[$reason] = syntaxError(static fn () => Parser::parse('1 + '));
ok(str_contains($reason, 'Expected a value'), 'a dangling operator names what was expected');

[$reason, , $column] = syntaxError(static fn () => Parser::parse('MIN(1, 2'));
ok(str_contains($reason, "Expected ')'"), 'an unclosed call is named');
same(9, $column, 'the unclosed call names the position where the bracket should be');

[$reason] = syntaxError(static fn () => Parser::parse('1 2'));
ok(str_contains($reason, 'after the end of the expression'), 'trailing junk is refused');

[$reason] = syntaxError(static fn () => Parser::parse('[' . implode(',', array_fill(0, 501, '1')) . ']'));
ok(str_contains($reason, 'at most 500 elements'), 'a list literal is bounded to 500 elements');

// --- aggregation calls parse (§5.9) ------------------------------------------

$ast = Parser::parse('agg.count(late_arrival, payroll_period, { event.value > 10 }) >= 3');
ok($ast instanceof Binary && $ast->left instanceof SmartPRS\Platform\Spx\Ast\AggCall, 'an agg call with a predicate block parses');
same('count', $ast->left->function, 'the aggregation function is captured');

$ast = Parser::parse('agg.count(late_arrival, rolling(30, days))');
ok($ast instanceof SmartPRS\Platform\Spx\Ast\AggCall
    && $ast->args[1] instanceof SmartPRS\Platform\Spx\Ast\WindowSpec, 'rolling(30, days) parses as a window spec');

[$reason] = syntaxError(static fn () => Parser::parse('agg.median(x, day)'));
ok(str_contains($reason, 'Unknown aggregation'), 'the aggregation set is closed');

[$reason] = syntaxError(static fn () => Parser::parse('agg.count(late_arrival, rolling(30))'));
ok(str_contains($reason, 'takes 2 argument'), 'a window with the wrong arity is refused');

// --- node counting, for the publish-time budgets -----------------------------

$ast = Parser::parse('1 + 2 * 3');
same(5, $ast->size(), 'the AST node count is the publish-time size');
same(3, $ast->depth(), 'the AST depth is the publish-time nesting check');
