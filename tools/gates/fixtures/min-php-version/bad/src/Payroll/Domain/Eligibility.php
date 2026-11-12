<?php
declare(strict_types=1);

/** Violating: array_any() is PHP 8.4 but the floor is 8.3. */
final class Eligibility
{
    public function anyPaid(array $lines): bool
    {
        return array_any($lines, static fn (object $l): bool => $l->paid);
    }

    public function firstPaid(array $lines): ?object
    {
        return array_find($lines, static fn (object $l): bool => $l->paid);
    }
}
