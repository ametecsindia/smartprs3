<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * THE ARITHMETIC BEHIND THE STATUTORY & COMPLIANCE SCREENS — gratuity, statutory
 * bonus, per-head totals read out of closed payroll runs, and the due-date
 * register. Every rate, ceiling, factor and due day arrives as a config array;
 * nothing statutory is typed into this file. Every result carries a `working`
 * string a person can check by hand (law 4).
 *
 * ponytail: static and pure — the screens hand in the config, the rows and the
 * date, and tests do the same without a store.
 */
final class StatutoryReports
{
    /** Read one of the config files, refusing a missing one loudly. */
    public static function config(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!is_array($decoded)) {
            throw new \RuntimeException("The statutory configuration at {$file} is missing or unreadable; the screen "
                . 'refuses rather than computing against nothing.');
        }

        return $decoded;
    }

    // ---- service and dates ------------------------------------------------------

    /** Whole months of service from $doj to $asOf (0 when the joining date is missing or later). */
    public static function serviceMonths(string $doj, string $asOf): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $doj) !== 1 || strcmp($doj, $asOf) > 0) {
            return 0;
        }

        $from = new \DateTimeImmutable($doj);
        $to = new \DateTimeImmutable($asOf);
        $diff = $from->diff($to);

        return $diff->y * 12 + $diff->m;
    }

    /** "2026-27" for a YYYY-MM period, with the financial year starting in $startMonth. */
    public static function financialYear(string $period, int $startMonth): string
    {
        $year = (int) substr($period, 0, 4);
        $month = (int) substr($period, 5, 2);
        $first = $month >= $startMonth ? $year : $year - 1;

        return $first . '-' . substr((string) ($first + 1), 2);
    }

    // ---- gratuity -----------------------------------------------------------------

    /**
     * SERVICE TO THE DAY: completed years, months, days from the joining date to $asOf.
     *
     * @return array{y: int, m: int, d: int, text: string}
     */
    public static function service(string $doj, string $asOf): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $doj) !== 1 || strcmp($doj, $asOf) > 0) {
            return ['y' => 0, 'm' => 0, 'd' => 0, 'text' => '0y 0m 0d'];
        }

        $diff = (new \DateTimeImmutable($doj))->diff(new \DateTimeImmutable($asOf));

        return ['y' => $diff->y, 'm' => $diff->m, 'd' => $diff->d, 'text' => sprintf('%dy %dm %dd', $diff->y, $diff->m, $diff->d)];
    }

    /**
     * GRATUITY FOR ONE EMPLOYEE — Code on Social Security 2020 s.53, every rule from config/gratuity.json.
     *
     *  - Eligibility: FULL years of continuous service >= eligibility_years (no rounding); fixed-term
     *    employment types after fixed_term.eligibility_years; death / disablement with no minimum.
     *  - Amount: wages last drawn (monthly rate) x 15/26 x counted years, where a part year counts only
     *    when it is MORE than part_year_more_than_months; capped at statutory_cap.
     *
     * @param array<string, mixed> $cfg config/gratuity.json
     * @return array{eligible: bool, months: int, years: int, amount: Money, capped: bool, working: string, service: string}
     */
    public static function gratuity(array $cfg, Money $wage, string $doj, string $asOf, string $employmentType = '', string $exitReason = ''): array
    {
        $sv = self::service($doj, $asOf);
        $partMonths = (int) ($cfg['part_year_more_than_months'] ?? 6);
        $excess = $sv['m'] > $partMonths || ($sv['m'] === $partMonths && $sv['d'] > 0);
        $counted = $sv['y'] + ($excess ? 1 : 0);
        $fixedTypes = array_map('strtolower', (array) ($cfg['fixed_term']['employment_types'] ?? []));
        $noMinimum = array_map('strtolower', (array) ($cfg['no_minimum_service_exit_reasons'] ?? []));
        $isFixed = in_array(strtolower(trim($employmentType)), $fixedTypes, true);
        $isNoMin = in_array(strtolower(trim($exitReason)), $noMinimum, true);
        $need = $isFixed ? (int) ($cfg['fixed_term']['eligibility_years'] ?? 1) : (int) $cfg['eligibility_years'];
        // The 240-day reading, only when the employer has switched it on.
        $fifthYear = ($cfg['count_240_days_in_final_year'] ?? false) === true && !$isFixed && $sv['y'] === $need - 1
            && $doj !== '' && (new \DateTimeImmutable($doj))->modify('+' . $sv['y'] . ' years')->diff(new \DateTimeImmutable($asOf))->days >= 240;
        $eligible = $doj !== '' && ($isNoMin || $sv['y'] >= $need || $fifthYear);

        $working = sprintf('Service %s since %s; ', $sv['text'], $doj === '' ? '(no joining date)' : $doj)
            . ($isNoMin ? 'exit by ' . $exitReason . ' — no minimum service; '
                : ($isFixed ? 'fixed-term — eligible after ' . $need . ' year(s); ' : 'eligible after ' . $need . ' full years of continuous service; '))
            . ($fifthYear ? '4 years + 240 days in the fifth year counted as five (employer setting); ' : '');

        if (!$eligible) {
            return ['eligible' => false, 'months' => $sv['y'] * 12 + $sv['m'], 'years' => $counted, 'amount' => Money::zero(), 'capped' => false,
                'service' => $sv['text'], 'working' => $working . 'not eligible yet, nothing payable'];
        }

        $factor = $cfg['days_factor'];
        $amount = $wage->multipliedBy(Rational::of((int) $factor['numerator'] * $counted, (int) $factor['denominator']), RoundingMode::HalfUp);
        $cap = Money::of((string) $cfg['statutory_cap']);
        $capped = $amount->compareTo($cap) > 0;
        $working .= sprintf('%d completed year(s)%s → %d counted; %s × %d/%d × %d = %s', $sv['y'],
            $sv['m'] + $sv['d'] > 0 ? sprintf(' + %dm %dd (%s %d months, so %s)', $sv['m'], $sv['d'], $excess ? 'more than' : 'not more than', $partMonths, $excess ? 'counted as a year' : 'not counted') : '',
            $counted, $wage->format(), (int) $factor['numerator'], (int) $factor['denominator'], $counted, $amount->format());

        if ($capped) {
            $working .= ', above the notified maximum of ' . $cap->format() . ' so capped';
            $amount = $cap;
        }

        return ['eligible' => true, 'months' => $sv['y'] * 12 + $sv['m'], 'years' => $counted, 'amount' => $amount, 'capped' => $capped,
            'service' => $sv['text'], 'working' => $working];
    }

    /**
     * WAGES LAST DRAWN for gratuity — the monthly RATE of Basic + DA + retaining allowance from the newest
     * payroll run, with the Code on Wages 50% add-back. A month with loss-of-pay days is scaled up to the
     * full month (period days / payable days), because the law reads the rate, not what one short month paid.
     *
     * @param list<array<string, mixed>> $runs Register::runs(), newest first
     * @param array<string, mixed> $cfg config/gratuity.json
     * @return array{wage: Money, basis: string}
     */
    public static function lastDrawnWages(string $code, Money $monthlyGross, array $runs, array $version, array $cfg, string $asOf): array
    {
        $bare = static fn (string $c): string => (string) preg_replace('/^[A-Z]-/', '', $c);
        $wageCodes = array_map('strtoupper', (array) ($cfg['wage_components'] ?? []));
        $notRem = array_map('strtoupper', (array) ($cfg['not_remuneration'] ?? []));

        foreach ($runs as $run) {
            $row = $run['rows'][$code] ?? null;

            if (!is_array($row) || (string) ($run['period'] ?? '') > substr($asOf, 0, 7)) {
                continue;
            }

            $period = Rational::fromDecimalString((string) ($row['period_days'] ?? '0'));
            $payable = Rational::fromDecimalString((string) ($row['payable_days'] ?? '0'));
            $scale = ($cfg['rate_not_amount'] ?? true) && $payable->compareTo(Rational::of(0)) > 0 && $payable->compareTo($period) < 0
                ? $period->dividedBy($payable) : Rational::of(1);
            $wageMinor = 0;
            $totalMinor = 0;
            $names = [];

            foreach ((array) ($row['lines'] ?? []) as $line) {
                if ((string) ($line['kind'] ?? '') !== 'earning') {
                    continue;
                }

                $c = strtoupper($bare((string) ($line['code'] ?? '')));

                if (in_array($c, $notRem, true)) {
                    continue;
                }

                $minor = (int) ($line['minor'] ?? 0);
                $totalMinor += $minor;

                if (in_array($c, $wageCodes, true)) {
                    $wageMinor += $minor;
                    $names[$c] = true;
                }
            }

            $wage = Money::ofMinor($wageMinor)->multipliedBy($scale, RoundingMode::HalfUp);
            $total = Money::ofMinor($totalMinor)->multipliedBy($scale, RoundingMode::HalfUp);
            $basis = 'run ' . (string) $run['period'] . ': ' . (implode('+', array_keys($names)) ?: 'no wage line') . ' ' . $wage->format()
                . ($scale->compareTo(Rational::of(1)) !== 0 ? ' (monthly rate: paid ' . $payable->toDecimalString() . ' of ' . $period->toDecimalString() . ' days, scaled up)' : '');
            $rule = (array) ($cfg['fifty_percent_rule'] ?? []);

            if ($rule !== [] && $asOf >= (string) ($rule['from'] ?? '9999-12-31') && $total->minor > 0) {
                $limit = $total->multipliedBy(Rational::of((int) $rule['percent'], 100), RoundingMode::HalfUp);
                $excluded = $total->minus($wage);

                if ($excluded->compareTo($limit) > 0) {
                    $add = $excluded->minus($limit);
                    $wage = $wage->plus($add);
                    $basis .= '; excluded allowances ' . $excluded->format() . ' exceed ' . (int) $rule['percent'] . '% of remuneration '
                        . $total->format() . ', so ' . $add->format() . ' added back (Code on Wages s.2(y)) = ' . $wage->format();
                } else {
                    $basis .= '; excluded allowances ' . $excluded->format() . ' within ' . (int) $rule['percent'] . '% of ' . $total->format() . ', nothing added back';
                }
            }

            return ['wage' => $wage, 'basis' => $basis];
        }

        $fallback = self::lastDrawnBasic($code, $monthlyGross, [], $version, (string) ($cfg['basic_flag'] ?? 'pf_base'));

        return ['wage' => $fallback['basic'], 'basis' => 'no payroll run yet — ' . $fallback['basis']];
    }

    /**
     * Component codes carrying a flag in a structure version.
     *
     * @param array<string, mixed> $version Structure::versionOn()
     * @return list<string>
     */
    public static function flaggedCodes(array $version, string $flag): array
    {
        $codes = [];

        foreach ((array) ($version['components'] ?? []) as $c) {
            if (($c['flags'][$flag] ?? false) === true) {
                $codes[] = (string) $c['code'];
            }
        }

        return $codes;
    }

    /**
     * The sum of a run row's lines whose code is in $codes, in paise.
     *
     * @param array<string, mixed> $row one entry of a run's `rows`
     * @param list<string> $codes
     */
    public static function linesMinor(array $row, array $codes): int
    {
        $minor = 0;
        // MATCHED ON THE BARE CODE (15 Oct): the structure names a component C-BASIC, a Salary Structure
        // rule writes the same line as BASIC, and a statutory line may be D-PT or PT. Gratuity read the
        // flagged C-BASIC against BASIC lines and found ₹0 for everybody.
        $bare = static fn (string $c): string => (string) preg_replace('/^[A-Z]-/', '', $c);
        $wanted = array_map($bare, $codes);

        foreach ((array) ($row['lines'] ?? []) as $line) {
            if (in_array($bare((string) ($line['code'] ?? '')), $wanted, true)) {
                $minor += (int) ($line['minor'] ?? 0);
            }
        }

        return $minor;
    }

    /**
     * THE LAST DRAWN BASIC — from the newest closed run that has a row for the
     * employee; otherwise derived from the structure, and the basis is named.
     *
     * @param list<array<string, mixed>> $runs Register::runs(), newest first
     * @param array<string, mixed> $version the structure version in force
     * @return array{basic: Money, basis: string}
     */
    public static function lastDrawnBasic(string $code, Money $monthlyGross, array $runs, array $version, string $flag): array
    {
        $codes = self::flaggedCodes($version, $flag);

        foreach ($runs as $run) {
            if (isset($run['rows'][$code]) && $codes !== []) {
                return ['basic' => Money::ofMinor(self::linesMinor($run['rows'][$code], $codes)),
                    'basis' => 'closed run ' . (string) $run['period'] . ', lines ' . implode('+', $codes)];
            }
        }

        // No run: the flagged percent-of-gross components applied to the month's gross.
        $percent = Rational::of(0);
        $named = [];

        foreach ((array) ($version['components'] ?? []) as $c) {
            if (in_array((string) $c['code'], $codes, true) && ($c['basis'] ?? '') === 'percent_of_gross') {
                $percent = $percent->plus(Rational::fromDecimalString((string) $c['rate_percent']));
                $named[] = (string) $c['code'] . ' ' . (string) $c['rate_percent'] . '%';
            }
        }

        if ($named !== []) {
            return ['basic' => $monthlyGross->multipliedBy($percent->dividedBy(Rational::of(100)), RoundingMode::HalfUp),
                'basis' => 'no closed run; structure ' . implode('+', $named) . ' of monthly gross'];
        }

        return ['basic' => $monthlyGross, 'basis' => 'no closed run and no component flagged ' . $flag
            . '; the whole monthly gross is used'];
    }

    // ---- statutory bonus ------------------------------------------------------------

    /**
     * Statutory bonus for one employee over the months given.
     *
     * @param array<string, mixed> $cfg config/statutory-bonus.json
     * @param array<string, Money> $monthlyWages period => bonus wage that month
     * @return array{months: list<array{period: string, wage: Money, eligible: bool, counted: Money}>,
     *     eligible_months: int, base: Money, at_min: Money, at_max: Money, working: string}
     */
    public static function bonus(array $cfg, array $monthlyWages): array
    {
        $eligibility = Money::of((string) $cfg['eligibility_wage_ceiling']);
        $ceiling = Money::of((string) $cfg['calculation_wage_ceiling']);
        $months = [];
        $base = Money::zero();
        $eligibleMonths = 0;

        foreach ($monthlyWages as $period => $wage) {
            $eligible = !$wage->isZero() && $wage->compareTo($eligibility) <= 0;
            $counted = $eligible ? ($wage->compareTo($ceiling) > 0 ? $ceiling : $wage) : Money::zero();
            $base = $base->plus($counted);
            $eligibleMonths += $eligible ? 1 : 0;
            $months[] = ['period' => (string) $period, 'wage' => $wage, 'eligible' => $eligible, 'counted' => $counted];
        }

        $pct = static fn (string $p): Rational => Rational::fromDecimalString($p)->dividedBy(Rational::of(100));
        $atMin = $base->multipliedBy($pct((string) $cfg['min_percent']), RoundingMode::HalfUp);
        $atMax = $base->multipliedBy($pct((string) $cfg['max_percent']), RoundingMode::HalfUp);

        return ['months' => $months, 'eligible_months' => $eligibleMonths, 'base' => $base,
            'at_min' => $atMin, 'at_max' => $atMax,
            'working' => sprintf('%d of %d month(s) eligible (bonus wage ≤ %s); wage counted up to %s a month = %s; × %s%% = %s, × %s%% = %s',
                $eligibleMonths, count($monthlyWages), $eligibility->format(), $ceiling->format(), $base->format(),
                (string) $cfg['min_percent'], $atMin->format(), (string) $cfg['max_percent'], $atMax->format())];
    }

    // ---- per-head totals from closed runs ---------------------------------------------

    /**
     * Per employee code, per period, the paise of the lines in $codes.
     *
     * @param list<array<string, mixed>> $runs Register::runs()
     * @param list<string> $codes
     * @return array<string, array<string, int>> code => period => minor
     */
    public static function byEmployment(array $runs, array $codes, ?string $fromPeriod = null, ?string $toPeriod = null): array
    {
        $out = [];

        foreach ($runs as $run) {
            $period = (string) $run['period'];

            if (($fromPeriod !== null && strcmp($period, $fromPeriod) < 0) || ($toPeriod !== null && strcmp($period, $toPeriod) > 0)) {
                continue;
            }

            foreach ((array) $run['rows'] as $code => $row) {
                $out[(string) $code][$period] = self::linesMinor((array) $row, $codes);
            }
        }

        foreach ($out as &$periods) {
            ksort($periods);
        }

        return $out;
    }

    // ---- the due-date register ------------------------------------------------------------

    /**
     * The periods an obligation was or will be due for, newest last.
     *
     * @param array<string, mixed> $obligation one of compliance-calendar.json obligations
     * @return list<array{period: string, due: string}>
     */
    public static function periods(array $obligation, string $today, int $count): array
    {
        $out = [];
        $month = new \DateTimeImmutable(substr($today, 0, 7) . '-01');

        if (($obligation['frequency'] ?? 'monthly') === 'monthly') {
            for ($i = $count - 1; $i >= 0; $i--) {
                $p = $month->modify("-{$i} months");
                $d = $p->modify('+' . (int) ($obligation['months_after'] ?? 1) . ' months');
                $due = $d->setDate((int) $d->format('Y'), (int) $d->format('m'), (int) $obligation['due_day']);
                $out[] = ['period' => $p->format('Y-m'), 'due' => $due->format('Y-m-d')];
            }

            return $out;
        }

        // Quarterly: walk back month by month and collect each distinct quarter
        // met. A quarter's months never straddle a calendar year, so the year of
        // the month met is the quarter's year; the period is named by financial
        // year (opened by the first listed quarter's first month) and quarter code.
        $seen = [];
        $fyStart = (int) ((array) $obligation['quarters'])[0]['months'][0];

        for ($i = 0; count($out) < $count && $i < $count * 3 + 12; $i++) {
            $p = $month->modify("-{$i} months");
            $m = (int) $p->format('n');

            foreach ((array) $obligation['quarters'] as $q) {
                if (!in_array($m, (array) $q['months'], true)) {
                    continue;
                }

                $key = self::financialYear($p->format('Y-m'), $fyStart) . '-' . (string) $q['code'];

                if (isset($seen[$key])) {
                    continue 2;
                }

                $seen[$key] = true;
                $out[] = ['period' => $key,
                    'due' => ((int) $p->format('Y') + (int) ($q['due_year_offset'] ?? 0)) . '-' . (string) $q['due']];
            }
        }

        return array_reverse($out);
    }

    public const FILED = 'filed';
    public const OVERDUE = 'overdue';
    public const DUE = 'due';
    public const UPCOMING = 'upcoming';

    /** The state of one period: filed beats everything; otherwise the due date against today. */
    public static function filingState(string $due, ?string $filedOn, string $today, int $leadDays): string
    {
        if ($filedOn !== null && trim($filedOn) !== '') {
            return self::FILED;
        }

        return match (Compliance::state($due, $today, $leadDays)) {
            Compliance::LAPSED => self::OVERDUE,
            Compliance::DUE => self::DUE,
            default => self::UPCOMING,
        };
    }
}
