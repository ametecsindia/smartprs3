<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Attendance;

/**
 * THE CANONICAL PUNCH — Part XI §XI.2.2 and §XI.4.1.
 *
 * An ABSOLUTE INSTANT and a direction. Not a date and a time; not a string.
 *
 * That is the whole of XI.1.1 and XI.1.2 in one design decision. SmartPRS 2
 * builds `punch_at` by concatenating `log_date` with `"$HH:MM:00"` and orders
 * punches with `strcmp`, which makes an overnight pair structurally impossible
 * to express — the OUT at 06:00 sorts BEFORE the IN at 22:00. And its late
 * arithmetic is `first_in_min − (shift_start_min + grace)` on minutes since
 * midnight, so for a 22:00 shift an arrival at 00:15 computes
 * `max(0, 15 − 1320) = 0`: a night-shift worker can never be marked late, while
 * the same punctual arrival is simultaneously charged eight hours of early exit.
 *
 * `businessDate` on this class is the business date OF THE INSTANT in the punch's
 * timezone — the partition key of layer 2. It is deliberately NOT the attendance
 * day: "the attendance day cannot be a property of the punch, it must be a
 * derived, configurable attribution" (§XI.1.1). Which day this punch's work
 * belongs to is ShiftWindow's answer, and it is a different question with a
 * different answer for the same instant under two different shifts.
 */
final readonly class Punch
{
    public const IN = 'in';
    public const OUT = 'out';

    public function __construct(
        public string $employmentId,
        public \DateTimeImmutable $at,
        public string $direction,
        public string $sourceCode = 'device',
        public ?string $deviceId = null,
        public ?string $externalId = null,
    ) {
        if ($direction !== self::IN && $direction !== self::OUT) {
            throw new \InvalidArgumentException(
                "'{$direction}' is not a punch direction. A punch whose direction is unknown is not a punch "
                . 'with a default — SmartPRS 2 infers direction from position in a list, so one missed swipe '
                . 'inverts every pair after it.'
            );
        }

        if ($at->getTimezone()->getName() === 'UTC' && $at->format('H:i:s') === '00:00:00') {
            // Not a hard error, but worth stating: a punch at exactly midnight
            // UTC is almost always a date that lost its time somewhere upstream.
            // The check is cheap and the alternative is an 00:00 punch that
            // silently anchors a whole night's work to the wrong day.
            throw new \InvalidArgumentException(
                'A punch at exactly 00:00:00 UTC is a date that lost its time. If midnight is genuinely when '
                . 'somebody swiped, say so with a timezone: an instant with no time of day cannot be attributed '
                . 'to a shift.'
            );
        }
    }

    /** The business date of the INSTANT — layer 2's partition key, not the attendance day. */
    public function businessDate(): \DateTimeImmutable
    {
        return $this->at->setTime(0, 0);
    }

    public function isIn(): bool
    {
        return $this->direction === self::IN;
    }

    /** The idempotency handle: the same swipe re-ingested is the same row (R-XI-003). */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            $this->employmentId,
            $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $this->direction,
            $this->sourceCode,
            $this->deviceId ?? '',
        ]));
    }

    public function describe(): string
    {
        return strtoupper($this->direction) . ' at ' . $this->at->format('D j M Y H:i T')
            . ($this->deviceId === null ? '' : " on {$this->deviceId}");
    }
}
