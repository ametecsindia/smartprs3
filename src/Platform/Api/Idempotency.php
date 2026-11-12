<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * A RETRY THAT DOES NOT PAY TWICE — Part XXIV §XXIV.2.7.
 *
 * Five situations, and the table is R-XXIV-032's, not this class's invention:
 *
 *   first use                            execute; record the response BEFORE returning it
 *   replay, same fingerprint, completed  the STORED response, byte-identical
 *   replay, same fingerprint, in flight  409 idempotency.in_progress, Retry-After
 *   replay, DIFFERENT fingerprint        422 key_reuse_conflict — never executed
 *   key older than the window            treated as first use
 *
 * The fourth row is the one that earns the class. A client that reuses a key
 * with a different body has a bug — two different requests believe they are the
 * same operation — and executing either of them is a coin flip about which
 * effect lands. Refusing is the only answer that does not silently pick one.
 *
 * R-XXIV-033: THE RECORD IS WRITTEN IN THE SAME TRANSACTION AS THE DOMAIN
 * EFFECT. A design where the key is recorded after a successful commit is not
 * idempotent under a crash between the two — the money moved and nothing knows
 * the key was used, so the retry moves it again. This class therefore takes the
 * work as a callable and requires the caller to have a transaction open, rather
 * than offering a convenient "record afterwards" path.
 *
 * And it is DEFENCE IN DEPTH, not a substitute for a natural key (R-XXIV-034).
 * Punch ingest keeps its `(tenant_id, employment_id, punch_instant, source)`
 * uniqueness — the one thing SmartPRS 2 got right here — because a client that
 * sends the same punch twice with two different keys is not replaying, it is
 * duplicating, and only the database can see that.
 */
final class Idempotency
{
    public const FIRST_USE = 'first_use';
    public const REPLAYED = 'replayed';
    public const IN_PROGRESS = 'in_progress';
    public const CONFLICT = 'conflict';

    /** @var array<string, array{fingerprint: string, status: string, response: ?array, stored_at: int}> */
    private array $records = [];

    public function __construct(
        private readonly int $retentionSeconds = 86400,
        private readonly int $maximumRetentionSeconds = 604800,
    ) {
        if ($retentionSeconds > $maximumRetentionSeconds) {
            throw new \InvalidArgumentException(
                'The idempotency retention window may not exceed seven days. Beyond that the table grows '
                . 'without bound and a key from last month blocks a legitimately new request.'
            );
        }
    }

    /**
     * @param callable(): array $work the domain effect, run inside the CALLER's transaction
     * @param bool $transactionOpen the caller asserts its transaction is open
     * @return array{outcome: string, response: ?array, replayed: bool}
     */
    public function run(
        string $tenantId,
        string $credentialId,
        string $endpoint,
        ?string $key,
        array $requestBody,
        callable $work,
        bool $transactionOpen,
        int $now,
        bool $keyRequired = true,
    ): array {
        if ($key === null || trim($key) === '') {
            if (!$keyRequired) {
                return ['outcome' => self::FIRST_USE, 'response' => $work(), 'replayed' => false];
            }

            return [
                'outcome' => self::CONFLICT,
                'response' => ['code' => 'idempotency.key_required'],
                'replayed' => false,
            ];
        }

        if (!$transactionOpen) {
            throw new \LogicException(
                'The idempotency record must be written in the SAME transaction as the domain effect '
                . '(R-XXIV-033). Recording the key after a successful commit is not idempotent under a '
                . 'crash between the two: the money moved, nothing knows the key was used, and the retry '
                . 'moves it again.'
            );
        }

        $id = implode('|', [$tenantId, $credentialId, $endpoint, $key]);
        $fingerprint = hash('sha256', json_encode($requestBody, JSON_THROW_ON_ERROR));
        $record = $this->records[$id] ?? null;

        // Expiry is treated as first use, not as an error: a key old enough to
        // have fallen out of the window is a key nobody is retrying.
        if ($record !== null && $now - $record['stored_at'] > $this->retentionSeconds) {
            $record = null;
            unset($this->records[$id]);
        }

        if ($record !== null) {
            if ($record['fingerprint'] !== $fingerprint) {
                // Never executed. Two different requests believe they are the
                // same operation; running either is a coin flip about which
                // effect lands.
                return [
                    'outcome' => self::CONFLICT,
                    'response' => ['code' => 'idempotency.key_reuse_conflict'],
                    'replayed' => false,
                ];
            }

            if ($record['status'] === self::IN_PROGRESS) {
                return [
                    'outcome' => self::IN_PROGRESS,
                    'response' => ['code' => 'idempotency.in_progress'],
                    'replayed' => false,
                ];
            }

            // Byte-identical, from storage. Not re-derived: re-running the read
            // would return today's data for yesterday's request.
            return ['outcome' => self::REPLAYED, 'response' => $record['response'], 'replayed' => true];
        }

        $this->records[$id] = ['fingerprint' => $fingerprint, 'status' => self::IN_PROGRESS,
            'response' => null, 'stored_at' => $now];

        try {
            $response = $work();
        } catch (\Throwable $e) {
            // A failed attempt does not hold the key. The client's retry is a
            // legitimate first use, and leaving the record in flight would
            // return 409 forever.
            unset($this->records[$id]);

            throw $e;
        }

        $this->records[$id] = ['fingerprint' => $fingerprint, 'status' => 'completed',
            'response' => $response, 'stored_at' => $now];

        return ['outcome' => self::FIRST_USE, 'response' => $response, 'replayed' => false];
    }

    public function has(string $tenantId, string $credentialId, string $endpoint, string $key): bool
    {
        return isset($this->records[implode('|', [$tenantId, $credentialId, $endpoint, $key])]);
    }
}
