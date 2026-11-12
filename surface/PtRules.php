<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * PROFESSIONAL TAX RULES (owner, 28 Oct 2026: "In Organization Configuration - as a sub menu of Salary
 * structure, add PT slabs … full customization … monthly, quarterly, half yearly, gender wise, salary
 * wise slabs"). Organization Setup → PT Slabs edits them; payroll (ComponentRules) and the PT
 * Applicable answer (governmentStatutory) both read them from here — one place, one answer.
 *
 * A RULE: who (state, company, branch, gender), how often (monthly / quarterly / half-yearly / yearly
 * and the months it is deducted in), what the slab "up to" figures measure (the month's salary, or the
 * income of the whole period), the slabs (up to ₹ → PT ₹, plus a different amount in one named month,
 * e.g. ₹300 in February), an annual maximum (checked on screen) and the date it takes effect.
 *
 * Saved as a versioned Customisation ('pt-rules'); until the first save the Government schedule in
 * config/pt.json is shown and used. Nothing is overwritten: an edit with a later "Effective from" is
 * added beside the rule it replaces, so a re-run of an earlier month still finds the old slabs.
 */
final class PtRules
{
    public const KEY = 'pt-rules';

    /** frequency => [label, months in one period] */
    public const FREQUENCIES = [
        'monthly' => ['Monthly', 1],
        'quarterly' => ['Quarterly', 3],
        'half-yearly' => ['Half-yearly', 6],
        'yearly' => ['Yearly', 12],
    ];

    public const GENDERS = ['all' => 'All', 'male' => 'Male', 'female' => 'Female', 'other' => 'Other / not set'];

    /** What the slab "Up to" figures are in: label, months of gross they stand for (MP: monthly deduction, yearly bands). */
    public const SLAB_ON = [
        'monthly' => ['Monthly salary', 1],
        'quarterly' => ['Quarterly income', 3],
        'half-yearly' => ['Half-yearly income', 6],
        'yearly' => ['Yearly income', 12],
    ];

    public const MONTHS = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

    /** @var \WeakMap<RowStore, self>|null keyed by the store object itself, so a freed store's id is never reused */
    private static ?\WeakMap $memo = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $rules = null;

    private ?array $cfg = null;

    public function __construct(private readonly RowStore $store, private readonly string $configDir)
    {
    }

    /** One instance per store for the request, so payroll reads the rules once, not per employee. */
    public static function for(RowStore $store, string $configDir): self
    {
        self::$memo ??= new \WeakMap();

        return self::$memo[$store] ??= new self($store, $configDir);
    }

    /** @return array<string, mixed> config/pt.json */
    public function config(): array
    {
        return $this->cfg ??= (array) (json_decode((string) @file_get_contents($this->configDir . '/pt.json'), true) ?: []);
    }

    /** The newest saved version, or null when nothing has been saved (or the saved choice is "Government"). */
    public function saved(): ?array
    {
        try {
            $best = null;
            foreach ($this->store->all('module_customisations') as $row) {
                if ((string) ($row['module_key'] ?? '') === self::KEY && (int) ($row['version'] ?? 0) > (int) ($best['version'] ?? 0)) {
                    $best = $row;
                }
            }
        } catch (\Throwable) {
            return null;   // no customisation table yet: the Government schedule applies, as before
        }
        $payload = $best === null ? null : json_decode((string) ($best['payload'] ?? ''), true);

        return is_array($payload) && !($payload['use_shipped'] ?? false) && is_array($payload['rules'] ?? null)
            ? array_values(array_map([self::class, 'normalise'], $payload['rules'])) : null;
    }

    public function isCustomised(): bool
    {
        return $this->saved() !== null;
    }

    /** @return list<array<string, mixed>> the rules in use: the saved ones, else the Government schedule */
    public function rules(): array
    {
        return $this->rules ??= ($this->saved() ?? $this->shipped());
    }

    /**
     * config/pt.json as rules: one per state, plus a women's rule where the state has one; a half-yearly
     * state's slabs are shown in half-year income (pt.json keeps them per month).
     *
     * @return list<array<string, mixed>>
     */
    public function shipped(): array
    {
        $cfg = $this->config();
        $cap = (string) ($cfg['annual_cap'] ?? '');
        $out = [];
        foreach ((array) ($cfg['states'] ?? []) as $key => $s) {
            $s = (array) $s;
            $id = 'gov-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $key));
            if (isset($s['bands'])) {   // written the way the screen shows it: [up to, amount, amount in the special month]
                $bands = array_map(static fn (array $b): array => ['upto' => $b[0], 'amount' => (string) $b[1], 'special' => (string) ($b[2] ?? '')], (array) $s['bands']);
                $out[] = self::normalise([
                    'id' => $id, 'name' => ucwords((string) $key), 'state' => ucwords((string) $key), 'gender' => 'all',
                    'frequency' => (string) ($s['frequency'] ?? 'monthly'), 'months' => (array) ($s['months'] ?? []),
                    'slab_on' => (string) ($s['slab_on'] ?? 'monthly'), 'slabs' => $bands, 'special_month' => (int) ($s['special_month'] ?? 0),
                    'annual_cap' => $cap, 'effective_from' => '', 'active' => true, 'source' => 'government',
                ]);
                continue;
            }
            $half = isset($s['half_yearly_months']);
            $n = $half ? 6 : 1;
            $feb = (string) ($s['february_top_amount'] ?? '');
            $mk = static function (array $rows) use ($n, $feb): array {
                $slabs = [];
                $last = count($rows) - 1;
                foreach (array_values($rows) as $i => $r) {
                    $slabs[] = [
                        'upto' => $r[1] === null ? null : self::fmt(Money::of((string) $r[1])->minor * $n),
                        'amount' => self::fmt(Money::of((string) $r[2])->minor),
                        'special' => $i === $last && $feb !== '' && Money::of((string) $r[2])->minor > 0 ? self::fmt(Money::of($feb)->minor) : '',
                    ];
                }

                return $slabs;
            };
            $levied = array_filter((array) $s['slabs'], static fn (array $r): bool => Money::of((string) $r[2])->minor > 0) !== [];
            $base = [
                'state' => ucwords((string) $key),
                'company_id' => '', 'branch_id' => '',
                'frequency' => $half ? 'half-yearly' : 'monthly',
                'months' => $half ? array_map('intval', (array) $s['half_yearly_months']) : range(1, 12),
                'slab_on' => $half ? 'half-yearly' : 'monthly',
                'special_month' => $feb !== '' ? 2 : 0,
                'annual_cap' => $levied ? $cap : '',
                'effective_from' => '',
                'active' => true,
                'source' => 'government',
            ];
            $out[] = self::normalise($base + ['id' => $id, 'name' => ucwords((string) $key), 'gender' => 'all', 'slabs' => $mk((array) $s['slabs'])]);
            if (isset($s['female'])) {
                $out[] = self::normalise($base + ['id' => $id . '-female', 'name' => ucwords((string) $key) . ' — women', 'gender' => 'female', 'slabs' => $mk((array) $s['female'])]);
            }
        }

        return $out;
    }

    /** @return list<string> every state / UT name the State dropdown offers, plus those used by rules */
    public function stateNames(array $extra = []): array
    {
        $all = [];
        foreach (array_merge((array) ($this->config()['state_names'] ?? []), array_map(static fn (array $r): string => (string) $r['state'], $this->rules()), $extra) as $s) {
            $s = trim((string) $s);
            $s === '' || $all[strtolower($s)] ??= $s;
        }
        natcasesort($all);

        return array_values($all);
    }

    /**
     * A rule in one shape whatever its source (saved, shipped, posted).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function normalise(array $r): array
    {
        $freq = isset(self::FREQUENCIES[(string) ($r['frequency'] ?? '')]) ? (string) $r['frequency'] : 'monthly';
        $months = array_values(array_unique(array_filter(array_map('intval', (array) ($r['months'] ?? [])), static fn (int $m): bool => $m >= 1 && $m <= 12)));
        // Monthly with no months given = every month (a shipped or older rule); any month can be unticked on screen.
        $freq === 'monthly' && $months === [] && !array_key_exists('months_set', $r) && $months = range(1, 12);
        sort($months);
        $slabs = [];
        foreach ((array) ($r['slabs'] ?? []) as $s) {
            $s = (array) $s;
            $upto = trim(str_replace([',', '₹', ' '], '', (string) ($s['upto'] ?? '')));
            $amount = trim(str_replace([',', '₹', ' '], '', (string) ($s['amount'] ?? '')));
            $special = trim(str_replace([',', '₹', ' '], '', (string) ($s['special'] ?? '')));
            if ($upto === '' && $amount === '' && $special === '') {
                continue;
            }
            $slabs[] = ['upto' => $upto === '' ? null : $upto, 'amount' => $amount, 'special' => $special];
        }
        $gender = strtolower((string) ($r['gender'] ?? 'all'));

        return [
            'id' => (string) ($r['id'] ?? ''),
            'name' => trim((string) ($r['name'] ?? '')),
            'state' => trim((string) ($r['state'] ?? '')),
            'company_id' => trim((string) ($r['company_id'] ?? '')),
            'branch_id' => trim((string) ($r['branch_id'] ?? '')),
            'gender' => isset(self::GENDERS[$gender]) ? $gender : 'all',
            'frequency' => $freq,
            'months' => $months,
            // 'period' (first version, 28 Oct) = the frequency's own months.
            'slab_on' => isset(self::SLAB_ON[(string) ($r['slab_on'] ?? '')]) ? (string) $r['slab_on']
                : (($r['slab_on'] ?? '') === 'period' ? $freq : 'monthly'),
            // EXEMPTIONS: age at least N on the payroll date; persons with disability; employee types.
            'exempt_age' => preg_match('/^\d{1,3}$/', trim((string) ($r['exempt_age'] ?? ''))) === 1 ? trim((string) $r['exempt_age']) : '',
            'exempt_pwd' => (bool) ($r['exempt_pwd'] ?? false),
            'exempt_types' => array_values(array_filter(array_map('strval', (array) ($r['exempt_types'] ?? [])), static fn (string $t): bool => trim($t) !== '')),
            'slabs' => $slabs,
            'special_month' => max(0, min(12, (int) ($r['special_month'] ?? 0))),
            'annual_cap' => trim(str_replace([',', '₹', ' '], '', (string) ($r['annual_cap'] ?? ''))),
            'effective_from' => trim((string) ($r['effective_from'] ?? '')),
            'active' => (bool) ($r['active'] ?? true),
            'source' => (string) ($r['source'] ?? ''),
        ];
    }

    /**
     * Everything wrong with a set of rules, in words for the person who typed them.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    public static function problems(array $rules): array
    {
        $out = [];
        $seen = [];
        $money = static function (string $v): ?int {
            if (preg_match('/^\d+(\.\d{1,2})?$/', $v) !== 1) {
                return null;
            }

            return Money::of($v)->minor;
        };
        foreach ($rules as $r) {
            $who = $r['name'] !== '' ? $r['name'] : ($r['state'] !== '' ? $r['state'] : 'A rule');
            $r['state'] === '' && $out[] = $who . ': choose the State.';
            $r['months'] === [] && $out[] = $who . ': tick at least one month PT is deducted in.';
            $r['slabs'] === [] && $out[] = $who . ': add at least one slab.';
            $prev = -1;
            foreach ($r['slabs'] as $i => $s) {
                $no = $i + 1;
                $money($s['amount']) === null && $out[] = $who . ', slab ' . $no . ': the PT amount must be a rupee figure (0 for nil).';
                $s['special'] !== '' && $money($s['special']) === null && $out[] = $who . ', slab ' . $no . ': the amount in ' . (self::MONTHS[$r['special_month']] ?? 'the special month') . ' must be a rupee figure.';
                if ($s['upto'] === null) {
                    $i === count($r['slabs']) - 1 || $out[] = $who . ', slab ' . $no . ': only the last slab may have "Up to" blank (= and above).';
                    continue;
                }
                $u = $money((string) $s['upto']);
                if ($u === null) {
                    $out[] = $who . ', slab ' . $no . ': "Up to" must be a rupee figure.';
                    continue;
                }
                $u > $prev || $out[] = $who . ', slab ' . $no . ': "Up to" must be higher than the slab above it.';
                $prev = $u;
            }
            $r['slabs'] !== [] && end($r['slabs'])['upto'] !== null && $out[] = $who . ': leave the last slab\'s "Up to" blank, so every salary above it is covered.';
            if ($r['special_month'] > 0 && !in_array($r['special_month'], $r['months'], true)) {
                $out[] = $who . ': PT is not deducted in ' . self::MONTHS[$r['special_month']] . ', so a different amount for that month would never be used — tick the month or choose "none".';
            }
            $r['annual_cap'] !== '' && $money($r['annual_cap']) === null && $out[] = $who . ': the annual maximum must be a rupee figure.';
            $r['exempt_age'] !== '' && ((int) $r['exempt_age'] < 14 || (int) $r['exempt_age'] > 100) && $out[] = $who . ': the exempt age must be between 14 and 100.';
            if ($r['effective_from'] !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['effective_from']) !== 1 || strtotime($r['effective_from']) === false)) {
                $out[] = $who . ': "Effective from" is not a date.';
            }
            if ($r['active']) {
                $k = strtolower($r['state']) . '|' . $r['company_id'] . '|' . $r['branch_id'] . '|' . $r['gender'] . '|' . $r['effective_from'];
                isset($seen[$k]) && $out[] = $who . ' and ' . $seen[$k] . ' cover the same people from the same date — change one of them, or make one Inactive.';
                $seen[$k] = $who;
            }
        }

        return array_values(array_unique($out));
    }

    /** "male" / "female" / "other" from an employee's Gender. */
    public static function genderKey(string $gender): string
    {
        return match (strtolower(substr(trim($gender), 0, 1))) {
            'm' => 'male',
            'f' => 'female',
            default => 'other',
        };
    }

    /**
     * THE RULE FOR ONE PERSON ON ONE DATE: active, their state, in effect on that date; the most specific
     * wins (Branch, then Company, then a Gender rule over "All"), then the latest Effective from.
     */
    public function ruleFor(string $state, string $companyId, string $branchId, string $gender, string $asOf): ?array
    {
        $g = self::genderKey($gender);
        $best = null;
        $bestKey = null;
        foreach ($this->rules() as $i => $r) {
            if (!$r['active'] || strcasecmp($r['state'], trim($state)) !== 0
                || ($r['effective_from'] !== '' && $r['effective_from'] > $asOf)
                || ($r['company_id'] !== '' && $r['company_id'] !== $companyId)
                || ($r['branch_id'] !== '' && $r['branch_id'] !== $branchId)
                || ($r['gender'] !== 'all' && $r['gender'] !== $g)) {
                continue;
            }
            $key = [($r['branch_id'] !== '' ? 4 : 0) + ($r['company_id'] !== '' ? 2 : 0) + ($r['gender'] !== 'all' ? 1 : 0), $r['effective_from'], $i];
            if ($bestKey === null || $key > $bestKey) {
                [$best, $bestKey] = [$r, $key];
            }
        }

        return $best;
    }

    /** Months of gross the slab figures stand for (1 monthly … 12 yearly). */
    public static function periodMonths(array $rule): int
    {
        return (int) self::SLAB_ON[$rule['slab_on']][1];
    }

    /**
     * The rule as the payroll engine's SLAB on the MONTH'S gross for one month: zero in a month PT is not
     * deducted; a period-income rule's "up to" figures divided by the months in the period (rounded so
     * the bands still meet exactly); the named month's own amount where one is set.
     *
     * @return list<array{from: string, to: ?string, amount: string, percent: null}>
     */
    public static function engineSlab(array $rule, int $month): array
    {
        if (!in_array($month, $rule['months'], true)) {
            return [['from' => '0', 'to' => null, 'amount' => '0.00', 'percent' => null]];
        }
        $n = self::periodMonths($rule);
        $out = [];
        $prev = null;
        foreach ($rule['slabs'] as $s) {
            $upto = $s['upto'] === null ? null : Money::of((string) $s['upto'])->minor;
            // g×n ≥ prev+1 paisa  ⇔  g ≥ ceil((prev+1)/n);  g×n ≤ upto  ⇔  g ≤ floor(upto/n) — the bands meet.
            $from = $prev === null ? 0 : intdiv($prev + 1 + $n - 1, $n);
            $amount = $rule['special_month'] === $month && $s['special'] !== '' ? $s['special'] : $s['amount'];
            $out[] = ['from' => self::fmt($from), 'to' => $upto === null ? null : self::fmt(intdiv($upto, $n)),
                'amount' => self::fmt(Money::of($amount === '' ? '0' : $amount)->minor), 'percent' => null];
            $prev = $upto;
        }

        return $out;
    }

    /**
     * The engine slab for a person, or null when no rule covers their state (payroll refuses then).
     *
     * PART OF A PERIOD (owner, 29 Oct 2026: "PT should be reduced proportionally for people who join or leave
     * mid-year"): a quarterly / half-yearly / yearly deduction is cut to the months of its period the person was
     * employed (Date of Joining, Exit Date), and someone leaving before the period's deduction month pays their
     * share in the exit month. A monthly rule is already charged only for the months worked.
     *
     * ANNUAL MAXIMUM IN PAYROLL (owner, 29 Oct 2026): once the PT deducted in this financial year's closed payrolls
     * reaches the rule's annual maximum, nothing more is deducted; the month that would cross it takes only the rest.
     */
    public function slab(string $state, string $companyId, string $branchId, string $gender, string $asOf, array $employee = []): ?array
    {
        $r = $this->ruleFor($state, $companyId, $branchId, $gender, $asOf);
        if ($r === null) {
            return null;
        }
        $zero = [['from' => '0', 'to' => null, 'amount' => '0.00', 'percent' => null]];
        if ($this->exemptReason($r, $employee, $asOf) !== null) {
            return $zero;
        }
        [$month, $num, $den] = self::partOfPeriod($r, substr($asOf, 0, 7), $employee);
        if ($month === null || $num === 0) {
            return $zero;
        }
        $slab = self::engineSlab($r, $month);
        $left = $this->capLeft($r, $employee, substr($asOf, 0, 7));
        foreach ($slab as $i => $b) {
            $minor = Money::of($b['amount'])->minor;
            // Whole rupees, half up: ₹1,250 × 2 of 6 months = ₹417.
            $num !== $den && $minor = intdiv($minor * $num + $den * 50, $den * 100) * 100;
            $left !== null && $minor = min($minor, $left);
            $slab[$i]['amount'] = self::fmt($minor);
        }

        return $slab;
    }

    /**
     * WHICH MONTH'S SLAB THIS PAYROLL MONTH CHARGES, AND WHAT SHARE OF IT: [month or null, months employed, months
     * in the period]. A monthly rule (deducted every month) is always [this month, 1, 1].
     *
     * @return array{0: ?int, 1: int, 2: int}
     */
    public static function partOfPeriod(array $rule, string $period, array $employee): array
    {
        $month = (int) substr($period, 5, 2);
        $due = $rule['months'];
        if (count($due) === 12 || $due === []) {
            return [$month, 1, 1];
        }
        $ym = static fn (string $p, int $add): string => date('Y-m', (int) strtotime($p . '-01 ' . ($add >= 0 ? '+' : '') . $add . ' months'));
        // The period a deduction month closes: from the month after the previous deduction month, up to it.
        $length = static function (int $d) use ($due): int {
            $i = array_search($d, $due, true);
            $prev = $due[($i - 1 + count($due)) % count($due)];

            return count($due) === 1 ? 12 : (($d - $prev + 12) % 12 ?: 12);
        };
        $doj = substr((string) Employment::joinedOn($employee), 0, 7);
        $exit = substr(trim((string) Employment::value($employee, 'exit_date')), 0, 7);
        $worked = static function (string $from, string $to) use ($doj, $exit, $ym): int {
            $n = 0;
            for ($p = $from; $p <= $to; $p = $ym($p, 1)) {
                ($doj === '' || $doj <= $p) && ($exit === '' || $exit >= $p) && $n++;
            }

            return $n;
        };

        if ($exit !== '' && $exit < $period) {
            return [null, 0, 1];   // left before this month: their share was taken in the exit month
        }
        if (in_array($month, $due, true)) {
            $len = $length($month);

            return [$month, $worked($ym($period, 1 - $len), $period), $len];
        }
        // LEAVING BEFORE THE PERIOD'S DEDUCTION MONTH: the share up to the exit month is deducted now.
        if ($exit !== '' && $exit === $period) {
            for ($k = 1; $k <= 12 && !in_array((($month - 1 + $k) % 12) + 1, $due, true); $k++) {
            }
            $d = (($month - 1 + $k) % 12) + 1;
            $len = $length($d);

            return [$d, $worked($ym($ym($period, $k), 1 - $len), $period), $len];
        }

        return [null, 0, 1];
    }

    /** @var array<string, array<string, int>>|null employee key => period => PT paise, from the closed payrolls */
    private ?array $ptPaid = null;

    /**
     * WHAT IS LEFT OF THE RULE'S ANNUAL MAXIMUM for this person in the financial year of `$period`, in paise — the
     * PT their closed payrolls of the year deducted before this month taken off. Null when the rule sets none.
     */
    public function capLeft(array $rule, array $employee, string $period): ?int
    {
        if ($rule['annual_cap'] === '' || preg_match('/^\d+(\.\d{1,2})?$/', $rule['annual_cap']) !== 1) {
            return null;
        }
        $start = $this->fyStartMonth();
        $y = (int) substr($period, 0, 4) - ((int) substr($period, 5, 2) < $start ? 1 : 0);
        $from = sprintf('%d-%02d', $y, $start);
        $paid = 0;
        foreach ([(string) ($employee['id'] ?? ''), Employment::code($employee)] as $k) {
            foreach ($k === '' ? [] : ($this->ptPaid()[$k] ?? []) as $p => $minor) {
                $p >= $from && $p < $period && $paid += $minor;
            }
            if ($paid > 0) {
                break;   // the same run rows, keyed by id or by code — counted once
            }
        }

        return max(0, Money::of($rule['annual_cap'])->minor - $paid);
    }

    /** @return array<string, array<string, int>> */
    private function ptPaid(): array
    {
        if ($this->ptPaid !== null) {
            return $this->ptPaid;
        }
        $this->ptPaid = [];
        $reversed = [];
        try {
            foreach ($this->store->all('payroll_corrections') as $c) {
                (string) ($c['new_fingerprint'] ?? '') === Register::REVERSED && $reversed[(string) ($c['period'] ?? '')] = true;
            }
        } catch (\Throwable) {
            $reversed = [];   // no corrections table yet: nothing was reversed
        }
        try {
            foreach ($this->store->all('payroll_runs') as $run) {
                $p = (string) ($run['period'] ?? $run['cycle_label'] ?? '');
                if ($p === '' || isset($reversed[$p]) || strtolower((string) ($run['status'] ?? '')) === Register::DISCARDED) {
                    continue;
                }
                $rows = $run['rows'] ?? $run['run_rows'] ?? [];
                $rows = is_array($rows) ? $rows : (array) json_decode((string) $rows, true);
                foreach ($rows as $key => $row) {
                    foreach ((array) ($row['lines'] ?? []) as $l) {
                        if (($l['kind'] ?? '') !== 'earning' && preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? '')) === 'PT') {
                            $this->ptPaid[(string) $key][$p] = ($this->ptPaid[(string) $key][$p] ?? 0) + (int) ($l['minor'] ?? 0);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            $this->ptPaid = [];   // no payroll tables yet: nothing has been deducted
        }

        return $this->ptPaid;
    }

    /** The organisation's financial year start month (Organization Setup → Financial Year; config default). */
    private function fyStartMonth(): int
    {
        $declared = (array) (json_decode((string) @file_get_contents($this->configDir . '/financial-year.json'), true) ?: []);
        $saved = [];
        try {
            $best = null;
            foreach ($this->store->all('module_customisations') as $row) {
                if ((string) ($row['module_key'] ?? '') === (string) ($declared['customisation_key'] ?? 'financial-year') && (int) ($row['version'] ?? 0) > (int) ($best['version'] ?? 0)) {
                    $best = $row;
                }
            }
            $saved = (array) json_decode((string) ($best['payload'] ?? ''), true);
        } catch (\Throwable) {
            $saved = [];   // no customisation table yet: the shipped default
        }

        return max(1, min(12, (int) ($saved['start_month'] ?? $declared['defaults']['start_month'] ?? 4)));
    }

    /**
     * WHY THIS PERSON PAYS NO PT UNDER THIS RULE, or null: age at least the rule's figure on the date (from
     * Date of Birth), a person with disability (the Employee field named in config/pt.json), or one of the
     * rule's employee types. A field that is blank never exempts anyone.
     */
    public function exemptReason(array $rule, array $employee, string $asOf): ?string
    {
        if ($employee === []) {
            return null;
        }
        $dob = (string) Employment::value($employee, 'dob');
        if ($rule['exempt_age'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dob) === 1) {
            $age = (new \DateTimeImmutable(substr($dob, 0, 10)))->diff(new \DateTimeImmutable(substr($asOf, 0, 10)))->y;
            if ($age >= (int) $rule['exempt_age']) {
                return 'age ' . $age . ' (exempt from ' . $rule['exempt_age'] . ')';
            }
        }
        $type = trim((string) Employment::value($employee, 'employment_type'));
        if ($type !== '' && in_array(strtolower($type), array_map('strtolower', $rule['exempt_types']), true)) {
            return 'employee type ' . $type;
        }
        if ($rule['exempt_pwd']) {
            $field = (string) ($this->config()['pwd_field'] ?? '');
            $yes = array_map('strtolower', (array) ($this->config()['pwd_values'] ?? []));
            $v = strtolower(trim((string) ($employee[$field] ?? ($this->pwdValues()[Employment::code($employee)] ?? ''))));
            if ($field !== '' && $v !== '' && in_array($v, $yes, true)) {
                return 'person with disability';
            }
        }

        return null;
    }

    /** @var array<string, string>|null employee code => the disability field's value, read once */
    private ?array $pwd = null;

    /** @return array<string, string> */
    private function pwdValues(): array
    {
        if ($this->pwd !== null) {
            return $this->pwd;
        }
        $this->pwd = [];
        $field = (string) ($this->config()['pwd_field'] ?? '');
        try {
            foreach ($this->store->all(EmployeeExtras::COLLECTION) as $row) {
                (string) ($row['field_code'] ?? '') === $field && $this->pwd[(string) ($row['employment_code'] ?? '')] = (string) ($row['value'] ?? '');
            }
        } catch (\Throwable) {
            $this->pwd = [];   // no employee-field table yet: nobody is recorded as a person with disability
        }

        return $this->pwd;
    }

    /** PT on one month's gross in one month (paise), from the engine slab — the same arithmetic payroll does. */
    public static function amountMinor(array $rule, int $grossMinor, int $month): int
    {
        foreach (self::engineSlab($rule, $month) as $b) {
            if ($grossMinor >= Money::of($b['from'])->minor && ($b['to'] === null || $grossMinor <= Money::of($b['to'])->minor)) {
                return Money::of($b['amount'])->minor;
            }
        }

        return 0;
    }

    /**
     * IS PT APPLICABLE on this monthly gross: any deduction month would charge something. Null when no rule
     * covers the state (the saved answer stands).
     */
    public function applies(string $state, string $companyId, string $branchId, string $gender, int $grossMinor, string $asOf, array $employee = []): ?bool
    {
        $r = $this->ruleFor($state, $companyId, $branchId, $gender, $asOf);
        if ($r === null) {
            return null;
        }
        if ($this->exemptReason($r, $employee, $asOf) !== null) {
            return false;
        }
        foreach ($r['months'] as $m) {
            if (self::amountMinor($r, $grossMinor, $m) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * THE YEAR AT A GLANCE for each slab: PT in each month of the financial year and the year's total —
     * how the screen shows every number's working, and checks the annual maximum.
     *
     * @return list<array{label: string, months: array<int, int>, total: int}>
     */
    public static function yearPlan(array $rule, int $fyStartMonth = 4): array
    {
        $order = [];
        for ($i = 0; $i < 12; $i++) {
            $order[] = (($fyStartMonth - 1 + $i) % 12) + 1;
        }
        $out = [];
        $prev = null;
        foreach ($rule['slabs'] as $s) {
            $months = [];
            foreach ($order as $m) {
                $amt = !in_array($m, $rule['months'], true) ? '0'
                    : ($rule['special_month'] === $m && $s['special'] !== '' ? $s['special'] : ($s['amount'] === '' ? '0' : $s['amount']));
                $months[$m] = preg_match('/^\d+(\.\d{1,2})?$/', $amt) === 1 ? Money::of($amt)->minor : 0;
            }
            $label = self::rangeLabel($prev === null ? null : (string) $prev, $s['upto'] === null ? null : (string) $s['upto']);
            $out[] = ['label' => $label, 'months' => $months, 'total' => array_sum($months)];
            $prev = $s['upto'];
        }

        return $out;
    }

    /** A slab's salary range in words: "Up to ₹15,000", "₹15,000.01 – ₹20,000", "Above ₹20,000", "₹25,000 and above". */
    public static function rangeLabel(?string $prev, ?string $upto): string
    {
        if ($prev === null) {
            return $upto === null ? 'Any salary' : 'Up to ' . self::rupees($upto);
        }
        $from = self::minor($prev) + 1;
        if ($upto === null) {
            return $from % 100 === 0 ? self::rupees(self::fmt($from)) . ' and above' : 'Above ' . self::rupees($prev);
        }

        return self::rupees(self::fmt($from)) . ' – ' . self::rupees($upto);
    }

    /** Each slab in words: "Up to ₹15,000: Nil", "₹15,000.01 – ₹20,000: ₹150", "Above ₹20,000: ₹200 (₹300 in Feb)". @return list<string> */
    public static function summaryLines(array $rule): array
    {
        $parts = [];
        $prev = null;
        foreach ($rule['slabs'] as $s) {
            $range = self::rangeLabel($prev === null ? null : (string) $prev, $s['upto'] === null ? null : (string) $s['upto']);
            $amt = $s['amount'] === '' || (preg_match('/^\d+(\.\d{1,2})?$/', $s['amount']) === 1 && Money::of($s['amount'])->minor === 0) ? 'Nil' : self::rupees($s['amount']);
            $sp = $rule['special_month'] > 0 && $s['special'] !== '' ? ' (' . self::rupees($s['special']) . ' in ' . self::MONTHS[$rule['special_month']] . ')' : '';
            $parts[] = $range . ': ' . $amt . $sp;
            $prev = $s['upto'];
        }

        return $parts;
    }

    public static function summary(array $rule): string
    {
        return implode(' · ', self::summaryLines($rule));
    }

    /** ₹ with Indian grouping, paise only when there are any: ₹15,000 · ₹15,000.01 */
    public static function rupees(string $decimal): string
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $decimal) !== 1) {
            return $decimal;
        }
        $minor = Money::of($decimal)->minor;
        $whole = (string) intdiv($minor, 100);
        $grouped = strlen($whole) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)) . ',' . substr($whole, -3)
            : $whole;

        return '₹' . $grouped . ($minor % 100 !== 0 ? sprintf('.%02d', $minor % 100) : '');
    }

    /** A rupee figure as paise (0 when it is not one). */
    public static function minor(string $decimal): int
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $decimal) === 1 ? Money::of($decimal)->minor : 0;
    }

    /** Paise back to a decimal string: 1500001 → "15000.01". */
    public static function fmt(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
