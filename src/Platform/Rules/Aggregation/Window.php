<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Rules\Aggregation;

/**
 * AN AGGREGATION WINDOW, RESOLVED TO A DATE RANGE — Part VII §5.9.
 *
 * The parser has known the window vocabulary since SPX was written and nothing
 * turned a window into two dates: §5.9 leaves that to "the caller", and the
 * caller is a domain service that does not exist yet. So `agg.count(late_arrival,
 * payroll_period, …)` compiled, keyed and type-checked, and no code anywhere
 * could say which days `payroll_period` meant.
 *
 * This is that code, and it is pure: dates in, a range out. It holds no
 * connection and reads no clock — the subject's date is supplied, and so is the
 * pay cycle, because R-VII-022 is emphatic that a payroll period comes from the
 * employee's effective-dated pay-cycle definition and NEVER from the calendar
 * month. SmartPRS 2's free-lates window is the payroll month by accident of two
 * lines in PayrollGenController, and its sandwich calculation indexes
 * day-of-month integers inside one month, so on a cut-off cycle it reads the
 * wrong month entirely.
 *
 * Every range below is INCLUSIVE at both ends. A half-open range is easier to
 * compose and harder to argue about with an administrator, and every rule this
 * serves is written in terms of days somebody can point at on a calendar.
 */
final readonly class Window
{
    /** §5.3's window_spec vocabulary, with the arity the parser enforces. */
    public const KINDS = [
        'day' => 0,
        'week' => 1,
        'payroll_period' => 0,
        'calendar_month' => 0,
        'quarter' => 0,
        'financial_year' => 0,
        'rolling' => 2,
        'since' => 1,
        'service_year' => 0,
    ];

    public const UNITS = ['days', 'weeks', 'months'];

    private const WEEKDAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public string $kind,
        public string $key,
    ) {
        if ($to < $from) {
            throw new \InvalidArgumentException(
                "Window '{$kind}' resolved to {$from->format('Y-m-d')} → {$to->format('Y-m-d')}, which is backwards."
            );
        }
    }

    /**
     * Resolve one window for one subject on one date.
     *
     * @param  array<string, mixed>  $arguments  the window's own arguments: week's start day,
     *                                           rolling's n and unit, since's date
     * @throws \InvalidArgumentException when the window needs a fact the caller did not supply
     */
    public static function resolve(
        string $kind,
        \DateTimeImmutable $subjectDate,
        AggregationContext $context,
        array $arguments = [],
    ): self {
        if (!array_key_exists($kind, self::KINDS)) {
            throw new \InvalidArgumentException(
                "'{$kind}' is not an aggregation window. The vocabulary is closed (§5.3): "
                . implode(', ', array_keys(self::KINDS)) . '.'
            );
        }

        $day = $subjectDate->setTime(0, 0);

        return match ($kind) {
            'day' => new self($day, $day, $kind, $day->format('Y-m-d')),
            'week' => self::week($day, $arguments),
            'payroll_period' => self::payrollPeriod($day, $context),
            'calendar_month' => new self(
                $day->modify('first day of this month'),
                $day->modify('last day of this month'),
                $kind,
                $day->format('Y-m'),
            ),
            'quarter' => self::quarter($day, $context),
            'financial_year' => self::financialYear($day, $context),
            'rolling' => self::rolling($day, $arguments),
            'since' => self::since($day, $arguments),
            'service_year' => self::serviceYear($day, $context),
        };
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        $day = $date->setTime(0, 0);

        return $day >= $this->from && $day <= $this->to;
    }

    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function __toString(): string
    {
        return $this->kind . ' [' . $this->from->format('Y-m-d') . ' → ' . $this->to->format('Y-m-d') . ']';
    }

    /**
     * `week('<start_day>')`. The start day is DECLARED, because a week that
     * starts on Monday in one tenant and Sunday in another is not a default
     * anything can carry.
     */
    private static function week(\DateTimeImmutable $day, array $arguments): self
    {
        $startDay = strtolower((string) ($arguments['start_day'] ?? ''));

        if (!isset(self::WEEKDAYS[$startDay])) {
            throw new \InvalidArgumentException(
                "week() needs a start_day, one of '" . implode("', '", array_keys(self::WEEKDAYS))
                . "'. A week with no declared start is two different weeks."
            );
        }

        $offset = ((int) $day->format('N') - self::WEEKDAYS[$startDay] + 7) % 7;
        $from = $day->modify("-{$offset} days");

        return new self($from, $from->modify('+6 days'), 'week', $from->format('o-\WW'));
    }

    /**
     * R-VII-022. From the employee's effective-dated pay cycle, and refused
     * outright when the caller did not supply one — a payroll period silently
     * falling back to the calendar month is the exact defect this rule names.
     */
    private static function payrollPeriod(\DateTimeImmutable $day, AggregationContext $context): self
    {
        $period = $context->payrollPeriodFor($day);

        if ($period === null) {
            throw new \InvalidArgumentException(
                'payroll_period was asked for ' . $day->format('Y-m-d') . ' and no pay cycle covers that date. '
                . 'R-VII-022: the period comes from the employee\'s effective-dated pay-cycle definition, '
                . 'honouring its cut-off day, and NEVER from the calendar month.'
            );
        }

        return new self($period['from'], $period['to'], 'payroll_period', $period['code']);
    }

    /**
     * Quarters align to the financial year, not to January.
     *
     * That follows from the financial-year start the caller already has to
     * supply rather than being a second decision: a tenant whose year starts in
     * April has Q1 = April–June, and a tenant who wants calendar quarters
     * declares a year starting in January. One parameter, one meaning.
     */
    private static function quarter(\DateTimeImmutable $day, AggregationContext $context): self
    {
        $yearStart = self::financialYear($day, $context)->from;
        $elapsed = (int) $yearStart->diff($day)->format('%m') + ((int) $yearStart->diff($day)->format('%y') * 12);
        $quarter = intdiv($elapsed, 3);

        $from = $yearStart->modify('+' . ($quarter * 3) . ' months');
        $to = $from->modify('+3 months')->modify('-1 day');

        return new self($from, $to, 'quarter', $from->format('Y-m') . ':Q' . ($quarter + 1));
    }

    private static function financialYear(\DateTimeImmutable $day, AggregationContext $context): self
    {
        $startMonth = $context->financialYearStartMonth;
        $year = (int) $day->format('n') >= $startMonth ? (int) $day->format('Y') : (int) $day->format('Y') - 1;

        $from = $day->setDate($year, $startMonth, 1)->setTime(0, 0);
        $to = $from->modify('+1 year')->modify('-1 day');

        return new self($from, $to, 'financial_year', $from->format('Y') . '-' . $to->format('y'));
    }

    /**
     * R-VII-023, verbatim: "a half-open window ending at, and including, the
     * subject's own date: [date − n units + 1 unit, date]".
     *
     * Spelled out because "3 lates in 30 days" is ambiguous otherwise, and the
     * off-by-one is the difference between a 30-day and a 31-day window on
     * every single evaluation.
     */
    private static function rolling(\DateTimeImmutable $day, array $arguments): self
    {
        $n = (int) ($arguments['n'] ?? 0);
        $unit = strtolower((string) ($arguments['unit'] ?? ''));

        if ($n < 1) {
            throw new \InvalidArgumentException('rolling() needs a positive length; a window of zero units is not a window.');
        }

        if (!in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException(
                "rolling()'s unit is one of '" . implode("', '", self::UNITS) . "', not '{$unit}'."
            );
        }

        // − n units, then + 1 unit's smallest step, so the window is n units
        // long INCLUDING today. `rolling(30, days)` on the 30th of a month
        // starts on the 1st, not the 31st of the month before.
        $from = match ($unit) {
            'days' => $day->modify('-' . ($n - 1) . ' days'),
            'weeks' => $day->modify('-' . ($n * 7 - 1) . ' days'),
            'months' => $day->modify('-' . $n . ' months')->modify('+1 day'),
        };

        return new self($from, $day, 'rolling', "{$n}{$unit}:" . $day->format('Y-m-d'));
    }

    private static function since(\DateTimeImmutable $day, array $arguments): self
    {
        $from = $arguments['date'] ?? null;

        if (!$from instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('since() needs a date to count from.');
        }

        return new self($from->setTime(0, 0), $day, 'since', $from->format('Y-m-d'));
    }

    /**
     * The service year running on the subject's date: from the joining
     * anniversary to the day before the next one.
     *
     * Not "the calendar year of joining" and not "months since joining rounded"
     * — an employee who joined on 15 August is in their third service year from
     * 15 August, and a leave accrual that says otherwise is wrong by up to a
     * year at the boundary.
     */
    private static function serviceYear(\DateTimeImmutable $day, AggregationContext $context): self
    {
        $joined = $context->joiningDate;

        if ($joined === null) {
            throw new \InvalidArgumentException(
                'service_year was asked for and the subject has no joining date. There is no service year '
                . 'without one, and assuming the start of the financial year would silently mis-accrue.'
            );
        }

        $joined = $joined->setTime(0, 0);

        if ($day < $joined) {
            throw new \InvalidArgumentException(
                'service_year was asked for ' . $day->format('Y-m-d') . ', before the joining date '
                . $joined->format('Y-m-d') . ". The engine's joining guard should have stopped the evaluation first."
            );
        }

        $completed = (int) $joined->diff($day)->y;
        $from = $joined->modify("+{$completed} years");

        return new self($from, $from->modify('+1 year')->modify('-1 day'), 'service_year', 'Y' . ($completed + 1));
    }
}
