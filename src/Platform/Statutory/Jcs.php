<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * RFC 8785 CANONICALISATION — Part XVII §XVII.4.6.
 *
 * "A signature over a pretty-printed document is a signature over a formatting
 * choice." Two byte-identical-in-meaning packs must hash identically whatever
 * order their keys were written in, however they were indented, and whichever
 * tool wrote them — otherwise a pack that survives a round-trip through a YAML
 * editor stops verifying and somebody learns to pass `--force`, which R-XVII-027
 * says does not exist.
 *
 * JCS in three rules: object keys sorted by their UTF-16 code units, no
 * insignificant whitespace, and numbers in the shortest form that round-trips.
 */
final class Jcs
{
    public static function canonicalise(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            // A float in a signed statutory artefact is refused, not
            // canonicalised. JCS defines a form for one, but Law 7 does not: a
            // rate or ceiling that arrives as an IEEE double has already lost
            // whatever the notification said, and no amount of careful hashing
            // afterwards recovers it. Money is a decimal string and a threshold
            // is an integer in minor units, both of which canonicalise exactly.
            is_float($value) => throw new \InvalidArgumentException(
                'A float (' . var_export($value, true) . ') cannot appear in a statutory artefact. Money is '
                . 'a decimal string and a threshold is an integer in minor units — a rate that arrives as a '
                . 'double has already lost precision the signature would then certify.'
            ),
            is_string($value) => self::string($value),
            is_array($value) => array_is_list($value) ? self::sequence($value) : self::object($value),
            default => throw new \InvalidArgumentException(
                'A ' . get_debug_type($value) . ' has no canonical JSON form, so it cannot be inside '
                . 'something that gets signed. What cannot be canonicalised cannot be verified.'
            ),
        };
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalise($value));
    }

    /** @param list<mixed> $items */
    private static function sequence(array $items): string
    {
        return '[' . implode(',', array_map(self::canonicalise(...), $items)) . ']';
    }

    /** @param array<string, mixed> $map */
    private static function object(array $map): string
    {
        $keys = array_map(strval(...), array_keys($map));

        // By UTF-16 code unit, which for the ASCII key names a manifest uses is
        // plain byte order — and stays correct if one ever is not.
        usort($keys, static fn (string $a, string $b): int => strcmp(
            mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ));

        $parts = [];

        foreach ($keys as $key) {
            $parts[] = self::string($key) . ':' . self::canonicalise($map[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
