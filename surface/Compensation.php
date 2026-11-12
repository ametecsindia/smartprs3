<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * COMPENSATION & CLAIMS ARITHMETIC — the Commission Calculator and the loan EMI
 * schedule, in exact paise, each figure carrying the sentence that produced it.
 *
 * SmartPRS 2 computed these in the browser with floats (`schemeBaseCalc`,
 * `rqCommCalc`) and on the server with `round()`. Here a rate is a Rational, a
 * figure is Money, and every row returns its `working` so the screen can print
 * how the number came about (law 4).
 */
final class Compensation
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(private readonly RowStore $store, string $configPath)
    {
        $this->config = (array) json_decode((string) file_get_contents($configPath), true);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * The GROSS payout of one row under a scheme.
     *
     * @param array<string, mixed> $scheme an incentive_schemes row (rate_type, rate_value, slabs, threshold)
     * @param string $figure what was collected / achieved, as typed
     * @param string $target the target, when the scheme gates on achievement
     * @param string $manual the typed amount, for an `open` scheme
     * @return array{gross: Money, paid: bool, achievement: ?string, working: string}
     */
    public static function commission(array $scheme, string $figure, string $target = '', string $manual = ''): array
    {
        $collected = Amount::of($figure);
        $rateType = strtolower(trim((string) ($scheme['rate_type'] ?? 'open')));
        $threshold = trim((string) ($scheme['threshold'] ?? ''));
        $achievement = null;
        $pctR = null;
        $working = [];
        // THE SCHEME'S OWN TARGET (owner, 29 Oct): used when no target is typed for the person.
        trim($target) === '' && $target = trim((string) ($scheme['target_amount'] ?? ''));
        // TARGET-BASED COMMISSION SETUP (owner, 3 Nov 2026): the slab band is picked by ACHIEVEMENT % (achieved ÷ target),
        // and its % is paid on the target achieved. Incentive Setup keeps slabs by amount.
        $byAchievement = self::isTargetBased($scheme);

        if ($byAchievement && $threshold === '') {
            $goal = Amount::of($target === '' ? '0' : $target);

            if ($goal->isZero()) {
                return ['gross' => Money::zero(), 'paid' => false, 'achievement' => null,
                    'working' => 'target-based scheme with no target per person set — nothing paid rather than a target assumed'];
            }

            $pctR = Rational::of($collected->minor, $goal->minor)->multipliedBy(Rational::of(100));
            $achievement = $pctR->toDecimalString(2);
            $working[] = sprintf('achievement %s of %s = %s%%', $collected->format(), $goal->format(), $achievement);
        }

        if ($threshold !== '') {
            $goal = Amount::of($target);

            if ($goal->isZero()) {
                return ['gross' => Money::zero(), 'paid' => false, 'achievement' => null,
                    'working' => 'the scheme pays only above ' . self::rateText($threshold) . '% of target and no target was given — nothing paid rather than a gate assumed passed'];
            }

            $pct = Rational::of($collected->minor, $goal->minor)->multipliedBy(Rational::of(100));
            $pctR = $pct;
            $achievement = $pct->toDecimalString(2);
            $working[] = sprintf('achievement %s of %s = %s%%', $collected->format(), $goal->format(), $achievement);

            if ($pct->compareTo(Rational::fromDecimalString($threshold)) < 0) {
                $working[] = 'below the ' . self::rateText($threshold) . '% gate — nothing paid';

                return ['gross' => Money::zero(), 'paid' => false, 'achievement' => $achievement, 'working' => implode('; ', $working)];
            }

            $working[] = 'clears the ' . self::rateText($threshold) . '% gate';
        }

        // MINIMUM COLLECTION TO BE ELIGIBLE (owner, 29 Oct): below it nothing is paid.
        $min = trim((string) ($scheme['min_collected'] ?? ''));
        // A TARGET-BASED COMMISSION SETUP SCHEME HAS ONE MINIMUM — the achievement % above (owner, 3 Nov 2026); a ₹ minimum
        // saved on it earlier is not applied.
        if ($rateType !== 'open' && !$byAchievement && $min !== '' && !Amount::of($min)->isZero()) {
            if ($collected->compareTo(Amount::of($min)) < 0) {
                $working[] = sprintf('collected %s is below the minimum %s to be eligible — nothing paid', $collected->format(), Amount::of($min)->format());

                return ['gross' => Money::zero(), 'paid' => false, 'achievement' => $achievement, 'working' => implode('; ', $working)];
            }
            $working[] = 'meets the minimum ' . Amount::of($min)->format();
        }

        // PAID ON THE WHOLE AMOUNT, OR ONLY ON WHAT IS ABOVE THE TARGET / MINIMUM (owner, 29 Oct).
        if (strtolower(trim((string) ($scheme['pay_on'] ?? ''))) === 'excess' && in_array($rateType, ['percent', 'slab'], true)) {
            $above = ($threshold !== '' || $byAchievement) && trim($target) !== '' ? Amount::of($target) : Amount::of($min === '' ? '0' : $min);
            $over = $collected->compareTo($above) > 0 ? $collected->minus($above) : Money::zero();
            $working[] = sprintf('paid only on the amount above %s: %s − %s = %s', $above->format(), $collected->format(), $above->format(), $over->format());
            $collected = $over;
        }

        $rate = trim((string) ($scheme['rate_value'] ?? ''));

        switch ($rateType) {
            case 'percent':
                $gross = self::percentOf($collected, $rate);
                $working[] = sprintf('%s × %s%% = %s', $collected->format(), self::rateText($rate), $gross->format());
                break;
            case 'fixed':
                $gross = Amount::of($rate);
                $working[] = self::isFixedCommission($scheme) ? sprintf('fixed commission %s for the month', $gross->format()) : sprintf('fixed %s per row', $gross->format());
                break;
            case 'slab':
                if ($byAchievement && $pctR !== null) {
                    $band = self::bandForPercent($pctR, self::slabsOf($scheme));

                    if ($band === null) {
                        return ['gross' => Money::zero(), 'paid' => false, 'achievement' => $achievement,
                            'working' => implode('; ', [...$working, 'no slab band covers ' . $achievement . '% achievement — nothing paid rather than a rate guessed'])];
                    }

                    $gross = self::percentOf($collected, (string) $band['rate']);
                    $working[] = sprintf('%s%% achievement falls in the band %s at %s%% of %s = %s', $achievement,
                        trim((string) ($band['upto'] ?? '')) === '' ? 'above the last limit' : 'up to ' . self::rateText((string) $band['upto']) . '%',
                        self::rateText((string) $band['rate']), $collected->format(), $gross->format());
                    break;
                }

                $band = self::bandFor($collected, self::slabsOf($scheme));

                if ($band === null) {
                    return ['gross' => Money::zero(), 'paid' => false, 'achievement' => $achievement,
                        'working' => implode('; ', [...$working, 'no slab band covers ' . $collected->format() . ' — nothing paid rather than a rate guessed'])];
                }

                $gross = self::percentOf($collected, (string) $band['rate']);
                $working[] = sprintf('%s falls in the band %s at %s%% = %s', $collected->format(),
                    trim((string) ($band['upto'] ?? '')) === '' ? 'above the last limit' : 'up to ' . Amount::of($band['upto'])->format(),
                    self::rateText((string) $band['rate']), $gross->format());
                break;
            default:
                $gross = Amount::of($manual);
                $working[] = 'open scheme: the typed amount ' . $gross->format() . ' is the payout';
        }

        return ['gross' => $gross, 'paid' => !$gross->isZero(), 'achievement' => $achievement, 'working' => implode('; ', $working)];
    }

    /**
     * TDS u/s 194H off a gross, half-up to the paisa.
     *
     * @return array{tds: Money, net: Money, working: string}
     */
    public static function tds(Money $gross, string $rate): array
    {
        $rate = trim($rate) === '' ? '0' : trim($rate);
        $tds = self::percentOf($gross, $rate);
        $net = $gross->minus($tds);

        return ['tds' => $tds, 'net' => $net,
            'working' => sprintf('gross %s − TDS %s%% %s = net %s', $gross->format(), self::rateText($rate), $tds->format(), $net->format())];
    }

    /**
     * FLAT 194H OR INCOME-TAX SLABS (owner, 29 Oct 2026): an employee whose Salary Type is one of
     * `flat_tds_salary_types` (config/compensation.json — "Commission") has the flat TDS % of the
     * commission entry deducted; everyone else (Salary, Salary + Commission, blank) has NO flat TDS on
     * the entry — the commission joins their salary and the payroll's income-tax slabs tax it.
     *
     * @param ?array<string, mixed> $employee @param array<string, mixed> $rules the `commission` block
     */
    /**
     * THE TDS ON ONE COMMISSION / INCENTIVE PAYOUT — the single rule every screen and payroll use.
     *
     * A scheme set up on Commission Setup / Incentive Setup (owner, 30 Oct 2026; it has a `scheme_kind`) decides by
     * itself: "TDS deduction applicable" ticked → its Flat ₹ (never more than the gross) or its %, for whoever the
     * scheme pays; not ticked → no TDS on the payout (salaried staff are still taxed on the income-tax slabs in
     * payroll). No scheme, or an older one: Salary Type decides (29 Oct, flatTds()).
     *
     * @return array{tds: Money, net: Money, rate: string, on_entry: bool, working: string}
     */
    public static function schemeTds(Money $gross, ?array $scheme, ?array $employee, array $rules, string $typedRate = ''): array
    {
        if ($scheme !== null && trim((string) ($scheme['scheme_kind'] ?? '')) !== '') {
            $name = 'scheme "' . (string) ($scheme['name'] ?? '') . '"';
            if (!self::tdsApplicable($scheme)) {
                return ['tds' => Money::zero(), 'net' => $gross, 'rate' => '0', 'on_entry' => false,
                    'working' => $name . ': TDS not applicable — net = gross ' . $gross->format()];
            }
            if (strtolower(trim((string) ($scheme['tds_mode'] ?? ''))) === 'flat') {
                $flat = Amount::of(trim((string) ($scheme['tds_flat'] ?? '')) === '' ? '0' : (string) $scheme['tds_flat']);
                $tds = $flat->minor > $gross->minor ? $gross : $flat;
                $net = $gross->minus($tds);

                return ['tds' => $tds, 'net' => $net, 'rate' => '0', 'on_entry' => true,
                    'working' => sprintf('%s: gross %s − TDS (flat) %s = net %s', $name, $gross->format(), $tds->format(), $net->format())];
            }
            $rate = trim((string) ($scheme['tds_rate'] ?? '')) === '' ? '0' : trim((string) $scheme['tds_rate']);
            $t = self::tds($gross, $rate);

            return $t + ['rate' => self::rateText($rate), 'on_entry' => true, 'working' => $name . ': ' . $t['working']];
        }

        if (self::flatTds($employee, $rules)) {
            $rate = trim($typedRate);
            $rate === '' && $rate = trim((string) ($scheme['tds_rate'] ?? ''));
            $rate === '' && $rate = (string) ($rules['default_tds_rate'] ?? '0');
            $t = self::tds($gross, $rate);

            return $t + ['rate' => self::rateText($rate), 'on_entry' => true,
                'working' => 'Salary Type ' . (string) $employee['salary_type'] . ' — flat TDS u/s 194H: ' . $t['working']];
        }

        return ['tds' => Money::zero(), 'net' => $gross, 'rate' => '0', 'on_entry' => false,
            'working' => 'Salary Type ' . (trim((string) ($employee['salary_type'] ?? '')) ?: 'Salary')
                . ' — no flat TDS; the commission is taxed with the salary on the income-tax slabs in payroll'];
    }

    /**
     * WHAT A SCHEME'S OWN LIMITS ALLOW (owner, 2 Nov 2026: "everything selected here should map"): Valid From / Valid
     * Till (the payroll month must fall inside), Max claims per person and Max ₹ per person (counting this person's
     * other entries under the scheme that were not Rejected or Clawed back). Null = allowed; otherwise the reason.
     *
     * @param list<array<string, mixed>> $entries every commissions row (the one being edited is skipped by $selfId)
     */
    public static function limitsProblem(array $scheme, string $employeeId, string $month, Money $gross, array $entries, string $selfId = ''): ?string
    {
        $name = '"' . (string) ($scheme['name'] ?? '') . '"';
        $month = substr(trim($month), 0, 7);
        $from = substr(trim((string) ($scheme['valid_from'] ?? '')), 0, 7);
        $till = substr(trim((string) ($scheme['valid_till'] ?? '')), 0, 7);
        if ($month !== '' && (($from !== '' && $month < $from) || ($till !== '' && $month > $till))) {
            return "Scheme {$name} is valid " . ($from !== '' ? 'from ' . Dates::dmy((string) $scheme['valid_from']) : '')
                . ($till !== '' ? ' till ' . Dates::dmy((string) $scheme['valid_till']) : '') . " — payroll month {$month} is outside it.";
        }

        $maxClaims = (int) trim((string) ($scheme['max_claims'] ?? ''));
        $maxAmount = trim((string) ($scheme['max_amount'] ?? ''));
        if ($maxClaims <= 0 && ($maxAmount === '' || Amount::of($maxAmount)->minor <= 0)) {
            return null;
        }
        $count = 0;
        $sum = 0;
        foreach ($entries as $e) {
            if ((string) ($e['scheme_id'] ?? '') !== (string) ($scheme['id'] ?? '-') || (string) ($e['employee_id'] ?? '') !== $employeeId
                || ($selfId !== '' && (string) ($e['id'] ?? '') === $selfId)
                || in_array(strtolower(trim((string) ($e['status'] ?? ''))), ['rejected', 'clawed back'], true) || Modules::isRetired($e)) {
                continue;
            }
            $count++;
            $sum += Amount::of(trim((string) ($e['gross_amount'] ?? '')) ?: (trim((string) ($e['amount'] ?? '')) ?: '0'))->minor;
        }
        if ($maxClaims > 0 && $count >= $maxClaims) {
            return "Scheme {$name} allows {$maxClaims} claim(s) per person — this employee already has {$count}.";
        }
        if ($maxAmount !== '' && ($cap = Amount::of($maxAmount))->minor > 0 && $sum + $gross->minor > $cap->minor) {
            return "Scheme {$name} pays at most " . $cap->format() . ' per person — this employee already has ' . Money::ofMinor($sum)->format()
                . ', so ' . $gross->format() . ' more would cross it (' . Money::ofMinor(max(0, $cap->minor - $sum))->format() . ' left).';
        }

        return null;
    }

    /** "Clawback on bounce?" ticked on the scheme. */
    public static function clawbackAllowed(?array $scheme): bool
    {
        return in_array(strtolower(trim((string) ($scheme['clawback'] ?? ''))), ['1', 'yes', 'true', 'on'], true);
    }

    /** The scheme's "TDS deduction applicable" tick. */
    public static function tdsApplicable(?array $scheme): bool
    {
        return in_array(strtolower(trim((string) ($scheme['tds_applicable'] ?? ''))), ['1', 'yes', 'true', 'on'], true);
    }

    public static function flatTds(?array $employee, array $rules): bool
    {
        $types = array_map(static fn ($t): string => self::salaryType((string) $t), (array) ($rules['flat_tds_salary_types'] ?? []));

        return $employee !== null && in_array(self::salaryType((string) ($employee['salary_type'] ?? '')), $types, true);
    }

    /**
     * ONE COMMISSION ENTRY'S MONEY, WORKED OUT — never typed (owner, 29 Oct 2026).
     *
     *  - Gross: from the scheme when it pays percent / fixed / slab with no target gate (percent and
     *    slab need the Amount Collected); otherwise the gross typed.
     *  - TDS %: flat-TDS salary types only — the % typed on the entry, else the scheme's, else the
     *    default; anyone else 0 (taxed on slabs in payroll).
     *  - TDS = gross × TDS %, half-up; Net = Gross − TDS.
     *
     * @param array<string, mixed> $input the posted entry
     * @param ?array<string, mixed> $employee @param ?array<string, mixed> $scheme
     * @param array<string, mixed> $rules the `commission` block of config/compensation.json
     * @return array{gross_amount: string, tds_rate: string, tds_amount: string, amount: string, working: string}
     */
    public static function entry(array $input, ?array $employee, ?array $scheme, array $rules): array
    {
        $base = trim((string) ($input['base_amount'] ?? ''));
        $rateType = strtolower(trim((string) ($scheme['rate_type'] ?? '')));
        $working = [];

        if ($scheme !== null && (trim((string) ($scheme['threshold'] ?? '')) === '' || trim((string) ($scheme['target_amount'] ?? '')) !== '')
            && ($rateType === 'fixed' || (in_array($rateType, ['percent', 'slab'], true) && $base !== ''))) {
            $r = self::commission($scheme, $base === '' ? '0' : $base);
            $gross = $r['gross'];
            $working[] = 'scheme "' . (string) ($scheme['name'] ?? '') . '": ' . $r['working'];
        } else {
            $gross = Amount::of(trim((string) ($input['gross_amount'] ?? '')) === '' ? '0' : (string) $input['gross_amount']);
        }

        $t = self::schemeTds($gross, $scheme, $employee, $rules, (string) ($input['tds_rate'] ?? ''));
        $rate = $t['rate'];
        $working[] = $t['working'];

        return ['gross_amount' => $gross->toDecimalString(), 'tds_rate' => $rate,
            'tds_amount' => $t['tds']->toDecimalString(), 'amount' => $t['net']->toDecimalString(),
            'working' => implode('; ', $working)];
    }

    /**
     * WHO THE SCHEME IS FOR, BY SALARY TYPE (owner, 29 Oct: "eligible for incentive"): `eligible_salary_types` blank or
     * "All" = everyone; otherwise one Salary Type, (or an old pair saved before 3 Nov — see salaryTypesOf).
     *
     * @param array<string, mixed> $scheme @param array<string, mixed> $employee
     */
    public static function eligible(array $scheme, array $employee, array $designationRoles = []): bool
    {
        // WHO IT IS FOR (owner, 30 Oct 2026): every Applies-to field set on the scheme must match — blank = ALL.
        foreach (['employer_company_id', 'branch_id', 'department_id', 'team_id', 'designation_id'] as $col) {
            $w = trim((string) ($scheme[$col] ?? ''));
            if ($w !== '' && trim((string) ($employee[$col] ?? '')) !== $w) {
                return false;
            }
        }
        $wEmp = trim((string) ($scheme['employee_id'] ?? ''));
        if ($wEmp !== '' && trim((string) ($employee['id'] ?? '')) !== $wEmp) {
            return false;
        }
        $wRole = trim((string) ($scheme['scope_role'] ?? ''));
        if ($wRole !== '' && strcasecmp($wRole, trim((string) ($designationRoles[(string) ($employee['designation_id'] ?? '')] ?? ''))) !== 0) {
            return false;
        }

        $want = trim((string) ($scheme['eligible_salary_types'] ?? ''));
        if ($want === '' || strcasecmp($want, 'All') === 0) {
            return true;
        }

        return in_array(self::salaryType((string) ($employee['salary_type'] ?? '')), self::salaryTypesOf($want), true);
    }

    /**
     * ONE SPELLING OF A SALARY TYPE (owner, 3 Nov 2026: "Salary, Salary + Commission and Commission"): the employee field
     * offers exactly these; "Salary & Commission" saved before then reads as "Salary + Commission". Lower-cased for comparing.
     */
    public static function salaryType(string $type): string
    {
        $t = strtolower(preg_replace('/\s+/', ' ', trim($type)) ?? '');

        return $t === 'salary & commission' || $t === 'salary and commission' ? 'salary + commission' : $t;
    }

    /**
     * The Salary Types a scheme names: one of the three, or — saved before 3 Nov — an old pair ("Commission + Salary &
     * Commission", "Salary + Salary & Commission"), which keeps paying both as it did.
     *
     * @return list<string> lower-cased
     */
    public static function salaryTypesOf(string $want): array
    {
        $legacy = ['commission + salary & commission' => ['commission', 'salary + commission'], 'salary + salary & commission' => ['salary', 'salary + commission']];

        return $legacy[strtolower(trim($want))] ?? [self::salaryType($want)];
    }

    /**
     * A SEPARATE PAYOUT'S DATE FOR ONE ENTRY (owner, 29 Oct: a fixed calendar date on a scheme is stale next month):
     * the scheme's payout day in the entry's payroll month, or the month after (`payout_offset`, blank = after); a day
     * past the month's end is its last day. A scheme saved before this with only a Payout Date uses that date's day.
     * Null when the scheme is not a separate payout or names no day.
     *
     * @param array<string, mixed> $scheme
     */
    public static function payoutDate(array $scheme, string $month): ?string
    {
        if (!str_starts_with(strtolower(trim((string) ($scheme['payout_method'] ?? ''))), 'separate') || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }
        $day = (int) trim((string) ($scheme['payout_day'] ?? ''));
        $day === 0 && preg_match('/^\d{4}-\d{2}-(\d{2})/', (string) ($scheme['payout_date'] ?? ''), $m) === 1 && $day = (int) $m[1];
        if ($day < 1) {
            return null;
        }
        // "same" = the claim's own month; anything else (blank, "next") = the month after.
        $offset = strtolower(trim((string) ($scheme['payout_offset'] ?? ''))) === 'same' ? 0 : 1;
        $first = (new \DateTimeImmutable($month . '-01'))->modify('+' . $offset . ' month');

        return $first->format('Y-m-') . sprintf('%02d', min($day, (int) $first->format('t')));
    }

    /** A rate as typed, not as DECIMAL(18,4) hands it back: "2", not "2.0000". */
    public static function rateText(string $rate): string
    {
        return Rational::fromDecimalString(trim($rate) === '' ? '0' : trim($rate))->toDecimalString(4);
    }

    /** rate is a decimal percentage string, e.g. "2.5". */
    private static function percentOf(Money $amount, string $rate): Money
    {
        $rate = trim($rate) === '' ? '0' : trim($rate);

        return $amount->multipliedBy(Rational::fromDecimalString($rate)->dividedBy(Rational::of(100)), RoundingMode::HalfUp);
    }

    /**
     * The scheme's slab bands, ordered by limit, the open-ended band last.
     *
     * @param array<string, mixed> $scheme
     * @return list<array{upto: string, rate: string}>
     */
    public static function slabsOf(array $scheme): array
    {
        $raw = $scheme['slabs'] ?? [];

        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : (array) json_decode($raw, true);
        }

        $bands = [];

        foreach ((array) $raw as $band) {
            $band = (array) $band;

            if (trim((string) ($band['rate'] ?? '')) === '') {
                continue;
            }

            $bands[] = ['upto' => trim((string) ($band['upto'] ?? '')), 'rate' => trim((string) $band['rate'])];
        }

        usort($bands, static function (array $a, array $b): int {
            if ($a['upto'] === '' || $b['upto'] === '') {
                return $a['upto'] === '' ? ($b['upto'] === '' ? 0 : 1) : -1;
            }

            return Amount::of($a['upto'])->compareTo(Amount::of($b['upto']));
        });

        return $bands;
    }

    /**
     * The band an ACHIEVEMENT % falls in — bands' "Up to" read as percentages, compared exactly (no rounding).
     *
     * @param list<array{upto: string, rate: string}> $bands
     * @return ?array{upto: string, rate: string}
     */
    private static function bandForPercent(Rational $pct, array $bands): ?array
    {
        usort($bands, static function (array $a, array $b): int {
            if ($a['upto'] === '' || $b['upto'] === '') {
                return $a['upto'] === '' ? ($b['upto'] === '' ? 0 : 1) : -1;
            }

            return Rational::fromDecimalString($a['upto'])->compareTo(Rational::fromDecimalString($b['upto']));
        });

        foreach ($bands as $band) {
            if ($band['upto'] === '' || $pct->compareTo(Rational::fromDecimalString($band['upto'])) <= 0) {
                return $band;
            }
        }

        return null;
    }

    /** A Commission Setup scheme "Paid on: Fixed commission" (owner, 3 Nov 2026) — paid by itself every month. */
    public static function isFixedCommission(?array $scheme): bool
    {
        return $scheme !== null && strcasecmp(trim((string) ($scheme['scheme_kind'] ?? '')), 'Commission') === 0
            && strcasecmp(trim((string) ($scheme['basis'] ?? '')), 'Fixed') === 0;
    }

    /** A Commission Setup scheme "Paid on: Based on target" (owner, 3 Nov 2026). */
    public static function isTargetBased(?array $scheme): bool
    {
        return $scheme !== null && strcasecmp(trim((string) ($scheme['scheme_kind'] ?? '')), 'Commission') === 0
            && strcasecmp(trim((string) ($scheme['basis'] ?? '')), 'Target') === 0;
    }

    /** Active, and the payroll month (YYYY-MM) inside Valid From – Valid Till (blank = open). */
    public static function liveIn(array $scheme, string $month): bool
    {
        $month = substr(trim($month), 0, 7);
        $from = substr(trim((string) ($scheme['valid_from'] ?? '')), 0, 7);
        $till = substr(trim((string) ($scheme['valid_till'] ?? '')), 0, 7);

        return strcasecmp(trim((string) ($scheme['status'] ?? '')), 'Active') === 0 && !Modules::isRetired($scheme)
            && ($month === '' || (($from === '' || $month >= $from) && ($till === '' || $month <= $till)));
    }

    /**
     * THE FIXED COMMISSION SCHEME THAT COVERS THIS EMPLOYEE IN THIS MONTH (owner, 3 Nov 2026), if any: such an
     * employee is paid by the scheme itself and takes no custom commission entry.
     *
     * @param list<array<string, mixed>> $schemes every incentive_schemes row
     * @param array<string, string> $designationRoles designation id => role
     */
    public static function fixedSchemeFor(array $schemes, array $employee, array $designationRoles, string $month): ?array
    {
        foreach ($schemes as $s) {
            if (self::isFixedCommission($s) && self::liveIn($s, $month) && self::eligible($s, $employee, $designationRoles)) {
                return $s;
            }
        }

        return null;
    }

    /**
     * THE SCHEME AS AN EXCEL FORMULA (owner, 3 Nov 2026: "the sample file should have the related calculation built in
     * based on the scheme selected") — the gross of one row from its Target Achieved cell $a and Target cell $t, the
     * same rules as commission(): the minimum %, the target-based slab by achievement % or by amount, the whole or
     * the excess, half-up to the paisa. Blank achieved = blank. Null when the scheme does not work from a figure.
     */
    public static function excelGross(array $scheme, string $a, string $t): ?string
    {
        $type = strtolower(trim((string) ($scheme['rate_type'] ?? '')));
        if (!in_array($type, ['percent', 'slab'], true)) {
            return null;
        }
        $num = static fn (string $v): string => Rational::fromDecimalString(trim($v) === '' ? '0' : trim($v))->toDecimalString(4);
        $byAch = self::isTargetBased($scheme);
        $target = trim((string) ($scheme['target_amount'] ?? ''));
        $thr = trim((string) ($scheme['threshold'] ?? ''));
        $min = trim((string) ($scheme['min_collected'] ?? ''));
        $stops = [];
        // nothing paid: below the minimum achievement % (needs a target), or below a ₹ minimum (not on a target scheme)
        $thr !== '' && $stops[] = "{$a}*100<" . $num($thr) . "*{$t}";
        !$byAch && $min !== '' && !Amount::of($min)->isZero() && $stops[] = "{$a}<" . $num($min);
        $excess = strtolower(trim((string) ($scheme['pay_on'] ?? ''))) === 'excess';
        $above = ($thr !== '' || $byAch) && $target !== '' ? $t : ($min === '' ? '0' : $num($min));
        $base = $excess ? "MAX({$a}-{$above},0)" : $a;
        if ($type === 'percent') {
            $rate = $num((string) ($scheme['rate_value'] ?? ''));
        } else {
            $rate = '0';   // no band covers it: nothing paid, as commission() does
            foreach (array_reverse(self::slabsOf($scheme)) as $band) {
                $test = $byAch ? "{$a}*100<=" . $num($band['upto']) . "*{$t}" : "{$a}<=" . $num($band['upto']);
                $rate = $band['upto'] === '' ? $num($band['rate']) : "IF({$test}," . $num($band['rate']) . ",{$rate})";
            }
        }
        $needsTarget = $byAch || $thr !== '';

        return "IF({$a}=\"\",\"\"," . ($needsTarget ? "IF(N({$t})=0,0," : '')
            . ($stops === [] ? '' : 'IF(OR(' . implode(',', $stops) . '),0,')
            . "ROUND({$base}*{$rate}/100,2)" . ($stops === [] ? '' : ')') . ($needsTarget ? ')' : '') . ')';
    }

    /** The TDS of one row as an Excel formula, from its gross cell $g and its TDS % cell $rate. */
    public static function excelTds(?array $scheme, string $g, string $rate): string
    {
        if ($scheme !== null && trim((string) ($scheme['scheme_kind'] ?? '')) !== '' && self::tdsApplicable($scheme)
            && strtolower(trim((string) ($scheme['tds_mode'] ?? ''))) === 'flat') {
            $flat = Rational::fromDecimalString(trim((string) ($scheme['tds_flat'] ?? '')) ?: '0')->toDecimalString(2);

            return "IF({$g}=\"\",\"\",MIN({$flat},{$g}))";
        }

        return "IF({$g}=\"\",\"\",ROUND({$g}*N({$rate})/100,2))";
    }

    /** Marks an entry the Fixed Commission scheme raised by itself (decided_by). */
    public const FIXED_BY = 'Commission Setup — Fixed commission';

    /**
     * FIXED COMMISSION, PAID BY ITSELF (owner, 3 Nov 2026): one Approved entry per covered active employee per Active
     * Fixed scheme for the month, carrying the scheme's amount, its TDS and its payout (with salary, or a separate
     * payout on its own day). Never a second one for the same scheme, employee and month, so running it again — every
     * payroll close, every visit to Commission Entries — changes nothing. Returns how many entries were raised.
     */
    public static function ensureFixed(RowStore $store, string $month, array $rules): int
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return 0;
        }
        $fixed = array_values(array_filter($store->all('incentive_schemes'), static fn (array $s): bool => self::isFixedCommission($s) && self::liveIn($s, $month)));
        if ($fixed === []) {
            return 0;
        }
        $roles = [];
        foreach ($store->all('designations') as $d) {
            $roles[(string) ($d['id'] ?? '')] = trim((string) ($d['role'] ?? ''));
        }
        $have = [];
        foreach ($store->all('commissions') as $c) {
            Modules::isRetired($c) || $have[(string) ($c['scheme_id'] ?? '') . '|' . (string) ($c['employee_id'] ?? '') . '|' . substr((string) ($c['month'] ?? ''), 0, 7)] = true;
        }
        $made = 0;
        foreach ($store->all('employees') as $e) {
            if (Modules::isRetired($e) || !in_array(strtolower(trim((string) ($e['status'] ?? ''))), ['', 'active'], true)) {
                continue;
            }
            $s = self::fixedSchemeFor($fixed, $e, $roles, $month);
            if ($s === null || isset($have[(string) $s['id'] . '|' . (string) $e['id'] . '|' . $month])) {
                continue;
            }
            $w = self::entry(['base_amount' => ''], $e, $s, $rules);
            $row = [
                'employee_id' => (string) $e['id'], 'employer_company_id' => (string) ($e['employer_company_id'] ?? ''), 'branch_id' => (string) ($e['branch_id'] ?? ''),
                'scheme_id' => (string) $s['id'], 'claim_type' => 'Simple claim — target / bonus / special incentive',
                'purpose' => (string) ($s['purpose'] ?? ''), 'portfolio' => (string) ($s['portfolio'] ?? ''), 'month' => $month,
                'remarks' => mb_substr('Fixed commission — scheme "' . (string) ($s['name'] ?? '') . '": ' . $w['working'], 0, 250),
                'gross_amount' => $w['gross_amount'], 'tds_rate' => $w['tds_rate'], 'tds_amount' => $w['tds_amount'], 'amount' => $w['amount'],
                'payout_method' => (string) ($s['payout_method'] ?? '') ?: 'With salary (in the payslip)',
                'payout_date' => (string) (self::payoutDate($s, $month) ?? ''),
                'status' => 'Approved', 'decided_by' => self::FIXED_BY, 'decided_at' => date('Y-m-d H:i:s'),
            ];
            $row['payout_date'] === '' && $row['payout_date'] = null;
            $store->put('commissions', $row);
            $made++;
        }

        return $made;
    }

    /**
     * @param list<array{upto: string, rate: string}> $bands
     * @return ?array{upto: string, rate: string}
     */
    private static function bandFor(Money $figure, array $bands): ?array
    {
        foreach ($bands as $band) {
            if ($band['upto'] === '' || $figure->compareTo(Amount::of($band['upto'])) <= 0) {
                return $band;
            }
        }

        return null;
    }

    /**
     * A loan's EMI schedule: `principal` recovered at `emi` a month from
     * `startMonth`, the LAST instalment being the remainder, never the standing EMI.
     *
     * @return list<array{n: int, month: string, instalment: Money, after: Money}>
     */
    public static function schedule(string $principal, string $emi, string $startMonth): array
    {
        $left = Amount::of($principal);
        $standing = Amount::of($emi);

        if ($left->isNegative() || $left->isZero() || $standing->isZero() || $standing->isNegative()) {
            return [];
        }

        if (preg_match('/^\d{4}-\d{2}$/', $startMonth) !== 1) {
            throw new \InvalidArgumentException("Start month must be YYYY-MM, got '{$startMonth}'.");
        }

        $month = new \DateTimeImmutable($startMonth . '-01');
        $rows = [];
        $n = 0;

        while (!$left->isZero()) {
            $due = $left->compareTo($standing) < 0 ? $left : $standing;
            $left = $left->minus($due);
            $rows[] = ['n' => ++$n, 'month' => $month->format('Y-m'), 'instalment' => $due, 'after' => $left];
            $month = $month->modify('+1 month');
        }

        return $rows;
    }

    /**
     * WHAT THE CLOSED RUNS SHOW RECOVERED against one line code for one
     * employee, by period, plus what was collected outside payroll. A reversed
     * or deleted run is not counted.
     *
     * ponytail: the same walk `Recoveries::build()` does, which is private and
     * keyed the other way round (it answers "how much before this period", this
     * answers "which month took what"). Expose a read-through on Recoveries the
     * day a third caller needs the ledger.
     *
     * @return array<string, int> period => paise
     */
    public function recoveredByPeriod(string $employmentCode, string $lineCode): array
    {
        $reversed = [];

        foreach ($this->store->all('payroll_corrections') as $correction) {
            if ((string) ($correction['new_fingerprint'] ?? '') === Register::REVERSED) {
                $reversed[(string) ($correction['period'] ?? '')] = true;
            }
        }

        $taken = [];

        foreach ($this->store->all('payroll_runs') as $run) {
            $period = (string) ($run['period'] ?? $run['cycle_label'] ?? '');

            // A deleted (testing) payroll recovered nothing — see Recoveries::build().
            if ($period === '' || isset($reversed[$period]) || (string) ($run['status'] ?? '') === Register::DISCARDED) {
                continue;
            }

            $rows = $run['rows'] ?? $run['run_rows'] ?? [];

            if (is_string($rows)) {
                $rows = $rows === '' ? [] : (array) json_decode($rows, true);
            }

            foreach ((array) (((array) $rows)[$employmentCode]['lines'] ?? []) as $line) {
                if ((string) ($line['code'] ?? '') === $lineCode) {
                    $taken[$period] = ($taken[$period] ?? 0) + (int) ($line['minor'] ?? 0);
                }
            }
        }

        // AND WHAT WAS COLLECTED OUTSIDE PAYROLL (Loans / Salary Advance
        // Recovery, 22 Sep) — cash is recovered money too, in the month received.
        foreach (RecoveryEntries::over($this->store)->collectedByPeriod($lineCode) as $p => $minor) {
            $taken[$p] = ($taken[$p] ?? 0) + $minor;
        }

        ksort($taken);

        return $taken;
    }
}
