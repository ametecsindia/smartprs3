<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * SALARY SCHEDULE / PAY CYCLE — WHICH RULE PAYS WHOM, AND OVER WHAT WINDOW.
 *
 * Organization Setup → Salary Schedule / Pay Cycle keeps rows, each scoped to a
 * company, branch, department, designation, role, team or one employee (blank
 * = any), with a cycle (monthly / fortnightly / weekly / custom), an
 * attendance window (calendar month, 21st–20th, or custom from-day to-day)
 * and a pay day. The rows live in the tenant's Customisation under
 * config/pay-cycles.json's key.
 *
 * THE MOST SPECIFIC ROW WINS for an employee: employee > team > designation >
 * role > department > branch > company > everyone. Ties go to the lower id,
 * which is the older row — the one already in force. ROLE sits below
 * designation on purpose: several designations usually share one Role
 * (Organization Configuration's "Role / Designation Setup"), so naming a Role
 * is a coarser scope than naming the exact designation.
 *
 * A window can close in a later calendar month than the one it pays for — the
 * custom cutoff 22 → 21 closes September's window on the 21st, but the people
 * living that cycle are paid on 1 October. `pay_day` alone cannot say that: it
 * is a day of the month with no month attached. `pay_month_offset` (0 by
 * default, so every rule saved before this existed keeps paying exactly where
 * it always did) is how many months after the period itself `pay_day` falls.
 */
final class PayCycles
{
    public const SCOPES = ['employee', 'team', 'designation', 'role', 'department', 'branch', 'company'];

    /** @var array<string, array<string, string>>|null */
    private ?array $names = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $configFile,
    ) {
    }

    public function declared(): array
    {
        return json_decode((string) file_get_contents($this->configFile), true) ?: [];
    }

    public function key(): string
    {
        return (string) ($this->declared()['customisation_key'] ?? 'pay-cycles');
    }

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return array_values((array) ($this->customisation->live()[$this->key()]['rules'] ?? []));
    }

    /** The value an employee holds for a scope, as a NAME — by id through the org registers, else the legacy text column. */
    public function valueOf(array $e, string $key): string
    {
        if ($key === 'employee') {
            return Employment::code($e);
        }

        if ($this->names === null) {
            $this->names = [];

            foreach (['companies', 'branches', 'departments', 'designations', 'teams'] as $t) {
                try {
                    foreach ($this->store->all($t) as $r) {
                        if (!Modules::isRetired($r)) {
                            $this->names[$t][(string) ($r['id'] ?? '')] = trim((string) ($r['name'] ?? ''));

                            if ($t === 'designations') {
                                // ROLE'S REGISTER IS "Role / Designation Setup"
                                // itself — its own free-text Role column, keyed
                                // by designation id, exactly like orgValueOf()
                                // in public/app.php reads it. Never the
                                // employees.role access tier.
                                $this->names['designation_roles'][(string) ($r['id'] ?? '')] = trim((string) ($r['role'] ?? ''));
                            }
                        }
                    }
                } catch (\Throwable) { // gate:allow-swallow a register this installation does not have yet names nobody
                }
            }
        }

        if ($key === 'role') {
            return $this->names['designation_roles'][(string) ($e['designation_id'] ?? '')] ?? '';
        }

        $table = ['company' => 'companies', 'branch' => 'branches', 'department' => 'departments', 'designation' => 'designations', 'team' => 'teams'][$key] ?? '';
        $byId = $this->names[$table][(string) ($e[$key === 'company' ? 'employer_company_id' : $key . '_id'] ?? '')] ?? '';

        return $byId !== '' ? $byId : trim((string) ($e[$key] ?? ''));
    }

    /** Whether a rule's scope covers an employee. Blank scope fields are "any". */
    public function covers(array $rule, array $e): bool
    {
        foreach (self::SCOPES as $k) {
            $want = trim((string) ($rule[$k] ?? ''));

            if ($want !== '' && strcasecmp($want, $this->valueOf($e, $k)) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** The rule in force for an employee, or null when nobody declared one. */
    public function for(array $e): ?array
    {
        $best = null;
        $bestRank = -1;

        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? 'active') !== 'active' || !$this->covers($rule, $e)) {
                continue;
            }

            // Specificity: the narrowest scope the rule names.
            $rank = 0;

            foreach (array_reverse(self::SCOPES) as $i => $k) {
                if (trim((string) ($rule[$k] ?? '')) !== '') {
                    $rank = $i + 1;
                }
            }

            if ($rank > $bestRank || ($rank === $bestRank && (int) $rule['id'] < (int) $best['id'])) {
                $best = $rule;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /** How many of the given employees a rule is the winning rule for. */
    public function reach(array $rule, array $employments): int
    {
        $n = 0;

        foreach ($employments as $e) {
            if ((string) ($this->for($e)['id'] ?? '') === (string) $rule['id']) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The attendance window a rule gives a payroll period (YYYY-MM).
     * calendar → 1st to month end; "20" → 21st of the previous month to the 20th;
     * custom → from_day of the previous month (or this month when from ≤ to) to to_day.
     *
     * @return array{0: string, 1: string} from, to (Y-m-d)
     */
    public static function window(array $rule, string $period): array
    {
        $first = $period . '-01';
        $last = date('Y-m-t', (int) strtotime($first));
        $cutoff = (string) ($rule['cutoff'] ?? 'calendar');
        $fromDay = (int) ($rule['from_day'] ?? 0);
        $toDay = (int) ($rule['to_day'] ?? 0);

        if ($cutoff === 'custom' && $fromDay >= 1 && $toDay >= 1) {
            // 26 → 25: the 26th of last month to the 25th of this. 1 → 31: this month.
            $to = self::clampDay($period, $toDay);
            $from = $fromDay > $toDay ? self::clampDay(date('Y-m', strtotime($first . ' -1 month')), $fromDay) : self::clampDay($period, $fromDay);

            return [$from, $to];
        }

        if (ctype_digit($cutoff)) {
            $n = (int) $cutoff;

            return [date('Y-m-d', strtotime(self::clampDay(date('Y-m', strtotime($first . ' -1 month')), $n) . ' +1 day')), self::clampDay($period, $n)];
        }

        return [$first, $last];
    }

    /** A day of a month, never past the month's end (31 in February is the 28th/29th). */
    private static function clampDay(string $yearMonth, int $day): string
    {
        $lastDay = (int) date('t', (int) strtotime($yearMonth . '-01'));

        return $yearMonth . '-' . str_pad((string) min($day, $lastDay), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The calendar date an employee on this rule is actually paid, for a payroll
     * period (YYYY-MM). `pay_day` is a day of the month; `pay_month_offset`
     * (default 0, so a rule saved before this existed pays exactly where it
     * always did) is how many months after the period that day falls — 1 turns
     * the window 22 Aug → 21 Sep (September's salary) into a 1 October pay date.
     * The day clamps to the target month's length, same as the window does.
     */
    public static function payDate(array $rule, string $period): string
    {
        $offset = max(0, (int) ($rule['pay_month_offset'] ?? 0));
        $month = date('Y-m', (int) strtotime($period . '-01 +' . $offset . ' months'));

        return self::clampDay($month, max(1, (int) ($rule['pay_day'] ?? 1)));
    }
}
