<?php

declare(strict_types=1);

/**
 * THE DOCUMENT THAT LEAVES THE BUILDING.
 *
 * A payslip is shown to a bank, a landlord, the next employer and sometimes a
 * tribunal, so the rules are stricter than for a screen: it is READ from the
 * closed run and never recomputed, and an unapproved run produces a payslip that
 * says so on its face rather than one that looks final.
 */

use SmartPRS\Surface\Payslip;

group('payslip');

$row = [
    'name' => 'Ramesh Naidu',
    'gross' => '96000.00',
    'payable_days' => 4,
    'period_days' => 30,
    'concerns' => [],
    'earnings' => '₹96,000.00',
    'deductions' => '₹200.00',
    'net' => '₹95,800.00',
    'net_minor' => 9580000,
    'lines' => [
        ['code' => 'C-BASIC', 'kind' => 'earning', 'amount' => '₹48,000.00', 'minor' => 4800000,
            'trace' => '50% of ₹96,000.00 = ₹48,000.00'],
        ['code' => 'D-PT', 'kind' => 'deduction', 'amount' => '₹200.00', 'minor' => 20000,
            'trace' => 'D-PT is a flat ₹200.00, and does NOT prorate'],
    ],
];

$draft = ['period' => '2026-09', 'status' => 'closed', 'closed_at' => '2026-09-07',
    'structure_effective_from' => '2026-01-01', 'fingerprint' => 'cf16eea30650cd1eb98bb7c',
    'rows' => ['SP-001' => $row]];

// NON-VACUITY: the ordinary case must produce a payslip, or every refusal below
// proves only that it refuses everything.
$made = Payslip::from($draft, '2026-09', 'SP-001');
ok($made['ok'], 'a closed run produces a payslip: ' . $made['why']);
$slip = $made['slip'];

same('Ramesh Naidu', $slip['name'], 'it names the person');
same(1, count($slip['earnings']), 'earnings are separated from deductions');
same(1, count($slip['deductions']), 'and deductions from earnings');
same('₹95,800.00', $slip['net'], 'the net comes from the run, not from arithmetic done here');

// EVERY LINE CARRIES THE RUN'S OWN EXPLANATION. Not regenerated: a fresh
// explanation of an old number is a guess about what the rules used to be.
ok(str_contains($slip['earnings'][0]['trace'], '50% of'),
    'each line carries the sentence the RUN wrote when it computed the figure');
ok(str_contains($slip['deductions'][0]['trace'], 'does NOT prorate'),
    'including the ones that explain why a figure did not change');

foreach (array_merge($slip['earnings'], $slip['deductions']) as $line) {
    ok(trim((string) $line['trace']) !== '', sprintf(
        'Line %s must explain itself — an amount with no explanation is the line an employee rings '
        . 'up about.', (string) $line['code']));
}

// The net, in words, on the Indian scale — what a dispute is settled by.
same('Rupees Ninety Five Thousand Eight Hundred Only', $slip['net_in_words'],
    'the net is written out, on the Indian scale');
same('Rupees Forty Two Lakh Only', Payslip::words(420000000), 'lakh, never million');

// AN UNAPPROVED RUN SAYS SO. Withheld would be wrong — HR has to check the
// draft — but an employee must never be handed one that looks final.
ok($slip['approved'] === false, 'a closed-but-unapproved run produces a DRAFT payslip');

$approved = $draft;
$approved['status'] = 'approved';
$approved['approved_by'] = 'ejaz@ametecsindia.com';
$approved['approved_at'] = '2026-09-08';
$signed = Payslip::from($approved, '2026-09', 'SP-001');

ok($signed['slip']['approved'] === true, 'and an approved run produces one that is not a draft');
same('ejaz@ametecsindia.com', $signed['slip']['approved_by'],
    'naming who signed it — approval is the moment a person takes responsibility');

// NO RUN IS A REFUSAL, NOT A BLANK PAYSLIP. A blank document with somebody's
// name on it looks like a month in which they earned nothing.
$none = Payslip::from(null, '2026-01', 'SP-001');
ok(!$none['ok'], 'a month with no closed payroll produces no payslip');
ok(str_contains($none['why'], '2026-01'), 'and says which month');
ok(!isset($none['slip']), 'and carries no slip at all, not an empty one');

// NOT IN THE RUN is a different fact from PAID NOTHING, and only one of them is
// somebody's mistake.
$absent = Payslip::from($draft, '2026-09', 'SP-999');
ok(!$absent['ok'], 'an employment not in the run produces no payslip');
ok(str_contains($absent['why'], 'SP-999'), 'and names them');
ok(str_contains($absent['why'], 'paid nothing'),
    'and distinguishes "not in this payroll" from "paid nothing this month"');

// The months offered are the ones the employment is actually IN. Offering one
// that renders "not in this run" is a dead end with a person's name on it.
same(['2026-09'], Payslip::periodsIn([$draft], 'SP-001'), 'only months this employment is in');
same([], Payslip::periodsIn([$draft], 'SP-999'), 'and none at all for somebody who is in no run');
