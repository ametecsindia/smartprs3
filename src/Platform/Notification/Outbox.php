<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Notification;

/**
 * QUEUED, SENT, DELIVERED — AND THE THIRD ONE IS NOT A GUESS.
 * Part XXXII Phase 8.
 *
 * *"A message is recorded delivered only on provider acknowledgement."*
 *
 * The distinction between SENT and DELIVERED is the whole class. Sent means this
 * system handed the message to a provider; delivered means the provider said it
 * arrived. Collapsing them — marking delivered when the API call returns 200 — is
 * how a payroll team believes 400 payslip notifications went out on the 1st when
 * the SMS gateway silently dropped every one of them, and nobody finds out until
 * an employee asks in March.
 *
 * So `deliver()` requires a provider reference and there is no other route to the
 * state. `send()` cannot reach it, and neither can anything else.
 *
 * THE LOG IS REDACTED ON THE WAY IN (see Redactor). A message log is the
 * least-guarded copy of every credential the system ever sent — read by support,
 * exported for debugging, folded into diagnostic bundles, retained long after the
 * OTP inside it stopped mattering to anyone except an attacker.
 */
final class Outbox
{
    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const SUPPRESSED = 'suppressed';

    /** @var array<string, array{state: string, channel: string, body: string, provider_ref: ?string, history: list<string>}> */
    private array $messages = [];

    public function queue(string $id, string $channel, string $body): void
    {
        $this->messages[$id] = [
            'state' => self::QUEUED,
            'channel' => $channel,
            // Redacted HERE, not at render. A log that stores the raw text and
            // masks it on the way out still holds every password anyone was
            // emailed, one forgotten query away from being read.
            'body' => Redactor::redact($body),
            'provider_ref' => null,
            'history' => ['queued'],
        ];
    }

    public function send(string $id, string $providerMessageId): void
    {
        $this->transition($id, self::SENT, "handed to the provider as {$providerMessageId}");
        $this->messages[$id]['provider_ref'] = $providerMessageId;
    }

    /**
     * The ONLY route to `delivered`, and it needs the provider to say so.
     *
     * @throws \InvalidArgumentException when nothing acknowledged it
     */
    public function deliver(string $id, string $providerAcknowledgement): void
    {
        if (trim($providerAcknowledgement) === '') {
            throw new \InvalidArgumentException(
                "Message {$id} cannot be marked delivered without a provider acknowledgement. Sent means we "
                . 'handed it over; delivered means the provider said it arrived. Collapsing the two is how a '
                . 'team believes 400 payslip notifications went out when the gateway dropped every one, and '
                . 'nobody finds out until an employee asks in March.'
            );
        }

        if (($this->messages[$id]['state'] ?? null) !== self::SENT) {
            throw new \InvalidArgumentException(
                "Message {$id} is '{$this->messages[$id]['state']}' and cannot be delivered from there. "
                . 'A message delivered without having been sent is a status nobody produced.'
            );
        }

        $this->transition($id, self::DELIVERED, "acknowledged by the provider: {$providerAcknowledgement}");
    }

    public function fail(string $id, string $reason): void
    {
        $this->transition($id, self::FAILED, "failed: {$reason}");
    }

    public function suppress(string $id, string $reason): void
    {
        $this->transition($id, self::SUPPRESSED, "suppressed: {$reason}");
    }

    public function state(string $id): string
    {
        return $this->messages[$id]['state'] ?? throw new \InvalidArgumentException("No message {$id}.");
    }

    public function storedBody(string $id): string
    {
        return $this->messages[$id]['body'];
    }

    /** @return array<string, int> the counts the operations console shows */
    public function counts(): array
    {
        $counts = [self::QUEUED => 0, self::SENT => 0, self::DELIVERED => 0,
            self::FAILED => 0, self::SUPPRESSED => 0];

        foreach ($this->messages as $message) {
            $counts[$message['state']]++;
        }

        return $counts;
    }

    /** @return list<string> every stored body that still contains something it should not */
    public function leakingMessages(): array
    {
        $leaking = [];

        foreach ($this->messages as $id => $message) {
            if (Redactor::leaks($message['body'])) {
                $leaking[] = $id;
            }
        }

        return $leaking;
    }

    private function transition(string $id, string $state, string $note): void
    {
        if (!isset($this->messages[$id])) {
            throw new \InvalidArgumentException("No message {$id} to move to {$state}.");
        }

        $this->messages[$id]['state'] = $state;
        $this->messages[$id]['history'][] = $note;
    }
}
