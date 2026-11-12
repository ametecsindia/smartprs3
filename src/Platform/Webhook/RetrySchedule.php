<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Webhook;

/**
 * EXPONENTIAL BACKOFF WITH FULL JITTER — Part XXIV R-XXIV-083, R-XXIV-084.
 *
 * Nine attempts over roughly 46 hours. *"SmartPRS 2 has no retry anywhere in the
 * codebase, so this schedule is new in its entirety."*
 *
 * FULL JITTER, not a fixed backoff, and the reason is specific. A receiver that
 * goes down takes every subscription's deliveries with it; when it comes back, a
 * fixed schedule sends all of them in the same second, and the receiver falls
 * over again. That is a thundering herd the sender caused, and the sender is the
 * only party that can prevent it. Full jitter — a uniform draw across the whole
 * interval rather than a small wobble around it — spreads the retries flat.
 *
 * `Retry-After` WINS WHEN THE RECEIVER SENDS ONE. They know something about
 * their own recovery that a schedule cannot: overriding it is the sender
 * insisting it understands the receiver's capacity better than the receiver
 * does, which is how a 429 becomes an outage.
 *
 * The cap is deliberate too: a `Retry-After` of a week would park a delivery
 * past its own retention, so it is clamped and the clamp is stated rather than
 * silently applied.
 */
final class RetrySchedule
{
    /** Nominal delays in seconds. Attempt 1 is immediate. */
    public const DEFAULT_DELAYS = [0, 30, 120, 600, 3600, 10800, 21600, 43200, 86400];

    /** @param list<int> $delays */
    public function __construct(
        private readonly array $delays = self::DEFAULT_DELAYS,
        private readonly int $maximumRetryAfter = 86400,
    ) {
        if ($delays === []) {
            throw new \InvalidArgumentException(
                'A schedule with no attempts never delivers and never dead-letters; the event simply '
                . 'disappears, which is the one outcome R-XXIV-086 forbids.'
            );
        }
    }

    public function attempts(): int
    {
        return count($this->delays);
    }

    public function isExhausted(int $attempt): bool
    {
        return $attempt >= count($this->delays);
    }

    /**
     * @param callable(int): int $random injected so the gate can assert the
     *        DISTRIBUTION rather than trust one lucky draw
     * @return array{delay: int, nominal: int, source: string}
     */
    public function nextDelay(int $attempt, ?int $retryAfter = null, ?callable $random = null): array
    {
        if ($this->isExhausted($attempt)) {
            throw new \LogicException(
                "Attempt {$attempt} is past the end of the schedule. An exhausted delivery goes to the "
                . 'dead-letter queue, where it is visible and replayable — it does not get another delay.'
            );
        }

        $nominal = $this->delays[$attempt];

        if ($retryAfter !== null) {
            // The receiver knows something about its own recovery that a
            // schedule cannot. Overriding it is the sender insisting it
            // understands the receiver's capacity better than the receiver does.
            $honoured = min(max($retryAfter, 0), $this->maximumRetryAfter);

            return [
                'delay' => $honoured,
                'nominal' => $nominal,
                'source' => $honoured === $retryAfter
                    ? 'the receiver asked for it'
                    : "the receiver asked for {$retryAfter}s, clamped to {$honoured}s so the delivery "
                        . 'does not outlive its own retention',
            ];
        }

        // FULL jitter: uniform across [0, nominal], not nominal ± a wobble. A
        // receiver that comes back up must not be hit by every queued delivery
        // in the same second.
        $draw = $random ?? static fn (int $max): int => random_int(0, $max);

        return [
            'delay' => $nominal === 0 ? 0 : $draw($nominal),
            'nominal' => $nominal,
            'source' => 'exponential backoff with full jitter',
        ];
    }

    /** The whole window, at nominal — what a customer is told before it dead-letters. */
    public function windowSeconds(): int
    {
        return array_sum($this->delays);
    }
}
