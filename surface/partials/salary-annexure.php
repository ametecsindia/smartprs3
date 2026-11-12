<?php declare(strict_types=1);
/**
 * THE SALARY STRUCTURE ANNEXURE (owner, 15 Oct) — the table an offer / increment letter attaches: the
 * employee, every component monthly and yearly, gross, deductions, employer contributions, take-home and
 * CTC, and the date that structure took effect (SalaryEffective). Worked by the payroll engine itself
 * (structureFor + PayrollRun at a full month, gross from monthlyGrossOf), on the CTC in force on that date.
 * Used by the Salary Breakup icon, the offer / increment letters ({{salary_structure}}) and their print.
 */
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Surface\Employment;

if (!function_exists('salaryEffectiveFor')) {
    /** When the employee's salary structure took effect, as on $asOf (today). */
    function salaryEffectiveFor(array $e, ?string $asOf = null): array
    {
        global $store, $calendar;
        static $companies = null, $incs = null;
        if ($companies === null) {
            $companies = [];
            foreach (liveRows($store, 'companies') as $c) { $companies[(string) $c['id']] = $c; }
            $incs = [];
            try { foreach ($store->all(SmartPRS\Surface\Increments::TABLE) as $i) { $incs[(string) ($i['employee_id'] ?? '')][] = $i; } } catch (\Throwable) { }
        }

        return SmartPRS\Surface\SalaryEffective::on($e, $companies[(string) ($e['employer_company_id'] ?? '')] ?? null, $incs[(string) ($e['id'] ?? '')] ?? [],
            isset($calendar) ? static fn (string $d): bool => $calendar->isWorkingDay($d) : null, $asOf ?? date('Y-m-d'));
    }

    /** The whole annexure for one employee, on $asOf (today, or a letter's effective date). */
    function salaryAnnexure(array $e, ?string $asOf = null): array
    {
        global $store, $structure, $componentRules, $statutoryApplicability;
        $asOf ??= date('Y-m-d');
        try { $e = SmartPRS\Surface\Increments::inForce([$e], $store->all(SmartPRS\Surface\Increments::TABLE), $asOf)[0]; } catch (\Throwable) { }
        $eff = salaryEffectiveFor($e, $asOf);
        // A DATE BEFORE THE FIRST STRUCTURE VERSION (an old joining date) reads today's basis.
        try { $basis = $structure->contractedCostBasis($asOf); } catch (\Throwable) { $basis = $structure->contractedCostBasis(date('Y-m-d')); }
        $out = ['e' => $e, 'as_of' => $asOf, 'eff' => $eff, 'lines' => ['earning' => [], 'deduction' => [], 'employer' => []], 'why' => null,
            'gross' => 0, 'ded' => 0, 'er' => 0, 'month_ctc' => Employment::monthlyGross($e, $basis)->minor, 'includes' => ctcIncludesEmployer(),
            'off' => array_values((array) ($statutoryApplicability?->offLabels($e, null, $asOf) ?? []))];
        try {
            $components = structureFor($e);
            $slip = (new PayrollRun(substr($asOf, 0, 7), $components, [['employment_id' => Employment::code($e) ?: 'x',
                'gross' => monthlyGrossOf($e, $basis, $components, substr($asOf, 0, 7)), 'payable_days' => Rational::of(30), 'period_days' => 30]]))->payslips[0];
            foreach ($slip->lines as $l) {
                $g = $l->kind === 'earning' ? 'earning' : (in_array($l->kind, ['deduction', 'recovery'], true) ? 'deduction' : 'employer');
                $c = (string) preg_replace('/^[A-Z]-/', '', (string) $l->componentCode);
                $out['lines'][$g][$c] ??= ['label' => $componentRules->labelFor((string) $l->componentCode), 'minor' => 0];
                $out['lines'][$g][$c]['minor'] += $l->amount->minor;
            }
            $out['gross'] = $slip->grossEarnings()->minor;
            $out['ded'] = $slip->totalDeductions()->minor;
            $out['er'] = $slip->employerCost()->minor;
        } catch (\Throwable $x) {
            $out['why'] = $x->getMessage();
        }
        $out['all_ded'] = $out['ded'] + ($out['includes'] ? $out['er'] : 0);
        $out['net'] = $out['gross'] - $out['ded'];
        $out['ctc'] = $out['gross'] + $out['er'];

        return $out;
    }

    /** Printable annexure — employee header, structure table, effective date and its history. */
    function salaryAnnexureHtml(array $a): string
    {
        global $store;
        $e = $a['e'];
        $m = static fn (int $x): string => Money::ofMinor($x)->format();
        $coRow = (string) ($e['employer_company_id'] ?? '') === '' ? [] : (array) ($store->find('companies', (string) $e['employer_company_id']) ?? []);
        $d = static fn (string $v): string => $v === '' ? '—' : SmartPRS\Surface\Dates::dmy($v);
        $row = static fn (string $label, int $minor, string $cls = ''): string => '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><td>' . e($label) . '</td><td class="n">' . e($m($minor)) . '</td><td class="n">' . e($m($minor * 12)) . '</td></tr>';
        $h = '<div class="sa"><div class="sa-h"><div><div class="sa-co">' . e((string) (($coRow['legal_name'] ?? '') ?: orgValueOf($store, $e, 'company'))) . '</div>'
            . '<div class="sa-t">ANNEXURE — SALARY STRUCTURE</div></div><div class="sa-eff"><span>Effective from</span><b>' . e($d((string) $a['eff']['date'])) . '</b><em>' . e((string) $a['eff']['why']) . '</em></div></div>';
        $info = ['Employee' => Employment::code($e) . ' · ' . (string) ($e['name'] ?? ''), 'Designation' => orgValueOf($store, $e, 'designation'), 'Department' => orgValueOf($store, $e, 'department'),
            'Branch' => orgValueOf($store, $e, 'branch'), 'Date of joining' => $d(substr((string) ($e['doj'] ?? ''), 0, 10)), 'Salary payable from' => $d((string) $a['eff']['pay_start']['date']),
            'PAN' => (string) ($e['pan'] ?? '') ?: '—', 'UAN' => (string) ($e['uan'] ?? '') ?: '—', 'Annual CTC' => $m($a['month_ctc'] * 12)];
        $h .= '<table class="sa-info">';
        $i = 0;
        foreach ($info as $k => $v) { $h .= ($i % 3 === 0 ? '<tr>' : '') . '<th>' . e($k) . '</th><td>' . e($v) . '</td>' . ($i % 3 === 2 ? '</tr>' : ''); $i++; }
        $h .= ($i % 3 !== 0 ? '</tr>' : '') . '</table>';
        if (($a['eff']['pay_start']['training'] ?? null) !== null) {
            $t = $a['eff']['pay_start']['training'];
            $h .= '<p class="sa-note">Induction training ' . e($d($t['from'])) . ' – ' . e($d($t['to'])) . ' — ' . ($t['paid'] ? 'paid' : 'unpaid; salary is payable from ' . e($d((string) $a['eff']['pay_start']['date']))) . '.</p>';
        }
        if ($a['why'] !== null) { return $h . '<p class="problem">The structure could not be worked out: ' . e((string) $a['why']) . '</p></div>'; }
        $h .= '<table class="sa-tbl"><thead><tr><th>Component</th><th class="n">Monthly (₹)</th><th class="n">Annual (₹)</th></tr></thead><tbody>';
        $h .= '<tr class="sa-g"><td colspan="3">A. Earnings</td></tr>';
        foreach ($a['lines']['earning'] as $l) { $h .= $row($l['label'], $l['minor']); }
        $h .= $row('Gross salary (A)', $a['gross'], 'sa-t1');
        // ORDER (owner, 15 Oct): earnings → deductions → take-home, then employer contributions and
        // Cost to Company at the end.
        $h .= '<tr class="sa-g"><td colspan="3">B. Deductions from salary</td></tr>';
        foreach ($a['lines']['deduction'] as $l) { $h .= $row($l['label'], $l['minor']); }
        $a['lines']['deduction'] === [] && $h .= '<tr><td colspan="3" class="sa-none">None</td></tr>';
        $h .= $row('Total employee deductions (B)', $a['ded'], 'sa-t1');
        $h .= $row('NET TAKE-HOME (A − B)', $a['net'], 'sa-t2');
        $h .= '<tr class="sa-g"><td colspan="3">C. Employer contributions' . ($a['includes'] ? ' (part of CTC)' : ' (on top of CTC)') . '</td></tr>';
        foreach ($a['lines']['employer'] as $l) { $h .= $row($l['label'], $l['minor']); }
        $a['lines']['employer'] === [] && $h .= '<tr><td colspan="3" class="sa-none">None</td></tr>';
        $h .= $row('Total employer contributions (C)', $a['er'], 'sa-t1');
        $a['includes'] && $h .= $row('Total deductions from CTC (B + C)', $a['all_ded'], 'sa-t1');
        $h .= $row('COST TO COMPANY (A + C)', $a['ctc'], 'sa-t2');
        $h .= '</tbody></table>';
        $a['off'] !== [] && $h .= '<p class="sa-note">Not applicable: ' . e(implode(', ', $a['off'])) . '.</p>';
        $h .= '<p class="sa-note">Before income tax (TDS), which depends on the tax regime and declarations, and before loans, incentives, loss of pay and other monthly items.</p>';
        if (count($a['eff']['history']) > 1) {
            $h .= '<table class="sa-hist"><thead><tr><th>Effective from</th><th>Revision</th></tr></thead><tbody>';
            foreach (array_reverse($a['eff']['history']) as $ev) { $h .= '<tr><td>' . e($d($ev['date'])) . '</td><td>' . e($ev['why']) . '</td></tr>'; }
            $h .= '</tbody></table>';
        }

        return $h . '</div>';
    }

    function salaryAnnexureCss(): string
    {
        return '<style>.sa{max-width:860px;margin:0 auto;background:#fff;color:#111;border:1px solid #d0d5dd;border-radius:8px;padding:24px 28px;font-size:12.5px}'
            . '.sa-h{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid #1F3A5F;padding-bottom:10px;margin-bottom:12px}.sa-co{font-size:16px;font-weight:800;color:#1F3A5F}.sa-t{letter-spacing:.08em;font-weight:700;color:#B8893B;margin-top:2px}'
            . '.sa-eff{text-align:right;display:flex;flex-direction:column}.sa-eff span{font-size:10.5px;text-transform:uppercase;color:#667085}.sa-eff b{font-size:18px;color:#1F3A5F}.sa-eff em{font-style:normal;font-size:11px;color:#475467;max-width:280px}'
            . '.sa table{width:100%;border-collapse:collapse;margin:8px 0}.sa th,.sa td{border:1px solid #d0d5dd;padding:5px 8px;text-align:left}.sa-info th{background:#f2f4f7;font-weight:600;width:13%;font-size:11.5px}'
            . '.sa-tbl thead th{background:#1F3A5F;color:#fff}.sa .n{text-align:right;font-family:ui-monospace,Menlo,monospace;white-space:nowrap}.sa-g td{background:#f8f9fb;font-weight:700;color:#1F3A5F}'
            . '.sa-t1 td{font-weight:700}.sa-t2 td{font-weight:800;background:#fdf6e9;border-top:2px solid #B8893B}.sa-none{color:#98a2b3;font-style:italic}.sa-note{color:#475467;font-size:11.5px;margin:6px 0}.sa-hist th{background:#f2f4f7}'
            . '@media print{body *{visibility:hidden!important}.sa,.sa *{visibility:visible!important}.sa{position:absolute;left:0;top:0;width:100%;border:0}.no-print{display:none!important}@page{size:A4;margin:12mm}}</style>';
    }

    /** The annexure as plain text, for the letter body ({{salary_structure}}) — kept as it went out. */
    function salaryAnnexureText(array $a): string
    {
        $m = static fn (int $x): string => Money::ofMinor($x)->format();
        $line = static fn (string $l, int $x): string => '  ' . $l . ': ' . $m($x) . ' a month (' . $m($x * 12) . ' a year)';
        $t = ['ANNEXURE — SALARY STRUCTURE (effective from ' . SmartPRS\Surface\Dates::dmy((string) $a['eff']['date']) . ')', '', 'A. Earnings'];
        foreach ($a['lines']['earning'] as $l) { $t[] = $line($l['label'], $l['minor']); }
        $t[] = $line('Gross salary (A)', $a['gross']);
        $t[] = '';
        $t[] = 'B. Deductions from salary';
        foreach ($a['lines']['deduction'] as $l) { $t[] = $line($l['label'], $l['minor']); }
        $t[] = $line('Net take-home (A − B)', $a['net']);
        $t[] = '';
        $t[] = 'C. Employer contributions';
        foreach ($a['lines']['employer'] as $l) { $t[] = $line($l['label'], $l['minor']); }
        $t[] = $line('Cost to company (A + C)', $a['ctc']);
        $t[] = '';
        $t[] = 'Income tax (TDS) is deducted as per the applicable regime and declarations.';

        return implode("\n", $t);
    }

    /** Training terms sentence for the offer letter ({{training_terms}}) — never blank. */
    function salaryTrainingTerms(array $e): string
    {
        $p = salaryEffectiveFor($e)['pay_start'];
        $t = $p['training'] ?? null;
        if ($t === null) { return 'Your salary is payable from your date of joining.'; }

        return 'You will undergo induction training from ' . SmartPRS\Surface\Dates::dmy($t['from']) . ' to ' . SmartPRS\Surface\Dates::dmy($t['to']) . ', '
            . ($t['paid'] ? 'which is paid; your salary is payable from your date of joining.' : 'which is unpaid; your salary is payable from ' . SmartPRS\Surface\Dates::dmy((string) $p['date']) . '.');
    }
}
