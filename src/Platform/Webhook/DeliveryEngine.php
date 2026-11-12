<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Webhook;

/**
 * NOTHING IS EVER DROPPED SILENTLY — Part XXIV §XXIV.4.5, R-XXIV-081 to
 * R-XXIV-090.
 *
 * R-XXIV-086 is the sentence this class is built around: *"A delivery that
 * cannot be attempted at all (no worker, egress refused, credential missing) is
 * recorded as an attempt WITH THAT CLASSIFICATION, not skipped. This is the
 * direct answer to DEF-C-14, where a failed pull is printed to the console and
 * vanishes."*
 *
 * So there is no path through this class that returns without writing an
 * attempt. "Could not even try" is an outcome with a name, because the
 * alternative — a log line and nothing in the database — is how an integration
 * is discovered to have been dead for three weeks.
 *
 * FAILURES ARE CLASSIFIED (R-XXIV-088) because the operator response differs by
 * class. A 500 from the receiver is their problem and will probably clear; a 401
 * is a credential somebody has to rotate; a DNS failure may be a decommissioned
 * endpoint; a refused egress is a firewall rule. "Failed 47 times" tells an
 * operator to look. "Failed 47 times with 401" tells them what to do.
 *
 * DEAD-LETTERED DELIVERIES KEEP THEIR ORIGINAL `event_uuid` (R-XXIV-085) so a
 * receiver that has since processed the event de-duplicates it on replay. A
 * replay with a fresh id is a second event, and a receiver doing its job
 * correctly will act on it twice.
 *
 * DELIVERY IS AT-LEAST-ONCE AND UNORDERED (R-XXIV-069), stated in the contract
 * rather than implied. A receiver that assumes ordering will be wrong on the
 * first retry, and one that assumes exactly-once will double-post the first time
 * a 2xx is lost on the way back.
 */
final class DeliveryEngine
{
    public const DELIVERED = 'delivered';
    public const FAILED_RECEIVER = 'failed_receiver';      // 5xx — theirs, usually transient
    public const FAILED_AUTH = 'failed_auth';              // 401/403 — a credential to rotate
    public const FAILED_CLIENT = 'failed_client';          // other 4xx — a contract mismatch
    public const FAILED_TIMEOUT = 'failed_timeout';
    public const FAILED_DNS = 'failed_dns';                // possibly a decommissioned endpoint
    public const NOT_ATTEMPTED = 'not_attempted';          // egress refused, no worker, no credential
    public const DEAD_LETTERED = 'dead_lettered';

    /** @var list<array<string, mixed>> */
    private array $attempts = [];

    /** @var array<string, array<string, mixed>> event uuid => the dead-lettered delivery */
    private array $deadLetters = [];

    public function __construct(
        private readonly RetrySchedule $schedule,
        private readonly int $consecutiveFailureThreshold = 20,
    ) {
    }

    /**
     * @param callable(): array{status: int, retry_after: ?int, body: string} $send
     * @return array{outcome: string, attempt: int, next_delay: ?int, why: string}
     */
    public function deliver(
        string $subscriptionId,
        string $eventUuid,
        string $eventType,
        string $rawBody,
        int $attempt,
        callable $send,
        int $now,
        bool $egressAllowed = true,
        bool $secretPresent = true,
    ): array {
        // The two "could not even try" cases. Both are RECORDED, because the
        // alternative is a console line and nothing in the database.
        if (!$egressAllowed) {
            return $this->record($subscriptionId, $eventUuid, $eventType, $attempt, self::NOT_ATTEMPTED,
                'the target URL was refused by the egress profile — recorded as an attempt rather than '
                . 'skipped, because a delivery that vanishes is an integration discovered to have been '
                . 'dead for three weeks', $now, null);
        }

        if (!$secretPresent) {
            return $this->record($subscriptionId, $eventUuid, $eventType, $attempt, self::NOT_ATTEMPTED,
                'the subscription has no signing secret, so nothing could be signed', $now, null);
        }

        try {
            $response = $send();
        } catch (\Throwable $e) {
            return $this->record($subscriptionId, $eventUuid, $eventType, $attempt, self::FAILED_DNS,
                'the endpoint could not be reached: ' . $e->getMessage(), $now, null);
        }

        $status = $response['status'];

        if ($status >= 200 && $status < 300) {
            return $this->record($subscriptionId, $eventUuid, $eventType, $attempt, self::DELIVERED,
                "the receiver returned {$status}", $now, null);
        }

        // Classified, because the operator response differs by class.
        $outcome = match (true) {
            $status === 0 => self::FAILED_TIMEOUT,
            $status === 401 || $status === 403 => self::FAILED_AUTH,
            $status >= 500 => self::FAILED_RECEIVER,
            default => self::FAILED_CLIENT,
        };

        $why = match ($outcome) {
            self::FAILED_AUTH => "the receiver returned {$status}: a credential to rotate, not a transient "
                . 'failure to wait out',
            self::FAILED_RECEIVER => "the receiver returned {$status}: their problem, and usually transient",
            self::FAILED_TIMEOUT => 'the receiver did not respond inside the timeout',
            default => "the receiver returned {$status}: a contract mismatch that retrying will not fix",
        };

        return $this->record($subscriptionId, $eventUuid, $eventType, $attempt, $outcome, $why, $now,
            $response['retry_after'] ?? null);
    }

    /** @return array{outcome: string, attempt: int, next_delay: ?int, why: string} */
    private function record(
        string $subscriptionId,
        string $eventUuid,
        string $eventType,
        int $attempt,
        string $outcome,
        string $why,
        int $now,
        ?int $retryAfter,
    ): array {
        $nextDelay = null;
        $finalOutcome = $outcome;

        if ($outcome !== self::DELIVERED) {
            if ($this->schedule->isExhausted($attempt + 1)) {
                $finalOutcome = self::DEAD_LETTERED;
                // The ORIGINAL event uuid is kept, so a receiver that has since
                // processed the event de-duplicates it on replay. A replay with
                // a fresh id is a second event.
                $this->deadLetters[$eventUuid] = [
                    'subscription' => $subscriptionId,
                    'event_uuid' => $eventUuid,
                    'event_type' => $eventType,
                    'attempts' => $attempt + 1,
                    'final_outcome' => $outcome,
                    'why' => $why,
                    'at' => $now,
                ];
                $why .= '; the schedule is exhausted, so the delivery is DEAD-LETTERED — visible, '
                    . 'retained and replayable, never discarded';
            } else {
                $nextDelay = $this->schedule->nextDelay($attempt + 1, $retryAfter)['delay'];
            }
        }

        $this->attempts[] = [
            'subscription' => $subscriptionId,
            'event_uuid' => $eventUuid,
            'event_type' => $eventType,
            'attempt' => $attempt,
            'outcome' => $finalOutcome,
            'why' => $why,
            'at' => $now,
        ];

        return ['outcome' => $finalOutcome, 'attempt' => $attempt, 'next_delay' => $nextDelay,
            'why' => $why];
    }

    /** Consecutive failures on a subscription — the automatic-disable signal (R-XXIV-089). */
    public function consecutiveFailures(string $subscriptionId): int
    {
        $count = 0;

        foreach (array_reverse($this->attempts) as $attempt) {
            if ($attempt['subscription'] !== $subscriptionId) {
                continue;
            }

            if ($attempt['outcome'] === self::DELIVERED) {
                break;
            }

            $count++;
        }

        return $count;
    }

    public function shouldDisable(string $subscriptionId): bool
    {
        return $this->consecutiveFailures($subscriptionId) >= $this->consecutiveFailureThreshold;
    }

    /** @return list<array<string, mixed>> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /** @return array<string, array<string, mixed>> */
    public function deadLetters(): array
    {
        return $this->deadLetters;
    }

    /**
     * Replay from the dead-letter queue, preserving the original event uuid.
     *
     * @throws \InvalidArgumentException when there is nothing to replay
     */
    public function replay(string $eventUuid): array
    {
        return $this->deadLetters[$eventUuid] ?? throw new \InvalidArgumentException(
            "No dead-lettered delivery for event {$eventUuid}. A replay that invents an event is a second "
            . 'event, and a receiver doing its job correctly acts on it twice.'
        );
    }
}
