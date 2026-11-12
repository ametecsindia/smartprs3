<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

final readonly class SystemClock implements Clock
{
    public function __construct(private \DateTimeZone $businessTimezone)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function today(): \DateTimeImmutable
    {
        return $this->now()
            ->setTimezone($this->businessTimezone)
            ->setTime(0, 0);
    }
}
