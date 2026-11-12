<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Types;

/**
 * THE COERCION MATRIX — Part VII §5.4.2 and Part VIII §5.2/§5.4, verbatim.
 *
 * This class is the barrier. SmartPRS 2's whole unit-confusion defect family
 * exists because nothing objects when a quantity measured in working days is
 * divided by a count of calendar days (PayrollGenController.php:1268-1284,
 * ₹477.89 per employee per two-day absence, every month, invisibly). Here that
 * expression does not type, and the rule that contains it cannot be published.
 *
 * The rules that matter most, stated so they can be read without the table:
 *
 *   Money * Money        type error          Money / Money    type error
 *   Money * Number       Money               Money / Days     Rate<Money/Days>
 *   Money % Number       Money  (percent-of) Rate<M/U> * U    Money
 *   Days  + Hours        type error          Days + Days      Days
 *   Money → Number       never (MONEY_AS_NUMBER, deliberately ugly)
 *   Number → Money       never (MONEY(amount, currency))
 *   Number → Days        never (TO_DAYS, which names the day-length policy)
 *
 * Anything not permitted below is a publish-time type error. There is no
 * fallback branch and no "best effort" arm.
 */
final class Coercion
{
    /** Types on which `< <= > >=` mean anything. Ordering Text or Code is not ordering. */
    private const ORDERED = [
        TypeKind::Number, TypeKind::Percentage, TypeKind::Money, TypeKind::Days, TypeKind::DayFraction,
        TypeKind::Hours, TypeKind::Minutes, TypeKind::Duration, TypeKind::Date, TypeKind::DateTime,
        TypeKind::Time, TypeKind::Rate,
    ];

    private const UNITS = [TypeKind::Days, TypeKind::Hours, TypeKind::Minutes];

    /**
     * The arithmetic result type, or null for a type error.
     *
     * @param string $op one of + - * / %  (`%` is percent-of; modulo is MOD())
     */
    public static function arithmetic(Type $left, string $op, Type $right): ?Type
    {
        // A NULL literal in arithmetic is a publish-time error: a nullable symbol
        // has the symbol's type, and its null-ness is a runtime concern (§5.4.3).
        if ($left->is(TypeKind::Null) || $right->is(TypeKind::Null)) {
            return null;
        }

        $l = self::erase($op, $left);
        $r = self::erase($op, $right);

        return match ($l->kind) {
            TypeKind::Money => self::moneyArithmetic($l, $op, $r),
            TypeKind::Number => self::numberArithmetic($l, $op, $r),
            TypeKind::Rate => self::rateArithmetic($l, $op, $r),
            TypeKind::Days, TypeKind::Hours, TypeKind::Minutes => self::unitArithmetic($l, $op, $r),
            TypeKind::DateTime => $op === '-' && $r->is(TypeKind::DateTime) ? Type::duration() : null,
            default => null,    // Date, Time, Boolean, Text, Code, List, Duration
        };
    }

    private static function moneyArithmetic(Type $left, string $op, Type $right): ?Type
    {
        if ($right->is(TypeKind::Money)) {
            // Same currency adds; different currency never does — no implicit FX.
            return in_array($op, ['+', '-'], true) && $left->parameter === $right->parameter ? $left : null;
        }

        if ($right->is(TypeKind::Number)) {
            return in_array($op, ['*', '/', '%'], true) ? $left : null;
        }

        // Money / Days → Rate<Money/Days>: the per-day value, and the only way
        // to make one (R-VIII-017).
        if ($op === '/' && $right->is(...self::UNITS)) {
            return Type::rate($right->kind, $left->parameter ?? 'INR');
        }

        return null;
    }

    private static function numberArithmetic(Type $left, string $op, Type $right): ?Type
    {
        if ($right->is(TypeKind::Number)) {
            return Type::number();
        }

        // `*` is commutative across the whole matrix; `/`, `-` and `%` are not.
        if ($op !== '*') {
            return null;
        }

        return match (true) {
            $right->is(TypeKind::Money), $right->is(TypeKind::Rate) => $right,
            $right->is(...self::UNITS) => $right,
            default => null,
        };
    }

    private static function rateArithmetic(Type $left, string $op, Type $right): ?Type
    {
        $unit = $left->element?->kind;

        if ($right->is(TypeKind::Rate)) {
            return in_array($op, ['+', '-'], true)
                && $right->element?->kind === $unit
                && $right->parameter === $left->parameter
                ? $left
                : null;
        }

        if ($right->is(TypeKind::Number)) {
            return in_array($op, ['*', '/', '%'], true) ? $left : null;
        }

        // Rate<Money/U> * U → Money. Only U: a per-day rate times hours is the
        // defect this type exists to prevent.
        if ($op === '*' && $unit !== null && $right->is($unit)) {
            return Type::money($left->parameter ?? 'INR');
        }

        return null;
    }

    private static function unitArithmetic(Type $left, string $op, Type $right): ?Type
    {
        if ($right->kind === $left->kind) {
            return match ($op) {
                '+', '-' => $left,
                '/' => Type::number(),      // a ratio; both share a day-length policy
                default => null,
            };
        }

        if ($right->is(TypeKind::Number)) {
            return in_array($op, ['*', '/', '%'], true) ? $left : null;
        }

        if ($op === '*' && $right->is(TypeKind::Rate) && $right->element?->kind === $left->kind) {
            return Type::money($right->parameter ?? 'INR');
        }

        return null;    // Days + Hours, Hours + Minutes, Days * Days …
    }

    /**
     * Refinement erasure for arithmetic (Part VIII §5.2).
     *
     * `DayFraction → Days` always (widening). `Percentage → Number` in `*`, `/`
     * and `%` only — the table's contexts.
     *
     * ponytail: the table is silent on `Percentage + Number`, so this rejects
     * it. Widen it here if authors ask for `policy.value('pct') + 5`; the
     * decision belongs in the table, not in a quiet parser default.
     */
    private static function erase(string $op, Type $type): Type
    {
        if ($type->is(TypeKind::DayFraction)) {
            return Type::days();
        }

        if ($type->is(TypeKind::Percentage) && in_array($op, ['*', '/', '%'], true)) {
            return Type::number();
        }

        return $type;
    }

    /**
     * Can these two be compared with `=` or `<>`? A Null operand is always
     * comparable and always yields UNKNOWN (§5.4.3).
     *
     * Code-versus-string-literal is NOT decided here: it needs the literal and
     * the value set, so TypeChecker owns it.
     */
    public static function comparable(Type $left, Type $right): bool
    {
        if ($left->is(TypeKind::Null) || $right->is(TypeKind::Null)) {
            return true;
        }

        $l = $left->base();
        $r = $right->base();

        // Date coerces to DateTime in a comparison with a DateTime: midnight in
        // the context's business timezone. Never the other way (DATE_OF()).
        if (($l->is(TypeKind::Date) && $r->is(TypeKind::DateTime))
            || ($l->is(TypeKind::DateTime) && $r->is(TypeKind::Date))
        ) {
            return true;
        }

        if ($l->kind !== $r->kind) {
            return false;
        }

        return match ($l->kind) {
            TypeKind::Money, TypeKind::Rate => $l->parameter === $r->parameter
                && $l->element?->kind === $r->element?->kind,
            // A Code with no declared value set compares with any Code; two
            // declared sets must be the same set.
            TypeKind::Code => $l->parameter === null || $r->parameter === null || $l->parameter === $r->parameter,
            TypeKind::ListOf => $l->element !== null && $r->element !== null
                && self::comparable($l->element, $r->element),
            default => true,
        };
    }

    /** `< <= > >=` additionally require an ordered type. */
    public static function orderable(Type $type): bool
    {
        return in_array($type->kind, self::ORDERED, true) || $type->is(TypeKind::Null);
    }

    /**
     * Is `$from` acceptable where `$to` is declared — function arguments, list
     * elements, COALESCE branches, IF branches.
     *
     * Only the widening rows of the table: `DayFraction → Days`,
     * `Percentage → Number`, `Null → anything` (the caller decides whether a
     * null is meaningful there). `Code → Text` is NOT here: it is permitted in
     * CONCAT, UPPER and LOWER only, and those declare a two-type parameter.
     */
    public static function assignable(Type $from, Type $to): bool
    {
        if ($from->is(TypeKind::Null)) {
            return true;
        }

        if ($from->equals($to)) {
            return true;
        }

        if ($from->is(TypeKind::DayFraction) && $to->is(TypeKind::Days)) {
            return true;
        }

        if ($from->is(TypeKind::Percentage) && $to->is(TypeKind::Number)) {
            return true;
        }

        // Code compatibility is symmetric when either side has no declared value
        // set, which is the same stance comparable() already takes. A bare Code
        // means "the set is not known here" — a policy value, a custom field, an
        // API input — and the value is checked against the real set at runtime.
        // Two DECLARED sets must still be the same set: passing Code(leave_type)
        // where Code(credential_type) is wanted stays a publish error, which is
        // the check that matters.
        if ($from->is(TypeKind::Code) && $to->is(TypeKind::Code)
            && ($to->parameter === null || $from->parameter === null)
        ) {
            return true;
        }

        if ($from->is(TypeKind::ListOf) && $to->is(TypeKind::ListOf)) {
            return $to->element === null
                || ($from->element !== null && self::assignable($from->element, $to->element));
        }

        return false;
    }

    /**
     * The common type of a set of branches (IF, CASE, COALESCE, MIN/MAX, a list
     * literal). Null branches are ignored; a disagreement returns null.
     *
     * @param list<Type> $types
     */
    public static function unify(array $types): ?Type
    {
        $result = null;

        foreach ($types as $type) {
            if ($type->is(TypeKind::Null)) {
                continue;
            }

            if ($result === null) {
                $result = $type;

                continue;
            }

            if ($result->equals($type)) {
                continue;
            }

            if (self::assignable($type, $result)) {
                continue;
            }

            if (self::assignable($result, $type)) {
                $result = $type;

                continue;
            }

            return null;
        }

        return $result ?? Type::null();
    }
}
