<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * LATE LOGIN CRITERIA — Organization Configuration → Late Login Policy (the
 * `late_policy` register, owner 7 Oct). When a row applies to an employee, IT
 * decides what late logins cost in payroll; with none, the published rule in
 * config/attendance-penalty-rules.json still does (see Attendance::penalties()).
 *
 * The row's own fields, nothing else:
 *   grace_min          a day is late when late minutes are MORE than this
 *   late_from          'start' (default) — the criteria minutes count from the shift
 *                      start; 'grace' — they count from the end of the grace period
 *                      (owner, 14 Oct). Kept per policy id in the Customisation key
 *                      late-policy-basis (no migration), merged in by for().
 *   lates_before_cut   free lates per payroll month before any cut
 *   no_cut_on_weekoff  1 (default) = a late on a weekly off / holiday is ignored
 *   mode               Simple · Tiered · Net_hours
 *   cut_mode (Simple)  None · Half_day_per_late · Full_day_per_late ·
 *                      One_day_per_n · Half_day_per_n   (cut_n = N)
 *   Tiered             each chargeable late costs l3_cut / l2_cut / l1_cut days
 *                      by the highest l*_min its late minutes reach
 *   Net_hours          no late cut — the day's hours decide the day
 *
 * Which row: every row whose Applies-to fields (Company, Branch, Department,
 * Role, Designation, Team, Employee, Staff type, Employee type — blank = all)
 * match the employee; the most specific wins (see for()).
 *
 * Pay is settled in half days, so a cut that is not a whole number of halves
 * is refused on the day, with the reason, never rounded.
 */
final class LatePolicy
{
    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private readonly RowStore $store)
    {
    }

    /** Which org / staff fields a policy can be narrowed by: key => label. */
    public const APPLIES_TO = ['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department',
        'role' => 'Role', 'designation' => 'Designation', 'team' => 'Team', 'employee' => 'Employee',
        'staff_type' => 'Staff type', 'employee_type' => 'Employee type'];

    /** The Customisation key holding each policy's late-minutes basis (owner, 14 Oct). */
    public const BASIS_KEY = 'late-policy-basis';

    /** @return array<string, string> policy id => 'start' | 'grace' */
    public static function basis(RowStore $store): array
    {
        try {
            return (array) ((new Customisation($store))->live()[self::BASIS_KEY]['policies'] ?? []);
        } catch (\Throwable) { // gate:allow-swallow no customisation store: every policy counts from the shift start
            return [];
        }
    }

    /**
     * WHAT THE DEDUCTION CRITERIA MEASURE (owner, 14 Oct): 'time' (default) — how late, in
     * minutes; 'count' — which late login of the month it is (the 3rd, the 6th …).
     *
     * @return array<string, string> policy id => 'time' | 'count'
     */
    /**
     * THE DEDUCTION PLAN, as the owner reads it (14 Oct, second pass) — per policy id:
     *   by = 'time'  rows [[minutes, days] …] — a late login that late or more costs that much
     *   by = 'count' rows [[number, days] …]  — from that late login of the month on, each costs that much
     *   by = 'every' every_n, every_d          — every N late logins (after the free ones) cost that much
     * Any number of rows. A policy saved before this has no plan and runs exactly as before.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function plans(RowStore $store): array
    {
        try {
            return (array) ((new Customisation($store))->live()[self::BASIS_KEY]['plans'] ?? []);
        } catch (\Throwable) { // gate:allow-swallow no customisation store: policies run on their own columns
            return [];
        }
    }

    public static function deductBy(RowStore $store): array
    {
        try {
            return (array) ((new Customisation($store))->live()[self::BASIS_KEY]['deduct_by'] ?? []);
        } catch (\Throwable) { // gate:allow-swallow no customisation store: every policy is time based
            return [];
        }
    }

    /** @var array<string, string>|null designation id => its Role */
    private ?array $roles = null;

    /**
     * WHO A ROW APPLIES TO, as key => value ('' / missing = ALL). Rows saved
     * from the Late Login Policy screen keep the narrowing (beyond the company
     * column) as JSON in `scope_target` with scope = Filters, so no migration is
     * needed; older rows (scope Company / Team / Employee) read as before.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function criteria(array $row): array
    {
        $c = ['company' => trim((string) ($row['employer_company_id'] ?? ''))];
        $scope = strtolower(trim((string) ($row['scope'] ?? '')));
        $target = trim((string) ($row['scope_target'] ?? ''));

        if ($scope === 'filters') {
            foreach ((array) json_decode($target, true) as $k => $v) {
                isset(self::APPLIES_TO[$k]) && $k !== 'company' && $c[$k] = trim((string) $v);
            }
        } elseif ($scope === 'employee' && $target !== '') {
            $c['employee'] = $target;
        } elseif ($scope === 'team' && $target !== '') {
            $c['team'] = $target;
        }

        return array_filter($c, static fn (string $v): bool => $v !== '');
    }

    /** @param array<string, mixed> $employee */
    public function for(array $employee): ?array
    {
        if ($this->rows === null) {
            try {
                $this->rows = array_values(array_filter($this->store->all('late_policy'),
                    static fn (array $r): bool => !Modules::isRetired($r)));
            } catch (\Throwable) {
                $this->rows = [];
            }
        }

        $best = null;
        $bestRank = -1;

        foreach ($this->rows as $r) {
            $criteria = self::criteria($r);

            if (!$this->matches($criteria, $employee)) {
                continue;
            }

            // THE MOST SPECIFIC ROW WINS: a named employee over everything, then
            // whichever row narrows by more fields; a tie goes to the later row.
            $rank = (isset($criteria['employee']) ? 1000 : 0) + count($criteria);

            if ($rank >= $bestRank) {
                [$best, $bestRank] = [$r, $rank];
            }
        }

        if ($best !== null) {
            static $basisByStore = [];
            $basis = $basisByStore[spl_object_id($this->store)] ??= self::basis($this->store);
            $best['late_from'] = ($basis[(string) ($best['id'] ?? '')] ?? 'start') === 'grace' ? 'grace' : 'start';
            static $byByStore = [];
            $by = $byByStore[spl_object_id($this->store)] ??= self::deductBy($this->store);
            $best['deduct_by'] = ($by[(string) ($best['id'] ?? '')] ?? 'time') === 'count' ? 'count' : 'time';
            static $plansByStore = [];
            $plan = ($plansByStore[spl_object_id($this->store)] ??= self::plans($this->store))[(string) ($best['id'] ?? '')] ?? null;
            is_array($plan) && $best['plan'] = $plan;
        }

        return $best;
    }

    /** @param array<string, string> $criteria @param array<string, mixed> $e */
    private function matches(array $criteria, array $e): bool
    {
        foreach ($criteria as $k => $want) {
            $have = match ($k) {
                'company' => [(string) ($e['employer_company_id'] ?? '')],
                'branch' => [(string) ($e['branch_id'] ?? ''), (string) ($e['branch'] ?? '')],
                'department' => [(string) ($e['department_id'] ?? ''), (string) ($e['department'] ?? '')],
                'designation' => [(string) ($e['designation_id'] ?? ''), (string) ($e['designation'] ?? '')],
                'team' => [(string) ($e['team_id'] ?? ''), (string) ($e['team'] ?? '')],
                'role' => [$this->roleOf($e)],
                'employee' => [Employment::code($e)],
                'staff_type' => [(string) ($e['type'] ?? '')],
                'employee_type' => [(string) ($e['employment_type'] ?? '')],
                default => [],
            };

            $hit = false;

            foreach ($have as $h) {
                $hit = $hit || ($h !== '' && strcasecmp(trim($h), $want) === 0);
            }

            if (!$hit) {
                return false;
            }
        }

        return true;
    }

    /** The job Role, from the employee's designation (Role / Designation Setup). @param array<string, mixed> $e */
    private function roleOf(array $e): string
    {
        if ($this->roles === null) {
            $this->roles = [];

            try {
                foreach ($this->store->all('designations') as $d) {
                    $this->roles[(string) ($d['id'] ?? '')] = trim((string) ($d['role'] ?? ''));
                }
            } catch (\Throwable) { // gate:allow-swallow no designations table on a file store: role scope matches nobody
            }
        }

        return $this->roles[(string) ($e['designation_id'] ?? '')] ?? '';
    }

    /**
     * What each late day costs under $policy, in the shape Attendance::penalties() returns.
     *
     * @param array<string, mixed> $policy
     * @param list<array{date: string, late: int, off: bool}> $days in date order
     * @return list<array{date: string, halves: int, penalties: list<array{days: string, reason: string, rule: string}>, why: ?string, trace: ?string}>
     */
    public static function penalties(array $policy, array $days): array
    {
        $mode = strtolower((string) ($policy['mode'] ?? 'simple')) ?: 'simple';

        if ($mode === 'net_hours') {
            return [];
        }

        $grace = (int) ($policy['grace_min'] ?? 0);
        $free = (int) ($policy['lates_before_cut'] ?? 0);
        $skipOff = (string) ($policy['no_cut_on_weekoff'] ?? '1') !== '0';
        $cutMode = (string) ($policy['cut_mode'] ?? '');
        // A row from the old register: the cut sat in `addl_late` ("Notes") and
        // cut_mode was left at None. Read it, so the existing row is not silently free.
        $cutMode = $cutMode !== '' && ($cutMode !== 'None' || in_array((string) ($policy['addl_late'] ?? ''), ['', 'None'], true)) ? $cutMode : match ((string) ($policy['addl_late'] ?? '')) {
            'Half_day' => 'Half_day_per_late', 'Full_day' => 'Full_day_per_late', 'One_day_per_n' => 'One_day_per_n', default => 'None',
        };
        $n = max(1, (int) ($policy['cut_n'] ?? 1));
        $fromGrace = (string) ($policy['late_from'] ?? 'start') === 'grace';
        $byCount = (string) ($policy['deduct_by'] ?? 'time') === 'count';
        $rule = 'Late Login Policy' . (($policy['id'] ?? '') !== '' ? ' #' . $policy['id'] : '');
        $count = 0;
        $out = [];
        $chargedHalves = 0; // count plan: what this month's late logins have already cost

        foreach ($days as $day) {
            if ($day['late'] <= $grace || ($skipOff && $day['off'])) {
                continue;
            }

            $count++;
            $beyond = $count - $free;

            if ($beyond <= 0) {
                continue;
            }

            // THE MINUTES THE CRITERIA READ (owner, 14 Oct): from the shift start, or from the
            // end of the grace period. Which days are late is the same either way.
            $mins = $fromGrace ? $day['late'] - $grace : $day['late'];
            $minsWord = $fromGrace ? "late {$day['late']} min ({$mins} after {$grace} min grace)" : "late {$day['late']} min";

            $plan = is_array($policy['plan'] ?? null) ? $policy['plan'] : null;

            if ($plan !== null) {
                if (($plan['by'] ?? '') === 'every') {
                    $every = max(1, (int) ($plan['every_n'] ?? 1));

                    if ($beyond % $every !== 0) {
                        continue;
                    }

                    $cut = trim((string) ($plan['every_d'] ?? '0')) ?: '0';
                    $why = "{$minsWord}, late login no. {$count} this month — every {$every} late logins"
                        . ($free > 0 ? " after {$free} free" : '') . " cost {$cut} day(s)";
                } else {
                    $byCount = ($plan['by'] ?? '') === 'count';
                    $measure = $byCount ? $count : $mins;

                    // "3 LATE LOGINS = ½ DAY, 6 = 1 DAY" (owner, 14 Oct): the month costs the highest
                    // line its late logins reach — charged on the late login that reaches it, less
                    // what earlier lines already cost, so 7 late logins cost 1 day in all.
                    if ($byCount) {
                        $tierAt = 0;
                        $tier = '0';

                        foreach ((array) ($plan['rows'] ?? []) as $r) {
                            if (is_array($r) && trim((string) ($r[1] ?? '')) !== '' && $count >= (int) $r[0] && (int) $r[0] >= $tierAt) {
                                [$tierAt, $tier] = [(int) $r[0], trim((string) $r[1])];
                            }
                        }

                        $cutHalves = max(0, (self::halves($tier) ?? 0) - $chargedHalves);

                        if ($cutHalves === 0) {
                            continue;
                        }

                        $before = $chargedHalves;
                        $chargedHalves += $cutHalves;
                        $cut = rtrim(rtrim(number_format($cutHalves / 2, 1, '.', ''), '0'), '.');
                        $why = "{$minsWord}, late login no. {$count} this month reaches \"{$tierAt} late logins = {$tier} day(s)\""
                            . ($before > 0 ? ' — ' . rtrim(rtrim(number_format($before / 2, 1, '.', ''), '0'), '.') . ' day(s) already cut this month' : '');
                        $out[] = ['date' => $day['date'], 'halves' => $cutHalves, 'why' => null, 'trace' => null,
                            'penalties' => [['days' => $cut, 'reason' => $why, 'rule' => $rule]]];

                        continue;
                    }
                    $rows = array_values(array_filter((array) ($plan['rows'] ?? []), static fn ($r): bool => is_array($r) && trim((string) ($r[1] ?? '')) !== ''));
                    usort($rows, static fn (array $a, array $b): int => (int) $b[0] <=> (int) $a[0]);
                    $cut = '0';

                    foreach ($rows as [$at, $d]) {
                        if ($measure >= (int) $at) {
                            $cut = trim((string) $d);
                            break;
                        }
                    }

                    $why = $byCount ? "{$minsWord}, late login no. {$count} this month — cut {$cut} day(s)"
                        : "{$minsWord}, chargeable late no. {$beyond} — cut {$cut} day(s)";
                }
            } elseif ($mode === 'tiered') {
                // "Every Nth late" applies to the criteria table too (Every_n).
                if ($cutMode === 'Every_n' && $beyond % $n !== 0) {
                    continue;
                }

                $cut = '0';

                foreach ([3, 2, 1] as $t) {
                    $at = (int) ($policy["l{$t}_min"] ?? 0);
                    // Criteria 1 with no minutes / count = any late login over the grace.
                    $any = $t === 1 && $at === 0 && trim((string) ($policy['l1_cut'] ?? '')) !== '';

                    // COUNT BASED: the criterion is the late login's number in the month (owner, 14 Oct).
                    if (($at > 0 && ($byCount ? $count : $mins) >= $at) || $any) {
                        $cut = trim((string) ($policy["l{$t}_cut"] ?? '0')) ?: '0';
                        break;
                    }
                }

                $why = $byCount ? "{$minsWord}, late login no. {$count} this month — cut {$cut} day(s)"
                    : "{$minsWord}, chargeable late no. {$beyond} — tier cut {$cut} day(s)";
            } else {
                $cut = match ($cutMode) {
                    'Half_day_per_late' => '0.5',
                    'Full_day_per_late' => '1',
                    'One_day_per_n' => $beyond % $n === 0 ? '1' : '0',
                    'Half_day_per_n' => $beyond % $n === 0 ? '0.5' : '0',
                    default => '0',
                };
                $why = "{$minsWord}, chargeable late no. {$beyond} after {$free} free — "
                    . str_replace('_', ' ', $cutMode) . ($n > 1 && str_ends_with($cutMode, '_n') ? " (N = {$n})" : '');
            }

            $halves = self::halves($cut);

            if ($halves === null) {
                $out[] = ['date' => $day['date'], 'halves' => 0, 'penalties' => [], 'trace' => null,
                    'why' => "A cut of {$cut} day(s) is not a whole number of half days; refused rather than rounded."];

                continue;
            }

            if ($halves > 0) {
                $out[] = ['date' => $day['date'], 'halves' => $halves, 'why' => null, 'trace' => null,
                    'penalties' => [['days' => $cut, 'reason' => $why, 'rule' => $rule]]];
            }
        }

        return $out;
    }

    /** '0.5' → 1, '1' → 2, '0.25' → null. */
    private static function halves(string $days): ?int
    {
        if (preg_match('/^\d+(\.\d+)?$/', $days) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $days), 2, '');
        $frac = rtrim($frac, '0');

        return match ($frac) {
            '' => (int) $whole * 2,
            '5' => (int) $whole * 2 + 1,
            default => null,
        };
    }
}
