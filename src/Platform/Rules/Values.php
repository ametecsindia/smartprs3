<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules;

use SmartPRS\Platform\Spx\Value as SpxValue;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * Canonical rendering and canonical JSON for typed values.
 *
 * Two obligations meet here. §7.4 requires the serialised (actions, trace) to be
 * byte-identical across runs, machines and PHP versions — so maps are ordered by
 * key and nothing is left to hash order. §6.3 hashes the *resolved* parameters
 * into an action's fingerprint — so two amounts that differ must serialise
 * differently, and a Rational one-half must never become the float 0.5 (Law 7).
 *
 * ponytail: one static helper, not a serialiser hierarchy. The value vocabulary
 * is closed — scalars, Money, Rational, dates, lists — because SPX's type system
 * is closed (S5.1).
 */
final class Values
{
    /** Human-readable, for the rendered trace. */
    public static function render(mixed $value): string
    {
        return match (true) {
            $value instanceof Money => $value->format(),
            $value instanceof Rational => (string) $value,
            // A caller may put an already-typed SPX value in the prepared
            // context — an aggregation counter carries its own type, because
            // nothing in the symbol table declares one for it.
            $value instanceof SpxValue => $value->describe(),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \Stringable => (string) $value,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            $value === null => 'null',
            is_array($value) => '[' . implode(', ', array_map(self::render(...), $value)) . ']',
            default => (string) $value,
        };
    }

    /** JSON-safe, order-stable, exact. The fingerprint input of §6.3. */
    public static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = self::canonical($item);
            }

            if (!array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }

        return match (true) {
            is_int($value), is_bool($value), $value === null => $value,
            default => self::render($value),
        };
    }

    public static function json(mixed $value): string
    {
        return json_encode(
            self::canonical($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }
}
