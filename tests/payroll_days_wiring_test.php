<?php

declare(strict_types=1);

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\LatePolicy;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\PayrollPeriod;
use SmartPRS\Surface\PenaltyRules;
use SmartPRS\Surface\Store;

/**
 * PAID DAYS AND DEDUCTIONS, WIRED (owner, 8 Oct): paid leave, weekly offs and
 * holidays are paid; absence, unpaid leave and the late-login cut are deducted —
 * each exactly once, and per employee's own calendar.
 */

group('Payroll days · wiring');

$root = dirname(__DIR__);
$rules = new DayRules($root . '/config/attendance-rules.json');
$penaltyRules = new PenaltyRules($root . '/config/attendance-penalty-rules.json');
$policy = new LeavePolicy($root . '/config/leave-policy.json');

$calendarFile = static function (string $basis) use ($root): string {
    $c = json_decode((string) file_get_contents($root . '/config/calendar.json'), true);
    $c['proration_basis'] = $basis;
    // A holiday for ANOTHER company only — not a holiday for our employee.
    $c['holidays'][] = ['on' => '2026-10-20', 'name' => 'Other Co Day', 'type' => 'Other',
        'scope' => ['company' => ['Other Co'], 'branch' => ['ANY'], 'role' => ['ANY'], 'designation' => ['ANY']]];
    $f = sys_get_temp_dir() . '/pdw-cal-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($f, json_encode($c));

    return $f;
};

$build = static function (string $basis) use ($rules, $penaltyRules, $policy, $calendarFile): PayrollPeriod {
    $calendar = new Calendar($calendarFile($basis));
    $store = (new Store(sys_get_temp_dir() . '/pdw-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('companies', ['id' => 'C1', 'name' => 'One']);
    $store->put('employees', ['id' => '1', 'emp_code' => 'E-A', 'name' => 'A', 'doj' => '2026-01-01',
        'status' => 'active', 'employer_company_id' => 'C1']);
    // Every late login costs a full day under this policy.
    $store->put('late_policy', ['employer_company_id' => '', 'scope' => 'Company', 'mode' => 'Simple',
        'grace_min' => 5, 'lates_before_cut' => 0, 'cut_mode' => 'Full_day_per_late']);
    $attendance = new Attendance($store, $rules, $calendar, null, $penaltyRules, new LatePolicy($store));

    // 21st: half a day worked. 22nd: a full day, but 30 minutes late.
    $attendance->record('E-A', '2026-10-21 09:00:00', 'in', 'device');
    $attendance->record('E-A', '2026-10-21 13:45:00', 'out', 'device');
    $attendance->record('E-A', '2026-10-22 09:30:00', 'in', 'device');
    $attendance->record('E-A', '2026-10-22 18:45:00', 'out', 'device');

    foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $i => $on) {
        $store->put('leaves', ['employment_id' => 'E-A', 'state' => 'approved', 'leave_type' => 'CL',
            'from' => $on, 'to' => $on, 'requested_at' => "2026-09-1{$i} 10:00:00"]);
    }

    return new PayrollPeriod($attendance, new Leave($store, $policy), $policy, $calendar, $rules, null, $store);
};

$m = $build('calendar_days')->forPeriod('E-A', '2026-10-01', '2026-10-31');
same('10', $m['off_days'], 'the 9 weekly offs + Gandhi Jayanti are paid; another company\'s holiday is not ours');
same(0, $m['penalty_halves'], 'a late login on a day of approved paid leave is not cut');
same([], $m['penalties'], 'and is not listed as a late mark');
// 10 off + CL 20th (1) + 21st half worked + half leave (1) + 22nd (1) = 13
same('13', $m['payable']->toDecimalString(), 'paid leave on another company\'s holiday is paid; a half day + paid leave is one full day');
same(0, $m['unpaid_leave'], 'no LOP');

$w = $build('working_days')->forPeriod('E-A', '2026-10-01', '2026-10-31');
same('0', $w['off_days'], 'over working days, weekly offs and holidays are not paid on top (they are not in the denominator)');
same('3', $w['payable']->toDecimalString(), 'so the three leave days are the whole payable figure');

// A late in-punch with no out-punch on a working day is HALF_DAY: the cut never exceeds the day.
$calendar = new Calendar($calendarFile('calendar_days'));
$store = (new Store(sys_get_temp_dir() . '/pdw-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'E-B', 'name' => 'B', 'doj' => '2026-01-01', 'status' => 'active']);
$store->put('late_policy', ['employer_company_id' => '', 'scope' => 'Company', 'mode' => 'Simple',
    'grace_min' => 5, 'lates_before_cut' => 0, 'cut_mode' => 'Full_day_per_late']);
$attendance = new Attendance($store, $rules, $calendar, null, $penaltyRules, new LatePolicy($store));
$attendance->record('E-B', '2026-10-05 10:00:00', 'in', 'device');
$b = (new PayrollPeriod($attendance, new Leave($store, $policy), $policy, $calendar, $rules, null, $store))
    ->forPeriod('E-B', '2026-10-05', '2026-10-05');
same(1, $b['penalty_halves'], 'a full-day late cut on a half day takes the half day, not more');
same('0', $b['payable']->toDecimalString(), 'leaving nothing payable for it');
