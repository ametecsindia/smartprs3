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
 * PAYROLL ACCURACY (owner, 12 Oct): the day a payslip is built from must be the
 * day the employee actually had —
 *   • the ROSTERED shift on a rostered date, not the default one;
 *   • the shift's own grace (Working Shifts → Grace) for marking late;
 *   • a late-login rule reads minutes past the SHIFT START (its own grace is
 *     the only grace), never minutes past another grace already taken off;
 *   • a half day on leave is not a late mark and not a late cut;
 *   • paid / unpaid leave on the payslip in days, halves included;
 *   • a leave type scoped to another company never accrues.
 *
 * October 2026, shipped calendar (Sat + Sun off, Gandhi Jayanti Fri 2nd).
 */
group('Payroll accuracy');

$root = dirname(__DIR__);
$calendar = new Calendar($root . '/config/calendar.json');
$rules = new DayRules($root . '/config/attendance-rules.json');
$penaltyRules = new PenaltyRules($root . '/config/attendance-penalty-rules.json');
$policy = new LeavePolicy($root . '/config/leave-policy.json');

$build = static function (array $latePolicy) use ($calendar, $rules, $penaltyRules, $policy): array {
    $store = (new Store(sys_get_temp_dir() . '/pa-' . bin2hex(random_bytes(4))))->forTenant('1');
    $store->put('companies', ['id' => '1', 'name' => 'Beta Co']);
    $store->put('employees', ['id' => '1', 'emp_code' => 'E-S', 'name' => 'Shift', 'doj' => '2026-01-01', 'status' => 'active', 'employer_company_id' => '1']);
    $store->put('shifts', ['id' => '1', 'name' => 'General', 'employer_company_id' => '1', 'start_time' => '09:30', 'end_time' => '18:00',
        'grace_min' => '10', 'full_day_hours' => '7.0000', 'half_day_hours' => '4.0000', 'break_budget' => '65', 'status' => 'Active']);
    $store->put('shifts', ['id' => '2', 'name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '23:00',
        'grace_min' => '10', 'full_day_hours' => '7.0000', 'half_day_hours' => '4.0000', 'break_budget' => '65', 'status' => 'Active']);
    $store->put('rosters', ['id' => '1', 'employee_id' => '1', 'shift_id' => '2', 'from_date' => '2026-10-05', 'to_date' => '2026-10-05',
        'status' => 'active', 'created_at' => '2026-10-01 10:00:00']);
    $store->put('late_policy', $latePolicy + ['employer_company_id' => '', 'scope' => 'Company', 'mode' => 'Simple']);
    $attendance = new Attendance($store, $rules, $calendar, null, $penaltyRules, new LatePolicy($store));

    $punch = static function (string $date, string $in, string $out) use ($attendance): void {
        $attendance->record('E-S', "{$date} {$in}:00", 'in', 'device');
        $attendance->record('E-S', "{$date} {$out}:00", 'out', 'device');
    };
    $punch('2026-10-05', '14:02', '23:05');   // ROSTERED onto the afternoon shift: a full, on-time day
    $punch('2026-10-06', '09:38', '18:10');   // 8 min past 09:30: inside the shift's 10-minute grace
    $punch('2026-10-07', '09:42', '18:30');   // 12 min: late
    $punch('2026-10-08', '14:05', '18:05');   // first half on CL, in at mid-shift: NOT a late mark
    $punch('2026-10-09', '09:30', '13:50');   // second half LOP
    $punch('2026-10-12', '09:45', '18:30');   // 15 min: late
    $punch('2026-10-13', '09:50', '18:30');   // 20 min: late

    $store->put('leaves', ['employment_id' => 'E-S', 'state' => 'approved', 'leave_type' => 'CL', 'from' => '2026-10-08', 'to' => '2026-10-08',
        'days' => '0.5', 'reason' => '[First half] Bank work', 'requested_at' => '2026-10-01 10:00:00']);
    $store->put('leaves', ['employment_id' => 'E-S', 'state' => 'approved', 'leave_type' => 'LOP', 'from' => '2026-10-09', 'to' => '2026-10-09',
        'days' => '0.5', 'reason' => '[Second half] Personal', 'requested_at' => '2026-10-01 10:01:00']);

    return [$store, $attendance, new PayrollPeriod($attendance, new Leave($store, $policy), $policy, $calendar, $rules, null, $store)];
};

// 2 free lates, then a full day per late.
[, $attendance, $period] = $build(['grace_min' => 10, 'lates_before_cut' => 2, 'cut_mode' => 'Full_day_per_late']);
$days = [];
foreach ($attendance->settledDays('E-S', '09:30', '18:00', '2026-10-01', '2026-10-31') as $d) {
    $days[$d['date']] = $d;
}

same('PRESENT', $days['2026-10-05']['classification']['status'] ?? null, 'a rostered afternoon day is settled on the afternoon shift: PRESENT');
same(0, $days['2026-10-05']['settlement']->lateMinutes ?? null, 'and it is not 272 minutes late against the default shift');
same(0, $days['2026-10-06']['settlement']->lateMinutes ?? null, '8 minutes late is inside the shift\'s own 10-minute grace');
same(12, $days['2026-10-07']['late_raw'] ?? null, 'the minutes past the shift start are reported as they are');

$m = $period->forPeriod('E-S', '2026-10-01', '2026-10-31', '09:30', '18:00');
same(0, $m['needing_attention'], 'no day needs regularising — the roster day is not an orphan punch');
same(['2026-10-13'], array_column($m['penalties'], 'date'),
    'lates on 7th, 12th, 13th (the half-day-leave 8th is not a late mark): 2 free, the 13th is cut');
same('0.5', $m['paid_leave_days'], 'half a day of CL is half a day of paid leave on the payslip');
same('0.5', $m['unpaid_leave_days'], 'and half a day of LOP is half a day of LOP');
same([], $m['overlaps'], 'half a day worked + half a day of CL is one day, not an overlap to resolve');

// THE LATE LOGIN POLICY'S GRACE IS FROM THE SHIFT START, not on top of another grace.
[, , $period] = $build(['grace_min' => 10, 'lates_before_cut' => 0, 'cut_mode' => 'Half_day_per_late']);
same(['2026-10-07', '2026-10-12', '2026-10-13'], array_column($period->forPeriod('E-S', '2026-10-01', '2026-10-31', '09:30', '18:00')['penalties'], 'date'),
    'grace 10: 12, 15 and 20 minutes late are all cut; 8 minutes is not');

// A LEAVE TYPE SCOPED TO ANOTHER COMPANY NEVER ACCRUES.
$policyFile = sys_get_temp_dir() . '/pa-policy-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($policyFile, json_encode(['types' => [
    ['code' => 'EL_A', 'name' => 'Alpha EL', 'paid' => true, 'opening_days' => '0', 'accrual_days_per_month' => '1.5', 'maximum_days' => '45', 'scope' => ['company' => 'Alpha Co']],
    ['code' => 'EL_B', 'name' => 'Beta EL', 'paid' => true, 'opening_days' => '0', 'accrual_days_per_month' => '1', 'maximum_days' => '30', 'scope' => ['company' => 'Beta Co']],
]]));
[$store] = $build(['grace_min' => 10]);
$scoped = new Leave($store, new LeavePolicy($policyFile));
$posted = $scoped->accrueThrough('E-S', '2026-01-01', '2026-03-15');
same(3, count($posted), 'three months of Beta EL, and no Alpha EL for a Beta Co employee');
same('0', $scoped->balance('E-S', 'EL_A', new DateTimeImmutable('2026-03-31'))->toDecimalString(), 'Alpha EL balance stays 0');
same('3', $scoped->balance('E-S', 'EL_B', new DateTimeImmutable('2026-03-31'))->toDecimalString(), 'Beta EL is 3');
@unlink($policyFile);

// A HALF DAY AGAINST A BALANCE OF EXACTLY HALF A DAY IS ALLOWED (the rules count in halves for a half day).
$hdStore = (new Store(sys_get_temp_dir() . '/pa-hd-' . bin2hex(random_bytes(4))))->forTenant('1');
$hdStore->put('leave_types', ['id' => '5', 'name' => 'Compensatory off']);
$hdStore->put('employees', ['id' => '1', 'emp_code' => 'E-H', 'name' => 'Half', 'doj' => '2026-01-01', 'status' => 'active', 'gender' => 'Male']);
$hdLeave = new Leave($hdStore, $policy, new SmartPRS\Surface\LeaveRules($root . '/config/leave-rules.json'),
    new SmartPRS\Surface\ChargeRules($root . '/config/leave-charge-rules.json'), $calendar, null);
$hdLeave->creditEarned('E-H', 'CO', '0.5', 'Half a comp-off for a test', 'test:co-half', '2026-10-01');
$hdOk = true;
try {
    $hdReq = $hdLeave->request('E-H', 'CO', '2026-10-14', '2026-10-14', 'Half day', 'first');
} catch (\InvalidArgumentException $why) {
    $hdOk = false;
    $hdReq = ['days' => $why->getMessage()];
}
ok($hdOk, 'a first-half CO against 0.5 available is accepted: ' . (string) $hdReq['days']);
throws(\InvalidArgumentException::class, static fn () => $hdLeave->request('E-H', 'CO', '2026-10-15', '2026-10-15', 'Full day', null),
    'but a FULL day against the same 0.5 (now pending) is still refused');
