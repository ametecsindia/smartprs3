<?php declare(strict_types=1);
/**
 * FORM 130 (OLD FORM 16) HELPERS (owner, 15 Oct) — shared by the form16 screen and its actions.
 * Part B is worked by the same IncomeTax engine as the monthly TDS:
 *   - a year that has ENDED uses only what the closed payroll paid (plus salary HR entered for
 *     months before SmartPRS 3 went live, previous employer and other income from the declaration);
 *   - a year still running is PROVISIONAL: paid so far + this month's salary × months left, exactly
 *     what TDS is deducted on, and the print says so.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Form16;
use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\TaxDeclarations;

include_once __DIR__ . '/tds-lib.php';

if (!function_exists('f16Store')) {
    function f16Store(): Form16
    {
        global $store, $customisation, $tenantId;
        static $f = null;

        return $f ??= new Form16($store, $customisation, __DIR__ . '/../../storage/form16/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $tenantId === '' ? 'platform' : $tenantId));
    }

    /** @return array{from: string, to: string, ended: bool} the year's first and last wage month */
    function f16Span(string $fy): array
    {
        $from = substr($fy, 0, 4) . sprintf('-%02d', financialYearStartGlobal());
        $to = date('Y-m', (int) strtotime($from . '-01 +11 months'));

        return ['from' => $from, 'to' => $to, 'ended' => date('Y-m') > $to];
    }

    /**
     * WHO GETS A FORM 16 FOR THE YEAR: everyone in reach who is active, or who was paid in a closed
     * run of the year (leavers included — they need one too), joined before the year ended.
     *
     * @return list<array<string, mixed>>
     */
    function f16People(string $fy, array $runRows): array
    {
        global $store, $scope, $may;
        $span = f16Span($fy);
        $paid = [];
        foreach ($runRows as $p => $rows) {
            if ($p >= $span['from'] && $p <= $span['to']) { foreach (array_keys((array) $rows) as $k) { $paid[(string) $k] = true; } }
        }
        $out = [];
        foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
            $active = in_array(strtolower(trim((string) ($e['status'] ?? 'active'))), ['active', ''], true);
            $inRuns = isset($paid[(string) $e['id']]) || isset($paid[Employment::code($e)]);
            $doj = substr((string) ($e['doj'] ?? ''), 0, 7);
            if (($active || $inRuns) && ($doj === '' || $doj <= $span['to'])) { $out[] = $e; }
        }
        usort($out, static fn (array $a, array $b): int => strnatcasecmp(Employment::code($a), Employment::code($b)));

        return $out;
    }

    /** The employee's company row (TAN, PAN, responsible person). @return array<string, mixed> */
    function f16Company(array $e): array
    {
        global $store;
        $id = (string) ($e['employer_company_id'] ?? '');

        return $id === '' ? [] : (array) ($store->find('companies', $id) ?? []);
    }

    /**
     * PART B FIGURES for one employee and year.
     *
     * @return array{sheet: array<string, mixed>, regime: string, figures: array<string, int>, decl: ?array,
     *     counted: array<string, int>, tds: array<string, int>, paid_before: int, provisional: bool, issues: list<string>}
     */
    function f16PartB(array $e, string $fy, array $runRows): array
    {
        global $taxDeclarations;
        $span = f16Span($fy);
        $decl = $taxDeclarations->declaration((string) $e['id'], $fy);

        if ($span['ended']) {
            $after = date('Y-m', (int) strtotime($span['to'] . '-01 +1 month'));
            $f = $taxDeclarations->figures($e, $fy, $after, [], $runRows, TaxDeclarations::estimates($decl));
            $s = $taxDeclarations->sheets($e, $fy, $f, $decl, date('Y-m-d'));
        } else {
            $s = tdsSheets($e, $fy, $decl);
            $f = $s['figures'];
        }

        $co = f16Company($e);
        $issues = [];
        (string) ($e['pan'] ?? '') === '' && $issues[] = 'PAN not on the employee record';
        (string) ($co['tan'] ?? '') === '' && $issues[] = 'Company TAN not set (Organization Configuration → Companies)';
        (string) ($co['tds_person_name'] ?? '') === '' && $issues[] = 'Person responsible for TDS not set on the company';
        // An ended year with months before SmartPRS 3 went live and nothing entered for them would
        // under-state the salary on the certificate — say so instead of printing a wrong figure.
        $span['ended'] && (int) ($f['estimated'] ?? 0) > 0
            && $issues[] = (int) $f['estimated'] . ' month(s) of the year were not paid through SmartPRS 3 — enter "Salary paid before SmartPRS 3" (and TDS) on the employee\'s TDS Declaration';
        !$span['ended'] && $issues[] = 'The year has not ended — Part B is provisional';

        return ['sheet' => $s[$s['regime']], 'regime' => (string) $s['regime'], 'figures' => $f, 'decl' => $decl,
            'tds' => $taxDeclarations->tdsByPeriod($e, $fy, $runRows), 'paid_before' => (int) $s['paid_before'],
            'provisional' => !$span['ended'], 'issues' => $issues];
    }

    /** One employee's Part B as an A4 block (page-break after). */
    function f16PartBHtml(array $e, string $fy, array $b, ?array $partA): string
    {
        global $store, $taxDeclarations;
        $Y = $taxDeclarations->year($fy);
        $span = f16Span($fy);
        $co = f16Company($e);
        $p = (array) $b['sheet']['parts'];
        $it = (array) $b['sheet']['items'];
        $m = static fn (int $v): string => tdsMoney($v);
        $row = static fn (string $no, string $label, string $amt, bool $bold = false): string
            => '<tr' . ($bold ? ' class="b"' : '') . '><td class="no">' . $no . '</td><td>' . $label . '</td><td class="n">' . $amt . '</td></tr>';
        $doj = substr((string) ($e['doj'] ?? ''), 0, 10);
        $exit = substr((string) ($e['exit_date'] ?? ''), 0, 10);
        $pFrom = max($span['from'] . '-01', $doj);
        $pTo = $exit !== '' && $exit < date('Y-m-t', (int) strtotime($span['to'] . '-01')) ? $exit : date('Y-m-t', (int) strtotime($span['to'] . '-01'));
        $ay = (int) substr($fy, 0, 4) + 1 . '-' . sprintf('%02d', ((int) substr($fy, 0, 4) + 2) % 100);
        $addr = trim(implode(', ', array_filter([(string) ($e['present_house_no'] ?? ''), (string) ($e['present_area'] ?? ''), (string) ($e['present_city'] ?? ''), (string) ($e['present_pincode'] ?? '')])), ', ') ?: (string) ($e['address'] ?? '');
        $coName = (string) ($co['legal_name'] ?? '') ?: orgValueOf($store, $e, 'company');
        $exempt = (array) $b['sheet']['exempt'];

        $h = '<div class="f16">';
        $b['provisional'] && $h .= '<div class="prov">PROVISIONAL — the year ' . e($fy) . ' has not ended. Figures are salary paid so far plus the projection TDS is deducted on.</div>';
        $h .= '<h1>FORM No. 130 <span>(earlier Form No. 16)</span></h1><div class="sub">Certificate of tax deducted at source on salary paid to an employee (Income-tax Act, 2025; earlier section 203 of the 1961 Act) &middot; <b>PART B (Annexure)</b></div>';
        $h .= '<table><tr><th style="width:50%">Name and address of the employer</th><th>Name and address of the employee</th></tr><tr><td>' . e($coName) . '<br>' . e((string) ($co['address'] ?? '')) . '</td><td>' . e((string) ($e['name'] ?? '')) . '<br>' . e($addr) . '<br>Designation: ' . e(orgValueOf($store, $e, 'designation')) . ' &middot; Employee ID ' . e(Employment::code($e)) . '</td></tr></table>';
        $h .= '<table><tr><th>PAN of the deductor</th><th>TAN of the deductor</th><th>PAN of the employee</th><th>Tax year</th><th>Period with the employer</th></tr><tr><td>' . e((string) ($co['pan'] ?? '') ?: '—') . '</td><td>' . e((string) ($co['tan'] ?? '') ?: '—') . '</td><td>' . e((string) ($e['pan'] ?? '') ?: 'PAN NOT AVAILABLE') . '</td><td>' . e($fy) . ' <span class="muted">(AY ' . e($ay) . ')</span></td><td>' . e(SmartPRS\Surface\Dates::dmy($pFrom)) . ' to ' . e(SmartPRS\Surface\Dates::dmy($pTo)) . '</td></tr></table>';
        $h .= '<p class="q">Whether the employee opted out of the new tax regime (section 202, old 115BAC): <b>' . ($b['regime'] === 'old' ? 'Yes — old regime' : 'No — new regime') . '</b></p>';
        $h .= '<h2>Details of salary paid and any other income and tax deducted</h2><table class="pb"><thead><tr><th class="no">#</th><th>Particulars</th><th class="n" style="width:24%">Amount (₹)</th></tr></thead><tbody>';
        $h .= $row('1', '<b>Gross salary</b>', '');
        $h .= $row('(a)', 'Salary as per old section 17(1)' . ((int) ($it['EARLIER_SALARY'] ?? 0) > 0 ? ' <span class="muted">(includes ' . $m((int) $it['EARLIER_SALARY']) . ' paid before SmartPRS 3)</span>' : ''), $m((int) $p['this_employer']));
        $h .= $row('(b)', 'Value of perquisites (old section 17(2))', $m(0));
        $h .= $row('(c)', 'Profits in lieu of salary (old section 17(3))', $m(0));
        $h .= $row('(d)', 'Total', $m((int) $p['this_employer']), true);
        $h .= $row('(e)', 'Reported total salary received from other employer(s)', $m((int) $p['previous_employer']));
        $h .= $row('2', '<b>Less: Allowances to the extent exempt (old section 10)</b>', '');
        $h .= $row('(a)', 'House rent allowance', $m((int) ($exempt['HRA'] ?? 0)));
        $h .= $row('(b)', 'Leave travel concession', $m((int) ($exempt['LTA'] ?? 0)));
        $h .= $row('(c)', 'Total exemption', $m((int) $p['exempt']), true);
        $h .= $row('3', 'Total salary received from the current employer [1(d) − 2(c)]', $m((int) $p['this_employer'] - (int) $p['exempt']), true);
        $h .= $row('4', '<b>Less: Deductions from salary (old section 16)</b>', '');
        $h .= $row('(a)', 'Standard deduction', $m((int) $p['standard_deduction']));
        $h .= $row('(b)', 'Entertainment allowance', $m(0));
        $h .= $row('(c)', 'Tax on employment (professional tax)', $m((int) $p['professional_tax']));
        $h .= $row('5', 'Total deductions from salary (old section 16)', $m((int) $p['standard_deduction'] + (int) $p['professional_tax']), true);
        $h .= $row('6', 'Income chargeable under the head "Salaries" [3 + 1(e) − 5]', $m((int) $p['salary_income']), true);
        $h .= $row('7', '<b>Add: Any other income reported by the employee</b>', '');
        $h .= $row('(a)', 'Income (or admissible loss) from house property', $p['house_property'] < 0 ? '(' . $m(-(int) $p['house_property']) . ')' : $m(0));
        $h .= $row('(b)', 'Income under the head Other Sources', $m((int) $p['other_income']));
        $h .= $row('8', 'Total other income [7(a) + 7(b)]', $m((int) $p['house_property'] + (int) $p['other_income']), true);
        $h .= $row('9', 'Gross total income (6 + 8)', $m((int) $p['gross_total_income']), true);
        $h .= $row('10', '<b>Deductions under Chapter VIII (old Chapter VI-A)</b>', '');
        $ded = (array) $b['sheet']['deductions'];
        $i = 0;
        foreach ($ded as $k => $v) {
            $i++;
            $h .= $row('(' . chr(96 + min(26, $i)) . ')', e(IncomeTax::dedLabel($Y, (string) $k)), $m((int) $v));
        }
        $ded === [] && $h .= $row('', '<span class="muted">No deduction claimed' . ($b['regime'] === 'new' ? ' — the new regime allows only the employer\'s NPS contribution' : '') . '</span>', $m(0));
        $h .= $row('11', 'Aggregate of deductible amount under Chapter VIII', $m((int) $p['deductions']), true);
        $h .= $row('12', 'Total taxable income (9 − 11)', $m((int) $p['taxable']), true);
        $h .= $row('13', 'Tax on total income', $m((int) $p['slab_tax']));
        $h .= $row('14', 'Rebate under section 156 (old 87A)', $m((int) $p['rebate']));
        $h .= $row('15', 'Surcharge, wherever applicable', $m((int) $p['surcharge']));
        $h .= $row('16', 'Health and education cess', $m((int) $p['cess']));
        $h .= $row('17', 'Tax payable (13 − 14 + 15 + 16)', $m((int) $p['total']), true);
        $h .= $row('18', 'Less: Relief for salary arrears (old section 89)', $m(0));
        $h .= $row('19', 'Net tax payable (17 − 18)', $m((int) $p['total']), true);
        $h .= '</tbody></table>';

        // TDS BY QUARTER — what the payroll deducted (Part A from TRACES shows what was deposited).
        $q = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ((array) $b['tds'] as $per => $v) { $q[Form16::quarter((string) $per, financialYearStartGlobal())] += (int) $v; }
        $byPayroll = array_sum($q);
        $h .= '<h2>Tax deducted from salary</h2><table><tr><th>Quarter 1 (Apr–Jun)</th><th>Quarter 2 (Jul–Sep)</th><th>Quarter 3 (Oct–Dec)</th><th>Quarter 4 (Jan–Mar)</th><th>Before SmartPRS 3 / previous employer</th><th>Total</th></tr><tr>';
        foreach ($q as $v) { $h .= '<td class="n">' . $m($v) . '</td>'; }
        $h .= '<td class="n">' . $m((int) $b['paid_before']) . '</td><td class="n"><b>' . $m($byPayroll + (int) $b['paid_before']) . '</b></td></tr></table>';
        $diff = (int) $p['total'] - $byPayroll - (int) $b['paid_before'];
        $h .= '<p class="q">' . ($diff === 0 ? 'Tax deducted equals the tax payable.' : ($diff > 0 ? 'Tax payable exceeds tax deducted by <b>' . $m($diff) . '</b>' . ($b['provisional'] ? ' — to be deducted in the remaining months.' : ' — payable by the employee with the return.') : 'Tax deducted exceeds the tax payable by <b>' . $m(-$diff) . '</b> — refundable to the employee through the return.')) . '</p>';
        $h .= '<p class="q">Part A (TDS deposited with the Government, quarter by quarter) is issued from TRACES' . ($partA !== null ? ' and is attached separately (uploaded ' . e(SmartPRS\Surface\Dates::dmy(substr((string) $partA['created_at'], 0, 10))) . ').' : ' — <b>not uploaded yet</b>.') . '</p>';

        $person = (string) ($co['tds_person_name'] ?? '') ?: '____________________';
        $h .= '<h2>Verification</h2><p>I, <b>' . e($person) . '</b>' . ((string) ($co['tds_person_father'] ?? '') !== '' ? ', son / daughter of ' . e((string) $co['tds_person_father']) : '') . ', working in the capacity of <b>' . e((string) ($co['tds_person_designation'] ?? '') ?: '__________') . '</b>, do hereby certify that the information given above is true, complete and correct and is based on the books of account, documents, TDS statements and other available records.</p>';
        $h .= '<div class="sig"><div>Place: ____________________<br><br>Date: ' . e(date('d-m-Y')) . '</div><div style="text-align:right">For ' . e($coName) . '<br><br><br>Signature of the person responsible for deduction of tax<br>Full name: ' . e($person) . '</div></div>';

        return $h . '</div>';
    }

    function f16PrintCss(): string
    {
        return '<style>
  @media print { body * { visibility: hidden !important; } #f16-print, #f16-print * { visibility: visible !important; } #f16-print { position: absolute; left: 0; top: 0; width: 100%; } .no-print { display: none !important; } @page { size: A4; margin: 10mm; } .f16 { border: 0 !important; box-shadow: none !important; margin: 0 !important; } }
  .f16 { max-width: 820px; margin: 0 auto 24px; background: #fff; color: #111; padding: 24px 30px; border: 1px solid var(--border); font-size: 11.5px; page-break-after: always; break-after: page; }
  .f16:last-child { page-break-after: auto; break-after: auto; }
  .f16 h1 { font-size: 16px; text-align: center; margin: 0 0 2px; color: #1F3A5F; } .f16 h1 span { font-weight: 400; font-size: 13px; }
  .f16 h2 { font-size: 12.5px; margin: 12px 0 4px; color: #1F3A5F; border-bottom: 2px solid #B8893B; padding-bottom: 2px; }
  .f16 .sub { text-align: center; color: #444; margin-bottom: 10px; }
  .f16 table { width: 100%; border-collapse: collapse; margin: 6px 0 8px; }
  .f16 th, .f16 td { border: 1px solid #9aa3b2; padding: 4px 6px; text-align: left; vertical-align: top; }
  .f16 th, .f16 thead th { background: #1F3A5F !important; color: #fff !important; font-weight: 600; text-transform: none; letter-spacing: 0; font-family: inherit; }
  .f16 td { background: #fff !important; color: #111 !important; }
  .f16 .pb tr.b td { font-weight: 700; background: #f3f5f9 !important; }
  .f16 .n { text-align: right; font-family: var(--mono); white-space: nowrap; } .f16 .no { width: 34px; text-align: center; color: #555; }
  .f16 .muted { color: #666; font-weight: 400; } .f16 .q { margin: 6px 0; }
  .f16 .sig { margin-top: 26px; display: flex; justify-content: space-between; gap: 20px; }
  .f16 .prov { border: 2px dashed #b45309; color: #b45309; font-weight: 700; padding: 6px 10px; margin-bottom: 10px; text-align: center; }
</style>';
    }
}
