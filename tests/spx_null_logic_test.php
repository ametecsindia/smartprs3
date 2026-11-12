<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

group('SPX three-valued logic');

/**
 * Part VII §5.4.3. UNKNOWN is what a comparison with a null operand yields, and
 * it is not FALSE: a `when` clause evaluating to UNKNOWN does not match, and is
 * recorded in the trace as `unknown` with the null-bearing symbol named. The
 * difference matters because "not known to be true" and "known to be false"
 * lead to different money.
 *
 * UNKNOWN and Null are one value here, which is what lets the whole thing be
 * three states rather than four.
 */
function nullSymbols(): SymbolTable
{
    static $symbols = null;

    return $symbols ??= SymbolTable::forNamespaces('employee', 'day', 'salary', 'leave', 'org', 'attendance');
}

function nullContext(): Context
{
    static $context = null;

    return $context ??= new Context(
        new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC')),
        [
            'employee.code' => Value::text('E-0001'),
            'employee.uuid' => Value::null(),                 // a null Text, for CONTAINS/MATCHES
            'employee.exit_date' => Value::null(),            // still employed
            'employee.is_active' => Value::boolean(true),
            'day.date' => Value::date(new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC'))),
            'day.is_holiday' => Value::boolean(false),
            'day.is_weekly_off' => Value::null(),             // not yet resolved
            'salary.gross' => Value::money(Money::of('50000.00')),
            'salary.basic' => Value::null(),
            'leave.type' => Value::null(),
            'org.branch' => Value::null(),
            'attendance.late_minutes' => Value::null(),
        ],
    );
}

/** TRUE, FALSE or NULL — the three states, rendered. */
function logic(string $source): string
{
    $compiled = Compiled::parse($source, nullSymbols());
    $check = $compiled->typeCheck();

    if (!$check->isValid()) {
        return 'PUBLISH ERROR: ' . implode('; ', $check->messages());
    }

    return Evaluator::evaluate($compiled, nullContext())->describe();
}

// `U` below is any expression that evaluates to UNKNOWN.
const U = 'day.is_weekly_off';

// --- the AND truth table ----------------------------------------------------

same('TRUE', logic('TRUE AND TRUE'), 'TRUE AND TRUE');
same('FALSE', logic('TRUE AND FALSE'), 'TRUE AND FALSE');
same('FALSE', logic('FALSE AND FALSE'), 'FALSE AND FALSE');
same('FALSE', logic(U . ' AND FALSE'), 'UNKNOWN AND FALSE = FALSE — the one place UNKNOWN collapses');
same('FALSE', logic('FALSE AND ' . U), 'and in the other order');
same('NULL', logic(U . ' AND TRUE'), 'UNKNOWN AND TRUE = UNKNOWN');
same('NULL', logic('TRUE AND ' . U), 'and in the other order');
same('NULL', logic(U . ' AND ' . U), 'UNKNOWN AND UNKNOWN = UNKNOWN');

// --- the OR truth table -----------------------------------------------------

same('TRUE', logic('TRUE OR FALSE'), 'TRUE OR FALSE');
same('FALSE', logic('FALSE OR FALSE'), 'FALSE OR FALSE');
same('TRUE', logic(U . ' OR TRUE'), 'UNKNOWN OR TRUE = TRUE — the other place it collapses');
same('TRUE', logic('TRUE OR ' . U), 'and in the other order');
same('NULL', logic(U . ' OR FALSE'), 'UNKNOWN OR FALSE = UNKNOWN');
same('NULL', logic('FALSE OR ' . U), 'and in the other order');
same('NULL', logic(U . ' OR ' . U), 'UNKNOWN OR UNKNOWN = UNKNOWN');

// --- NOT --------------------------------------------------------------------

same('FALSE', logic('NOT TRUE'), 'NOT TRUE');
same('TRUE', logic('NOT FALSE'), 'NOT FALSE');
same('NULL', logic('NOT ' . U), 'NOT UNKNOWN = UNKNOWN, not TRUE');

// --- every relational operator with a null operand --------------------------

foreach (['=', '<>', '<', '<=', '>', '>='] as $operator) {
    same('NULL', logic("attendance.late_minutes {$operator} 10m"), "a null operand makes {$operator} UNKNOWN");
    same('NULL', logic("10m {$operator} attendance.late_minutes"), "and on either side of {$operator}");
}

same('NULL', logic('employee.code = NULL'), '`= NULL` is UNKNOWN, which is why IS_NULL exists');
same('NULL', logic('employee.code <> NULL'), 'and so is `<> NULL`');
same('TRUE', logic('IS_NULL(employee.exit_date)'), 'IS_NULL is the only null test');
same('FALSE', logic('IS_NULL(employee.code)'), 'and it is definite in both directions');

// --- membership, BETWEEN, CONTAINS, MATCHES ---------------------------------

same('NULL', logic("org.branch IN ['HYD','BLR']"), 'a null left operand of IN is UNKNOWN');
same('NULL', logic("employee.code IN ['HYD', NULL]"), 'no match but a null in the list is UNKNOWN, not FALSE');
same('TRUE', logic("employee.code IN ['E-0001', NULL]"), 'a definite match beats the null');
same('FALSE', logic("employee.code IN ['HYD','BLR']"), 'and a complete list with no match is definitely FALSE');
same('NULL', logic("org.branch NOT IN ['HYD']"), 'NOT IN with a null operand is UNKNOWN too');
same('NULL', logic('attendance.late_minutes BETWEEN 0m AND 10m'), 'BETWEEN with a null value is UNKNOWN');
same('NULL', logic("employee.uuid CONTAINS 'HY'"), 'CONTAINS with a null operand is UNKNOWN');
same('NULL', logic("employee.uuid MATCHES 'HYD'"), 'MATCHES with a null subject is UNKNOWN');

// --- arithmetic and functions with a null operand ---------------------------

same('NULL', logic('salary.basic % 40'), 'arithmetic on a null yields Null');
same('NULL', logic('salary.gross + salary.basic'), 'and on either side');
same('NULL', logic('ABS(salary.basic)'), 'a function on a null yields Null');
same('₹50,000.00', logic('COALESCE(salary.basic, salary.gross)'), 'COALESCE is how a null is removed');
same('NULL', logic('COALESCE(salary.basic, salary.basic)'), 'all-null COALESCE is still Null');

// --- UNKNOWN does not match ------------------------------------------------

same('FALSE', logic('IF(' . U . ', TRUE, FALSE)'), 'an UNKNOWN condition takes the else branch');
same('99', logic('CASE WHEN ' . U . ' THEN 1 ELSE 99 END'), 'an UNKNOWN WHEN does not match');
same('1', logic('CASE WHEN ' . U . ' THEN 0 WHEN TRUE THEN 1 ELSE 99 END'), 'and the next definite branch is taken');

$unknown = Evaluator::evaluate(Compiled::parse(U, nullSymbols()), nullContext());
ok(!$unknown->isTrue(), 'a when clause evaluating to UNKNOWN does not match');
ok(!$unknown->isFalse(), 'and it is not FALSE either — the trace records it as unknown');
ok($unknown->isNull(), 'the third state is Null');
ok(!$unknown->isError(), 'and UNKNOWN is not an error');

// --- Null is distinct from 0, '' and FALSE (§5.4.1) -------------------------

same('FALSE', logic('IS_NULL(0)'), 'zero is not null');
same('FALSE', logic("IS_NULL('')"), 'the empty string is not null');
same('FALSE', logic('IS_NULL(FALSE)'), 'FALSE is not null');
same('TRUE', logic('IS_NULL(NULL)'), 'only NULL is null');
same('NULL', logic('NULL AND TRUE'), 'a NULL literal behaves as UNKNOWN in logic');

// --- the quantifiers carry it too -------------------------------------------

same('TRUE', logic('ANY([TRUE, ' . U . '])'), 'ANY is TRUE when one element definitely is');
same('NULL', logic('ANY([FALSE, ' . U . '])'), 'and UNKNOWN when the answer turns on the unknown');
same('FALSE', logic('ALL([FALSE, ' . U . '])'), 'ALL is FALSE when one element definitely is');
same('NULL', logic('ALL([TRUE, ' . U . '])'), 'and UNKNOWN otherwise');
same('FALSE', logic('NONE([TRUE, ' . U . '])'), 'NONE is FALSE when one element is definitely true');
same('NULL', logic('NONE([FALSE, ' . U . '])'), 'and UNKNOWN otherwise');

// --- short-circuiting is observable, and it is what makes UNKNOWN cheap -----

same('FALSE', logic('FALSE AND (1 / 0) > 1'), 'a short-circuited AND never evaluates the error on its right');
same('TRUE', logic('TRUE OR (1 / 0) > 1'), 'nor a short-circuited OR');

// Every function is total, so a null argument yields Null rather than throwing.
same('NULL', logic('UPPER(employee.uuid)'), 'UPPER of a null is Null, not an exception');
same('NULL', logic('LOWER(employee.uuid)'), 'LOWER of a null is Null');
same('NULL', logic('TRIM(employee.uuid)'), 'TRIM of a null is Null');
same('NULL', logic('LEFT(employee.uuid, 2)'), 'LEFT of a null is Null');
same('E-0001', logic('CONCAT(employee.code, employee.uuid)'), 'CONCAT joins a null as nothing, not as the word null');
