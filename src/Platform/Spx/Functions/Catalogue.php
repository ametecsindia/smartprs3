<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Functions;

use SmartPRS\Platform\Spx\Ast\ListLiteral;
use SmartPRS\Platform\Spx\Ast\Literal;
use SmartPRS\Platform\Spx\Ast\LiteralKind;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Types\Coercion;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Support\Rational;

/**
 * THE CLOSED FUNCTION CATALOGUE — Part VII §5.6, spine S5.4, Part VIII.
 *
 * Closed means closed: `signatures()` is the whole set a published expression
 * can name. An unknown name is a publish-time error with no dynamic resolution,
 * no `__call`, no fallback to a PHP function of the same name. That is half of
 * "SPX cannot execute arbitrary code" (§10.1a-2); the other half is that the
 * grammar has no production that could name anything else.
 *
 * Every function is TOTAL (spine S5.6): at runtime it returns a typed error
 * value rather than throwing, and the error propagates to a rule evaluation
 * failure that an operator sees. Runtime lives in Runtime.php; this file is the
 * publish-time half.
 *
 * `ROUND` takes a mandatory mode. There is no default rounding anywhere in
 * SmartPRS 3, which is why ESI can be the only ceil in the system by accident in
 * SmartPRS 2 and cannot be here.
 */
final class Catalogue
{
    /** R-VII-017: a rule pins the catalogue major version it compiled against. */
    public const VERSION = 1;

    public const ROUNDING_MODES = ['half_up', 'half_down', 'half_even', 'up', 'down', 'ceil', 'floor'];
    public const STEP_MODES = ['ceil', 'floor', 'round'];
    public const DATE_UNITS = ['days', 'weeks', 'months', 'years'];
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<string, Signature>|null */
    private static ?array $signatures = null;

    public static function has(string $name): bool
    {
        return isset(self::signatures()[strtoupper($name)]);
    }

    public static function signature(string $name): ?Signature
    {
        return self::signatures()[strtoupper($name)] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::signatures());
    }

    /** @return array<string, Signature> */
    public static function signatures(): array
    {
        return self::$signatures ??= self::build();
    }

    /** @return array<string, Signature> */
    private static function build(): array
    {
        $signatures = [];

        foreach (self::rows() as $row) {
            [$name, $min, $max, $resolve, $summary] = $row;
            $signatures[$name] = new Signature($name, $min, $max, $resolve, $summary);
        }

        return $signatures;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int|null, 3: \Closure(list<Type>, list<Node>): (Type|string), 4: string}>
     */
    private static function rows(): array
    {
        return [
            // ---- Arithmetic ------------------------------------------------
            ['MIN', 1, null, self::extremum('MIN'), 'least of its arguments, all the same type'],
            ['MAX', 1, null, self::extremum('MAX'), 'greatest of its arguments, all the same type'],
            ['ABS', 1, 1, static function (array $t): Type|string {
                return self::isSigned($t[0])
                    ? $t[0]
                    : 'ABS() takes a Number, Money, Days, Hours, Minutes or Duration, not ' . $t[0];
            }, 'absolute value'],
            ['ROUND', 3, 3, static function (array $t, array $n): Type|string {
                // Spine S5.4: the mode is MANDATORY and must be a literal, so
                // the rounding of every published number is visible in its source.
                $mode = self::literalText($n[1]);

                if ($mode === null || !in_array($mode, self::ROUNDING_MODES, true)) {
                    return "ROUND() requires an explicit rounding mode literal, one of '"
                        . implode("', '", self::ROUNDING_MODES) . "'";
                }

                if (!$t[2]->isNumeric()) {
                    return 'ROUND()\'s unit is a Number quantum such as 0.01, 1 or 0.25, not ' . $t[2];
                }

                return self::isSigned($t[0]) ? $t[0] : 'ROUND() cannot round a ' . $t[0];
            }, 'ROUND(value, mode, unit) — the mode is mandatory'],
            ['CEIL', 2, 2, self::quantise('CEIL'), 'CEIL(value, unit) — away from zero to the next unit'],
            ['FLOOR', 2, 2, self::quantise('FLOOR'), 'FLOOR(value, unit) — toward zero'],
            ['TRUNC', 2, 2, self::quantise('TRUNC'), 'TRUNC(value, unit) — discard the remainder'],
            ['CLAMP', 3, 3, static function (array $t): Type|string {
                $unified = Coercion::unify($t);

                return $unified ?? 'CLAMP(a, lo, hi) needs all three of the same type; got '
                    . implode(', ', array_map(strval(...), $t));
            }, 'CLAMP(value, lo, hi)'],
            ['MOD', 2, 2, static function (array $t): Type|string {
                // R-VIII-018: MOD is undefined on Money, Rate and any duration
                // type. Modular arithmetic on an amount reads as a rounding and
                // is not one.
                return $t[0]->isNumeric() && $t[1]->isNumeric()
                    ? Type::number()
                    : 'MOD() is defined on Number only (R-VIII-018); got ' . $t[0] . ' and ' . $t[1];
            }, 'MOD(a, b) — the modulo that `%` is not'],
            ['SIGN', 1, 1, static function (array $t): Type|string {
                return self::isSigned($t[0]) ? Type::number() : 'SIGN() takes a signed quantity, not ' . $t[0];
            }, 'SIGN(a) → -1, 0 or 1'],

            // ---- Money -----------------------------------------------------
            ['MONEY', 2, 2, static function (array $t, array $n): Type|string {
                if (!$t[0]->isNumeric()) {
                    return 'MONEY() takes a Number amount, not ' . $t[0];
                }

                $currency = self::literalText($n[1]);

                return $currency === null
                    ? 'MONEY() takes a literal ISO currency code, e.g. MONEY(1500, \'INR\')'
                    : Type::money($currency);
            }, 'MONEY(amount, currency)'],
            ['ALLOCATE', 2, 2, static function (array $t): Type|string {
                if (!$t[0]->is(TypeKind::Money)) {
                    return 'ALLOCATE() distributes Money, not ' . $t[0];
                }

                if (!$t[1]->is(TypeKind::ListOf) || !($t[1]->element?->isNumeric() ?? false)) {
                    return 'ALLOCATE() takes a List<Number> of weights, not ' . $t[1];
                }

                return Type::listOf($t[0]);
            }, 'ALLOCATE(amount, weights) — parts sum exactly to the whole, residue to the last'],
            ['ROUND_TO', 3, 3, static function (array $t, array $n): Type|string {
                $mode = self::literalText($n[1]);

                if ($mode === null || !in_array($mode, self::ROUNDING_MODES, true)) {
                    return "ROUND_TO() requires an explicit rounding mode literal, one of '"
                        . implode("', '", self::ROUNDING_MODES) . "'";
                }

                if (!$t[0]->is(TypeKind::Money) || !$t[2]->is(TypeKind::Money)) {
                    return 'ROUND_TO(amount, mode, unit) takes Money and a Money quantum; got '
                        . $t[0] . ' and ' . $t[2];
                }

                return $t[0];
            }, 'ROUND_TO(amount, mode, unit)'],
            ['MONEY_AS_NUMBER', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Money)
                    ? Type::number()
                    : 'MONEY_AS_NUMBER() takes Money; ' . $t[0] . ' is already a number';
            }, 'the deliberately ugly escape hatch for a money/money ratio'],

            // ---- Conditional -----------------------------------------------
            ['IF', 3, 3, static function (array $t): Type|string {
                if (!$t[0]->is(TypeKind::Boolean, TypeKind::Null)) {
                    return 'IF()\'s condition must be Boolean, not ' . $t[0];
                }

                $unified = Coercion::unify([$t[1], $t[2]]);

                return $unified ?? 'IF() branches must have the same type; got ' . $t[1] . ' and ' . $t[2];
            }, 'IF(condition, then, else) — both branches the same type'],
            ['COALESCE', 2, null, static function (array $t): Type|string {
                $unified = Coercion::unify($t);

                return $unified ?? 'COALESCE() arguments must share a type; got '
                    . implode(', ', array_map(strval(...), $t));
            }, 'first non-null; it absorbs Null, never an error'],
            ['IS_NULL', 1, 1, static fn (): Type => Type::boolean(), 'the only null test — `= NULL` is UNKNOWN'],

            // ---- Bands, steps, slabs ---------------------------------------
            ['BANDS', 2, 3, static function (array $t, array $n): Type|string {
                // The two-argument form: one configured band table, named by a
                // literal code so its result type is known at publish. The
                // blueprint writes BANDS this way in four places, and a table an
                // administrator maintains as one artefact is configuration in a
                // way that two lists inlined in an expression are not.
                if (count($t) === 2) {
                    $code = self::literalText($n[1]);

                    if ($code === null) {
                        return 'BANDS(value, table) takes a literal band-table code, resolved from '
                            . 'configuration — a computed reference could not be type-checked before it ran';
                    }

                    $result = \SmartPRS\Platform\Spx\BandTables::resultType($code);

                    return $result
                        ?? "Band table '{$code}' declares no result type. Add it to BandTables, or an "
                            . 'expression using it could not know what it returns.';
                }

                if (!$t[1]->is(TypeKind::ListOf) || !$t[2]->is(TypeKind::ListOf)) {
                    return 'BANDS(value, lower_bounds, results) takes two lists; got ' . $t[1] . ' and ' . $t[2];
                }

                $bound = $t[1]->element ?? Type::null();

                if (!$bound->is(TypeKind::Null) && !Coercion::comparable($t[0], $bound)) {
                    return 'BANDS() compares ' . $t[0] . ' against bounds of ' . $bound;
                }

                $problem = self::validateBands($n[1], $n[2]);

                return $problem ?? ($t[2]->element ?? Type::null());
            }, 'BANDS(value, table) or BANDS(value, [b1, …], [r1, …]) — half-open [b(i), b(i+1)), last band open'],
            ['STEP', 4, 4, static function (array $t, array $n): Type|string {
                $mode = self::literalText($n[3]);

                if ($mode === null || !in_array($mode, self::STEP_MODES, true)) {
                    return "STEP()'s mode must be a literal 'ceil', 'floor' or 'round'";
                }

                if (!Coercion::comparable($t[0], $t[1])) {
                    return 'STEP(value, block, …) needs value and block of the same type; got '
                        . $t[0] . ' and ' . $t[1];
                }

                return $t[2];
            }, 'STEP(value, block, step, mode) — mode(value / block) × step'],
            ['SLAB', 3, 3, self::slab('SLAB'), 'SLAB(value, slab_table_code, as_of) — the marginal walk'],
            ['SLAB_MARGINAL_RELIEF', 3, 3, self::slab('SLAB_MARGINAL_RELIEF'), 'the declared marginal-relief rule of a slab table'],

            // ---- Dates and calendar ----------------------------------------
            ['DATEDIFF', 3, 3, static function (array $t, array $n): Type|string {
                $unit = self::literalText($n[2]);

                if ($unit === null || !in_array($unit, self::DATE_UNITS, true)) {
                    return "DATEDIFF()'s unit must be a literal one of '" . implode("', '", self::DATE_UNITS) . "'";
                }

                return self::bothDates($t, 'DATEDIFF') ?? Type::number();
            }, 'DATEDIFF(a, b, unit) — signed b − a'],
            ['DATEADD', 3, 3, static function (array $t, array $n): Type|string {
                $unit = self::literalText($n[2]);

                if ($unit === null || !in_array($unit, self::DATE_UNITS, true)) {
                    return "DATEADD()'s unit must be a literal one of '" . implode("', '", self::DATE_UNITS) . "'";
                }

                if (!$t[0]->is(TypeKind::Date)) {
                    return 'DATEADD() takes a Date, not ' . $t[0];
                }

                return $t[1]->isNumeric() ? Type::date() : 'DATEADD()\'s count must be a Number, not ' . $t[1];
            }, 'DATEADD(date, n, unit)'],
            ['ADD_MONTHS', 2, 2, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Date) && $t[1]->isNumeric()
                    ? Type::date()
                    : 'ADD_MONTHS(date, n) takes a Date and a Number; got ' . $t[0] . ' and ' . $t[1];
            }, 'ADD_MONTHS(date, n) — clamps to month end'],
            ['EOMONTH', 1, 1, self::dateToDate('EOMONTH'), 'last day of the month'],
            ['SOMONTH', 1, 1, self::dateToDate('SOMONTH'), 'first day of the month'],
            ['NTH_WEEKDAY', 3, 3, static function (array $t, array $n): Type|string {
                if (!$t[0]->is(TypeKind::Date)) {
                    return 'NTH_WEEKDAY() takes a Date in the month of interest, not ' . $t[0];
                }

                $weekday = self::literalText($n[1]);

                if ($weekday === null || !in_array(strtolower($weekday), self::WEEKDAYS, true)) {
                    return "NTH_WEEKDAY()'s weekday must be a literal one of '" . implode("', '", self::WEEKDAYS) . "'";
                }

                return $t[2]->isNumeric() ? Type::date() : 'NTH_WEEKDAY()\'s n must be a Number (-1 means last)';
            }, 'NTH_WEEKDAY(month, weekday, n) — counts actual weekdays, not ceil(day/7)'],
            ['LAST_WEEKDAY', 2, 2, static function (array $t, array $n): Type|string {
                if (!$t[0]->is(TypeKind::Date)) {
                    return 'LAST_WEEKDAY() takes a Date in the month of interest, not ' . $t[0];
                }

                $weekday = self::literalText($n[1]);

                return $weekday !== null && in_array(strtolower($weekday), self::WEEKDAYS, true)
                    ? Type::date()
                    : "LAST_WEEKDAY()'s weekday must be a literal one of '" . implode("', '", self::WEEKDAYS) . "'";
            }, 'LAST_WEEKDAY(month, weekday)'],
            ['DAY_OF_WEEK', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Date) ? Type::code('weekday') : 'DAY_OF_WEEK() takes a Date, not ' . $t[0];
            }, 'DAY_OF_WEEK(date) → mon…sun'],
            ['WEEK_OF_MONTH', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Date) ? Type::number() : 'WEEK_OF_MONTH() takes a Date, not ' . $t[0];
            }, 'the actual weekday-occurrence index'],
            ['IS_WORKING_DAY', 2, 2, self::calendarPredicate('IS_WORKING_DAY'), 'resolves the employee calendar as at the date'],
            ['IS_HOLIDAY', 2, 2, self::calendarPredicate('IS_HOLIDAY'), 'holiday on the resolved calendar'],
            // The sandwich rule (Part VII §12 RL-LVE-001) is written in terms of
            // the working day on either side of a weekly off, and named these
            // two — which were in no catalogue, so the specification's own
            // sandwich rule could not be published. They belong to the
            // IS_WORKING_DAY family and resolve the same calendar.
            ['PREV_WORKING_DAY', 2, 2, self::calendarDate('PREV_WORKING_DAY'), 'the working day before a date, on the resolved calendar'],
            ['NEXT_WORKING_DAY', 2, 2, self::calendarDate('NEXT_WORKING_DAY'), 'the working day after a date, on the resolved calendar'],
            ['IS_WEEKLY_OFF', 2, 2, self::calendarPredicate('IS_WEEKLY_OFF'), 'weekly off on the resolved calendar'],
            ['HOLIDAY_TYPE', 2, 2, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Date) && $t[1]->is(TypeKind::Code, TypeKind::Text)
                    ? Type::code('holiday_type')
                    : 'HOLIDAY_TYPE(date, scope) takes a Date and a scope Code';
            }, 'national | festival | optional | restricted | none'],
            ['CLAMP_DAY_TO_MONTH', 2, 2, static function (array $t): Type|string {
                return $t[0]->isNumeric() && $t[1]->is(TypeKind::Date)
                    ? Type::date()
                    : 'CLAMP_DAY_TO_MONTH(day, month) takes a Number and a Date; got ' . $t[0] . ' and ' . $t[1];
            }, '31 → 28/29/30'],
            ['DATE_OF', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::DateTime) ? Type::date() : 'DATE_OF() narrows a DateTime, not ' . $t[0];
            }, 'the explicit DateTime → Date narrowing'],
            ['TIME_OF', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::DateTime) ? Type::time() : 'TIME_OF() takes a DateTime, not ' . $t[0];
            }, 'wall-clock time of an instant'],

            // ---- Service ---------------------------------------------------
            ['YEARS_OF_SERVICE', 1, 1, self::dateToNumber('YEARS_OF_SERVICE'), 'fractional years as at a date'],
            ['MONTHS_OF_SERVICE', 1, 1, self::dateToNumber('MONTHS_OF_SERVICE'), 'fractional months as at a date'],
            ['COMPLETED_YEARS', 1, 1, self::dateToNumber('COMPLETED_YEARS'), 'integer years, floored'],

            // ---- Calendar aggregates ---------------------------------------
            ['WORKING_DAYS', 3, 3, static function (array $t): Type|string {
                return self::bothDates($t, 'WORKING_DAYS') ?? ($t[2]->is(TypeKind::Code, TypeKind::Text)
                    ? Type::number()
                    : 'WORKING_DAYS()\'s scope must be a Code');
            }, 'ONE definition — SmartPRS 2 has three incompatible ones'],
            ['CALENDAR_DAYS', 2, 2, static function (array $t): Type|string {
                return self::bothDates($t, 'CALENDAR_DAYS') ?? Type::number();
            }, 'inclusive calendar days between two dates'],
            ['PAYABLE_DAYS', 2, 2, static function (array $t): Type|string {
                return self::bothDates($t, 'PAYABLE_DAYS') ?? Type::days();
            }, 'from the period settlement'],

            // ---- Attendance and leave --------------------------------------
            ['ATTENDANCE_HOURS', 2, 2, static function (array $t): Type|string {
                return self::bothDates($t, 'ATTENDANCE_HOURS') ?? Type::hours();
            }, 'worked hours over a range'],
            ['OT_HOURS', 2, 2, static function (array $t): Type|string {
                return self::bothDates($t, 'OT_HOURS') ?? Type::hours();
            }, 'overtime hours over a range'],
            ['LEAVE_UNITS', 3, 3, static function (array $t): Type|string {
                if (!$t[0]->is(TypeKind::Code, TypeKind::Text)) {
                    return 'LEAVE_UNITS() takes a leave type Code, not ' . $t[0];
                }

                return self::bothDates([$t[1], $t[2]], 'LEAVE_UNITS') ?? Type::days();
            }, 'approved units of one leave type'],
            ['LEAVE_DAYS_IN_RANGE', 3, 3, static function (array $t): Type|string {
                if (!$t[0]->is(TypeKind::Code, TypeKind::Text)) {
                    return 'LEAVE_DAYS_IN_RANGE() takes a leave type Code, not ' . $t[0];
                }

                return self::bothDates([$t[1], $t[2]], 'LEAVE_DAYS_IN_RANGE') ?? Type::listOf(Type::date());
            }, 'the individual dates, for the sandwich rule'],

            // ---- Text (bounded, and never a source of identity) ------------
            ['UPPER', 1, 1, self::textToText('UPPER'), 'upper-case'],
            ['LOWER', 1, 1, self::textToText('LOWER'), 'lower-case'],
            ['TRIM', 1, 1, self::textToText('TRIM'), 'trim surrounding whitespace'],
            ['CONCAT', 1, null, static function (array $t): Type|string {
                foreach ($t as $index => $type) {
                    if (!$type->is(TypeKind::Text, TypeKind::Code, TypeKind::Null)) {
                        return 'CONCAT() joins Text and Code; argument ' . ($index + 1) . ' is ' . $type;
                    }
                }

                return Type::text();
            }, 'join, bounded to 512 characters'],
            ['LEFT', 2, 2, self::textSlice('LEFT'), 'LEFT(text, n)'],
            ['RIGHT', 2, 2, self::textSlice('RIGHT'), 'RIGHT(text, n)'],

            // ---- Lists ------------------------------------------------------
            ['COUNT', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::ListOf) ? Type::number() : 'COUNT() takes a List, not ' . $t[0];
            }, 'element count'],
            ['SUM', 1, 1, static function (array $t): Type|string {
                $element = $t[0]->is(TypeKind::ListOf) ? $t[0]->element : null;

                if ($element === null) {
                    return 'SUM() takes a List, not ' . $t[0];
                }

                return $element->isNumeric()
                    || $element->is(TypeKind::Money, TypeKind::Days, TypeKind::Hours, TypeKind::Minutes, TypeKind::Null)
                    ? $element
                    : 'SUM() adds numbers, Money and unit quantities, not ' . $element;
            }, 'exact sum of a list'],
            ['AVG', 1, 1, static function (array $t): Type|string {
                $element = $t[0]->is(TypeKind::ListOf) ? $t[0]->element : null;

                return $element !== null && ($element->isNumeric() || $element->is(TypeKind::Null))
                    ? Type::number()
                    : 'AVG() averages a List<Number>, not ' . $t[0];
            }, 'empty → Null'],
            ['ANY', 1, 1, self::booleanList('ANY'), 'empty → FALSE'],
            ['ALL', 1, 1, self::booleanList('ALL'), 'empty → TRUE'],
            ['NONE', 1, 1, self::booleanList('NONE'), 'empty → TRUE'],

            // ---- Unit conversion — the named, traced crossings ---------------
            ['TO_MINUTES', 1, 2, static function (array $t, array $n): Type|string {
                if ($t[0]->is(TypeKind::Hours, TypeKind::Duration, TypeKind::Minutes)) {
                    return Type::minutes();
                }

                if ($t[0]->base()->is(TypeKind::Days)) {
                    // R-VIII-016: converting Days needs the day-length policy that
                    // gives the Days its meaning. This is the exact crossing that
                    // SmartPRS 2 makes silently.
                    return count($n) === 2 && self::literalText($n[1]) !== null
                        ? Type::minutes()
                        : "TO_MINUTES() on Days requires a day-length policy code, e.g. TO_MINUTES(d, 'STANDARD_8H')";
                }

                return 'TO_MINUTES() converts Hours, Duration or Days, not ' . $t[0];
            }, 'TO_MINUTES(x[, day_length])'],
            ['TO_HOURS', 1, 1, static function (array $t): Type|string {
                return $t[0]->is(TypeKind::Minutes, TypeKind::Duration, TypeKind::Hours)
                    ? Type::hours()
                    : 'TO_HOURS() converts Minutes or Duration, not ' . $t[0];
            }, 'TO_HOURS(x)'],
            ['TO_DAYS', 2, 2, static function (array $t, array $n): Type|string {
                // Number is here because the coercion table has always said so:
                // "Number → Days: never (TO_DAYS, which names the day-length
                // policy)". TO_DAYS IS the sanctioned conversion, and refusing a
                // Number made the table's own escape hatch unreachable — which
                // is why Part VIII's fixed-30, encashment and comp-off divisors,
                // each a configured count of days, could not be turned into Days
                // at all. A bare count with a named basis is exactly the shape
                // the policy code exists to give meaning to.
                if (!$t[0]->isNumeric() && !$t[0]->is(TypeKind::Minutes, TypeKind::Hours, TypeKind::Days)) {
                    return 'TO_DAYS() converts a Number, Minutes, Hours or Days under a named '
                        . 'day-length policy, not ' . $t[0];
                }

                return self::literalText($n[1]) !== null
                    ? Type::days()
                    : "TO_DAYS() requires a literal day-length policy code, e.g. TO_DAYS(m, 'CALENDAR')";
            }, 'TO_DAYS(x, day_length) — the policy names numerator and denominator basis'],

            // ---- Interval (Part XI, the night-window allowance) -------------
            ['OVERLAP_MINUTES', 4, 4, static function (array $t): Type|string {
                foreach ($t as $index => $type) {
                    if (!$type->is(TypeKind::DateTime, TypeKind::Time)) {
                        return 'OVERLAP_MINUTES() takes DateTime or Time bounds; argument '
                            . ($index + 1) . ' is ' . $type;
                    }
                }

                return Type::minutes();
            }, 'minutes of intersection, anchored to instants so midnight is not two intervals'],
        ];
    }

    // ---- shared resolver shapes -------------------------------------------

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function extremum(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            $unified = Coercion::unify($types);

            if ($unified === null) {
                return $name . '() needs arguments of one type; got '
                    . implode(', ', array_map(strval(...), $types));
            }

            return $unified;
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function quantise(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            if (!self::isSigned($types[0])) {
                return $name . '() cannot quantise a ' . $types[0];
            }

            return $types[1]->isNumeric()
                ? $types[0]
                : $name . '()\'s unit is a Number quantum such as 0.01 or 1, not ' . $types[1];
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function slab(string $name): \Closure
    {
        return static function (array $types, array $nodes) use ($name): Type|string {
            if (!$types[0]->is(TypeKind::Money)) {
                return $name . '() walks a slab table over Money, not ' . $types[0];
            }

            // The table may be a literal OR a Code-typed expression. It has to
            // be, because the professional-tax table is chosen per employee by
            // their establishment's state — the very indirection that replaces
            // SmartPRS 2's str_contains(strtolower($state), $key), where
            // "Andhra Pradesh" and "Andhra" match by luck and a renamed state
            // falls through to an unreachable hard-coded list.
            //
            // A literal was required so the table's bands could be validated at
            // formula publish (gate G7). That was the wrong place: a slab table
            // is PACK CONTENT, and sixteen state tables are validated when the
            // PACK is signed, not when one formula that might select any of them
            // is published. The formula-publish check asserts the reference is a
            // Code; the pack-signing gate asserts the bands are sorted,
            // contiguous and declare marginal or flat.
            if (self::literalText($nodes[1]) === null && !$types[1]->is(TypeKind::Code)) {
                return $name . '() takes a slab table code — a literal, or an expression typed Code '
                    . '(a pack value resolved per employee); got ' . $types[1];
            }

            return $types[2]->is(TypeKind::Date)
                ? $types[0]
                : $name . '()\'s as_of must be a Date, not ' . $types[2];
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function calendarPredicate(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            return $types[0]->is(TypeKind::Date) && $types[1]->is(TypeKind::Code, TypeKind::Text)
                ? Type::boolean()
                : $name . '(date, scope) takes a Date and a scope Code; got '
                    . $types[0] . ' and ' . $types[1];
        };
    }

    /** Same shape as calendarPredicate(), but the answer is a Date. */
    private static function calendarDate(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            return $types[0]->is(TypeKind::Date) && $types[1]->is(TypeKind::Code, TypeKind::Text)
                ? Type::date()
                : $name . '(date, scope) takes a Date and a scope Code; got '
                    . $types[0] . ' and ' . $types[1];
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function dateToDate(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            return $types[0]->is(TypeKind::Date) ? Type::date() : $name . '() takes a Date, not ' . $types[0];
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function dateToNumber(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            return $types[0]->is(TypeKind::Date) ? Type::number() : $name . '() takes an as-at Date, not ' . $types[0];
        };
    }

    /**
     * Text in, Text out. A Code coerces to Text here (§5.4.2) — but CONTAINS and
     * MATCHES may not be applied to a Code (§5.6, §10.3), so no amount of
     * UPPER()ing lets an author rebuild identity-from-name semantics.
     *
     * @return \Closure(list<Type>, list<Node>): (Type|string)
     */
    private static function textToText(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            return $types[0]->is(TypeKind::Text, TypeKind::Code)
                ? Type::text()
                : $name . '() takes Text, not ' . $types[0];
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function textSlice(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            if (!$types[0]->is(TypeKind::Text, TypeKind::Code)) {
                return $name . '() takes Text, not ' . $types[0];
            }

            return $types[1]->isNumeric() ? Type::text() : $name . '()\'s length must be a Number';
        };
    }

    /** @return \Closure(list<Type>, list<Node>): (Type|string) */
    private static function booleanList(string $name): \Closure
    {
        return static function (array $types) use ($name): Type|string {
            $element = $types[0]->is(TypeKind::ListOf) ? $types[0]->element : null;

            return $element !== null && $element->is(TypeKind::Boolean, TypeKind::Null)
                ? Type::boolean()
                : $name . '() takes a List<Boolean>, not ' . $types[0];
        };
    }

    /** @param list<Type> $types */
    private static function bothDates(array $types, string $name): ?string
    {
        foreach ([$types[0], $types[1]] as $index => $type) {
            if (!$type->is(TypeKind::Date)) {
                return $name . '() takes Date bounds; argument ' . ($index + 1) . ' is ' . $type;
            }
        }

        return null;
    }

    private static function isSigned(Type $type): bool
    {
        return $type->base()->is(
            TypeKind::Number,
            TypeKind::Money,
            TypeKind::Days,
            TypeKind::Hours,
            TypeKind::Minutes,
            TypeKind::Duration,
            TypeKind::Rate,
        );
    }

    /**
     * The text of a literal argument, or null when the argument is computed.
     * Several catalogue entries insist on a literal — a rounding mode, a slab
     * table code, a regex — so that publish-time linting has something to read.
     */
    public static function literalText(Node $node): ?string
    {
        return $node instanceof Literal && $node->literal === LiteralKind::Text ? $node->text : null;
    }

    /**
     * BANDS validation at publish (§5.6): bounds ascending, both lists the same
     * length, at least one band. Expressed as two parallel lists rather than the
     * blueprint's `[{from,to,result}…]`, because the grammar has no record
     * literal and `{…}` is the aggregation predicate block. Contiguity and
     * non-overlap are then structural rather than checked, which is stronger.
     *
     * ponytail: bounds must be literals to be checked here. A computed bound is
     * allowed and validated at runtime instead.
     */
    private static function validateBands(Node $bounds, Node $results): ?string
    {
        if (!$bounds instanceof ListLiteral || !$results instanceof ListLiteral) {
            return null;
        }

        if (count($bounds->elements) !== count($results->elements)) {
            return 'BANDS() needs one result per lower bound; got ' . count($bounds->elements)
                . ' bound(s) and ' . count($results->elements) . ' result(s)';
        }

        if ($bounds->elements === []) {
            return 'BANDS() needs at least one band';
        }

        $previous = null;

        foreach ($bounds->elements as $element) {
            if (!$element instanceof Literal || $element->literal !== LiteralKind::Number) {
                return null;    // a computed bound; checked at runtime
            }

            // Exact decimal comparison. A float would order 0.1 and 0.10 by luck.
            $value = Rational::fromDecimalString($element->text);

            if ($previous !== null && $value->compareTo($previous) <= 0) {
                return 'BANDS() lower bounds must ascend strictly; ' . $element->text
                    . ' does not follow ' . $previous;
            }

            $previous = $value;
        }

        return null;
    }
}
