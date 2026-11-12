<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * TDS DECLARATIONS, INVESTMENT PROOFS AND THE TAX EACH EMPLOYEE OWES (owner, 14 Oct).
 *
 * The corporate cycle, as Indian employers run it:
 *   1. DECLARATION WINDOW (April, and again for new joiners) — the employee picks the regime and
 *      declares planned investments, rent and previous-employer income (Form 122 / Form 124, old
 *      Form 12BB). Outside the window it is read-only; last year's declaration pre-fills the form.
 *   2. Every payroll close deducts monthly TDS = (projected annual tax − TDS already deducted) ÷
 *      months left, from the declared figures.
 *   3. PROOF WINDOW (typically Jan–Feb) — proofs are uploaded per item, HR approves or rejects.
 *      Reminders go out as the window closes.
 *   4. After the proof cut-off, only APPROVED amounts count, and the last months' TDS absorbs the
 *      difference (organisation setting).
 *
 * The windows and reminders are the organisation's settings (Customisation key `tds-settings`,
 * versioned); every rate and limit is config/income-tax.json; the arithmetic is IncomeTax.
 */
final class TaxDeclarations
{
    public const SETTINGS_KEY = 'tds-settings';
    /** The organisation's own edits to a year's rules (owner, 15 Oct): Customisation key, versioned. */
    public const RULES_KEY = 'income-tax-rules';
    private const DECL = 'tax_declarations';
    private const PROOF = 'tax_proofs';

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $taxFile,
        private readonly int $fyStartMonth = 4,
        private readonly ?\Closure $tdsOff = null,
        private readonly ?\Closure $extras = null,
    ) {
    }

    // ---- settings and windows -------------------------------------------------------------

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings ??= array_merge([
            'fy' => IncomeTax::fyOf(date('Y-m-d'), $this->fyStartMonth),
            'declaration_from' => '', 'declaration_to' => '',
            'proof_from' => '', 'proof_to' => '',
            'proof_reminder_days' => [15, 7, 3, 1],
            'declaration_reminder_days' => [7, 3, 1],
            'after_proof_cutoff' => 'approved_only',
            'tds_in_payroll' => true,
            'new_joiner_days' => 30,
        ], (array) ($this->customisation->live()[self::SETTINGS_KEY] ?? []), [
            // THE YEAR IN FORCE (owner, 15 Oct) is Organization Configuration → Companies → Financial
            // Year: Automatic moves on the first day of each year; a fixed year holds until changed.
            'fy' => $this->autoFy(),
        ], $this->fixedFy());
    }

    public function autoFy(): string
    {
        return IncomeTax::fyOf(date('Y-m-d'), $this->fyStartMonth);
    }

    /** @return array{fy?: string} the year an administrator fixed, when they did */
    private function fixedFy(): array
    {
        $s = (array) ($this->customisation->live()['financial-year'] ?? []);

        if (($s['mode'] ?? 'auto') !== 'fixed' || preg_match('/^\d{4}$/', (string) ($s['active'] ?? '')) !== 1) {
            return [];
        }

        return ['fy' => sprintf('%d-%02d', (int) $s['active'], ((int) $s['active'] + 1) % 100)];
    }

    /** @return array<string, array<string, mixed>> fy => rules: config/income-tax.json, overlaid by the organisation's edits */
    public function years(): array
    {
        $file = (array) (((array) json_decode((string) @file_get_contents($this->taxFile), true))['years'] ?? []);
        $own = (array) (((array) ($this->customisation->live()[self::RULES_KEY] ?? []))['years'] ?? []);

        foreach ($own as $fy => $block) {
            if (is_array($block) && $block !== []) { $file[(string) $fy] = $block + ['source' => 'organisation']; }
        }

        ksort($file);

        return $file;
    }

    /** Years to offer in pickers: every configured year, this year and next. @return list<string> */
    public function yearList(): array
    {
        $next = (int) substr($this->autoFy(), 0, 4) + 1;
        $y = array_values(array_unique(array_merge(array_keys($this->years()), [$this->autoFy(), $this->fy(), sprintf('%d-%02d', $next, ($next + 1) % 100)])));
        sort($y);

        return $y;
    }

    /** Save the organisation's rules for a year (a new version; the earlier ones are kept). @return array{ok: bool, why: string} */
    public function saveRules(string $fy, ?array $block, string $byId, string $byLabel): array
    {
        $s = (array) ($this->customisation->live()[self::RULES_KEY] ?? []);
        $s['years'] = (array) ($s['years'] ?? []);
        if ($block === null) { unset($s['years'][$fy]); } else { unset($block['source'], $block['carried_forward_from']); $s['years'][$fy] = $block; }

        return $this->customisation->saveRaw(self::RULES_KEY, $s, $byId, $byLabel, $block === null ? 'Income-tax rules for ' . $fy . ' reverted to the shipped file' : 'Income-tax rules for ' . $fy);
    }

    public function saveSettings(array $s, string $byId, string $byLabel): array
    {
        $this->settings = null;

        return $this->customisation->saveRaw(self::SETTINGS_KEY, $s, $byId, $byLabel, 'TDS windows and reminders');
    }

    public function fy(): string
    {
        return (string) $this->settings()['fy'];
    }

    /** @return array<string, mixed> the year's rules, [] when config/income-tax.json lacks it */
    public function year(?string $fy = null): array
    {
        return IncomeTax::yearFrom($this->years(), $fy ?? $this->fy());
    }

    /**
     * Is a window open today? A new joiner may declare for `new_joiner_days` after joining even
     * when the window is shut — the practice for mid-year hires.
     *
     * @return array{open: bool, from: string, to: string, why: string}
     */
    public function window(string $kind, string $today, ?array $employee = null): array
    {
        $s = $this->settings();
        $from = (string) ($s[$kind . '_from'] ?? '');
        $to = (string) ($s[$kind . '_to'] ?? '');
        $open = $from !== '' && $today >= $from && ($to === '' || $today <= $to);
        $why = $from === '' ? 'not opened by the organisation yet'
            : ($today < $from ? 'opens on ' . Dates::dmy($from) : ($to !== '' && $today > $to ? 'closed on ' . Dates::dmy($to) : 'open until ' . ($to === '' ? 'further notice' : Dates::dmy($to))));

        if (!$open && $kind === 'declaration' && $employee !== null) {
            $doj = substr((string) ($employee['doj'] ?? ''), 0, 10);
            $days = (int) $s['new_joiner_days'];

            if ($doj !== '' && $days > 0 && $today >= $doj && $today <= date('Y-m-d', (int) strtotime($doj . ' +' . $days . ' days'))) {
                return ['open' => true, 'from' => $doj, 'to' => date('Y-m-d', (int) strtotime($doj . ' +' . $days . ' days')), 'why' => 'open for you as a new joiner until ' . Dates::dmy(date('Y-m-d', (int) strtotime($doj . ' +' . $days . ' days')))];
            }
        }

        return ['open' => $open, 'from' => $from, 'to' => $to, 'why' => $why];
    }

    /** True once the proof window has closed and only approved amounts count. */
    public function proofsCutOff(string $today): bool
    {
        $s = $this->settings();

        return (string) $s['after_proof_cutoff'] === 'approved_only' && (string) $s['proof_to'] !== '' && $today > (string) $s['proof_to'];
    }

    // ---- declarations ---------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function live(string $table): array
    {
        try {
            return array_values(array_filter($this->store->all($table), static fn (array $r): bool => !Modules::isRetired($r)));
        } catch (\Throwable) {
            return []; // the table before its migration: nothing declared yet
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function decoded(array $r): array
    {
        foreach (['items', 'rent'] as $k) {
            $r[$k] = is_array($r[$k] ?? null) ? $r[$k] : ((array) json_decode((string) ($r[$k] ?? ''), true));
        }

        return $r;
    }

    /** @return array<string, mixed>|null the employee's live declaration for the year */
    public function declaration(string $employeeId, string $fy): ?array
    {
        $best = null;

        foreach ($this->live(self::DECL) as $r) {
            if ((string) $r['employee_id'] === $employeeId && (string) $r['financial_year'] === $fy
                && ($best === null || (int) $r['id'] > (int) $best['id'])) {
                $best = $r;
            }
        }

        return $best === null ? null : self::decoded($best);
    }

    /** The most recent declaration from an EARLIER year — what pre-fills a new year's form. */
    public function previous(string $employeeId, string $fy): ?array
    {
        $best = null;

        foreach ($this->live(self::DECL) as $r) {
            if ((string) $r['employee_id'] === $employeeId && (string) $r['financial_year'] < $fy
                && ($best === null || [(string) $r['financial_year'], (int) $r['id']] > [(string) $best['financial_year'], (int) $best['id']])) {
                $best = $r;
            }
        }

        return $best === null ? null : self::decoded($best);
    }

    /** @return array<string, array<string, mixed>> employee id => live declaration, for a year */
    public function allFor(string $fy): array
    {
        $out = [];

        foreach ($this->live(self::DECL) as $r) {
            $e = (string) $r['employee_id'];
            if ((string) $r['financial_year'] === $fy && (!isset($out[$e]) || (int) $r['id'] > (int) $out[$e]['id'])) {
                $out[$e] = $r;
            }
        }

        return array_map(self::decoded(...), $out);
    }

    /**
     * Save a declaration as a NEW row and retire the employee's earlier one for the year (law 3).
     *
     * @param array<string, string> $items code => rupees @param array<string, string> $rent
     * @return array<string, mixed> the stored row
     */
    public function save(string $employeeId, string $fy, string $regime, array $items, array $rent, bool $parentsSenior, string $by): array
    {
        $earlier = $this->declaration($employeeId, $fy);
        $row = $this->store->put(self::DECL, [
            'employee_id' => $employeeId, 'financial_year' => $fy, 'regime' => $regime,
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE), 'rent' => json_encode($rent, JSON_UNESCAPED_UNICODE),
            'parents_senior' => $parentsSenior ? 'yes' : 'no', 'status' => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'), 'submitted_by' => $by,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($earlier !== null) {
            $this->store->put(self::DECL, ['id' => $earlier['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
        }

        return self::decoded($row);
    }

    // ---- proofs ---------------------------------------------------------------------------

    /** @return list<array<string, mixed>> live proofs for a year, one employee or all */
    public function proofs(string $fy, ?string $employeeId = null): array
    {
        return array_values(array_filter($this->live(self::PROOF), static fn (array $r): bool
            => (string) $r['financial_year'] === $fy && ($employeeId === null || (string) $r['employee_id'] === $employeeId)));
    }

    public function addProof(string $employeeId, string $fy, string $code, string $amount, string $path, string $name): array
    {
        return $this->store->put(self::PROOF, [
            'employee_id' => $employeeId, 'financial_year' => $fy, 'item_code' => $code,
            'amount_claimed' => Amount::of($amount)->toDecimalString(), 'file_path' => $path, 'file_name' => $name,
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function proof(string $id): ?array
    {
        foreach ($this->live(self::PROOF) as $r) {
            if ((string) $r['id'] === $id) { return $r; }
        }

        return null;
    }

    public function review(string $id, string $status, string $approved, string $note, string $by): array
    {
        return $this->store->put(self::PROOF, [
            'id' => $id, 'status' => $status,
            'approved_amount' => $status === 'approved' ? Amount::of($approved)->toDecimalString() : '0.0000',
            'review_note' => $note === '' ? null : $note, 'reviewed_by' => $by,
            'reviewed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function withdrawProof(string $id): void
    {
        $this->store->put(self::PROOF, ['id' => $id, 'deleted_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array<string, int> item code => approved paise */
    public function approved(string $employeeId, string $fy): array
    {
        $out = [];

        foreach ($this->proofs($fy, $employeeId) as $p) {
            if ((string) $p['status'] === 'approved') {
                $c = (string) $p['item_code'];
                $out[$c] = ($out[$c] ?? 0) + IncomeTax::p($p['approved_amount']);
            }
        }

        return $out;
    }

    /**
     * Items that NEED a proof: declared, not taken from payroll, not plain income.
     *
     * @return array<string, array<string, mixed>> code => item (HRA included when rent is declared)
     */
    public function proofItems(array $declaration, string $fy): array
    {
        $out = [];

        foreach ((array) ($this->year($fy)['items'] ?? []) as $item) {
            $c = (string) $item['code'];
            if (isset($item['auto']) || ($item['income'] ?? false) || ($item['no_proof'] ?? false) || $c === 'NPS_EMPLOYER') { continue; }
            if (IncomeTax::p($declaration['items'][$c] ?? '') > 0) { $out[$c] = $item; }
        }

        if (self::rentAnnual((array) ($declaration['rent'] ?? [])) > 0) {
            $out = ['HRA' => ['code' => 'HRA', 'label' => 'House rent (HRA)', 'proof' => (string) ($this->year($fy)['proofs']['HRA'] ?? '')]] + $out;
        }

        return $out;
    }

    /** @param array<string, mixed> $rent */
    public static function rentAnnual(array $rent): int
    {
        return IncomeTax::p($rent['monthly'] ?? '') * max(0, min(12, (int) ($rent['months'] ?? 12)));
    }

    // ---- what counts, and the tax ---------------------------------------------------------

    /**
     * The amounts the tax is worked on: declared, or — once the proof window is shut and the
     * organisation counts approved only — what HR approved. Income is always counted as declared.
     *
     * @return array{items: array<string, int>, rent_annual: int, basis: string}
     */
    public function counted(?array $declaration, string $employeeId, string $fy, string $today): array
    {
        $items = [];
        foreach ((array) ($declaration['items'] ?? []) as $c => $v) { $items[(string) $c] = IncomeTax::p($v); }
        $rent = self::rentAnnual((array) ($declaration['rent'] ?? []));

        if ($declaration === null || !$this->proofsCutOff($today)) {
            return ['items' => $items, 'rent_annual' => $rent, 'basis' => 'declared'];
        }

        $ok = $this->approved($employeeId, $fy);
        $need = $this->proofItems($declaration, $fy);

        foreach (array_keys($need) as $c) {
            $c === 'HRA' ? $rent = min($rent, $ok['HRA'] ?? 0) : $items[$c] = min($items[$c] ?? 0, $ok[$c] ?? 0);
        }

        return ['items' => $items, 'rent_annual' => $rent, 'basis' => 'approved proofs'];
    }

    /** Age at the end of the financial year (how the Act tests "60 or more"). */
    public function ageAt(array $employee, string $fy): int
    {
        $dob = substr((string) ($employee['dob'] ?? ''), 0, 10);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) !== 1) {
            return 30;
        }

        $end = (int) substr($fy, 0, 4) + 1 . sprintf('-%02d-01', $this->fyStartMonth);

        return (int) (new \DateTimeImmutable($dob))->diff((new \DateTimeImmutable($end))->modify('-1 day'))->y;
    }

    public function isMetro(string $city, string $fy): bool
    {
        foreach ((array) ($this->year($fy)['hra']['metro_cities'] ?? []) as $m) {
            if (strcasecmp(trim($city), (string) $m) === 0) { return true; }
        }

        return false;
    }

    /** Closed, not-discarded payroll runs as [period => [key => row]] — read straight off the table. */
    public function runRows(): array
    {
        $out = [];

        try {
            foreach ($this->store->all('payroll_runs') as $run) {
                if (strtolower((string) ($run['status'] ?? '')) === 'discarded') { continue; }
                $p = (string) ($run['period'] ?? $run['cycle_label'] ?? '');
                $rows = $run['rows'] ?? $run['run_rows'] ?? [];
                $out[$p] = is_array($rows) ? $rows : (array) json_decode((string) $rows, true);
            }
        } catch (\Throwable) {
            return []; // no payroll table yet: nothing closed
        }

        return $out;
    }

    /**
     * THE YEAR'S SALARY FIGURES: what closed runs in the year paid before `$fromPeriod`, plus the
     * monthly figures × the months left from `$fromPeriod` to March.
     *
     * @param array<string, int> $monthly component code => paise, earnings; 'DED:<code>' deductions;
     *     'GROSS' the month's gross
     * @return array<string, int>
     */
    public function figures(array $employee, string $fy, string $fromPeriod, array $monthly, array $runRows, bool $estimate = true): array
    {
        $codes = (array) ($this->year($fy)['salary_codes'] ?? []);
        $in = static fn (string $set, string $code): bool => in_array($code, (array) ($codes[$set] ?? []), true);
        $keys = array_filter([(string) ($employee['id'] ?? ''), Employment::code($employee)]);
        $fyFrom = substr($fy, 0, 4) . sprintf('-%02d', $this->fyStartMonth);
        $closedPeriods = [];
        $f = ['gross' => 0, 'basic_da' => 0, 'hra' => 0, 'lta' => 0, 'employee_pf' => 0, 'pt' => 0, 'employer_nps' => 0, 'tds_paid' => 0, 'closed' => 0];

        foreach ($runRows as $period => $rows) {
            if ($period < $fyFrom || $period >= $fromPeriod) { continue; }
            $row = null;
            foreach ($keys as $k) { $row ??= $rows[$k] ?? null; }
            if (!is_array($row)) { continue; }
            $f['closed']++;
            $closedPeriods[$period] = true;
            $f['gross'] += IncomeTax::p($row['earnings'] ?? '');
            foreach ((array) ($row['lines'] ?? []) as $l) {
                // A structure line is stored as C-BASIC / C-HRA; the rules name BASIC / HRA.
                $c = (string) preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? ''));
                $m = (int) ($l['minor'] ?? 0);
                $earn = ($l['kind'] ?? '') === 'earning';
                $earn && $in('basic_da', $c) && $f['basic_da'] += $m;
                $earn && $in('hra', $c) && $f['hra'] += $m;
                $earn && $in('lta_component', $c) && $f['lta'] += $m;
                !$earn && $in('employee_pf', $c) && $f['employee_pf'] += $m;
                !$earn && $in('professional_tax', $c) && $f['pt'] += $m;
                $in('employer_nps', $c) && $f['employer_nps'] += $m;
                !$earn && $in('tds', $c) && $f['tds_paid'] += $m;
            }
        }

        // MONTHS OF THE YEAR ALREADY GONE BUT NOT CLOSED HERE (since joining) are estimated at this
        // month's salary — otherwise a year whose early months were paid elsewhere looks half as big.
        $doj = substr((string) ($employee['doj'] ?? ''), 0, 7);
        $f['estimated'] = 0;
        for ($p = $fyFrom; $p < substr($fromPeriod, 0, 7); $p = date('Y-m', (int) strtotime($p . '-01 +1 month'))) {
            if ($estimate && ($doj === '' || $p >= $doj) && !isset($closedPeriods[$p])) { $f['estimated']++; }
        }
        $left = (substr($fromPeriod, 0, 7) >= $fyFrom ? IncomeTax::monthsLeft($fromPeriod, $this->fyStartMonth) : 0);
        $proj = $left + $f['estimated'];
        $sum = static function (string $set, string $prefix = '') use ($monthly, $codes): int {
            $t = 0;
            foreach ((array) ($codes[$set] ?? []) as $c) { $t += (int) ($monthly[$prefix . $c] ?? 0); }
            return $t;
        };
        $f['gross'] += (int) ($monthly['GROSS'] ?? 0) * $proj;
        $f['basic_da'] += $sum('basic_da') * $proj;
        $f['hra'] += $sum('hra') * $proj;
        $f['lta'] += $sum('lta_component') * $proj;
        $f['employee_pf'] += $sum('employee_pf', 'DED:') * $proj;
        $f['pt'] += $sum('professional_tax', 'DED:') * $proj;
        $f['employer_nps'] += ($sum('employer_nps') + $sum('employer_nps', 'ER:')) * $proj;
        $f['months_left'] = $left;

        return $f;
    }

    /**
     * Both regimes' sheets for one employee, and the one that applies.
     *
     * @return array{new: array<string, mixed>, old: array<string, mixed>, regime: string, basis: string, figures: array<string, int>}
     */
    public function sheets(array $employee, string $fy, array $figures, ?array $declaration, string $today, ?string $regime = null, ?array $overrideItems = null, ?array $overrideRent = null): array
    {
        $year = $this->year($fy);
        $counted = $this->counted($declaration, (string) $employee['id'], $fy, $today);
        $items = $overrideItems ?? $counted['items'];
        $rent = $overrideRent ?? ['annual' => $counted['rent_annual'], 'city' => (string) ($declaration['rent']['city'] ?? '')];
        $city = (string) ($rent['city'] ?? '') ?: (string) ($employee['present_city'] ?? '');
        $in = [
            'age' => $this->ageAt($employee, $fy), 'resident' => $this->isResident($employee), 'gross' => $figures['gross'], 'basic_da' => $figures['basic_da'],
            'hra_received' => $figures['hra'], 'lta_received' => $figures['lta'], 'employee_pf' => $figures['employee_pf'],
            'pt' => $figures['pt'], 'employer_nps' => $figures['employer_nps'], 'rent_annual' => (int) ($rent['annual'] ?? 0),
            'metro' => $this->isMetro($city, $fy), 'parents_senior' => (string) ($declaration['parents_senior'] ?? '') === 'yes',
            'items' => $items,
        ];

        return [
            'new' => IncomeTax::compute($year, 'new', $in),
            'old' => IncomeTax::compute($year, 'old', $in),
            'regime' => $regime ?? (string) ($declaration['regime'] ?? $year['default_regime'] ?? 'new'),
            'basis' => $counted['basis'],
            'figures' => $figures,
            'paid_before' => (int) ($items['PREV_TDS'] ?? 0) + (int) ($items['EARLIER_TDS'] ?? 0),
        ];
    }

    /**
     * THE MONTH'S TDS FOR A PAYROLL CLOSE: the chosen regime's annual tax, less TDS already
     * deducted this year (earlier closes, the previous employer, TDS on this month's incentives),
     * spread over the months left. Null when TDS does not apply or the year has no rules.
     *
     * @param array<string, Money> $base the month's full salary, component => Money (+ GROSS, DED:*)
     * @param list<array<string, mixed>> $lines this month's other input lines (incentives, bonus…)
     * @return array{amount: Money, trace: string}|null
     */
    public function monthlyTds(array $employee, string $period, array $base, array $lines): ?array
    {
        // TDS APPLICABLE = No on the employee (kept beside the record), or TDS off during probation,
        // is StatutoryApplicability's answer — asked here, not re-read from a column that is not there.
        if (!(bool) $this->settings()['tds_in_payroll'] || ($this->tdsOff !== null && ($this->tdsOff)($employee))) {
            return null;
        }

        $fy = IncomeTax::fyOf($period . '-01', $this->fyStartMonth);

        if ($this->year($fy) === []) {
            return null;
        }

        $monthly = [];
        foreach ($base as $k => $m) { $monthly[(string) $k] = $m instanceof Money ? $m->minor : IncomeTax::p($m); }
        $extraEarn = 0;
        $incentiveTds = 0;
        foreach ($lines as $l) {
            ($l['kind'] ?? '') === 'earning' && $extraEarn += (int) ($l['minor'] ?? 0);
            in_array((string) ($l['code'] ?? ''), (array) ($this->year($fy)['salary_codes']['tds'] ?? []), true) && ($l['kind'] ?? '') !== 'earning' && $incentiveTds += (int) ($l['minor'] ?? 0);
        }

        $decl = $this->declaration((string) $employee['id'], $fy);
        $f = $this->figures($employee, $fy, $period, $monthly, $this->runRows(), self::estimates($decl));
        $f['gross'] += $extraEarn;
        $s = $this->sheets($employee, $fy, $f, $decl, date('Y-m-d'));
        $sheet = $s[$s['regime']];
        $paid = $f['tds_paid'] + $s['paid_before'] + $incentiveTds;
        $amount = IncomeTax::monthly($sheet['total'], $paid, IncomeTax::monthsLeft($period, $this->fyStartMonth));

        return ['amount' => Money::ofMinor($amount), 'trace' => sprintf(
            'TDS on salary, %s regime, %s: annual tax %s on taxable income %s (amounts %s%s), less %s already deducted, over %d month(s) left',
            $s['regime'], $fy, Money::ofMinor($sheet['total'])->format(), Money::ofMinor($sheet['taxable'])->format(),
            $decl === null ? 'no declaration — default regime, payroll figures only' : $s['basis'], '',
            Money::ofMinor($paid)->format(), IncomeTax::monthsLeft($period, $this->fyStartMonth),
        )];
    }

    /**
     * MONTHS OF THE YEAR BEFORE SMARTPRS 3 WENT LIVE are estimated at this month's salary — unless HR
     * has entered what was actually paid then (EARLIER_SALARY), which then replaces the estimate.
     */
    public static function estimates(?array $declaration): bool
    {
        return IncomeTax::p($declaration['items']['EARLIER_SALARY'] ?? '') === 0;
    }

    /**
     * TDS ON SALARY THE PAYROLL DEDUCTED, per wage month of the year (closed runs only): what Part A
     * of Form 130 (old Form 16) lists quarter by quarter.
     *
     * @return array<string, int> period (YYYY-MM) => paise
     */
    public function tdsByPeriod(array $employee, string $fy, array $runRows): array
    {
        $codes = (array) ($this->year($fy)['salary_codes']['tds'] ?? []);
        $keys = array_filter([(string) ($employee['id'] ?? ''), Employment::code($employee)]);
        $fyFrom = substr($fy, 0, 4) . sprintf('-%02d', $this->fyStartMonth);
        $fyTo = date('Y-m', (int) strtotime($fyFrom . '-01 +11 months'));
        $out = [];

        foreach ($runRows as $period => $rows) {
            if ($period < $fyFrom || $period > $fyTo) { continue; }
            $row = null;
            foreach ($keys as $k) { $row ??= $rows[$k] ?? null; }
            if (!is_array($row)) { continue; }
            foreach ((array) ($row['lines'] ?? []) as $l) {
                $c = (string) preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? ''));
                if (($l['kind'] ?? '') !== 'earning' && in_array($c, $codes, true)) {
                    $out[(string) $period] = ($out[(string) $period] ?? 0) + (int) ($l['minor'] ?? 0);
                }
            }
        }

        ksort($out);

        return $out;
    }

    /** Residential status for income tax (kept beside the record); blank = Resident. */
    public function isResident(array $employee): bool
    {
        $v = strtolower(trim((string) ($this->extras === null ? '' : (($this->extras)($employee)['residential_status'] ?? ''))));

        return !str_starts_with($v, 'non');
    }

    /** Is TDS switched off for this employee (TDS Applicable = No, or off during probation)? */
    public function tdsOff(array $employee): bool
    {
        return $this->tdsOff !== null && ($this->tdsOff)($employee);
    }

    // ---- reminders -------------------------------------------------------------------------

    /**
     * REMINDERS, once a day at most (the caller throttles): when a window opens, and on each of the
     * organisation's reminder days before it closes — the declaration to those who have not
     * declared, proofs to those with declared items still unproven. Deduped per person per day.
     *
     * @param list<array<string, mixed>> $employees active employees
     * @return int messages queued
     */
    public function remind(array $employees, Notifications $notifications, string $today): int
    {
        $s = $this->settings();
        $fy = $this->fy();
        $sent = 0;
        $due = static function (string $kind) use ($s, $today): ?int {
            $from = (string) ($s[$kind . '_from'] ?? '');
            $to = (string) ($s[$kind . '_to'] ?? '');
            if ($from === '' || $today < $from || ($to !== '' && $today > $to)) { return null; }
            if ($today === $from) { return -1; } // the window opened today
            if ($to === '') { return null; }
            $left = (int) ((strtotime($to) - strtotime($today)) / 86400);
            return in_array($left, array_map('intval', (array) ($s[$kind . '_reminder_days'] ?? [])), true) ? $left : null;
        };
        $declLeft = $due('declaration');
        $proofLeft = $due('proof');

        if ($declLeft === null && $proofLeft === null) {
            return 0;
        }

        $all = $this->allFor($fy);

        foreach ($employees as $e) {
            $code = Employment::code($e);
            if ($code === '') { continue; }
            $d = $all[(string) $e['id']] ?? null;

            if ($declLeft !== null && ($declLeft === -1 || $d === null)) {
                $sent += (int) $notifications->queue('tds.declaration_reminder', $code, 'tds-decl:' . $fy . ':' . $code . ':' . $today, [
                    'fy' => $fy, 'to' => (string) $s['declaration_to'] === '' ? 'further notice' : Dates::dmy((string) $s['declaration_to']),
                    'state' => $d === null ? 'You have not declared yet.' : 'You can still change what you declared.',
                ]);
            }

            if ($proofLeft !== null && $d !== null) {
                $need = $this->proofItems($d, $fy);
                $have = array_unique(array_map(static fn (array $p): string => (string) $p['item_code'], $this->proofs($fy, (string) $e['id'])));
                $missing = array_diff(array_keys($need), $have);

                if ($missing !== []) {
                    $sent += (int) $notifications->queue('tds.proof_reminder', $code, 'tds-proof:' . $fy . ':' . $code . ':' . $today, [
                        'fy' => $fy, 'to' => (string) $s['proof_to'] === '' ? 'further notice' : Dates::dmy((string) $s['proof_to']),
                        'items' => implode(', ', array_map(static fn (string $c): string => (string) ($need[$c]['label'] ?? $c), $missing)),
                    ]);
                }
            }
        }

        return $sent;
    }
}
