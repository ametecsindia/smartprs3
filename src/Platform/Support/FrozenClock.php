<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

final class FrozenClock implements Clock
{
    private function __construct(
        private \DateTimeImmutable $instant,
        private \DateTimeZone $businessTimezone,
    ) {
    }

    public static function at(string $instant, string $businessTimezone = 'Asia/Kolkata'): self
    {
        return new self(
            // setTimezone, so an instant written with a 'Z' or an offset still
            // reports UTC rather than its literal suffix.
            (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC')),
            new \DateTimeZone($businessTimezone),
        );
    }

    public function now(): \DateTimeImmutable
    {
        return $this->instant;
    }

    public function today(): \DateTimeImmutable
    {
        return $this->instant->setTimezone($this->businessTimezone)->setTime(0, 0);
    }

    /** Test affordance: advance the frozen instant, e.g. '+1 day'. */
    public function advance(string $interval): void
    {
        $this->instant = $this->instant->modify($interval);
    }
}
