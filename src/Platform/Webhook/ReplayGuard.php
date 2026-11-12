<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Webhook;

/**
 * A NONCE SEEN TWICE IS A REPLAY — Part XXIV R-XXIV-077 to R-XXIV-079.
 *
 * A signature proves who sent a request. It proves nothing about WHEN, or about
 * how many times. Those are the nonce's job, and this class is the only place
 * that job is done — R-XXIV-079: replay rejection is enforced at the TRANSPORT
 * layer and is independent of whatever the handler does afterwards, because a
 * handler that happens to be idempotent today is not a security control.
 *
 * NONCES ARE RETAINED FOR AT LEAST TWICE THE CLOCK TOLERANCE (R-XXIV-078). The
 * arithmetic is the whole design: if the window accepts a timestamp up to five
 * minutes old and nonces are forgotten after four, there is a minute in which a
 * captured request is both fresh enough to accept and forgotten enough to look
 * new. Twice the tolerance closes it with room to spare.
 *
 * The two checks are complementary and neither is sufficient. Freshness alone
 * lets an attacker replay a captured request as many times as they like inside
 * the window; uniqueness alone lets them replay one from last year.
 */
final class ReplayGuard
{
    /** @var array<string, array<string, int>> endpoint => nonce => first seen */
    private array $seen = [];

    public function __construct(
        private readonly int $toleranceSeconds = 300,
        private readonly ?int $retentionSeconds = null,
    ) {
        if ($toleranceSeconds <= 0) {
            throw new \InvalidArgumentException('A zero clock tolerance rejects every legitimate request.');
        }
    }

    private function retention(): int
    {
        // At least twice the tolerance, always — a caller that asks for less is
        // asking for the gap where a replay is both fresh and forgotten.
        return max($this->retentionSeconds ?? 0, $this->toleranceSeconds * 2);
    }

    /**
     * @return array{accepted: bool, reason: string}
     */
    public function accept(string $endpoint, string $nonce, int $timestamp, int $now): array
    {
        if (trim($nonce) === '') {
            return ['accepted' => false, 'reason' => 'the request carries no nonce, so nothing distinguishes '
                . 'it from a replay of itself'];
        }

        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            return ['accepted' => false, 'reason' => sprintf(
                'the timestamp is %d second(s) from now and the tolerance is %d. A correctly signed request '
                . 'from last week is a correctly signed request',
                abs($now - $timestamp), $this->toleranceSeconds,
            )];
        }

        $this->forgetExpired($endpoint, $now);

        if (isset($this->seen[$endpoint][$nonce])) {
            return ['accepted' => false, 'reason' => "nonce '{$nonce}' has already been used on this "
                . 'endpoint. Freshness alone would let this be replayed as often as the window allows'];
        }

        $this->seen[$endpoint][$nonce] = $now;

        return ['accepted' => true, 'reason' => 'fresh, and this nonce has not been seen'];
    }

    private function forgetExpired(string $endpoint, int $now): void
    {
        foreach ($this->seen[$endpoint] ?? [] as $nonce => $at) {
            if ($now - $at > $this->retention()) {
                unset($this->seen[$endpoint][$nonce]);
            }
        }
    }

    public function retentionSeconds(): int
    {
        return $this->retention();
    }
}
