<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * KRAS AND KPIS — Organization Setup → KRAs and KPIs, and Performance & Rewards
 * → KPI Achievements.
 *
 * THE CATALOGUE — which KRA, which KPI, its description and its calculation —
 * is config/kra-kpi.json: pure data, seeded from the collections/recovery
 * industry's own KRA/KPI matrix. Nothing here decides what a KPI IS; a
 * customer running a different vertical replaces the file, never this class.
 *
 * A TARGET IS TENANT DATA, SCOPED AND EFFECTIVE-DATED. "Collection Achievement
 * %" has one target for Hyderabad and another for the Pune branch, and a
 * target agreed in September is not the same fact as one agreed in April — so
 * a target is never edited in place (law 3): publishing closes whatever was
 * in force for that exact (KPI, company, branch, team, role, designation,
 * employee) tuple and opens a new row. The scope ladder — company, branch,
 * team, role, designation, employee — and the "blank/`All` means any"
 * convention are the platform's own, the same ones Structure, PayCycles and
 * ComponentRules already resolve by (and the exact ladder Payslip Format
 * uses), so a role-scoped target beats a branch-scoped one beats a
 * tenant-wide one, and two screens never disagree about which target won.
 * Widened from company/branch/role alone to the full six-part ladder on the
 * owner's instruction (19 Sep): the original design deliberately left out
 * team/designation/employee, reasoning a KPI target is set for "a slice of
 * the organisation, never one named employee" — the owner asked for exactly
 * that, so it is no longer true, and every existing target (company/branch/
 * role only) keeps meaning precisely what it always did, since the new
 * columns are simply blank ("any") for it.
 *
 * A SCOPE TUPLE MAY CARRY A NAME (`scope_name`) — purely a label, never part
 * of matching. Every KPI row published for one exact tuple in the same
 * `publish()` call shares that name, and `savedScopes()` groups the
 * currently-in-force rows back into one entry per tuple so the screen can
 * list them — "Hyderabad Collections Team", "All branches · Field Agent" —
 * the same way Payslip Format lists its named, independently-scoped formats,
 * so an admin picks a saved scope by name instead of reconstructing six
 * dropdowns from memory. The MATCHING itself (`winningRows()`, most specific
 * wins) is completely unaware of names — two tuples that happen to be scoped
 * identically still resolve exactly as they always would, named or not.
 *
 * AN ACHIEVEMENT ENTRY IS A LEDGER, NEVER OVERWRITTEN — exactly like
 * `Points`'s points_ledger. Recording the same employee/KPI/period again does
 * not replace the earlier entry; it adds one, so a correction is visible as a
 * correction rather than a fact that silently changed.
 *
 * EVERY ACHIEVED FIGURE CARRIES THE TARGET IT WAS MEASURED AGAINST (law 4):
 * the target in force is resolved and FROZEN onto the entry at the moment it
 * is recorded, never looked up again later — so a target changed in October
 * does not silently rewrite what September's percentage meant, and the
 * `working` text says exactly which target, whose scope, and what arithmetic
 * produced the percentage shown.
 *
 * THE ORG NAMES (an employee's own company/branch/role, as words) are
 * resolved by the CALLER, not here — `orgValueOf()` in public/app.php already
 * does exactly this lookup for every other scoped screen, and a second copy
 * of it in this class is a second place it can drift from that one.
 *
 * "KEPT" IS A ROW, NOT A COLUMN: publishing is a FULL REPLACE of one exact
 * scope tuple — every row open for it is closed, and only the KPI codes the
 * admin ticked come back open, with a target number or with none yet
 * (`target_value` is nullable). A KPI is "kept" for a scope precisely when a
 * currently in-force row exists for it there, number or not; `kpisEnabledFor()`
 * and `resolveTarget()` share one scope-matching pass (`winningRows()`) so the
 * KPI Achievements dropdown and the percentage math can never disagree about
 * which target — or whether any — applies to an employee.
 *
 * NOT EVERY KPI IS TARGETED AND ACHIEVED AGAINST ITSELF. The source matrix
 * itself, in a handful of places, spells out a triplet: a planned/assigned
 * figure (e.g. "Field Visits Assigned"), the actual outcome (e.g. "Field
 * Visits Completed"), and a percentage whose own formula is exactly
 * `outcome ÷ planned × 100` (e.g. "Visit Completion %"). Letting all three be
 * independently targeted and recorded would mean the percentage is either a
 * second, driftable copy of arithmetic the system already does, or a number
 * someone has to enter that could disagree with the two it is supposed to be
 * computed from. So a catalogue row optionally carries a `role`:
 * `'target'` (Organization Setup ONLY — this is the planned figure), or
 * `'achievement'` (Performance ONLY, and `measures_against` names the
 * `'target'`-role KPI its percentage is worked out against — never a target
 * published under its own code), or `'computed'` (neither screen — the
 * catalogue already explains its formula, and its value is exactly the
 * `'achievement'` row's % Achieved once that is recorded, so a separate copy
 * would be redundant, per the KRA/KPI matrix's own arithmetic, not a guess).
 * A row with NO `role` is `'standalone'`, the ORIGINAL behaviour every KPI
 * had before this distinction existed: targetable in Organization Setup AND
 * recordable in Performance against its own target. This is deliberately
 * the default for the great majority of the 175-row matrix — splitting a
 * ratio KPI only happens where its own formula's two components are BOTH
 * already separate rows in the same KRA; everywhere else, inventing a
 * pairing would be a guess this class has no basis for making.
 */
final class KraKpi
{
    public const TARGETS = 'kra_kpi_targets';
    public const ACHIEVEMENTS = 'kra_kpi_achievements';
    private const ROLE_TARGET = 'target';
    private const ROLE_ACHIEVEMENT = 'achievement';
    private const ROLE_COMPUTED = 'computed';

    /** @var array<string, mixed>|null */
    private ?array $catalogue = null;

    public function __construct(
        private readonly RowStore $targetsStore,
        private readonly RowStore $achievementsStore,
        private readonly string $catalogueFile,
    ) {
    }

    /** @return array<string, mixed> */
    public function catalogue(): array
    {
        return $this->catalogue ??= (array) json_decode((string) file_get_contents($this->catalogueFile), true);
    }

    /** Every KPI, keyed by its stable code, in file order. @return array<string, array<string, string>> */
    public function kpis(): array
    {
        $byCode = [];

        foreach ((array) ($this->catalogue()['kpis'] ?? []) as $row) {
            $byCode[(string) $row['code']] = $row;
        }

        return $byCode;
    }

    /**
     * The KPIs grouped by KRA, in file order — the full catalogue, every row,
     * every role. Used for name lookups and the audit trail; the two screens
     * that let somebody DO something with a KPI use `configurableByKra()` or
     * `achievableByKra()` instead, which leave out the rows that role makes
     * off-limits there.
     *
     * @return list<array{kra: string, items: list<array<string, string>>}>
     */
    public function byKra(): array
    {
        return $this->groupedByKra(static fn (array $row): bool => true);
    }

    /**
     * The KPIs Organization Setup's KRAs and KPIs screen may Keep and set a
     * Target for — every KPI except `'achievement'`- and `'computed'`-role
     * rows. An achievement KPI's number comes from recording it in
     * Performance, never from a target published under its own code; a
     * computed KPI is arithmetic over two other rows this same screen already
     * carries a target and an achievement for.
     *
     * @return list<array{kra: string, items: list<array<string, string>>}>
     */
    public function configurableByKra(): array
    {
        return $this->groupedByKra(fn (array $row): bool
            => !in_array($this->roleOf($row), [self::ROLE_ACHIEVEMENT, self::ROLE_COMPUTED], true));
    }

    /**
     * The KPIs KPI Achievements may record an Achieved value against — every
     * KPI except `'target'`- and `'computed'`-role rows. A target-role KPI IS
     * the plan, never something achieved against itself; a computed-role KPI
     * is arithmetic over two other rows, not a figure anybody enters.
     *
     * @return list<array{kra: string, items: list<array<string, string>>}>
     */
    public function achievableByKra(): array
    {
        return $this->groupedByKra(fn (array $row): bool
            => !in_array($this->roleOf($row), [self::ROLE_TARGET, self::ROLE_COMPUTED], true));
    }

    /** @param \Closure(array<string, string>): bool $keep */
    private function groupedByKra(\Closure $keep): array
    {
        $groups = [];

        foreach ((array) ($this->catalogue()['kpis'] ?? []) as $row) {
            if (!$keep($row)) {
                continue;
            }

            $kra = (string) $row['kra'];
            $groups[$kra] ??= ['kra' => $kra, 'items' => []];
            $groups[$kra]['items'][] = $row;
        }

        return array_values($groups);
    }

    /**
     * A catalogue row's role: `'target'`, `'achievement'`, `'computed'`, or
     * `''` for the default, "standalone" (targetable AND achievable against
     * its own code — every KPI's original behaviour). See the class doc
     * comment for what each means and why most rows carry none at all.
     */
    private function roleOf(array $row): string
    {
        return (string) ($row['role'] ?? '');
    }

    /**
     * The KPI code whose PUBLISHED TARGET actually governs this one — itself,
     * unless the catalogue names a different KPI to measure against (an
     * `'achievement'`-role row paired with a `'target'`-role row).
     */
    private function targetKpiFor(string $kpiCode): string
    {
        $measuresAgainst = (string) ($this->kpis()[$kpiCode]['measures_against'] ?? '');

        return $measuresAgainst !== '' ? $measuresAgainst : $kpiCode;
    }

    /**
     * The `'achievement'`-role KPIs' display names that measure against a
     * given `'target'`-role code — what the Organization Setup screen notes
     * under a target so the admin can see what publishing it here also feeds
     * in Performance, without having to remember the pairing.
     *
     * @return list<string>
     */
    public function achievementsMeasuring(string $targetCode): array
    {
        $names = [];

        foreach ($this->kpis() as $code => $row) {
            if ($this->roleOf($row) === self::ROLE_ACHIEVEMENT
                && (string) ($row['measures_against'] ?? '') === $targetCode) {
                $names[] = (string) $row['kpi'];
            }
        }

        return $names;
    }

    /** 'All' (any case) IS blank — the same word Structure/PayCycles/ComponentRules read as "any". */
    public static function any(string $value): string
    {
        $value = trim($value);

        return strcasecmp($value, 'All') === 0 ? '' : $value;
    }

    /** A one-line description of who a scope tuple applies to, for a tuple with no name of its own. Same shape as `PayslipFormats::describe()`. */
    public static function describe(array $scope): string
    {
        $parts = array_values(array_filter([
            (string) ($scope['scope_company'] ?? ''), (string) ($scope['scope_branch'] ?? ''),
            (string) ($scope['scope_team'] ?? ''), (string) ($scope['scope_role'] ?? ''),
            (string) ($scope['scope_designation'] ?? ''), (string) ($scope['scope_employee'] ?? ''),
        ]));

        return $parts === [] ? 'Everybody' : implode(' · ', $parts);
    }

    // ------------------------------------------------------------ targets --

    /** @return list<array<string, mixed>> every target ever published, newest first */
    public function allTargets(): array
    {
        $rows = $this->targetsStore->all(self::TARGETS);

        usort($rows, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        return $rows;
    }

    /**
     * EVERY DISTINCT SCOPE TUPLE CURRENTLY IN FORCE, NAMED — the "similar to
     * Payslip" list the owner asked for (19 Sep). Payslip Format keeps a rail
     * of named, independently-scoped formats instead of one filter an admin
     * reconstructs from memory each time; this is the read side of the same
     * idea for KRA/KPI targets, and it is NOT a new storage concept — every
     * target row already carries its own scope tuple and an optional
     * `scope_name` label (see `publish()`). This groups the currently-in-force
     * rows back into one entry per tuple, most specific first, so the screen
     * can list "Hyderabad Collections Team" or "All branches · Field Agent"
     * once, instead of the admin needing to already know which company/
     * branch/team/role/designation/employee combination has targets on it.
     * A full `publish()` always replaces every row for one tuple together, so
     * every currently-in-force row sharing a tuple came from the same save
     * and carries the same name — nothing to reconcile between rows.
     *
     * @return list<array{name: string, company: string, branch: string, team: string, role: string, designation: string, employee: string, specificity: int, kpi_count: int}>
     */
    public function savedScopes(string $asOf): array
    {
        $byTuple = [];

        foreach ($this->targetsInForce($asOf) as $row) {
            $tuple = implode('|', [
                (string) ($row['scope_company'] ?? ''), (string) ($row['scope_branch'] ?? ''),
                (string) ($row['scope_team'] ?? ''), (string) ($row['scope_role'] ?? ''),
                (string) ($row['scope_designation'] ?? ''), (string) ($row['scope_employee'] ?? ''),
            ]);

            if (isset($byTuple[$tuple])) {
                $byTuple[$tuple]['kpi_count']++;

                continue;
            }

            $byTuple[$tuple] = [
                'name' => trim((string) ($row['scope_name'] ?? '')),
                'company' => (string) ($row['scope_company'] ?? ''),
                'branch' => (string) ($row['scope_branch'] ?? ''),
                'team' => (string) ($row['scope_team'] ?? ''),
                'role' => (string) ($row['scope_role'] ?? ''),
                'designation' => (string) ($row['scope_designation'] ?? ''),
                'employee' => (string) ($row['scope_employee'] ?? ''),
                'specificity' => (int) ($row['specificity'] ?? 0),
                'kpi_count' => 1,
            ];
        }

        $out = array_values($byTuple);

        foreach ($out as &$entry) {
            if ($entry['name'] === '') {
                $entry['name'] = self::describe([
                    'scope_company' => $entry['company'], 'scope_branch' => $entry['branch'],
                    'scope_team' => $entry['team'], 'scope_role' => $entry['role'],
                    'scope_designation' => $entry['designation'], 'scope_employee' => $entry['employee'],
                ]);
            }
        }

        unset($entry);

        usort($out, static fn (array $a, array $b): int
            => ($b['specificity'] <=> $a['specificity']) ?: strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array<string, mixed>> targets in force on a date, most specific first */
    public function targetsInForce(string $asOf): array
    {
        $rows = array_values(array_filter(
            $this->targetsStore->all(self::TARGETS),
            static function (array $r) use ($asOf): bool {
                $from = (string) ($r['effective_from'] ?? '');
                $to = (string) ($r['effective_to'] ?? '');

                return $from !== '' && $from <= $asOf && ($to === '' || $to >= $asOf);
            },
        ));

        // MOST SPECIFIC FIRST, same convention as ComponentRules::inForce() — the
        // count of scope columns set, so a role-scoped target beats a
        // branch-scoped one beats a tenant-wide one.
        usort($rows, static fn (array $a, array $b): int
            => ((int) ($b['specificity'] ?? 0) <=> (int) ($a['specificity'] ?? 0))
                ?: strcmp((string) ($a['kpi_code'] ?? ''), (string) ($b['kpi_code'] ?? '')));

        return $rows;
    }

    /**
     * The targets currently open for an EXACT scope tuple — what the setup
     * screen pre-fills its checkboxes and target boxes with when editing that
     * tuple. Deliberately not the merged/most-specific resolution
     * `resolveTarget()` answers: editing "Pune's targets" means editing rows
     * scoped exactly to Pune, not whatever currently applies to Pune through
     * inheritance from a tenant-wide row. A code present here (even with '')
     * is "kept" for this exact scope — the value is '' when kept but no
     * number has been agreed yet.
     *
     * @return array<string, string> kpi_code => target_value ('' if none set)
     */
    public function targetsForExactScope(
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $employee,
        string $asOf,
    ): array {
        $company = self::any($company);
        $branch = self::any($branch);
        $team = self::any($team);
        $role = self::any($role);
        $designation = self::any($designation);
        $employee = self::any($employee);
        $out = [];

        foreach ($this->targetsInForce($asOf) as $row) {
            if (self::any((string) ($row['scope_company'] ?? '')) === $company
                && self::any((string) ($row['scope_branch'] ?? '')) === $branch
                && self::any((string) ($row['scope_team'] ?? '')) === $team
                && self::any((string) ($row['scope_role'] ?? '')) === $role
                && self::any((string) ($row['scope_designation'] ?? '')) === $designation
                && self::any((string) ($row['scope_employee'] ?? '')) === $employee) {
                $out[(string) ($row['kpi_code'] ?? '')] = (string) ($row['target_value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * Publish the KEPT KPIs for ONE scope tuple (company/branch/team/role/
     * designation/employee — each '' or 'All' meaning any). This is a FULL
     * REPLACE of that exact scope tuple, not a merge: every row currently
     * open for it is closed the day before (never edited in place — law 3),
     * and only the codes in `$keptCodes` come back open. Unchecking a KPI and
     * re-publishing is how it stops being kept for this scope. A kept code
     * with no number in `$targets` (or a blank one) still publishes —
     * `target_value` is null — so "kept" and "has a target" are two separate
     * facts: the KPI shows up for this scope on the Achievements screen
     * either way, but recording against it is refused until a number is set
     * (see `resolveTarget()`). `$name` is purely a label for this tuple (see
     * `savedScopes()`) — never part of matching.
     *
     * @param list<string> $keptCodes KPI codes checked "keep" for this scope
     * @param array<string, string> $targets kpi_code => target value (decimal string); blank means kept, no number yet
     * @return array{published: int, closed: int, refused: list<string>}
     */
    public function publish(
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $employee,
        array $keptCodes,
        array $targets,
        string $effectiveFrom,
        string $by,
        string $note = '',
        string $name = '',
    ): array {
        $company = self::any($company);
        $branch = self::any($branch);
        $team = self::any($team);
        $role = self::any($role);
        $designation = self::any($designation);
        $employee = self::any($employee);
        $name = trim($name);
        $known = $this->kpis();
        $refused = [];
        $toPublish = [];

        foreach (array_unique(array_map('strval', $keptCodes)) as $code) {
            if (!isset($known[$code])) {
                $refused[] = "'{$code}' is not a KPI in config/kra-kpi.json.";

                continue;
            }

            $codeRole = $this->roleOf($known[$code]);

            if ($codeRole === self::ROLE_ACHIEVEMENT) {
                $refused[] = "{$known[$code]['kpi']} is recorded in Performance & Rewards, "
                    . 'measured against its own target there — it is never targeted directly here.';

                continue;
            }

            if ($codeRole === self::ROLE_COMPUTED) {
                $refused[] = "{$known[$code]['kpi']} is calculated ({$known[$code]['formula']}) from "
                    . 'other KPIs already targeted and recorded here — it is never targeted or '
                    . 'recorded on its own.';

                continue;
            }

            $raw = trim((string) ($targets[$code] ?? ''));
            // A person naturally types a rate/currency target with its unit still on it
            // (60%, 1,200) — strip that formatting before validating. The KPI's own
            // description/formula already says what the number means; it is always
            // stored as a plain decimal here.
            $value = rtrim(str_replace(',', '', $raw), '%');

            if ($value !== '' && preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
                $refused[] = "{$known[$code]['kpi']}: '{$raw}' is not a plain positive number.";

                continue;
            }

            $toPublish[$code] = $value === '' ? null : $value;
        }

        if ($refused !== []) {
            // NOTHING IS PUBLISHED WHILE ANY ENTERED VALUE IS INVALID — a partial
            // publish would leave this scope's targets neither the old set nor
            // the new one, and nobody could say which KPIs were meant.
            return ['published' => 0, 'closed' => 0, 'refused' => $refused];
        }

        $closesOn = (new \DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');
        $closed = 0;

        // FULL REPLACE: every row open for this exact scope tuple closes,
        // whether or not its KPI is being kept going forward — that is what
        // makes unchecking a KPI actually remove it, not just leave it be.
        foreach ($this->targetsStore->all(self::TARGETS) as $row) {
            if ((string) ($row['effective_to'] ?? '') !== ''
                || self::any((string) ($row['scope_company'] ?? '')) !== $company
                || self::any((string) ($row['scope_branch'] ?? '')) !== $branch
                || self::any((string) ($row['scope_team'] ?? '')) !== $team
                || self::any((string) ($row['scope_role'] ?? '')) !== $role
                || self::any((string) ($row['scope_designation'] ?? '')) !== $designation
                || self::any((string) ($row['scope_employee'] ?? '')) !== $employee) {
                continue;
            }

            $this->targetsStore->put(self::TARGETS, [
                'id' => $row['id'],
                'effective_to' => $closesOn,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $closed++;
        }

        // SPECIFICITY IS DERIVED, NEVER TYPED — one fact stored twice is one
        // copy that decides which target wins while being wrong.
        $specificity = count(array_filter(
            [$company, $branch, $team, $role, $designation, $employee],
            static fn (string $v): bool => $v !== '',
        ));

        foreach ($toPublish as $code => $value) {
            $this->targetsStore->put(self::TARGETS, [
                'kpi_code' => $code,
                'scope_company' => $company === '' ? null : $company,
                'scope_branch' => $branch === '' ? null : $branch,
                'scope_team' => $team === '' ? null : $team,
                'scope_role' => $role === '' ? null : $role,
                'scope_designation' => $designation === '' ? null : $designation,
                'scope_employee' => $employee === '' ? null : $employee,
                'scope_name' => $name === '' ? null : $name,
                'specificity' => $specificity,
                'target_value' => $value,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'published_by' => $by,
                'note' => $note,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['published' => count($toPublish), 'closed' => $closed, 'refused' => []];
    }

    /**
     * The winning row for every KPI code that has one in force for this
     * employee's scope — most specific match wins, exactly once per code
     * (`targetsInForce()` is already sorted most-specific-first, so the
     * first match for a code is its winner; `isset()` skips a code once it
     * has one, so a less-specific row for the same code that does NOT match
     * this scope is correctly tried next rather than the code being given
     * up on). Shared by `resolveTarget()` (one KPI) and `kpisEnabledFor()`
     * (every kept KPI) so the two can never disagree about which target, or
     * whether any, applies.
     *
     * @return array<string, array<string, mixed>> kpi_code => winning row
     */
    private function winningRows(
        string $asOf,
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $employee,
    ): array {
        $winners = [];

        foreach ($this->targetsInForce($asOf) as $row) {
            $code = (string) ($row['kpi_code'] ?? '');

            if (isset($winners[$code])) {
                continue; // a more specific row for this code already matched
            }

            $wantCompany = (string) ($row['scope_company'] ?? '');
            $wantBranch = (string) ($row['scope_branch'] ?? '');
            $wantTeam = (string) ($row['scope_team'] ?? '');
            $wantRole = (string) ($row['scope_role'] ?? '');
            $wantDesignation = (string) ($row['scope_designation'] ?? '');
            $wantEmployee = (string) ($row['scope_employee'] ?? '');

            if (($wantCompany === '' || strcasecmp($wantCompany, $company) === 0)
                && ($wantBranch === '' || strcasecmp($wantBranch, $branch) === 0)
                && ($wantTeam === '' || strcasecmp($wantTeam, $team) === 0)
                && ($wantRole === '' || strcasecmp($wantRole, $role) === 0)
                && ($wantDesignation === '' || strcasecmp($wantDesignation, $designation) === 0)
                && ($wantEmployee === '' || strcasecmp($wantEmployee, $employee) === 0)) {
                $winners[$code] = $row;
            }
        }

        return $winners;
    }

    /**
     * The target in force for a KPI, given an employee's own company/branch/role
     * NAMES. For an `'achievement'`-role KPI this resolves the KPI it
     * `measures_against`, never a target published under its own code — a
     * publish() refusal is what stops one existing anyway, this is the read
     * side of the same rule. Null both when nobody has configured a target
     * that covers this employee at all, AND when the KPI is kept for this
     * scope but no number has been agreed yet — either way there is nothing
     * to measure an achievement against.
     *
     * @return array{target_value: string, scope_company: string, scope_branch: string, scope_team: string, scope_role: string, scope_designation: string, scope_employee: string}|null
     */
    public function resolveTarget(
        string $kpiCode,
        string $asOf,
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $employee,
    ): ?array {
        $row = $this->winningRows($asOf, $company, $branch, $team, $role, $designation, $employee)[$this->targetKpiFor($kpiCode)] ?? null;

        if ($row === null) {
            return null;
        }

        $targetValue = (string) ($row['target_value'] ?? '');

        if ($targetValue === '') {
            return null; // kept for this scope, but no number set yet
        }

        return [
            'target_value' => $targetValue,
            'scope_company' => (string) ($row['scope_company'] ?? ''),
            'scope_branch' => (string) ($row['scope_branch'] ?? ''),
            'scope_team' => (string) ($row['scope_team'] ?? ''),
            'scope_role' => (string) ($row['scope_role'] ?? ''),
            'scope_designation' => (string) ($row['scope_designation'] ?? ''),
            'scope_employee' => (string) ($row['scope_employee'] ?? ''),
        ];
    }

    /**
     * The KPI codes KEPT for an employee's scope — i.e. offered by
     * `achievableByKra()` AND whose governing target (`targetKpiFor()`,
     * itself for a standalone KPI, the paired target for an achievement KPI)
     * has a currently in-force row, number set or not. This is what the KPI
     * Achievements screen's dropdown filters to: an admin ticks a KPI's
     * "keep" box under Organization Setup, and only ticked KPIs — or KPIs
     * measured against a ticked one — are ever a choice for that profile,
     * whether or not a target number has been agreed for it yet.
     *
     * @return list<string>
     */
    public function kpisEnabledFor(
        string $asOf,
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $employee,
    ): array {
        $winners = $this->winningRows($asOf, $company, $branch, $team, $role, $designation, $employee);
        $enabled = [];

        foreach ($this->kpis() as $code => $row) {
            if (in_array($this->roleOf($row), [self::ROLE_TARGET, self::ROLE_COMPUTED], true)) {
                continue; // never a choice on the Achievements screen
            }

            if (isset($winners[$this->targetKpiFor($code)])) {
                $enabled[] = $code;
            }
        }

        return $enabled;
    }

    // ------------------------------------------------------- achievements --

    /** @return list<array<string, mixed>> every entry, newest first */
    public function achievements(): array
    {
        $rows = $this->achievementsStore->all(self::ACHIEVEMENTS);

        usort($rows, static fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        return $rows;
    }

    /**
     * Record one achievement entry. The target is resolved against
     * `$periodTo` and FROZEN onto the row now (law 4) — a target changed
     * later must not silently rewrite what this entry's percentage meant. An
     * entry is never edited: recording the same employee/KPI/period again
     * adds a new row, exactly like the Points ledger.
     *
     * @return array{ok: bool, why: string, row: array<string, mixed>|null}
     */
    public function record(
        string $employmentId,
        string $employeeCode,
        string $employeeName,
        string $kpiCode,
        string $periodFrom,
        string $periodTo,
        string $achievedValue,
        string $company,
        string $branch,
        string $team,
        string $role,
        string $designation,
        string $recordedBy,
    ): array {
        // NO SEPARATE $employee PARAMETER — the employee whose achievement this
        // is IS the employee scope value; $employeeCode already names them, so
        // asking the caller to repeat it as a scope argument would just be the
        // same fact passed twice with a chance to disagree.
        $employee = $employeeCode;
        $known = $this->kpis();

        if (!isset($known[$kpiCode])) {
            return ['ok' => false, 'why' => "'{$kpiCode}' is not a KPI in config/kra-kpi.json.", 'row' => null];
        }

        $achievedValue = trim($achievedValue);

        if (preg_match('/^-?\d+(\.\d+)?$/', $achievedValue) !== 1) {
            return ['ok' => false, 'why' => "'{$achievedValue}' is not a plain number.", 'row' => null];
        }

        if ($periodFrom === '' || $periodTo === '' || $periodTo < $periodFrom) {
            return ['ok' => false, 'why' => 'The period is not a valid range.', 'row' => null];
        }

        $target = $this->resolveTarget($kpiCode, $periodTo, $company, $branch, $team, $role, $designation, $employee);
        $targetCode = $this->targetKpiFor($kpiCode);
        $targetName = (string) ($known[$targetCode]['kpi'] ?? $known[$kpiCode]['kpi']);
        $measuredAgainst = $targetCode !== $kpiCode
            ? " — “{$known[$kpiCode]['kpi']}” is measured against “{$targetName}”’s target"
            : '';

        if ($target === null) {
            return ['ok' => false, 'why' => "No target is configured for “{$targetName}”{$measuredAgainst} "
                . 'that covers this employee. Set one first under Organization Setup / Configuration '
                . '→ KRAs and KPIs.', 'row' => null];
        }

        if (Rational::fromDecimalString($target['target_value'])->isZero()) {
            return ['ok' => false, 'why' => "The configured target for “{$targetName}”{$measuredAgainst} "
                . 'is zero, so a percentage achieved cannot be worked out. Correct the target first.',
                'row' => null];
        }

        $percent = Rational::fromDecimalString($achievedValue)
            ->dividedBy(Rational::fromDecimalString($target['target_value']))
            ->multipliedBy(Rational::of(100));
        $percentText = $percent->toDecimalString(2);
        $scopeWords = implode(', ', array_filter([
            $target['scope_company'] !== '' ? 'company ' . $target['scope_company'] : '',
            $target['scope_branch'] !== '' ? 'branch ' . $target['scope_branch'] : '',
            $target['scope_team'] !== '' ? 'team ' . $target['scope_team'] : '',
            $target['scope_role'] !== '' ? 'role ' . $target['scope_role'] : '',
            $target['scope_designation'] !== '' ? 'designation ' . $target['scope_designation'] : '',
            $target['scope_employee'] !== '' ? 'employee ' . $target['scope_employee'] : '',
        ]));
        $working = "{$achievedValue} \u{f7} {$target['target_value']} \u{d7} 100 = {$percentText}% "
            . '(target ' . ($scopeWords === '' ? 'is tenant-wide' : "scoped to {$scopeWords}") . ')';

        $row = $this->achievementsStore->put(self::ACHIEVEMENTS, [
            'employment_id' => $employmentId === '' ? null : $employmentId,
            'employee_code' => $employeeCode,
            'employee_name' => $employeeName,
            'kpi_code' => $kpiCode,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'achieved_value' => $achievedValue,
            'target_value' => $target['target_value'],
            'percent_achieved' => $percentText,
            'working' => $working,
            'recorded_by' => $recordedBy,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'why' => 'Recorded. ' . $working, 'row' => $row];
    }
}
