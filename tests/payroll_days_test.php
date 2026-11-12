<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\LatePolicy;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollInputs;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\PenaltyRules;
use SmartPRS\Surface\Store;

/**
 * WHAT A MONTH IS PAID FOR (owner, 7 Oct): holidays and weekly offs nobody
 * punched on are paid, paid leave is paid, LOP and absence are not, late logins
 * cost what the Late Login Policy says, and nothing outside the employment is
 * paid at all. Then incentives / deductions from their registers.
 *
 * October 2026 on the shipped calendar: 31 days, Sat+Sun off (9 days), Gandhi
 * Jayanti on Fri 2nd — 21 working days.
 */

group('Payroll days');

$root = dirname(__DIR__);
$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');
$penaltyRules = new PenaltyRules($root . '/config/attendance-penalty-rules.json');
$policy = new LeavePolicy($root . '/config/leave-policy.json');

$build = static function (array $latePolicy = []) use ($calendar, $rules, $penaltyRules, $policy): array {
    $store = (new Store(sys_get_temp_dir() . '/pd-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'E-P', 'name' => 'Present', 'doj' => '2026-01-01', 'status' => 'active']);
    $store->put('employees', ['id' => '2', 'emp_code' => 'E-N', 'name' => 'New joiner', 'doj' => '2026-10-15', 'status' => 'active']);
    $store->put('employees', ['id' => '3', 'emp_code' => 'E-X', 'name' => 'Leaver', 'doj' => '2025-01-01', 'exit_date' => '2026-10-09', 'status' => 'exited']);
    $store->put('employees', ['id' => '4', 'emp_code' => 'E-I', 'name' => 'Inactive', 'doj' => '2025-01-01', 'status' => 'inactive']);
    $latePolicy === [] || $store->put('late_policy', $latePolicy);
    $attendance = new Attendance($store, $rules, $calendar, null, $penaltyRules, new LatePolicy($store));

    for ($d = new DateTimeImmutable('2026-10-01'); $d->format('m') === '10'; $d = $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');

        if (!$calendar->isWorkingDay($date) || in_array($date, ['2026-10-06', '2026-10-07', '2026-10-08'], true)) {
            continue; // 6th paid leave, 7th LOP, 8th simply absent
        }

        $late = in_array($date, ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16'], true);
        $attendance->record('E-P', $date . ($late ? ' 09:30:00' : ' 09:00:00'), 'in', 'device');
        $attendance->record('E-P', $date . ' 18:45:00', 'out', 'device');
    }

    foreach ([['CL', '2026-10-06'], ['LOP', '2026-10-07']] as $i => [$type, $on]) {
        $store->put('leaves', ['employment_id' => 'E-P', 'state' => 'approved', 'leave_type' => $type,
            'from' => $on, 'to' => $on, 'requested_at' => "2026-09-2{$i} 10:00:00"]);
    }

    $leave = new Leave($store, $policy);

    return [$store, new PayrollPeriod($attendance, $leave, $policy, $calendar, $rules, null, $store)];
};

[, $period] = $build();
$m = $period->forPeriod('E-P', '2026-10-01', '2026-10-31');
same('10', $m['off_days'], '9 weekly offs + Gandhi Jayanti, none punched, are PAID');
same(1, $m['holidays'], 'and one of them is the holiday');
same('18', $m['worked_days'], '18 days worked');
same(1, $m['paid_leave'], 'one day of paid leave (CL) is paid');
same(1, $m['unpaid_leave'], 'one day of LOP is not');
same(1, $m['absent'], 'one plain absence');
same(3, count($m['penalties']), 'five late logins: the 3rd, 4th and 5th are cut under the shipped rule');
same(3, $m['penalty_halves'], 'half a day each');
same('27.5', $m['payable']->toDecimalString(), '18 + 10 + 1 − 1.5 = 27.5 payable of 31');
same(31, $m['period_days'], 'over 31 calendar days');

// THE LATE LOGIN POLICY OVERRIDES THE SHIPPED RULE: 2 free, then half a day per 2nd chargeable late.
[, $period] = $build(['employer_company_id' => '', 'scope' => 'Company', 'mode' => 'Simple', 'grace_min' => 10,
    'lates_before_cut' => 2, 'cut_mode' => 'Half_day_per_n', 'cut_n' => 2]);
$m = $period->forPeriod('E-P', '2026-10-01', '2026-10-31');
same(1, count($m['penalties']), 'Late Login Policy: 5 lates, 2 free, ½ day per 2 → one cut');
same('Late Login Policy', substr((string) $m['penalties'][0]['penalties'][0]['rule'], 0, 17), 'and the policy is named as the rule');
same('28.5', $m['payable']->toDecimalString(), 'so 29 − 0.5 = 28.5');

[, $period] = $build(['employer_company_id' => '', 'scope' => 'Company', 'mode' => 'Simple', 'grace_min' => 45,
    'lates_before_cut' => 0, 'cut_mode' => 'Full_day_per_late']);
same('29', $period->forPeriod('E-P', '2026-10-01', '2026-10-31')['payable']->toDecimalString(),
    'a 45-minute grace forgives 30-minute lates entirely');

[, $period] = $build();
same('5', $period->forPeriod('E-N', '2026-10-01', '2026-10-31')['payable']->toDecimalString(),
    'a joiner on the 15th with no punches is paid only the weekly offs from the 15th (17, 18, 24, 25, 31)');
$x = $period->forPeriod('E-X', '2026-10-01', '2026-10-31');
same('3', $x['payable']->toDecimalString(), 'a leaver on the 9th: only the holiday and weekend before it (2, 3, 4)');
same('2026-10-09', $x['exited_on'], 'and the exit date is the record\'s exit_date');
same('0', $period->forPeriod('E-I', '2026-10-01', '2026-10-31')['payable']->toDecimalString(),
    'an inactive record is not paid weekly offs it was not working around');

// LATE POLICY, AS A PURE FUNCTION.
$days = array_map(static fn (int $d): array => ['date' => sprintf('2026-10-%02d', $d), 'late' => 20, 'off' => false], range(1, 6));
same(4, count(LatePolicy::penalties(['grace_min' => 10, 'lates_before_cut' => 2, 'cut_mode' => 'Half_day_per_late'], $days)), 'Half_day_per_late: 6 lates, 2 free → 4 cuts');
same(2, count(LatePolicy::penalties(['grace_min' => 10, 'lates_before_cut' => 0, 'cut_mode' => 'One_day_per_n', 'cut_n' => 3], $days)), 'One_day_per_n (3): 6 lates → 2 days');
same(2, LatePolicy::penalties(['grace_min' => 10, 'lates_before_cut' => 0, 'cut_mode' => 'One_day_per_n', 'cut_n' => 3], $days)[0]['halves'], 'one day is two halves');
same(1, count(LatePolicy::penalties(['mode' => 'Tiered', 'grace_min' => 0, 'l1_min' => 15, 'l1_cut' => '0.5', 'lates_before_cut' => 5], $days)), 'Tiered: 6th late beyond 5 free costs the tier cut');
same([], LatePolicy::penalties(['mode' => 'Net_hours', 'grace_min' => 0], $days), 'Net_hours: hours decide, no late cut');
same(0, LatePolicy::penalties(['grace_min' => 0, 'cut_mode' => 'Full_day_per_late', 'mode' => 'Tiered', 'l1_min' => 1, 'l1_cut' => '0.25'], $days)[0]['halves'], 'a quarter-day cut is refused, never rounded');

// INCENTIVES AND OTHER EARNINGS / DEDUCTIONS FROM THEIR REGISTERS.
[$store] = $build();
$store->put('commissions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Approved', 'gross_amount' => '10000.00',
    'tds_rate' => '2', 'tds_amount' => '200.00', 'amount' => '9800.00', 'payout_method' => 'With salary (in the payslip)']);
$store->put('commissions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Approved', 'gross_amount' => '5000.00',
    'payout_method' => 'Separate payout (own dates - ledger)']);
$store->put('commissions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Pending', 'gross_amount' => '7000.00']);
$store->put('bonus_encashment', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Leave Encashment', 'amount' => '1500.00']);
$store->put('overtime', ['employee_id' => '1', 'ot_date' => '2026-10-20', 'status' => 'Approved', 'hours' => '2', 'rate' => '150.00', 'multiplier' => '2']);
$store->put('expenses', ['employee_id' => '1', 'date' => '2026-10-21', 'status' => 'Approved', 'type' => 'Fuel', 'amount' => '640.00']);
$store->put('deductions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Pending', 'type' => 'Pcc', 'amount' => '300.00']);
$store->put('deductions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Pending', 'type' => 'Late', 'amount' => '999.00']);
$store->put('deductions', ['employee_id' => '1', 'month' => '2026-09', 'status' => 'Pending', 'type' => 'Other', 'amount' => '111.00']);
// Salary Type "Commission" takes the entry's flat TDS (owner, 29 Oct 2026 — config/compensation.json).
$in = (new PayrollInputs($store, $root . '/config/payroll-inputs.json'))->forEmployment(['id' => '1', 'salary_type' => 'Commission'], '2026-10', '2026-10-01', '2026-10-31');
$byCode = [];
foreach ($in['lines'] as $l) { $byCode[$l['code']] = [$l['kind'], $l['minor']]; }
same(['earning', 1000000], $byCode['INCENTIVE'] ?? null, 'an approved with-salary incentive is paid GROSS');
same(['deduction', 20000], $byCode['TDS_INCENTIVE'] ?? null, 'and its TDS is deducted');
same(['earning', 150000], $byCode['LEAVE_ENCASHMENT'] ?? null, 'leave encashment from Bonus & Encashment');
same(['earning', 60000], $byCode['OT'] ?? null, 'overtime with no amount: 2 h × ₹150 × 2');
same(['earning', 64000], $byCode['REIMBURSEMENT'] ?? null, 'an approved expense is reimbursed');
same(['deduction', 30000], $byCode['PCC_DEDUCTION'] ?? null, 'a manual PCC deduction is deducted');
same(6, count($in['lines']), 'and nothing else — separate payout, pending, other months excluded');
ok(str_contains(implode(' ', $in['concerns']), 'Late'), 'a manual Late deduction is NOT charged twice, and is named');

// THE CLOSE CARRIES THEM: earnings, deductions, net and the run totals all move by exactly the lines.
$closeWith = static function (bool $inputs) use ($root, $calendar, $rules, $penaltyRules, $policy): array {
    $store = (new Store(sys_get_temp_dir() . '/pdr-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'E-R', 'name' => 'Run', 'doj' => '2026-01-01', 'status' => 'active', 'ctc' => '600000.00', 'salary_type' => 'Commission']);
    $store->put('commissions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'approved', 'gross_amount' => '10000.00', 'tds_amount' => '200.00']);
    $store->put('deductions', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Applied', 'type' => 'Other', 'amount' => '300.00']);
    $attendance = new Attendance($store, $rules, $calendar, null, $penaltyRules);
    $period = new PayrollPeriod($attendance, new Leave($store, $policy), $policy, $calendar, $rules, null, $store);
    $register = new SmartPRS\Surface\Register($store, $period, new SmartPRS\Surface\Structure($store, $root . '/config/salary-structure.json'),
        null, null, [], null, null, null, null, $inputs ? new PayrollInputs($store, $root . '/config/payroll-inputs.json') : null);
    $closed = $register->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
    ok($closed['ok'], '2026-10 closes: ' . $closed['why']);

    return $closed['run'];
};
$without = $closeWith(false);
$with = $closeWith(true);
same($without['rows']['E-R']['net_minor'] + 1000000 - 20000 - 30000, $with['rows']['E-R']['net_minor'],
    'net = net without them + ₹10,000 incentive − ₹200 TDS − ₹300 deduction');
$minor = static fn (string $f): int => (int) round((float) str_replace([',', '₹'], '', $f) * 100);
same($minor((string) $without['earnings']) + 1000000, $minor((string) $with['earnings']), 'run earnings rise by the incentive');
same($minor((string) $without['deductions']) + 50000, $minor((string) $with['deductions']), 'run deductions rise by TDS + deduction');
same($minor((string) $with['rows']['E-R']['earnings']) - $minor((string) $with['rows']['E-R']['deductions']), $with['rows']['E-R']['net_minor'],
    'and the payslip adds up: earnings − deductions = net');
same($with['rows']['E-R']['net_minor'], $with['net_minor'], 'and the run net is the row net');
same('10', $with['rows']['E-R']['days']['off'], 'the row stores the day breakdown for the payslip');

// BONUS BY BASIS (7 Oct): % of a component / days × per-day rate, from the employee's full-month salary; blank employee = everyone.
[$store] = $build();
$store->put('bonus_encashment', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Statutory Bonus', 'basis' => 'percent', 'basis_component' => 'BASIC', 'basis_value' => '8.3300']);
$store->put('bonus_encashment', ['employee_id' => '', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Leave Encashment', 'basis' => 'days', 'basis_component' => 'GROSS', 'basis_value' => '3']);
$store->put('bonus_encashment', ['employee_id' => '', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Festival Bonus', 'basis' => 'fixed', 'amount' => '2000.00']);
$store->put('bonus_encashment', ['employee_id' => '1', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Festival Bonus', 'basis' => 'percent', 'basis_component' => 'LTA', 'basis_value' => '10']);
$base = ['BASIC' => SmartPRS\Platform\Support\Money::of('25000.00'), 'GROSS' => SmartPRS\Platform\Support\Money::of('50000.00')];
$in = (new PayrollInputs($store, $root . '/config/payroll-inputs.json'))->forEmployment(['id' => '2'], '2026-10', '2026-10-01', '2026-10-31', $base);
same([['LEAVE_ENCASHMENT', 500000], ['BONUS:Festival Bonus', 200000]], array_map(static fn (array $l): array => [$l['code'], $l['minor']], $in['lines']),
    'ALL-employee rows reach another employee: 3 days × ₹50,000 ÷ 30 = ₹5,000 encashment, ₹2,000 fixed festival bonus');
$in = (new PayrollInputs($store, $root . '/config/payroll-inputs.json'))->forEmployment(['id' => '1'], '2026-10', '2026-10-01', '2026-10-31', $base);
same(208250, $in['lines'][0]['minor'], '8.33% of Basic ₹25,000 = ₹2,082.50');
ok(str_contains($in['lines'][0]['trace'], '8.33% of BASIC'), 'and the payslip line says how it was worked out');
ok(str_contains(implode(' ', $in['concerns']), "LTA is not on this employee's salary"), 'a component the employee does not have is a concern, not a guess');

// BULK UPLOAD SAMPLE ROUND TRIP (generic registers).
$bulkFields = [
    ['name' => 'employee_id', 'label' => 'Employee', 'type' => 'text', 'required' => false, 'readonly' => false, 'options' => [], 'lookup' => [['id' => '7', 'label' => 'AIPL-1002 — Asha']], 'min' => null],
    ['name' => 'basis', 'label' => 'Basis', 'type' => 'text', 'required' => true, 'readonly' => false, 'options' => ['fixed' => 'Fixed amount', 'percent' => '% of a salary component'], 'lookup' => [], 'min' => null],
    ['name' => 'month', 'label' => 'Pay in month', 'type' => 'month', 'required' => true, 'readonly' => false, 'options' => [], 'lookup' => [], 'min' => null],
    ['name' => 'decided_at', 'label' => 'Decided At', 'type' => 'datetime-local', 'required' => false, 'readonly' => true, 'options' => [], 'lookup' => [], 'min' => null],
];
$cols = SmartPRS\Surface\ModuleBulk::columns(['fields' => $bulkFields]);
same(3, count($cols), 'read-only fields are not in the sample');
$csv = SmartPRS\Surface\Sheet::toCsv(SmartPRS\Surface\ModuleBulk::sample($cols), 's.xlsx');
same('employee_id,basis,month', explode("\n", $csv)[1], 'row 2 carries the field codes');
$read = SmartPRS\Surface\ModuleBulk::read($cols, $csv . "\"AIPL-1002 — Asha\",% of a salary component,2026-10\nAIPL-1002,Fixed amount,01-11-2026\n");
same([['employee_id' => '7', 'basis' => 'percent', 'month' => '2026-10'], ['employee_id' => '7', 'basis' => 'fixed', 'month' => '2026-11']],
    array_column($read['rows'], 'input'), 'labels become the stored ids / keys; a date typed in a month cell is its month; the hint row is skipped');

// THE CLOSE WORKS THE BASIS OUT FROM THE EMPLOYEE'S OWN FULL-MONTH SALARY.
$bStore = (new Store(sys_get_temp_dir() . '/pdb-' . bin2hex(random_bytes(4))))->forTenant('1');
$bStore->put('employees', ['id' => '1', 'emp_code' => 'E-B', 'name' => 'Bonus', 'doj' => '2026-01-01', 'status' => 'active', 'ctc' => '600000.00']);
$bStore->put('bonus_encashment', ['employee_id' => '', 'month' => '2026-10', 'status' => 'Approved', 'type' => 'Festival Bonus', 'basis' => 'percent', 'basis_component' => 'GROSS', 'basis_value' => '10']);
$bPeriod = new PayrollPeriod(new Attendance($bStore, $rules, $calendar), new Leave($bStore, $policy), $policy, $calendar, $rules, null, $bStore);
$bRun = (new SmartPRS\Surface\Register($bStore, $bPeriod, new SmartPRS\Surface\Structure($bStore, $root . '/config/salary-structure.json'),
    null, null, [], null, null, null, null, new PayrollInputs($bStore, $root . '/config/payroll-inputs.json')))
    ->close('2026-10', '09:00', '18:00', 'maker@x')['run'];
$bLine = array_values(array_filter($bRun['rows']['E-B']['lines'], static fn (array $l): bool => str_starts_with($l['code'], 'BONUS')))[0] ?? [];
same(500000, $bLine['minor'] ?? null, '10% of GROSS ₹50,000 (₹6,00,000 a year) = ₹5,000 for an ALL-employee bonus row');
