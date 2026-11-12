<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain\Money;

/** VIOLATING FIXTURE — the SmartPRS 2 loan-instalment defect, in shape. */
final class LoanInstalment
{
    public function outstanding(float $balance): float
    {
        return $balance;
    }

    public function shouldDeduct(string $raw): bool
    {
        $balance = (float) $raw;
        $amount = floatval($raw);

        if ($balance == 0.0) {
            return false;
        }
        return round($amount, 2) != 0.0;
    }
}
