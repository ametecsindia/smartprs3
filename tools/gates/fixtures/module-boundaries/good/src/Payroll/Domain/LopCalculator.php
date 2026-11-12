<?php
declare(strict_types=1);

namespace SmartPRS\Payroll\Domain;

use SmartPRS\Absence\Contracts\LeaveSettlementReader;
use SmartPRS\TimeAttendance\Contracts\AttendanceSettlementReader;

/** CLEAN FIXTURE — both neighbours reached through their declared contracts. */
final class LopCalculator
{
    public function __construct(
        private readonly LeaveSettlementReader $leave,
        private readonly AttendanceSettlementReader $attendance,
    ) {
    }

    public function days(PayrollPeriod $period, EmploymentId $employment): LopDays
    {
        return LopDays::of(
            $this->attendance->absentDays($employment, $period),
            $this->leave->unpaidDays($employment, $period),
        );
    }
}
