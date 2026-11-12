<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Functions;

use SmartPRS\Platform\Spx\Arith;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\ErrorCode;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * The runtime half of the closed catalogue (Part VII §5.6).
 *
 * Every function here is TOTAL and PURE. Total: it returns a typed error Value
 * rather than throwing, and the error propagates to a rule-evaluation failure an
 * operator sees. Pure: it reads its arguments and the prepared context, and
 * nothing else — no clock, no randomness, no filesystem, no network, no
 * database. The data-backed functions (PAYABLE_DAYS, ATTENDANCE_HOURS, …) read
 * an answer the caller prepared; they do not go and get one.
 *
 * Money never becomes a float on any path here. Amounts are exact Rationals of
 * minor units; the collapse to paise happens at a declared rounding boundary.
 */
final class Runtime
{
    /** IF, COALESCE and CASE are evaluated lazily by the Evaluator, never here. */
    private const LAZY = ['IF', 'COALESCE'];

    /** A year. PREV/NEXT_WORKING_DAY never walks further than this. */
    private const MAX_CALENDAR_WALK = 366;

    /**
     * @param list<Value> $args
     * @param list<Node> $nodes
     */
    public static function call(string $name, array $args, array $nodes, Context $context): Value
    {
        if (in_array($name, self::LAZY, true)) {
            throw new \LogicException("{$name}() is evaluated lazily by the Evaluator.");
        }

        $error = self::firstError($args);

        if ($error !== null) {
            return $error;
        }

        return match ($name) {
            'MIN' => self::extremum($args, -1),
            'MAX' => self::extremum($args, 1),
            'ABS' => self::mapQuantity($args[0], static fn (Rational $r): Rational
                => $r->isNegative() ? Rational::of(-$r->numerator, $r->denominator) : $r),
            'ROUND' => self::quantise($args[0], $args[2], $args[1]->string()),
            'CEIL' => self::quantise($args[0], $args[1], 'up'),
            'FLOOR' => self::quantise($args[0], $args[1], 'down'),
            'TRUNC' => self::quantise($args[0], $args[1], 'down'),
            'CLAMP' => self::clamp($args),
            'MOD' => self::mod($args[0], $args[1]),
            'SIGN' => self::sign($args[0]),

            'MONEY' => self::money($args[0], $args[1]),
            'ALLOCATE' => self::allocate($args[0], $args[1]),
            'ROUND_TO' => self::roundTo($args[0], $args[1]->string(), $args[2]),
            'MONEY_AS_NUMBER' => self::nullOr($args, static fn (): Value
                => Value::number(Arith::divide($args[0]->rational(), Rational::of(100)) ?? Rational::of(0))),

            'IS_NULL' => Value::boolean($args[0]->isNull()),

            'BANDS' => isset($args[2])
                ? self::bands($args[0], $args[1], $args[2])
                : self::bandTable($args[0], $args[1], $context),
            'STEP' => self::step($args[0], $args[1], $args[2], $args[3]->string()),
            'SLAB' => self::slab($args[0], $args[1], $context, false),
            'SLAB_MARGINAL_RELIEF' => self::slab($args[0], $args[1], $context, true),

            'DATEDIFF' => self::dateDiff($args[0], $args[1], $args[2]->string()),
            'DATEADD' => self::dateAdd($args[0], $args[1], $args[2]->string()),
            'ADD_MONTHS' => self::addMonths($args[0], $args[1]),
            'EOMONTH' => self::nullOr($args, static fn (): Value
                => Value::date($args[0]->dateValue()->modify('last day of this month'))),
            'SOMONTH' => self::nullOr($args, static fn (): Value
                => Value::date($args[0]->dateValue()->modify('first day of this month'))),
            'NTH_WEEKDAY' => self::nthWeekday($args[0], $args[1]->string(), $args[2]),
            'LAST_WEEKDAY' => self::nthWeekday($args[0], $args[1]->string(), Value::number(Rational::of(-1))),
            'DAY_OF_WEEK' => self::nullOr($args, static fn (): Value
                => Value::code(strtolower($args[0]->dateValue()->format('D')), 'weekday')),
            'WEEK_OF_MONTH' => self::nullOr($args, static fn (): Value
                => Value::number(Rational::of(intdiv((int) $args[0]->dateValue()->format('j') - 1, 7) + 1))),
            'IS_WORKING_DAY' => self::calendarAnswer($args, $context, 'working'),
            'IS_HOLIDAY' => self::calendarAnswer($args, $context, 'holiday'),
            'IS_WEEKLY_OFF' => self::calendarAnswer($args, $context, 'weekly_off'),
            'HOLIDAY_TYPE' => self::calendarAnswer($args, $context, 'type'),
            'PREV_WORKING_DAY' => self::adjacentWorkingDay($args, $context, -1),
            'NEXT_WORKING_DAY' => self::adjacentWorkingDay($args, $context, +1),
            'CLAMP_DAY_TO_MONTH' => self::clampDayToMonth($args[0], $args[1]),
            'DATE_OF' => self::nullOr($args, static fn (): Value => Value::date($args[0]->dateValue())),
            'TIME_OF' => self::nullOr($args, static fn (): Value => Value::time(
                (int) $args[0]->dateValue()->format('H') * 3600
                + (int) $args[0]->dateValue()->format('i') * 60
                + (int) $args[0]->dateValue()->format('s'),
            )),

            'YEARS_OF_SERVICE', 'MONTHS_OF_SERVICE', 'COMPLETED_YEARS' => self::service($name, $args[0], $context),

            'WORKING_DAYS' => self::workingDays($args, $context),
            'CALENDAR_DAYS' => self::calendarDays($args[0], $args[1]),
            'PAYABLE_DAYS', 'ATTENDANCE_HOURS', 'OT_HOURS', 'LEAVE_UNITS', 'LEAVE_DAYS_IN_RANGE'
                => self::preparedAnswer($name, $args, $context),

            'UPPER' => self::mapText($args[0], strtoupper(...)),
            'LOWER' => self::mapText($args[0], strtolower(...)),
            'TRIM' => self::mapText($args[0], trim(...)),
            'CONCAT' => self::concat($args),
            'LEFT' => self::slice($args[0], $args[1], true),
            'RIGHT' => self::slice($args[0], $args[1], false),

            'COUNT' => self::nullOr($args, static fn (): Value => Value::number(Rational::of(count($args[0]->elements())))),
            'SUM' => self::sum($args[0]),
            'AVG' => self::average($args[0]),
            'ANY' => self::quantifier($args[0], 'ANY'),
            'ALL' => self::quantifier($args[0], 'ALL'),
            'NONE' => self::quantifier($args[0], 'NONE'),

            'TO_MINUTES' => self::toMinutes($args, $context),
            'TO_HOURS' => self::toHours($args[0]),
            'TO_DAYS' => self::toDays($args, $context),

            'OVERLAP_MINUTES' => self::overlapMinutes($args),

            // The catalogue is closed and the type checker has already refused
            // anything not in it; reaching here is a platform bug, not an input.
            default => Value::error(ErrorCode::Type, "No runtime implementation for {$name}()"),
        };
    }

    // ---- comparison, shared with the evaluator ----------------------------

    /**
     * -1, 0, 1, or null when the two are not comparable (which the type checker
     * has normally already refused) or the comparison would overflow.
     */
    public static function compare(Value $a, Value $b): ?int
    {
        [$a, $b] = self::alignDates($a, $b);

        if ($a->payload instanceof Rational && $b->payload instanceof Rational) {
            return Arith::compare($a->payload, $b->payload);
        }

        if ($a->payload instanceof \DateTimeImmutable && $b->payload instanceof \DateTimeImmutable) {
            return $a->payload <=> $b->payload;
        }

        if (is_string($a->payload) && is_string($b->payload)) {
            return strcmp($a->payload, $b->payload) <=> 0;
        }

        if (is_bool($a->payload) && is_bool($b->payload)) {
            return (int) $a->payload <=> (int) $b->payload;
        }

        if (is_int($a->payload) && is_int($b->payload)) {
            return $a->payload <=> $b->payload;
        }

        if (is_array($a->payload) && is_array($b->payload)) {
            return self::compareLists($a->elements(), $b->elements());
        }

        return null;
    }

    /** A Date compared with a DateTime is midnight in the business timezone. */
    private static function alignDates(Value $a, Value $b): array
    {
        if ($a->type->is(TypeKind::Date) && $b->type->is(TypeKind::DateTime)) {
            return [Value::dateTime($a->dateValue()), $b];
        }

        if ($a->type->is(TypeKind::DateTime) && $b->type->is(TypeKind::Date)) {
            return [$a, Value::dateTime($b->dateValue())];
        }

        return [$a, $b];
    }

    /** @param list<Value> $a @param list<Value> $b */
    private static function compareLists(array $a, array $b): ?int
    {
        if (count($a) !== count($b)) {
            return count($a) <=> count($b);
        }

        foreach ($a as $index => $element) {
            $order = self::compare($element, $b[$index]);

            if ($order === null || $order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    // ---- arithmetic --------------------------------------------------------

    /** @param list<Value> $args */
    private static function extremum(array $args, int $direction): Value
    {
        $best = null;

        foreach ($args as $candidate) {
            if ($candidate->isNull()) {
                continue;   // Null arguments are ignored; all-null → Null
            }

            if ($best === null) {
                $best = $candidate;

                continue;
            }

            $order = self::compare($candidate, $best);

            if ($order === null) {
                return Value::error(ErrorCode::Type, 'MIN/MAX arguments are not comparable');
            }

            if ($order === $direction) {
                $best = $candidate;
            }
        }

        return $best ?? Value::null();
    }

    /** @param list<Value> $args */
    private static function clamp(array $args): Value
    {
        [$value, $low, $high] = $args;

        if ($value->isNull()) {
            return Value::null();
        }

        $order = self::compare($low, $high);

        if ($order === null) {
            return Value::error(ErrorCode::Type, 'CLAMP bounds are not comparable');
        }

        if ($order > 0) {
            return Value::error(ErrorCode::Arg, 'CLAMP(value, lo, hi) requires lo <= hi');
        }

        if ((self::compare($value, $low) ?? 0) < 0) {
            return $low;
        }

        return (self::compare($value, $high) ?? 0) > 0 ? $high : $value;
    }

    private static function mod(Value $a, Value $b): Value
    {
        if ($a->isNull() || $b->isNull()) {
            return Value::null();
        }

        if ($b->rational()->isZero()) {
            return Value::error(ErrorCode::DivZero, 'MOD() by zero');
        }

        $quotient = Arith::divide($a->rational(), $b->rational());

        if ($quotient === null) {
            return self::precision();
        }

        $whole = Rational::of(intdiv($quotient->numerator, $quotient->denominator));    // toward zero
        $product = Arith::multiply($whole, $b->rational());
        $remainder = $product === null ? null : Arith::subtract($a->rational(), $product);

        return $remainder === null ? self::precision() : Value::number($remainder);
    }

    private static function sign(Value $a): Value
    {
        if ($a->isNull()) {
            return Value::null();
        }

        $rational = $a->rational();

        return Value::number(Rational::of($rational->isZero() ? 0 : ($rational->isNegative() ? -1 : 1)));
    }

    /**
     * Quantise to a unit under an explicit mode. `unit` is the quantum (0.01, 1,
     * 0.25) and the mode is never defaulted — spine S5.4.
     */
    private static function quantise(Value $value, Value $unit, string $mode): Value
    {
        if ($value->isNull() || $unit->isNull()) {
            return Value::null();
        }

        $quantum = $unit->rational();

        if ($quantum->isZero()) {
            return Value::error(ErrorCode::Arg, 'The rounding unit may not be zero');
        }

        // Money is held in minor units, so a 0.01 quantum is 1 paisa.
        if ($value->type->is(TypeKind::Money) || $value->type->is(TypeKind::Rate)) {
            $quantum = Arith::multiply($quantum, Rational::of(100)) ?? $quantum;
        }

        $steps = Arith::divide($value->rational(), $quantum);

        if ($steps === null) {
            return self::precision();
        }

        $rounded = Arith::multiply(Rational::of(self::roundToInt($steps, $mode)), $quantum);

        return $rounded === null ? self::precision() : self::retype($value, $rounded);
    }

    /** The seven declared rounding modes (§5.6, §6.3). No default, ever. */
    private static function roundToInt(Rational $value, string $mode): int
    {
        if ($mode === 'half_down') {
            // Ties toward zero. Support\RoundingMode has no case for it because
            // no SmartPRS component declares it; SPX exposes it because §5.6 does.
            $quotient = intdiv($value->numerator, $value->denominator);
            $remainder = $value->numerator - $quotient * $value->denominator;

            if ($remainder === 0) {
                return $quotient;
            }

            return abs($remainder) * 2 > $value->denominator
                ? $quotient + ($value->numerator < 0 ? -1 : 1)
                : $quotient;
        }

        return $value->toInt(match ($mode) {
            'half_up' => RoundingMode::HalfUp,
            'half_even' => RoundingMode::HalfEven,
            'up' => RoundingMode::Up,
            'down' => RoundingMode::Down,
            'ceil' => RoundingMode::Ceiling,
            'floor' => RoundingMode::Floor,
            default => RoundingMode::HalfUp,
        });
    }

    // ---- money -------------------------------------------------------------

    private static function money(Value $amount, Value $currency): Value
    {
        if ($amount->isNull()) {
            return Value::null();
        }

        $minor = Arith::multiply($amount->rational(), Rational::of(100));

        if ($minor === null) {
            return self::precision();
        }

        if ($minor->denominator !== 1) {
            return Value::error(
                ErrorCode::Arg,
                'MONEY() amount ' . $amount->rational() . ' is finer than the minor unit; round it explicitly',
            );
        }

        return Value::moneyOfMinor($minor, $currency->string());
    }

    /**
     * Spine S7 / Part VIII §6.4: the parts sum EXACTLY to the whole, and the
     * residue lands on the last element. The one correct pattern in SmartPRS 2.
     */
    private static function allocate(Value $amount, Value $weights): Value
    {
        if ($amount->isNull() || $weights->isNull()) {
            return Value::null();
        }

        $minor = $amount->rational();

        if ($minor->denominator !== 1) {
            return Value::error(ErrorCode::Arg, 'ALLOCATE() needs an exact amount in minor units');
        }

        $parts = $weights->elements();

        if ($parts === []) {
            return Value::error(ErrorCode::Arg, 'ALLOCATE() needs at least one weight');
        }

        $total = Rational::of(0);

        foreach ($parts as $weight) {
            if ($weight->isNull() || $weight->rational()->isNegative()) {
                return Value::error(ErrorCode::Arg, 'ALLOCATE() weights must be non-negative numbers');
            }

            $total = Arith::add($total, $weight->rational()) ?? $total;
        }

        if ($total->isZero()) {
            return Value::error(ErrorCode::Arg, 'ALLOCATE() weights must sum to more than zero');
        }

        $allocated = 0;
        $result = [];
        $last = count($parts) - 1;

        foreach ($parts as $index => $weight) {
            if ($index === $last) {
                break;
            }

            $share = Arith::multiply($minor, $weight->rational());
            $share = $share === null ? null : Arith::divide($share, $total);

            if ($share === null) {
                return self::precision();
            }

            $paise = intdiv($share->numerator, $share->denominator);   // toward zero
            $allocated += $paise;
            $result[] = Value::moneyOfMinor(Rational::of($paise), $amount->type->parameter ?? 'INR');
        }

        $result[] = Value::moneyOfMinor(
            Rational::of($minor->numerator - $allocated),
            $amount->type->parameter ?? 'INR',
        );

        return Value::list($result, $amount->type);
    }

    private static function roundTo(Value $amount, string $mode, Value $unit): Value
    {
        if ($amount->isNull() || $unit->isNull()) {
            return Value::null();
        }

        $quantum = $unit->rational();

        if ($quantum->isZero()) {
            return Value::error(ErrorCode::Arg, 'ROUND_TO() unit may not be zero');
        }

        $steps = Arith::divide($amount->rational(), $quantum);

        if ($steps === null) {
            return self::precision();
        }

        $rounded = Arith::multiply(Rational::of(self::roundToInt($steps, $mode)), $quantum);

        return $rounded === null ? self::precision() : self::retype($amount, $rounded);
    }

    // ---- bands, steps, slabs -----------------------------------------------

    /**
     * The two-argument form: one configured band table, resolved by the caller
     * and put in the prepared context. Same walk, same rules — the difference is
     * only where the bounds and results came from, and that an administrator
     * maintains them as an artefact rather than an author inlining them.
     */
    private static function bandTable(Value $value, Value $code, Context $context): Value
    {
        if ($value->isNull()) {
            return Value::null();
        }

        $table = $context->bandTable($code->string());

        if ($table === null) {
            return Value::error(
                ErrorCode::MissingInput,
                "Band table '{$code->string()}' is not in the prepared context",
            );
        }

        return self::bands(
            $value,
            Value::list($table["bounds"]),
            Value::list($table["results"]),
        );
    }

    private static function bands(Value $value, Value $bounds, Value $results): Value
    {
        if ($value->isNull()) {
            return Value::null();
        }

        $lower = $bounds->elements();
        $outcomes = $results->elements();

        if (count($lower) !== count($outcomes) || $lower === []) {
            return Value::error(ErrorCode::Arg, 'BANDS() needs one result per lower bound, and at least one band');
        }

        $chosen = null;
        $previous = null;

        foreach ($lower as $index => $bound) {
            if ($previous !== null && (self::compare($bound, $previous) ?? 0) <= 0) {
                return Value::error(ErrorCode::Arg, 'BANDS() lower bounds must ascend strictly');
            }

            $previous = $bound;
            $order = self::compare($value, $bound);

            if ($order === null) {
                return Value::error(ErrorCode::Type, 'BANDS() value and bounds are not comparable');
            }

            if ($order >= 0) {
                $chosen = $outcomes[$index];
            }
        }

        // Below the first band: the domain is not covered, and guessing is how a
        // sub-L1 late gets charged at the L1 rate.
        return $chosen ?? Value::error(
            ErrorCode::Arg,
            'BANDS() value ' . $value->describe() . ' is below the first band; the domain is not covered',
        );
    }

    private static function step(Value $value, Value $block, Value $step, string $mode): Value
    {
        if ($value->isNull() || $block->isNull() || $step->isNull()) {
            return Value::null();
        }

        if ($block->rational()->isZero()) {
            return Value::error(ErrorCode::DivZero, 'STEP() block size may not be zero');
        }

        $blocks = Arith::divide($value->rational(), $block->rational());

        if ($blocks === null) {
            return self::precision();
        }

        $count = self::roundToInt($blocks, match ($mode) {
            'ceil' => 'ceil',
            'floor' => 'floor',
            default => 'half_up',
        });

        $result = Arith::multiply(Rational::of($count), $step->rational());

        return $result === null ? self::precision() : self::retype($step, $result);
    }

    /**
     * Walks a statutory slab table from the resolved rule pack version. Marginal
     * by default; a table may declare itself flat. The table is data in the
     * prepared context — no threshold is ever a literal in a rule.
     */
    private static function slab(Value $value, Value $tableCode, Context $context, bool $withRelief): Value
    {
        if ($value->isNull()) {
            return Value::null();
        }

        $table = $context->slabTable($tableCode->string());

        if ($table === null) {
            return Value::error(ErrorCode::Arg, "Slab table '{$tableCode->string()}' is not in the prepared context");
        }

        $tax = self::walkSlab($value->rational(), $table, $value->type);

        if ($tax->isError() || !$withRelief) {
            return $tax;
        }

        $threshold = $table['marginal_relief']['threshold'] ?? null;

        if ($threshold === null) {
            return Value::error(
                ErrorCode::Arg,
                "Slab table '{$tableCode->string()}' declares no marginal-relief rule",
            );
        }

        $over = Arith::subtract($value->rational(), Rational::of($threshold));

        if ($over === null) {
            return self::precision();
        }

        if ($over->isNegative() || $over->isZero()) {
            return $tax;
        }

        // Relief caps the charge at (charge at the threshold) + (income over it),
        // applied ONCE here rather than in each caller — SmartPRS 2 applies 87A
        // in the caller and one caller forgets (salaryTdsMonthly():1836-1840).
        $atThreshold = self::walkSlab(Rational::of($threshold), $table, $value->type);

        if ($atThreshold->isError()) {
            return $atThreshold;
        }

        $cap = Arith::add($atThreshold->rational(), $over);

        if ($cap === null) {
            return self::precision();
        }

        return (Arith::compare($tax->rational(), $cap) ?? 0) > 0
            ? self::retype($value, $cap)
            : $tax;
    }

    /** @param array{bands: list<array{from: int, rate: string}>, flat: bool} $table */
    private static function walkSlab(Rational $amount, array $table, Type $type): Value
    {
        $bands = $table['bands'];
        $charge = Rational::of(0);

        foreach ($bands as $index => $band) {
            $from = Rational::of($band['from']);
            $rate = Rational::fromDecimalString($band['rate']);
            $ceiling = isset($bands[$index + 1]) ? Rational::of($bands[$index + 1]['from']) : null;

            // The lower bound is inclusive: an amount exactly on a band boundary
            // is in that band, which matters for a flat table.
            if ((Arith::compare($amount, $from) ?? 0) < 0) {
                continue;
            }

            if ($table['flat'] ?? false) {
                // A flat table charges the whole amount at the band it lands in.
                if ($ceiling === null || (Arith::compare($amount, $ceiling) ?? 0) < 0) {
                    $flat = Arith::multiply($amount, $rate);
                    $flat = $flat === null ? null : Arith::divide($flat, Rational::of(100));

                    return $flat === null
                        ? self::precision()
                        : Value::moneyOfMinor($flat, $type->parameter ?? 'INR');
                }

                continue;
            }

            $top = $ceiling !== null && (Arith::compare($amount, $ceiling) ?? 0) > 0 ? $ceiling : $amount;
            $slice = Arith::subtract($top, $from);
            $slice = $slice === null ? null : Arith::multiply($slice, $rate);
            $slice = $slice === null ? null : Arith::divide($slice, Rational::of(100));

            if ($slice === null) {
                return self::precision();
            }

            $charge = Arith::add($charge, $slice) ?? $charge;
        }

        return Value::moneyOfMinor($charge, $type->parameter ?? 'INR');
    }

    // ---- dates -------------------------------------------------------------

    private static function dateDiff(Value $a, Value $b, string $unit): Value
    {
        if ($a->isNull() || $b->isNull()) {
            return Value::null();
        }

        $from = $a->dateValue();
        $to = $b->dateValue();

        $days = (int) $from->diff($to)->format('%r%a');

        return Value::number(Rational::of(match ($unit) {
            'days' => $days,
            'weeks' => intdiv($days, 7),
            'months' => self::wholeMonths($from, $to),
            default => intdiv(self::wholeMonths($from, $to), 12),
        }));
    }

    /** Signed, truncated toward zero, and a partial month does not count. */
    private static function wholeMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'));

        if ($months > 0 && (int) $to->format('j') < (int) $from->format('j')) {
            $months--;
        }

        if ($months < 0 && (int) $to->format('j') > (int) $from->format('j')) {
            $months++;
        }

        return $months;
    }

    private static function dateAdd(Value $date, Value $count, string $unit): Value
    {
        if ($date->isNull() || $count->isNull()) {
            return Value::null();
        }

        $n = self::wholeNumber($count);

        if ($n === null) {
            return Value::error(ErrorCode::Arg, 'DATEADD() takes a whole number of units');
        }

        if ($unit === 'months' || $unit === 'years') {
            return self::addMonths($date, Value::number(Rational::of($unit === 'years' ? $n * 12 : $n)));
        }

        $days = $unit === 'weeks' ? $n * 7 : $n;

        return Value::date($date->dateValue()->modify(($days >= 0 ? '+' : '') . $days . ' days'));
    }

    /** Clamps to month end: 31-Jan + 1 month is 28-Feb, not 3-Mar. */
    private static function addMonths(Value $date, Value $count): Value
    {
        if ($date->isNull() || $count->isNull()) {
            return Value::null();
        }

        $n = self::wholeNumber($count);

        if ($n === null) {
            return Value::error(ErrorCode::Arg, 'ADD_MONTHS() takes a whole number of months');
        }

        $start = $date->dateValue();
        $day = (int) $start->format('j');
        $target = $start->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . $n . ' months');
        $length = (int) $target->format('t');

        return Value::date($target->setDate(
            (int) $target->format('Y'),
            (int) $target->format('n'),
            min($day, $length),
        ));
    }

    /**
     * Counts ACTUAL weekday occurrences, which is what "second Saturday" means.
     * SmartPRS 2 uses ceil(day/7) — a day-of-month band that diverges from the
     * colloquial meaning in most months (PayrollGenController.php:83).
     */
    private static function nthWeekday(Value $month, string $weekday, Value $n): Value
    {
        if ($month->isNull() || $n->isNull()) {
            return Value::null();
        }

        $index = self::wholeNumber($n);
        $target = array_search(strtolower($weekday), Catalogue::WEEKDAYS, true);

        if ($index === null || $index === 0 || $target === false) {
            return Value::error(ErrorCode::Arg, 'NTH_WEEKDAY() takes a weekday code and a non-zero n');
        }

        $first = $month->dateValue()->modify('first day of this month');
        $length = (int) $first->format('t');
        $matches = [];

        for ($day = 1; $day <= $length; $day++) {
            $candidate = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day);

            // ISO-8601 day of week: 1 = Monday, matching the WEEKDAYS order.
            if ((int) $candidate->format('N') - 1 === $target) {
                $matches[] = $candidate;
            }
        }

        $chosen = $index > 0 ? ($matches[$index - 1] ?? null) : ($matches[count($matches) + $index] ?? null);

        return $chosen === null
            ? Value::error(ErrorCode::Arg, "There is no {$index}th {$weekday} in " . $first->format('F Y'))
            : Value::date($chosen);
    }

    private static function clampDayToMonth(Value $day, Value $month): Value
    {
        if ($day->isNull() || $month->isNull()) {
            return Value::null();
        }

        $target = self::wholeNumber($day);

        if ($target === null || $target < 1) {
            return Value::error(ErrorCode::Arg, 'CLAMP_DAY_TO_MONTH() takes a day number of 1 or more');
        }

        $first = $month->dateValue()->modify('first day of this month');

        return Value::date($first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min($target, (int) $first->format('t')),
        ));
    }

    // ---- calendar ----------------------------------------------------------

    /** @param list<Value> $args */
    private static function calendarAnswer(array $args, Context $context, string $question): Value
    {
        if ($args[0]->isNull() || $args[1]->isNull()) {
            return Value::null();
        }

        $scope = $args[1]->string();
        $calendar = $context->calendar($scope);

        if ($calendar === null) {
            return Value::error(ErrorCode::MissingInput, "Calendar scope '{$scope}' is not in the prepared context");
        }

        $day = $args[0]->dateValue()->format('Y-m-d');
        $isHoliday = in_array($day, $calendar['holidays'], true);
        $isWeeklyOff = in_array($day, $calendar['weekly_offs'], true);

        return match ($question) {
            'holiday' => Value::boolean($isHoliday),
            'weekly_off' => Value::boolean($isWeeklyOff),
            'working' => Value::boolean(!$isHoliday && !$isWeeklyOff),
            default => Value::code($calendar['holiday_types'][$day] ?? ($isHoliday ? 'national' : 'none'), 'holiday_type'),
        };
    }

    /**
     * The nearest working day in one direction, on the resolved calendar.
     *
     * Bounded at a year: a calendar that declares every day non-working is a
     * configuration error, and an unbounded walk would be a way to burn the
     * evaluation budget from inside a single node (§10.1b). The bound is a named
     * error, never a silent stop.
     *
     * @param list<Value> $args
     */
    private static function adjacentWorkingDay(array $args, Context $context, int $step): Value
    {
        if ($args[0]->isNull() || $args[1]->isNull()) {
            return Value::null();
        }

        $scope = $args[1]->string();
        $calendar = $context->calendar($scope);

        if ($calendar === null) {
            return Value::error(ErrorCode::MissingInput, "Calendar scope '{$scope}' is not in the prepared context");
        }

        $move = ($step > 0 ? '+' : '-') . '1 day';
        $day = $args[0]->dateValue();

        for ($i = 0; $i < self::MAX_CALENDAR_WALK; $i++) {
            $day = $day->modify($move);
            $key = $day->format('Y-m-d');

            if (!in_array($key, $calendar['holidays'], true)
                && !in_array($key, $calendar['weekly_offs'], true)
            ) {
                return Value::date($day);
            }
        }

        return Value::error(
            ErrorCode::Arg,
            'No working day within ' . self::MAX_CALENDAR_WALK . " days on calendar '{$scope}'"
        );
    }

    /** ONE definition of a working day. SmartPRS 2 has three incompatible ones. */
    private static function workingDays(array $args, Context $context): Value
    {
        if ($args[0]->isNull() || $args[1]->isNull() || $args[2]->isNull()) {
            return Value::null();
        }

        $scope = $args[2]->string();
        $calendar = $context->calendar($scope);

        if ($calendar === null) {
            return Value::error(ErrorCode::MissingInput, "Calendar scope '{$scope}' is not in the prepared context");
        }

        $from = $args[0]->dateValue();
        $to = $args[1]->dateValue();

        if ($from > $to) {
            return Value::error(ErrorCode::Arg, 'WORKING_DAYS() needs from <= to');
        }

        $count = 0;

        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');

            if (!in_array($key, $calendar['holidays'], true) && !in_array($key, $calendar['weekly_offs'], true)) {
                $count++;
            }
        }

        return Value::number(Rational::of($count));
    }

    private static function calendarDays(Value $from, Value $to): Value
    {
        if ($from->isNull() || $to->isNull()) {
            return Value::null();
        }

        $days = (int) $from->dateValue()->diff($to->dateValue())->format('%r%a');

        return Value::number(Rational::of($days >= 0 ? $days + 1 : $days - 1));
    }

    /**
     * The data-backed functions. Their answers are prepared by the caller under
     * tenant scope and authorisation; the evaluator never fetches one, which is
     * what keeps evaluation pure and the safety argument structural.
     *
     * @param list<Value> $args
     */
    private static function preparedAnswer(string $name, array $args, Context $context): Value
    {
        $key = Context::key($name, $args);
        $answer = $context->prepared($key);

        return $answer ?? Value::error(
            ErrorCode::MissingInput,
            "{$key} was not prepared for this evaluation",
        );
    }

    private static function service(string $name, Value $asOf, Context $context): Value
    {
        if ($asOf->isNull()) {
            return Value::null();
        }

        $joining = $context->symbol('employee.joining_date');

        if ($joining === null || $joining->isNull()) {
            return Value::error(ErrorCode::MissingInput, 'employee.joining_date is not in the prepared context');
        }

        $from = $joining->dateValue();
        $to = $asOf->dateValue();
        $months = self::wholeMonths($from, $to);

        if ($name === 'COMPLETED_YEARS') {
            return Value::number(Rational::of(intdiv($months, 12)));
        }

        // Fractional part: elapsed days since the last whole unit, over the
        // length of the unit that follows it. Exact, and not 365.25.
        $anchor = self::addMonths(
            Value::date($from),
            Value::number(Rational::of($name === 'YEARS_OF_SERVICE' ? intdiv($months, 12) * 12 : $months)),
        )->dateValue();
        $next = self::addMonths(
            Value::date($anchor),
            Value::number(Rational::of($name === 'YEARS_OF_SERVICE' ? 12 : 1)),
        )->dateValue();

        $elapsed = (int) $anchor->diff($to)->format('%r%a');
        $span = (int) $anchor->diff($next)->format('%a');
        $whole = $name === 'YEARS_OF_SERVICE' ? intdiv($months, 12) : $months;

        $fraction = $span === 0 ? Rational::of(0) : Rational::of($elapsed, $span);
        $total = Arith::add(Rational::of($whole), $fraction);

        return $total === null ? self::precision() : Value::number($total);
    }

    // ---- text --------------------------------------------------------------

    /** Null in, Null out — the mapping is not applied to an absent value. */
    private static function mapText(Value $value, \Closure $map): Value
    {
        return $value->isNull() ? Value::null() : self::boundedText($map($value->string()));
    }

    /** @param list<Value> $args */
    private static function concat(array $args): Value
    {
        $result = '';

        foreach ($args as $argument) {
            if ($argument->isNull()) {
                continue;   // a null joins as nothing, not as the word "null"
            }

            $result .= $argument->string();
        }

        return self::boundedText($result);
    }

    private static function slice(Value $text, Value $length, bool $fromLeft): Value
    {
        if ($text->isNull() || $length->isNull()) {
            return Value::null();
        }

        $n = self::wholeNumber($length);

        if ($n === null || $n < 0) {
            return Value::error(ErrorCode::Arg, 'LEFT()/RIGHT() take a whole, non-negative length');
        }

        $subject = $text->string();

        return Value::text($fromLeft
            ? mb_substr($subject, 0, $n, 'UTF-8')
            : ($n === 0 ? '' : mb_substr($subject, -$n, null, 'UTF-8')));
    }

    private static function boundedText(string $value): Value
    {
        return mb_strlen($value, 'UTF-8') > Sandbox::MAX_TEXT_CHARS
            ? Value::error(ErrorCode::Arg, 'Text is bounded to ' . Sandbox::MAX_TEXT_CHARS . ' characters')
            : Value::text($value);
    }

    // ---- lists -------------------------------------------------------------

    private static function sum(Value $list): Value
    {
        if ($list->isNull()) {
            return Value::null();
        }

        $elements = $list->elements();
        $total = Rational::of(0);

        foreach ($elements as $element) {
            if ($element->isError()) {
                return $element;
            }

            if ($element->isNull()) {
                continue;
            }

            $next = Arith::add($total, $element->rational());

            if ($next === null) {
                return self::precision();
            }

            $total = $next;
        }

        $element = $list->type->element;

        return Value::quantityOfType(
            $element === null || $element->is(TypeKind::Null) ? Type::number() : $element,
            $total,
        );
    }

    private static function average(Value $list): Value
    {
        if ($list->isNull()) {
            return Value::null();
        }

        $elements = $list->elements();

        if ($elements === []) {
            return Value::null();   // empty → Null, declared, not zero
        }

        $sum = self::sum($list);

        if ($sum->isError()) {
            return $sum;
        }

        $average = Arith::divide($sum->rational(), Rational::of(count($elements)));

        return $average === null ? self::precision() : Value::number($average);
    }

    private static function quantifier(Value $list, string $kind): Value
    {
        if ($list->isNull()) {
            return Value::null();
        }

        $anyTrue = false;
        $anyFalse = false;
        $anyUnknown = false;

        foreach ($list->elements() as $element) {
            if ($element->isError()) {
                return $element;
            }

            match (true) {
                $element->isNull() => $anyUnknown = true,
                $element->isTrue() => $anyTrue = true,
                default => $anyFalse = true,
            };
        }

        // Three-valued logic reaches the quantifiers: an unknown that could
        // change the answer makes the answer unknown. Empty list: ANY is FALSE,
        // ALL and NONE are TRUE (§5.6).
        return match ($kind) {
            'ANY' => $anyTrue ? Value::boolean(true) : ($anyUnknown ? Value::null() : Value::boolean(false)),
            'ALL' => $anyFalse ? Value::boolean(false) : ($anyUnknown ? Value::null() : Value::boolean(true)),
            default => $anyTrue ? Value::boolean(false) : ($anyUnknown ? Value::null() : Value::boolean(true)),
        };
    }

    // ---- unit conversion ----------------------------------------------------

    /** @param list<Value> $args */
    private static function toMinutes(array $args, Context $context): Value
    {
        $value = $args[0];

        if ($value->isNull()) {
            return Value::null();
        }

        if ($value->type->is(TypeKind::Minutes)) {
            return $value;
        }

        if ($value->type->is(TypeKind::Hours)) {
            $minutes = Arith::multiply($value->rational(), Rational::of(60));

            return $minutes === null ? self::precision() : Value::quantity(TypeKind::Minutes, $minutes);
        }

        if ($value->type->is(TypeKind::Duration)) {
            $minutes = Arith::divide($value->rational(), Rational::of(60));

            return $minutes === null ? self::precision() : Value::quantity(TypeKind::Minutes, $minutes);
        }

        $length = self::dayLength($args[1] ?? null, $context);

        if ($length instanceof Value) {
            return $length;
        }

        $minutes = Arith::multiply($value->rational(), $length);

        return $minutes === null ? self::precision() : Value::quantity(TypeKind::Minutes, $minutes);
    }

    private static function toHours(Value $value): Value
    {
        if ($value->isNull()) {
            return Value::null();
        }

        if ($value->type->is(TypeKind::Hours)) {
            return $value;
        }

        $divisor = $value->type->is(TypeKind::Duration) ? 3600 : 60;
        $hours = Arith::divide($value->rational(), Rational::of($divisor));

        return $hours === null ? self::precision() : Value::quantity(TypeKind::Hours, $hours);
    }

    /** @param list<Value> $args */
    private static function toDays(array $args, Context $context): Value
    {
        $value = $args[0];

        if ($value->isNull()) {
            return Value::null();
        }

        if ($value->type->base()->is(TypeKind::Days)) {
            return $value->as(Type::days());
        }

        $length = self::dayLength($args[1] ?? null, $context);

        if ($length instanceof Value) {
            return $length;
        }

        $minutes = $value->type->is(TypeKind::Hours)
            ? Arith::multiply($value->rational(), Rational::of(60))
            : $value->rational();

        $days = $minutes === null ? null : Arith::divide($minutes, $length);

        return $days === null ? self::precision() : Value::quantity(TypeKind::Days, $days);
    }

    /** The day-length policy, or the error Value explaining its absence. */
    private static function dayLength(?Value $code, Context $context): Rational|Value
    {
        if ($code === null || $code->isNull()) {
            return Value::error(ErrorCode::Arg, 'This conversion needs a day-length policy code (R-VIII-016)');
        }

        $length = $context->dayLength($code->string());

        if ($length === null) {
            return Value::error(
                ErrorCode::MissingInput,
                "Day-length policy '{$code->string()}' is not in the prepared context",
            );
        }

        return $length->isZero()
            ? Value::error(ErrorCode::Arg, "Day-length policy '{$code->string()}' declares a zero-length day")
            : $length;
    }

    // ---- interval -----------------------------------------------------------

    /**
     * Minutes of intersection, anchored to absolute instants so a window
     * crossing midnight is ONE interval and not two (Part XI, WT-05).
     *
     * @param list<Value> $args
     */
    private static function overlapMinutes(array $args): Value
    {
        foreach ($args as $argument) {
            if ($argument->isNull()) {
                return Value::null();
            }
        }

        // All four bounds must be the same kind: seconds-of-day and absolute
        // instants are not on the same number line, and intersecting them would
        // produce a confident wrong answer.
        $kinds = array_unique(array_map(static fn (Value $v): string => $v->type->kind->value, $args));

        if (count($kinds) !== 1) {
            return Value::error(
                ErrorCode::Arg,
                'OVERLAP_MINUTES() needs all four bounds as DateTime or all four as Time, not a mixture',
            );
        }

        $seconds = [];

        foreach ($args as $argument) {
            $seconds[] = $argument->type->is(TypeKind::Time)
                ? (int) $argument->payload
                : $argument->dateValue()->getTimestamp();
        }

        [$aFrom, $aTo, $bFrom, $bTo] = $seconds;

        if ($aTo < $aFrom || $bTo < $bFrom) {
            return Value::error(ErrorCode::Arg, 'OVERLAP_MINUTES() intervals must not end before they start');
        }

        $overlap = min($aTo, $bTo) - max($aFrom, $bFrom);

        return Value::quantity(TypeKind::Minutes, Rational::of(max(0, $overlap), 60));
    }

    // ---- plumbing -----------------------------------------------------------

    /** @param list<Value> $args */
    private static function firstError(array $args): ?Value
    {
        foreach ($args as $argument) {
            if ($argument->isError()) {
                return $argument;
            }
        }

        return null;
    }

    /** @param list<Value> $args */
    private static function nullOr(array $args, \Closure $compute): Value
    {
        foreach ($args as $argument) {
            if ($argument->isNull()) {
                return Value::null();
            }
        }

        return $compute();
    }

    private static function mapQuantity(Value $value, \Closure $map): Value
    {
        return $value->isNull() ? Value::null() : self::retype($value, $map($value->rational()));
    }

    private static function retype(Value $template, Rational $payload): Value
    {
        return Value::quantityOfType($template->type, $payload);
    }

    private static function wholeNumber(Value $value): ?int
    {
        $rational = $value->rational();

        return $rational->denominator === 1 ? $rational->numerator : null;
    }

    private static function precision(): Value
    {
        return Value::error(
            ErrorCode::Precision,
            'Exact arithmetic exceeded the representable range; simplify the expression',
        );
    }
}
