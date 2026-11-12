<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Calendar;

/**
 * A WEEKLY-OFF PATTERN, AS CONFIGURATION — Part XII §XII.3.
 *
 * Five shapes, and the reason there are five rather than a boolean is the Indian
 * six-day week: "Sunday off" describes almost nobody. A collections floor runs
 * `sat_off_mode` = alternate Saturdays; a warehouse runs a rotation where the
 * off moves through the week; a shop under a state Shops and Establishments Act
 * has an elected off the employee chooses; a support desk has "two offs a
 * fortnight, allocated by the roster".
 *
 *   FIXED           named weekdays, every week
 *   NTH             the nth occurrence of a weekday in the month (2nd and 4th
 *                   Saturday, which is the one SmartPRS 2 half-implements)
 *   ROTATION        a repeating cycle over a fixed anchor date
 *   ELECTED         the employee's own choice, from an allowed set
 *   COUNT_ALLOCATED n offs per window, placed by the roster
 *
 * R-XII-012: no item here is a code literal. A tenant adds a pattern; nobody
 * ships a release for it. SmartPRS 2 encodes `sat_off_mode` as a column with
 * hard-coded meanings in four places, so a floor that wants 1st and 3rd Saturday
 * instead of 2nd and 4th needs a developer.
 */
final readonly class WeeklyOffPattern
{
    public const FIXED = 'fixed';
    public const NTH = 'nth';
    public const ROTATION = 'rotation';
    public const ELECTED = 'elected';
    public const COUNT_ALLOCATED = 'count_allocated';

    public const KINDS = [self::FIXED, self::NTH, self::ROTATION, self::ELECTED, self::COUNT_ALLOCATED];

    /**
     * @param list<int>                        $weekdays   ISO-8601: 1 = Monday … 7 = Sunday
     * @param list<array{weekday: int, nth: list<int>}> $nthRules
     * @param list<int>                        $cycle      rotation: offsets within the cycle that are off
     */
    public function __construct(
        public string $code,
        public string $kind,
        public string $versionId,
        public array $weekdays = [],
        public array $nthRules = [],
        public array $cycle = [],
        public int $cycleLength = 0,
        public ?\DateTimeImmutable $cycleAnchor = null,
        public int $offsPerWindow = 0,
        public string $window = 'week',
    ) {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(
                "'{$kind}' is not a weekly-off pattern kind. One of: " . implode(', ', self::KINDS) . '.'
            );
        }

        foreach ([...$weekdays, ...array_column($nthRules, 'weekday')] as $weekday) {
            if ($weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException(
                    "'{$weekday}' is not a weekday. ISO-8601 throughout: 1 is Monday and 7 is Sunday, because "
                    . "PHP's `w` is 0 for Sunday and mixing the two is a class of bug that only shows up on "
                    . 'one day of the week.'
                );
            }
        }

        if ($kind === self::FIXED && $weekdays === []) {
            throw new \InvalidArgumentException(
                "Pattern '{$code}' is fixed and names no weekday, so it grants no off at all. An employee on "
                . 'it works every day of the year, which nobody configured on purpose.'
            );
        }

        if ($kind === self::NTH && $nthRules === []) {
            throw new \InvalidArgumentException("Pattern '{$code}' is nth and declares no rule.");
        }

        if ($kind === self::ROTATION) {
            if ($cycleLength < 2) {
                throw new \InvalidArgumentException("A rotation needs a cycle of at least two days; '{$code}' has {$cycleLength}.");
            }

            if ($cycleAnchor === null) {
                throw new \InvalidArgumentException(
                    "Rotation '{$code}' names no anchor date. A cycle with no origin gives a different answer "
                    . 'depending on when it is asked, which is the opposite of what R-XII-003 requires.'
                );
            }

            foreach ($cycle as $offset) {
                if ($offset < 0 || $offset >= $cycleLength) {
                    throw new \InvalidArgumentException(
                        "Rotation '{$code}' marks day {$offset} off in a {$cycleLength}-day cycle."
                    );
                }
            }
        }

        if ($kind === self::COUNT_ALLOCATED && $offsPerWindow < 1) {
            throw new \InvalidArgumentException("Pattern '{$code}' allocates {$offsPerWindow} offs per {$window}.");
        }
    }

    /**
     * Does this pattern make `$date` an off?
     *
     * @param  list<int>  $electedWeekdays  the employee's own choice, for ELECTED
     * @return array{off: bool, reason: string, slot: ?int}
     */
    public function decide(\DateTimeImmutable $date, array $electedWeekdays = []): array
    {
        $weekday = (int) $date->format('N');

        return match ($this->kind) {
            self::FIXED => [
                'off' => in_array($weekday, $this->weekdays, true),
                'reason' => WeeklyOffVerdict::PATTERN_FIXED,
                'slot' => in_array($weekday, $this->weekdays, true)
                    ? array_search($weekday, $this->weekdays, true) + 1
                    : null,
            ],
            self::NTH => $this->decideNth($date, $weekday),
            self::ROTATION => $this->decideRotation($date),
            self::ELECTED => [
                'off' => in_array($weekday, $electedWeekdays, true),
                'reason' => WeeklyOffVerdict::PATTERN_ELECTED,
                'slot' => in_array($weekday, $electedWeekdays, true) ? 1 : null,
            ],
            // R-XII-010: a count-allocated pattern does not decide by itself.
            // The roster places the offs, and a date with no roster cell is NOT
            // an off — saying otherwise would give an employee an off the roster
            // never granted, on a day somebody was scheduled to work.
            self::COUNT_ALLOCATED => [
                'off' => false,
                'reason' => WeeklyOffVerdict::PATTERN_COUNT_ALLOCATED,
                'slot' => null,
            ],
            default => ['off' => false, 'reason' => WeeklyOffVerdict::NOT_OFF, 'slot' => null],
        };
    }

    /**
     * The nth occurrence of a weekday in ITS OWN MONTH.
     *
     * "2nd and 4th Saturday" counts Saturdays from the 1st of the month the date
     * falls in — not from the start of the payroll cycle, and not by dividing the
     * day number by seven. A month with five Saturdays has no 5th-Saturday rule
     * unless one is declared, and R-XII-009 makes a declared occurrence that does
     * not exist in a given month simply not apply rather than an error.
     */
    private function decideNth(\DateTimeImmutable $date, int $weekday): array
    {
        foreach ($this->nthRules as $rule) {
            if ($rule['weekday'] !== $weekday) {
                continue;
            }

            $occurrence = intdiv((int) $date->format('j') - 1, 7) + 1;

            if (in_array($occurrence, $rule['nth'], true)) {
                return ['off' => true, 'reason' => WeeklyOffVerdict::PATTERN_NTH, 'slot' => $occurrence];
            }

            // -1 is "the last one in the month", which is a different day from
            // the 4th in a month with five.
            if (in_array(-1, $rule['nth'], true)
                && $date->modify('+7 days')->format('n') !== $date->format('n')
            ) {
                return ['off' => true, 'reason' => WeeklyOffVerdict::PATTERN_NTH, 'slot' => $occurrence];
            }
        }

        return ['off' => false, 'reason' => WeeklyOffVerdict::NOT_OFF, 'slot' => null];
    }

    /** Days since the anchor, modulo the cycle. Total for every date, past or future. */
    private function decideRotation(\DateTimeImmutable $date): array
    {
        $days = (int) $this->cycleAnchor->diff($date)->format('%r%a');
        // PHP's % keeps the sign of the dividend, so a date BEFORE the anchor
        // would land on a negative offset and match nothing. The double modulo
        // is what makes the rotation total in both directions.
        $offset = (($days % $this->cycleLength) + $this->cycleLength) % $this->cycleLength;

        return in_array($offset, $this->cycle, true)
            ? ['off' => true, 'reason' => WeeklyOffVerdict::PATTERN_ROTATION, 'slot' => $offset + 1]
            : ['off' => false, 'reason' => WeeklyOffVerdict::NOT_OFF, 'slot' => null];
    }

    /** How many offs this pattern grants in a seven-day week, where that is knowable. */
    public function offsPerWeek(): ?int
    {
        return match ($this->kind) {
            self::FIXED => count($this->weekdays),
            self::COUNT_ALLOCATED => $this->window === 'week' ? $this->offsPerWindow : null,
            default => null,
        };
    }
}
