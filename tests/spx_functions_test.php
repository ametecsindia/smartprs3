<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\ErrorCode;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Functions\Catalogue;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

group('SPX functions and evaluation');

// --- one context, assembled once, used by every case below -------------------

function spxContext(): Context
{
    static $context = null;

    if ($context !== null) {
        return $context;
    }

    $date = static fn (string $ymd): DateTimeImmutable => new DateTimeImmutable($ymd, new DateTimeZone('UTC'));

    return $context = (new Context(
        $date('2026-08-15'),
        [
            'employee.code' => Value::text('E-0001'),
            'employee.joining_date' => Value::date($date('2020-04-01')),
            'employee.exit_date' => Value::null(),
            'employee.stage' => Value::code('confirmed', 'employment_stage'),
            'employee.is_active' => Value::boolean(true),
            'salary.gross' => Value::money(Money::of('50000.00')),
            'salary.basic' => Value::money(Money::of('15000.00')),
            'salary.ctc' => Value::money(Money::of('600000.00')),
            "salary.component('BASIC')" => Value::money(Money::of('15000.00')),
            "salary.component('HRA')" => Value::money(Money::of('6000.00')),
            'period.start' => Value::date($date('2026-08-01')),
            'period.end' => Value::date($date('2026-08-31')),
            'period.calendar_days' => Value::number(Rational::of(31)),
            'period.working_days' => Value::number(Rational::of(26)),
            'period.payable_days' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Days,
                Rational::of(26),
            ),
            'settlement.payable_days' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Days,
                Rational::of(26),
            ),
            'attendance.worked_minutes' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Minutes,
                Rational::of(480),
            ),
            'attendance.late_minutes' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Minutes,
                Rational::of(25),
            ),
            'attendance.first_in' => Value::dateTime(new DateTimeImmutable('2026-08-15 09:25:00', new DateTimeZone('UTC'))),
            'attendance.last_out' => Value::dateTime(new DateTimeImmutable('2026-08-15 18:40:00', new DateTimeZone('UTC'))),
            'shift.hours' => Value::quantity(SmartPRS\Platform\Spx\Types\TypeKind::Hours, Rational::of(17, 2)),
            'shift.start' => Value::time(9 * 3600 + 30 * 60),
            'shift.end' => Value::time(18 * 3600),
            'leave.type' => Value::code('SL', 'leave_type'),
            'leave.units' => Value::quantity(SmartPRS\Platform\Spx\Types\TypeKind::Days, Rational::of(1, 2)),
            "policy.value('basic_pct_of_gross')" => Value::percentage(Rational::of(40)),
            "policy.value('late_grace_min')" => Value::number(Rational::of(10)),
            'day.date' => Value::date($date('2026-08-15')),
            'day.is_holiday' => Value::boolean(false),
            'context.evaluation_date' => Value::date($date('2026-08-15')),
        ],
    ))
        ->withCalendar(
            'DEFAULT',
            ['2026-08-15'],                                          // Independence Day
            ['2026-08-02', '2026-08-09', '2026-08-16', '2026-08-23', '2026-08-30'],
            ['2026-08-15' => 'national'],
        )
        ->withDayLength('STANDARD_8H', '480')
        ->withDayLength('CALENDAR', '1440')
        ->withSlabTable('TDS-FY27', [
            ['from' => 0, 'rate' => '0'],
            ['from' => 30000000, 'rate' => '5'],       // ₹3,00,000
            ['from' => 60000000, 'rate' => '20'],      // ₹6,00,000
        ], false, ['threshold' => 70000000])
        // A flat table, where the charge jumps at the boundary and marginal
        // relief therefore binds — the 87A shape.
        ->withSlabTable('FLAT-LEVY', [
            ['from' => 0, 'rate' => '0'],
            ['from' => 5000000, 'rate' => '10'],
        ], true, ['threshold' => 4999900])
        ->withPrepared([
            'PAYABLE_DAYS(#2026-08-01#,#2026-08-31#)' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Days,
                Rational::of(26),
            ),
            'ATTENDANCE_HOURS(#2026-08-01#,#2026-08-31#)' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Hours,
                Rational::of(416),
            ),
            'OT_HOURS(#2026-08-01#,#2026-08-31#)' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Hours,
                Rational::of(9, 2),
            ),
            "LEAVE_UNITS('SL',#2026-08-01#,#2026-08-31#)" => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Days,
                Rational::of(3, 2),
            ),
            "LEAVE_DAYS_IN_RANGE('SL',#2026-08-01#,#2026-08-31#)" => Value::list([
                Value::date($date('2026-08-06')),
                Value::date($date('2026-08-07')),
            ]),
            'agg.count(late_arrival,payroll_period)' => Value::number(Rational::of(3)),
            'agg.balance(comp_off,#2026-08-15#)' => Value::quantity(
                SmartPRS\Platform\Spx\Types\TypeKind::Days,
                Rational::of(2),
            ),
        ]);
}

function spxSymbols(): SymbolTable
{
    static $symbols = null;

    return $symbols ??= SymbolTable::catalogue()
        ->withKeyedTypes('policy.value', [
            'basic_pct_of_gross' => Type::percentage(),
            'late_grace_min' => Type::number(),
        ])
        ->withAggMetric('comp_off', Type::days());
}

/** Type-check and evaluate; returns the rendered value or the publish errors. */
function spx(string $source): string
{
    $compiled = Compiled::parse($source, spxSymbols());
    $check = $compiled->typeCheck();

    if (!$check->isValid()) {
        return 'PUBLISH ERROR: ' . implode('; ', $check->messages());
    }

    return Evaluator::evaluate($compiled, spxContext())->describe();
}

function spxErrorCode(string $source): string
{
    $compiled = Compiled::parse($source, spxSymbols());
    $check = $compiled->typeCheck();

    if (!$check->isValid()) {
        return 'PUBLISH ERROR';
    }

    $value = Evaluator::evaluate($compiled, spxContext());

    return $value->error->value ?? 'no error: ' . $value->describe();
}

// =============================================================================
// MONEY IS EXACT, END TO END (Law 7, Part VIII §6.2)
// =============================================================================

same('₹6,000.00', spx('salary.basic % 40'), 'BASIC % 40 on ₹15,000 is exactly ₹6,000.00');
same('₹6,000.00', spx("salary.basic % policy.value('basic_pct_of_gross')"), 'and the same through a Percentage policy value');
same('₹20,000.00', spx('salary.gross % 40'), '40 percent of ₹50,000 is ₹20,000.00');

// The equivalence SmartPRS 2 cannot offer, because on floats these differ.
same(
    spx('(salary.gross / 27) * 25'),
    spx('(salary.gross * 25) / 27'),
    '(gross / 27) * 25 and (gross * 25) / 27 are the identical exact rational',
);

$compiled = Compiled::parse('salary.basic % 40', spxSymbols());
$value = Evaluator::evaluate($compiled, spxContext());
ok($value->payload instanceof Rational, 'a Money value is carried as an exact Rational of minor units');
same(600000, $value->payload->numerator, 'six hundred thousand paise');
same(1, $value->payload->denominator, 'exactly, with no remainder');
same(600000, $value->toMoney()->minor, 'and it collapses to Money without needing a rounding mode');
ok(!is_float($value->payload->numerator), 'no float appears on the money path');

// An inexact money value refuses to collapse without a declared mode.
$third = Evaluator::evaluate(Compiled::parse('salary.basic / 7', spxSymbols()), spxContext());
same(7, $third->payload->denominator, 'a seventh of ₹15,000 stays an exact rational, not a rounded one');
throws(
    LogicException::class,
    static fn () => $third->toMoney(),
    'collapsing an inexact money value with no declared mode throws — there is no default rounding',
);
same(214286, $third->toMoney(RoundingMode::HalfUp)->minor, 'with a declared mode it collapses to paise');

// =============================================================================
// ARITHMETIC FUNCTIONS
// =============================================================================

same('1', spx('MIN(3, 1, 2)'), 'MIN over numbers');
same('3', spx('MAX(3, 1, 2)'), 'MAX over numbers');
same('₹15,000.00', spx('MIN(salary.basic, salary.gross)'), 'MIN over Money');
same('2', spx('MIN(NULL, 2, 3)'), 'MIN ignores Null arguments');
same('NULL', spx('MIN(NULL, NULL)'), 'all-null MIN is Null');
same('PUBLISH ERROR: MIN() needs arguments of one type; got Money, Days (line 1, column 1)',
    spx('MIN(salary.basic, leave.units)'), 'MIN across two types is a publish error');

same('5', spx('ABS(-5)'), 'ABS on a negative number');
same('₹15,000.00', spx('ABS(-salary.basic)'), 'ABS on Money keeps the type');
same('0', spx('ABS(0)'), 'ABS of zero');

same('1.25', spx("ROUND(1.25, 'half_up', 0.01)"), 'ROUND to the paisa');
same('1.3', spx("ROUND(1.25, 'half_up', 0.1)"), 'half_up rounds a tie away from zero');
same('1.2', spx("ROUND(1.25, 'half_down', 0.1)"), 'half_down rounds a tie toward zero');
same('1.2', spx("ROUND(1.25, 'half_even', 0.1)"), "half_even breaks the tie to the even neighbour");
same('1.4', spx("ROUND(1.35, 'half_even', 0.1)"), 'and the other way when the even neighbour is above');
same('-1.3', spx("ROUND(-1.25, 'half_up', 0.1)"), 'half_up on a negative goes away from zero');
same('-1.2', spx("ROUND(-1.25, 'ceil', 0.1)"), 'ceil is toward +infinity, so it differs on negatives');
same('-1.3', spx("ROUND(-1.25, 'floor', 0.1)"), 'floor is toward -infinity');
same('₹1,250.00', spx("ROUND(MONEY(1249.50, 'INR'), 'ceil', 1)"), 'ceil to the whole rupee');
same('₹1,249.00', spx("ROUND(MONEY(1249.50, 'INR'), 'floor', 1)"), 'floor to the whole rupee');
same('₹1,249.50', spx("ROUND(MONEY(1249.50, 'INR'), 'half_up', 0.01)"), 'a paisa quantum leaves an exact amount alone');

// ROUND without a mode is a PUBLISH-time error. There is no default rounding.
ok(str_contains(spx('ROUND(1.25)'), 'PUBLISH ERROR'), 'ROUND with one argument does not publish');
ok(str_contains(spx('ROUND(1.25)'), 'the mode is mandatory'), 'and the message says why');
ok(str_contains(spx('ROUND(1.25, 0.01)'), 'PUBLISH ERROR'), 'ROUND with a unit but no mode does not publish either');
ok(str_contains(spx("ROUND(1.25, 'nearest', 0.01)"), 'explicit rounding mode'), 'an invented mode is refused');
ok(str_contains(spx("ROUND(1.25, employee.code, 0.01)"), 'explicit rounding mode'), 'a computed mode is refused: the mode must be readable in the source');

same('2', spx('CEIL(1.2, 1)'), 'CEIL to the next unit');
same('-2', spx('CEIL(-1.2, 1)'), 'CEIL is away from zero (§5.6)');
same('1', spx('FLOOR(1.8, 1)'), 'FLOOR is toward zero (§5.6)');
same('-1', spx('FLOOR(-1.8, 1)'), 'FLOOR on a negative is toward zero too');
same('1', spx('TRUNC(1.8, 1)'), 'TRUNC discards the remainder');
same('1.25', spx('FLOOR(1.29, 0.25)'), 'a quarter-unit quantum');

same('5', spx('CLAMP(10, 1, 5)'), 'CLAMP to the upper bound');
same('1', spx('CLAMP(0, 1, 5)'), 'CLAMP to the lower bound');
same('3', spx('CLAMP(3, 1, 5)'), 'CLAMP leaves an in-range value alone');
same('ERR_ARG', spxErrorCode('CLAMP(3, 5, 1)'), 'CLAMP with lo > hi is ERR_ARG, not a guess');

same('10', spx('MOD(50, 40)'), 'MOD is the modulo that % is not');
same('-2', spx('MOD(-12, 5)'), 'MOD truncates toward zero, so the sign follows the dividend');
same('ERR_DIV_ZERO', spxErrorCode('MOD(1, 0)'), 'MOD by zero is ERR_DIV_ZERO');
ok(str_contains(spx('MOD(salary.gross, 2)'), 'R-VIII-018'), 'MOD on Money does not publish');

same('-1', spx('SIGN(-3)'), 'SIGN of a negative');
same('0', spx('SIGN(0)'), 'SIGN of zero');
same('1', spx('SIGN(salary.gross)'), 'SIGN of Money is a Number');

// =============================================================================
// MONEY FUNCTIONS
// =============================================================================

same('₹1,500.00', spx("MONEY(1500, 'INR')"), 'MONEY constructs from a decimal');
same('₹1,500.50', spx("MONEY(1500.50, 'INR')"), 'including paise');
same('ERR_ARG', spxErrorCode("MONEY(1500.005, 'INR')"), 'a sub-paise amount is ERR_ARG, not a silent round');
ok(str_contains(spx('MONEY(1500, employee.code)'), 'literal ISO currency'), 'the currency must be a literal');

same('[₹333.33, ₹333.33, ₹333.34]', spx("ALLOCATE(MONEY(1000, 'INR'), [1, 1, 1])"), 'the classic thirds: residue to the last');
same('[₹50.00, ₹30.00, ₹20.00]', spx("ALLOCATE(MONEY(100, 'INR'), [50, 30, 20])"), 'a weighted split');
same('[₹100.00]', spx("ALLOCATE(MONEY(100, 'INR'), [7])"), 'one weight takes the whole');
same('ERR_ARG', spxErrorCode("ALLOCATE(MONEY(10, 'INR'), [0, 0])"), 'weights summing to zero is ERR_ARG');
same('ERR_ARG', spxErrorCode("ALLOCATE(MONEY(10, 'INR'), [1, -1])"), 'a negative weight is ERR_ARG');

// The invariant: the parts sum EXACTLY to the whole, always.
$parts = Evaluator::evaluate(
    Compiled::parse("ALLOCATE(MONEY(100, 'INR'), [1, 1, 1, 1, 1, 1, 7])", spxSymbols()),
    spxContext(),
)->elements();
$total = 0;

foreach ($parts as $part) {
    $total += $part->toMoney()->minor;
}

same(10000, $total, 'ALLOCATE parts sum exactly to the whole, with no residue lost');

same('₹1,250.00', spx("ROUND_TO(MONEY(1249.50, 'INR'), 'half_up', MONEY(1, 'INR'))"), 'ROUND_TO the whole rupee');
same('₹1,200.00', spx("ROUND_TO(MONEY(1249.50, 'INR'), 'down', MONEY(100, 'INR'))"), 'ROUND_TO the nearest hundred, downward');
same('15000', spx('MONEY_AS_NUMBER(salary.basic)'), 'MONEY_AS_NUMBER yields the major-unit amount');
same('10/3', spx('MONEY_AS_NUMBER(salary.gross) / MONEY_AS_NUMBER(salary.basic)'),
    'a money/money ratio needs the escape hatch on both sides, and is then exact');
ok(str_contains(spx('salary.gross / salary.basic'), 'MONEY_AS_NUMBER'),
    'without it, the ratio does not publish');

// =============================================================================
// CONDITIONAL
// =============================================================================

same('1', spx('IF(TRUE, 1, 2)'), 'IF takes the true branch');
same('2', spx('IF(FALSE, 1, 2)'), 'IF takes the false branch');
same('2', spx('IF(employee.exit_date = day.date, 1, 2)'), 'an UNKNOWN condition takes the else branch');
same('1', spx('IF(TRUE, 1, 1 / 0)'), 'IF is lazy: the untaken branch cannot poison the result');
same('5', spx('COALESCE(NULL, 5, 6)'), 'COALESCE returns the first non-null');
same('TRUE', spx('COALESCE(employee.exit_date = day.date, TRUE)'), 'COALESCE absorbs an UNKNOWN');
same('ERR_DIV_ZERO', spxErrorCode('COALESCE(1 / 0, 5)'), 'COALESCE does NOT absorb an error (§5.8)');
same('TRUE', spx('IS_NULL(employee.exit_date)'), 'IS_NULL is the only null test');
same('FALSE', spx('IS_NULL(employee.code)'), 'and it is false for a present value');
same('NULL', spx('employee.exit_date = day.date'), '`= NULL` is UNKNOWN, which is why IS_NULL exists');

// =============================================================================
// BANDS, STEPS, SLABS
// =============================================================================

// BANDS(value, lower_bounds, results) — arbitrary count, half-open [b(i), b(i+1)).
same('0', spx('BANDS(5, [0, 10, 60, 120], [0, 1, 2, 3])'), 'below the second bound is the first band');
same('1', spx('BANDS(10, [0, 10, 60, 120], [0, 1, 2, 3])'), 'the bound itself belongs to its own band');
same('2', spx('BANDS(119, [0, 10, 60, 120], [0, 1, 2, 3])'), 'just under the next bound');
same('3', spx('BANDS(100000, [0, 10, 60, 120], [0, 1, 2, 3])'), 'the last band is open-ended');
same('0.25', spx('BANDS(attendance.late_minutes, [0m, 10m, 120m], [0, 0.25, 0.5])'),
    'bands over Minutes: 25 minutes late is the second band');
same('0.5', spx('BANDS(150m, [0m, 10m, 120m], [0, 0.25, 0.5])'), 'and 150 minutes is the third');

$bounds = implode(',', range(0, 49));
$results = implode(',', range(100, 149));
same('149', spx("BANDS(1000, [{$bounds}], [{$results}])"), 'fifty bands are as ordinary as three');

ok(str_contains(spx('BANDS(1, [10, 5], [1, 2])'), 'ascend strictly'), 'unsorted bands do not publish');
ok(str_contains(spx('BANDS(1, [0, 5], [1])'), 'one result per lower bound'), 'a missing result does not publish');
same('ERR_ARG', spxErrorCode('BANDS(-5, [0, 10], [1, 2])'), 'a value below the first band is ERR_ARG, never a guess');

same('30', spx("STEP(25, 10, 10, 'ceil')"), 'STEP in ceil mode charges the started block');
same('20', spx("STEP(25, 10, 10, 'floor')"), 'STEP in floor mode charges completed blocks only');
same('30', spx("STEP(25, 10, 10, 'round')"), 'STEP in round mode rounds the block count');
same('20', spx("STEP(24, 10, 10, 'round')"), 'and rounds down below the half');
same('₹300.00', spx("STEP(25, 10, MONEY(100, 'INR'), 'ceil')"), 'the step may be Money');
same('ERR_DIV_ZERO', spxErrorCode("STEP(25, 0, 10, 'ceil')"), 'a zero block is ERR_DIV_ZERO');
ok(str_contains(spx("STEP(25, 10, 10, 'nearest')"), "'ceil', 'floor' or 'round'"), 'an invented STEP mode does not publish');

// The slab walk: ₹8,00,000 over 0/5/20 % bands from ₹3,00,000 and ₹6,00,000.
same('₹55,000.00', spx("SLAB(MONEY(800000, 'INR'), 'TDS-FY27', period.end)"), 'a marginal slab walk');
same('₹0.00', spx("SLAB(MONEY(200000, 'INR'), 'TDS-FY27', period.end)"), 'below the first charging band is zero');
same('ERR_ARG', spxErrorCode("SLAB(MONEY(100, 'INR'), 'NOT-A-TABLE', period.end)"), 'an unknown slab table is ERR_ARG, not zero tax');
same('₹37,000.00', spx("SLAB_MARGINAL_RELIEF(MONEY(710000, 'INR'), 'TDS-FY27', period.end)"),
    'on a marginal table the relief does not bind, and the plain charge stands');
same('₹5,000.00', spx("SLAB(MONEY(50000, 'INR'), 'FLAT-LEVY', period.end)"),
    'a flat table charges the whole amount at the band it lands in');
same('₹1.00', spx("SLAB_MARGINAL_RELIEF(MONEY(50000, 'INR'), 'FLAT-LEVY', period.end)"),
    'and relief caps the ₹5,000 cliff at the ₹1 by which the threshold was crossed');
ok(str_contains(spxErrorCode("SLAB_MARGINAL_RELIEF(MONEY(800000, 'INR'), 'NOT-A-TABLE', period.end)"), 'ERR_ARG'),
    'relief against an unknown table is ERR_ARG');

// =============================================================================
// DATES AND CALENDAR
// =============================================================================

same('30', spx("DATEDIFF(period.start, period.end, 'days')"), 'DATEDIFF in days is signed b - a');
same('-30', spx("DATEDIFF(period.end, period.start, 'days')"), 'and negative in the other direction');
same('4', spx("DATEDIFF(period.start, period.end, 'weeks')"), 'DATEDIFF in weeks');
same('6', spx("DATEDIFF(employee.joining_date, period.end, 'years')"), 'DATEDIFF in whole years');
same('76', spx("DATEDIFF(employee.joining_date, period.end, 'months')"), 'DATEDIFF in whole months');
same('2026-09-05', spx("DATEADD(#2026-08-31#, 5, 'days')"), 'DATEADD in days');
same('2026-09-07', spx("DATEADD(#2026-08-31#, 1, 'weeks')"), 'DATEADD in weeks');
same('2026-09-30', spx("DATEADD(#2026-08-31#, 1, 'months')"), 'DATEADD in months clamps to the month end');
same('2026-02-28', spx('ADD_MONTHS(#2026-01-31#, 1)'), '31-Jan plus one month is 28-Feb, not 3-Mar');
same('2028-02-29', spx('ADD_MONTHS(#2028-01-31#, 1)'), 'and 29-Feb in a leap year');
same('2026-07-31', spx('ADD_MONTHS(#2026-08-31#, -1)'), 'ADD_MONTHS goes backwards too');
same('2026-08-31', spx('EOMONTH(day.date)'), 'EOMONTH');
same('2026-08-01', spx('SOMONTH(day.date)'), 'SOMONTH');
same('2026-08-08', spx("NTH_WEEKDAY(day.date, 'sat', 2)"), 'the SECOND SATURDAY of August 2026 is the 8th');
same('2026-08-29', spx("NTH_WEEKDAY(day.date, 'sat', -1)"), 'n = -1 is the last one');
same('2026-08-29', spx("LAST_WEEKDAY(day.date, 'sat')"), 'LAST_WEEKDAY is the same thing');
same('ERR_ARG', spxErrorCode("NTH_WEEKDAY(day.date, 'sat', 6)"), 'there is no sixth Saturday, and that is an error not a null');
same('sat', spx('DAY_OF_WEEK(#2026-08-08#)'), 'DAY_OF_WEEK');
same('2', spx('WEEK_OF_MONTH(#2026-08-08#)'), 'WEEK_OF_MONTH by weekday occurrence');
same('2026-02-28', spx('CLAMP_DAY_TO_MONTH(31, #2026-02-10#)'), 'day 31 in February is the 28th');
same('2026-08-15', spx('CLAMP_DAY_TO_MONTH(15, day.date)'), 'an in-range day is untouched');
same('2026-08-15', spx('DATE_OF(attendance.first_in)'), 'DATE_OF narrows an instant');
same('09:25:00', spx('TIME_OF(attendance.first_in)'), 'TIME_OF takes the wall clock off an instant');

same('TRUE', spx("IS_HOLIDAY(#2026-08-15#, 'DEFAULT')"), 'IS_HOLIDAY reads the resolved calendar');
same('FALSE', spx("IS_HOLIDAY(#2026-08-14#, 'DEFAULT')"), 'and is false on a working day');
same('TRUE', spx("IS_WEEKLY_OFF(#2026-08-16#, 'DEFAULT')"), 'IS_WEEKLY_OFF');
same('FALSE', spx("IS_WORKING_DAY(#2026-08-15#, 'DEFAULT')"), 'a holiday is not a working day');
same('TRUE', spx("IS_WORKING_DAY(#2026-08-14#, 'DEFAULT')"), 'an ordinary Friday is');
same('national', spx("HOLIDAY_TYPE(#2026-08-15#, 'DEFAULT')"), 'HOLIDAY_TYPE');
same('none', spx("HOLIDAY_TYPE(#2026-08-14#, 'DEFAULT')"), 'and "none" on a working day');
same('ERR_MISSING_INPUT', spxErrorCode("IS_HOLIDAY(#2026-08-15#, 'NOT-PREPARED')"),
    'an unprepared calendar scope fails loudly rather than answering FALSE');

same('25', spx("WORKING_DAYS(period.start, period.end, 'DEFAULT')"), 'ONE definition of a working day');
same('31', spx('CALENDAR_DAYS(period.start, period.end)'), 'CALENDAR_DAYS is inclusive of both ends');
same('ERR_ARG', spxErrorCode("WORKING_DAYS(period.end, period.start, 'DEFAULT')"), 'a reversed range is ERR_ARG');

// =============================================================================
// SERVICE
// =============================================================================

same('6', spx('COMPLETED_YEARS(period.end)'), 'COMPLETED_YEARS is the floor');
same('TRUE', spx('YEARS_OF_SERVICE(period.end) > 6'), 'YEARS_OF_SERVICE is fractional and past six');
same('TRUE', spx('YEARS_OF_SERVICE(period.end) < 7'), 'and not yet seven');
same('6', spx('YEARS_OF_SERVICE(#2026-04-01#)'), 'exactly on the anniversary it is a whole number');
same('76', spx('MONTHS_OF_SERVICE(#2026-08-01#)'), 'MONTHS_OF_SERVICE over whole months');

// =============================================================================
// PREPARED, DATA-BACKED FUNCTIONS
// =============================================================================

same('26', spx('PAYABLE_DAYS(period.start, period.end)'), 'PAYABLE_DAYS comes from the period settlement');
same('416', spx('ATTENDANCE_HOURS(period.start, period.end)'), 'ATTENDANCE_HOURS');
same('4.5', spx('OT_HOURS(period.start, period.end)'), 'OT_HOURS, exactly, as a rational');
same('1.5', spx("LEAVE_UNITS('SL', period.start, period.end)"), 'LEAVE_UNITS by type');
same('[2026-08-06, 2026-08-07]', spx("LEAVE_DAYS_IN_RANGE('SL', period.start, period.end)"), 'the individual dates, for the sandwich rule');
same('ERR_MISSING_INPUT', spxErrorCode("LEAVE_UNITS('CL', period.start, period.end)"),
    'an answer the caller did not prepare is a named failure, never a fetch and never a zero');

// =============================================================================
// TEXT — bounded, and never a source of identity
// =============================================================================

same('ABC', spx("UPPER('abc')"), 'UPPER');
same('abc', spx("LOWER('ABC')"), 'LOWER');
same('a b', spx("TRIM('  a b  ')"), 'TRIM');
same('E-0001:2026-08-15', spx("CONCAT(employee.code, ':', '2026-08-15')"), 'CONCAT');
same('E-0', spx('LEFT(employee.code, 3)'), 'LEFT');
same('001', spx('RIGHT(employee.code, 3)'), 'RIGHT');
same('', spx('RIGHT(employee.code, 0)'), 'RIGHT of zero is empty, not the whole string');
same('ERR_ARG', spxErrorCode("CONCAT('" . str_repeat('x', 500) . "', '" . str_repeat('y', 100) . "')"),
    'text beyond 512 characters is ERR_ARG, not a silent truncation');

// =============================================================================
// LISTS
// =============================================================================

same('3', spx('COUNT([1, 2, 3])'), 'COUNT');
same('6', spx('SUM([1, 2, 3])'), 'SUM');
same('2', spx('AVG([1, 2, 3])'), 'AVG');
same('NULL', spx('AVG([])'), 'AVG of an empty list is Null, declared, not zero');
same('0', spx('COUNT([])'), 'COUNT of an empty list is zero');
same('TRUE', spx('ANY([FALSE, TRUE])'), 'ANY');
same('FALSE', spx('ANY([])'), 'ANY of an empty list is FALSE');
same('TRUE', spx('ALL([TRUE, TRUE])'), 'ALL');
same('FALSE', spx('ALL([TRUE, FALSE])'), 'ALL with a false');
same('TRUE', spx('ALL([])'), 'ALL of an empty list is TRUE');
same('TRUE', spx('NONE([FALSE, FALSE])'), 'NONE');
same('FALSE', spx('NONE([FALSE, TRUE])'), 'NONE with a true');
same('TRUE', spx('NONE([])'), 'NONE of an empty list is TRUE');
same('₹21,000.00', spx("SUM([salary.component('BASIC'), salary.component('HRA')])"), 'SUM over Money stays Money');

// =============================================================================
// UNIT CONVERSION — the named, traced crossings (R-VIII-016)
// =============================================================================

same('510', spx('TO_MINUTES(shift.hours)'), '8.5 hours is 510 minutes');
same('8.5', spx('TO_HOURS(attendance.worked_minutes) + 0.5h'), '480 minutes is 8 hours, and Hours add to Hours');
same('240', spx("TO_MINUTES(leave.units, 'STANDARD_8H')"), 'half a day is 240 minutes on an eight-hour day');
same('720', spx("TO_MINUTES(leave.units, 'CALENDAR')"), 'and 720 on a calendar day — which is why the policy is named');
same('1', spx("TO_DAYS(attendance.worked_minutes, 'STANDARD_8H')"), '480 minutes is one standard day');
same('1/3', spx("TO_DAYS(attendance.worked_minutes, 'CALENDAR')"), 'and a third of a calendar day');
ok(str_contains(spx('TO_MINUTES(leave.units)'), 'day-length policy'), 'converting Days without naming the policy does not publish');
same('ERR_MISSING_INPUT', spxErrorCode("TO_DAYS(attendance.worked_minutes, 'NOT-PREPARED')"),
    'an unprepared day-length policy is a named failure');

// =============================================================================
// INTERVAL (Part XI, the night-window allowance)
// =============================================================================

same('120', spx('OVERLAP_MINUTES(#22:00#, #23:59#, #22:00#, #23:59#) + 1m'), 'a fully overlapping window');
same('0', spx('OVERLAP_MINUTES(#09:00#, #10:00#, #11:00#, #12:00#)'), 'disjoint intervals overlap by zero');
same('30', spx('OVERLAP_MINUTES(#09:00#, #10:00#, #09:30#, #11:00#)'), 'a partial overlap');
same('ERR_ARG', spxErrorCode('OVERLAP_MINUTES(#10:00#, #09:00#, #09:30#, #11:00#)'), 'an interval that ends before it starts is ERR_ARG');

// =============================================================================
// AGGREGATION — resolved from the prepared context, never fetched
// =============================================================================

same('3', spx('agg.count(late_arrival, payroll_period)'), 'an aggregation answer prepared by the caller');
same('TRUE', spx('agg.count(late_arrival, payroll_period) >= 3'), 'the RL-ATT-001 shape');
same('2', spx('agg.balance(comp_off, #2026-08-15#)'), 'a ledger balance, typed by its declaration');
same('ERR_AGG_UNAVAILABLE', spxErrorCode('agg.count(absent_day, calendar_month)'),
    'an aggregate the caller did not prepare is ERR_AGG_UNAVAILABLE, never a truncated count');

// =============================================================================
// THE CATALOGUE IS CLOSED
// =============================================================================

ok(str_contains(spx('strlen(employee.code)'), 'catalogue is closed'), 'a PHP function name is not a function');
ok(str_contains(spx('EVAL(employee.code)'), 'catalogue is closed'), 'nor is EVAL');
ok(str_contains(spx('SYSTEM(employee.code)'), 'catalogue is closed'), 'nor SYSTEM');
same(65, count(Catalogue::names()), 'the catalogue has a fixed, countable size');
same(count(Catalogue::names()), count(array_unique(Catalogue::names())), 'and no name appears twice');

// OVERLAP_MINUTES must not intersect a wall-clock time with an absolute instant:
// they are not on the same number line (Part XI, WT-05).
same('ERR_ARG', spxErrorCode('OVERLAP_MINUTES(attendance.first_in, attendance.last_out, #22:00#, #23:00#)'),
    'mixing DateTime and Time bounds is ERR_ARG, not a confident wrong answer');
ok(str_contains(spx('OVERLAP_MINUTES(attendance.first_in, attendance.last_out, attendance.first_in, day.date)'),
    'takes DateTime or Time bounds'), 'and a Date is not an interval bound at all');
