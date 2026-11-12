<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * CURSOR PAGINATION, ON EVERY COLLECTION — Part XXIV R-XXIV-035.
 *
 * "No endpoint offers offset/limit, because offset pagination silently skips and
 * duplicates rows under concurrent writes, and every collection in a payroll
 * system is written to while it is read."
 *
 * That sentence is the whole design. `?page=2&per_page=100` is correct only if
 * nothing inserts or deletes between page 1 and page 2 — and payroll is a system
 * where somebody is always joining, leaving or being corrected. The failure is
 * silent in both directions: an insert before the cursor pushes a row from page
 * 1 onto page 2 and it is EXPORTED TWICE; a delete pulls one back and it is
 * NEVER EXPORTED. A finance team reconciling an export against a total finds the
 * discrepancy and cannot reproduce it, because by then the table has moved again.
 *
 * A cursor is opaque ON PURPOSE. A client that decodes one and constructs its own
 * is a client that breaks when the sort key changes, and then reports it as our
 * bug. It carries its sort so that a cursor from one ordering cannot be replayed
 * against another — which would silently return a different window of rows.
 */
final class Cursor
{
    /** @param array<string, string> $lastSeen the sort-key values of the last row returned */
    public static function encode(array $lastSeen, string $sort): string
    {
        return rtrim(strtr(base64_encode(json_encode(
            ['k' => $lastSeen, 's' => $sort, 'v' => 1],
            JSON_THROW_ON_ERROR,
        )), '+/', '-_'), '=');
    }

    /**
     * @return array{k: array<string, string>, s: string}
     * @throws \InvalidArgumentException on anything that is not a cursor this API issued
     */
    public static function decode(string $cursor, string $sort): array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false) {
            throw new \InvalidArgumentException('request.bad_cursor: the cursor is not decodable.');
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('request.bad_cursor: the cursor is not a cursor.');
        }

        if (!is_array($decoded) || !isset($decoded['k'], $decoded['s'])) {
            throw new \InvalidArgumentException('request.bad_cursor: the cursor is malformed.');
        }

        if ($decoded['s'] !== $sort) {
            // Replaying a cursor against a different ordering returns a
            // different window of rows and looks like it worked.
            throw new \InvalidArgumentException(
                "request.bad_cursor: this cursor was issued for sort '{$decoded['s']}' and was replayed "
                . "against '{$sort}'. The same cursor under a different ordering points at a different "
                . 'window, and nothing about the response would say so.'
            );
        }

        return ['k' => $decoded['k'], 's' => $sort];
    }

    /** The one place a limit is clamped, so no endpoint invents its own maximum. */
    public static function limit(mixed $requested, int $default = 50, int $maximum = 500): int
    {
        $limit = is_numeric($requested) ? (int) $requested : $default;

        return max(1, min($limit, $maximum));
    }

    /** Reject the offset/page vocabulary outright rather than quietly ignoring it. */
    public static function rejectOffsetParameters(array $query): ?string
    {
        foreach (['offset', 'page', 'per_page', 'skip'] as $forbidden) {
            if (array_key_exists($forbidden, $query)) {
                return "request.unknown_filter: '{$forbidden}' is not supported. Offset pagination "
                    . 'silently skips and duplicates rows under concurrent writes — an insert pushes a row '
                    . 'onto the next page and it is exported twice; a delete pulls one back and it is '
                    . 'never exported. Use ?cursor= instead.';
            }
        }

        return null;
    }
}
