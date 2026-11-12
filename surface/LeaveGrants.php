<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * A LEAVE GRANT IS A RULE ABOUT A GROUP, AND THE LEDGER IS WHERE IT LANDS.
 *
 * "Give the Mumbai branch 12 casual leaves a year, not carried forward" is one
 * sentence of policy. In SmartPRS 2 it was twelve edits to twelve employee rows,
 * and the thirteenth person, who joined in June, got nothing. Here it is one row
 * under Organization Setup → Leave Types → Grants, scoped by any of company,
 * branch, department, designation, team or a single employee, and APPLIED to the
 * ledger of every employee it matches — including the one who joins tomorrow,
 * the next time the grants are applied.
 *
 * APPLYING IS IDEMPOTENT. Every posting is `grant:<id>:<year>` for that
 * employee, so applying twice posts once. A grant changed after it was applied
 * is a NEW grant (the versioned customisation keeps the old one readable): the
 * days already on the ledger are not silently rewritten — law 3.
 *
 * THE GRANT IS CONFIGURATION, saved through Customisation like every other
 * setting, so it is versioned, effective-dated by its save, and never a PHP
 * change. The postings it makes are ledger rows, which is where a balance is
 * allowed to come from.
 *
 * ponytail: a grant is a whole-year figure posted on 1 January (or on the joining
 * date for a joiner that year). No pro-rata for a mid-year joiner — that is a
 * `prorate: true` field before it is code.
 */
final class LeaveGrants
{
    public const KEY = 'leave-grants';

    /** The scopes a grant may name. Each is an `employees` column or the company link. */
    public const SCOPES = ['company', 'branch', 'department', 'designation', 'team', 'employee'];

    public function __construct(
        private readonly Customisation $customisation,
        private readonly LeavePolicy $policy,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $saved = $this->customisation->live()[self::KEY]['grants'] ?? [];

        return is_array($saved) ? array_values($saved) : [];
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $g) {
            if ((string) $g['id'] === $id) {
                return $g;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->customisation->history(self::KEY);
    }

    /**
     * Add a grant. Nothing is posted yet: `apply()` does that, so a wrong grant
     * can be withdrawn before it reaches a ledger.
     *
     * @param array<string, string> $input type, days, carry_forward, from_year, note, and the SCOPES
     * @return array{ok: bool, why: string, grant?: array<string, mixed>}
     */
    public function add(array $input, string $byId, string $byLabel): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));

        if (!isset($this->policy->types()[$type])) {
            return ['ok' => false, 'why' => "'{$type}' is not one of this company's leave types. Add it under Leave Types first."];
        }

        $days = trim((string) ($input['days'] ?? ''));

        try {
            $d = Rational::fromDecimalString($days === '' ? 'x' : $days);
        } catch (\Throwable) {
            return ['ok' => false, 'why' => "'{$days}' is not a number of days."];
        }

        if ($d->isZero() || str_starts_with($days, '-')) {
            return ['ok' => false, 'why' => 'A grant is a positive number of days.'];
        }

        $scope = [];

        foreach (self::SCOPES as $s) {
            $v = trim((string) ($input[$s] ?? ''));

            if ($v !== '') {
                $scope[$s] = $v;
            }
        }

        $year = trim((string) ($input['from_year'] ?? date('Y')));

        if (preg_match('/^20\d\d$/', $year) !== 1) {
            return ['ok' => false, 'why' => "'{$year}' is not a year."];
        }

        $grants = $this->all();
        $max = 0;

        foreach ($grants as $g) {
            $max = max($max, (int) $g['id']);
        }

        $grant = [
            'id' => (string) ($max + 1),
            'type' => $type,
            'days' => $days,
            'carry_forward' => in_array(strtolower((string) ($input['carry_forward'] ?? 'yes')), ['yes', '1', 'true', 'on'], true),
            'scope' => $scope,
            'from_year' => $year,
            'note' => trim((string) ($input['note'] ?? '')),
            'added_by' => $byLabel,
            'added_on' => date('Y-m-d'),
            'active' => true,
        ];
        $grants[] = $grant;
        $saved = $this->customisation->saveRaw(self::KEY, ['grants' => $grants], $byId, $byLabel, 'Leave grant added: ' . self::describe($grant));

        return $saved['ok'] ? ['ok' => true, 'grant' => $grant, 'why' => 'Grant added: ' . self::describe($grant) . '. Nothing is on any ledger until you apply it.'] : $saved;
    }

    /** Withdraw: the grant stops applying to future years; what it posted stays on the ledgers. */
    public function withdraw(string $id, string $byId, string $byLabel): array
    {
        $grants = $this->all();
        $found = false;

        foreach ($grants as $i => $g) {
            if ((string) $g['id'] === $id) {
                $grants[$i]['active'] = false;
                $grants[$i]['withdrawn_by'] = $byLabel;
                $grants[$i]['withdrawn_on'] = date('Y-m-d');
                $found = true;
            }
        }

        if (!$found) {
            return ['ok' => false, 'why' => 'No such grant.'];
        }

        $saved = $this->customisation->saveRaw(self::KEY, ['grants' => $grants], $byId, $byLabel, "Leave grant {$id} withdrawn");

        return $saved['ok'] ? ['ok' => true, 'why' => "Grant {$id} withdrawn. Days it already posted stay on the ledgers — a balance is never taken back by a setting."] : $saved;
    }

    /**
     * Does this employee fall inside the grant's scope? Every named scope
     * must match; an unnamed one matches everybody.
     *
     * @param array<string, string> $companyNames company id => name
     */
    public static function matches(array $grant, array $employment, array $companyNames): bool
    {
        foreach ((array) ($grant['scope'] ?? []) as $s => $want) {
            $have = match ((string) $s) {
                'company' => (string) ($companyNames[(string) ($employment['employer_company_id'] ?? '')] ?? ($employment['company'] ?? '')),
                'employee' => Employment::code($employment),
                default => (string) ($employment[(string) $s] ?? ''),
            };

            if (strcasecmp(trim($have), trim((string) $want)) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Who a grant reaches, from a list of employees.
     *
     * @param ?LeavePolicy $policy when given, an employee the grant's TYPE does
     *     not apply to — wrong marital status, or outside the type's own
     *     company/branch/role/designation scope — is excluded here too, not
     *     just from the grant's own scope. This is how "Maternity Leave only
     *     for Married employees" holds even when a grant is scoped broadly
     *     (a whole branch, say): the grant's scope says WHO was meant to
     *     receive it, the type's scope says who the type allows at all, and
     *     both have to say yes.
     * @return list<array<string, mixed>>
     */
    public static function reach(array $grant, array $employments, array $companyNames, ?LeavePolicy $policy = null): array
    {
        return array_values(array_filter($employments,
            static fn (array $e): bool => Employment::code($e) !== '' && self::matches($grant, $e, $companyNames)
                && ($policy === null || $policy->eligible((string) $grant['type'], $e))));
    }

    /**
     * Post every active grant to every employee it matches, for one year.
     *
     * @return array{posted: int, already: int, skipped: int, notes: list<string>}
     */
    public function apply(string $year, array $employments, array $companyNames, Leave $leave): array
    {
        $posted = 0;
        $already = 0;
        $skipped = 0;
        $notes = [];

        foreach ($this->all() as $g) {
            if (!($g['active'] ?? true) || (string) $g['from_year'] > $year) {
                $skipped++;

                continue;
            }

            foreach (self::reach($g, $employments, $companyNames, $this->policy) as $e) {
                $code = Employment::code($e);
                $joined = Employment::joinedOn($e);
                // A joiner AFTER this year has nothing to receive; one who joined
                // during it receives from their joining date.
                if ($joined !== '' && substr($joined, 0, 4) > $year) {
                    continue;
                }

                $on = $joined !== '' && substr($joined, 0, 4) === $year ? $joined : "{$year}-01-01";
                $ok = $leave->creditEarned($code, (string) $g['type'], (string) $g['days'],
                    'Grant ' . $g['id'] . ' for ' . $year . ': ' . self::describe($g), "grant:{$g['id']}:{$year}", $on);
                $ok ? $posted++ : $already++;
            }
        }

        if ($posted === 0 && $already === 0) {
            $notes[] = 'No grant reached anybody — check the scopes against the employees\' branch, department, designation, team and company.';
        }

        return ['posted' => $posted, 'already' => $already, 'skipped' => $skipped, 'notes' => $notes];
    }

    /**
     * Year end: for grants that do NOT carry forward, lapse whatever of the
     * granted days is still unspent on 31 December. Idempotent per grant-year.
     *
     * @return array{lapsed: int, already: int, notes: list<string>}
     */
    public function lapse(string $year, array $employments, array $companyNames, Leave $leave): array
    {
        $lapsed = 0;
        $already = 0;
        $notes = [];
        $eve = new \DateTimeImmutable("{$year}-12-31");

        foreach ($this->all() as $g) {
            if ($g['carry_forward'] ?? true) {
                continue;
            }

            if ((string) $g['from_year'] > $year) {
                continue;
            }

            foreach (self::reach($g, $employments, $companyNames, $this->policy) as $e) {
                $code = Employment::code($e);
                $balance = $leave->balance($code, (string) $g['type'], $eve);

                if ($balance->isZero() || $balance->isNegative()) {
                    continue;
                }

                // Lapse the smaller of the balance and what this grant gave: a
                // balance built from several sources loses only this grant's share.
                $granted = Rational::fromDecimalString((string) $g['days']);
                $amount = $balance->compareTo($granted) < 0 ? $balance : $granted;
                $ok = $leave->lapse($code, (string) $g['type'], $amount->toDecimalString(4), "{$year}-12-31",
                    "Lapsed at year end {$year}: grant {$g['id']} does not carry forward", "lapse:{$g['id']}:{$year}");
                $ok ? $lapsed++ : $already++;
            }
        }

        return ['lapsed' => $lapsed, 'already' => $already, 'notes' => $notes];
    }

    public static function describe(array $g): string
    {
        $scope = (array) ($g['scope'] ?? []);
        $where = $scope === [] ? 'everyone' : implode(', ', array_map(static fn ($k, $v): string => "{$k} {$v}", array_keys($scope), $scope));

        return $g['days'] . ' ' . $g['type'] . ' to ' . $where . ' from ' . $g['from_year'] . ($g['carry_forward'] ?? true ? ', carried forward' : ', not carried forward');
    }
}
