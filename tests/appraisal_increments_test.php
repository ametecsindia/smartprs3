<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\Increments;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\PenaltyRules;
use SmartPRS\Surface\Sheet;
use SmartPRS\Surface\Store;

/**
 * AN APPRAISAL IS WIRED INTO PAY (owner, 8 Oct). The employee record carries
 * the new CTC from the moment the rating is imported / applied; a payroll month
 * ending before the increment's effective date is still paid on the old one.
 */
group('Appraisal increments');

$e = ['id' => '1', 'ctc' => '660000.00'];
$raise = ['employee_id' => '1', 'status' => 'Effected', 'old_ctc' => '600000.00', 'new_ctc' => '660000.00', 'effective' => '2026-11-01'];
same('600000.00', Increments::ctcOn($e, [$raise], '2026-10-31'), 'a month ending before the effective date: the old CTC');
same('660000.00', Increments::ctcOn($e, [$raise], '2026-11-30'), 'from the effective date: the record (new CTC)');
same('660000.00', Increments::ctcOn($e, [['status' => 'Withheld'] + $raise], '2026-10-31'), 'a withheld (superseded) increment changes nothing');
same('660000.00', Increments::ctcOn($e, [['status' => 'Effected', 'new_ctc' => '660000.00', 'effective' => '2026-11-01']], '2026-10-31'),
    'a hand-typed row with no old CTC changes nothing');
$second = ['employee_id' => '1', 'status' => 'Effected', 'old_ctc' => '660000.00', 'new_ctc' => '700000.00', 'effective' => '2027-04-01'];
same('600000.00', Increments::ctcOn(['ctc' => '700000.00'], [$second, $raise], '2026-10-31'), 'two raises: the EARLIEST later one says what was in force');
same('660000.00', Increments::ctcOn(['ctc' => '700000.00'], [$second, $raise], '2026-12-31'), 'between them: the first raise');
same('600000.00', Increments::inForce([['id' => '1', 'ctc' => '660000.00'], ['id' => '2', 'ctc' => '1.00']], [$raise], '2026-10-31')[0]['ctc'], 'inForce rewrites only that employee');

// THE CLOSE: October is paid on ₹6,00,000 a year (₹50,000) although the record already says ₹6,60,000.
$root = dirname(__DIR__);
$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');
$policy = new LeavePolicy($root . '/config/leave-policy.json');
$closeWith = static function (?string $effective) use ($root, $calendar, $rules, $policy): array {
    $store = (new Store(sys_get_temp_dir() . '/pai-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('employees', ['id' => '1', 'emp_code' => 'E-A', 'name' => 'Appraised', 'doj' => '2026-01-01', 'status' => 'active', 'ctc' => '660000.00']);
    $effective === null || $store->put('increments', ['employee_id' => '1', 'status' => 'Effected', 'old_ctc' => '600000.00',
        'new_ctc' => '660000.00', 'pct' => '10', 'effective' => $effective]);
    $attendance = new Attendance($store, $rules, $calendar, null, new PenaltyRules($root . '/config/attendance-penalty-rules.json'));
    $period = new PayrollPeriod($attendance, new Leave($store, $policy), $policy, $calendar, $rules, null, $store);
    $register = new SmartPRS\Surface\Register($store, $period, new SmartPRS\Surface\Structure($store, $root . '/config/salary-structure.json'));
    $closed = $register->close('2026-10', $calendar->shiftStart(), $calendar->shiftEnd(), 'maker@x');
    ok($closed['ok'], '2026-10 closes: ' . $closed['why']);

    return $closed['run']['rows']['E-A'];
};
same('50000.00', $closeWith('2026-11-01')['gross'], 'increment effective 1 Nov: October gross is the OLD ₹50,000 a month');
same('55000.00', $closeWith('2026-10-01')['gross'], 'effective 1 Oct: October gross is the new ₹55,000');
same('55000.00', $closeWith(null)['gross'], 'no increment: the record as it stands');

// THE FORMATTED EXPORT opens as a real workbook: money as numbers, totals bold, header frozen.
$x = Sheet::report('T', 'S', ['Name', 'CTC'], [['A', '1234.50'], ['Total', '1234.50']], [1], [1], 'Appraisal');
$z = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($tmp, $x);
ok($z->open($tmp) === true, 'the report is a zip workbook');
$sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
ok(str_contains($sheet, '<c r="B5" s="3"><v>1234.50</v></c>'), 'an amount is a NUMBER cell with the #,##0.00 style');
ok(str_contains($sheet, '<c r="B6" s="8"><v>1234.50</v></c>'), 'the total row is bold');
ok(str_contains($sheet, 'state="frozen"') && str_contains($sheet, '<autoFilter ref="A4:B6"/>'), 'header frozen and filterable');
$z->close();
unlink($tmp);
