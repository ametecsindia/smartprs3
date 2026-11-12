<?php

declare(strict_types=1);

use SmartPRS\Surface\PayrollReports;
use SmartPRS\Surface\Recoveries;

/**
 * THE PAYROLL REPORT ADDS UP THE WAY THE PAYSLIPS UNDERNEATH IT DO.
 *
 * Owner, 23 Sep: a Reports page under Payroll — a summary grouped by company,
 * by company and branch, and by company and department, and a detailed report
 * with a column per salary component.
 *
 * The properties worth holding a report to are the ones nobody checks by eye on
 * a screen of forty numbers:
 *
 *   - AN EMPLOYER CONTRIBUTION IS NOT A DEDUCTION. It is the company's cost and
 *     is never withheld from anybody's pay, so it must not touch gross-to-net.
 *     Counting it as a deduction understates every net on the report and the
 *     error grows with the size of the company.
 *   - THE PARTS ADD TO THE WHOLE. Companies must sum to overall and branches to
 *     their company, by construction, not coincidence.
 *   - HEADCOUNT IS PEOPLE, NOT PAYSLIPS. Three months of 2 employees is 6
 *     payslips and 2 people.
 *   - FOUR HUNDRED LOANS ARE ONE COLUMN. Recoveries writes an instalment under
 *     the obligation's own code so a run can be read back per loan; a report
 *     that made a column of each would be unreadable and the column for loan 41
 *     would mean a different person on every row.
 */

group('payroll reports · summary and buckets');

/** One run line, in the shape Register stores. */
$line = static fn (string $code, string $kind, int $minor): array
    => ['code' => $code, 'kind' => $kind, 'amount' => '', 'minor' => $minor, 'trace' => ''];

/** One payroll fact, in the shape payrollFacts() returns. */
$fact = static function (string $period, string $code, string $company, string $branch,
    string $department, array $lines) : array {
    $net = 0;

    foreach ($lines as $one) {
        if ($one['kind'] === PayrollReports::EARNING) {
            $net += $one['minor'];
        } elseif ($one['kind'] !== PayrollReports::EMPLOYER) {
            $net -= $one['minor'];
        }
    }

    return ['period' => $period, 'approved' => true, 'code' => $code, 'name' => $code,
        'org' => ['company' => $company, 'branch' => $branch, 'department' => $department,
            'role' => '', 'designation' => '', 'team' => ''],
        'row' => ['name' => $code, 'lines' => $lines, 'net_minor' => $net], 'employment' => []];
};

// --- one row's own arithmetic ---------------------------------------------------

$one = $fact('2026-05', 'E-1', 'Alpha', 'Kondapur', 'Ops', [
    $line('BASIC', PayrollReports::EARNING, 2_000_000),
    $line('HRA', PayrollReports::EARNING, 800_000),
    $line('PF_EMPLOYEE', PayrollReports::DEDUCTION, 180_000),
    $line('PT', PayrollReports::DEDUCTION, 20_000),
    $line('PF_EMPLOYER', PayrollReports::EMPLOYER, 180_000),
    $line(Recoveries::LOAN . '41', PayrollReports::DEDUCTION, 500_000),
]);

$money = PayrollReports::moneyOf($one['row']);
same(2_800_000, $money['gross'], 'gross is the earning lines, nothing else');
same(700_000, $money['deductions'], 'deductions are the withheld lines, loan instalment included');
same(180_000, $money['employer'], "the employer's contribution is counted apart");
same(2_100_000, $money['net'], 'net is gross less deductions — the employer cost does not touch it');
ok(PayrollReports::reconciles($one['row']), 'the row reconciles: gross - deductions = the net paid');

$buckets = PayrollReports::linesOf($one['row']);
same(['BASIC' => 2_000_000, 'HRA' => 800_000], $buckets['earning'], 'earnings keep their own codes');
same(['PF_EMPLOYER' => 180_000], $buckets['employer'], 'the employer bucket holds only employer lines');
ok(isset($buckets['deduction'][PayrollReports::RECOVERY_COLUMN]),
    'a LOAN- instalment lands in the one recovery column, not a column of its own');
ok(!isset($buckets['deduction'][Recoveries::LOAN . '41']),
    'and the obligation\'s own code is not a column');

// TWO LINES OF THE SAME CODE ARE ADDED. An arrear and its ordinary line can both
// be present in one month; keeping one of them is being short by the other.
$twice = ['lines' => [$line('BASIC', PayrollReports::EARNING, 100), $line('BASIC', PayrollReports::EARNING, 250)],
    'net_minor' => 350];
same(['BASIC' => 350], PayrollReports::linesOf($twice)['earning'], 'two lines of one code are added, not replaced');

// A LOAN AND AN ADVANCE IN THE SAME MONTH FOLD TOGETHER.
$both = ['lines' => [$line(Recoveries::LOAN . '1', PayrollReports::DEDUCTION, 100),
    $line(Recoveries::ADVANCE . '9', PayrollReports::DEDUCTION, 400)], 'net_minor' => -500];
same([PayrollReports::RECOVERY_COLUMN => 500], PayrollReports::linesOf($both)['deduction'],
    'a loan and an advance in one month are one recovery figure');

// A ROW THAT DOES NOT RECONCILE SAYS SO rather than being quietly corrected.
$bent = ['lines' => [$line('BASIC', PayrollReports::EARNING, 1000)], 'net_minor' => 999];
ok(!PayrollReports::reconciles($bent), 'a row whose stored net differs from its lines is reported, not adjusted');
same(999, PayrollReports::moneyOf($bent)['net'], 'and the net shown is the one that was paid');

// --- the summary ------------------------------------------------------------------

group('payroll reports · the parts add to the whole');

$pay = static fn (int $basic, int $pf): array => [
    $line('BASIC', PayrollReports::EARNING, $basic),
    $line('PF_EMPLOYEE', PayrollReports::DEDUCTION, $pf),
    $line('PF_EMPLOYER', PayrollReports::EMPLOYER, $pf),
];

$facts = [
    $fact('2026-04', 'E-1', 'Alpha', 'Kondapur', 'Ops', $pay(1_000_000, 100_000)),
    $fact('2026-05', 'E-1', 'Alpha', 'Kondapur', 'Ops', $pay(1_000_000, 100_000)),
    $fact('2026-05', 'E-2', 'Alpha', 'Gachibowli', 'Ops', $pay(2_000_000, 200_000)),
    $fact('2026-05', 'E-3', 'Alpha', 'Gachibowli', 'Finance', $pay(3_000_000, 300_000)),
    $fact('2026-05', 'E-4', 'Beta', 'Chennai', 'Ops', $pay(4_000_000, 400_000)),
    $fact('2026-05', 'E-5', 'Beta', '', 'Finance', $pay(5_000_000, 500_000)),
];

$summary = PayrollReports::summary($facts);

same(16_000_000, $summary['overall']['gross'], 'overall gross is every earning line in the range');
same(1_600_000, $summary['overall']['deductions'], 'overall deductions likewise');
same(1_600_000, $summary['overall']['employer'], "and the company's own cost is kept apart from both");
same(14_400_000, $summary['overall']['net'], 'overall net is gross less deductions');
same(6, $summary['overall']['slips'], 'six payslips in the range');
same(5, $summary['overall']['employees'], 'five PEOPLE — E-1 was paid twice and is one employee');

same(['Alpha', 'Beta'], array_keys($summary['company']), 'companies come out in name order');
same(7_000_000, $summary['company']['Alpha']['gross'], "Alpha's gross");
same(9_000_000, $summary['company']['Beta']['gross'], "Beta's gross");
same($summary['overall']['gross'],
    array_sum(array_column($summary['company'], 'gross')),
    'the companies add up to the overall');
same(3, $summary['company']['Alpha']['employees'], 'Alpha has three people across two months');

same(['Gachibowli', 'Kondapur'], array_keys($summary['branch']['Alpha']), "Alpha's branches, in name order");
same(2_000_000, $summary['branch']['Alpha']['Kondapur']['gross'], 'Kondapur is E-1 twice');
same(1, $summary['branch']['Alpha']['Kondapur']['employees'], 'and that is one person, not two');
same($summary['company']['Alpha']['gross'],
    array_sum(array_column($summary['branch']['Alpha'], 'gross')),
    "Alpha's branches add up to Alpha");
same($summary['company']['Beta']['gross'],
    array_sum(array_column($summary['department']['Beta'], 'gross')),
    "Beta's departments add up to Beta");

ok(isset($summary['branch']['Beta']['Not set']),
    'an employee with no branch is grouped under "Not set" rather than an unlabelled row');

same([], PayrollReports::summary([])['company'], 'no rows in the range is no companies, not a crash');
same(0, PayrollReports::summary([])['overall']['employees'], 'and an overall of nothing');

// --- which columns the detailed report needs ---------------------------------------

group('payroll reports · component columns');

$catalogue = ['BASIC', 'HRA', 'SPECIAL', 'PF_EMPLOYEE', 'TDS', 'PF_EMPLOYER'];
$columns = PayrollReports::columns([$one], $catalogue);

same(['BASIC', 'HRA'], $columns['earning'], 'only the components these rows actually used');
same(['PF_EMPLOYEE', 'PT', PayrollReports::RECOVERY_COLUMN], $columns['deduction'],
    'catalogue order first, then codes the catalogue has never heard of, by name');
same(['PF_EMPLOYER'], $columns['employer'], 'the employer bucket has its own columns');
same(['earning' => [], 'deduction' => [], 'employer' => []], PayrollReports::columns([], $catalogue),
    'nothing to report is no columns');

// SPECIAL IS IN THE CATALOGUE AND IN NOBODY'S PAY. Eighteen empty columns is how
// a table stops being readable.
ok(!in_array('SPECIAL', $columns['earning'], true), 'a component nobody was paid gets no column');

// --- which months a range covers -----------------------------------------------------

group('payroll reports · the months a range covers');

same(['2026-04', '2026-05', '2026-06'], PayrollReports::monthsIn('2026-04-17', '2026-06-02'),
    'a range that starts mid-April and ends early June covers all three months');
same(['2026-05'], PayrollReports::monthsIn('2026-05-01', '2026-05-31'), 'one month is one month');
same(['2025-12', '2026-01'], PayrollReports::monthsIn('2025-12-31', '2026-01-01'), 'a range crosses the year end');
same([], PayrollReports::monthsIn('2026-05-01', '2026-04-01'), 'a backwards range covers nothing');
same(12, count(PayrollReports::monthsIn('2026-04-01', '2027-03-31')), 'a financial year is twelve months');
