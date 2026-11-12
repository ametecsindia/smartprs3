<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * A STORED COLUMN, READ AS MONEY, WITHOUT A FLOAT IN THE MIDDLE (Law 7).
 *
 * Money lives in DECIMAL(18,4) columns and arrives here as the string MySQL
 * wrote — "4200000.0000" — or as whatever a JSON row store round-tripped. Every
 * screen that needed it as a `Money` was doing the same thing to get there:
 * `number_format((float) $value, 2, '.', '')`. That is a float on the money
 * path, and on eighteen significant digits it is a float that is no longer the
 * number that was stored. Five surface files did it; this is that one line, done
 * in integers.
 *
 * ponytail: one function, no interface, no registry. It exists because five call
 * sites parse the same column shape, not because parsing deserves a layer.
 */
final class Amount
{
    /**
     * Are these two stored figures the SAME amount?
     *
     * "50000" and "50000.0000" are one number written two ways — DECIMAL(18,4) out
     * of MySQL and a person typing into a form — and a string comparison calls them
     * different, which reports an unchanged threshold as a change and writes a new
     * customisation version every time somebody presses Save. Compared as money,
     * in paise, because that is what they are.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::of($a)->equals(self::of($b));
    }

    /** Sub-paise digits round HALF UP, the same way the float `round()` here did. */
    public static function of(mixed $stored, string $currency = 'INR'): Money
    {
        $text = trim(str_replace(['₹', ',', ' '], '', (string) $stored));

        if ($text === '') {
            return Money::zero($currency);
        }

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $text, $m) !== 1) {
            throw new \InvalidArgumentException("Not an amount: {$text}");
        }

        $fraction = str_pad($m[3] ?? '', 2, '0');
        $minor = (int) ($m[2] . substr($fraction, 0, 2));
        $subPaise = substr($fraction, 2);

        if ($subPaise !== '' && $subPaise[0] >= '5') {
            $minor++;
        }

        return Money::ofMinor($m[1] === '-' ? -$minor : $minor, $currency);
    }

    /**
     * The same exact parse for a QUANTITY that is not money — a leave day count,
     * a report column of days. It is not rupees and is never formatted as rupees;
     * it is here because "10.5 is more than 10" has to be as exact for days as it
     * is for paise, and this is the exact comparison the codebase already owns.
     */
    public static function quantity(mixed $stored): Money
    {
        return self::of($stored);
    }
}
