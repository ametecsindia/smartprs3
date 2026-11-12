<?php

declare(strict_types=1);

use SmartPRS\Surface\Payslip;

/**
 * AN EMPLOYER CONTRIBUTION IS NOT A DEDUCTION, ON THE PAYSLIP EITHER.
 *
 * The employer's half of PF, gratuity, group insurance — the company's own cost.
 * None of it is withheld from anybody's pay.
 *
 * `Payslip::from()` used to read `kind === 'earning' ? earnings : deductions`, so
 * every employer line landed in the deduction list. The TOTAL was right, because
 * the run computes it without them — which made the defect worse rather than
 * better: the printed deduction LINES did not add up to the printed TOTAL
 * DEDUCTIONS, on the one document this product makes that leaves the building and
 * gets shown to a bank, a landlord and the next employer.
 *
 * So the properties here are about a document, not a calculation:
 *
 *   - the deduction list adds up to the deduction total;
 *   - the employer's contributions are present (an employee is entitled to know
 *     what is paid on their behalf) and in their own bucket;
 *   - and NOTHING about net pay moved, because net was never wrong.
 */

group('payslip · the company\'s share is not a deduction');

/** One stored run line, in the shape Register writes. */
$line = static fn (string $code, string $kind, int $minor): array => [
    'code' => $code, 'kind' => $kind, 'amount' => '', 'minor' => $minor, 'trace' => 'because',
];

$run = [
    'period' => '2026-05',
    'status' => 'approved',
    'approved_by' => 'hr@example.test',
    'rows' => [
        'E-1' => [
            'name' => 'Ganesh Nayak',
            'lines' => [
                $line('C-BASIC', 'earning', 2_000_000),
                $line('C-HRA', 'earning', 800_000),
                $line('PF_EMPLOYEE', 'deduction', 180_000),
                $line('D-PT', 'deduction', 20_000),
                // The kind AS THE RUN STORES IT, written out rather than taken
                // from the constant: this fixture stands in for a row already on
                // disk, and a test that reads the same constant as the code it is
                // testing cannot tell you the two ever agreed.
                $line('PF_EMPLOYER', 'employer_contribution', 180_000),
                $line('GRATUITY', 'employer_contribution', 96_150),
            ],
            'earnings' => '₹28,000.00',
            // What the RUN computed: gross less the two real deductions. The
            // employer's share is not in it, and never was.
            'deductions' => '₹2,000.00',
            'net' => '₹26,000.00',
            'net_minor' => 2_600_000,
            'payable_days' => '31',
            'period_days' => 31,
        ],
    ],
];

// Asked with defined() rather than read directly, so a build without the constant
// FAILS THIS ONE ASSERTION and still runs the rest — a test that fatals on line one
// tells you nothing about the twelve properties underneath it.
ok(defined(Payslip::class . '::EMPLOYER') && Payslip::EMPLOYER === 'employer_contribution',
    "Payslip's own name for the kind is the one the engine writes into a run");

$out = Payslip::from($run, '2026-05', 'E-1');
ok($out['ok'], 'the payslip is issued');
$slip = $out['slip'];

// --- the three buckets -----------------------------------------------------------

same(['C-BASIC', 'C-HRA'], array_column($slip['earnings'], 'code'), 'earnings are the earning lines');
same(['PF_EMPLOYEE', 'D-PT'], array_column($slip['deductions'], 'code'),
    'deductions are what was withheld — and nothing else');
same(['PF_EMPLOYER', 'GRATUITY'], array_column((array) ($slip['employer'] ?? []), 'code'),
    "the company's own contributions are present, in their own bucket");

ok(!in_array('PF_EMPLOYER', array_column($slip['deductions'], 'code'), true),
    'the employer half of PF is NOT listed as a deduction');

// --- the printed lines add up to the printed total ---------------------------------
// This is the assertion the defect broke, and the reason it matters: an employee
// with a calculator gets the same answer the document states.

same(200_000, (int) array_sum(array_column($slip['deductions'], 'minor')),
    'the deduction lines add up to ₹2,000.00 — the total the slip prints');
same(276_150, (int) array_sum(array_column((array) ($slip['employer'] ?? []), 'minor')),
    "and the company's contributions add up on their own");

// --- nothing about net moved -------------------------------------------------------

same(2_600_000, $slip['net_minor'], 'net pay is the run\'s own figure, untouched');
same('₹2,000.00', $slip['deductions_total'], 'and so is the deduction total');
same(2_800_000,
    (int) array_sum(array_column($slip['earnings'], 'minor')),
    'gross is the earning lines only');
ok((int) array_sum(array_column($slip['earnings'], 'minor'))
    - (int) array_sum(array_column($slip['deductions'], 'minor')) === $slip['net_minor'],
    'gross less deductions equals the net that was paid — with the employer lines out of it');

// --- a payslip with no employer contributions is unchanged --------------------------
// The overwhelming majority of installs have none declared, and they must not
// grow an empty block on every document.

$plain = $run;
$plain['rows']['E-1']['lines'] = array_slice($run['rows']['E-1']['lines'], 0, 4);
$plainSlip = Payslip::from($plain, '2026-05', 'E-1')['slip'];
same([], (array) ($plainSlip['employer'] ?? []), 'no employer components means an empty bucket, not a missing key');
same(2, count($plainSlip['deductions']), 'and the deductions are exactly what they were');
