<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Aggregation;

/**
 * The facts a window needs that are not the subject's date.
 *
 * Every one of them is supplied rather than looked up, for the same reason the
 * evaluator takes a prepared context: this layer holds no connection, so the
 * pay cycle, the joining date and the financial year all arrive from a domain
 * service that resolved them under tenant scope, as at the right date.
 *
 * `financialYearStartMonth` has NO DEFAULT. April is right for India and wrong
 * for most of the world, and Law 1 does not make an exception for a value that
 * happens to be right for the first customer. A caller states it.
 */
final readonly class AggregationContext
{
    /**
     * @param list<array{code: string, from: \DateTimeImmutable, to: \DateTimeImmutable}> $payrollPeriods
     *        the employee's effective-dated pay cycle, already resolved (R-VII-022)
     */
    public function __construct(
        public int $financialYearStartMonth,
        public array $payrollPeriods = [],
        public ?\DateTimeImmutable $joiningDate = null,
    ) {
        if ($financialYearStartMonth < 1 || $financialYearStartMonth > 12) {
            throw new \InvalidArgumentException(
                "'{$financialYearStartMonth}' is not a month. The financial year's start is declared "
                . 'configuration, not a platform default — April is right for India and wrong elsewhere.'
            );
        }

        foreach ($payrollPeriods as $period) {
            if (!isset($period['code'], $period['from'], $period['to'])) {
                throw new \InvalidArgumentException(
                    'A payroll period is a code, a from and a to. A period without a code cannot appear in '
                    . 'a counter key, and one without dates is not a period.'
                );
            }

            if ($period['to'] < $period['from']) {
                throw new \InvalidArgumentException("Payroll period {$period['code']} ends before it starts.");
            }
        }
    }

    /**
     * The pay period covering a date, or null.
     *
     * Null rather than a fallback: `Window::resolve()` turns it into a named
     * refusal. A pay cycle with a gap is a configuration defect somebody must
     * fix, and the alternative — quietly using the calendar month — is the
     * defect R-VII-022 exists to name.
     *
     * @return array{code: string, from: \DateTimeImmutable, to: \DateTimeImmutable}|null
     */
    public function payrollPeriodFor(\DateTimeImmutable $date): ?array
    {
        $day = $date->setTime(0, 0);

        foreach ($this->payrollPeriods as $period) {
            if ($day >= $period['from']->setTime(0, 0) && $day <= $period['to']->setTime(0, 0)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * A monthly cycle with a cut-off day, built for a range of months.
     *
     * A cut-off of 25 means the August period runs 26 July to 25 August — which
     * is the case SmartPRS 2 gets wrong twice, once by treating the payroll
     * month as the calendar month and once by indexing day-of-month integers
     * inside the wrong month.
     *
     * @param int $cutOffDay the last day of the period; 0 means the month end
     */
    public static function monthlyCycle(
        int $financialYearStartMonth,
        int $cutOffDay,
        \DateTimeImmutable $firstPeriodEndsOn,
        int $periods = 24,
        ?\DateTimeImmutable $joiningDate = null,
    ): self {
        $list = [];
        $end = $firstPeriodEndsOn->setTime(0, 0);

        for ($i = 0; $i < $periods; $i++) {
            $start = $end->modify('first day of this month')->modify('-1 month');
            $start = $cutOffDay === 0
                ? $end->modify('first day of this month')
                : $start->setDate((int) $start->format('Y'), (int) $start->format('n'), min(
                    $cutOffDay + 1,
                    (int) $start->format('t'),
                ));

            $list[] = [
                'code' => $end->format('Y-m'),
                'from' => $start,
                'to' => $end,
            ];

            $end = $start->modify('-1 day');
        }

        return new self($financialYearStartMonth, $list, $joiningDate);
    }
}
