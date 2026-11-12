<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Support;

/**
 * Domain code never calls now(). It asks the injected clock, so every engine is
 * deterministic and every calculation is reproducible from its inputs
 * (spine S5.6, S11 "a re-run reproduces the original result exactly").
 *
 * This is one of the few one-implementation-looking interfaces the blueprint
 * genuinely requires: FrozenClock is the second implementation and it is what
 * makes payroll testable at all.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;

    /** The business date in the caller's timezone — instants are UTC (spine S16). */
    public function today(): \DateTimeImmutable;
}
