<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * The primitive behind Law 3 (spine S1, V.5.3).
 *
 * Held internally as a half-open interval [from, untilExclusive) because that is
 * the only representation in which overlaps and abutment are one comparison and
 * cannot be off by a day. The blueprint's persisted shape is the *inclusive*
 * effective_to, and effectiveTo() renders it — publishing a successor closes the
 * predecessor at successor.from - 1 day, which is exactly untilExclusive.
 *
 * There is no NULL from. A NULL to means "in force until superseded" and is
 * never confused with "not yet effective" (V.5.3).
 */
final readonly class EffectiveRange
{
    private function __construct(
        public \DateTimeImmutable $from,
        public ?\DateTimeImmutable $untilExclusive,
    ) {
    }

    /** The persisted shape: effective_from and an inclusive, nullable effective_to. */
    public static function fromInclusive(
        \DateTimeImmutable|string $from,
        \DateTimeImmutable|string|null $toInclusive = null,
    ): self {
        $from = self::date($from);
        $to = $toInclusive === null ? null : self::date($toInclusive);

        if ($to !== null && $to < $from) {
            throw new \InvalidArgumentException(
                'effective_to must not precede effective_from (' . $to->format('Y-m-d')
                . ' < ' . $from->format('Y-m-d') . ').'
            );
        }

        return new self($from, $to?->modify('+1 day'));
    }

    /** Open-ended: in force from this date until superseded. */
    public static function openEndedFrom(\DateTimeImmutable|string $from): self
    {
        return self::fromInclusive($from, null);
    }

    public function isOpenEnded(): bool
    {
        return $this->untilExclusive === null;
    }

    /** Inclusive end date, as persisted. NULL when open-ended. */
    public function effectiveTo(): ?\DateTimeImmutable
    {
        return $this->untilExclusive?->modify('-1 day');
    }

    public function contains(\DateTimeImmutable|string $date): bool
    {
        $date = self::date($date);

        return $date >= $this->from
            && ($this->untilExclusive === null || $date < $this->untilExclusive);
    }

    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = $other->untilExclusive === null || $this->from < $other->untilExclusive;
        $otherStartsBeforeThisEnds = $this->untilExclusive === null || $other->from < $this->untilExclusive;

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }

    /** Adjacent with no gap and no overlap, in either direction. */
    public function abuts(self $other): bool
    {
        return ($this->untilExclusive !== null && $this->untilExclusive == $other->from)
            || ($other->untilExclusive !== null && $other->untilExclusive == $this->from);
    }

    /** Publishing a successor: close this range the day before the successor starts. */
    public function closedAt(\DateTimeImmutable|string $successorFrom): self
    {
        $successorFrom = self::date($successorFrom);

        if ($successorFrom <= $this->from) {
            throw new \InvalidArgumentException('A successor may not start on or before its predecessor.');
        }

        return new self($this->from, $successorFrom);
    }

    public function __toString(): string
    {
        return $this->from->format('Y-m-d') . ' → ' . ($this->effectiveTo()?->format('Y-m-d') ?? '');
    }

    private static function date(\DateTimeImmutable|string $value): \DateTimeImmutable
    {
        $date = is_string($value)
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : $value;

        // Dates are DATE, not instants (spine S16). Take the calendar day the
        // caller means — in *their* timezone — and rebuild it in UTC, so a
        // 2026-08-01 in Asia/Kolkata never becomes a 2026-07-31 here.
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
