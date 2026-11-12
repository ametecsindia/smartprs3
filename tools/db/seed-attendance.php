<?php
/**
 * REALISTIC SAMPLE ATTENDANCE — punches, breaks, leaves, weekly offs — for
 * every employee, from 1 Jan 2026 to today.
 *
 *   php tools/db/seed-attendance.php
 *
 * Written through the SAME classes the application itself punches through
 * (Attendance::record(), Leave::request()/decide()) so a generated day settles
 * exactly like a real one — same rules, same statuses, same payroll. Nothing
 * is inserted straight into a table.
 *
 * IDEMPOTENT BY DAY: an employee-day that already has a punch, or already
 * falls inside any leave request (any state), is left alone. Running this
 * twice does not double a day's punches or stack two leave requests on it.
 *
 * TENANCY IS STRUCTURAL (see SqlStore's own doc-comment): every write needs a
 * store scoped with forTenant(), or `tenant_id` has nothing to write and the
 * database refuses the row. Employees are grouped by their own `tenant_id`
 * below and a fresh, properly scoped set of Attendance/Leave objects is built
 * per tenant found — one loop either way, whether there is one tenant or ten.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

spl_autoload_register(static function (string $class): void {
    foreach (['SmartPRS\\Platform\\' => '/../../src/Platform/', 'SmartPRS\\Surface\\' => '/../../surface/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            is_file($path) && require_once $path;

            return;
        }
    }
});

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\ChargeRules;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\DayRules;
use SmartPRS\Surface\EarnRules;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\LeavePolicy;
use SmartPRS\Surface\LeaveRules;
use SmartPRS\Surface\PenaltyRules;
use SmartPRS\Surface\RowStore;
use SmartPRS\Surface\SqlStore;

$root = __DIR__ . '/../..';
$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    fwrite(STDERR, "No database connection (.env). Start MySQL/Laragon and check .env, then retry.\n");

    exit(1);
}

$platform = new SqlStore($pdo);
$from = '2026-01-01';
$today = date('Y-m-d');

/** "HH:MM" + N minutes (can go negative), clamped to a sane day. */
$clock = static function (string $hhmm, int $deltaMinutes): string {
    $t = (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2) + $deltaMinutes;
    $t = max(0, min(23 * 60 + 59, $t));

    return sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
};

/** One tenant's worth of seeding, with its own scoped store and objects throughout. */
$seedTenant = static function (RowStore $store, array $employees) use ($root, $from, $today, $clock): array {
    $customisation = new Customisation($store);
    $calendar = new Calendar($root . '/config/calendar.json', $customisation);
    $dayRules = new DayRules($root . '/config/attendance-rules.json');
    $earnRules = new EarnRules($root . '/config/attendance-earn-rules.json');
    $penaltyRules = new PenaltyRules($root . '/config/attendance-penalty-rules.json');
    $attendance = new Attendance($store, $dayRules, $calendar, $earnRules, $penaltyRules);

    $policy = new LeavePolicy($root . '/config/leave-policy.json', $customisation);
    $leaveRules = new LeaveRules($root . '/config/leave-rules.json');
    $chargeRules = new ChargeRules($root . '/config/leave-charge-rules.json');
    $leave = new Leave($store, $policy, $leaveRules, $chargeRules, $calendar);

    [$shiftStart, $shiftEnd] = [$calendar->shiftStart(), $calendar->shiftEnd()];
    $totals = ['present' => 0, 'half_day' => 0, 'open_shift' => 0, 'leave' => 0, 'absent' => 0, 'skipped' => 0];

    foreach ($employees as $employee) {
        $code = Employment::code($employee);

        if ($code === '') {
            continue;
        }

        $joinedOn = Employment::joinedOn($employee);
        $start = $joinedOn !== '' && $joinedOn > $from ? $joinedOn : $from;

        if ($start > $today) {
            continue; // joins in the future — nothing to seed yet
        }

        // Existing punches and leave spans for this one employee, read once, so a
        // second run of this script skips every day it already touched.
        $punchedDates = [];

        foreach ($attendance->punchesFor($code) as $punch) {
            $punchedDates[substr((string) ($punch['at'] ?? ''), 0, 10)] = true;
        }

        $leaveDates = [];

        foreach ($leave->requestsFor($code) as $request) {
            for ($d = new DateTimeImmutable((string) $request['from']); $d <= new DateTimeImmutable((string) $request['to']); $d = $d->modify('+1 day')) {
                $leaveDates[$d->format('Y-m-d')] = true;
            }
        }

        $leaveTaken = ['CL' => 0, 'SL' => 0]; // this run's own count, on top of whatever the ledger already holds

        for ($d = new DateTimeImmutable($start); $d->format('Y-m-d') <= $today; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');

            if ($calendar->isWeeklyOff($date) || isset($calendar->holidays()[$date])) {
                continue; // the rule engine prices these from the calendar alone — nothing to seed
            }

            if (isset($punchedDates[$date]) || isset($leaveDates[$date])) {
                $totals['skipped']++;

                continue;
            }

            $roll = mt_rand(1, 1000);

            if ($roll <= 40 && ($leaveTaken['CL'] < 3 || $leaveTaken['SL'] < 3)) {
                // 4% of working days: a single day of CL or SL, whichever this
                // employee has taken less of so far this run.
                $type = $leaveTaken['CL'] <= $leaveTaken['SL'] ? 'CL' : 'SL';
                $reason = $type === 'CL'
                    ? ['Personal work', 'Family function', 'Travel'][mt_rand(0, 2)]
                    : ['Not feeling well', 'Fever', 'Medical appointment'][mt_rand(0, 2)];

                try {
                    $req = $leave->request($code, $type, $date, $date, $reason);
                    $leave->decide((string) $req['id'], true, 'system-seed', 'Approved — sample data seed');
                    $leaveTaken[$type]++;
                    $totals['leave']++;
                } catch (\Throwable) {
                    // gate:allow-swallow balance or policy refused it — leave the day unpunched (counts as absent, same as any other refusal would)
                    $totals['absent']++;
                }

                continue;
            }

            if ($roll <= 70) {
                // 3% of working days: a genuine absence. Realistic attendance has some.
                $totals['absent']++;

                continue;
            }

            // The remaining ~93%: present, with natural variance.
            $shape = mt_rand(1, 100);
            $in = $clock($shiftStart, mt_rand(-5, 20)); // mostly on time, sometimes a few minutes late

            if ($shape <= 3) {
                // Missed punch-out: HALF_DAY, flagged for review — a realistic device gap.
                $attendance->record($code, "{$date} {$in}:00", 'in', 'seed');
                $totals['open_shift']++;

                continue;
            }

            if ($shape <= 8) {
                // Half day: left after roughly the shift's half.
                $out = $clock($shiftEnd, -mt_rand(200, 260));
                $attendance->record($code, "{$date} {$in}:00", 'in', 'seed');
                $attendance->record($code, "{$date} {$out}:00", 'out', 'seed');
                $totals['half_day']++;

                continue;
            }

            // A full day, most of them with a recorded lunch break.
            $out = $clock($shiftEnd, mt_rand(-15, 30));

            if ($shape <= 78) {
                $lunchOut = $clock('13:00', mt_rand(-15, 30));
                $lunchIn = $clock($lunchOut, mt_rand(30, 45));
                $attendance->record($code, "{$date} {$in}:00", 'in', 'seed');
                $attendance->record($code, "{$date} {$lunchOut}:00", 'out', 'seed');
                $attendance->record($code, "{$date} {$lunchIn}:00", 'in', 'seed');
                $attendance->record($code, "{$date} {$out}:00", 'out', 'seed');
            } else {
                $attendance->record($code, "{$date} {$in}:00", 'in', 'seed');
                $attendance->record($code, "{$date} {$out}:00", 'out', 'seed');
            }

            $totals['present']++;
        }

        echo "  {$code}\n";
    }

    return $totals;
};

// GROUPED BY EACH EMPLOYEE'S OWN tenant_id, read unscoped once — the read every
// tenant-scoped table allows without a tenant on the store (SqlStore/Store both
// return everything, unfiltered, when none is set). A WRITE has no such
// fallback: `attendance_logs`.`tenant_id` has no default, so every write below
// goes through a store built with forTenant() for that row's own tenant.
$byTenant = [];

foreach ($platform->all('employees') as $employee) {
    $byTenant[(string) ($employee['tenant_id'] ?? '')][] = $employee;
}

if ($byTenant === []) {
    fwrite(STDERR, "No employees found. Nothing to seed.\n");

    exit(0);
}

$grand = ['present' => 0, 'half_day' => 0, 'open_shift' => 0, 'leave' => 0, 'absent' => 0, 'skipped' => 0];

foreach ($byTenant as $tenantId => $employees) {
    // PHP CASTS A NUMERIC ARRAY KEY TO int, even though it was written to the
    // array as a string above — "2" as a key becomes 2. Cast back before
    // forTenant(), which is typed string to match every column it is compared
    // against as text.
    $tenantId = (string) $tenantId;
    $store = $tenantId === '' ? $platform : $platform->forTenant($tenantId);
    printf("tenant %s: %d employee(s). Seeding %s to %s.\n\n", $tenantId === '' ? '(none)' : $tenantId,
        count($employees), $from, $today);
    $totals = $seedTenant($store, $employees);

    foreach ($totals as $key => $value) {
        $grand[$key] += $value;
    }

    echo "\n";
}

printf("Done. present=%d half_day=%d open_shift(missed punch-out)=%d leave=%d absent=%d already-had-data(skipped)=%d\n",
    $grand['present'], $grand['half_day'], $grand['open_shift'], $grand['leave'], $grand['absent'], $grand['skipped']);
