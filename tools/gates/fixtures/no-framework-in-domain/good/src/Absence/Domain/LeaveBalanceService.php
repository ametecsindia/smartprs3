<?php
declare(strict_types=1);

namespace SmartPRS\Absence\Domain;

use SmartPRS\Absence\Domain\Contracts\LeaveLedgerReader;

/** CLEAN FIXTURE — value objects in, value object out, no framework anywhere. */
final class LeaveBalanceService
{
    public function __construct(private readonly LeaveLedgerReader $ledger)
    {
    }

    public function balance(EmploymentId $employment, LeaveTypeCode $type, BusinessDate $asAt): LeaveBalance
    {
        $entries = $this->ledger->entriesAsAt($employment, $type, $asAt);

        return LeaveBalance::fromEntries($entries);
    }
}
