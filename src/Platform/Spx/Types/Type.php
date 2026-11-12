<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Spx\Types;

/**
 * An SPX type (Part VII §5.4.1, Part VIII §5.1).
 *
 * A kind plus at most one parameter, because no SPX type needs two:
 *   Money      → currency        ('INR')       — no implicit FX, ever
 *   Code       → value set name  ('leave_type')— membership checked at publish
 *   List<T>    → element type
 *   Rate<M/U>  → the denominator unit          — Days, Hours or Minutes
 *
 * Immutable and comparable by value: `$a->equals($b)`. There is no subtype
 * lattice and no inference beyond the coercion table — Coercion is the whole
 * relation between two types.
 */
final readonly class Type
{
    private function __construct(
        public TypeKind $kind,
        public ?string $parameter = null,
        public ?self $element = null,
    ) {
    }

    public static function of(TypeKind $kind): self
    {
        return new self($kind);
    }

    public static function number(): self
    {
        return new self(TypeKind::Number);
    }

    public static function money(string $currency = 'INR'): self
    {
        return new self(TypeKind::Money, $currency);
    }

    public static function boolean(): self
    {
        return new self(TypeKind::Boolean);
    }

    public static function text(): self
    {
        return new self(TypeKind::Text);
    }

    /** A Code with no declared value set accepts any code; publish-time membership is skipped. */
    public static function code(?string $valueSet = null): self
    {
        return new self(TypeKind::Code, $valueSet);
    }

    public static function date(): self
    {
        return new self(TypeKind::Date);
    }

    public static function dateTime(): self
    {
        return new self(TypeKind::DateTime);
    }

    public static function time(): self
    {
        return new self(TypeKind::Time);
    }

    public static function days(): self
    {
        return new self(TypeKind::Days);
    }

    public static function hours(): self
    {
        return new self(TypeKind::Hours);
    }

    public static function minutes(): self
    {
        return new self(TypeKind::Minutes);
    }

    public static function duration(): self
    {
        return new self(TypeKind::Duration);
    }

    public static function dayFraction(): self
    {
        return new self(TypeKind::DayFraction);
    }

    public static function percentage(): self
    {
        return new self(TypeKind::Percentage);
    }

    public static function null(): self
    {
        return new self(TypeKind::Null);
    }

    /** Rate<Money/U>: the result of Money / U, and multipliable only by U. */
    public static function rate(TypeKind $unit, string $currency = 'INR'): self
    {
        if (!in_array($unit, [TypeKind::Days, TypeKind::Hours, TypeKind::Minutes], true)) {
            throw new \InvalidArgumentException("Rate<Money/{$unit->value}> is not a type; U is Days, Hours or Minutes.");
        }

        return new self(TypeKind::Rate, $currency, self::of($unit));
    }

    public static function listOf(self $element): self
    {
        return new self(TypeKind::ListOf, null, $element);
    }

    public function is(TypeKind ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->parameter === $other->parameter
            && (
                ($this->element === null && $other->element === null)
                || ($this->element !== null && $other->element !== null && $this->element->equals($other->element))
            );
    }

    /** The base a refinement stands on: DayFraction → Days, Percentage → Number. */
    public function base(): self
    {
        return match ($this->kind) {
            TypeKind::DayFraction => self::days(),
            TypeKind::Percentage => self::number(),
            default => $this,
        };
    }

    public function isNumeric(): bool
    {
        return $this->is(TypeKind::Number, TypeKind::Percentage);
    }

    /**
     * The inverse of `__toString()`.
     *
     * `formula_payloads.result_type` has stored a rendered type since the
     * payload migration and nothing could read one back, so a published formula
     * could be written and never rehydrated — a serialisation with no
     * deserialisation is a one-way trip, and the read side is where the version
     * pinning of Part VIII §4.1 actually happens.
     *
     * Refuses rather than guessing: an unrecognised type is a payload written by
     * a version of this code that knew something this one does not, and
     * defaulting it to Number is how a Money column becomes a float.
     */
    public static function parse(string $rendered): self
    {
        $text = trim($rendered);

        if (preg_match('/^List<(.+)>$/', $text, $m) === 1) {
            return self::listOf(self::parse($m[1]));
        }

        if (preg_match('/^Rate<Money\/(\w+)>$/', $text, $m) === 1) {
            $unit = TypeKind::tryFrom($m[1]);

            if ($unit === null) {
                throw new \InvalidArgumentException("'{$text}' names a rate over an unknown unit '{$m[1]}'.");
            }

            return self::rate($unit);
        }

        if (preg_match('/^Money\((\w+)\)$/', $text, $m) === 1) {
            return self::money($m[1]);
        }

        if (preg_match('/^Code\((.+)\)$/', $text, $m) === 1) {
            return self::code($m[1]);
        }

        $kind = TypeKind::tryFrom($text);

        if ($kind === null) {
            throw new \InvalidArgumentException(
                "'{$rendered}' is not a type this build knows. It was written by something that knew a type "
                . 'this code does not, and a stored type is never defaulted — one wrong default here is a '
                . 'Money column read as a plain number.'
            );
        }

        return match ($kind) {
            TypeKind::Money => self::money(),
            TypeKind::Code => self::code(),
            TypeKind::ListOf, TypeKind::Rate => throw new \InvalidArgumentException(
                "'{$rendered}' is a parameterised type and must name its parameter, e.g. List<Money>."
            ),
            default => self::of($kind),
        };
    }

    public function __toString(): string
    {
        return match ($this->kind) {
            TypeKind::ListOf => 'List<' . ($this->element?->__toString() ?? '?') . '>',
            TypeKind::Rate => 'Rate<Money/' . ($this->element?->kind->value ?? '?') . '>',
            TypeKind::Money => 'Money' . ($this->parameter !== null && $this->parameter !== 'INR' ? "({$this->parameter})" : ''),
            TypeKind::Code => 'Code' . ($this->parameter !== null ? "({$this->parameter})" : ''),
            default => $this->kind->value,
        };
    }
}
