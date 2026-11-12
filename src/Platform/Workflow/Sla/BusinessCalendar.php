<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow\Sla;

/**
 * SLA ARITHMETIC — Part IX §IX.7.2, R-IX-057 and R-IX-058.
 *
 * "SLA arithmetic MUST use absolute instants and the node's resolved calendar,
 * and MUST convert to the instance's business timezone for display.
 * Minutes-since-midnight arithmetic is prohibited."
 *
 * Everything below takes and returns `DateTimeImmutable` — an absolute instant.
 * The calendar's timezone is used to decide WHICH DAY an instant falls on and
 * what its working window is, and never to do arithmetic on a clock reading.
 * The distinction is the whole rule: on the day a zone shifts, "09:00 plus 480
 * minutes" and "09:00 to 17:00" are different amounts of elapsed time, and only
 * one of them is what an SLA promised.
 *
 * SmartPRS 2's greeting sweep uses the server's hour behind a docblock claiming
 * the tenant's. The same class of bug in an SLA moves every due date by hours,
 * silently, and only for some of the year.
 *
 * ponytail: no calendar table yet. This is a value object the caller resolves
 * and supplies, exactly like every other input to these engines — they hold no
 * connection. When `cfg_sla_calendars` lands, a repository builds one of these
 * and nothing here changes.
 */
final readonly class BusinessCalendar
{
    /** §IX.7.2. */
    public const CLOCK_MODES = ['business_hours', 'calendar_hours', 'calendar_days'];

    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * A day this walker will not step past before giving up.
     *
     * A calendar with no working day at all, or a holiday list that swallows a
     * year, would otherwise loop forever. The cap turns that into a named
     * error naming the calendar, which is a configuration defect somebody can
     * fix rather than a worker that never returns.
     */
    private const MAX_DAYS_WALKED = 3650;

    /**
     * @param array<string, array{start: string, end: string}> $workingDays  'mon'..'sun' => local HH:MM window
     * @param list<string>                                     $holidays     Y-m-d in this calendar's zone
     * @param list<array{start: string, end: string}>          $breaks       daily non-working intervals, local HH:MM
     */
    public function __construct(
        public string $key,
        public \DateTimeZone $timezone,
        public array $workingDays = [],
        public array $holidays = [],
        public array $breaks = [],
        public string $clockMode = 'business_hours',
    ) {
        if (!in_array($clockMode, self::CLOCK_MODES, true)) {
            throw new \InvalidArgumentException(
                "Calendar '{$key}': '{$clockMode}' is not a clock mode. One of: " . implode(', ', self::CLOCK_MODES) . '.'
            );
        }

        foreach ($workingDays as $day => $window) {
            if (!in_array($day, self::WEEKDAYS, true)) {
                throw new \InvalidArgumentException("Calendar '{$key}': '{$day}' is not a weekday.");
            }

            self::assertTime($key, $window['start'] ?? '');
            self::assertTime($key, $window['end'] ?? '');

            if (($window['end'] <=> $window['start']) !== 1) {
                throw new \InvalidArgumentException(
                    "Calendar '{$key}': {$day} ends at {$window['end']}, which is not after {$window['start']}. "
                    . 'A working day with no length would make every SLA on it unreachable.'
                );
            }
        }

        if ($clockMode === 'business_hours' && $workingDays === []) {
            throw new \InvalidArgumentException(
                "Calendar '{$key}': clock_mode is business_hours and no working day is declared, so no SLA "
                . 'measured against it could ever elapse. A calendar with no working hours is a configuration '
                . 'error, not a calendar that is always closed.'
            );
        }
    }

    /**
     * A 24/7 calendar: the honest way to say "no business hours apply".
     *
     * It exists so that a node with no calendar is a DECLARED choice at the call
     * site rather than a null the arithmetic silently reinterprets.
     */
    public static function alwaysOpen(string $key = 'CALENDAR-24x7', ?\DateTimeZone $timezone = null): self
    {
        return new self($key, $timezone ?? new \DateTimeZone('UTC'), clockMode: 'calendar_hours');
    }

    /** Mon–Fri, one window a day, in one zone. The shape most tenants configure. */
    public static function weekdays(
        string $key,
        \DateTimeZone $timezone,
        string $start = '09:30',
        string $end = '18:00',
        array $holidays = [],
        array $breaks = [],
    ): self {
        $days = [];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri'] as $day) {
            $days[$day] = ['start' => $start, 'end' => $end];
        }

        return new self($key, $timezone, $days, $holidays, $breaks);
    }

    /**
     * The instant `$minutes` of THIS CALENDAR'S time after `$from`.
     *
     * For `calendar_hours` and `calendar_days` this is plain elapsed time. For
     * `business_hours` it consumes only the minutes inside working windows, so
     * a two-day SLA raised at 17:00 on a Friday is not due at 17:00 on Sunday.
     */
    public function add(\DateTimeImmutable $from, int $minutes): \DateTimeImmutable
    {
        if ($minutes < 0) {
            throw new \InvalidArgumentException('An SLA is a positive number of minutes. Use subtract() to go back.');
        }

        if ($this->clockMode === 'calendar_hours') {
            return $from->modify("+{$minutes} minutes");
        }

        if ($this->clockMode === 'calendar_days') {
            // §IX.7.2 counts whole days here, so the remainder is carried as
            // minutes rather than silently truncated away.
            return $from->modify('+' . intdiv($minutes, 1440) . ' days')->modify('+' . $minutes % 1440 . ' minutes');
        }

        $cursor = $from->setTimezone($this->timezone);
        $remaining = $minutes;
        $walked = 0;

        while ($remaining > 0) {
            if ($walked++ > self::MAX_DAYS_WALKED) {
                throw new \RuntimeException(
                    "Calendar '{$this->key}': walked " . self::MAX_DAYS_WALKED . ' days without consuming '
                    . "{$minutes} working minutes. Its working days or holiday list make this SLA unreachable."
                );
            }

            $windows = $this->windowsOn($cursor);

            if ($windows === []) {
                $cursor = $this->startOfNextDay($cursor);

                continue;
            }

            foreach ($windows as $window) {
                if ($remaining <= 0) {
                    break;
                }

                // Time already past in this window is not available to spend.
                $start = $cursor > $window[0] ? $cursor : $window[0];

                if ($start >= $window[1]) {
                    continue;
                }

                $available = self::minutesBetween($start, $window[1]);

                if ($available >= $remaining) {
                    return $start->modify("+{$remaining} minutes")->setTimezone($from->getTimezone());
                }

                $remaining -= $available;
                $cursor = $window[1];
            }

            if ($remaining > 0) {
                $cursor = $this->startOfNextDay($cursor);
            }
        }

        return $cursor->setTimezone($from->getTimezone());
    }

    /**
     * Working minutes between two instants, on this calendar.
     *
     * This is what a breach duration is measured in, and it is why R-IX-058
     * matters: an item overdue across a weekend is not overdue by 4,320 minutes
     * on a business-hours calendar, and reporting it that way makes every SLA
     * report a work of fiction.
     */
    public function elapsedMinutes(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        if ($this->clockMode !== 'business_hours') {
            return self::minutesBetween($from, $to);
        }

        $cursor = $from->setTimezone($this->timezone);
        $end = $to->setTimezone($this->timezone);
        $elapsed = 0;
        $walked = 0;

        while ($cursor < $end) {
            if ($walked++ > self::MAX_DAYS_WALKED) {
                throw new \RuntimeException(
                    "Calendar '{$this->key}': the interval spans more than " . self::MAX_DAYS_WALKED
                    . ' days, which is not an SLA measurement.'
                );
            }

            foreach ($this->windowsOn($cursor) as $window) {
                $start = $cursor > $window[0] ? $cursor : $window[0];
                $finish = $end < $window[1] ? $end : $window[1];

                if ($finish > $start) {
                    $elapsed += self::minutesBetween($start, $finish);
                }
            }

            $cursor = $this->startOfNextDay($cursor);
        }

        return $elapsed;
    }

    /** Whether this instant falls inside a working window. */
    public function isWorking(\DateTimeImmutable $at): bool
    {
        if ($this->clockMode !== 'business_hours') {
            return true;
        }

        $local = $at->setTimezone($this->timezone);

        foreach ($this->windowsOn($local) as $window) {
            if ($local >= $window[0] && $local < $window[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The working windows of the day `$local` falls on, as absolute instants.
     *
     * R-IX-058: a holiday and a non-working weekday both produce none. The
     * holiday list is the attendance engine's — there is not a second one.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function windowsOn(\DateTimeImmutable $local): array
    {
        $day = strtolower($local->format('D'));

        if (in_array($local->format('Y-m-d'), $this->holidays, true) || !isset($this->workingDays[$day])) {
            return [];
        }

        $window = $this->workingDays[$day];
        $open = $this->at($local, $window['start']);
        $close = $this->at($local, $window['end']);

        if ($this->breaks === []) {
            return [[$open, $close]];
        }

        // A break splits the day's window. Breaks are declared in order and are
        // clipped to the window, so a break outside working hours is a no-op
        // rather than an error — a calendar shared across sites often carries a
        // lunch break that one site's shift does not reach.
        $windows = [];
        $cursor = $open;

        foreach ($this->breaks as $break) {
            $breakStart = $this->at($local, $break['start']);
            $breakEnd = $this->at($local, $break['end']);

            if ($breakEnd <= $cursor || $breakStart >= $close) {
                continue;
            }

            if ($breakStart > $cursor) {
                $windows[] = [$cursor, $breakStart];
            }

            $cursor = $breakEnd > $cursor ? $breakEnd : $cursor;
        }

        if ($cursor < $close) {
            $windows[] = [$cursor, $close];
        }

        return $windows;
    }

    /**
     * A local clock reading on the day `$local` falls on, as an instant.
     *
     * Built by setting the time on a date in this zone rather than by adding
     * minutes to midnight: on a DST transition day midnight plus 570 minutes is
     * not 09:30, and 09:30 is what the calendar declared.
     */
    private function at(\DateTimeImmutable $local, string $time): \DateTimeImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $time));

        return $local->setTime($hour, $minute);
    }

    private function startOfNextDay(\DateTimeImmutable $local): \DateTimeImmutable
    {
        return $local->modify('+1 day')->setTime(0, 0);
    }

    private static function minutesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }

    private static function assertTime(string $key, string $time): void
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new \InvalidArgumentException("Calendar '{$key}': '{$time}' is not an HH:MM time.");
        }
    }
}
