<?php

declare(strict_types=1);

/**
 * LOANS / SALARY ADVANCE RECOVERY — the owner's two recovery options (22 Sep).
 *
 *   Option 1: Tenure + EMI, derived, recovered every month (what already existed).
 *   Option 2: MANUAL — no EMI; nothing is recovered until somebody records an
 *             amount on Compensation & Claims → Loans / Salary Advance Recovery.
 *
 * A recovery entry is one of two things, and both must move the balance:
 *   - PAYROLL: "deduct ₹X from <month>'s payroll". It becomes that month's
 *     deduction line (ADV-<id> / LOAN-<id>) in place of the EMI, so it is on the
 *     payslip, in the net, and — once the month is closed — in the run the
 *     outstanding is derived from.
 *   - COLLECTED: "₹X was received in cash/bank in <month>". Not on any payslip
 *     (payroll did not take it), but the outstanding drops by it, so every later
 *     EMI and the last-instalment remainder are right.
 *
 * Asserted in rupees a person can check by hand.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\RecoveryEntries;
use SmartPRS\Surface\Recoveries;
use SmartPRS\Surface\Register;
use SmartPRS\Surface\SalaryAdvance;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Structure;

group('recovery entries');

$root = __DIR__ . '/..';

$made = static function (array $advances = [], array $loans = [], array $entries = [], array $runs = []) use ($root): array {
    $store = (new Store(sys_get_temp_dir() . '/prs3-recentries-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Kavita Sharma',
        'ctc' => '600000.00', 'doj' => '2020-01-01', 'status' => 'active']);
    $store->put('leaves', ['id' => 'L-1', 'employment_id' => 'SP-001', 'state' => 'approved',
        'leave_type' => 'EL', 'from' => '2026-10-01', 'to' => '2026-11-30', 'requested_at' => '2026-09-01T00:00:00+05:30']);

    foreach ($advances as $a) {
        $store->put('advances', $a);
    }

    foreach ($loans as $l) {
        $store->put('loans', $l);
    }

    foreach ($entries as $e) {
        $store->put(RecoveryEntries::COLLECTION, $e + ['status' => 'active', 'employee_id' => '1']);
    }

    foreach ($runs as $r) {
        $store->put('payroll_runs', $r);
    }

    $policy = new LeavePolicy($root . '/config/leave-policy.json');
    $calendar = new Calendar($root . '/config/calendar.json');
    $period = new PayrollPeriod(new Attendance($store), new Leave($store, $policy), $policy, $calendar);
    $register = new Register($store, $period, new Structure($store, $root . '/config/salary-structure.json'), null, new Recoveries($store));

    return [$register, $calendar, $store];
};

$close = static function (array $m, string $p): array {
    $c = $m[0]->close($p, $m[1]->shiftStart(), $m[1]->shiftEnd(), 'maker@x');
    ok($c['ok'], "{$p} closes: " . $c['why']);

    return $c['run'] ?? ['rows' => []];
};

$line = static fn (array $run, string $code): ?array => ((array) ($run['rows']['SP-001']['lines'] ?? []))
    ? (array_values(array_filter((array) $run['rows']['SP-001']['lines'], static fn (array $l): bool => ($l['code'] ?? '') === $code))[0] ?? null)
    : null;

$advance = ['id' => '4', 'employee_id' => '1', 'amount' => '10000.0000', 'balance' => '10000.0000',
    'per_month' => '5000.0000', 'tenure_months' => 2, 'emi_start' => '2026-10', 'status' => 'disbursed'];

$bareOct = (string) $close($made(), '2026-10')['rows']['SP-001']['net'];
$bn = $made();
$close($bn, '2026-10');
$bareNov = (string) $close($bn, '2026-11')['rows']['SP-001']['net'];
same('₹49,800.00', $bareOct, 'the October baseline net, with nothing to recover');

// ------------------------------------------------ PAYROLL ENTRY REPLACES THE EMI
$oct = $close($made([$advance], [], [
    ['id' => 'E1', 'kind' => 'advance', 'obligation_id' => '4', 'line_code' => 'ADV-4', 'mode' => 'payroll', 'period' => '2026-10', 'amount' => '2000.00'],
]), '2026-10');
same('₹2,000.00', (string) ($line($oct, 'ADV-4')['amount'] ?? ''),
    'an amount recorded against October\'s payroll is what October deducts — in place of the ₹5,000 EMI, not on top of it');
same('₹47,800.00', (string) $oct['rows']['SP-001']['net'], 'and the net is ₹49,800.00 less exactly ₹2,000');
ok(str_contains((string) ($line($oct, 'ADV-4')['trace'] ?? ''), 'recorded on Loans / Salary Advance Recovery'),
    'and the payslip line says where the figure came from');

// ------------------------------------------------ A CANCELLED ENTRY IS IGNORED
$octCancelled = $close($made([$advance], [], [
    ['id' => 'E1', 'kind' => 'advance', 'obligation_id' => '4', 'line_code' => 'ADV-4', 'mode' => 'payroll', 'period' => '2026-10', 'amount' => '2000.00', 'status' => 'cancelled'],
]), '2026-10');
same('₹5,000.00', (string) ($line($octCancelled, 'ADV-4')['amount'] ?? ''), 'a cancelled entry is history, not a deduction: the EMI stands');

// ------------------------------------------------ COLLECTED ENTRY LOWERS THE BALANCE
$twoMonths = $made([$advance], [], [
    ['id' => 'E2', 'kind' => 'advance', 'obligation_id' => '4', 'line_code' => 'ADV-4', 'mode' => 'collected', 'period' => '2026-10', 'amount' => '3000.00', 'collected_on' => '2026-10-05'],
]);
$o = $close($twoMonths, '2026-10');
same('₹5,000.00', (string) ($line($o, 'ADV-4')['amount'] ?? ''), 'October still takes the EMI — ₹7,000 is owed after the ₹3,000 collected');
ok(str_contains((string) ($line($o, 'ADV-4')['trace'] ?? ''), '₹2,000.00 outstanding after this one'),
    'and the trace counts the cash collected: ₹10,000 − ₹3,000 − ₹5,000 = ₹2,000 left');
$nv = $close($twoMonths, '2026-11');
same('₹2,000.00', (string) ($line($nv, 'ADV-4')['amount'] ?? ''), 'November takes only the ₹2,000 remainder, not a second ₹5,000');

$rec = new Recoveries($twoMonths[2]);
same(0, $rec->outstandingMinor('advance', $twoMonths[2]->find('advances', '4'), $twoMonths[2]->find('employees', '1')),
    'after both months the advance is fully recovered: ₹5,000 + ₹2,000 by payroll, ₹3,000 collected');
$comp = new Compensation($twoMonths[2], $root . '/config/compensation.json');
same(1000000, array_sum($comp->recoveredByPeriod('SP-001', 'ADV-4')), 'Recovered so far on the Salary Advance screen counts the collected cash too');

// ------------------------------------------------ MANUAL MODE: nothing unless recorded
$manualLoan = ['id' => '9', 'employee_id' => '1', 'amount' => '20000.0000', 'outstanding' => '20000.0000',
    'recovery_mode' => 'manual', 'status' => 'disbursed'];
$m = $made([], [$manualLoan], [
    ['id' => 'E3', 'kind' => 'loan', 'obligation_id' => '9', 'line_code' => 'LOAN-9', 'mode' => 'payroll', 'period' => '2026-11', 'amount' => '6000.00'],
]);
$mo = $close($m, '2026-10');
same(null, $line($mo, 'LOAN-9'), 'a manual-mode loan takes nothing in a month nobody recorded an amount for');
same([], (array) $mo['rows']['SP-001']['concerns'], 'and that is not a finding — it is what manual means');
$mn = $close($m, '2026-11');
same('₹6,000.00', (string) ($line($mn, 'LOAN-9')['amount'] ?? ''), 'November takes the ₹6,000 recorded against it');
ok((string) $mn['rows']['SP-001']['net'] !== $bareNov, 'and November\'s net moves by it');

// ------------------------------------------------ NEVER MORE THAN IS OWED
$over = $close($made([], [array_merge($manualLoan, ['outstanding' => '1500.0000'])], [
    ['id' => 'E4', 'kind' => 'loan', 'obligation_id' => '9', 'line_code' => 'LOAN-9', 'mode' => 'payroll', 'period' => '2026-10', 'amount' => '4000.00'],
]), '2026-10');
same('₹1,500.00', (string) ($line($over, 'LOAN-9')['amount'] ?? ''), 'an entry above the balance takes the balance, never more');

// ------------------------------------------------ A DELETED (DISCARDED) RUN RECOVERED NOTHING
$disc = $made([array_merge($advance, ['emi_start' => '2026-09'])], [], [], [
    ['id' => 'R-09', 'cycle_label' => '2026-09 · deleted 2026-09-21 10:00', 'status' => Register::DISCARDED,
        'rows' => ['SP-001' => ['lines' => [['code' => 'ADV-4', 'kind' => 'deduction', 'minor' => 500000]]]]],
]);
same(1000000, (new Recoveries($disc[2]))->outstandingMinor('advance', $disc[2]->find('advances', '4'), $disc[2]->find('employees', '1')),
    'a payroll deleted while testing gave nothing back and took nothing: its ₹5,000 is not "recovered"');

// ------------------------------------------------ WHAT MAY STILL BE RECORDED
$pend = $made([], [$manualLoan], [
    ['id' => 'E5', 'kind' => 'loan', 'obligation_id' => '9', 'line_code' => 'LOAN-9', 'mode' => 'payroll', 'period' => '2026-12', 'amount' => '6000.00'],
]);
$pr = new Recoveries($pend[2]);
same(1400000, $pr->availableMinor('loan', $pend[2]->find('loans', '9'), $pend[2]->find('employees', '1')),
    '₹20,000 owed less ₹6,000 already booked against a month not yet generated leaves ₹14,000 to record');

$entries = new RecoveryEntries($pend[2]);
$emp = $pend[2]->find('employees', '1');
$loanRow = $pend[2]->find('loans', '9');
ok(!$entries->shape(['mode' => 'payroll', 'period' => '2026-12', 'amount' => '0'], 'loan', $loanRow, $emp, 1400000, 'a@x')['ok'], 'zero is refused');
ok(!$entries->shape(['mode' => 'payroll', 'period' => '2026-12', 'amount' => '15000'], 'loan', $loanRow, $emp, 1400000, 'a@x')['ok'], 'more than can still be recovered is refused');
ok(!$entries->shape(['mode' => 'payroll', 'period' => 'Dec', 'amount' => '100'], 'loan', $loanRow, $emp, 1400000, 'a@x')['ok'], 'the month is YYYY-MM');
ok(!$entries->shape(['mode' => 'collected', 'amount' => '100'], 'loan', $loanRow, $emp, 1400000, 'a@x')['ok'], 'a collection needs its date');
ok(!$entries->shape(['mode' => 'other', 'amount' => '100', 'period' => '2026-12'], 'loan', $loanRow, $emp, 1400000, 'a@x')['ok'], 'only payroll or collected');
$good = $entries->shape(['mode' => 'collected', 'collected_on' => '2026-10-05', 'amount' => '2500.50', 'reference' => 'UPI 123'], 'loan', $loanRow, $emp, 1400000, 'a@x');
ok($good['ok'], 'a collection shapes: ' . $good['why']);
same('2026-10', $good['row']['period'] ?? null, 'a collection is counted in the month it was received');
same('LOAN-9', $good['row']['line_code'] ?? null, 'and keyed by the same code payroll uses');
same('2500.50', $good['row']['amount'] ?? null, 'the amount as typed, exact');

// ------------------------------------------------ SALARY ADVANCE / LOANS: OPTION 2 = MANUAL
$dir = sys_get_temp_dir() . '/prs3-recmode-' . bin2hex(random_bytes(4));
$s2 = (new Store($dir))->forTenant('1');
file_put_contents($dir . '/modules.json', json_encode(['modules' => []]));
$sa = new SalaryAdvance($s2, new Modules($s2, $dir . '/modules.json'), $root . '/config/salary-advance.json');
$tara = ['id' => '4', 'name' => 'Tara'];
$manual = $sa->shape(['amount_requested' => '15000', 'amount' => '12000', 'recovery_mode' => 'manual', 'tenure_months' => '4', 'emi_start' => '2026-10', 'remarks' => 'x'], $tara, '2026-09-22');
ok($manual['ok'], 'Option 2 (manual) shapes');
same('manual', $manual['row']['recovery_mode'] ?? null, 'and says it is manual');
same(null, $manual['row']['per_month'], 'with no EMI — the amount each month is whatever is recorded');
same(null, $manual['row']['tenure_months'], 'and no tenure');
$emi = $sa->shape(['amount_requested' => '15000', 'amount' => '12000', 'tenure_months' => '4', 'remarks' => 'x'], $tara, '2026-09-22');
same('emi', $emi['row']['recovery_mode'] ?? null, 'Option 1 (tenure + EMI) is the default');
same('3000.00', $emi['row']['per_month'], 'and derives its EMI as before');
