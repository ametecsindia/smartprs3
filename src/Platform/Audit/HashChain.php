<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Audit;

/**
 * Spine Law 5 / S14 — the append-only, hash-chained audit ledger.
 *
 * Each entry's hash covers the previous hash and a canonical serialisation of
 * the entry payload, so altering, reordering, inserting or removing any entry
 * breaks the chain from that point on and verify() reports the index.
 *
 * Canonicalisation is the whole game: the same entry must hash the same on any
 * machine, in any PHP version, whatever order the payload keys arrive in. So:
 * keys sorted recursively, no floats, explicit UTF-8, no escaped slashes.
 */
final class HashChain
{
    public const GENESIS = '';
    private const ALGORITHM = 'sha256';

    /** The hash of the entry that follows $previousHash. */
    public static function next(?string $previousHash, array $payload): string
    {
        return hash(
            self::ALGORITHM,
            ($previousHash ?? self::GENESIS) . "\n" . self::canonicalise($payload),
        );
    }

    /**
     * Stable serialisation of an entry payload.
     *
     * Floats are rejected outright: 0.1 does not serialise identically
     * everywhere, and money is integer paise anyway (Law 7).
     */
    public static function canonicalise(array $payload): string
    {
        return json_encode(
            self::normalise($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Walk a chain and return the first break.
     *
     * Each entry is ['payload' => array, 'prev_hash' => ?string, 'hash' => string].
     *
     * @param  iterable<array{payload: array, prev_hash: ?string, hash: string}>  $entries
     * @return array{index: int, reason: string}|null  null when the chain is intact
     */
    public static function verify(iterable $entries, ?string $expectedFirstPrevHash = null): ?array
    {
        $expectedPrevious = $expectedFirstPrevHash ?? self::GENESIS;
        $index = 0;

        foreach ($entries as $entry) {
            $actualPrevious = $entry['prev_hash'] ?? self::GENESIS;

            if ($actualPrevious !== $expectedPrevious) {
                return [
                    'index' => $index,
                    'reason' => 'Broken link: prev_hash is ' . self::show($actualPrevious)
                        . ', expected ' . self::show($expectedPrevious) . '.',
                ];
            }

            $recomputed = self::next($actualPrevious, $entry['payload']);

            if (!hash_equals($recomputed, $entry['hash'])) {
                return [
                    'index' => $index,
                    'reason' => 'Tampered entry: recomputed hash ' . self::show($recomputed)
                        . ' does not match the stored ' . self::show($entry['hash']) . '.',
                ];
            }

            $expectedPrevious = $entry['hash'];
            $index++;
        }

        return null;
    }

    /** The daily anchor value (S14): the chain head a tenant's day closed on. */
    public static function anchor(string $tenantId, string $anchorOn, string $chainHeadHash, int $entryCount): string
    {
        return hash(self::ALGORITHM, self::canonicalise([
            'tenant_id' => $tenantId,
            'anchor_on' => $anchorOn,
            'chain_head_hash' => $chainHeadHash,
            'entry_count' => $entryCount,
        ]));
    }

    private static function normalise(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                'Floats are not serialisable deterministically and never appear in an audit payload.'
            );
        }

        if (!is_array($value)) {
            return $value instanceof \DateTimeInterface
                ? $value->format(\DateTimeInterface::RFC3339)
                : $value;
        }

        // A list keeps its order (order is meaning); a map is sorted by key
        // (key order is not meaning, and callers must not have to remember it).
        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalise(...), $value);
    }

    private static function show(string $hash): string
    {
        return $hash === self::GENESIS ? '<genesis>' : substr($hash, 0, 12) . '…';
    }
}
