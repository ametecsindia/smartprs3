<?php
declare(strict_types=1);

namespace SmartPRS\TimeAttendance\Domain;

/** VIOLATING FIXTURE — a derivation that cannot be reproduced or replayed. */
final class LateMarkRule
{
    public function evaluate(string $punch): array
    {
        $today = date('Y-m-d');
        $at = new \DateTime($punch);
        $stamp = time();
        $ref = \Carbon\Carbon::now();
        $token = uniqid();
        $jitter = random_int(0, 5);

        return [$today, $at, $stamp, $ref, $token, $jitter];
    }
}
