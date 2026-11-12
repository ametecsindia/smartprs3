<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain\Money;

/** CLEAN FIXTURE — integer minor units end to end, exact comparison only. */
final class LoanInstalment
{
    public function __construct(private readonly Money $outstanding)
    {
    }

    public function outstandingPaise(): int
    {
        return $this->outstanding->paise();
    }

    public function shouldDeduct(Money $instalment): bool
    {
        if ($this->outstanding->isZero()) {
            return false;
        }
        return $this->outstanding->greaterThanOrEqual($instalment);
    }

    public function remainder(Money $instalment): Money
    {
        return $this->outstanding->minus($instalment);
    }
}
