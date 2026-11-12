<?php declare(strict_types=1);
/**
 * MONTHLY SALARY STATEMENT (owner, 15 Oct) — the Salary Breakup's second tab: for the month picked,
 * what each employee was actually paid, straight from the GENERATED payroll (closed run, not discarded):
 * gross, every earning (incentives, bonus, OT, reimbursements…), every deduction (PF, ESI, PT, TDS,
 * loan / advance, DRA, PCC, manual…), the days lost to loss of pay and late-login penalties, and net.
 * Nothing is recomputed here — these are the payslip figures. Shared by the screen and the export.
 */
use SmartPRS\Surface\Employment;

if (!function_exists('ssData')) {
    /** @return array{month: string, run: ?array, rows: list<array>, all: list<array>, cols: array<string, array<string, string>>, months: list<string>} */
    function ssData(string $month, array $filter): array
    {
        global $store, $scope, $may, $register, $componentRules;
        $runs = $register->runs();
        $months = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['period'], $runs)));
        rsort($months);
        $month = in_array($month, $months, true) ? $month : ($months[0] ?? $month);
        $run = null;
        foreach ($runs as $r) { (string) $r['period'] === $month && $run ??= $r; }
        $out = ['month' => $month, 'run' => $run, 'rows' => [], 'all' => [], 'cols' => ['earning' => [], 'deduction' => [], 'employer' => []], 'months' => $months];
        if ($run === null) { return $out; }

        // Every employee this account may see, by code — exited ones too, a closed month still paid them.
        $byCode = [];
        foreach ($scope->only($store->all('employees'), $may('employee.list')) as $e) { ($c = Employment::code($e)) === '' || $byCode[$c] = $e; }
        $labels = [];
        foreach ((array) ((json_decode((string) @file_get_contents(__DIR__ . '/../../config/payroll-inputs.json'), true) ?: [])['sources'] ?? []) as $s) { $labels[(string) ($s['code'] ?? '')] = (string) ($s['label'] ?? ''); }
        $label = static function (string $code) use ($componentRules, $labels): string {
            $l = $componentRules->labelFor($code);
            return ($l === '' || $l === $code) ? ($labels[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)))) : $l;
        };

        // EVERY COMPONENT IN THE CATALOGUE IS A COLUMN (owner, 15 Oct: "all columns should be included … if
        // that is not available for the employee, keep it blank") — Organization Configuration's own list,
        // in its own order, so the statement lines up with the payslip column for column.
        try {
            foreach ((array) ($componentRules->catalogue()['components'] ?? []) as $cc) {
                $k = (string) ($cc['kind'] ?? '');
                $g = $k === 'earning' ? 'earning' : ($k === 'deduction' ? 'deduction' : 'employer');
                $out['cols'][$g][(string) $cc['code']] = (string) ($cc['label'] ?? $cc['code']);
            }
        } catch (\Throwable) { }

        foreach ((array) ($run['rows'] ?? []) as $code => $row) {
            $e = $byCode[(string) $code] ?? null;
            if ($e === null || !is_array($row)) { continue; }
            // WHO THEY WERE WHEN THE MONTH CLOSED — company, branch, department as the payslip prints them.
            $e = array_merge($e, array_filter((array) ($row['as_closed'] ?? []), static fn ($v): bool => $v !== null && $v !== ''));
            $lines = [];
            $er = 0;
            foreach ((array) ($row['lines'] ?? []) as $l) {
                $kind = (string) ($l['kind'] ?? '');
                $g = $kind === 'earning' ? 'earning' : (in_array($kind, ['deduction', 'recovery'], true) ? 'deduction' : 'employer');
                $c = (string) preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? ''));
                $out['cols'][$g][$c] ??= $label((string) ($l['code'] ?? ''));
                $lines[$c] = ($lines[$c] ?? 0) + (int) ($l['minor'] ?? 0);
                $g === 'employer' && $er += (int) ($l['minor'] ?? 0);
            }
            $gross = SmartPRS\Surface\IncomeTax::p($row['gross'] ?? '');
            $days = (array) ($row['days'] ?? []);
            $period = max(1, (int) ($row['period_days'] ?? 30));
            $payable = (float) ($row['payable_days'] ?? $period);
            $out['all'][] = ['e' => $e, 'code' => (string) $code, 'gross' => $gross, 'lines' => $lines,
                'earn' => SmartPRS\Surface\IncomeTax::p($row['earnings'] ?? ''), 'ded' => SmartPRS\Surface\IncomeTax::p($row['deductions'] ?? ''),
                'net' => (int) ($row['net_minor'] ?? SmartPRS\Surface\IncomeTax::p($row['net'] ?? '')), 'er' => $er,
                'period' => $period, 'payable' => (string) ($row['payable_days'] ?? ''), 'lop' => (string) ($days['unpaid_leave'] ?? '0'),
                'worked' => (string) ($days['worked'] ?? ''), 'woff' => (string) ($days['off'] ?? '0'), 'hol' => (int) ($days['holidays'] ?? 0),
                'paid_leave' => (string) ($days['paid_leave'] ?? '0'),
                'absent' => (int) ($days['absent'] ?? 0), 'late' => (string) ($days['late_penalty'] ?? '0'), 'late_marks' => (int) ($days['late_marks'] ?? 0),
                // PAY LOST TO THE DAYS NOT PAYABLE (LOP, absence, late-login penalties) — already out of the earnings above.
                'lost' => (int) round($gross * max(0, $period - $payable) / $period)];
        }


        $out['rows'] = array_values(array_filter($out['all'], static function (array $r) use ($store, $filter): bool {
            foreach ($filter as $k => $v) { if ($v !== '' && strcasecmp(orgValueOf($store, $r['e'], $k), $v) !== 0) { return false; } }
            return true;
        }));
        usort($out['rows'], static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));

        return $out;
    }

    /** The statement as plain rows for Excel / CSV. @return array{head: list<string>, rows: list<list<string>>, money: list<int>} */
    function ssTable(array $d): array
    {
        global $store;
        $m = static fn (int $x): string => SmartPRS\Platform\Support\Money::ofMinor($x)->toDecimalString();
        $head = ['Employee ID', 'Name', 'Company', 'Branch', 'Department', 'Designation', 'Month', 'Days in month', 'Present days', 'Weekly offs', 'Holidays', 'Paid leave', 'LOP (unpaid leave)', 'Absent', 'Late marks', 'Late-login days deducted', 'Payable days', 'Pay lost to LOP / absence / late (₹)', 'Gross salary (month)'];
        $money = [17, 18];
        $add = static function (string $h) use (&$head, &$money): void { $money[] = count($head); $head[] = $h; };
        foreach ($d['cols']['earning'] as $l) { $add($l); }
        $add('Total earnings');
        foreach ($d['cols']['deduction'] as $l) { $add($l); }
        $add('Total deductions');
        $add('Net salary');
        foreach ($d['cols']['employer'] as $l) { $add($l . ' (employer)'); }
        $rows = [];
        foreach ($d['rows'] as $r) {
            $line = [$r['code'], (string) ($r['e']['name'] ?? ''), orgValueOf($store, $r['e'], 'company'), orgValueOf($store, $r['e'], 'branch'), orgValueOf($store, $r['e'], 'department'),
                orgValueOf($store, $r['e'], 'designation'), $d['month'], (string) $r['period'], $r['worked'], $r['woff'], (string) $r['hol'], $r['paid_leave'], $r['lop'],
                (string) $r['absent'], (string) $r['late_marks'], $r['late'], $r['payable'], $m($r['lost']), $m($r['gross'])];
            foreach (array_keys($d['cols']['earning']) as $c) { $line[] = isset($r['lines'][$c]) ? $m($r['lines'][$c]) : ''; }
            $line[] = $m($r['earn']);
            foreach (array_keys($d['cols']['deduction']) as $c) { $line[] = isset($r['lines'][$c]) ? $m($r['lines'][$c]) : ''; }
            $line[] = $m($r['ded']);
            $line[] = $m($r['net']);
            foreach (array_keys($d['cols']['employer']) as $c) { $line[] = isset($r['lines'][$c]) ? $m($r['lines'][$c]) : ''; }
            $rows[] = $line;
        }

        return ['head' => $head, 'rows' => $rows, 'money' => $money];
    }
}
