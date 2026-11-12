<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Calendar;

/**
 * THE ONE EVALUATOR — Part XII §XII.2.1, R-XII-001 and R-XII-002.
 *
 * "There is exactly ONE implementation of weekly-off determination in
 * SmartPRS 3." Twelve consumers call it and nothing else (R-XII-004): attendance
 * day derivation, absence detection, work-time policy rules, the payroll LOP
 * denominator, leave day counting, sandwich adjacency, comp-off accrual, overtime
 * eligibility, points, reports and the muster roll, roster publish validation,
 * and both simulators.
 *
 * SmartPRS 2 has FOUR definitions, and the divergence is not theoretical:
 * `ShiftResolver::resolve():118-125`, `AbsenceService.php:45`,
 * `PayrollGenController::isWeekOff():70-89` and `PointsController::monthMeta():259`,
 * the last of which believes Sunday is the only weekly off there is. The payslip
 * and the muster roll read two of the four, which is why they disagree about how
 * many days somebody worked.
 *
 * Three properties, each load-bearing:
 *
 *   PURE AND TOTAL (R-XII-003). No clock beyond the injected date, no
 *   randomness, and every input produces a verdict — including a verdict that
 *   says it could not resolve. It NEVER defaults to "working" or to "off".
 *   SmartPRS 2's absence sweep marks the entire workforce absent when the holiday
 *   query throws, because a thrown exception had nowhere to go but into the
 *   answer.
 *
 *   RESOLVED AS AT THE DATE (R-XII-005), never "current". Recomputing August with
 *   a pattern published in October reproduces AUGUST's answer, which is the
 *   difference between a re-run that agrees with the payslip and one that
 *   silently restates it.
 *
 *   PRECEDENCE IS DECLARED. A holiday on a weekly off is recorded as the weekly
 *   off, because the employee was already resting. Whether that earns a
 *   compensatory day is policy, and the verdict carries what policy needs.
 *
 * The roster wins over the pattern, always. A roster cell is somebody's explicit
 * decision about a named day; a pattern is the default that applies when nobody
 * made one.
 */
final class CalendarService
{
    /**
     * @param (callable(string, \DateTimeImmutable): ?WeeklyOffPattern) $resolvePattern
     *        the pattern governing this employment AS AT the date (spine S4)
     * @param (callable(string, \DateTimeImmutable): ?string) $rosterCell
     *        the roster's own answer for the day: 'off', 'work', or null for no cell
     * @param (callable(string, \DateTimeImmutable): ?string) $holiday
     *        the holiday code in force for this employment's calendar, or null
     * @param (callable(string, \DateTimeImmutable): list<int>) $elected
     *        the employee's elected weekdays, for an ELECTED pattern
     */
    public function __construct(
        private $resolvePattern,
        private $rosterCell = null,
        private $holiday = null,
        private $elected = null,
    ) {
    }

    /**
     * R-XII-001. The signature every consumer calls.
     *
     * Wrapped in a catch not to swallow anything but to CONVERT: a resolution
     * failure becomes a typed verdict the caller must handle, which is exactly
     * what stops it becoming "everybody was absent".
     */
    public function isWeeklyOff(string $employmentId, \DateTimeImmutable $date): WeeklyOffVerdict
    {
        try {
            // The roster first: an explicit cell for a named day beats the
            // default that applies when nobody decided.
            $cell = $this->rosterCell === null ? null : ($this->rosterCell)($employmentId, $date);

            if ($cell === 'off') {
                return WeeklyOffVerdict::off(
                    WeeklyOffVerdict::ROSTER_CELL,
                    'roster',
                    'roster',
                    trace: "the roster names {$date->format('D j M Y')} an off for this employment",
                );
            }

            $pattern = ($this->resolvePattern)($employmentId, $date);

            if ($pattern === null) {
                return WeeklyOffVerdict::unresolved(
                    "No weekly-off pattern governs employment {$employmentId} on {$date->format('Y-m-d')}. "
                    . 'This is a typed verdict rather than a default, because both defaults are wrong: '
                    . '"working" charges somebody an absence they did not take, and "off" pays for a day '
                    . 'nobody granted (R-XII-003).',
                    trace: 'pattern resolution as at ' . $date->format('Y-m-d') . ' returned nothing',
                );
            }

            if ($cell === 'work') {
                // A roster cell scheduling work overrides the pattern's off. The
                // day is still a working day, and the fact that the PATTERN said
                // otherwise is what makes it a weekly-off-worked (R-XII-015)
                // rather than an ordinary Tuesday.
                return WeeklyOffVerdict::working(
                    $pattern->code,
                    $pattern->versionId,
                    'the roster scheduled work on a day the pattern would have made an off',
                );
            }

            $decision = $pattern->decide($date, $this->electedFor($employmentId, $date));

            return $decision['off']
                ? WeeklyOffVerdict::off(
                    $decision['reason'],
                    $pattern->code,
                    $pattern->versionId,
                    $decision['slot'],
                    "{$pattern->code} ({$pattern->kind}) makes {$date->format('D j M Y')} an off",
                )
                : WeeklyOffVerdict::working(
                    $pattern->code,
                    $pattern->versionId,
                    "{$pattern->code} ({$pattern->kind}) does not make {$date->format('D j M Y')} an off",
                );
        } catch (\Throwable $e) {
            // Converted, not swallowed: the exception's message travels into a
            // typed UNRESOLVED verdict the caller must handle, which is the
            // whole mechanism R-XII-003 asks for. SmartPRS 2 lets a thrown
            // holiday query mark a workforce absent because the failure had
            // nowhere else to go but into the answer.
            return WeeklyOffVerdict::unresolved(
                'Weekly-off resolution failed: ' . $e->getMessage(),
                trace: $e::class . ' while resolving ' . $date->format('Y-m-d'),
            );
        }
    }

    /**
     * The employee's own elected weekdays, for an ELECTED pattern.
     *
     * An employment with no election under an elected pattern gets an EMPTY set
     * rather than a default weekday, so the pattern grants no off and the day
     * reads as working. That is deliberately visible: an employee under a Shops
     * and Establishments elected off who has not chosen one is a configuration
     * gap somebody must close, and quietly giving them Sunday would hide it.
     *
     * @return list<int>
     */
    private function electedFor(string $employmentId, \DateTimeImmutable $date): array
    {
        return $this->elected === null ? [] : ($this->elected)($employmentId, $date);
    }

    /** R-XII-004's `isHoliday`. The same total contract. */
    public function isHoliday(string $employmentId, \DateTimeImmutable $date): ?string
    {
        return $this->holiday === null ? null : ($this->holiday)($employmentId, $date);
    }

    /**
     * The day's type, with the declared precedence applied.
     *
     * @return array{type: DayType, verdict: WeeklyOffVerdict, holiday: ?string, why: string}
     */
    public function resolveDayType(string $employmentId, \DateTimeImmutable $date): array
    {
        $verdict = $this->isWeeklyOff($employmentId, $date);

        if ($verdict->isUnresolved()) {
            // The unresolved verdict travels UP. A day type of "working" here
            // would be the default R-XII-003 forbids, dressed as an answer.
            return [
                'type' => DayType::Working,
                'verdict' => $verdict,
                'holiday' => null,
                'why' => 'UNRESOLVED — the caller must handle this rather than read the type: ' . $verdict->error,
            ];
        }

        $holiday = $this->isHoliday($employmentId, $date);

        if ($verdict->isWeeklyOff) {
            return [
                'type' => DayType::WeeklyOff,
                'verdict' => $verdict,
                'holiday' => $holiday,
                'why' => $holiday === null
                    ? $verdict->describe()
                    : $verdict->describe() . ", and {$holiday} falls on it — the weekly off takes precedence "
                        . 'because the employee was already not working, and whether the holiday earns a '
                        . 'compensatory day is a policy question this verdict carries enough to answer',
            ];
        }

        return $holiday === null
            ? ['type' => DayType::Working, 'verdict' => $verdict, 'holiday' => null, 'why' => $verdict->describe()]
            : ['type' => DayType::Holiday, 'verdict' => $verdict, 'holiday' => $holiday, 'why' => "{$holiday}"];
    }

    /**
     * R-XII-004's `workingDays` — the payroll LOP denominator, and the one number
     * SmartPRS 2 computes four different ways.
     *
     * An unresolved day is NOT counted as working and NOT skipped: it is
     * reported, and a range containing one has no working-day count at all. A
     * denominator computed over a range the system could not fully resolve is a
     * denominator that divides somebody's salary by a guess.
     *
     * @return array{days: ?int, unresolved: list<string>, by_type: array<string, int>}
     */
    public function workingDays(
        string $employmentId,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        if ($to < $from) {
            throw new \InvalidArgumentException(
                'The range ends before it begins. A negative range silently returning zero working days is a '
                . 'zero denominator somewhere downstream.'
            );
        }

        $working = 0;
        $unresolved = [];
        $byType = [];

        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $resolved = $this->resolveDayType($employmentId, $date);

            if ($resolved['verdict']->isUnresolved()) {
                $unresolved[] = $date->format('Y-m-d');

                continue;
            }

            $type = $resolved['type']->value;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $working += $resolved['type']->isWorkingDay() ? 1 : 0;
        }

        return [
            'days' => $unresolved === [] ? $working : null,
            'unresolved' => $unresolved,
            'by_type' => $byType,
        ];
    }
}
