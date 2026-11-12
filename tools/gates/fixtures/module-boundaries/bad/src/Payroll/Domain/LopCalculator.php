<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain;

use SmartPRS\Absence\Domain\LeaveLedger;
use SmartPRS\TimeAttendance\Persistence\AttendanceDayRepository;

/** VIOLATING FIXTURE — Payroll reaching into Absence and Time internals. */
final class LopCalculator
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly AttendanceDayRepository $days,
    ) {
    }

    public function days(): int
    {
        return \SmartPRS\Absence\Domain\LeaveLedger::UNPAID;
    }
}
