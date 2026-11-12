<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE WORKING CALENDAR, AS CONFIGURATION.
 *
 * `Surface\PayrollPeriod` computed working days as Monday to Friday, in a
 * `private static` method, with a `ponytail:` comment admitting it. `'09:00'`
 * and `'18:00'` were typed into every form on every screen. A six-day week, a
 * night shift and Diwali are not code.
 *
 * THE PRORATION BASIS IS DECLARED, NEVER DEFAULTED. Prorating over calendar days
 * and over working days give different payslips for the same month and the same
 * attendance. A platform that picks one silently has made a business decision on
 * the customer's behalf and not told them — so the config names it, and this
 * class refuses a file that does not.
 *
 * A HOLIDAY THAT FALLS ON A WEEKLY OFF IS NOT A SECOND DAY OFF. It is counted
 * once, because a day is either worked or it is not, and double-counting is the
 * arithmetic that makes a month have 32 days in it.
 *
 * ponytail: one calendar for the whole tenant, EXCEPT holidays, which are
 * scoped per company/branch/role/designation below — the one place a tenant
 * actually asked for "Pune gets it, Hyderabad doesn't" — AND the weekly off,
 * which since 30 Oct may have extra schedules by Applies-to (scheduleFor()). Shift
 * timings and the proration basis stay tenant-wide; `Platform\Calendar\
 * CalendarService`, named here before, was never built and nothing in this
 * codebase constructs it — this class is the one real implementation, so the
 * scoping below extends it rather than a class that does not exist.
 *
 * A HOLIDAY'S SCOPE IS EXPLICIT, NEVER INFERRED FROM BLANKNESS. Checking
 * "Any <dimension>" is how an admin leaves a dimension open on purpose; a
 * dimension left with nothing checked at all reaches nobody in it, and a
 * holiday with any dimension reaching nobody reaches nobody, full stop — the
 * owner's own rule: "if blank, it doesn't apply." A holiday nobody has ever
 * opened the scope editor for (every one shipped before this feature, and
 * anything saved by the old form) carries no `scope` key at all, which reaches
 * everybody exactly as it always did — that is the difference between "never
 * asked" and "asked and left blank", and it is the only reason turning this
 * on does not silently cancel every holiday for every tenant on the day it
 * ships.
 */
final class Calendar
{
    public const CALENDAR_DAYS = 'calendar_days';
    public const WORKING_DAYS = 'working_days';

    /** The dimensions a holiday's scope may narrow. Role, not the RBAC access tier. */
    public const HOLIDAY_SCOPES = ['company', 'branch', 'role', 'designation'];

    /** The explicit "no restriction here" choice — a real selection, never inferred from blankness. */
    public const ANY = 'ANY';

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** The customisation key the tenant's own holidays and weekly offs are saved under. */
    public const KEY = 'calendar';

    public function __construct(
        private readonly string $file,
        // THE TENANT'S OWN HOLIDAY LIST AND WEEKLY OFFS, from Organization
        // Setup → Holiday Calendar. Laid over the shipped file: the shift and
        // the proration basis stay the file's, because a payroll denominator
        // is not a thing to change from a holiday screen.
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return list<string> the kinds of holiday the file declares */
    public function holidayTypes(): array
    {
        return array_values(array_map('strval', (array) ($this->config()['holiday_types'] ?? [])));
    }

    public function isCustomised(): bool
    {
        return is_array($this->customisation?->live()[self::KEY] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->customisation?->history(self::KEY) ?? [];
    }

    /**
     * Save the tenant's holidays and weekly offs. Checked before kept.
     *
     * @param list<array{on?: string, name?: string}> $holidays
     * @param list<string> $weeklyOff three-letter day names
     * @return array{ok: bool, why: string}
     */
    public function saveCalendar(array $holidays, array $weeklyOff, string $byId, string $byLabel, array $weeklyOffNth = [], ?array $schedules = null): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $off = array_values(array_unique(array_filter(array_map(static fn ($d): string => ucfirst(strtolower(substr(trim((string) $d), 0, 3))), $weeklyOff),
            static fn (string $d): bool => in_array($d, $days, true))));

        if (count($off) >= 7) {
            return ['ok' => false, 'why' => 'Every day of the week cannot be a weekly off.'];
        }

        $nth = self::cleanNth($weeklyOffNth, $off);
        $woff = $schedules === null ? (array) ($this->config()['woff_schedules'] ?? []) : [];

        // EXTRA W.OFF SCHEDULES (owner, 30 Oct): each narrowed by the Late Login
        // Policy's Applies-to fields; whoever matches none follows the default above.
        foreach ($schedules ?? [] as $i => $sc) {
            $n = count($woff) + 2;
            $name = trim((string) ($sc['name'] ?? '')) ?: 'W.OFF Schedule ' . $n;
            $applies = [];

            foreach (array_keys(LatePolicy::APPLIES_TO) as $k) {
                $v = trim((string) ($sc['applies_to'][$k] ?? ''));
                $v !== '' && $applies[$k] = $v;
            }

            if ($applies === []) {
                return ['ok' => false, 'why' => "{$name}: pick at least one Applies-to field (Company, Branch, Role …) — everyone else already follows the default schedule."];
            }

            if (strcasecmp($name, 'Default') === 0) {
                return ['ok' => false, 'why' => '"Default" is Schedule 1\'s name — give this schedule another.'];
            }

            foreach ($woff as $prev) {
                if (strcasecmp($prev['name'], $name) === 0) {
                    return ['ok' => false, 'why' => "Two W.OFF schedules are named \"{$name}\" — give each its own name."];
                }

                if ($prev['applies_to'] == $applies) {
                    return ['ok' => false, 'why' => "{$name} applies to exactly the same people as {$prev['name']} — change one of them."];
                }
            }

            $scOff = array_values(array_intersect($days, array_map('strval', (array) ($sc['weekly_off'] ?? []))));

            if (count($scOff) >= 7) {
                return ['ok' => false, 'why' => "{$name}: every day of the week cannot be a weekly off."];
            }

            $woff[] = ['name' => $name, 'applies_to' => $applies, 'weekly_off' => $scOff,
                'weekly_off_nth' => self::cleanNth((array) ($sc['weekly_off_nth'] ?? []), $scOff)];
        }

        $clean = [];
        $seen = [];

        foreach (array_values($holidays) as $i => $h) {
            $on = trim((string) ($h['on'] ?? ''));
            $name = trim((string) ($h['name'] ?? ''));

            if ($on === '' && $name === '') {
                continue;
            }

            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);

            if ($d === false || $d->format('Y-m-d') !== $on) {
                return ['ok' => false, 'why' => 'Row ' . ($i + 1) . ": '{$on}' is not a date (Y-m-d)."];
            }

            if ($name === '') {
                return ['ok' => false, 'why' => "Row " . ($i + 1) . " ({$on}): a holiday needs a name — it is printed on the muster."];
            }

            // RECURRING: only the month and day are kept meaningful — the year
            // picked in the date box is thrown away at lookup time (materialize()
            // below), so "1 Jan 2026" and "1 Jan 2027" are the same declaration.
            // Two recurring holidays cannot share a month-day any more than two
            // dated ones can share a date.
            $recurring = in_array(strtolower((string) ($h['recurring'] ?? '')), ['1', 'yes', 'true', 'on'], true);
            $dupKey = $recurring ? 'recurring:' . substr($on, 5) : $on;

            if (isset($seen[$dupKey])) {
                return ['ok' => false, 'why' => 'Row ' . ($i + 1) . ': '
                    . ($recurring ? 'another holiday already repeats on ' . substr($on, 5) : "{$on} is listed twice") . '.'];
            }

            $seen[$dupKey] = true;
            $type = trim((string) ($h['type'] ?? ''));
            $types = $this->holidayTypes();

            if ($type !== '' && !in_array($type, $types, true)) {
                return ['ok' => false, 'why' => "Row " . ($i + 1) . " ({$on}): '{$type}' is not a declared holiday type (" . implode(', ', $types) . ').'];
            }

            $row = ['on' => $on, 'name' => $name, 'type' => $type !== '' ? $type : ($types[0] ?? '')];

            if ($recurring) {
                $row['recurring'] = true;
            }

            $scope = self::cleanScope($h);

            if ($scope !== null) {
                $row['scope'] = $scope;
            }

            $clean[] = $row;
        }

        usort($clean, static fn (array $a, array $b): int => strcmp(
            ($a['recurring'] ?? false) ? '0000-' . substr($a['on'], 5) : $a['on'],
            ($b['recurring'] ?? false) ? '0000-' . substr($b['on'], 5) : $b['on'],
        ));
        $this->config = null;

        return $this->customisation->saveRaw(self::KEY, ['weekly_off' => $off, 'weekly_off_nth' => $nth, 'woff_schedules' => $woff, 'holidays' => $clean], $byId, $byLabel, 'Holiday / W.OFF schedule');
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "The calendar at {$this->file} is missing or unreadable. It is refused rather than "
                . 'defaulted to '
                . 'a Monday-to-Friday week, because a six-day week silently paid as a five-day one '
                . 'is a wrong payslip that looks right.'
            );
        }

        $basis = (string) ($decoded['proration_basis'] ?? '');

        if (!in_array($basis, [self::CALENDAR_DAYS, self::WORKING_DAYS], true)) {
            throw new \RuntimeException(
                "The calendar does not declare a proration basis ('{$basis}'). It must say "
                . 'calendar_days or working_days: the two give different payslips for the same month, '
                . 'and choosing one silently is a business decision made on the customer\'s behalf.'
            );
        }

        // THE TENANT'S OVERLAY: holidays and weekly offs only.
        $saved = $this->customisation?->live()[self::KEY] ?? null;

        if (is_array($saved)) {
            foreach (['holidays', 'weekly_off', 'weekly_off_nth', 'woff_schedules'] as $key) {
                if (is_array($saved[$key] ?? null)) {
                    $decoded[$key] = $saved[$key];
                }
            }
        }

        return $this->config = $decoded;
    }

    public function shiftStart(): string
    {
        return (string) ($this->config()['shift']['starts'] ?? '09:00');
    }

    public function shiftEnd(): string
    {
        return (string) ($this->config()['shift']['ends'] ?? '18:00');
    }

    public function prorationBasis(): string
    {
        return (string) $this->config()['proration_basis'];
    }

    /**
     * Minutes of grace before an arrival counts as late.
     *
     * It was `graceMinutes: 5` written into `Attendance::settledDays()` — a
     * policy number in an argument list, where it looked like a default and was
     * in fact the rule. In most Indian attendance policies the late mark is the
     * first step towards a day of loss of pay, so five minutes is a decision
     * somebody makes deliberately and changes without waiting for a release.
     *
     * Null when the file does not say, so the caller applies a NAMED default
     * rather than this class inventing one: a calendar that silently answers
     * five is indistinguishable from a calendar that was asked and had five.
     */
    public function graceMinutes(): ?int
    {
        $declared = $this->config()['grace_minutes'] ?? null;

        return is_int($declared) ? $declared : null;
    }

    /**
     * @return array<string, string> date => holiday name, tenant-wide and
     * unscoped — every holiday regardless of which company/branch/role/
     * designation it is narrowed to. This is what the admin screen shows and
     * edits; a specific employee's list is `holidaysForEmployment()`.
     *
     * ponytail: a recurring holiday is materialized for last year through five
     * years out when $forYear is not given — wide enough for any attendance,
     * leave or payroll span this system walks in one call. Widen the window
     * here if a caller ever genuinely needs further out; nothing else changes.
     */
    public function holidays(?int $forYear = null): array
    {
        return $this->materialize((array) ($this->config()['holidays'] ?? []), $forYear);
    }

    /**
     * Only the holidays that reach THIS employment — same shape as
     * `holidays()`. The weekly off has its own scoping — extra W.OFF schedules,
     * see scheduleFor().
     *
     * @param array<string, string> $companyNames company id => name
     * @param array<string, string> $designationRoles designation id => role name
     * @return array<string, string> date => holiday name
     */
    public function holidaysForEmployment(array $employment, array $companyNames, array $designationRoles, ?int $forYear = null): array
    {
        $rows = array_values(array_filter((array) ($this->config()['holidays'] ?? []),
            static fn (array $h): bool => self::holidayMatches($h, $employment, $companyNames, $designationRoles)));

        return $this->materialize($rows, $forYear);
    }

    /** @param list<array<string, mixed>> $rows */
    private function materialize(array $rows, ?int $forYear): array
    {
        $out = [];
        $years = $forYear !== null ? [$forYear] : range((int) date('Y') - 1, (int) date('Y') + 5);

        foreach ($rows as $holiday) {
            $on = (string) ($holiday['on'] ?? '');
            $name = (string) ($holiday['name'] ?? '');

            if (!($holiday['recurring'] ?? false)) {
                if ($forYear === null || str_starts_with($on, (string) $forYear)) {
                    $out[$on] = $name;
                }

                continue;
            }

            $monthDay = substr($on, 5);

            foreach ($years as $y) {
                $out[sprintf('%d-%s', $y, $monthDay)] = $name;
            }
        }

        return $out;
    }

    /**
     * Does this employment fall inside a holiday's scope?
     *
     * No `scope` key at all reaches everybody — a holiday nobody has narrowed,
     * shipped or saved before this feature existed. Once a holiday DOES carry
     * a scope, every named dimension must match and a dimension with nothing
     * checked (not even "Any") matches nobody, so the whole holiday matches
     * nobody — the owner's own rule, applied literally rather than softened
     * into a wildcard.
     *
     * @param array<string, mixed> $holiday
     * @param array<string, string> $companyNames company id => name
     * @param array<string, string> $designationRoles designation id => role name
     */
    public static function holidayMatches(array $holiday, array $employment, array $companyNames, array $designationRoles): bool
    {
        if (!isset($holiday['scope']) || !is_array($holiday['scope'])) {
            return true;
        }

        foreach (self::HOLIDAY_SCOPES as $dim) {
            $checked = array_values(array_unique(array_map('strval', (array) ($holiday['scope'][$dim] ?? []))));

            if (in_array(self::ANY, $checked, true)) {
                continue;
            }

            if ($checked === []) {
                return false;
            }

            // ROLE COMES FROM THE EMPLOYEE'S DESIGNATION — Role/Designation
            // Setup's own Role column — never `employment['role']`, which is
            // the administrator/manager/viewer access tier Authorisation
            // enforces. The same mistake `orgValueOf()` in app.php already
            // guards against, made here too because a holiday scoped by "Role"
            // is a job role, not a login permission.
            $have = match ($dim) {
                'company' => (string) ($companyNames[(string) ($employment['employer_company_id'] ?? '')] ?? ($employment['company'] ?? '')),
                'role' => (string) ($designationRoles[(string) ($employment['designation_id'] ?? '')] ?? ''),
                default => (string) ($employment[$dim] ?? ''),
            };

            $found = false;

            foreach ($checked as $want) {
                if (strcasecmp(trim($have), trim($want)) === 0) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, list<string>>|null null when this row's scope was
     * never presented for editing — a save from before this feature, kept
     * unrestricted rather than read as "everything left blank".
     */
    private static function cleanScope(array $h): ?array
    {
        if (!in_array(strtolower((string) ($h['scope_submitted'] ?? '')), ['1', 'yes', 'true', 'on'], true)) {
            return null;
        }

        $scope = [];

        foreach (self::HOLIDAY_SCOPES as $dim) {
            $scope[$dim] = array_values(array_unique(array_map('strval', (array) ($h['scope'][$dim] ?? []))));
        }

        return $scope;
    }

    /** @return array<string, list<int>> weekday => occurrences 1..5, days already off every week dropped */
    private static function cleanNth(array $weeklyOffNth, array $off): array
    {
        $nth = [];

        foreach ($weeklyOffNth as $day => $weeks) {
            $day = ucfirst(strtolower(substr(trim((string) $day), 0, 3)));
            $weeks = array_values(array_unique(array_filter(array_map('intval', (array) $weeks), static fn (int $w): bool => $w >= 1 && $w <= 5)));
            sort($weeks);

            if (in_array($day, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], true) && $weeks !== [] && !in_array($day, $off, true)) {
                $nth[$day] = $weeks;
            }
        }

        return $nth;
    }

    /** @return list<array{name: string, applies_to: array<string, string>, weekly_off: list<string>, weekly_off_nth: array<string, list<int>>}> the extra W.OFF schedules, in order */
    public function woffSchedules(): array
    {
        return array_values(array_filter((array) ($this->config()['woff_schedules'] ?? []), 'is_array'));
    }

    /**
     * THE W.OFF SCHEDULE THIS EMPLOYEE FOLLOWS: the most specific extra schedule
     * whose Applies-to fields all match (a named employee beats everything, then
     * the one narrowing by more fields; a tie goes to the lower-numbered one) —
     * else the default. No employee given = the default, exactly as before.
     *
     * @param array<string, mixed>|null $employment
     * @param array<string, string> $designationRoles designation id => role name
     * @return array{name: string, applies_to: array<string, string>, weekly_off: list<string>, weekly_off_nth: array<string, list<int>>}
     */
    public function scheduleFor(?array $employment, array $designationRoles = []): array
    {
        $best = ['name' => 'Default', 'applies_to' => [],
            'weekly_off' => array_map('strval', (array) ($this->config()['weekly_off'] ?? [])),
            'weekly_off_nth' => (array) ($this->config()['weekly_off_nth'] ?? [])];

        if ($employment === null || $employment === []) {
            return $best;
        }

        $bestRank = -1;

        foreach ($this->woffSchedules() as $sc) {
            $applies = (array) ($sc['applies_to'] ?? []);

            if ($applies === [] || !self::woffMatches($applies, $employment, $designationRoles)) {
                continue;
            }

            $rank = (isset($applies['employee']) ? 1000 : 0) + count($applies);

            if ($rank > $bestRank) {
                [$best, $bestRank] = [$sc, $rank];
            }
        }

        return $best;
    }

    /** Same field reading as LatePolicy::matches(): ids or names, Role from the designation. */
    private static function woffMatches(array $applies, array $e, array $designationRoles): bool
    {
        foreach ($applies as $k => $want) {
            $have = match ($k) {
                'company' => [(string) ($e['employer_company_id'] ?? '')],
                'branch' => [(string) ($e['branch_id'] ?? ''), (string) ($e['branch'] ?? '')],
                'department' => [(string) ($e['department_id'] ?? ''), (string) ($e['department'] ?? '')],
                'designation' => [(string) ($e['designation_id'] ?? ''), (string) ($e['designation'] ?? '')],
                'team' => [(string) ($e['team_id'] ?? ''), (string) ($e['team'] ?? '')],
                'role' => [(string) ($designationRoles[(string) ($e['designation_id'] ?? '')] ?? '')],
                'employee' => [Employment::code($e)],
                'staff_type' => [(string) ($e['type'] ?? '')],
                'employee_type' => [(string) ($e['employment_type'] ?? '')],
                default => [],
            };
            $hit = false;

            foreach ($have as $h) {
                $hit = $hit || ($h !== '' && strcasecmp(trim($h), trim((string) $want)) === 0);
            }

            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> three-letter day names that are weekly offs (the default schedule) */
    public function weeklyOff(): array
    {
        return array_map('strval', (array) ($this->config()['weekly_off'] ?? []));
    }

    /** Why this date is not a working day — or null when it is one. */
    public function nonWorkingReason(string $date, ?array $employment = null, array $designationRoles = []): ?string
    {
        $holidays = $this->holidays();

        // The holiday is named FIRST. "It was a holiday" is the answer somebody
        // wants; "it was a Saturday" is true of the same day and less useful.
        if (isset($holidays[$date])) {
            return $holidays[$date];
        }

        return $this->isWeeklyOff($date, $employment, $designationRoles) ? 'weekly off (' . (new \DateTimeImmutable($date))->format('D') . ')' : null;
    }

    /**
     * Is this date a weekly off — every week (`weekly_off`) or on particular
     * occurrences of the weekday (`weekly_off_nth`, e.g. Sat on the 1st and 4th
     * week: {"Sat": [1, 4]})? "Every Sun + 1st and 4th Sat" is the commonest
     * Indian office pattern and the one a yes/no per day cannot express.
     */
    public function isWeeklyOff(string $date, ?array $employment = null, array $designationRoles = []): bool
    {
        $d = new \DateTimeImmutable($date);
        $day = $d->format('D');
        $sc = $this->scheduleFor($employment, $designationRoles);

        if (in_array($day, array_map('strval', (array) $sc['weekly_off']), true)) {
            return true;
        }

        $nth = intdiv((int) $d->format('j') - 1, 7) + 1; // 1..5: which occurrence of this weekday in the month

        return in_array($nth, array_map('intval', (array) ($sc['weekly_off_nth'][$day] ?? [])), true);
    }

    /** @return array<string, list<int>> weekday => occurrences (1..5) that are off */
    public function weeklyOffNth(): array
    {
        $out = [];

        foreach ((array) ($this->config()['weekly_off_nth'] ?? []) as $day => $weeks) {
            $out[(string) $day] = array_values(array_map('intval', (array) $weeks));
        }

        return $out;
    }

    /** The pattern in words: "Every Sun + 1st and 4th Sat" — the default schedule, or the one given. */
    public function weeklyOffLabel(?array $schedule = null): string
    {
        $ord = static fn (int $n): string => $n . (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
        $every = $schedule === null ? $this->weeklyOff() : array_map('strval', (array) ($schedule['weekly_off'] ?? []));
        $parts = array_map(static fn (string $d): string => 'Every ' . $d, $every);

        foreach ($schedule === null ? $this->weeklyOffNth() : (array) ($schedule['weekly_off_nth'] ?? []) as $day => $weeks) {
            $weeks = array_map('intval', (array) $weeks);

            if ($weeks !== [] && !in_array($day, $every, true)) {
                $parts[] = implode(' and ', array_map($ord, $weeks)) . ' ' . $day;
            }
        }

        return $parts === [] ? 'No weekly off' : implode(' + ', $parts);
    }

    public function isWorkingDay(string $date, ?array $employment = null, array $designationRoles = []): bool
    {
        return $this->nonWorkingReason($date, $employment, $designationRoles) === null;
    }

    /** Working days in an inclusive range. A holiday on a weekly off counts once. */
    public function workingDaysBetween(string $from, string $to, ?array $employment = null, array $designationRoles = []): int
    {
        $count = 0;
        $day = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);

        while ($day <= $end) {
            if ($this->isWorkingDay($day->format('Y-m-d'), $employment, $designationRoles)) {
                $count++;
            }

            $day = $day->modify('+1 day');
        }

        return $count;
    }

    /** The denominator payroll prorates over, and the reason it is that number. */
    public function prorationDays(string $from, string $to, ?array $employment = null, array $designationRoles = []): array
    {
        $calendarDays = (int) (new \DateTimeImmutable($from))->diff(
            new \DateTimeImmutable($to))->days + 1;
        $workingDays = $this->workingDaysBetween($from, $to, $employment, $designationRoles);

        return $this->prorationBasis() === self::WORKING_DAYS
            ? ['days' => $workingDays, 'why' => "{$workingDays} working days, because the calendar "
                . 'declares proration over working days — weekly offs and holidays are excluded from '
                . 'the denominator, so a day of absence costs more than it would over calendar days']
            : ['days' => $calendarDays, 'why' => "{$calendarDays} calendar days, because the calendar "
                . 'declares proration over calendar days — weekly offs and holidays are inside the '
                . 'denominator and are paid, so a day of absence costs less than it would over '
                . 'working days'];
    }
}
