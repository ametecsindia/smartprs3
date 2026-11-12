<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * INCOME TAX ON SALARY — the computation sheet every corporate payslip and Form 130 carries:
 *
 *   Gross salary (this employer + previous employer)
 *   − exemptions (old regime: HRA, LTA)          − standard deduction      − professional tax (old)
 *   = income from salary   − house-property loss (old)   + other income    = gross total income
 *   − Chapter VIII deductions (old 80C/80CCD/80D/… ; new regime: employer NPS only)
 *   = taxable income (rounded to ₹10)  → slab tax − rebate (87A, with marginal relief) + surcharge + cess
 *   = total tax (rounded to ₹10)       → monthly TDS = (total tax − TDS already paid) ÷ months left
 *
 * EVERY RATE, SLAB, CAP AND CITY comes from config/income-tax.json (law 1). Money is paise in
 * integers, never a float (law 7). Every figure is returned as a labelled line (law 4): the screen,
 * the payslip trace and the payroll all print the same sheet.
 *
 * ponytail: surcharge has no marginal relief (it matters only just above ₹50 lakh); add it when
 * an employee in that band appears.
 */
final class IncomeTax
{
    /**
     * The year's block. A NEW YEAR NOT YET IN THE FILE CARRIES FORWARD the latest earlier year (owner,
     * 15 Oct: the year changes by itself on 1 April) — the law stays as it was until a Finance Act
     * changes it — flagged `carried_forward_from` so every screen says the rules await confirmation.
     * [] only for a year earlier than any configured.
     *
     * @return array<string, mixed>
     */
    public static function year(string $file, string $fy): array
    {
        return self::yearFrom((array) (((array) json_decode((string) @file_get_contents($file), true))['years'] ?? []), $fy);
    }

    /** The same, from years already loaded (the file merged with the organisation's own edits). */
    public static function yearFrom(array $years, string $fy): array
    {
        if (isset($years[$fy])) {
            return (array) $years[$fy];
        }

        $earlier = array_filter(array_keys($years), static fn (string $y): bool => $y < $fy);

        if ($earlier === []) {
            return [];
        }

        $from = max($earlier);
        $y = (array) $years[$from];
        $y['carried_forward_from'] = $from;
        $y['label'] = 'Tax Year ' . $fy . ' — rules of ' . $from . ' carried forward (an administrator updates them on Income Tax Rules → Edit rules when the Finance Act changes them)';

        return $y;
    }

    /** The financial year a date falls in, "2026-27", for an April start. */
    public static function fyOf(string $date, int $startMonth = 4): string
    {
        $y = (int) substr($date, 0, 4);
        $start = (int) substr($date, 5, 2) >= $startMonth ? $y : $y - 1;

        return sprintf('%d-%02d', $start, ($start + 1) % 100);
    }

    /** Paise from a rupee figure ("1,50,000", "₹12.50", "" → 0). */
    public static function p(mixed $v): int
    {
        return Amount::of($v)->minor;
    }

    private static function pct(int $minor, string $pct): int
    {
        // pct may carry decimals ("0.75"): scale to basis points, round half up, all in integers.
        [$w, $f] = explode('.', $pct . '.');
        $bp = (int) $w * 100 + (int) str_pad(substr($f, 0, 2), 2, '0');

        return intdiv($minor * $bp + 5000, 10000);
    }

    private static function roundTo(int $minor, int $rupees): int
    {
        $unit = $rupees * 100;

        return $unit <= 0 ? $minor : intdiv($minor + intdiv($unit, 2), $unit) * $unit;
    }

    /** Tax on $taxable over the slabs. @param list<array{upto: ?string, pct: string}> $slabs */
    public static function slabTax(int $taxable, array $slabs): int
    {
        $tax = 0;
        $floor = 0;

        foreach ($slabs as $s) {
            $top = $s['upto'] === null ? PHP_INT_MAX : self::p($s['upto']);

            if ($taxable > $floor) {
                $tax += self::pct(min($taxable, $top) - $floor, (string) $s['pct']);
            }

            $floor = $top;
        }

        return $tax;
    }

    /** The slabs reached, as words: "up to ₹4,00,000 nil · ₹4,00,000–₹8,00,000 at 5% = ₹20,000". */
    public static function slabNote(int $taxable, array $slabs): string
    {
        $parts = [];
        $floor = 0;
        foreach ($slabs as $s) {
            if ($taxable <= $floor) { break; }
            $top = $s['upto'] === null ? PHP_INT_MAX : self::p($s['upto']);
            $band = min($taxable, $top) - $floor;
            $range = ($floor === 0 ? 'up to ' : Money::ofMinor($floor)->format() . '–') . ($top === PHP_INT_MAX ? 'above' : Money::ofMinor($top)->format());
            $top === PHP_INT_MAX && $range = 'above ' . Money::ofMinor($floor)->format();
            $parts[] = $range . ((string) $s['pct'] === '0' ? ' nil' : ' at ' . $s['pct'] . '% = ' . Money::ofMinor(self::pct($band, (string) $s['pct']))->format());
            $floor = $top;
        }

        return implode(' · ', $parts);
    }

    /**
     * The whole sheet for one regime.
     *
     * @param array<string, mixed> $year config/income-tax.json years[fy]
     * @param array{age?: int, gross: int, basic_da?: int, hra_received?: int, lta_received?: int,
     *     employee_pf?: int, pt?: int, employer_nps?: int, rent_annual?: int, metro?: bool,
     *     parents_senior?: bool, items?: array<string, int>} $in all money in paise, annual
     * @return array{regime: string, lines: list<array{0: string, 1: int, 2?: string}>, taxable: int,
     *     tax: int, deductions: array<string, int>, exempt: array<string, int>, total: int}
     */
    public static function compute(array $year, string $regime, array $in): array
    {
        $r = (array) ($year['regimes'][$regime] ?? []);

        if ($r === []) {
            throw new \InvalidArgumentException("No '{$regime}' regime is configured for this year in config/income-tax.json.");
        }

        $items = array_map('intval', (array) ($in['items'] ?? []));
        $it = static fn (string $c): int => max(0, (int) ($items[$c] ?? 0));
        // A NON-RESIDENT gets no rebate and no age-based basic exemption (the deduction caps for
        // seniors still follow age).
        $resident = (bool) ($in['resident'] ?? true);
        $age = (int) ($in['age'] ?? 30);
        $senior = $age >= 60;
        $old = $regime === 'old';
        $lines = [];

        // 1. GROSS SALARY — this employer (payroll + salary paid before SmartPRS 3 went live) plus
        // any previous employer this year.
        $thisEmployer = max(0, (int) $in['gross']) + $it('EARLIER_SALARY');
        $gross = $thisEmployer + $it('PREV_INCOME');
        $lines[] = ['Gross salary', $gross, implode('; ', array_filter([
            $it('EARLIER_SALARY') > 0 ? 'includes ' . Money::ofMinor($it('EARLIER_SALARY'))->format() . ' paid before SmartPRS 3' : '',
            $it('PREV_INCOME') > 0 ? 'includes previous employer ' . Money::ofMinor($it('PREV_INCOME'))->format() : '',
        ]))];

        // 2. EXEMPTIONS — old regime only.
        $exempt = [];
        $allowedEx = (array) ($r['exemptions_allowed'] ?? []);

        if (in_array('HRA', $allowedEx, true) && ($in['rent_annual'] ?? 0) > 0 && ($in['hra_received'] ?? 0) > 0) {
            $h = (array) $year['hra'];
            $salary = (int) ($in['basic_da'] ?? 0);
            $exempt['HRA'] = max(0, min(
                (int) $in['hra_received'],
                (int) $in['rent_annual'] - self::pct($salary, (string) $h['rent_less_pct_of_salary']),
                self::pct($salary, (string) (($in['metro'] ?? false) ? $h['metro_pct'] : $h['other_pct'])),
            ));
        }

        if (in_array('LTA', $allowedEx, true) && $it('LTA') > 0) {
            $exempt['LTA'] = min($it('LTA'), (int) ($in['lta_received'] ?? 0));
        }

        foreach ($exempt as $k => $v) {
            $lines[] = ['Less: ' . ($k === 'HRA' ? 'HRA exemption' : 'LTA exemption'), -$v];
        }

        // 3. STANDARD DEDUCTION and PROFESSIONAL TAX.
        $std = min(self::p($r['standard_deduction']), max(0, $gross - array_sum($exempt)));
        $lines[] = ['Less: Standard deduction', -$std];
        $pt = ($r['professional_tax_deductible'] ?? false) ? (int) ($in['pt'] ?? 0) + $it('PREV_PT') : 0;
        $pt > 0 && $lines[] = ['Less: Professional tax', -$pt];
        $salaryIncome = max(0, $gross - array_sum($exempt) - $std - $pt);
        $lines[] = ['Income from salary', $salaryIncome];

        // 4. HOUSE PROPERTY (old) and OTHER INCOME.
        $houseCap = self::groupCap($year, 'HOUSE');
        $houseLoss = $old ? min($it('HL_INTEREST'), $houseCap ?? PHP_INT_MAX) : 0;
        $houseLoss > 0 && $lines[] = ['Less: Home loan interest (house property)', -$houseLoss];
        $other = $it('OTHER_INCOME');
        $other > 0 && $lines[] = ['Add: Other income', $other];
        $gti = max(0, $salaryIncome - $houseLoss + $other);
        $lines[] = ['Gross total income', $gti];

        // 5. CHAPTER VIII DEDUCTIONS.
        $allowed = $r['chapter_via_allowed'] ?? [];
        $allowedOf = static fn (string $g): bool => $allowed === 'all' || in_array($g, (array) $allowed, true);
        $ded = [];
        $groupItems = [];

        foreach ((array) ($year['items'] ?? []) as $item) {
            $groupItems[(string) $item['group']][] = $item;
        }

        // Payroll's own figures stand in for the auto items.
        $items['EPF'] = (int) ($in['employee_pf'] ?? 0);
        $items['NPS_EMPLOYER'] = (int) ($in['employer_nps'] ?? 0);

        foreach (['80C', '80CCD1B'] as $g) {
            if (!$allowedOf($g)) { continue; }
            $sum = 0;
            foreach ($groupItems[$g] ?? [] as $item) { $sum += max(0, (int) ($items[(string) $item['code']] ?? 0)); }
            $cap = self::groupCap($year, $g);
            $sum > 0 && $ded[$g] = $cap === null ? $sum : min($sum, $cap);
        }

        if ($allowedOf('80CCD2') && $items['NPS_EMPLOYER'] > 0) {
            $pctCap = (string) ((self::group($year, '80CCD2')['cap_pct_of_salary'] ?? [])[$regime] ?? '0');
            $ded['80CCD2'] = min($items['NPS_EMPLOYER'], self::pct((int) ($in['basic_da'] ?? 0), $pctCap));
        }

        foreach (['80D', 'OTHER_VIA'] as $g) {
            if (!$allowedOf($g)) { continue; }
            foreach ($groupItems[$g] ?? [] as $item) {
                $v = max(0, (int) ($items[(string) $item['code']] ?? 0));
                if ($v === 0) { continue; }
                $capKey = match (true) {
                    isset($item['cap_if_senior_self']) && $senior => 'cap_if_senior_self',
                    isset($item['cap_if_senior_parents']) && ($in['parents_senior'] ?? false) => 'cap_if_senior_parents',
                    default => 'cap',
                };
                $ded[(string) $item['code']] = isset($item[$capKey]) ? min($v, self::p($item[$capKey])) : $v;
            }
        }

        $dedTotal = min(array_sum($ded), $gti);

        foreach ($ded as $k => $v) {
            $lines[] = ['Less: ' . self::dedLabel($year, (string) $k), -$v];
        }

        // 6. TAXABLE INCOME → TAX.
        $taxable = self::roundTo(max(0, $gti - $dedTotal), (int) ($year['round_taxable_to'] ?? 10));
        $lines[] = ['Taxable income', $taxable];
        $slabs = isset($r['slabs']) ? (array) $r['slabs']
            : (array) ($r['slabs_by_age'][!$resident ? 'below_60' : ($age >= 80 ? '80_plus' : ($senior ? '60_to_79' : 'below_60'))] ?? []);
        $tax = self::slabTax($taxable, $slabs);
        $slabTax = $tax;
        $lines[] = ['Tax on taxable income', $tax, self::slabNote($taxable, $slabs)];

        $rb = (array) ($r['rebate'] ?? []);
        $limit = $resident && isset($rb['income_upto']) ? self::p($rb['income_upto']) : 0;
        $rebate = 0;

        if ($limit > 0 && $taxable <= $limit) {
            $rebate = min($tax, self::p($rb['max']));
        } elseif ($limit > 0 && ($rb['marginal_relief'] ?? false) && $tax > $taxable - $limit) {
            // MARGINAL RELIEF: just above the rebate limit, tax never exceeds the income above it.
            $rebate = $tax - ($taxable - $limit);
        }

        $rebate > 0 && $lines[] = ['Less: Rebate (Section 156, old 87A)' . ($taxable > $limit ? ' — marginal relief' : ''), -$rebate,
            $taxable > $limit ? 'tax limited to the income above ' . Money::ofMinor($limit)->format() : 'taxable income is within ' . Money::ofMinor($limit)->format() . ', so the tax is rebated up to ' . Money::ofMinor(self::p($rb['max']))->format()];
        $tax -= $rebate;

        $sRate = '0';
        foreach ((array) ($r['surcharge'] ?? []) as $s) {
            if ($taxable > self::p($s['above'])) { $sRate = (string) $s['pct']; }
        }
        $surcharge = self::pct($tax, $sRate);
        $surcharge > 0 && $lines[] = ['Add: Surcharge ' . $sRate . '%', $surcharge];
        $cess = self::pct($tax + $surcharge, (string) $year['cess_pct']);
        $lines[] = ['Add: Health & education cess ' . $year['cess_pct'] . '%', $cess];
        $total = self::roundTo($tax + $surcharge + $cess, 10);
        $lines[] = ['Total tax for the year', $total];

        // WHY THE TAX IS WHAT IT IS, in one sentence a payslip reader can follow — above all when it is nil.
        $firstNil = ((string) ($slabs[0]['pct'] ?? '') === '0' && ($slabs[0]['upto'] ?? null) !== null) ? self::p($slabs[0]['upto']) : 0;
        $why = [];
        $dedTotal > 0 && $why[] = 'Deductions claimed (investments and the like) of ' . Money::ofMinor($dedTotal)->format() . ' bring taxable income down to ' . Money::ofMinor($taxable)->format() . '.';
        $dedTotal === 0 && $why[] = 'No investment or other deduction is counted' . ($allowed === 'all' ? '' : ' — under this regime 80C, 80D and similar investments are not deductible') . '; taxable income is ' . Money::ofMinor($taxable)->format() . '.';
        if ($total === 0 && $taxable <= $firstNil) {
            $why[] = 'It is within the ' . Money::ofMinor($firstNil)->format() . ' nil slab, so no tax is payable.';
        } elseif ($total === 0 && $rebate > 0) {
            $why[] = 'Slab tax of ' . Money::ofMinor($tax + $rebate)->format() . ' is fully rebated under Section 156 because taxable income is within ' . Money::ofMinor($limit)->format() . ', so no tax is payable.';
        } else {
            $why[] = 'Overall tax payable for the year is ' . Money::ofMinor($total)->format() . '.';
        }

        // THE SAME FIGURES BY NAME, for Form 130 (old Form 16) Part B, which prints each on its own row.
        $parts = ['this_employer' => $thisEmployer, 'previous_employer' => $it('PREV_INCOME'), 'gross' => $gross,
            'exempt' => array_sum($exempt), 'standard_deduction' => $std, 'professional_tax' => $pt, 'salary_income' => $salaryIncome,
            'house_property' => -$houseLoss, 'other_income' => $other, 'gross_total_income' => $gti, 'deductions' => $dedTotal,
            'taxable' => $taxable, 'slab_tax' => $slabTax, 'rebate' => $rebate, 'surcharge' => $surcharge, 'cess' => $cess, 'total' => $total];

        return ['regime' => $regime, 'lines' => $lines, 'taxable' => $taxable, 'tax' => $tax, 'why' => implode(' ', $why),
            'deductions' => $ded, 'exempt' => $exempt, 'total' => $total, 'parts' => $parts, 'items' => $items];
    }

    /** Monthly TDS: what is still owed, spread over the months left (this one included), whole rupees. */
    public static function monthly(int $annualTax, int $paidSoFar, int $monthsLeft): int
    {
        $left = max(0, $annualTax - $paidSoFar);

        // Rounded UP to the rupee, so the months left always cover what is owed.
        return $monthsLeft <= 0 ? $left : intdiv($left + $monthsLeft * 100 - 1, $monthsLeft * 100) * 100;
    }

    /** Months from $period (YYYY-MM) to the end of its financial year, this month included. */
    public static function monthsLeft(string $period, int $startMonth = 4): int
    {
        $m = (int) substr($period, 5, 2);

        return (($startMonth - 1 - $m + 12) % 12) + 1;
    }

    /** @return array<string, mixed> */
    public static function group(array $year, string $key): array
    {
        foreach ((array) ($year['groups'] ?? []) as $g) {
            if ((string) $g['key'] === $key) { return (array) $g; }
        }

        return [];
    }

    private static function groupCap(array $year, string $key): ?int
    {
        $g = self::group($year, $key);

        return isset($g['cap']) ? self::p($g['cap']) : null;
    }

    public static function dedLabel(array $year, string $k): string
    {
        if (in_array($k, ['80C', '80CCD1B', '80CCD2'], true)) {
            return (string) (self::group($year, $k)['label'] ?? $k);
        }

        foreach ((array) ($year['items'] ?? []) as $item) {
            if ((string) $item['code'] === $k) { return (string) $item['label']; }
        }

        return $k;
    }
}
