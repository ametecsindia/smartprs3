<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT WAS PAID, OVER A RANGE OF MONTHS, ADDED UP THE ONE WAY.
 *
 * Owner, 23 Sep: a Reports page under Payroll — the org filters, a date range
 * (YTD / MTD / last month / custom), and then either a SUMMARY grouped by
 * company, by company and branch, and by company and department, or a DETAILED
 * report with a column per salary component.
 *
 * EVERY FIGURE IS READ FROM THE CLOSED RUNS, never recomputed. That is the same
 * rule `Payslip` keeps and for the same reason: a total worked out months later
 * against a structure that has since changed is a different number from the one
 * that was paid, and a report that disagrees with the payslips underneath it is
 * worse than no report. This class only adds up lines that already exist.
 *
 * THE THREE BUCKETS ARE THE ENGINE'S OWN KINDS, not a list typed here. A run
 * line carries `earning`, `deduction` or `employer_contribution` exactly as
 * `SalaryComponent` defines them, so a component added to the catalogue arrives
 * in the right column without this file changing (Law 1). An employer
 * contribution is NOT a deduction — it is the company's cost, never withheld
 * from anybody's pay — so it is counted apart and is not in the gross-to-net
 * arithmetic at all.
 *
 * GROSS AND DEDUCTIONS ARE SUMMED FROM THE LINES; NET IS THE RUN'S OWN. The run
 * stored `net_minor` as the engine's net less exactly what the statutory and
 * recovery steps returned, and that stored figure is the one that was paid. So
 * net is read, not re-derived — and `reconciles()` below states the invariant
 * that must hold between the three, which is what a test can hold this to.
 *
 * ponytail: monthly periods only, because the register closes calendar months
 * and nothing else. A custom date range picks the months it covers; it cannot
 * pay half of one. Upgrade path if pay cycles ever straddle months: the range
 * would need to weigh a run by the days of it inside the range, which is a
 * different question from this one and wants its own answer.
 */
final class PayrollReports
{
    /** The engine's own line kinds — see SmartPRS\Platform\Payroll\SalaryComponent. */
    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    public const RECOVERY = 'recovery';

    public const EMPLOYER = 'employer_contribution';

    /**
     * THE ONE COLUMN EVERY LOAN AND ADVANCE INSTALMENT FOLDS INTO.
     *
     * `Recoveries` writes an instalment under the obligation's own code —
     * `LOAN-41`, `ADV-7` — so that a closed run can be read back per loan. That
     * is right for the ledger and wrong for a report: a company with four
     * hundred loans would grow four hundred columns, almost all of them empty,
     * and the column for loan 41 would mean a different person every row.
     * Detail per obligation is not lost — it is the three loan columns at the
     * end of the detailed report, which `Recoveries::positionFor()` answers.
     */
    public const RECOVERY_COLUMN = 'RECOVERY';

    /** The prefixes Recoveries uses; folded rather than matched by shape. */
    private const RECOVERY_PREFIXES = [Recoveries::LOAN, Recoveries::ADVANCE];

    /**
     * One payroll row's lines, added up per component code and bucketed by kind.
     *
     * Two lines of the same code in one month are ADDED, not the second one
     * winning: an arrear and its ordinary line can both be present, and a
     * report that silently kept one of them would be short by the other.
     *
     * @param array<string, mixed> $row one employee's row out of a closed run
     * @return array{earning: array<string, int>, deduction: array<string, int>, employer: array<string, int>}
     */
    public static function linesOf(array $row): array
    {
        $out = ['earning' => [], 'deduction' => [], 'employer' => []];

        foreach ((array) ($row['lines'] ?? []) as $line) {
            $code = self::columnOf((string) ($line['code'] ?? ''));

            if ($code === '') {
                continue;
            }

            $bucket = match ((string) ($line['kind'] ?? '')) {
                self::EARNING => 'earning',
                self::EMPLOYER => 'employer',
                // `deduction`, `recovery`, and anything a future engine calls a
                // withholding: it came off this person's pay, so it is a
                // deduction here. Defaulting the OTHER way would quietly move a
                // withheld amount out of the gross-to-net arithmetic, which is
                // the one error in this file nobody would see on the screen.
                default => 'deduction',
            };

            $out[$bucket][$code] = ($out[$bucket][$code] ?? 0) + (int) ($line['minor'] ?? 0);
        }

        return $out;
    }

    /**
     * The four figures for one payroll row, in paise.
     *
     * @param array<string, mixed> $row
     * @return array{gross: int, deductions: int, employer: int, net: int}
     */
    public static function moneyOf(array $row): array
    {
        $lines = self::linesOf($row);

        return [
            'gross' => array_sum($lines['earning']),
            'deductions' => array_sum($lines['deduction']),
            'employer' => array_sum($lines['employer']),
            // THE RUN'S OWN NET, not gross less deductions. They are the same
            // number whenever the run is sound; when they are not, the stored
            // one is what was paid and the difference is a finding, which is
            // what reconciles() is for.
            'net' => (int) ($row['net_minor'] ?? 0),
        ];
    }

    /**
     * Does this row's own arithmetic hold — gross less deductions equal to the
     * net that was paid?
     *
     * Stated as a question rather than enforced, because a row that fails it
     * has already been paid and hiding it would not unpay it. The screen shows
     * such a row with its difference; this is what makes that possible.
     *
     * @param array<string, mixed> $row
     */
    public static function reconciles(array $row): bool
    {
        $money = self::moneyOf($row);

        return $money['gross'] - $money['deductions'] === $money['net'];
    }

    /**
     * THE SUMMARY: overall, then per company, then per company and branch, then
     * per company and department.
     *
     * Every level is added up from the SAME rows in one pass, so the companies
     * add to the overall and the branches add to their company by construction
     * rather than by luck. A second pass per level is how a report ends up with
     * a total that is not the sum of its own lines.
     *
     * HEADCOUNT IS DISTINCT PEOPLE, NOT PAYSLIPS. Over three months a company of
     * 36 has 108 payslips and still 36 employees, and "108 employees" on a
     * quarterly report is the kind of number somebody repeats in a board meeting.
     * Both are carried: `employees` and `slips`.
     *
     * @param list<array<string, mixed>> $facts as payrollFacts() returns them
     * @return array{overall: array<string, mixed>, company: array<string, array<string, mixed>>,
     *     branch: array<string, array<string, array<string, mixed>>>,
     *     department: array<string, array<string, array<string, mixed>>>}
     */
    public static function summary(array $facts): array
    {
        $out = ['overall' => self::zero(), 'company' => [], 'branch' => [], 'department' => []];
        $seen = ['overall' => [], 'company' => [], 'branch' => [], 'department' => []];

        foreach ($facts as $fact) {
            $money = self::moneyOf((array) ($fact['row'] ?? []));
            $code = (string) ($fact['code'] ?? '');
            $org = (array) ($fact['org'] ?? []);
            $company = self::named($org['company'] ?? '');
            $branch = self::named($org['branch'] ?? '');
            $department = self::named($org['department'] ?? '');

            self::add($out['overall'], $money);
            $seen['overall'][$code] = true;

            $out['company'][$company] ??= self::zero();
            self::add($out['company'][$company], $money);
            $seen['company'][$company][$code] = true;

            $out['branch'][$company][$branch] ??= self::zero();
            self::add($out['branch'][$company][$branch], $money);
            $seen['branch'][$company . "\0" . $branch][$code] = true;

            $out['department'][$company][$department] ??= self::zero();
            self::add($out['department'][$company][$department], $money);
            $seen['department'][$company . "\0" . $department][$code] = true;
        }

        $out['overall']['employees'] = count($seen['overall']);

        foreach ($out['company'] as $company => $_) {
            $out['company'][$company]['employees'] = count($seen['company'][$company] ?? []);
        }

        foreach (['branch', 'department'] as $level) {
            foreach ($out[$level] as $company => $rows) {
                foreach (array_keys($rows) as $key) {
                    $out[$level][$company][$key]['employees']
                        = count($seen[$level][$company . "\0" . $key] ?? []);
                }
            }
        }

        ksort($out['company']);
        ksort($out['branch']);
        ksort($out['department']);

        foreach (['branch', 'department'] as $level) {
            foreach (array_keys($out[$level]) as $company) {
                ksort($out[$level][$company]);
            }
        }

        return $out;
    }

    /**
     * WHICH COMPONENT COLUMNS THIS REPORT ACTUALLY NEEDS, per bucket.
     *
     * Only codes that appear in the rows being reported: a catalogue of 27
     * components printed in full is 27 columns of which a given company uses
     * nine, and eighteen empty columns is how a table stops being readable.
     * Ordered by the CATALOGUE, so BASIC comes before SPECIAL on every report
     * rather than in whatever order the first employee's lines happened to be
     * stored; codes the catalogue has never heard of — a statutory head, the
     * folded recovery column — keep their bucket and sort after it by name.
     *
     * @param list<array<string, mixed>> $facts
     * @param list<string> $catalogueOrder component codes, in the catalogue's own order
     * @return array{earning: list<string>, deduction: list<string>, employer: list<string>}
     */
    public static function columns(array $facts, array $catalogueOrder): array
    {
        $present = ['earning' => [], 'deduction' => [], 'employer' => []];

        foreach ($facts as $fact) {
            foreach (self::linesOf((array) ($fact['row'] ?? [])) as $bucket => $byCode) {
                foreach (array_keys($byCode) as $code) {
                    $present[$bucket][(string) $code] = true;
                }
            }
        }

        $rank = array_flip(array_values($catalogueOrder));
        $out = [];

        foreach ($present as $bucket => $codes) {
            $codes = array_keys($codes);
            usort($codes, static function (string $a, string $b) use ($rank): int {
                $ra = $rank[$a] ?? PHP_INT_MAX;
                $rb = $rank[$b] ?? PHP_INT_MAX;

                return $ra === $rb ? strcmp($a, $b) : $ra <=> $rb;
            });
            $out[$bucket] = $codes;
        }

        return $out;
    }

    /**
     * The months a date range covers, as the register names them (YYYY-MM).
     *
     * A range is whole months because a run is a whole month — see the class
     * docblock. `2026-04-17 → 2026-06-02` is April, May and June: every run
     * whose month touches the range, not only the ones inside it, because a
     * report of "April to June" that silently dropped April is a report that
     * is wrong by a month's payroll and says nothing about it.
     *
     * @return list<string>
     */
    public static function monthsIn(string $from, string $to): array
    {
        $first = substr($from, 0, 7);
        $last = substr($to, 0, 7);

        if ($first === '' || $last === '' || $last < $first) {
            return [];
        }

        $months = [];
        $cursor = $first . '-01';

        // A thousand months is eighty-three years: a ceiling that no real range
        // reaches and a malformed one cannot run past.
        for ($i = 0; $i < 1000 && substr($cursor, 0, 7) <= $last; $i++) {
            $months[] = substr($cursor, 0, 7);
            $cursor = date('Y-m-d', (int) strtotime($cursor . ' +1 month'));
        }

        return $months;
    }

    /** A component code as the column it belongs to — every loan and advance in one. */
    private static function columnOf(string $code): string
    {
        foreach (self::RECOVERY_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return self::RECOVERY_COLUMN;
            }
        }

        // C-BASIC and BASIC are one component (28 Sep): one column, not two
        // both headed "Basic".
        return preg_match('/^[A-Z]-(.+)$/', $code, $m) === 1 ? $m[1] : $code;
    }

    /** A blank org value said once, rather than as an empty cell on every screen. */
    private static function named(mixed $value): string
    {
        $text = trim((string) $value);

        return $text === '' ? 'Not set' : $text;
    }

    /** @return array<string, int> */
    private static function zero(): array
    {
        return ['gross' => 0, 'deductions' => 0, 'employer' => 0, 'net' => 0, 'slips' => 0, 'employees' => 0];
    }

    /**
     * @param array<string, int> $totals
     * @param array{gross: int, deductions: int, employer: int, net: int} $money
     */
    private static function add(array &$totals, array $money): void
    {
        $totals['gross'] += $money['gross'];
        $totals['deductions'] += $money['deductions'];
        $totals['employer'] += $money['employer'];
        $totals['net'] += $money['net'];
        $totals['slips']++;
    }
}
