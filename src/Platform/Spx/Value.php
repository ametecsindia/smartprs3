<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Types\TypeKind;

/**
 * A runtime SPX value: a type, a payload, and — because every function is total
 * (spine S5.6) — possibly a typed error instead of a payload.
 *
 * Payload representation, chosen once so the arithmetic has one code path:
 *
 *   Number, Percentage, Money, Days, Hours, Minutes, Duration, Rate → Rational
 *   Date, DateTime → \DateTimeImmutable     Time → int seconds of day
 *   Boolean → bool    Text, Code → string   List → list<Value>    Null → null
 *
 * Money is an exact Rational of MINOR UNITS, not a Money object, and stays exact
 * until the declared rounding boundary (Part VIII §6.2). ₹15,000 × 40% is
 * Rational(600000, 1) — 6,00,000 paise, ₹6,000.00 exactly, with no float
 * anywhere on the path.
 *
 * UNKNOWN and Null are one value (§5.4.3): a comparison with a null operand
 * yields Null, and a Null in a Boolean position is UNKNOWN. Three-valued logic
 * then needs no fourth state.
 */
final readonly class Value
{
    private function __construct(
        public Type $type,
        public mixed $payload,
        public ?ErrorCode $error = null,
        public ?string $detail = null,
    ) {
    }

    public static function number(Rational $value): self
    {
        return new self(Type::number(), $value);
    }

    public static function percentage(Rational $value): self
    {
        return new self(Type::percentage(), $value);
    }

    /** From a decimal string such as '0.25'. Exact; never a float. */
    public static function numberOf(string $decimal): self
    {
        return new self(Type::number(), Rational::fromDecimalString($decimal));
    }

    /** $minor is exact paise, possibly fractional between rounding boundaries. */
    public static function moneyOfMinor(Rational $minor, string $currency = 'INR'): self
    {
        return new self(Type::money($currency), $minor);
    }

    public static function money(Money $money): self
    {
        return new self(Type::money($money->currency), Rational::of($money->minor));
    }

    public static function quantity(TypeKind $kind, Rational $value): self
    {
        return new self(Type::of($kind), $value);
    }

    /** Same quantity, carrying a type that already exists — currency and all. */
    public static function quantityOfType(Type $type, Rational $value): self
    {
        return new self($type, $value);
    }

    public static function rate(Rational $minorPerUnit, TypeKind $unit, string $currency = 'INR'): self
    {
        return new self(Type::rate($unit, $currency), $minorPerUnit);
    }

    public static function boolean(bool $value): self
    {
        return new self(Type::boolean(), $value);
    }

    public static function text(string $value): self
    {
        return new self(Type::text(), $value);
    }

    public static function code(string $value, ?string $valueSet = null): self
    {
        return new self(Type::code($valueSet), $value);
    }

    public static function date(\DateTimeImmutable $value): self
    {
        return new self(Type::date(), $value->setTime(0, 0));
    }

    public static function dateTime(\DateTimeImmutable $value): self
    {
        return new self(Type::dateTime(), $value);
    }

    /** Seconds since midnight, wall clock. */
    public static function time(int $secondsOfDay): self
    {
        return new self(Type::time(), $secondsOfDay);
    }

    /** @param list<self> $elements */
    public static function list(array $elements, ?Type $element = null): self
    {
        return new self(Type::listOf($element ?? ($elements[0]->type ?? Type::null())), $elements);
    }

    public static function null(): self
    {
        return new self(Type::null(), null);
    }

    /** UNKNOWN in three-valued logic is Null in a Boolean position. */
    public static function unknown(): self
    {
        return self::null();
    }

    public static function error(ErrorCode $code, string $detail): self
    {
        return new self(Type::null(), null, $code, $detail);
    }

    /** Retype without touching the payload — refinement erasure, mostly. */
    public function as(Type $type): self
    {
        return new self($type, $this->payload, $this->error, $this->detail);
    }

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public function isNull(): bool
    {
        return $this->error === null && $this->type->is(TypeKind::Null);
    }

    /** True only for a definite TRUE; UNKNOWN and FALSE are both "not true". */
    public function isTrue(): bool
    {
        return $this->payload === true;
    }

    public function isFalse(): bool
    {
        return $this->payload === false;
    }

    public function rational(): Rational
    {
        return $this->payload instanceof Rational
            ? $this->payload
            : throw new \LogicException('Value of type ' . $this->type . ' is not a quantity.');
    }

    public function string(): string
    {
        return is_string($this->payload)
            ? $this->payload
            : throw new \LogicException('Value of type ' . $this->type . ' is not text.');
    }

    public function dateValue(): \DateTimeImmutable
    {
        return $this->payload instanceof \DateTimeImmutable
            ? $this->payload
            : throw new \LogicException('Value of type ' . $this->type . ' is not a date.');
    }

    /** @return list<self> */
    public function elements(): array
    {
        return is_array($this->payload)
            ? $this->payload
            : throw new \LogicException('Value of type ' . $this->type . ' is not a list.');
    }

    /**
     * Collapse to integer paise — the declared rounding boundary, and the only
     * place a Money value stops being exact (R-VIII-023). An exact value needs
     * no mode; an inexact one without a declared mode is a programming error,
     * because there is no default rounding in SmartPRS 3.
     */
    public function toMoney(?RoundingMode $mode = null): Money
    {
        $rational = $this->rational();

        if ($rational->denominator === 1) {
            return Money::ofMinor($rational->numerator, $this->type->parameter ?? 'INR');
        }

        if ($mode === null) {
            throw new \LogicException(
                'This money value is not exact in paise and no rounding mode was declared. '
                . 'Rounding is declared on the component and applied at the declared point (R-VIII-025).'
            );
        }

        return Money::ofMinor($rational->toInt($mode), $this->type->parameter ?? 'INR');
    }

    /** Human-readable, for traces and test failures. */
    public function describe(): string
    {
        if ($this->error !== null) {
            return $this->error->value . ': ' . $this->detail;
        }

        return match (true) {
            $this->type->is(TypeKind::Null) => 'NULL',
            $this->type->is(TypeKind::Money) => $this->payload instanceof Rational && $this->payload->denominator === 1
                ? $this->toMoney()->format()
                : '~' . $this->payload . ' paise',
            $this->type->is(TypeKind::Boolean) => $this->payload === true ? 'TRUE' : 'FALSE',
            $this->type->is(TypeKind::Date) => $this->dateValue()->format('Y-m-d'),
            $this->type->is(TypeKind::DateTime) => $this->dateValue()->format('Y-m-d H:i:s'),
            $this->type->is(TypeKind::Time) => sprintf('%02d:%02d:%02d', intdiv((int) $this->payload, 3600), intdiv((int) $this->payload % 3600, 60), (int) $this->payload % 60),
            $this->type->is(TypeKind::ListOf) => '[' . implode(', ', array_map(
                static fn (self $v): string => $v->describe(),
                $this->elements(),
            )) . ']',
            $this->payload instanceof Rational => self::decimal($this->payload),
            default => (string) $this->payload,
        };
    }

    /**
     * A terminating decimal where one exists, and the exact fraction where one
     * does not. `1/3` is shown as `1/3` rather than as a rounded decimal,
     * because the value genuinely is a third and a trace that says 0.333 invites
     * someone to reconcile against 0.333.
     */
    private static function decimal(Rational $value): string
    {
        for ($scale = 0; $scale <= 9; $scale++) {
            $power = 10 ** $scale;

            if ($power % $value->denominator !== 0) {
                continue;
            }

            $factor = intdiv($power, $value->denominator);

            if ($factor !== 0 && abs($value->numerator) > intdiv(PHP_INT_MAX, $factor)) {
                break;
            }

            $scaled = $value->numerator * $factor;
            $digits = str_pad((string) abs($scaled), $scale + 1, '0', STR_PAD_LEFT);

            return ($scaled < 0 ? '-' : '')
                . ($scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale));
        }

        return (string) $value;
    }
}
