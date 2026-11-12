<?php declare(strict_types=1);
/**
 * TDS SCREEN HELPERS (14 Oct) — shared by TDS Declaration, Investment Proofs, the export and the
 * employee profile. The month's package comes from the SAME engine the payslip uses (structureFor +
 * PayrollRun, as Live Salary does), at a full month; the year is closed months from the payroll
 * plus that month × the months left (TaxDeclarations::figures).
 */
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;

if (!function_exists('tdsPackage')) {
    /** The employee's full month: lines for display and the code => paise map. @return array{lines: list<array{code: string, kind: string, minor: int}>, monthly: array<string, int>, why: ?string} */
    function tdsPackage(array $e): array
    {
        global $structure, $componentRules;
        $out = ['lines' => [], 'monthly' => [], 'why' => null];

        try {
            $days = (int) date('t');
            $components = structureFor($e);
            $slip = (new PayrollRun(date('Y-m'), $components, [[
                'employment_id' => Employment::code($e) ?: (string) $e['id'],
                'gross' => monthlyGrossOf($e, $structure->contractedCostBasis(date('Y-m-t')), $components),
                'payable_days' => Rational::of($days), 'period_days' => $days,
            ]]))->payslips[0];
        } catch (\Throwable $x) {
            $out['why'] = $x->getMessage();

            return $out;
        }

        foreach ($slip->lines as $l) {
            $code = (string) $l->componentCode;
            $out['lines'][] = ['code' => $code, 'kind' => (string) $l->kind, 'minor' => $l->amount->minor, 'label' => $componentRules->labelFor($code)];
            $l->kind === 'earning' ? $out['monthly'][(string) preg_replace('/^[A-Z]-/', '', $code)] = ($out['monthly'][(string) preg_replace('/^[A-Z]-/', '', $code)] ?? 0) + $l->amount->minor
                : $out['monthly'][($l->kind === 'deduction' ? 'DED:' : 'ER:') . $code] = $l->amount->minor;
        }

        $out['monthly']['GROSS'] = $slip->grossEarnings()->minor;

        return $out;
    }

    /** Both regimes for one employee, for the year. @return array<string, mixed> */
    function tdsSheets(array $e, string $fy, ?array $decl, ?array $items = null, ?array $rent = null, ?string $regime = null): array
    {
        global $taxDeclarations;
        $pkg = tdsPackage($e);
        $from = date('Y-m');
        $fyFrom = substr($fy, 0, 4) . sprintf('-%02d', financialYearStartGlobal());
        $fyTo = date('Y-m', (int) strtotime($fyFrom . '-01 +11 months'));
        // A year not yet begun projects from its first month; one already over, from nothing left.
        $from = $from < $fyFrom ? $fyFrom : ($from > $fyTo ? date('Y-m', (int) strtotime($fyTo . '-01 +1 month')) : $from);
        $f = $taxDeclarations->figures($e, $fy, $from, $pkg['monthly'], $taxDeclarations->runRows(), \SmartPRS\Surface\TaxDeclarations::estimates($decl));
        $s = $taxDeclarations->sheets($e, $fy, $f, $decl, date('Y-m-d'), $regime, $items, $rent);
        $s['package'] = $pkg;
        $chosen = $s[$s['regime']];
        $s['tds_off'] = $taxDeclarations->tdsOff($e);
        $s['monthly_tds'] = $s['tds_off'] ? 0 : IncomeTax::monthly($chosen['total'], $f['tds_paid'] + $s['paid_before'], max(1, (int) $f['months_left']));

        return $s;
    }

    function financialYearStartGlobal(): int
    {
        global $customisation;

        return financialYearStart($customisation);
    }

    function tdsMoney(int $minor): string
    {
        return e(Money::ofMinor($minor)->format());
    }

    /** The computation sheet, both regimes side by side, the applicable one marked. */
    function tdsSheetHtml(array $s): string
    {
        $chosen = (string) $s['regime'];
        $better = $s['new']['total'] <= $s['old']['total'] ? 'new' : 'old';
        $saves = abs($s['new']['total'] - $s['old']['total']);
        $h = '<div class="tds-cmp">';

        foreach (['new' => 'New regime', 'old' => 'Old regime'] as $k => $label) {
            $sh = $s[$k];
            $h .= '<div class="tds-col' . ($k === $chosen ? ' on' : '') . '"><div class="tds-col-h"><b>' . $label . '</b>'
                . ($k === $chosen ? ' <span class="badge badge-blue">applies</span>' : '')
                . ($k === $better && $saves > 0 ? ' <span class="badge badge-green">lower by ' . tdsMoney($saves) . '</span>' : '') . '</div><table class="tds-sheet">';
            foreach ($sh['lines'] as $l) {
                $strong = in_array($l[0], ['Income from salary', 'Gross total income', 'Taxable income', 'Total tax for the year'], true);
                $h .= '<tr' . ($strong ? ' class="tds-strong"' : '') . '><td>' . e((string) $l[0]) . (($l[2] ?? '') !== '' ? '<br><span class="note">' . e((string) $l[2]) . '</span>' : '')
                    . '</td><td class="tds-n">' . ($l[1] < 0 ? '(' . tdsMoney(-$l[1]) . ')' : tdsMoney($l[1])) . '</td></tr>';
            }
            $h .= '</table>' . (($sh['why'] ?? '') !== '' ? '<div class="note" style="padding:8px 14px">' . e((string) $sh['why']) . '</div>' : '') . '</div>';
        }

        $f = $s['figures'];
        $h .= '</div><div class="tds-foot"><div><span class="note">TDS already deducted this year</span><b>' . tdsMoney($f['tds_paid'] + $s['paid_before']) . '</b></div>'
            . '<div><span class="note">Months left (from this month)</span><b>' . (int) $f['months_left'] . '</b></div>'
            . '<div><span class="note">Monthly TDS (' . e($chosen) . ' regime)</span><b style="color:var(--accent)">' . tdsMoney((int) $s['monthly_tds']) . '</b>' . (($s['tds_off'] ?? false) ? '<span class="note">TDS is off for this employee (TDS Applicable = No, or probation)</span>' : '') . '</div>'
            . '<div><span class="note">Worked on</span><b>' . e((string) $s['basis']) . '</b></div></div>'
            . tdsSumTag($s)
            . '<p class="note" style="margin:8px 0 0">Year = ' . (int) $f['closed'] . ' closed payroll month(s)' . ((int) ($f['estimated'] ?? 0) > 0 ? ' + ' . (int) $f['estimated'] . ' earlier month(s) not closed here, estimated at this month\'s salary' : '') . ' + this month\'s full salary × ' . (int) $f['months_left'] . ' month(s) left. Every rate and limit is from config/income-tax.json.</p>';

        return $h;
    }

    /** Both regimes' taxable income, annual tax and monthly TDS as data, for the package table's live rows. */
    function tdsSumTag(array $s): string
    {
        $f = $s['figures'];
        $paid = (int) $f['tds_paid'] + (int) $s['paid_before'];
        $a = ' data-regime="' . e((string) $s['regime']) . '"';
        foreach (['new', 'old'] as $k) {
            $taxable = (int) $s[$k]['taxable'];
            $total = (int) $s[$k]['total'];
            $mon = ($s['tds_off'] ?? false) ? 0 : IncomeTax::monthly($total, $paid, max(1, (int) $f['months_left']));
            $a .= ' data-' . $k . '-taxable="' . tdsMoney($taxable) . '" data-' . $k . '-taxable-m="' . tdsMoney(intdiv($taxable, 12)) . '"'
                . ' data-' . $k . '-total="' . tdsMoney($total) . '" data-' . $k . '-monthly="' . tdsMoney($mon) . '"';
        }

        return '<span id="tds-sum" hidden' . $a . '></span>';
    }

    function tdsCss(): string
    {
        return '<style>
  .tds-cmp{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px}
  .tds-col{border:1px solid var(--border);border-radius:10px;overflow:hidden;background:var(--card)}
  .tds-col.on{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
  .tds-col-h{padding:10px 14px;border-bottom:1px solid var(--border);background:color-mix(in srgb,var(--text1) 4%,var(--card))}
  .tds-sheet{width:100%;border-collapse:collapse;font-size:13px}
  .tds-sheet td{padding:6px 14px;border-bottom:1px solid var(--border)}
  .tds-n{text-align:right;font-family:var(--mono);white-space:nowrap}
  .tds-strong td{font-weight:700;background:color-mix(in srgb,var(--text1) 3%,var(--card))}
  .tds-foot{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px}
  .tds-foot > div{border:1px solid var(--border);border-radius:10px;padding:10px 14px;display:flex;flex-direction:column;gap:4px}
  .tds-foot b{font-size:18px;font-family:var(--mono)}
  .tds-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px 16px}
  .tds-sec{border:1px solid var(--border);border-radius:10px;margin-bottom:14px;overflow:hidden}
  .tds-sec > h4{margin:0;padding:10px 14px;font-size:13px;background:color-mix(in srgb,var(--text1) 4%,var(--card));border-bottom:1px solid var(--border)}
  .tds-sec > .tds-body{padding:12px 14px}
  .tds-sec .note.cap{display:block;margin-top:2px}
</style>';
    }
}

if (!function_exists('tdsWho')) {
    /**
     * WHOSE TAX THIS SCREEN SHOWS. HR (payroll.view) may open anyone in reach with ?id=; everybody
     * else sees only their own record — an account with no employee record sees nothing (fails closed).
     *
     * @return array{e: ?array, hr: bool, self: bool}
     */
    function tdsWho(): array
    {
        global $session, $store, $scope, $may;
        $hr = $may('payroll.view');
        $own = (string) ($session->user()['employee_id'] ?? '');
        $id = $hr ? (string) ($_GET['id'] ?? $_POST['employee_id'] ?? '') : $own;

        if ($id === '') {
            return ['e' => null, 'hr' => $hr, 'self' => false];
        }

        $e = $store->find('employees', $id);
        $inReach = $e !== null && ($id === $own || $scope->only([$e], $may('employee.list')) !== []);

        return ['e' => $inReach ? $e : null, 'hr' => $hr, 'self' => $id === $own];
    }

    /** The Company / Branch buttons + organisation filter, shared by the HR lists. */
    function tdsOrgButtons(string $route, array $filter, array $scoped, array $counts, string $keepQs, string $extra = '', ?array $period = null): string
    {
        global $store;
        $href = static fn (array $over): string => '?r=' . $route . orgFilterQuery(array_merge($filter, ['employee' => ''], $over)) . $keepQs;
        $count = static function (string $k, ?string $company = null) use ($scoped, $counts, $store): array {
            $n = [];
            foreach ($scoped as $e) {
                if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $e, 'company'), $company) !== 0) { continue; }
                ($v = orgValueOf($store, $e, $k)) === '' || $n[$v] = ($n[$v] ?? 0) + (int) ($counts[(string) $e['id']] ?? 0);
            }
            ksort($n, SORT_NATURAL | SORT_FLAG_CASE);
            return $n;
        };
        $co = $count('company');
        $br = $count('branch', $filter['company']);
        $brCo = [];
        foreach ($scoped as $e) { $b = orgValueOf($store, $e, 'branch'); $b === '' || $brCo[$b] ??= orgValueOf($store, $e, 'company'); }
        $h = '<style>.ch-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}.ch-row > b{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);min-width:74px}'
            . '.ch{--c:var(--text2);display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;background:var(--card);border:1px solid color-mix(in srgb,var(--c) 45%,var(--border));color:var(--c)}'
            . '.ch:hover{background:color-mix(in srgb,var(--c) 8%,var(--card))}.ch i{font-size:11.5px}.ch span{font-size:11px;font-weight:600;padding:0 7px;border-radius:6px;background:color-mix(in srgb,var(--c) 12%,transparent);color:var(--c)}'
            . '.ch.on{background:var(--c);border-color:var(--c);color:#fff}.ch.on span{background:rgba(255,255,255,.22);color:#fff}'
            . '.ch::before{content:"\f0c8";font-family:"Font Awesome 6 Free";font-weight:400;font-size:13px;line-height:1}.ch.on::before{content:"\f14a";font-weight:900}'
            . '.ch.all{--c:var(--text1)}.ch.all:not(.on){color:var(--text2);border-color:var(--border)}'
            . '.tds-orgf .org-filter > div:has(#of_company), .tds-orgf .org-filter > div:has(#of_branch){display:none}</style>';
        $h .= '<div class="tds-orgf"><div class="ch-row"><b>Company</b><a class="ch all' . ($filter['company'] === '' ? ' on' : '') . '" href="' . $href(['company' => '', 'branch' => '']) . '"><i class="fas fa-layer-group"></i> All companies <span>' . array_sum($co) . '</span></a>';
        foreach ($co as $n => $c) {
            $h .= '<a class="ch' . (strcasecmp($filter['company'], (string) $n) === 0 ? ' on' : '') . '" style="--c:' . companyColor($store, (string) $n) . '" href="' . $href(['company' => (string) $n, 'branch' => '']) . '"><i class="fas fa-building"></i> ' . e((string) $n) . ' <span>' . $c . '</span></a>';
        }
        $h .= '</div><div class="ch-row"><b>Branch</b><a class="ch all' . ($filter['branch'] === '' ? ' on' : '') . '" href="' . $href(['branch' => '']) . '"><i class="fas fa-map-location-dot"></i> All branches <span>' . array_sum($br) . '</span></a>';
        foreach ($br as $n => $c) {
            $h .= '<a class="ch' . (strcasecmp($filter['branch'], (string) $n) === 0 ? ' on' : '') . '" style="--c:' . companyColor($store, (string) ($brCo[(string) $n] ?? '')) . '" href="' . $href(['branch' => (string) $n]) . '"><i class="fas fa-location-dot"></i> ' . e((string) $n) . ' <span>' . $c . '</span></a>';
        }

        return $h . '</div>' . orgFilterBar($route, $filter, orgFilterOptions($store, $scoped), [], 'Show', null, $period, $period === null ? null : PERIOD_MODES_ALL, $extra) . '</div>';
    }

    /** Rows for the HR list and its export: one per active employee in reach passing the filter. */
    function tdsRows(string $fy, array $filter, string $state): array
    {
        global $store, $scope, $may, $taxDeclarations;
        $scoped = array_values(array_filter($scope->only(liveRows($store, 'employees'), $may('employee.list')),
            static fn (array $e): bool => in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true)));
        $decls = $taxDeclarations->allFor($fy);
        $rows = [];

        foreach (orgFilterApply($store, $scoped, $filter) as $e) {
            $d = $decls[(string) $e['id']] ?? null;
            if (($state === 'declared' && $d === null) || ($state === 'pending' && $d !== null)) { continue; }
            $rows[] = ['e' => $e, 'd' => $d];
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp(Employment::code($a['e']), Employment::code($b['e'])));

        return ['rows' => $rows, 'scoped' => $scoped, 'decls' => $decls];
    }
}
