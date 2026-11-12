<?php declare(strict_types=1);
/**
 * SALARY BREAKUP (owner, 15 Oct) — every employee's full month from their CTC, through the SAME
 * engine a payroll close uses: structureFor() (the Salary Structure rules that win for this person —
 * company, branch and its city/state, department, designation, team, gender, employee — merged with
 * the statutory defaults, less any head switched off on the record) and PayrollRun at a full month.
 * Shared by the screen and its Excel / CSV export so the two can never disagree.
 */
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Surface\Employment;

if (!function_exists('sbData')) {
    /** Exited before today, or a status that means gone: not on the breakup. */
    function sbActive(array $e): bool
    {
        $exit = substr((string) ($e['exit_date'] ?? ''), 0, 10);
        if ($exit !== '' && $exit !== '0000-00-00' && $exit < date('Y-m-d')) { return false; }

        return !in_array(strtolower(trim((string) ($e['status'] ?? ''))), ['exited', 'inactive', 'relieved', 'absconded', 'terminated', 'resigned'], true);
    }

    /** Which column group a payslip line belongs to. */
    function sbGroup(string $kind): string
    {
        return $kind === 'earning' ? 'earning' : (in_array($kind, ['deduction', 'recovery'], true) ? 'deduction' : 'employer');
    }

    /**
     * @param array<string, string> $filter orgFilter()
     * @return array{rows: list<array<string, mixed>>, cols: array<string, array<string, string>>, problems: list<string>, all: list<array<string, mixed>>}
     */
    function sbData(array $filter): array
    {
        global $store, $scope, $may, $structure, $componentRules, $statutoryApplicability;
        $days = (int) date('t');
        $basis = $structure->contractedCostBasis(date('Y-m-t'));
        $cities = [];
        foreach (liveRows($store, 'branches') as $b) { $cities[(string) $b['id']] = trim((string) ($b['city'] ?? '')); }
        $cols = ['earning' => [], 'deduction' => [], 'employer' => []];
        $all = [];
        $problems = [];

        $employees = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
        // THE CTC IN FORCE THIS MONTH — an approved increment counts from its effective date, as at a close.
        try { $employees = SmartPRS\Surface\Increments::inForce($employees, $store->all(SmartPRS\Surface\Increments::TABLE), date('Y-m-t')); } catch (\Throwable) { }
        $includes = ctcIncludesEmployer();

        foreach ($employees as $e) {
            $code = Employment::code($e);
            if ($code === '' || !sbActive($e)) { continue; }
            $row = ['e' => $e, 'code' => $code, 'city' => $cities[(string) ($e['branch_id'] ?? '')] ?? '',
                'gender' => (string) ($e['gender'] ?? ''), 'lines' => [], 'gross' => 0, 'ded' => 0, 'er' => 0, 'why' => null,
                // WHAT IS SWITCHED OFF FOR THEM, AND WHY (on probation, TDS Applicable = No…) — the same answer payroll uses.
                'off' => array_values((array) ($statutoryApplicability?->offLabels($e, null, date('Y-m-d')) ?? []))];
            try {
                $components = structureFor($e);
                $slip = (new PayrollRun(date('Y-m'), $components, [[
                    'employment_id' => $code,
                    'gross' => monthlyGrossOf($e, $basis, $components),
                    'payable_days' => Rational::of($days), 'period_days' => $days,
                ]]))->payslips[0];
                foreach ($slip->lines as $l) {
                    $g = sbGroup((string) $l->kind);
                    // ONE COLUMN PER COMPONENT: the structure's C-BASIC and a Salary Structure rule's BASIC are the same Basic.
                    $c = (string) preg_replace('/^[A-Z]-/', '', (string) $l->componentCode);
                    $cols[$g][$c] ??= $componentRules->labelFor((string) $l->componentCode);
                    $row['lines'][$c] = ($row['lines'][$c] ?? 0) + $l->amount->minor;
                }
                $row['gross'] = $slip->grossEarnings()->minor;
                $row['ded'] = $slip->totalDeductions()->minor;
                $row['er'] = $slip->employerCost()->minor;
            } catch (\Throwable $x) {
                $row['why'] = $x->getMessage();
                $problems[] = $code . ': ' . $x->getMessage();
            }
            $row['eff'] = function_exists('salaryEffectiveFor') ? salaryEffectiveFor($e) : ['date' => '', 'why' => ''];
            $row['net'] = $row['gross'] - $row['ded'];
            // TOTAL DEDUCTIONS FROM THE CTC: the employee's own deductions, plus the employer's contributions
            // when the CTC includes them (Salary structure setting) — so CTC − total deductions = take-home.
            $row['all_ded'] = $row['ded'] + ($includes ? $row['er'] : 0);
            // CTC CHECK: what the month costs (gross + employer contributions) against the contract's month.
            $row['cost'] = $row['gross'] + $row['er'];
            $row['month_ctc'] = Employment::monthlyGross($e, $basis)->minor;
            $row['annual_ctc'] = $basis === 'monthly' ? $row['month_ctc'] * 12 : SmartPRS\Platform\Support\Money::of(Employment::ctc($e))->minor;
            $all[] = $row;
        }

        $rows = array_values(array_filter($all, static function (array $r) use ($store, $filter): bool {
            foreach ($filter as $k => $v) {
                if ($v !== '' && strcasecmp(orgValueOf($store, $r['e'], $k), $v) !== 0) { return false; }
            }
            return true;
        }));
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));

        return ['rows' => $rows, 'cols' => $cols, 'problems' => $problems, 'all' => $all];
    }

    /**
     * The table as plain rows, for the export: header + one row per employee, amounts in rupees.
     * @return array{head: list<string>, rows: list<list<string>>, money: list<int>}
     */
    function sbTable(array $data, bool $annual): array
    {
        global $store;
        $x = $annual ? 12 : 1;
        $m = static fn (int $minor): string => SmartPRS\Platform\Support\Money::ofMinor($minor * $x)->toDecimalString();
        $head = ['Employee ID', 'Name', 'Company', 'Branch', 'City', 'Department', 'Designation', 'Gender', 'Annual CTC', 'CTC for the ' . ($annual ? 'year' : 'month')];
        $money = [8, 9];
        $add = static function (string $h) use (&$head, &$money): void { $money[] = count($head); $head[] = $h; };
        foreach ($data['cols']['earning'] as $label) { $add($label); }
        $add('Gross earnings');
        foreach ($data['cols']['deduction'] as $label) { $add($label); }
        foreach ($data['cols']['employer'] as $label) { $add($label . ' (employer)'); }
        $add('Total deductions');
        $add('Net take-home');
        $add('Cost to company (gross + employer)');
        $add('Difference from CTC');
        $head[] = 'Salary structure effective from';
        $head[] = 'Effective because';
        $head[] = 'Note';
        $rows = [];
        foreach ($data['rows'] as $r) {
            $line = [$r['code'], (string) ($r['e']['name'] ?? ''), orgValueOf($store, $r['e'], 'company'), orgValueOf($store, $r['e'], 'branch'), $r['city'],
                orgValueOf($store, $r['e'], 'department'), orgValueOf($store, $r['e'], 'designation'), $r['gender'],
                SmartPRS\Platform\Support\Money::ofMinor($r['annual_ctc'])->toDecimalString(), $m($r['month_ctc'])];
            foreach (array_keys($data['cols']['earning']) as $c) { $line[] = isset($r['lines'][$c]) ? $m($r['lines'][$c]) : ''; }
            $line[] = $m($r['gross']);
            foreach (['deduction', 'employer'] as $g) {
                foreach (array_keys($data['cols'][$g]) as $c) { $line[] = isset($r['lines'][$c]) ? $m($r['lines'][$c]) : ''; }
            }
            $line[] = $m($r['all_ded']);
            $line[] = $m($r['net']);
            $line[] = $m($r['cost']);
            $line[] = $m($r['cost'] - $r['month_ctc']);
            $line[] = (string) ($r['eff']['date'] ?? '');
            $line[] = (string) ($r['eff']['why'] ?? '');
            $line[] = trim((string) ($r['why'] ?? '') . ($r['off'] === [] ? '' : ' Not applicable: ' . implode(', ', $r['off'])));
            $rows[] = $line;
        }

        return ['head' => $head, 'rows' => $rows, 'money' => $money];
    }
}
