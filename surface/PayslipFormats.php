<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT A PAYSLIP LOOKS LIKE, AND WHOSE PAYSLIP IT IS.
 *
 * A collections agency's field executive in Hyderabad and a director at head
 * office do not get the same payslip: one shows overtime and a conveyance line,
 * the other shows neither and does show the employer's contributions. Until this
 * existed the payslip was one layout for everybody, written into a screen.
 *
 * A FORMAT IS SCOPED THE SAME WAY A COMPONENT RULE IS — company, branch, team,
 * designation, employee, in the platform's own order — and the most specific one
 * wins. That is deliberate: two configuration screens that resolve "who does
 * this apply to" by two different ladders is how a customer ends up unable to
 * explain why somebody's payslip looks the way it does. Specificity is COUNTED
 * from the scopes actually set, never typed.
 *
 * IT DECIDES PRESENTATION, NOT MONEY. Which lines appear, in what order, and
 * whether the employer-cost block is shown. The amounts come from the payroll
 * run; a component the format hides is a component that was still computed and
 * still in the totals — hiding a deduction on paper does not stop it being
 * deducted, and the screen says so, because the alternative is somebody using
 * this to make a payslip that disagrees with the bank transfer.
 */
final class PayslipFormats
{
    public const COLLECTION = 'payslip_formats';

    /** The three blocks of a payslip, in the order they are printed. */
    public const SECTIONS = ['earning', 'deduction', 'employer_cost'];

    public function __construct(
        private readonly RowStore $store,
        private readonly ComponentRules $components,
    ) {
    }

    /** @return list<array<string, mixed>> every format still in use, most specific first */
    public function all(): array
    {
        $rows = array_map(self::decoded(...), array_values(array_filter($this->rows(),
            static fn (array $r): bool => trim((string) ($r['retired_at'] ?? '')) === '')));

        usort($rows, static fn (array $a, array $b): int
            => ((int) $b['specificity'] <=> (int) $a['specificity'])
                ?: strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if ((string) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The format that applies to one person's payslip.
     *
     * @param array<string, string> $context company, branch, team, designation, employee
     */
    public function forContext(array $context): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($this->all() as $format) {
            $score = 0;

            foreach (['company', 'branch', 'team', 'role', 'designation', 'employee'] as $key) {
                $scoped = trim((string) ($format['scope_' . $key] ?? ''));
                $actual = trim((string) ($context[$key] ?? ''));

                // EMPTY MEANS ANY. A format that names a branch and is asked
                // about somebody in another branch does not apply at all —
                // it is not a weaker match, it is not a match.
                if ($scoped === '' || strcasecmp($scoped, 'All') === 0) {
                    continue;
                }

                // A FORMAT SAVED WITH A DEPARTMENT UNDER "TEAM" (before 28 Sep the
                // Team list offered departments) still matches that department.
                if (strcasecmp($scoped, $actual) !== 0
                    && !($key === 'team' && strcasecmp($scoped, trim((string) ($context['department'] ?? ''))) === 0)) {
                    continue 2;
                }

                $score++;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $format;
            }
        }

        return $best;
    }

    /**
     * Save a format — new when `id` is empty, otherwise the same one again.
     *
     * @param array<string, mixed> $format
     * @return array{ok: bool, why: string, refused: list<string>, id?: string}
     */
    public function save(array $format, string $by): array
    {
        $refused = $this->refusals($format);

        if ($refused !== []) {
            return ['ok' => false, 'refused' => $refused, 'why' => 'Nothing was saved.'];
        }

        $scope = [
            'scope_company' => self::scopeValue($format['company'] ?? ''),
            'scope_branch' => self::scopeValue($format['branch'] ?? ''),
            'scope_team' => self::scopeValue($format['team'] ?? ''),
            'scope_role' => self::scopeValue($format['role'] ?? ''),
            'scope_designation' => self::scopeValue($format['designation'] ?? ''),
            'scope_employee' => self::scopeValue($format['employee'] ?? ''),
        ];

        $row = $scope + [
            'name' => trim((string) $format['name']),
            // DERIVED, NEVER TYPED — the same rule the component rules follow,
            // so the two screens cannot disagree about which is more specific.
            'specificity' => count(array_filter($scope, static fn (string $v): bool => $v !== '')),
            'show_employer' => !empty($format['show_employer']) ? 1 : 0,
            // HEADER PRESENTATION — what prints above the earnings/deductions
            // tables, none of it money. Defaults true: a format saved before
            // these existed keeps showing exactly what it always showed.
            'show_logo' => array_key_exists('show_logo', $format) ? (!empty($format['show_logo']) ? 1 : 0) : 1,
            'show_pan' => array_key_exists('show_pan', $format) ? (!empty($format['show_pan']) ? 1 : 0) : 1,
            'show_uan' => array_key_exists('show_uan', $format) ? (!empty($format['show_uan']) ? 1 : 0) : 1,
            'show_designation' => array_key_exists('show_designation', $format) ? (!empty($format['show_designation']) ? 1 : 0) : 1,
            'show_doj' => array_key_exists('show_doj', $format) ? (!empty($format['show_doj']) ? 1 : 0) : 1,
            // TWO NEWER HEADER FIELDS — 11 Sep. Defaulting them OFF, not on like
            // the five above: those five default true because that is what
            // every format saved before they existed was already printing, and
            // leaving them out must not change anybody's payslip. Address and
            // location never printed before either existed, so "absent" for
            // them means the same thing "off" does.
            'show_company_address' => !empty($format['show_company_address']) ? 1 : 0,
            'show_employee_location' => !empty($format['show_employee_location']) ? 1 : 0,
            'lines' => json_encode(self::cleanLines($format['lines'] ?? []),
                JSON_THROW_ON_ERROR),
            'updated_by' => $by,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = trim((string) ($format['id'] ?? ''));

        if ($id !== '') {
            $row['id'] = $id;
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
        }

        $stored = $this->store->put(self::COLLECTION, $row);

        return ['ok' => true, 'refused' => [], 'id' => (string) ($stored['id'] ?? $id),
            'why' => 'Saved. It applies to ' . self::describe($scope) . '.'];
    }

    /**
     * Save the whole set the designer holds.
     *
     * The designer edits a list and hands back the list, so this reconciles:
     * every format in it is saved, and every format that is NOT in it is
     * retired. A format the customer removed on screen and that quietly stayed
     * in the database would go on printing somebody's payslip.
     *
     * Nothing is written while any format is refused — a half-saved set is
     * neither the old formats nor the new ones, and the payslips printed in
     * between would be from whichever half won.
     *
     * @param list<array<string, mixed>> $formats
     * @return array{ok: bool, why: string, refused: list<string>, saved: int, retired: int}
     */
    public function sync(array $formats, string $by): array
    {
        $refused = [];
        $seenScopes = [];

        foreach ($formats as $index => $format) {
            foreach ($this->refusals($format + ['id' => (string) ($format['id'] ?? '')]) as $reason) {
                $refused[] = 'Format ' . ($index + 1) . ': ' . $reason;
            }

            // WITHIN THE POSTED SET TOO. `refusals()` compares against what is
            // stored; two new formats sharing a scope are only visible here.
            $scope = implode('|', [
                self::scopeValue($format['company'] ?? ''),
                self::scopeValue($format['branch'] ?? ''),
                self::scopeValue($format['team'] ?? ''),
                self::scopeValue($format['role'] ?? ''),
                self::scopeValue($format['designation'] ?? ''),
                self::scopeValue($format['employee'] ?? ''),
            ]);

            if (isset($seenScopes[$scope])) {
                $refused[] = "'" . (string) ($format['name'] ?? '') . "' and '"
                    . $seenScopes[$scope] . "' both apply to exactly the same people. Two formats "
                    . 'that both apply cannot both print.';
            }

            $seenScopes[$scope] = (string) ($format['name'] ?? '');
        }

        if ($refused !== []) {
            return ['ok' => false, 'refused' => array_values(array_unique($refused)),
                'saved' => 0, 'retired' => 0, 'why' => 'Nothing was saved.'];
        }

        $kept = [];
        $saved = 0;

        foreach ($formats as $format) {
            $result = $this->save($format, $by);

            if (!empty($result['id'])) {
                $kept[] = (string) $result['id'];
            }

            $saved++;
        }

        $retired = 0;

        foreach ($this->all() as $existing) {
            if (in_array((string) $existing['id'], $kept, true)) {
                continue;
            }

            $this->retire((string) $existing['id'], $by);
            $retired++;
        }

        return ['ok' => true, 'refused' => [], 'saved' => $saved, 'retired' => $retired,
            'why' => $saved . ' format(s) saved'
                . ($retired > 0 ? ', ' . $retired . ' retired' : '') . '.'];
    }

    /**
     * Everything wrong with a format, in words.
     *
     * @param array<string, mixed> $format
     * @return list<string>
     */
    public function refusals(array $format): array
    {
        $refused = [];
        $name = trim((string) ($format['name'] ?? ''));

        if ($name === '') {
            $refused[] = 'A format needs a name. "Format 3" on a list of four is a choice nobody '
                . 'can make.';
        }

        $known = array_keys($this->components->components());
        $chosen = 0;

        foreach (self::cleanLines($format['lines'] ?? []) as $code => $line) {
            if (!in_array($code, $known, true)) {
                $refused[] = "'{$code}' is not a component in config/salary-components.json. A "
                    . 'payslip line for a component nothing computes is a line that would always '
                    . 'print as empty.';
            }

            $chosen++;
        }

        if ($chosen === 0) {
            $refused[] = 'No components are ticked, so this format would print a payslip with no '
                . 'lines on it. A blank payslip is not a format anybody meant to save.';
        }

        // A NAME THAT IS ALREADY IN USE FOR THE SAME SCOPE. Two formats that
        // both apply cannot both win, and whichever the sort returns first is
        // not a decision anybody made.
        $scope = implode('|', [
            self::scopeValue($format['company'] ?? ''), self::scopeValue($format['branch'] ?? ''),
            self::scopeValue($format['team'] ?? ''), self::scopeValue($format['role'] ?? ''),
            self::scopeValue($format['designation'] ?? ''),
            self::scopeValue($format['employee'] ?? ''),
        ]);

        foreach ($this->all() as $existing) {
            if ((string) $existing['id'] === trim((string) ($format['id'] ?? ''))) {
                continue;
            }

            $existingScope = implode('|', [
                (string) $existing['scope_company'], (string) $existing['scope_branch'],
                (string) $existing['scope_team'], (string) $existing['scope_role'],
                (string) $existing['scope_designation'],
                (string) $existing['scope_employee'],
            ]);

            if ($existingScope === $scope) {
                $refused[] = "'" . (string) $existing['name'] . "' already applies to exactly this "
                    . 'scope. Two formats that both apply to the same people cannot both be used, '
                    . 'and whichever came first is not a decision anybody made — narrow one of '
                    . 'them, or edit that one instead.';
            }
        }

        return $refused;
    }

    /**
     * Retire a format. NOT deleted: a payslip was printed in it, and the answer
     * to "why did last March's slip look like that" has to survive somebody
     * tidying up this March. Retired formats stop matching anybody.
     */
    public function retire(string $id, string $by): void
    {
        $this->store->put(self::COLLECTION, [
            'id' => $id,
            'retired_at' => date('Y-m-d H:i:s'),
            'updated_by' => $by,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A one-line description of who a format applies to. */
    public static function describe(array $scope): string
    {
        $parts = array_values(array_filter([
            (string) ($scope['scope_company'] ?? ''), (string) ($scope['scope_branch'] ?? ''),
            (string) ($scope['scope_team'] ?? ''), (string) ($scope['scope_role'] ?? ''),
            (string) ($scope['scope_designation'] ?? ''),
            (string) ($scope['scope_employee'] ?? ''),
        ]));

        return $parts === [] ? 'everybody' : implode(' · ', $parts);
    }

    /**
     * The lines of a format, in print order, with the component's label.
     *
     * @param array<string, mixed> $format
     * @return array<string, list<array<string, mixed>>> section => lines
     */
    public function linesOf(array $format): array
    {
        $components = $this->components->components();
        $bySection = array_fill_keys(self::SECTIONS, []);

        foreach ((array) $format['lines'] as $code => $line) {
            $component = $components[(string) $code] ?? null;

            if ($component === null) {
                continue;
            }

            $section = (string) $component['kind'];
            $bySection[$section][] = [
                'code' => (string) $code,
                'label' => (string) ($line['label'] ?? '') !== ''
                    ? (string) $line['label'] : (string) $component['label'],
                'order' => (int) ($line['order'] ?? 0),
                'sample' => (string) ($line['sample'] ?? ''),
            ];
        }

        foreach ($bySection as $section => $lines) {
            usort($lines, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $bySection[$section] = $lines;
        }

        return $bySection;
    }

    /**
     * @param mixed $lines
     * @return array<string, array<string, mixed>>
     */
    private static function cleanLines(mixed $lines): array
    {
        $clean = [];

        foreach ((array) $lines as $code => $line) {
            if (empty($line['checked'])) {
                continue;
            }

            $clean[(string) $code] = [
                'order' => (int) ($line['order'] ?? 0),
                // A RENAMED LINE IS STILL THE SAME COMPONENT. The label is what
                // the employee reads; the code is what the money came from, and
                // the two are kept apart so a customer calling conveyance
                // "Travel allowance" does not detach the line from its rule.
                'label' => trim((string) ($line['label'] ?? '')),
                'sample' => trim((string) ($line['sample'] ?? '')),
            ];
        }

        return $clean;
    }

    private static function scopeValue(mixed $value): string
    {
        $text = trim((string) $value);

        return strcasecmp($text, 'All') === 0 ? '' : $text;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        try {
            return $this->store->all(self::COLLECTION);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decoded(array $row): array
    {
        $lines = json_decode((string) ($row['lines'] ?? '{}'), true);
        $row['lines'] = is_array($lines) ? $lines : [];
        $row['show_employer'] = (bool) ($row['show_employer'] ?? false);

        // HEADER PRESENTATION FLAGS — absent on any row saved before they
        // existed, and absent means "on": that is what those rows already
        // printed, so leaving them out must not change anybody's payslip.
        foreach (['show_logo', 'show_pan', 'show_uan', 'show_designation', 'show_doj'] as $flag) {
            $row[$flag] = !array_key_exists($flag, $row) || (bool) $row[$flag];
        }

        // Absent means OFF here — see the doc-comment on save() above.
        foreach (['show_company_address', 'show_employee_location'] as $flag) {
            $row[$flag] = array_key_exists($flag, $row) && (bool) $row[$flag];
        }

        foreach (['company', 'branch', 'team', 'role', 'designation', 'employee'] as $key) {
            $row['scope_' . $key] = (string) ($row['scope_' . $key] ?? '');
        }

        return $row;
    }
}
