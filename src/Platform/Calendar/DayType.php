<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Calendar;

/**
 * WHAT KIND OF DAY THIS IS — Part XII §XII.2.
 *
 * One closed catalogue, resolved by one evaluator, consumed by twelve callers
 * (R-XII-004). SmartPRS 2 has FOUR definitions of a weekly off — a shift
 * resolver's, an absence service's, a payroll controller's `isWeekOff()` and a
 * points controller that believes Sunday is the only one — so "was the 15th a
 * working day?" has four answers there depending on who asks, and the payslip
 * and the muster roll are two of the askers.
 *
 * The ordering below is the precedence when a date qualifies as more than one
 * thing, and it is a decision rather than an accident: a holiday that falls on a
 * weekly off is recorded as the WEEKLY OFF, because the employee was already not
 * working and a holiday that lands there does not become a second day of rest.
 * Whether it earns a compensatory off instead is a policy question, and the
 * verdict carries enough for policy to answer it.
 */
enum DayType: string
{
    case Working = 'working';
    case WeeklyOff = 'weekly_off';
    case Holiday = 'holiday';
    case RestDay = 'rest_day';
    case LeaveDay = 'leave_day';

    /** Higher wins when a date qualifies as more than one. */
    public function precedence(): int
    {
        return match ($this) {
            self::WeeklyOff => 4,
            self::Holiday => 3,
            self::RestDay => 2,
            self::LeaveDay => 1,
            self::Working => 0,
        };
    }

    /** Does a day of this type count toward the working-day denominator (Part XIV)? */
    public function isWorkingDay(): bool
    {
        return $this === self::Working;
    }

    public function label(): string
    {
        return match ($this) {
            self::Working => 'a working day',
            self::WeeklyOff => 'a weekly off',
            self::Holiday => 'a declared holiday',
            self::RestDay => 'a statutory rest day',
            self::LeaveDay => 'a day of approved leave',
        };
    }
}
