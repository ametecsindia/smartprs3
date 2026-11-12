<?php
/**
 * COMMISSION TDS BY SALARY TYPE (owner, 29 Oct 2026): Commission = flat % on the entry; Salary / Salary &
 * Commission / blank = no flat TDS (income-tax slabs in payroll). Gross from the scheme; net = gross − TDS.
 *   php tests/commission_tds_check.php
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

use SmartPRS\Surface\Compensation;

$rules = (array) json_decode((string) file_get_contents(__DIR__ . '/../config/compensation.json'), true)['commission'];
$commission = ['salary_type' => 'Commission'];
$salaryComm = ['salary_type' => 'Salary & Commission'];
$salary = ['salary_type' => 'Salary'];

ok(Compensation::flatTds($commission, $rules), 'Commission type takes flat TDS');
ok(!Compensation::flatTds($salaryComm, $rules), 'Salary & Commission does not');
ok(!Compensation::flatTds($salary, $rules), 'Salary does not');
ok(!Compensation::flatTds(['salary_type' => ''], $rules), 'blank Salary Type does not');
ok(!Compensation::flatTds(null, $rules), 'no employee does not');

$pct = ['name' => 'P', 'rate_type' => 'Percent', 'rate_value' => '2', 'tds_rate' => '5'];
$e = Compensation::entry(['base_amount' => '100000', 'gross_amount' => '1', 'tds_rate' => ''], $commission, $pct, $rules);
same('2000.00', $e['gross_amount'], 'percent scheme: 2% of 1,00,000, typed gross ignored');
same('5', $e['tds_rate'], 'blank TDS % takes the scheme rate');
same('100.00', $e['tds_amount'], 'TDS 5% of 2,000');
same('1900.00', $e['amount'], 'net = gross − TDS');

$e = Compensation::entry(['base_amount' => '100000', 'tds_rate' => '1'], $commission, $pct, $rules);
same('20.00', $e['tds_amount'], 'the % typed on the entry wins');

$e = Compensation::entry(['base_amount' => '100000', 'tds_rate' => '10'], $salaryComm, $pct, $rules);
same('0', $e['tds_rate'], 'Salary & Commission: TDS % forced to 0');
same('0.00', $e['tds_amount'], 'Salary & Commission: no TDS on the entry');
same('2000.00', $e['amount'], 'Salary & Commission: net = gross');

$e = Compensation::entry(['gross_amount' => '1234.56'], $commission, null, $rules);
same('1234.56', $e['gross_amount'], 'no scheme: the typed gross');
same($rules['default_tds_rate'], $e['tds_rate'], 'no scheme, no %: the default');
same('24.69', $e['tds_amount'], '2% of 1,234.56 = 24.6912 → 24.69');

$e = Compensation::entry(['gross_amount' => '700'], $commission, ['rate_type' => 'Fixed', 'rate_value' => '500', 'tds_rate' => ''], $rules);
same('500.00', $e['gross_amount'], 'fixed scheme pays its ₹ whatever is typed');

$e = Compensation::entry(['base_amount' => '150001', 'gross_amount' => '9'], $salary,
    ['rate_type' => 'Slab', 'slabs' => '[{"upto":"100000","rate":"1"},{"upto":"","rate":"2"}]'], $rules);
same('3000.02', $e['gross_amount'], 'slab: the band rate on the whole figure');

$e = Compensation::entry(['base_amount' => '90000', 'gross_amount' => '800'], $commission, ['rate_type' => 'Percent', 'rate_value' => '2', 'threshold' => '80'], $rules);
same('800.00', $e['gross_amount'], 'a target-gated scheme keeps the typed gross (the calculator applies the gate)');

// SCHEME ELIGIBILITY (owner, 29 Oct): minimum collection, pay only above it, scheme target, Salary Type.
$min = ['rate_type' => 'Percent', 'rate_value' => '10', 'min_collected' => '50000'];
same('0.00', Compensation::commission($min, '49999')['gross']->toDecimalString(), 'below the minimum: nothing');
same('5000.00', Compensation::commission($min, '50000')['gross']->toDecimalString(), 'at the minimum: 10% of the whole');
same('1000.00', Compensation::commission($min + ['pay_on' => 'excess'], '60000')['gross']->toDecimalString(), 'pay only above the minimum: 10% of 10,000');
$tg = ['rate_type' => 'Percent', 'rate_value' => '5', 'threshold' => '80', 'target_amount' => '100000'];
same('0.00', Compensation::commission($tg, '79999')['gross']->toDecimalString(), 'scheme target 1,00,000 at 80%: below the gate');
same('4000.00', Compensation::commission($tg, '80000')['gross']->toDecimalString(), 'scheme target used when none typed');
same('500.00', Compensation::commission($tg + ['pay_on' => 'excess'], '110000')['gross']->toDecimalString(), 'pay only above the target: 5% of 10,000');
same('0.00', Compensation::commission($tg, '90000', '200000')['gross']->toDecimalString(), 'a typed target wins over the scheme target');
ok(Compensation::eligible([], $salary) && Compensation::eligible(['eligible_salary_types' => 'All'], $salary), 'blank / All = everyone');
ok(Compensation::eligible(['eligible_salary_types' => 'Commission'], $commission) && !Compensation::eligible(['eligible_salary_types' => 'Commission'], $salaryComm), 'Commission only');
ok(Compensation::eligible(['eligible_salary_types' => 'Commission + Salary & Commission'], $salaryComm) && !Compensation::eligible(['eligible_salary_types' => 'Commission + Salary & Commission'], $salary), 'both commission types, not Salary');

// SEPARATE PAYOUT DATE PER ENTRY (owner, 29 Oct): payout day in the entry's month or the next; last day of a short month.
$sep = ['payout_method' => 'Separate payout (own dates - ledger)', 'payout_day' => '31'];
same('2026-02-28', Compensation::payoutDate($sep, '2026-01'), 'day 31, month after January = 28 Feb');
same('2026-10-31', Compensation::payoutDate($sep + ['payout_offset' => 'same'], '2026-10'), 'same month');
same('2027-01-10', Compensation::payoutDate(['payout_method' => 'Separate payout', 'payout_date' => '2026-10-10'], '2026-12'), 'an older scheme with only a date uses its day, every month');
same(null, Compensation::payoutDate(['payout_method' => 'With salary (in the payslip)', 'payout_day' => '5'], '2026-10'), 'with salary: no own date');

echo Assertions::$passed . ' passed, ' . count(Assertions::$failures) . " failed\n";
foreach (Assertions::$failures as $f) { echo "FAIL $f\n"; }
exit(Assertions::$failures === [] ? 0 : 1);
