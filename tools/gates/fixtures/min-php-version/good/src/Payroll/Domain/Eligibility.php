<?php
declare(strict_types=1);

/** Clean: the same intent, on the declared floor. */
final class Eligibility
{
    public function anyPaid(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->paid) {
                return true;
            }
        }

        return false;
    }
}
