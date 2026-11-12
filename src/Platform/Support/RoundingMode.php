<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Spine S5.4: "ROUND requires an explicit mode. There is no default rounding
 * anywhere in SmartPRS 3." Every API in this namespace that can lose precision
 * takes one of these; none of them defaults it.
 */
enum RoundingMode
{
    case HalfUp;    // ties away from zero — the payroll default customers expect
    case HalfDown;  // ties toward zero
    case HalfEven;  // banker's rounding
    case Up;        // away from zero
    case Down;      // toward zero (truncate)
    case Ceiling;   // toward +inf
    case Floor;     // toward -inf

    /**
     * Round the exact rational $numerator / $denominator to an integer.
     * $denominator must be positive; callers normalise sign into the numerator.
     */
    public function divide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Denominator must be positive.');
        }

        $quotient = intdiv($numerator, $denominator); // truncates toward zero
        $remainder = $numerator - $quotient * $denominator;

        if ($remainder === 0) {
            return $quotient;
        }

        $sign = $numerator < 0 ? -1 : 1;
        $twiceRemainder = abs($remainder) * 2;

        return match ($this) {
            self::Down => $quotient,
            self::Up => $quotient + $sign,
            self::Floor => $remainder < 0 ? $quotient - 1 : $quotient,
            self::Ceiling => $remainder > 0 ? $quotient + 1 : $quotient,
            self::HalfUp => $twiceRemainder >= $denominator ? $quotient + $sign : $quotient,
            self::HalfDown => $twiceRemainder > $denominator ? $quotient + $sign : $quotient,
            self::HalfEven => match (true) {
                $twiceRemainder > $denominator => $quotient + $sign,
                $twiceRemainder < $denominator => $quotient,
                default => $quotient % 2 === 0 ? $quotient : $quotient + $sign,
            },
        };
    }
}
