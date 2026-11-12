<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Coercion;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;

group('SPX type system');

// =============================================================================
// THE OPERATION MATRIX — Part VIII §5.4, cell by cell.
//
// Every cell of the published table is asserted here, both the permitted ones
// and — the point of the exercise — every one that must be a type error. This
// is the barrier that stops SmartPRS 2's unit-confusion defect family: absence
// measured in working days and valued in calendar days, with nothing objecting.
// =============================================================================

function cell(Type $left, string $operator, Type $right): string
{
    $result = Coercion::arithmetic($left, $operator, $right);

    return $result === null ? 'ERROR' : (string) $result;
}

/** @param array<string, string> $expected op => result type or 'ERROR' */
function matrixRow(string $label, Type $left, Type $right, array $expected): void
{
    foreach ($expected as $operator => $want) {
        same($want, cell($left, $operator, $right), "{$label}: {$left} {$operator} {$right}");
    }
}

$money = Type::money();
$usd = Type::money('USD');
$number = Type::number();
$percent = Type::percentage();
$days = Type::days();
$dayFraction = Type::dayFraction();
$hours = Type::hours();
$minutes = Type::minutes();
$duration = Type::duration();
$date = Type::date();
$dateTime = Type::dateTime();
$time = Type::time();
$boolean = Type::boolean();
$text = Type::text();
$codeType = Type::code('leave_type');
$rateDays = Type::rate(TypeKind::Days);
$rateHours = Type::rate(TypeKind::Hours);
$rateMinutes = Type::rate(TypeKind::Minutes);

matrixRow('Money op Money (same ccy)', $money, $money,
    ['+' => 'Money', '-' => 'Money', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Money op Money (diff ccy)', $money, $usd,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Money op Number', $money, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Money', '/' => 'Money', '%' => 'Money']);
matrixRow('Money op Percentage', $money, $percent,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Money', '/' => 'Money', '%' => 'Money']);
matrixRow('Money op Days', $money, $days,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'Rate<Money/Days>', '%' => 'ERROR']);
matrixRow('Money op Hours', $money, $hours,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'Rate<Money/Hours>', '%' => 'ERROR']);
matrixRow('Money op Minutes', $money, $minutes,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'Rate<Money/Minutes>', '%' => 'ERROR']);
matrixRow('Rate<Money/Days> op Days', $rateDays, $days,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Money', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Rate<Money/Hours> op Hours', $rateHours, $hours,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Money', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Rate<Money/Days> op Number', $rateDays, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Rate<Money/Days>', '/' => 'Rate<Money/Days>', '%' => 'Rate<Money/Days>']);
matrixRow('Rate op the same Rate', $rateDays, $rateDays,
    ['+' => 'Rate<Money/Days>', '-' => 'Rate<Money/Days>', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Rate op a Rate of another unit', $rateDays, $rateHours,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Rate<Money/Days> op Hours', $rateDays, $hours,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Number op Number', $number, $number,
    ['+' => 'Number', '-' => 'Number', '*' => 'Number', '/' => 'Number', '%' => 'Number']);
matrixRow('Days op Days', $days, $days,
    ['+' => 'Days', '-' => 'Days', '*' => 'ERROR', '/' => 'Number', '%' => 'ERROR']);
matrixRow('Days op Number', $days, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Days', '/' => 'Days', '%' => 'Days']);
matrixRow('Days op Hours', $days, $hours,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Hours op Hours', $hours, $hours,
    ['+' => 'Hours', '-' => 'Hours', '*' => 'ERROR', '/' => 'Number', '%' => 'ERROR']);
matrixRow('Hours op Number', $hours, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Hours', '/' => 'Hours', '%' => 'Hours']);
matrixRow('Hours op Minutes', $hours, $minutes,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Minutes op Minutes', $minutes, $minutes,
    ['+' => 'Minutes', '-' => 'Minutes', '*' => 'ERROR', '/' => 'Number', '%' => 'ERROR']);
matrixRow('Minutes op Number', $minutes, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'Minutes', '/' => 'Minutes', '%' => 'Minutes']);
matrixRow('Date op Date', $date, $date,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('Date op Number', $date, $number,
    ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
matrixRow('DateTime op DateTime', $dateTime, $dateTime,
    ['+' => 'ERROR', '-' => 'Duration', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);

foreach (['Boolean' => $boolean, 'Text' => $text, 'Code' => $codeType, 'Date' => $date, 'Time' => $time] as $label => $type) {
    matrixRow("Money op {$label}", $money, $type,
        ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
    matrixRow("Number op {$label}", $number, $type,
        ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
    matrixRow("{$label} op Number", $type, $number,
        ['+' => 'ERROR', '-' => 'ERROR', '*' => 'ERROR', '/' => 'ERROR', '%' => 'ERROR']);
}

// `*` is commutative across the matrix; `/`, `-` and `%` are not.
same('Money', cell($number, '*', $money), 'Number * Money is Money — * is commutative');
same('Money', cell($percent, '*', $money), 'Percentage * Money is Money');
same('Days', cell($number, '*', $days), 'Number * Days is Days');
same('Money', cell($days, '*', $rateDays), 'Days * Rate<Money/Days> is Money');
same('ERROR', cell($number, '/', $money), 'Number / Money is not the same as Money / Number');
same('ERROR', cell($number, '-', $money), 'Number - Money is a type error in both directions');

// The four the brief asks about explicitly.
same('Money', cell($money, '*', $number), 'Money * Number → Money');
same('Rate<Money/Days>', cell($money, '/', $days), 'Money / Days → Rate<Money/Days>');
same('ERROR', cell($days, '+', $hours), 'Days + Hours → type error');
same('Money', cell($money, '%', $number), 'Money % Number → Money (percent-of)');

// Refinements: DayFraction widens to Days always; Percentage erases in * / %.
same('Days', cell($dayFraction, '+', $days), 'DayFraction + Days → Days (refinement widening)');
same('Days', cell($days, '+', $dayFraction), 'and in the other order');
same('Days', cell($dayFraction, '*', $number), 'DayFraction * Number → Days');
same('Number', cell($percent, '*', $number), 'Percentage * Number → Number (tag erased)');
same('ERROR', cell($percent, '+', $number), 'Percentage + Number is not a listed coercion context');
same('ERROR', cell($duration, '+', $duration), 'Duration arithmetic is not in the matrix; convert first');

// R-VIII-015: no numeric coercion between Money and Number in either direction.
same('ERROR', cell($money, '+', $number), 'Money + Number: the engine cannot be talked into treating rupees as a scalar');
same('ERROR', cell($number, '+', $money), 'nor in the other direction');

// =============================================================================
// THE COERCION TABLE — Part VII §5.4.2 / Part VIII §5.2, the non-arithmetic rows
// =============================================================================

ok(Coercion::comparable($date, $dateTime), 'Date coerces to DateTime in a comparison');
ok(Coercion::comparable($dateTime, $date), 'and the comparison is symmetric');
ok(!Coercion::assignable($dateTime, $date), 'DateTime never narrows to Date implicitly — DATE_OF(dt) is required');
ok(!Coercion::assignable($money, $number), 'Money never becomes a Number implicitly');
ok(!Coercion::assignable($number, $money), 'a Number never becomes Money implicitly');
ok(!Coercion::assignable($number, $days), 'a Number never becomes Days implicitly');
ok(!Coercion::assignable($number, $hours), 'a Number never becomes Hours implicitly');
ok(!Coercion::assignable($number, $minutes), 'a Number never becomes Minutes implicitly');
ok(!Coercion::assignable($days, $hours), 'Days never becomes Hours implicitly');
ok(!Coercion::assignable($hours, $minutes), 'Hours never becomes Minutes implicitly');
ok(!Coercion::assignable($text, $codeType), 'Text never becomes a Code implicitly');
ok(Coercion::assignable($dayFraction, $days), 'DayFraction widens to Days');
ok(!Coercion::assignable($days, $dayFraction), 'Days does not narrow to DayFraction without a check');
ok(Coercion::assignable($percent, $number), 'Percentage widens to Number');
ok(Coercion::assignable(Type::null(), $money), 'Null is assignable anywhere; three-valued logic takes it from there');
ok(!Coercion::comparable($money, $usd), 'two currencies never compare — there is no implicit FX');
ok(!Coercion::comparable($days, $hours), 'Days and Hours do not compare');
ok(!Coercion::comparable(Type::code('leave_type'), Type::code('attendance_status')), 'two value sets do not compare');
ok(Coercion::comparable(Type::code('leave_type'), Type::code()), 'a bare Code parameter accepts a declared one');

ok(Coercion::orderable($money), 'Money is ordered');
ok(Coercion::orderable($date), 'Date is ordered');
ok(!Coercion::orderable($boolean), 'Boolean has no ordering');
ok(!Coercion::orderable($text), 'Text has no ordering: < on a name is a smell, not a comparison');
ok(!Coercion::orderable($codeType), 'a Code has no ordering');

// unify() — the common type of IF/CASE/COALESCE/MIN/MAX branches and list elements
same('Money', (string) Coercion::unify([$money, $money]), 'two Money unify to Money');
same('Days', (string) Coercion::unify([$days, $dayFraction]), 'Days and DayFraction unify to Days');
same('Money', (string) Coercion::unify([Type::null(), $money]), 'a Null branch does not change the type');
same(null, Coercion::unify([$money, $days]), 'Money and Days do not unify');
same('Null', (string) Coercion::unify([Type::null(), Type::null()]), 'all-null unifies to Null');

// =============================================================================
// The same rules, reached through a published expression
// =============================================================================

function publishErrors(string $source, ?SymbolTable $symbols = null): array
{
    $symbols ??= SymbolTable::catalogue();

    return Compiled::parse($source, $symbols)->typeCheck()->messages();
}

function publishType(string $source, ?SymbolTable $symbols = null): string
{
    $symbols ??= SymbolTable::catalogue();
    $result = Compiled::parse($source, $symbols)->typeCheck();

    return $result->isValid() ? (string) $result->type : 'INVALID: ' . implode('; ', $result->messages());
}

same('Money', publishType("salary.basic % 40"), 'BASIC % 40 types to Money');
same('Money', publishType("salary.component('BASIC') * 2"), 'a keyed accessor types by its declaration');
same('Rate<Money/Days>', publishType('salary.gross / period.payable_days'), 'gross per payable day is a typed rate');
same('Money', publishType('(salary.gross / period.payable_days) * settlement.payable_days'), 'and multiplying it by Days gives Money back');

$errors = publishErrors('salary.gross * salary.basic');
ok(count($errors) === 1 && str_contains($errors[0], 'MONEY_AS_NUMBER'), 'Money * Money is a publish-time error naming the escape hatch');

$errors = publishErrors('leave.units + attendance.worked_minutes');
ok(str_contains($errors[0] ?? '', 'TO_MINUTES'), 'Days + Minutes is refused, with the conversion named');

$errors = publishErrors('shift.hours + attendance.worked_minutes');
ok(str_contains($errors[0] ?? '', 'TO_MINUTES(h) + m'), 'Hours + Minutes is refused, with the fix quoted');

$errors = publishErrors('period.end - period.start');
ok(str_contains($errors[0] ?? '', 'DATEDIFF'), 'Date - Date is refused, pointing at DATEDIFF');

// The SmartPRS 2 defect itself: a Days numerator over a calendar-day denominator.
$errors = publishErrors('(settlement.scheduled_days - settlement.absent_days) / period.calendar_days');
ok($errors !== [], 'the working-days-over-calendar-days mismatch does not publish');

same('Number', publishType('settlement.payable_days / period.payable_days'), 'a Days/Days ratio is a Number, and is allowed');

// Every error is reported, not just the first.
$errors = publishErrors('salary.gross * salary.basic AND leave.units + shift.hours');
ok(count($errors) >= 2, 'every type error is reported: ' . count($errors) . ' found');

// A namespace the decision point does not declare is a publish-time error.
$narrow = SymbolTable::forNamespaces('attendance', 'day', 'shift');
$errors = publishErrors('salary.gross > 0', $narrow);
ok(str_contains($errors[0] ?? '', 'not declared available'), 'a rule at one decision point cannot read another\'s namespace');
ok(publishErrors('attendance.late_minutes > 10m', $narrow) === [], 'and it can read its own');

$errors = publishErrors('nonsense.thing = 1');
ok(str_contains($errors[0] ?? '', 'Unknown namespace'), 'an invented namespace is named as unknown');

$errors = publishErrors('employee.not_a_field = 1');
ok(str_contains($errors[0] ?? '', 'Unknown symbol'), 'an invented symbol in a real namespace is named too');

$errors = publishErrors('salary.component = 1');
ok(str_contains($errors[0] ?? '', 'keyed accessor'), 'a keyed accessor used without its key is an error');

$errors = publishErrors("employee.code('X') = 1");
ok(str_contains($errors[0] ?? '', 'not a keyed accessor'), 'a plain symbol used with a key is an error');

// Value-set membership, checked at publish when the set is declared.
$withSet = SymbolTable::catalogue()->withValueSet('employment_stage', ['probation', 'confirmed']);
ok(publishErrors("employee.stage = 'probation'", $withSet) === [], 'a declared member compares cleanly');
$errors = publishErrors("employee.stage = 'PROBATIONARY'", $withSet);
ok(str_contains($errors[0] ?? '', 'not a member of the value set'), 'a non-member literal is refused at publish');
ok(publishErrors("employee.stage IN ['probation','nope']", $withSet) !== [], 'and inside an IN list too');

// CONTAINS and MATCHES may not be applied to a Code (§5.6, §10.3): this is what
// makes SmartPRS 2's identity-from-name defect family inexpressible.
$errors = publishErrors("employee.stage CONTAINS 'prob'");
ok(str_contains($errors[0] ?? '', 'CONTAINS may not be applied to a Code'), 'no substring semantics on a Code');
$errors = publishErrors("leave.type MATCHES 'S.*'");
ok(str_contains($errors[0] ?? '', 'MATCHES may not be applied to a Code'), 'no regex identity on a Code either');

// Comparison ordering and non-comparability.
$errors = publishErrors('employee.is_active > day.is_holiday');
ok(str_contains($errors[0] ?? '', 'no ordering'), 'ordering two Booleans is refused');
$errors = publishErrors('salary.gross = leave.units');
ok(str_contains($errors[0] ?? '', 'Cannot compare'), 'Money and Days do not compare');

// A list is homogeneous.
$errors = publishErrors("employee.code IN ['A', 1]");
ok(str_contains($errors[0] ?? '', 'homogeneous'), 'a mixed list is refused');

// CASE branches must agree.
$errors = publishErrors('CASE WHEN employee.is_active THEN salary.gross ELSE leave.units END');
ok(str_contains($errors[0] ?? '', 'same type'), 'CASE branches of different types are refused');
$errors = publishErrors('CASE WHEN salary.gross THEN 1 ELSE 2 END');
ok(str_contains($errors[0] ?? '', 'must be Boolean'), 'a non-Boolean WHEN is refused');

// AND/OR take Booleans.
$errors = publishErrors('salary.gross AND employee.is_active');
ok(str_contains($errors[0] ?? '', 'Boolean operands'), 'AND over a Money operand is refused');

// Publish-time depth budget (§7.7): 32 levels of AST, checked before publish.
// Parentheses do not deepen the AST — grouping is precedence, not structure —
// so the depth that matters is the operator nesting.
$deep = str_repeat('NOT ', 40) . 'TRUE';
$errors = publishErrors($deep);
ok(str_contains($errors[0] ?? '', 'levels deep'), 'an over-deep expression is rejected at publish, not at evaluation');
ok(publishErrors(str_repeat('NOT ', 20) . 'TRUE') === [], 'and one within the limit publishes');

// event.* is available inside an aggregation predicate only (§5.9.3).
$errors = publishErrors('event.value > 10');
ok(str_contains($errors[0] ?? '', 'predicate block only'), 'event.* outside a predicate is a publish error');
ok(publishErrors('agg.count(late_arrival, payroll_period, { event.value > 10m }) >= 3') === [],
    'and inside one it is fine');
$errors = publishErrors('agg.count(late_arrival, payroll_period, { salary.gross > 0 }) >= 3');
ok(str_contains($errors[0] ?? '', 'may not read salary.*'), 'a predicate may not read the outer subject\'s salary');
$errors = publishErrors('agg.count(late_arrival, payroll_period, { agg.count(x, day) > 1 }) >= 3');
ok(str_contains(implode(' ', $errors), 'Nested aggregation'), 'R-VII-025: nested aggregation is a publish error');

$many = implode(' + ', array_fill(0, 9, 'agg.count(late_arrival, payroll_period)'));
$errors = publishErrors($many . ' > 1');
ok(str_contains(implode(' ', $errors), 'aggregation calls'), 'more than eight aggregation calls is a publish error');

// =============================================================================
// Type::parse — the inverse of __toString, and why it exists
// =============================================================================
//
// `formula_payloads.result_type` has stored a rendered type since the payload
// migration and nothing could read one back. A serialisation with no
// deserialisation is a one-way trip: it looks like persistence until something
// tries to load what it wrote, which is what Formula\FormulaResolver does.

foreach ([
    'Number' => Type::number(),
    'Money' => Type::money(),
    'Money(USD)' => Type::money('USD'),
    'Percentage' => Type::percentage(),
    'DayFraction' => Type::dayFraction(),
    'Code(leave_type)' => Type::code('leave_type'),
    'List<Money>' => Type::listOf(Type::money()),
    'Rate<Money/Days>' => Type::rate(SmartPRS\Platform\Spx\Types\TypeKind::Days),
] as $rendered => $type) {
    same($rendered, (string) $type, "{$rendered} renders as itself");
    ok(Type::parse($rendered)->equals($type), "and parses back to the same type, not a lookalike");
}

throws(
    InvalidArgumentException::class,
    static fn () => Type::parse('Furlongs'),
    'an unrecognised type is refused, never defaulted — one wrong default is a Money column read as a number',
);
throws(
    InvalidArgumentException::class,
    static fn () => Type::parse('List'),
    'and a parameterised type must name its parameter',
);
