<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Support\Money;

/**
 * WHAT A COMPONENT IS WORTH, AND WHO IT APPLIES TO.
 *
 * `config/salary-structure.json` declares components for the whole tenant. This
 * answers the question that file cannot: WHICH company, branch, team,
 * designation or one named employee. Conveyance differs by branch in most Indian
 * companies, and a structure that cannot say so is one every customer works
 * around with a spreadsheet nobody audits.
 *
 * THE CATALOGUE IS CONFIGURATION. Which components exist, which side of the
 * payslip each lands on, which calculations are offered and which employee
 * fields a status rule may read all come from `config/salary-components.json`.
 * They arrived as object literals inside the screen's JavaScript — a business
 * list living in code, in the one file a customer never edits.
 *
 * NOTHING HERE IS EDITED IN PLACE. Changing a rule closes the row that was in
 * force and opens a new one, so a payslip reprinted for March is computed from
 * the rule that was in force in March (law 3). The screen calls it "publish" for
 * that reason: it appends.
 *
 * IT REFUSES RATHER THAN STORES SOMETHING IT CANNOT COMPUTE. A component the
 * catalogue does not name, a percentage that is not an exact decimal, a second
 * balance component, a status rule against a column `employees` does not have —
 * each is refused with the reason, because a rule that is silently ignored at
 * payroll time is a deduction somebody thinks they configured.
 */
final class ComponentRules
{
    private const COLLECTION = 'salary_component_rules';

    /**
     * THE ONLY CATALOGUE CODES THIS BUILD KNOWS A FIXED CENTRAL/STATE-LAW
     * SHAPE FOR. "As per Government rules" on any OTHER component (Basic,
     * DA, ESI, Gratuity, …) still computes from whatever the row itself
     * declares, exactly as before this existed — see governmentComponent()
     * for why each of those is left alone rather than guessed at.
     */
    private const GOVERNMENT_CODES = ['HRA', 'PF_EMPLOYEE', 'PF_EMPLOYER', 'PT'];

    /**
     * "As per Government rules" computed OUTSIDE the structure (16 Oct): ESI needs the contribution
     * period, the ceiling and the ₹176/day exemption, which Register::governmentEsi() applies from
     * config/esi.json. A Govt. row for these needs no calculation and adds no structure line.
     */
    private const GOVERNMENT_ELSEWHERE = ['ESI_EMPLOYEE', 'ESI_EMPLOYER'];

    /**
     * PROFESSIONAL TAX IS NOT A SALARY STRUCTURE ROW (owner, 30 Oct 2026): it lives in Organization Setup →
     * Salary structure → PT Slabs. Every employee gets this one rule, priced from PT Slabs by state / company /
     * branch / gender (PtRules); PT rows published on the Salary structure before are ignored, and the next
     * publish closes them. PT Applicable = No still takes it off (StatutoryApplicability::without).
     */
    public const PT_SLABS_ID = 'pt-slabs';

    /** Section 10(13A)/Rule 2A's metro cities (50% of Basic+DA; 40% elsewhere) — settled law, not a proposal. */
    // Income-tax Rules 2026 (CBDT, 20 Mar 2026, from 1 Apr 2026) added Bengaluru, Hyderabad, Pune and Ahmedabad.
    // ponytail: one list, no date — a rerun of a month before Apr 2026 also uses 50% for those four.
    private const HRA_METRO_CITIES = ['delhi', 'new delhi', 'mumbai', 'kolkata', 'chennai',
        'bengaluru', 'bangalore', 'hyderabad', 'secunderabad', 'pune', 'ahmedabad'];

    // Professional Tax slabs: Organization Setup → PT Slabs (PtRules, 28 Oct); config/pt.json holds the
    // shipped Government schedule used until the tenant saves its own rules.

    /**
     * WHAT A MANUAL RULE'S "IF" CONDITION MAY COMPARE — owner request, 12
     * Sep 2026 ("if attendance is more than 22 days, incentive of xx
     * rupees"). Both sides are SPX symbols `SalaryComponent::NAMESPACES`
     * already declares (`period.*`); this is the allow-list of which of
     * those a non-technical Manual row may name, not a second copy of the
     * symbol table.
     */
    private const MANUAL_SYSTEM_VALUES = [
        'payable_days' => 'period.payable_days',
        'calendar_days' => 'period.calendar_days',
    ];

    /** Same set the SPX lexer itself accepts (Spx\TokenKind), offered on the Manual screen. */
    private const MANUAL_CONDITION_OPS = ['>', '>=', '<', '<=', '=', '<>'];

    /** @var array<string, mixed>|null */
    private ?array $catalogue = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
    ) {
    }

    /** @return list<array<string, mixed>> the rules in force on a date, most specific first */
    public function inForce(string $asOf): array
    {
        $rows = array_values(array_filter(
            $this->store->all(self::COLLECTION),
            static function (array $r) use ($asOf): bool {
                $from = (string) ($r['effective_from'] ?? '');
                $to = (string) ($r['effective_to'] ?? '');

                return $from !== '' && $from <= $asOf && ($to === '' || $to >= $asOf);
            },
        ));

        // MOST SPECIFIC FIRST. Specificity is the count of scope columns set, so
        // a designation rule beats a branch rule beats a tenant-wide one — the
        // same ladder Platform\Config resolves every other artefact by. Ordering
        // here rather than at each call site is what stops two screens
        // disagreeing about which rule won.
        //
        // THE NARROWEST SCOPE WINS, NOT THE MOST FILLED-IN (28 Sep). Counting
        // filled scope boxes let a company+branch rule beat a rule written for
        // one named employee — two boxes against one. Each level now outranks
        // every combination of the levels above it: employee > designation >
        // role > team > branch > company. Computed from the scope columns, so
        // rules already published re-rank without being touched.
        usort($rows, static fn (array $a, array $b): int
            => (self::narrowness($b) <=> self::narrowness($a))
                ?: strcmp((string) ($a['component_code'] ?? ''), (string) ($b['component_code'] ?? ''))
                ?: ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0)));

        return array_map(self::decoded(...), $rows);
    }

    /** How narrow a rule's scope is: one bit per level, the narrowest level highest. */
    private static function narrowness(array $rule): int
    {
        $weight = 0;
        $detail = is_array($rule['detail'] ?? null) ? $rule['detail'] : (json_decode((string) ($rule['detail'] ?? ''), true) ?: []);
        $rule['scope_department'] = (string) ($detail['scope_department'] ?? '');
        $rule['scope_city'] = (string) ($detail['scope_city'] ?? '');

        // City (16 Oct) sits between company and branch: a branch is narrower than its city.
        foreach (['scope_company' => 1, 'scope_city' => 2, 'scope_branch' => 4, 'scope_department' => 8, 'scope_team' => 16,
            'scope_role' => 32, 'scope_designation' => 64, 'scope_employee' => 128] as $column => $bit) {
            $value = trim((string) ($rule[$column] ?? ''));
            $weight += ($value !== '' && strcasecmp($value, 'All') !== 0) ? $bit : 0;
        }

        return $weight;
    }

    /** Every rule ever published, newest first — the audit trail the screen shows. */
    public function all(): array
    {
        $rows = $this->store->all(self::COLLECTION);

        usort($rows, static fn (array $a, array $b): int
            => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        return array_map(self::decoded(...), $rows);
    }

    /**
     * Publish a set of rules, effective from a date.
     *
     * Every row currently in force is CLOSED on the day before, and the new set
     * opens. Not deleted: the closed rows are how last month was computed.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{published: int, closed: int, refused: list<string>}
     */
    public function publish(array $rules, string $effectiveFrom, string $by): array
    {
        $refused = $this->refusals($rules);

        if ($refused !== []) {
            // NOTHING IS PUBLISHED WHILE ANY ROW IS INVALID. A partial publish
            // leaves a structure that is neither the old one nor the new one,
            // and nobody can say which components in it were meant.
            return ['published' => 0, 'closed' => 0, 'refused' => $refused];
        }

        $closesOn = (new \DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');
        $closed = 0;

        foreach ($this->store->all(self::COLLECTION) as $row) {
            if ((string) ($row['effective_to'] ?? '') !== '') {
                continue;
            }

            $this->store->put(self::COLLECTION, [
                'id' => $row['id'],
                'effective_to' => $closesOn,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $closed++;
        }

        $published = 0;

        foreach ($rules as $rule) {
            $component = $this->component((string) ($rule['component'] ?? ''));
            $scope = [
                'scope_company' => trim((string) ($rule['company'] ?? '')),
                'scope_branch' => trim((string) ($rule['branch'] ?? '')),
                'scope_role' => trim((string) ($rule['role'] ?? '')),
                'scope_team' => trim((string) ($rule['team'] ?? '')),
                // MULTIPLE DESIGNATIONS, ONE COLUMN — comma-joined, same as the
                // screen sends it (owner request, 18 Sep: "select multiple items
                // like Collection Executives and Field Agent"). No schema change:
                // resolveFor() below splits this back into a list for matching.
                'scope_designation' => trim((string) ($rule['designation'] ?? '')),
                'scope_employee' => trim((string) ($rule['employee'] ?? '')),
            ];

            $this->store->put(self::COLLECTION, $scope + [
                'component_code' => (string) $component['code'],
                'kind' => (string) $component['kind'],
                'calculation' => (string) ($rule['calculation'] ?? ''),
                // SPECIFICITY IS DERIVED, NEVER TYPED. A number somebody enters
                // beside a scope they also entered is one fact stored twice, and
                // the copy that is wrong is the one that decides which rule wins.
                'specificity' => count(array_filter($scope, static fn (string $v): bool => $v !== ''
                    && strcasecmp($v, 'All') !== 0)),
                'basis_column' => (string) ($rule['basis_column'] ?? ''),
                'percent_value' => self::exactOrNull($rule['percent'] ?? ''),
                'flat_amount' => self::exactOrNull($rule['amount'] ?? ''),
                'detail' => json_encode([
                    'formula' => $rule['formula'] ?? [],
                    'formula_text' => (string) ($rule['formula_text'] ?? ''),
                    // MANUAL'S N BRANCHES — owner request, 12 Sep 2026, widened
                    // to N branches same day (see manualComponent()). A plain
                    // associative bag, same as `slab`/`formula` above: this
                    // screen's own shape, translated to an engine component
                    // only at compute time.
                    'branches' => (array) ($rule['branches'] ?? []),
                    'else_result' => (array) ($rule['else_result'] ?? []),
                    'max_ot_hours' => (string) ($rule['max_ot_hours'] ?? ''),
                    'slab_basis' => (string) ($rule['slab_basis'] ?? ''),
                    'slab' => $rule['slab'] ?? [],
                    'status_field' => (string) ($rule['status_field'] ?? ''),
                    'status_condition' => (string) ($rule['status_condition'] ?? ''),
                    'status_amount' => (string) ($rule['status_amount'] ?? ''),
                    // EMPLOYEE GENDER (owner, 13 Oct): a treatment per gender — male, female,
                    // other / not set — each {mode: exempt|flat|percent_of|government, amount,
                    // percent, basis_column}. E.g. Professional Tax exempt for women.
                    'gender' => self::genderConfig((array) ($rule['gender'] ?? [])),
                    // CALC MODE LIVES HERE, NOT AS A COLUMN. It is the screen's
                    // own per-row toggle — Custom (the row's own scope and
                    // calculation apply) or As per Government rules (everything
                    // else on the row is locked, because a statutory component
                    // is not something a branch manager negotiates) — and
                    // `salary_component_rules` ships as a real table (law 2):
                    // this build adds no migration for a field a query never
                    // filters or joins on. Absent means 'custom', so a row
                    // published before this existed reads exactly as it always
                    // computed.
                    'calc_mode' => ((string) ($rule['calc_mode'] ?? '')) === 'government'
                        ? 'government' : 'custom',
                    // DEPARTMENT SCOPE (28 Sep) — kept in `detail` so no column
                    // has to be added to a live table; resolveFor() reads it.
                    'scope_department' => trim((string) ($rule['department'] ?? '')),
                    // CITY SCOPE (owner, 16 Oct) — the city of the employee's branch; kept in
                    // `detail` like department, so no column is added to a live table.
                    'scope_city' => trim((string) ($rule['city'] ?? '')),
                ], JSON_THROW_ON_ERROR),
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'published_by' => $by,
                'note' => (string) ($rule['note'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $published++;
        }

        return ['published' => $published, 'closed' => $closed, 'refused' => []];
    }

    /**
     * Everything wrong with a set of rules, in words.
     *
     * @param list<array<string, mixed>> $rules
     * @return list<string>
     */
    public function refusals(array $rules): array
    {
        $refused = [];
        $balances = [];
        $seen = [];

        foreach ($rules as $index => $rule) {
            $row = $index + 1;
            $code = (string) ($rule['component'] ?? '');
            $calculation = (string) ($rule['calculation'] ?? '');

            if ($code === '') {
                $refused[] = "Row {$row}: no component chosen.";

                continue;
            }

            if ($this->componentOrNull($code) === null) {
                $refused[] = "Row {$row}: '{$code}' is not in config/salary-components.json. A "
                    . 'component nobody declared is one payroll would silently skip.';

                continue;
            }

            // THE SAME COMPONENT, THE SAME SCOPE, TWICE. Two rules that both
            // apply cannot both win, and whichever the sort happens to return
            // first is not a decision anybody made.
            $key = $code . '|' . implode('|', [
                (string) ($rule['company'] ?? ''), (string) ($rule['city'] ?? ''), (string) ($rule['branch'] ?? ''), (string) ($rule['department'] ?? ''),
                (string) ($rule['role'] ?? ''), (string) ($rule['team'] ?? ''),
                (string) ($rule['designation'] ?? ''), (string) ($rule['employee'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                $refused[] = "Row {$row} ({$code}): the same component is already declared for the "
                    . 'same scope on row ' . $seen[$key] . '. Two rules that both apply cannot both '
                    . 'win, and whichever came first is not a decision anybody made.';
            }

            $seen[$key] = $row;

            // AS PER GOVERNMENT RULES NEEDS NO CALCULATION (owner, 16 Oct): the statute is the calculation.
            // Which components HAVE a Government rule is declared in config/salary-components.json
            // (`government`); the screen offers the choice only on those (owner, 16 Oct).
            if ((string) ($rule['calc_mode'] ?? '') === 'government') {
                if (!empty($this->componentOrNull($code)['government'])) {
                    continue;
                }
                if ($calculation === '') {
                    $refused[] = "Row {$row} ({$code}): there is no Government rule for this component — choose Custom and a calculation.";

                    continue;
                }
            }

            if ($calculation === '') {
                $refused[] = "Row {$row} ({$code}): no calculation chosen, so there is nothing to "
                    . 'compute.';

                continue;
            }

            if (!isset($this->calculations()[$calculation])) {
                $refused[] = "Row {$row} ({$code}): '{$calculation}' is not a declared calculation.";

                continue;
            }

            if ($calculation === 'same_as' && (string) ($this->component($code)['same_as'] ?? '') === '') {
                $refused[] = "Row {$row} ({$code}): \"Same as\" is only for a component that names its pair "
                    . '(Provident Fund (Employer) is the same as Provident Fund (Employee)).';

                continue;
            }

            foreach ((array) $this->calculations()[$calculation]['needs'] as $need) {
                $missing = match ($need) {
                    'percent' => self::exactOrNull($rule['percent'] ?? '') === null,
                    'amount' => self::exactOrNull($rule['amount'] ?? '') === null,
                    'basis_column' => trim((string) ($rule['basis_column'] ?? '')) === '',
                    'formula' => ($rule['formula'] ?? []) === [],
                    'branches' => ($rule['branches'] ?? []) === [],
                    'slab' => ($rule['slab'] ?? []) === [],
                    'status' => trim((string) ($rule['status_field'] ?? '')) === '',
                    default => false,
                };

                if ($missing && $need === 'branches') {
                    $refused[] = "Row {$row} ({$code}): a Manual calculation rule needs at least one IF "
                        . 'branch ("+ Add another condition" — IF … THEN …, ELSE …); a plain term-by-term '
                        . "formula with no condition is not yet computed by this build's payroll engine.";
                } elseif ($missing) {
                    $refused[] = "Row {$row} ({$code}): a "
                        . $this->calculations()[$calculation]['label'] . ' rule needs '
                        . str_replace('_', ' ', $need)
                        . ', and an exact one — a percentage that is not an exact decimal becomes '
                        . '0.0 the moment it is cast, on a number that multiplies somebody\'s pay.';
                }
            }

            // BUILT FOR REAL, HERE, NOT JUST SHAPE-CHECKED. The only way to
            // know a set of branches compiles to a valid SPX expression is to
            // ask SPX (SalaryComponent::compile(), inside manualComponent())
            // — the same reason a FORMULA component's own expression is
            // refused at publish rather than left to fail on a close.
            if ($calculation === 'manual' && ($rule['branches'] ?? []) !== []) {
                try {
                    self::manualComponent(
                        $code,
                        self::engineKind(['kind' => (string) ($this->componentOrNull($code)['kind'] ?? '')]),
                        [
                            'branches' => (array) $rule['branches'],
                            'else_result' => (array) ($rule['else_result'] ?? []),
                        ],
                        0,
                        [],
                        array_keys($this->components()),
                    );
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $refused[] = "Row {$row} ({$code}): " . $e->getMessage();
                }
            }

            // BUILT FOR REAL, HERE TOO — the same reasoning as MANUAL just
            // above, and the same gap MANUAL used to have: a slab basis this
            // build does not map ('CTC' or 'BASIC' only — see mapSlab()) or a
            // range whose 'to'/amount does not parse was previously accepted
            // by this method (only "is the slab array non-empty" was
            // checked) and only discovered when a payroll close actually
            // tried to build the component — a rule that "saved" and then
            // silently never priced anything is worse than one refused here,
            // by name, while whoever typed it is still looking at the row.
            // ponytail: plain-language refusal for the commonest slab miss (owner, 22 Oct) — blank "Range based on".
            if ($calculation === 'slab' && !in_array((string) ($rule['slab_basis'] ?? ''), ['CTC', 'BASIC'], true)) {
                $label = (string) ($this->componentOrNull($code)['label'] ?? $code);
                $refused[] = "Row {$row} ({$label}): open \"Configure slab\" and choose \"Range based on\" (Gross or Basic) — it is still \"Select basis\".";

                continue;
            }

            if ($calculation === 'slab' && ($rule['slab'] ?? []) !== []) {
                try {
                    self::mapSlab([
                        'component_code' => $code,
                        'kind' => (string) ($this->componentOrNull($code)['kind'] ?? ''),
                        'detail' => [
                            'slab_basis' => (string) ($rule['slab_basis'] ?? ''),
                            'slab' => (array) ($rule['slab'] ?? []),
                        ],
                    ], 0);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $refused[] = "Row {$row} ({$code}): " . $e->getMessage();
                }
            }

            // PERCENT OF, BUILT FOR REAL TOO (28 Sep): a fractional rate or a
            // basis this build does not map used to publish cleanly and then
            // abort the whole payroll close.
            if ($calculation === 'percent_of' && self::exactOrNull($rule['percent'] ?? '') !== null
                && trim((string) ($rule['basis_column'] ?? '')) !== '') {
                try {
                    self::mapPercentOf([
                        'component_code' => $code,
                        'kind' => (string) ($this->componentOrNull($code)['kind'] ?? ''),
                        'basis_column' => (string) ($rule['basis_column'] ?? ''),
                        'percent_value' => (string) ($rule['percent'] ?? ''),
                    ], 0);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $refused[] = "Row {$row} ({$code}): " . $e->getMessage();
                }
            }

            if ($calculation === 'employee_status' && self::exactOrNull($rule['status_amount'] ?? '') === null) {
                $refused[] = "Row {$row} ({$code}): an Employee status rule needs an exact applicable amount.";
            }

            if ($calculation === 'balance') {
                $balances[] = $code;
            }

            if ($calculation === 'employee_gender') {
                foreach (self::genderConfig((array) ($rule['gender'] ?? [])) as $g => $c) {
                    $who = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other / not set'][$g];
                    $problem = match ($c['mode']) {
                        'exempt' => null,
                        'flat' => self::exactOrNull($c['amount']) === null ? 'needs an exact amount' : null,
                        'percent_of' => self::exactOrNull($c['percent']) === null || $c['basis_column'] === '' ? 'needs an exact % and what it is a % of' : null,
                        'government' => in_array($code, self::GOVERNMENT_CODES, true) ? null : 'can be "As per Government rules" only for ' . implode(', ', self::GOVERNMENT_CODES),
                        // NOTHING CHOSEN IS ALLOWED (owner, 13 Oct): that gender simply falls through
                        // to the component's other rows — see resolveFor().
                        default => null,
                    };
                    $problem === null || $refused[] = "Row {$row} ({$code}): Employee Gender — {$who} {$problem}.";
                }
            }

            if ($calculation === 'employee_status') {
                $field = (string) ($rule['status_field'] ?? '');
                $fields = array_column((array) ($this->catalogue()['status_fields'] ?? []), 'code');

                if ($field !== '' && !in_array($field, $fields, true)) {
                    $refused[] = "Row {$row} ({$code}): '{$field}' is not a declared employee "
                        . 'field. A rule against a column the table does not have applies to nobody '
                        . 'and says nothing about it.';
                }
            }
        }

        // ESIC (Employer) "Same as" ESIC (Employee) only when the employee row is As per Government
        // rules: the two shares are 3.25% and 0.75%, so copying a Custom employee % would be wrong.
        foreach ($rules as $index => $rule) {
            $code = (string) ($rule['component'] ?? '');
            $component = $this->componentOrNull($code) ?? [];
            if ((string) ($rule['calculation'] ?? '') !== 'same_as' || empty($component['same_as_government_only'])) {
                continue;
            }
            foreach ($rules as $other) {
                if ((string) ($other['component'] ?? '') === (string) $component['same_as'] && (string) ($other['calc_mode'] ?? '') !== 'government') {
                    $refused[] = 'Row ' . ($index + 1) . " ({$code}): \"Same as\" needs every {$component['same_as']} row "
                        . 'to be "As per Government rules" — the employer and employee rates differ, so a Custom employee % cannot be copied.';
                    break;
                }
            }
        }

        if (count($balances) > 1) {
            $refused[] = 'Two components are set to Balance (' . implode(', ', $balances)
                . '). The balance absorbs whatever is left of the gross, and two of them is an '
                . 'unsolvable structure — there is no split that both can be.';
        }

        return $refused;
    }

    /** @return array<string, array<string, mixed>> code => component */
    public function components(): array
    {
        $byCode = [];

        foreach ((array) ($this->catalogue()['components'] ?? []) as $component) {
            $byCode[(string) $component['code']] = $component;
        }

        return $byCode;
    }

    /** @return array<string, array<string, mixed>> code => calculation */
    public function calculations(): array
    {
        $byCode = [];

        foreach ((array) ($this->catalogue()['calculations'] ?? []) as $calculation) {
            $byCode[(string) $calculation['code']] = $calculation;
        }

        return $byCode;
    }

    /**
     * ONE EMPLOYMENT'S WINNING RULE PER COMPONENT, on a date.
     *
     * `inForce()` already sorts the whole collection most-specific-first, so
     * walking it once and keeping the FIRST rule that covers this employment
     * for each component code is exactly "most specific wins" — decided
     * independently per component, because a company-wide HRA and a
     * branch-only CONVEYANCE are two different questions.
     *
     * Scope matching is PayCycles' OWN ladder (employee > team > designation
     * > branch > company; this class has no department scope), taken as a
     * parameter rather than duplicated — the same ~25 lines of id-to-name
     * resolution already live there and a second copy is a second place they
     * can drift apart.
     *
     * @param array<string, mixed> $employment
     * @return list<array<string, mixed>> one row per governed component_code, decoded
     */
    public function resolveFor(array $employment, string $asOf, PayCycles $scope): array
    {
        $winners = [];

        foreach ($this->inForce($asOf) as $rule) {
            $code = (string) ($rule['component_code'] ?? '');

            if ($code === '' || $code === 'PT' || isset($winners[$code])) {
                // A more specific rule for this component already won —
                // inForce() is sorted specificity-first, so the first match
                // per code IS the winner.
                continue;
            }

            $asScope = [];

            foreach ([
                'employee' => 'scope_employee', 'team' => 'scope_team',
                'branch' => 'scope_branch', 'role' => 'scope_role',
                'company' => 'scope_company',
            ] as $key => $column) {
                $value = trim((string) ($rule[$column] ?? ''));
                // "All" IS blank, published as a chosen word rather than a left-empty
                // field. publish() already treats it as blank when it counts
                // specificity; treated the same way here, or a rule scoped "All"
                // would match nobody — an exact-string comparison against a
                // company literally named "All".
                $asScope[$key] = strcasecmp($value, 'All') === 0 ? '' : $value;
            }

            // DESIGNATION IS A LIST; PayCycles::covers() BELOW STILL DOES A
            // SINGLE-VALUE EXACT MATCH FOR EVERY OTHER SCOPE, on purpose — that
            // method also resolves Pay Cycles rules, KRA/KPI targets and Payslip
            // Format, and changing its matching semantics for one caller would
            // silently change it for all four. So membership is checked here,
            // against the comma-joined scope_designation this class alone
            // writes, and then $asScope['designation'] is left blank ("any") so
            // covers() does not also compare the whole list as one literal
            // string.
            $designations = array_values(array_filter(
                array_map('trim', explode(',', (string) ($rule['scope_designation'] ?? ''))),
                static fn (string $d): bool => $d !== '' && strcasecmp($d, 'All') !== 0,
            ));

            if ($designations !== []) {
                $employeeDesignation = $scope->valueOf($employment, 'designation');
                $matched = false;

                foreach ($designations as $designation) {
                    if (strcasecmp($designation, $employeeDesignation) === 0) {
                        $matched = true;

                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }
            }

            // CITY: the city of the employee's own branch (Organization Configuration → Branches).
            $city = trim((string) (((array) ($rule['detail'] ?? []))['scope_city'] ?? ''));
            if ($city !== '' && strcasecmp($city, 'All') !== 0
                && strcasecmp($city, (string) $this->branchOf($employment)['city']) !== 0) {
                continue;
            }

            $asScope['designation'] = '';
            $department = trim((string) (((array) ($rule['detail'] ?? []))['scope_department'] ?? ''));
            $asScope['department'] = strcasecmp($department, 'All') === 0 ? '' : $department;

            if ($scope->covers($asScope, $employment)) {
                // A GOVERNMENT RULE DECLARED AS A CALCULATION (Basic: 50% of Gross under the Code on Wages)
                // — the row computes exactly as that calculation, figures from config/salary-components.json.
                $declared = $this->componentOrNull($code)['government'] ?? null;
                if (is_array($declared) && ((string) ($rule['detail']['calc_mode'] ?? '')) === 'government') {
                    $rule['calculation'] = (string) $declared['calculation'];
                    $rule['basis_column'] = (string) ($declared['basis_column'] ?? '');
                    $rule['percent_value'] = (string) ($declared['percent'] ?? '');
                }

                if (
                    ((string) ($rule['detail']['calc_mode'] ?? '')) === 'government'
                    && in_array($code, self::GOVERNMENT_CODES, true)
                ) {
                    // ONLY FETCHED WHEN ACTUALLY NEEDED — a rule nobody has
                    // published as "As per Government rules" for one of the
                    // four codes above costs this build nothing extra.
                    $rule['_govt_branch'] = $this->branchOf($employment, $asOf);
                }

                // EMPLOYEE GENDER IS DECIDED HERE TOO, per employee: the treatment for this
                // person's gender, carried as a variant so a closed run reproduces it.
                if ((string) ($rule['calculation'] ?? '') === 'employee_gender') {
                    $rule['_gender_rule'] = self::genderTreatmentFor($rule, $employment);

                    // NO TREATMENT FOR THIS PERSON'S GENDER (owner, 13 Oct): this row does not
                    // apply to them; the next matching row for the component decides, as if
                    // this one were not there — e.g. a row only for Male, the usual row for the rest.
                    if ((string) $rule['_gender_rule']['mode'] === '') {
                        continue;
                    }

                    $rule['_gender_rule']['mode'] === 'government' && in_array($code, self::GOVERNMENT_CODES, true)
                        && $rule['_govt_branch'] = $this->branchOf($employment, $asOf);
                }

                // EMPLOYEE STATUS IS DECIDED HERE, PER EMPLOYEE, where the
                // employee is in hand — the engine's formulas cannot read an
                // employee column. The rule becomes a flat amount for this
                // person: the published amount when their field matches, nil
                // when it does not (28 Sep: it used to abort the whole close).
                if ((string) ($rule['calculation'] ?? '') === 'employee_status') {
                    $rule['_status_amount'] = $this->statusAmountFor($rule, $employment);
                }

                $winners[$code] = $rule;
            }
        }

        // "SAME AS" (owner, 16 Oct): PF (Employer) takes this employee's own winning PF (Employee)
        // rule — calculation, %, basis, slab, Govt. mode — so it is set once. No rule for the pair:
        // nothing to follow, so the component is not computed for this person.
        foreach ($winners as $code => $rule) {
            if ((string) ($rule['calculation'] ?? '') !== 'same_as') {
                continue;
            }
            $pair = (string) ($this->componentOrNull($code)['same_as'] ?? '');
            if ($pair === '' || !isset($winners[$pair]) || (string) ($winners[$pair]['calculation'] ?? '') === 'same_as') {
                unset($winners[$code]);

                continue;
            }
            $winners[$code] = ['id' => $rule['id'] ?? null, 'component_code' => $code, 'kind' => $rule['kind'] ?? '',
                '_same_as' => $pair] + $winners[$pair];
        }

        // PT FROM PT SLABS, for everyone (see PT_SLABS_ID).
        if ($this->componentOrNull('PT') !== null) {
            $winners['PT'] = $this->ptSlabsRule() + ['_govt_branch' => $this->branchOf($employment, $asOf)];
        }

        // Govt. ESI rows add no structure line: Register::governmentEsi() computes them.
        $winners = array_filter($winners, static fn (array $r): bool
            => !(in_array((string) $r['component_code'], self::GOVERNMENT_ELSEWHERE, true)
                && ((string) ($r['detail']['calc_mode'] ?? '')) === 'government'));

        return array_values($winners);
    }

    /**
     * The employment's own branch row (city, state) — the same lookup
     * PayCycles::valueOf() does for scope matching, but this build has no
     * shared place for it yet (ponytail: move both here, or to a small
     * OrgLookup, the day a third caller needs it).
     *
     * @param array<string, mixed> $employment
     * @return array{city: string, state: string}
     */
    private function branchOf(array $employment, string $asOf = ''): array
    {
        $branchId = (string) ($employment['branch_id'] ?? '');
        $branchName = strtolower(trim((string) ($employment['branch'] ?? '')));
        $found = null;

        // BY ID, AND BY NAME WHEN THERE IS NO ID (28 Sep): a ported employee
        // with only the text branch got a blank city and state — 40% HRA
        // silently, and a PT refusal that stopped the whole close.
        foreach ($this->store->all('branches') as $row) {
            if (($branchId !== '' && (string) ($row['id'] ?? '') === $branchId)
                || ($branchId === '' && $branchName !== '' && strtolower(trim((string) ($row['name'] ?? ''))) === $branchName)) {
                $found = $row;

                break;
            }
        }

        // PROFESSIONAL TAX FOLLOWS THE PERSON'S OWN PT STATE when their record
        // names one (employee-fields: "which state's schedule applies is a fact
        // about the person, not the company"); the branch's state otherwise.
        $ptState = trim((string) ($employment['pt_state'] ?? ''));

        $state = $ptState !== '' && strcasecmp($ptState, 'Not applicable') !== 0
            ? $ptState : trim((string) ($found['state'] ?? ''));

        return [
            'city' => trim((string) ($found['city'] ?? '')),
            'state' => $state,
            // Carried with the rule (a variant), so a closed run reproduces the slab it used.
            // PT SLABS (owner, 28 Oct): Organization Setup → PT Slabs — the most specific rule for this
            // state / company / branch / gender in force on the payroll date (config/pt.json until saved).
            'pt_slab' => PtRules::for($this->store, dirname($this->file))->slab($state,
                (string) ($employment['employer_company_id'] ?? ''), (string) ($found['id'] ?? $branchId),
                (string) ($employment['gender'] ?? ''), $asOf !== '' ? $asOf : date('Y-m-d'), $employment),
        ];
    }

    /**
     * An Employee Gender rule's three treatments, normalised: male, female, other / not set.
     * "Other" left unset follows Male, so a record with no gender is never silently exempt.
     *
     * @param array<string, mixed> $raw
     * @return array<string, array{mode: string, amount: string, percent: string, basis_column: string}>
     */
    public static function genderConfig(array $raw): array
    {
        $out = [];
        foreach (['male', 'female', 'other'] as $g) {
            $c = (array) ($raw[$g] ?? []);
            $out[$g] = ['mode' => (string) ($c['mode'] ?? ''), 'amount' => trim((string) ($c['amount'] ?? '')),
                'percent' => trim((string) ($c['percent'] ?? '')), 'basis_column' => trim((string) ($c['basis_column'] ?? ''))];
        }
        if ($out['other']['mode'] === '' || $out['other']['mode'] === 'same_as_male') {
            $out['other'] = $out['male'];
        }

        return $out;
    }

    /**
     * The treatment an Employee Gender rule gives THIS employee, from their Gender on file.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $employment
     * @return array{gender: string, mode: string, amount: string, percent: string, basis_column: string}
     */
    private static function genderTreatmentFor(array $rule, array $employment): array
    {
        $have = strtolower(trim((string) ($employment['gender'] ?? '')));
        $g = in_array($have, ['male', 'm'], true) ? 'male' : (in_array($have, ['female', 'f'], true) ? 'female' : 'other');

        return ['gender' => $g] + self::genderConfig((array) (((array) ($rule['detail'] ?? []))['gender'] ?? []))[$g];
    }

    /**
     * The flat amount an Employee-status rule gives THIS employee: the rule's
     * amount when the employee's field matches the condition (or, with no
     * condition, holds any value), '0.00' otherwise.
     *
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $employment
     */
    private function statusAmountFor(array $rule, array $employment): string
    {
        $detail = (array) ($rule['detail'] ?? []);
        $field = (string) ($detail['status_field'] ?? $rule['status_field'] ?? '');
        $column = $field;

        foreach ((array) ($this->catalogue()['status_fields'] ?? []) as $f) {
            if ((string) ($f['code'] ?? '') === $field) {
                $column = (string) ($f['column'] ?? $field);
            }
        }

        $have = trim((string) ($employment[$column] ?? ''));
        $condition = trim((string) ($detail['status_condition'] ?? $rule['status_condition'] ?? ''));
        $matches = $condition === '' ? $have !== '' : strcasecmp($have, $condition) === 0;
        $amount = self::exactOrNull($detail['status_amount'] ?? $rule['status_amount'] ?? '');

        return $matches && $amount !== null ? $amount : '0.00';
    }

    /**
     * WHAT A RESOLVED RULE DEPENDED ON FOR THIS EMPLOYEE beyond its own row —
     * the branch city/state a Government rule priced from, the amount an
     * Employee-status rule gave. Two employees on the same rule ids but a
     * different branch are NOT the same component list, so this is part of the
     * grouping key and is stored with the closed row for reproduction.
     *
     * @param list<array<string, mixed>> $resolved
     * @return array<string, array<string, mixed>> rule id => variant
     */
    public function variantsOf(array $resolved): array
    {
        $out = [];

        foreach ($resolved as $rule) {
            $v = array_intersect_key($rule, ['_govt_branch' => true, '_status_amount' => true, '_gender_rule' => true]);

            if ($v !== []) {
                $out[(string) ($rule['id'] ?? '')] = $v;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Put stored variants back onto rules read by id (verify()).
     *
     * @param list<array<string, mixed>> $rules
     * @param array<string, array<string, mixed>> $variants
     * @return list<array<string, mixed>>
     */
    public static function withVariants(array $rules, array $variants): array
    {
        return array_map(static fn (array $r): array
            => array_merge($r, (array) ($variants[(string) ($r['id'] ?? '')] ?? [])), $rules);
    }

    /**
     * The winning rules, AS ENGINE COMPONENTS — or a clear refusal naming
     * exactly which rule and why. Never a silently dropped component: a rule
     * this build cannot compute is a wrong payslip produced confidently, and
     * that is worth more than a component missing loudly.
     *
     * @param list<array<string, mixed>> $resolved from resolveFor()
     * @param array<string, array<string, bool>> $flagsByCode borrowed onto
     *        each built component by its own code — see `merge()`, the only
     *        caller with flags to offer; `SalaryComponent` is `readonly`, so
     *        these have to go in at construction, not after.
     * @return list<SalaryComponent>
     * @throws \RuntimeException when any winning rule cannot be mapped
     */
    public function asEngineComponents(array $resolved, array $flagsByCode = []): array
    {
        $components = [];

        foreach (array_values($resolved) as $i => $rule) {
            $code = (string) $rule['component_code'];
            $calculation = (string) $rule['calculation'];
            $flags = $flagsByCode[$code] ?? [];

            if (
                ((string) ($rule['detail']['calc_mode'] ?? '')) === 'government'
                && in_array($code, self::GOVERNMENT_CODES, true)
            ) {
                // OVERRIDES THE ROW'S OWN CALCULATION ENTIRELY. "As per
                // Government rules" means the number comes from the statute,
                // not from whatever percent happened to be on the row before
                // the toggle locked it — see governmentComponent().
                $components[] = self::governmentComponent($rule, $i, $flags);

                continue;
            }

            if ($calculation === 'employee_gender') {
                $gt = (array) ($rule['_gender_rule'] ?? throw new \RuntimeException("{$code}: an Employee Gender rule was not resolved against an employee."));
                $components[] = match ((string) ($gt['mode'] ?? '')) {
                    'government' => self::governmentComponent($rule, $i, $flags),
                    'percent_of' => self::mapPercentOf(['basis_column' => (string) $gt['basis_column'], 'percent_value' => (string) $gt['percent']] + $rule, $i, $flags),
                    'flat' => new SalaryComponent(code: $code, kind: self::engineKind($rule), basis: SalaryComponent::FLAT, flags: $flags,
                        flatAmount: (string) (self::exactOrNull($gt['amount'] ?? '') ?? '0.00'), sequence: $i),
                    default => new SalaryComponent(code: $code, kind: self::engineKind($rule), basis: SalaryComponent::FLAT, flags: $flags,
                        flatAmount: '0.00', sequence: $i), // exempt
                };

                continue;
            }

            $components[] = match ($calculation) {
                // FLAT AND MONTHLY ARE THE SAME BASIS. The engine's FLAT basis
                // uses a component's flatAmount unchanged, every period, with
                // no annual/monthly conversion inside it — Structure.php's own
                // components() does the same. "Monthly" exists on this screen
                // only to stop a customer confusing an annual CTC figure with
                // a per-period one; it changes nothing about how the number is
                // used once published.
                'flat', 'monthly' => new SalaryComponent(
                    code: $code,
                    kind: self::engineKind($rule),
                    basis: SalaryComponent::FLAT,
                    flags: $flags,
                    flatAmount: (string) (self::exactOrNull($rule['flat_amount'] ?? null)
                        ?? throw new \RuntimeException("{$code}: published as {$calculation} with no amount.")),
                    sequence: $i,
                ),
                'percent_of' => self::mapPercentOf($rule, $i, $flags),
                'balance' => new SalaryComponent(
                    code: $code, kind: self::engineKind($rule), basis: SalaryComponent::BALANCE,
                    flags: $flags, sequence: $i,
                ),
                'slab' => self::mapSlab($rule, $i, $flags),
                // MANUAL IS WIRED ONLY AS N IF/THEN BRANCHES PLUS ELSE (owner
                // request, 12 Sep 2026, widened to N branches same day) — see
                // manualComponent(). refusals() already stops a row with no
                // branches from publishing (needs: branches) — so reaching
                // this arm with none would be a rule published before this
                // existed, or a slipped validation, either way a bug worth
                // seeing loudly rather than a silent zero.
                'manual' => self::manualComponent(
                    $code,
                    self::engineKind($rule),
                    [
                        'branches' => (array) ($rule['detail']['branches'] ?? []),
                        'else_result' => (array) ($rule['detail']['else_result'] ?? []),
                    ],
                    $i,
                    $flags,
                    array_keys($this->components()),
                ),
                'employee_status' => new SalaryComponent(
                    code: $code,
                    kind: self::engineKind($rule),
                    basis: SalaryComponent::FLAT,
                    flags: $flags,
                    flatAmount: (string) ($rule['_status_amount']
                        ?? throw new \RuntimeException("{$code}: an Employee status rule was not resolved against an employee.")),
                    sequence: $i,
                ),
                default => throw new \RuntimeException(
                    "{$code}: '{$calculation}' is not a calculation this build maps to the engine."
                ),
            };
        }

        return $components;
    }

    /**
     * ONE EMPLOYMENT'S COMPONENTS: the winning rules, PLUS whatever the flat
     * structure declares that no rule governs. `resolveFor()` decides a
     * winner per component CODE, independently — a branch's own HRA rule
     * says nothing about Professional Tax, which nobody has published a rule
     * for. Returning only the winning rules would silently drop every
     * component this screen has never been used to configure, the moment
     * ANY of an employment's components got a scoped rule — a statutory
     * deduction disappearing from a payslip because an unrelated allowance
     * became scoped is exactly the confidently-wrong payslip this codebase
     * refuses everywhere else.
     *
     * FLAGS RIDE ALONG BY CATALOGUE CODE. `asEngineComponents()` has no
     * flags to give a resolved component — this screen's catalogue
     * (`config/salary-components.json`) declares none, unlike the flat
     * structure file. So a resolved BASIC with no flags would silently stop
     * counting toward the PF wage base the moment its branch got a scoped
     * rule. Best available answer: borrow the flags the FLAT file declares
     * for the same catalogue code, since that is the only place in this
     * build flags are declared at all.
     *
     * ponytail: flags borrowed from the flat file rather than declared on
     * this screen's own catalogue. Add a `flags` column to Salary Structure
     * rules (or to config/salary-components.json) the day a tenant needs a
     * wage-base flag that the flat file's component of the same code
     * doesn't also carry.
     *
     * @param list<array<string, mixed>> $resolved from resolveFor()
     * @param list<SalaryComponent> $fallback the flat structure's own components
     * @return list<SalaryComponent>
     */
    public function merge(array $resolved, array $fallback): array
    {
        if ($resolved === []) {
            return $fallback;
        }

        $flagsByCode = [];

        foreach ($fallback as $component) {
            $flagsByCode[self::catalogueCodeOf($component->code)] = $component->flags;
        }

        // SalaryComponent is `readonly` — flags have to go in at
        // construction, so they are handed to asEngineComponents() rather
        // than copied onto the built objects afterward.
        $ruled = $this->asEngineComponents($resolved, $flagsByCode);

        $ruledCodes = array_fill_keys(
            array_map(static fn (array $r): string => (string) $r['component_code'], $resolved),
            true,
        );

        $keptFlat = array_values(array_filter(
            $fallback,
            static fn (SalaryComponent $c): bool => !isset($ruledCodes[self::catalogueCodeOf($c->code)]),
        ));

        // A COMPONENT ON EITHER SIDE CAN REFERENCE ANOTHER BY THE WRONG SIDE'S
        // CODE. A still-flat HRA's ofComponent is 'C-BASIC'; a RULED HRA's is
        // always the plain 'BASIC' (`mapPercentOf()` hardcodes it). Whichever
        // one Basic itself did NOT move to now carries the other spelling, so
        // one of the two silently reads an ofComponent nothing in this run
        // produces. PayslipCalculator reads an unmatched ofComponent as zero,
        // SILENTLY — exactly the "HRA is blank" this exists to stop. Repoint
        // every reference, on either side, at whichever code the SAME
        // catalogue component actually carries in this merged list.
        $actualCodeOf = [];

        foreach ([...$keptFlat, ...$ruled] as $component) {
            $actualCodeOf[self::catalogueCodeOf($component->code)] = $component->code;
        }

        $repoint = static function (SalaryComponent $c) use ($actualCodeOf): SalaryComponent {
            if ($c->ofComponent === null) {
                return $c;
            }

            $wants = $actualCodeOf[self::catalogueCodeOf($c->ofComponent)] ?? $c->ofComponent;

            if ($wants === $c->ofComponent) {
                return $c;
            }

            if ($c->basis === SalaryComponent::SLAB) {
                // SLAB's compiled ranges ARE recoverable — Money::toDecimalString()
                // round-trips the exact figure a range was declared with — so a
                // slab that references a moved component is rebuilt with the
                // same ranges, repointed, same as every other basis below. (This
                // used to be skipped as "not recoverable"; it was, the compiled
                // ->slab was just never read back out until a Salary Structure
                // SLAB component — the governmentComponent() ones — first needed it.)
                return new SalaryComponent(
                    code: $c->code, kind: $c->kind, basis: $c->basis, flags: $c->flags,
                    ofComponent: $wants, sequence: $c->sequence,
                    declaredSlab: array_map(static fn (array $r): array => [
                        'from' => $r['from']->toDecimalString(),
                        'to' => $r['to']?->toDecimalString(),
                        'amount' => $r['amount']?->toDecimalString(),
                        'percent' => $r['percent'],
                    ], $c->slab),
                );
            }

            return new SalaryComponent(
                code: $c->code, kind: $c->kind, basis: $c->basis, flags: $c->flags,
                ofComponent: $wants, ratePercent: $c->ratePercent, flatAmount: $c->flatAmount,
                sequence: $c->sequence,
            );
        };

        return [...array_map($repoint, $keptFlat), ...array_map($repoint, $ruled)];
    }

    /** The flat structure's kind-prefixed codes (C-BASIC, D-PT, …) share this screen's catalogue codes (BASIC, PT, …) without the prefix. */
    private static function catalogueCodeOf(string $structureCode): string
    {
        return preg_match('/^[A-Z]-(.+)$/', $structureCode, $m) === 1 ? $m[1] : $structureCode;
    }

    /**
     * WHAT A COMPONENT IS CALLED, for anything that prints one.
     *
     * Owner, 23 Sep, of the payroll report's column headings: "what is C- and
     * D-... mention that clearly to avoid confusion". C-BASIC and D-PT are the
     * SALARY STRUCTURE's codes, and the C/D are a naming habit of the shipped
     * default structure — C for a component that is paid, D for one that is
     * deducted. The engine does not read the letter at all; every component
     * declares its `kind` outright. So the letter means nothing to the software
     * and something to whoever typed it, which is the worst of both, and a
     * column heading is no place for it.
     *
     * This returns the CATALOGUE's own label — "Basic", "House Rent Allowance",
     * "Professional Tax" — for a structure code, a catalogue code, or either
     * with a prefix, so a screen never has to know which of the two it is
     * holding. A code the catalogue has never heard of (a statutory head from a
     * pack, a component somebody added straight to the structure) comes back as
     * its own code with the prefix off, which is still more readable than
     * "D-PT" and is honest about being unmapped.
     */
    public function labelFor(string $code): string
    {
        // A NAMED LINE "<CODE>:<name>" (a bonus type, owner 5 Nov 2026) is called by its own name.
        if (str_contains($code, ':')) {
            return substr($code, strpos($code, ':') + 1);
        }

        $components = $this->components();
        $bare = self::catalogueCodeOf($code);
        $label = (string) ($components[$code]['label'] ?? $components[$bare]['label'] ?? '');

        return $label !== '' ? $label : $bare;
    }

    /**
     * A stable key for a resolved rule set. Two employments whose winning
     * rules are the SAME set of published rows may share one PayrollRun —
     * this is the key that says so, built from the rules' own ids so it
     * cannot disagree with `byId()`'s reconstruction at verify time.
     *
     * @param list<array<string, mixed>> $resolved from resolveFor()
     */
    public function signatureOf(array $resolved): string
    {
        $ids = array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $resolved);
        sort($ids, SORT_STRING);
        $variants = $this->variantsOf($resolved);

        // THE BRANCH AND STATUS A RULE PRICED FROM ARE PART OF THE KEY (28 Sep):
        // a Mumbai and a Hyderabad employee on the same Government HRA rule
        // used to share one group, and both got whichever branch came first.
        return implode(',', $ids) . ($variants === [] ? '' : '#' . md5((string) json_encode($variants)));
    }

    /**
     * One published rule row by id, decoded — for `verify()` to reconstruct a
     * FROZEN resolution (the ids a close recorded) rather than re-resolving
     * scope against employment data that may have changed since.
     *
     * @return array<string, mixed>|null
     */
    public function byId(string $id): ?array
    {
        if ($id === self::PT_SLABS_ID) {
            return $this->ptSlabsRule();
        }

        $row = $this->store->find(self::COLLECTION, $id);

        return $row === null ? null : self::decoded($row);
    }

    /** @return array<string, mixed> the whole declared catalogue, for the screen */
    public function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || ($decoded['components'] ?? null) === null) {
            throw new \RuntimeException(
                'config/salary-components.json is missing or unreadable. Refused rather than '
                . 'treated as "no components", which would offer an empty screen that looks like a '
                . 'tenant with nothing configured.'
            );
        }

        return $this->catalogue = $decoded;
    }

    /**
     * ADDS ONE COMPONENT TO THE CATALOGUE FILE ITSELF. The class doc above
     * calls config/salary-components.json "the one file a customer never
     * edits" — true until the Salary structure screen needed to let an
     * admin add a component (a conveyance-style allowance this list has
     * never heard of, say) without a release. This is the one place that
     * happens: it appends one object to the file's `components` array and
     * changes nothing else in it — `kinds`, `calculations` and every
     * existing component are exactly as they were.
     *
     * Returns false (nothing written) if the code is already declared —
     * codes are the primary key rule rows key on (component_code), so a
     * silent duplicate would leave the catalogue ambiguous about which
     * entry a saved rule means. flock() rather than a plain
     * file_put_contents(): two admins adding a component in the same
     * second must not have the second write silently lose the first.
     */
    public function addComponent(string $code, string $label, string $kind, bool $statutory): bool
    {
        $handle = fopen($this->file, 'r+');

        if ($handle === false) {
            throw new \RuntimeException('config/salary-components.json could not be opened.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(
                    'config/salary-components.json is in use — try again in a moment.');
            }

            $decoded = json_decode((string) stream_get_contents($handle), true);

            if (!is_array($decoded) || ($decoded['components'] ?? null) === null) {
                throw new \RuntimeException(
                    'config/salary-components.json is missing or unreadable.');
            }

            foreach ($decoded['components'] as $existing) {
                if (strcasecmp((string) ($existing['code'] ?? ''), $code) === 0) {
                    return false;
                }
            }

            $entry = ['code' => $code, 'label' => $label, 'kind' => $kind];

            if ($statutory) {
                $entry['statutory'] = true;
            }

            $decoded['components'][] = $entry;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($handle);

            $this->catalogue = $decoded;

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function component(string $code): array
    {
        return $this->componentOrNull($code)
            ?? throw new \InvalidArgumentException("No component '{$code}' is declared.");
    }

    /** @return array<string, mixed>|null */
    /** @return array<string, mixed> the PT rule every employee carries — computed as Government, from PT Slabs */
    private function ptSlabsRule(): array
    {
        return ['id' => self::PT_SLABS_ID, 'component_code' => 'PT',
            'kind' => (string) ($this->componentOrNull('PT')['kind'] ?? 'deduction'), 'calculation' => 'slab',
            'detail' => ['calc_mode' => 'government']];
    }

    private function componentOrNull(string $code): ?array
    {
        return $this->components()[$code] ?? null;
    }

    /**
     * An exact decimal, or null when the field was empty or unreadable.
     *
     * Never a float and never a silent zero. `(float) '12,000'` is 12.0 and
     * `(float) 'abc'` is 0.0, and both of those multiply somebody's salary
     * without complaining — which is law 7 in one cast.
     */
    private static function exactOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        // A DECIMAL(18,4) column reads back as "3000.0000" (owner's Sep payroll failed on it,
        // 13 Oct): trailing zeros past the paisa carry no value, so they are dropped — down to
        // two places, never further, and never a non-zero digit (that stays, and the engine refuses it).
        return str_contains($text, '.') ? (string) preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $text) : $text;
    }

    /** This class's `kind` strings, as the engine's own. */
    private static function engineKind(array $rule): string
    {
        return match ((string) ($rule['kind'] ?? '')) {
            'earning' => SalaryComponent::EARNING,
            'deduction' => SalaryComponent::DEDUCTION,
            'employer_cost' => SalaryComponent::EMPLOYER_CONTRIBUTION,
            default => throw new \RuntimeException(sprintf(
                "%s: '%s' is not a kind this build maps to the engine.",
                (string) ($rule['component_code'] ?? ''), (string) ($rule['kind'] ?? ''),
            )),
        };
    }

    /**
     * "As per Government rules", for the four codes in GOVERNMENT_CODES —
     * HRA's metro/non-metro split, both Provident Fund components' 12%
     * capped at the ₹15,000 wage ceiling, and Professional Tax's state slab.
     * Everything else this component's row declares (its own percent,
     * amount, slab, scope) is ignored: the number comes from the statute,
     * not from a locked field.
     *
     * WHY NOT EVERY STATUTORY COMPONENT: Basic has no fixed government
     * percentage at all (it always used its own row, unchanged here — this
     * is why BASIC is not in GOVERNMENT_CODES). DA is mandatory only for
     * government/PSU staff, not a private employer, so there is no rate to
     * apply. ESI's exact 0.75%/3.25% needs a fractional rate, and this
     * engine's PERCENT_OF/PERCENT_OF_GROSS/SLAB bases only accept a WHOLE
     * percent (SalaryComponent::$ratePercent is `?int`) — only the FORMULA
     * basis could express a fractional rate, and Gratuity's 15/26 x years-
     * of-service needs employee tenure, which the formula sandbox cannot
     * reach either (SalaryComponent::NAMESPACES is `salary.*`/`period.*`
     * only — no `employee.*`). Both are left for a future formula-based
     * pass rather than approximated here.
     *
     * @param array<string, mixed> $rule a resolved rule row, with `_govt_branch` from resolveFor()
     */
    private static function governmentComponent(array $rule, int $sequence, array $flags): SalaryComponent
    {
        $code = (string) $rule['component_code'];
        $kind = self::engineKind($rule);
        $branch = (array) ($rule['_govt_branch'] ?? ['city' => '', 'state' => '']);

        return match ($code) {
            'HRA' => new SalaryComponent(
                code: $code, kind: $kind, basis: SalaryComponent::PERCENT_OF, flags: $flags,
                ofComponent: 'BASIC', sequence: $sequence,
                ratePercent: in_array(strtolower((string) $branch['city']), self::HRA_METRO_CITIES, true)
                    ? 50 : 40,
            ),
            'PF_EMPLOYEE', 'PF_EMPLOYER' => new SalaryComponent(
                code: $code, kind: $kind, basis: SalaryComponent::SLAB, flags: $flags,
                ofComponent: 'BASIC', sequence: $sequence,
                declaredSlab: [
                    ['from' => '0', 'to' => '15000', 'amount' => null, 'percent' => 12],
                    // Above the wage ceiling, PF stays at exactly 12% of
                    // ₹15,000 — a flat amount, because SLAB's percent branch
                    // would apply 12% to the FULL (uncapped) Basic instead.
                    ['from' => '15000.01', 'to' => null, 'amount' => '1800.00', 'percent' => null],
                ],
            ),
            'PT' => new SalaryComponent(
                code: $code, kind: $kind, basis: SalaryComponent::SLAB, flags: $flags,
                sequence: $sequence,
                declaredSlab: $branch['pt_slab'] ?? throw new \RuntimeException(
                    'PT is published \'As per Government rules\' but its employee\'s branch state is '
                    . ((string) $branch['state'] === '' ? 'not set' : "'{$branch['state']}'")
                    . ", which no Professional Tax rule covers (Organization Setup → PT Slabs). Add a rule for it there, set the "
                    . 'branch\'s state under Organization Setup → Branches, or republish PT as a Custom '
                    . 'rule with your own slab — refused rather than computed with the wrong state\'s '
                    . 'slab, or none at all.'
                ),
            ),
            default => throw new \LogicException(
                "{$code} is not a component governmentComponent() covers — GOVERNMENT_CODES and this "
                . 'match arm have drifted apart.'
            ),
        };
    }

    /**
     * MANUAL, AS AN IF/THEN/ELSE — owner request, 12 Sep 2026: "if the
     * employee's attendance is more than 22 days, give them an incentive of
     * xx rupees". Built through SPX's own `CASE WHEN … THEN … ELSE … END`
     * (SalaryComponent::FORMULA), not a second conditional evaluator — SPX
     * already parses, type-checks and computes exactly this shape, and a
     * hand-rolled if/else here would be a second place a condition could
     * disagree with it.
     *
     * THEN and ELSE are flat rupee amounts, never component arithmetic — the
     * Manual screen asks for two amounts, not a formula, so there is nothing
     * here that mixes Money and Number the way the general term-by-term
     * builder does (which is why that shape still refuses to publish).
     *
     * description/rounding/sample_cases are DERIVED, not asked for a second
     * time: `SalaryComponent::compile()` requires them of every FORMULA
     * component (they exist for hand-authored expressions, printed in a
     * trace), but a Manual row's condition already says everything they
     * would — see conditionSentence() and describe().
     *
     * WIDENED FROM ONE CONDITION TO N (owner: "n number of conditions with n
     * number calculations… remove attendance from that… include conditional
     * and comparison operators in the Manual calculation", 12 Sep 2026). A
     * branch's own result is a real arithmetic chain (renderTermChain on the
     * screen), not a flat amount — the same term/operator shape the Manual
     * builder already used for its plain formula, now reused for THEN and
     * ELSE. A branch may AND together more than one comparison; branches are
     * checked in order, first match wins, exactly like the CASE they compile
     * to.
     *
     * @param array<string, mixed> $manual {branches: list<array{comparisons: list<array{left: array, op: string, right: array}>, result: list<array>}>, else_result: list<array>}
     * @param array<string, bool>  $flags
     * @param list<string>         $componentCodes every code config/salary-components.json declares — the
     *        same allow-list componentOrNull() checks a row's OWN component against, now also checked
     *        against every value a comparison or a result term names
     */
    private static function manualComponent(
        string $code,
        string $kind,
        array $manual,
        int $sequence,
        array $flags,
        array $componentCodes,
    ): SalaryComponent {
        $branches = array_values((array) ($manual['branches'] ?? []));

        if ($branches === []) {
            throw new \RuntimeException(
                "{$code} is published as a Manual calculation rule with no IF branch. This build's payroll "
                . 'engine computes Manual only as IF … THEN … [ELSE IF … THEN …] ELSE … ("+ Add another '
                . "condition\" on the Salary Structure screen) — a plain term-by-term formula with no "
                . "condition is not yet wired. Republish {$code} with at least one branch, or with a "
                . 'supported calculation (% OF, Flat, Monthly, Balance or Slab).'
            );
        }

        $whenParts = [];

        foreach ($branches as $bi => $branch) {
            $branchNo = $bi + 1;
            $comparisons = array_values((array) ($branch['comparisons'] ?? []));

            if ($comparisons === []) {
                throw new \InvalidArgumentException("{$code}: branch {$branchNo} has no condition.");
            }

            $condParts = [];

            foreach ($comparisons as $ci => $comparison) {
                try {
                    $condParts[] = self::comparisonExpression((array) $comparison, $code, $componentCodes);
                } catch (\InvalidArgumentException $e) {
                    throw new \InvalidArgumentException(
                        "{$code}: branch {$branchNo}, condition " . ($ci + 1) . ': ' . $e->getMessage()
                    );
                }
            }

            try {
                $result = self::resultExpression((array) ($branch['result'] ?? []), $code, $componentCodes);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(
                    "{$code}: branch {$branchNo}'s THEN pay — " . $e->getMessage()
                );
            }

            $whenParts[] = 'WHEN ' . implode(' AND ', $condParts) . " THEN {$result}";
        }

        try {
            $elseResult = self::resultExpression((array) ($manual['else_result'] ?? []), $code, $componentCodes);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException("{$code}: the ELSE pay — " . $e->getMessage());
        }

        $expression = 'CASE ' . implode(' ', $whenParts) . " ELSE {$elseResult} END";
        $branchCount = count($branches);

        return new SalaryComponent(
            code: $code,
            kind: $kind,
            basis: SalaryComponent::FORMULA,
            flags: $flags,
            sequence: $sequence,
            declaredFormula: [
                'expression' => $expression,
                'rounding_mode' => 'half_up',
                'rounding_unit' => '0.01',
                'description' => "{$code}: computed by {$branchCount} IF condition(s) checked in order, "
                    . 'each paying its own amount, with an ELSE amount when none of them match.',
                'sample_cases' => self::manualSampleCases($branchCount),
            ],
        );
    }

    /**
     * Which SPX kind a single comparison/result term reads as, and its SPX
     * text when it is NOT a custom number (a component or a wired system
     * value, whose kind is fixed by what it names — never chosen by the
     * caller).
     *
     * @param  list<string> $componentCodes
     * @return array{0: 'money'|'days'|'number', 1: string}
     */
    private static function manualTermKind(array $term, string $code, array $componentCodes): array
    {
        // NOT PREFIXED WITH $code — every caller of manualTermKind()/
        // manualLiteral() (comparisonExpression(), resultExpression()) is
        // itself wrapped in a try/catch that prefixes "{$code}: branch N…"
        // or "{$code}: the ELSE amount…" onto whatever this throws; a $code
        // prefix here too would print it twice.
        $value = (string) ($term['component'] ?? '');

        if ($value === '') {
            throw new \InvalidArgumentException('a value is missing.');
        }

        if ($value === '__custom__') {
            return ['number', ''];
        }

        if (isset(self::MANUAL_SYSTEM_VALUES[$value])) {
            return ['days', self::MANUAL_SYSTEM_VALUES[$value]];
        }

        if (!in_array($value, $componentCodes, true)) {
            throw new \InvalidArgumentException(
                "'{$value}' is not a component this build knows, and not a wired system value "
                . '(Calendar days in month, Attendance (payable days)).'
            );
        }

        return ['money', "salary.component('{$value}')"];
    }

    /** A comparison/result term's own SPX literal — a custom number rendered as the KIND the caller decided on. */
    private static function manualLiteral(array $term, string $kind, string $code, array $componentCodes): string
    {
        $value = (string) ($term['component'] ?? '');

        if ($value !== '__custom__') {
            return self::manualTermKind($term, $code, $componentCodes)[1];
        }

        $raw = trim((string) ($term['customValue'] ?? ''));

        if ($raw === '') {
            throw new \InvalidArgumentException('a custom value is required.');
        }

        return match ($kind) {
            'money' => self::moneyLiteral($raw, 'a custom amount'),
            'days' => self::daysLiteral($raw, 'a custom day count'),
            default => self::plainNumberLiteral($raw),
        };
    }

    private static function plainNumberLiteral(string $raw): string
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $raw)) {
            throw new \InvalidArgumentException("'{$raw}' is not an exact positive number.");
        }

        return $raw;
    }

    /** A whole number of days, as the SPX duration literal `22d` SalaryComponent::compile()'s expression can read. */
    private static function daysLiteral(mixed $value, string $what): string
    {
        $text = trim((string) $value);

        if (!preg_match('/^\d+$/', $text)) {
            throw new \InvalidArgumentException("{$what} '{$text}' must be a whole number of days.");
        }

        return "{$text}d";
    }

    /**
     * One `left op right` SPX comparison. SPX requires both sides to share
     * the same TypeKind (Coercion::comparable()) — a bare custom number has
     * no kind of its own, so it is PROMOTED to whichever kind the other side
     * already is (a component/system-value term never bends the other way).
     *
     * @param list<string> $componentCodes
     */
    private static function comparisonExpression(array $comparison, string $code, array $componentCodes): string
    {
        $op = (string) ($comparison['op'] ?? '');

        if (!in_array($op, self::MANUAL_CONDITION_OPS, true)) {
            throw new \InvalidArgumentException(
                "'{$op}' is not a comparison this build supports (" . implode(' ', self::MANUAL_CONDITION_OPS) . ').'
            );
        }

        $left = (array) ($comparison['left'] ?? []);
        $right = (array) ($comparison['right'] ?? []);
        [$leftKind] = self::manualTermKind($left, $code, $componentCodes);
        [$rightKind] = self::manualTermKind($right, $code, $componentCodes);

        if ($leftKind === 'number' && $rightKind !== 'number') {
            $leftKind = $rightKind;
        }

        if ($rightKind === 'number' && $leftKind !== 'number') {
            $rightKind = $leftKind;
        }

        if ($leftKind === 'number' && $rightKind === 'number') {
            throw new \InvalidArgumentException(
                'a comparison needs at least one side that names a component or a system value — two custom '
                . 'numbers compared against each other is never anything but always-true or always-false.'
            );
        }

        return self::manualLiteral($left, $leftKind, $code, $componentCodes) . " {$op} "
            . self::manualLiteral($right, $rightKind, $code, $componentCodes);
    }

    /**
     * A THEN/ELSE amount as an arithmetic chain of terms (same +, -, *, /, %
     * and parenthesis shape the Manual builder's plain formula already
     * offered). SPX never coerces Money to/from Number (Money+Number and
     * Money-Number are refused), so a bare custom number's OWN operator
     * decides its literal kind: '*'/'/'/'%' read as a plain Number (a rate
     * or a divisor), '+'/'-'/the first term read as Money (an amount).
     *
     * @param list<string> $componentCodes
     */
    private static function resultExpression(array $terms, string $code, array $componentCodes): string
    {
        $terms = array_values(array_filter(
            $terms,
            static fn (array $t): bool => (string) ($t['component'] ?? '') !== '',
        ));

        if ($terms === []) {
            throw new \InvalidArgumentException('needs at least one term.');
        }

        $out = '';

        foreach ($terms as $i => $term) {
            $op = $i === 0 ? '' : ((string) ($term['operator'] ?? '+'));

            if ($i > 0) {
                $out .= " {$op} ";
            }

            if (!empty($term['openParen'])) {
                $out .= '(';
            }

            $out .= self::resultTermLiteral((array) $term, $op, $code, $componentCodes);

            if (!empty($term['closeParen'])) {
                $out .= ')';
            }
        }

        return $out;
    }

    private static function resultTermLiteral(array $term, string $ownOp, string $code, array $componentCodes): string
    {
        $value = (string) ($term['component'] ?? '');

        if ($value === '__custom__') {
            $raw = trim((string) ($term['customValue'] ?? ''));

            if ($raw === '') {
                throw new \InvalidArgumentException('has a custom term with no value.');
            }

            return in_array($ownOp, ['*', '/', '%'], true)
                ? self::plainNumberLiteral($raw)
                : self::moneyLiteral($raw, 'a custom amount');
        }

        if (isset(self::MANUAL_SYSTEM_VALUES[$value])) {
            return self::MANUAL_SYSTEM_VALUES[$value];
        }

        if (!in_array($value, $componentCodes, true)) {
            throw new \InvalidArgumentException(
                "names '{$value}', which is not a component this build knows, and not a wired system value "
                . '(Calendar days in month, Attendance (payable days)).'
            );
        }

        return "salary.component('{$value}')";
    }

    /** @return list<string> */
    private static function manualSampleCases(int $branchCount): array
    {
        $cases = [];

        for ($i = 1; $i <= $branchCount; $i++) {
            $cases[] = "branch {$i} matches";
        }

        $cases[] = 'no branch matches (else)';

        while (count($cases) < 3) {
            $cases[] = 'boundary case ' . (count($cases) + 1);
        }

        return $cases;
    }

    /** An exact rupee amount, as the SPX money literal `SalaryComponent::compile()`'s expression can read. */
    private static function moneyLiteral(mixed $value, string $what): string
    {
        $text = trim((string) $value);

        if ($text === '') {
            throw new \InvalidArgumentException("{$what} is required.");
        }

        try {
            return '₹' . Money::of($text)->toDecimalString();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                "{$what} '{$text}' is not an exact rupee amount. " . $e->getMessage()
            );
        }
    }

    /**
     * A percent_of rule, mapped by ITS OWN basis_column — CTC is a rate on
     * the gross (`PERCENT_OF_GROSS`), BASIC is a rate on the already-computed
     * BASIC component (`PERCENT_OF`, ofComponent: 'BASIC'). No other basis is
     * offered by the publish screen, so no other is mapped.
     */
    /** @param array<string, bool> $flags */
    private static function mapPercentOf(array $rule, int $sequence, array $flags = []): SalaryComponent
    {
        $code = (string) $rule['component_code'];
        $basisColumn = (string) ($rule['basis_column'] ?? '');
        $rate = self::wholePercent((string) ($rule['percent_value'] ?? ''), $code, 'its % rate');

        return match ($basisColumn) {
            'CTC' => new SalaryComponent(
                code: $code, kind: self::engineKind($rule), basis: SalaryComponent::PERCENT_OF_GROSS,
                flags: $flags, ratePercent: $rate, sequence: $sequence,
            ),
            'BASIC' => new SalaryComponent(
                code: $code, kind: self::engineKind($rule), basis: SalaryComponent::PERCENT_OF,
                flags: $flags, ofComponent: 'BASIC', ratePercent: $rate, sequence: $sequence,
            ),
            default => throw new \RuntimeException(
                "{$code}: '{$basisColumn}' is not a basis column this build maps (only Gross or Basic)."
            ),
        };
    }

    /**
     * A slab rule. `slab_basis` picks what the ranges are measured against —
     * the same CTC/BASIC choice `percent_of` offers, and mapped the same way
     * (CTC: the gross itself, via a null ofComponent; BASIC: the
     * already-computed BASIC component) — `PayslipCalculator::slabAmount()`
     * reads `ofComponent` to decide, exactly as `PERCENT_OF` does.
     */
    /** @param array<string, bool> $flags */
    private static function mapSlab(array $rule, int $sequence, array $flags = []): SalaryComponent
    {
        $code = (string) $rule['component_code'];
        $detail = (array) $rule['detail'];
        $slabBasis = (string) ($detail['slab_basis'] ?? '');
        $ofComponent = match ($slabBasis) {
            'CTC' => null,
            'BASIC' => 'BASIC',
            default => throw new \RuntimeException(
                "{$code}: '{$slabBasis}' is not a slab basis this build maps (only Gross or Basic)."
            ),
        };

        return new SalaryComponent(
            code: $code, kind: self::engineKind($rule), basis: SalaryComponent::SLAB,
            flags: $flags, ofComponent: $ofComponent, sequence: $sequence,
            declaredSlab: self::mapSlabRows((array) ($detail['slab'] ?? []), $code),
        );
    }

    /**
     * The screen's slab rows, `{from, to, amount}` with `amount` free text
     * that may be a rupee figure OR a percentage ("1800" or "12%" — the
     * screen's own placeholder), into the engine's `{from, to, amount,
     * percent}` shape with exactly one of amount/percent set. Never guesses
     * which the text means: an unparseable row refuses the whole rule with
     * the row number, rather than defaulting to zero on a range somebody
     * published expecting it to pay.
     *
     * @return list<array{from: string, to: ?string, amount: ?string, percent: ?int}>
     */
    private static function mapSlabRows(array $rows, string $code): array
    {
        $out = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $from = self::exactOrNull($row['from'] ?? '');

            if ($from === null) {
                throw new \RuntimeException("{$code}: slab range {$n}'s 'from' is not an exact amount.");
            }

            $toText = trim((string) ($row['to'] ?? ''));
            $to = null;

            if ($toText !== '' && strcasecmp($toText, 'above') !== 0 && strcasecmp($toText, 'and above') !== 0) {
                $to = self::exactOrNull($toText);

                if ($to === null) {
                    throw new \RuntimeException(
                        "{$code}: slab range {$n}'s 'to' ('{$toText}') is not an exact amount or 'Above'."
                    );
                }
            }

            $amountText = trim((string) ($row['amount'] ?? ''));
            $amount = null;
            $percent = null;

            if (str_ends_with($amountText, '%')) {
                $percent = self::wholePercent(
                    substr($amountText, 0, -1), $code, "slab range {$n}'s applicable amount",
                );
            } else {
                $amount = self::exactOrNull($amountText);

                if ($amount === null) {
                    throw new \RuntimeException(
                        "{$code}: slab range {$n}'s applicable amount ('{$amountText}') is not an exact "
                        . "amount or a whole percentage (e.g. 1800 or 12%)."
                    );
                }
            }

            $out[] = ['from' => $from, 'to' => $to, 'amount' => $amount, 'percent' => $percent];
        }

        return $out;
    }

    /**
     * An exact decimal, as a WHOLE percent. `SalaryComponent`'s own rate is
     * `?int` — `PayslipCalculator` casts it straight into `Rational::percent()`
     * with no rounding step — so "12.5" would throw a TypeError deep inside
     * the engine on a run somebody is closing, rather than a sentence naming
     * the rule here, at the one place that knows which rule it was.
     */
    private static function wholePercent(string $decimal, string $code, string $of): int
    {
        $text = trim($decimal);

        if ($text === '') {
            throw new \RuntimeException("{$code}: {$of} needs a percentage and none was published.");
        }

        // A rate published through the Salary structure screen is stored in a
        // DECIMAL column and comes back as "50.0000", not "50" — still a whole
        // percent, just with the column's fixed scale attached. Strip a
        // fractional part that is entirely zeros before checking; an actually
        // fractional rate (e.g. "12.5000") is still refused below.
        $whole = preg_match('/^(\d+)\.0+$/', $text, $m) === 1 ? $m[1] : $text;

        if (preg_match('/^\d+$/', $whole) !== 1) {
            throw new \RuntimeException(
                "{$code}: {$of} is '{$text}%', which is not a whole number. This build's percentage rate "
                . 'is a whole percent — refused rather than rounded, which would silently change what was '
                . 'published.'
            );
        }

        return (int) $whole;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decoded(array $row): array
    {
        $detail = json_decode((string) ($row['detail'] ?? '{}'), true);
        $row['detail'] = is_array($detail) ? $detail : [];

        return $row;
    }
}
