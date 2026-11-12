<?php

declare(strict_types=1);

/**
 * A LOAN INSTALMENT ON A REAL PAYSLIP — and never a paisa more than is owed.
 *
 * `loans` and `advances` have been in this build since the port as two CRUD
 * screens and two "outstanding" reports, and no instalment has ever reached a
 * payslip. `Platform\Payroll\Recovery` — finished, tested — had no caller at all.
 * A customer running loans was therefore told the recovery was manual: somebody
 * types a deduction onto a payslip off a spreadsheet, every month.
 *
 * The two things that must be true, and both are asserted here in RUPEES a person
 * can check by hand rather than as a relationship between two computed quantities
 * that could be wrong together:
 *
 *   1. An instalment reduces net pay by EXACTLY the instalment. ₹49,800.00 less a
 *      ₹4,000 EMI is ₹45,800.00.
 *   2. THE LAST INSTALMENT IS THE REMAINDER. Twelve EMIs of ₹4,000 against a
 *      ₹47,500 balance take ₹48,000 — ₹500 the employee never borrowed, under a
 *      trace that explains the arithmetic perfectly while doing it. The twelfth
 *      here is ₹3,500 and the net is ₹46,300.00.
 *
 * And the outstanding those figures come from is DERIVED FROM THE CLOSED RUNS —
 * the eleven months below are real stored runs, not a decremented column. The
 * ported `loans.outstanding` never changes here; if it were the truth, a reversed
 * instalment would leave a balance that is right by luck and a history saying the
 * deduction never happened, which is the SmartPRS 2 defect `Recovery`'s docblock
 * names by name.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\Payslip;
use SmartPRS\Surface\Recoveries;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('recovery');

$root = __DIR__ . '/..';

/**
 * One employment, its loans and advances, and any months already closed.
 *
 * @param list<array<string, mixed>> $loans
 * @param list<array<string, mixed>> $advances
 * @param list<array{period: string, code: string, minor: int}> $alreadyRecovered
 * @return array{0: Register, 1: Calendar, 2: Store}
 */
$fixture = static function (array $loans, array $advances = [], array $alreadyRecovered = [],
    bool $wire = true) use ($root): array {
    $store = (new Store(sys_get_temp_dir() . '/prs3-recovery-' . bin2hex(random_bytes(4))))
        ->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma',
        'ctc' => '600000.00', 'doj' => '2020-01-01', 'status' => 'active']);
    // Approved PAID leave across the months under test, which is the cheapest way
    // to give this employment payable days without an attendance fixture — the
    // same device statutory_in_run_test.php uses, and for the same reason: with no
    // payable days the gross prorates to zero and an instalment could not show.
    $store->put('leaves', ['id' => 'L-1', 'employment_id' => 'SP-001', 'state' => 'approved',
        'leave_type' => 'EL', 'from' => '2026-10-01', 'to' => '2026-11-30',
        'requested_at' => '2026-09-01T00:00:00+05:30']);

    foreach ($loans as $loan) {
        $store->put('loans', $loan);
    }

    foreach ($advances as $advance) {
        $store->put('advances', $advance);
    }

    // MONTHS ALREADY CLOSED, stored as the runs they were. This is the ledger the
    // outstanding is derived from, and it is deliberately the real storage shape
    // rather than a stub: if `Recoveries` read the ported `outstanding` column
    // instead, every figure below would still look plausible and the twelfth
    // instalment would be ₹4,000.
    foreach ($alreadyRecovered as $earlier) {
        $store->put('payroll_runs', ['id' => 'R-' . $earlier['period'], 'period' => $earlier['period'],
            'status' => 'approved', 'rows' => ['SP-001' => ['name' => 'Kavita Sharma', 'lines' => [
                ['code' => $earlier['code'], 'kind' => 'deduction', 'minor' => $earlier['minor'],
                    'amount' => '', 'trace' => 'an instalment taken in ' . $earlier['period']],
            ]]]]);
    }

    $policy = new LeavePolicy($root . '/config/leave-policy.json');
    $calendar = new Calendar($root . '/config/calendar.json');
    $period = new PayrollPeriod(new Attendance($store), new Leave($store, $policy), $policy, $calendar);
    $structure = new Structure($store, $root . '/config/salary-structure.json');

    return [
        new Register($store, $period, $structure, null, $wire ? new Recoveries($store) : null),
        $calendar,
        $store,
    ];
};

/** The deduction lines a closed run wrote for SP-001, by component code. */
$linesOf = static function (array $run, string $prefix = ''): array {
    $found = [];

    foreach ((array) ($run['rows']['SP-001']['lines'] ?? []) as $line) {
        if ($prefix === '' || str_starts_with((string) $line['code'], $prefix)) {
            $found[(string) $line['code']] = $line;
        }
    }

    return $found;
};

$close = static function (array $made, string $period): array {
    [$register, $calendar] = $made;
    $closed = $register->close($period, $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
    ok($closed['ok'], "{$period} closes: " . $closed['why']);

    return $closed['run'] ?? ['rows' => []];
};

// ------------------------------------------------ the baseline, with no loan at all
$bare = $close($fixture([]), '2026-10');
same('₹49,800.00', (string) $bare['rows']['SP-001']['net'],
    '₹6,00,000 a year is ₹50,000 a month; a full month of paid leave pays all 31 days (weekly offs and holidays are payable, 8 Oct), so ₹50,000.00 of gross, less the '
    . "structure's one flat ₹200 deduction. This is the net every figure below is measured against");

// ------------------------------------------------------------ ONE NORMAL INSTALMENT
$normal = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '48000.0000', 'status' => 'active',
        'installments_paid' => 0],
]), '2026-10');
$row = $normal['rows']['SP-001'];
$loan = $linesOf($normal, Recoveries::LOAN)['LOAN-17'] ?? null;

ok($loan !== null, 'a live loan puts an instalment on the payslip — the thing this product has '
    . 'never once done');
same('₹4,000.00', (string) $loan['amount'], 'the instalment is the EMI, in rupees');
same('₹45,800.00', (string) $row['net'],
    'and the net is EXACTLY ₹49,800.00 less ₹4,000.00 — the deduction moves take-home by the '
    . 'instalment and by nothing else');
same('₹45,800.00', (string) $normal['net'],
    "and the month's control total moves by the same ₹4,000.00, so the run still adds up");
same('instalment 1 of 12 against loan #17, ₹4,000.00 of ₹48,000.00, ₹44,000.00 outstanding after '
    . 'this one', (string) $loan['trace'],
    'and the line explains itself the way every other line on this payslip does: which instalment, '
    . 'of how many, against what, and what is left');
same([], (array) $row['concerns'], 'a loan that recovers normally is not a finding');

// ------------------------------- TWO MONTHS: the outstanding comes from the RUNS
$twoMonths = $fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '48000.0000', 'status' => 'active',
        'installments_paid' => 0],
]);
$october = $close($twoMonths, '2026-10');
$november = $close($twoMonths, '2026-11');

same('instalment 2 of 12 against loan #17, ₹4,000.00 of ₹48,000.00, ₹40,000.00 outstanding after '
    . 'this one', (string) ($linesOf($november, Recoveries::LOAN)['LOAN-17']['trace'] ?? ''),
    'the second month knows it is the second: ₹48,000 opening less the ₹4,000 the OCTOBER RUN shows '
    . 'recovered. Nothing decremented a column — the run is the ledger');

$stillStored = $twoMonths[2]->find('loans', '17');
same('48000.0000', (string) $stillStored['outstanding'],
    'and the ported `outstanding` column is untouched at its opening figure after two months of '
    . 'recovery. It is the opening position, not the truth: a column decremented in place makes a '
    . 'reversed instalment a balance that is right by luck and a history saying it never happened');

// ------------------------ THE LAST INSTALMENT IS THE REMAINDER, NOT THE STANDING EMI
//
// ₹47,500 at ₹4,000 a month. Eleven months take ₹44,000; the twelfth owes ₹3,500.
// A twelfth EMI of ₹4,000 would take ₹500 the employee never borrowed.
$eleven = [];

foreach (['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
    '2026-07', '2026-08', '2026-09'] as $earlier) {
    $eleven[] = ['period' => $earlier, 'code' => 'LOAN-17', 'minor' => 400000];
}

same(11, count($eleven), 'eleven months of ₹4,000 stand behind the figure below — ₹44,000 recovered');

$final = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '47500.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '47500.0000', 'status' => 'active',
        'installments_paid' => 0],
], [], $eleven), '2026-10');
$last = $linesOf($final, Recoveries::LOAN)['LOAN-17'] ?? null;

ok($last !== null, 'the twelfth month still recovers something');
same('₹3,500.00', (string) $last['amount'],
    'THE LAST INSTALMENT IS ₹3,500.00 — the balance — and not the standing ₹4,000.00 EMI. ₹47,500 '
    . 'less the ₹44,000 eleven closed runs show recovered. Taking the EMI would take ₹500 more than '
    . 'was ever borrowed, and the employee would be the one who has to notice');
same('₹46,300.00', (string) $final['rows']['SP-001']['net'],
    'so the net is ₹49,800.00 less ₹3,500.00 — not the ₹45,800.00 a twelfth full EMI would leave');
ok(str_contains((string) $last['trace'], 'instalment 12 of 12'),
    'the trace counts it as the twelfth: ' . (string) $last['trace']);
ok(str_contains((string) $last['trace'], '₹0.00 outstanding after this one'),
    'and says the loan is finished after it');
ok(str_contains((string) $last['trace'], 'would take ₹500.00 more than is owed'),
    'and names the ₹500.00 it did NOT take, so the reduced figure reads as a decision rather than '
    . 'as an arithmetic slip');

// -------------------------------- A SETTLED LOAN PRODUCES NOTHING, SILENTLY
$settled = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '48000.0000', 'status' => 'active',
        'installments_paid' => 0],
], [], array_merge($eleven, [
    ['period' => '2026-09-b', 'code' => 'LOAN-17', 'minor' => 400000],
])), '2026-10');

same([], $linesOf($settled, Recoveries::LOAN),
    'a fully recovered loan writes NO line — twelve ₹4,000 instalments against ₹48,000 leaves '
    . 'nothing owed');
same([], (array) $settled['rows']['SP-001']['concerns'],
    'and raises NO concern. A finished obligation is not a finding, and a paragraph on the payslip '
    . 'of everybody who has ever had a loan is how concerns stop being read');
same('₹49,800.00', (string) $settled['rows']['SP-001']['net'],
    'and the net is the untouched baseline, to the paisa');

// ------------------- A LOAN WITH NO INSTALMENT DECLARED IS A CONCERN, NOT A ZERO
$noEmi = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => null,
        'tenure' => null, 'outstanding' => '48000.0000', 'status' => 'active',
        'installments_paid' => 0],
]), '2026-10');
$concerns = implode(' ', array_map('strval', (array) $noEmi['rows']['SP-001']['concerns']));

same([], $linesOf($noEmi, Recoveries::LOAN),
    'a loan with no EMI declared writes no line — a made-up instalment is worse than none');
same('₹49,800.00', (string) $noEmi['rows']['SP-001']['net'],
    'and moves the net by nothing at all');
ok(str_contains($concerns, 'Loan #17'), 'but it SAYS so, naming the loan: ' . $concerns);
ok(str_contains($concerns, '₹48,000.00') && str_contains($concerns, 'no EMI'),
    'with the balance still outstanding and what is missing');
ok(str_contains($concerns, 'NOTHING was deducted'),
    'and that nothing was deducted — never a silent skip and never a zero, which on a payslip looks '
    . 'exactly like a settled loan');

// ------------------------------------ AN UNKNOWN STATUS IS A QUESTION NOBODY ANSWERED
$odd = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '48000.0000', 'status' => 'under review',
        'installments_paid' => 0],
]), '2026-10');

same([], $linesOf($odd, Recoveries::LOAN), "a status this build does not understand recovers nothing");
ok(str_contains(implode(' ', array_map('strval', (array) $odd['rows']['SP-001']['concerns'])),
    "the status 'under review', which this build does not understand"),
    'and states the status by name rather than guessing which side of it the loan is on');

// ------------------------------------------------- AN ADVANCE RECOVERS THE SAME WAY
$advance = $close($fixture([], [
    ['id' => '4', 'employee_id' => '1', 'amount' => '20000.0000', 'per_month' => '5000.0000',
        'balance' => '10000.0000', 'status' => 'active'],
]), '2026-10');
$advanceLine = $linesOf($advance, Recoveries::ADVANCE)['ADV-4'] ?? null;

ok($advanceLine !== null, 'an advance recovers too — the same ledger, a different noun');
same('₹5,000.00', (string) $advanceLine['amount'], 'at its declared monthly recovery');
same('instalment 1 of 2 against advance #4, ₹5,000.00 of ₹10,000.00, ₹5,000.00 outstanding after '
    . 'this one', (string) $advanceLine['trace'],
    'and counts against the BALANCE still owed, not the ₹20,000 originally advanced: two more months '
    . 'of ₹5,000, not four');
same('₹44,800.00', (string) $advance['rows']['SP-001']['net'], 'and the net is ₹49,800.00 less ₹5,000');

// ---------------------------------- the document that leaves the building carries it
$slip = Payslip::from($normal, '2026-10', 'SP-001');
ok($slip['ok'], 'the payslip issues: ' . $slip['why']);
same('₹45,800.00', (string) $slip['slip']['net'], 'with the recovered net, read from the stored run');

$onSlip = null;

foreach ((array) $slip['slip']['deductions'] as $deduction) {
    if ((string) $deduction['code'] === 'LOAN-17') {
        $onSlip = $deduction;
    }
}

ok($onSlip !== null, 'and the instalment among its DEDUCTIONS — an instalment is a deduction like '
    . 'any other');
ok(str_contains((string) ($onSlip['trace'] ?? ''), 'outstanding after this one'),
    'carrying the trace the run wrote, so the employee can see what is left without asking');

$slipWithConcern = Payslip::from($noEmi, '2026-10', 'SP-001');
ok(str_contains(implode(' ', array_map('strval', (array) $slipWithConcern['slip']['concerns'])),
    'Loan #17'),
    'and a concern reaches Payslip::from() the same way a statutory refusal does. A finding that '
    . 'stops at the register is one nobody outside the building ever sees');

// ------------------------------------------------------------------- NON-VACUITY
//
// Every assertion above would also pass against a recovery step that was never
// called, or one that returns an empty array for everything. These prove the
// outcomes genuinely differ, and that the differences are the ones claimed.
$nets = [
    'no loan at all' => (string) $bare['rows']['SP-001']['net'],
    'a normal ₹4,000 instalment' => (string) $normal['rows']['SP-001']['net'],
    'a final ₹3,500 instalment' => (string) $final['rows']['SP-001']['net'],
    'a ₹5,000 advance' => (string) $advance['rows']['SP-001']['net'],
];

same(4, count(array_unique($nets)),
    'four scenarios, four DIFFERENT nets (' . implode(', ', $nets) . '). If any two matched, the '
    . 'rupee figures above would be agreeing with each other rather than with the arithmetic');
ok($nets['a normal ₹4,000 instalment'] !== $nets['a final ₹3,500 instalment'],
    'and in particular the final instalment is NOT the standing one — the assertion the whole '
    . 'over-recovery guard exists for would be vacuous if it were');

$unwired = $close($fixture([
    ['id' => '17', 'employee_id' => '1', 'amount' => '48000.0000', 'emi' => '4000.0000',
        'tenure' => 12, 'outstanding' => '48000.0000', 'status' => 'active',
        'installments_paid' => 0],
], [], [], false), '2026-10');

same([], $linesOf($unwired, Recoveries::LOAN),
    'a register built WITHOUT a Recoveries recovers nothing from the identical loan — so the lines '
    . 'above are the recovery step speaking, not something the payroll engine was going to do anyway');
same('₹49,800.00', (string) $unwired['rows']['SP-001']['net'],
    'and pays the baseline net. This is the assertion that makes every net above load-bearing');
same([], (array) $unwired['rows']['SP-001']['concerns'],
    'and raises no concern, so the concerns above are the recovery step too');

// The eleven-month ledger is what the final instalment is derived FROM. If those
// runs were not being read, the twelfth would be a first, and it is not.
ok(str_contains((string) $last['trace'], 'of ₹47,500.00'),
    'the final instalment names the OPENING figure it was derived against (' . (string) $last['trace']
    . '), which is the stored column read as an opening position and never as a running balance');
