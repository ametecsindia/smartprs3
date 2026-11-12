<?php

declare(strict_types=1);

/**
 * SEED A SMALL DEMO DATASET FOR TENANT 3.
 *
 *   php tools/seed-demo-data.php
 *
 * `tools/smoke.php` opens every route and fails on a PHP warning — but a screen
 * with no rows renders its "nothing yet" branch and never touches the code that
 * reads a row's columns. An empty table hides every broken column read behind it.
 * This fills the main tables so the smoke check exercises the rendering paths.
 *
 * Re-runnable: it looks for SP-001 and exits without writing if it is there.
 *
 * ponytail: no faker, no fixtures library, no CLI flags. Literal arrays; the day
 * a second tenant needs its own set, copy the file or pass the id in.
 */
require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';

use SmartPRS\Surface\Database;
use SmartPRS\Surface\SqlStore;

const TENANT = '3';
const MARKER = 'SP-001';

$pdo = Database::connect(__DIR__ . '/../.env');

if ($pdo === null) {
    fwrite(STDERR, "No database connection. This script seeds MySQL; check .env.\n");

    exit(1);
}

$store = (new SqlStore($pdo))->forTenant(TENANT);

foreach ($store->all('employees') as $employee) {
    if ((string) ($employee['emp_code'] ?? '') === MARKER) {
        echo "already seeded: employee " . MARKER . " exists for tenant " . TENANT . ".\n";

        exit(0);
    }
}

$counts = [];

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
$seed = static function (string $table, array $rows) use ($store, &$counts): array {
    $stored = [];

    foreach ($rows as $row) {
        $stored[] = $store->put($table, $row);
    }

    $counts[$table] = ($counts[$table] ?? 0) + count($stored);

    return $stored;
};

/** Dates are relative to today so "expires in 40 days" stays true next month. */
$day = static fn (int $offset): string => date('Y-m-d', strtotime("{$offset} days"));

$departments = $seed('departments', [
    ['name' => 'Collections'],
    ['name' => 'Legal & Recovery'],
    ['name' => 'Tele-calling'],
    ['name' => 'Human Resources'],
]);

$designations = $seed('designations', [
    ['name' => 'Field Collection Executive', 'department_id' => $departments[0]['id']],
    ['name' => 'Senior Collection Officer', 'department_id' => $departments[0]['id']],
    ['name' => 'Collections Team Leader', 'department_id' => $departments[0]['id']],
    ['name' => 'Recovery Manager', 'department_id' => $departments[1]['id']],
    ['name' => 'Tele-caller', 'department_id' => $departments[2]['id']],
    ['name' => 'HR Executive', 'department_id' => $departments[3]['id']],
]);

$branches = $seed('branches', [
    ['name' => 'Hyderabad - Banjara Hills', 'city' => 'Hyderabad', 'pincode' => '500034'],
    ['name' => 'Bengaluru - Koramangala', 'city' => 'Bengaluru', 'pincode' => '560095'],
    ['name' => 'Mumbai - Andheri East', 'city' => 'Mumbai', 'pincode' => '400069'],
]);

// The compliance screen has three states — expired, due soon, and NO DATE
// RECORDED — and the third one only renders if rows actually carry NULL. Every
// combination below is deliberate; do not "tidy up" the nulls.
$people = [
    ['Ramesh Naidu', 'male', 0, 1, 0, 'active', -820, 720000, -30, 40, 'expired', 'valid'],
    ['Sunitha Reddy', 'female', 0, 2, 0, 'active', -640, 860000, 95, 150, 'valid', 'valid'],
    ['Arun Kumar Yadav', 'male', 0, 0, 1, 'probation', -70, 420000, null, null, null, null],
    ['Priya Deshmukh', 'female', 1, 3, 2, 'active', -1010, 1180000, 210, -15, 'valid', 'expired'],
    ['Mohammed Irfan', 'male', 0, 0, 0, 'active', -430, 480000, 25, 55, 'valid', 'valid'],
    ['Lakshmi Prasanna', 'female', 2, 4, 1, 'active', -300, 360000, null, 300, null, 'valid'],
    ['Vikram Shetty', 'male', 0, 2, 1, 'notice', -900, 940000, -120, null, 'expired', null],
    ['Anjali Nair', 'female', 3, 5, 2, 'active', -560, 660000, 400, 380, 'valid', 'valid'],
    ['Suresh Babu Gollapudi', 'male', 0, 1, 0, 'exited', -1080, 540000, -200, -240, 'expired', 'expired'],
    ['Farhan Qureshi', 'male', 1, 3, 2, 'active', -210, 1050000, 50, 12, 'valid', 'valid'],
    ['Meenakshi Iyer', 'female', 2, 4, 1, 'probation', -45, 330000, null, null, null, null],
    ['Rajesh Chandrasekhar', 'male', 1, 3, 0, 'active', -1090, 1120000, 600, 45, 'valid', 'valid'],
];

$employees = [];
$n = 0;

foreach ($people as [$name, $gender, $dept, $desig, $branch, $status, $doj,
    $ctc, $dra, $pcc, $draStatus, $pccStatus]) {
    $code = sprintf('SP-%03d', ++$n);
    $slug = strtolower(str_replace(' ', '.', $name));

    $employees[] = [
        'emp_code' => $code,
        'name' => $name,
        'gender' => $gender,
        'mobile' => '9' . str_pad((string) (400000000 + $n * 1234567), 9, '0', STR_PAD_LEFT),
        'email' => $slug . '@smartprslocal',
        'department_id' => $departments[$dept]['id'],
        'designation_id' => $designations[$desig]['id'],
        'branch_id' => $branches[$branch]['id'],
        // The denormalised copies the list screens read without a join.
        'department' => $departments[$dept]['name'],
        'designation' => $designations[$desig]['name'],
        'branch' => $branches[$branch]['name'],
        'doj' => $day($doj),
        'ctc' => $ctc,
        'salary_type' => 'monthly',
        'status' => $status,
        'employment_type' => $status === 'probation' ? 'probation' : 'permanent',
        'dra_status' => $draStatus,
        'dra_expiry' => $dra === null ? null : $day($dra),
        'pcc_status' => $pccStatus,
        'pcc_expiry' => $pcc === null ? null : $day($pcc),
        // BANK AND STATUTORY IDENTIFIERS, seeded deliberately.
        //
        // `tools/exposure.php` works by reading the real secret values out of the
        // database and asserting they do not appear on a page served to somebody
        // who may not see them. With these columns empty it had nothing to hunt
        // for, so it reported five of its seven value classes as NOT ASSERTED —
        // honest, and blind. A check that cannot see is the thing this product is
        // written against, and the fix was the data, not the check.
        //
        // Obviously fake, and shaped like the real thing so the formats a screen
        // might mangle are exercised: PAN is five letters, four digits, a letter;
        // UAN and Aadhaar are 12 digits; IFSC is four letters, a zero, six.
        'bank_name' => ['HDFC Bank', 'ICICI Bank', 'State Bank of India',
            'Axis Bank'][$n % 4],
        'bank_acc' => (string) (50100000000000 + $n * 7919),
        'ifsc' => sprintf('%s0%06d', ['HDFC', 'ICIC', 'SBIN', 'UTIB'][$n % 4], 400000 + $n),
        'pan' => sprintf('ABCPD%04dK', 1000 + $n),
        'uan' => (string) (100200300000 + $n * 4441),
        'aadhaar' => (string) (900800700000 + $n * 3313),
    ];
}

$employees = $seed('employees', $employees);
$id = static fn (int $i): string => (string) $employees[$i]['id'];

// REPORTING LINES, so the manager role has something to be a manager OF.
//
// Written after the insert because a reporting manager is an employment id, and
// the ids do not exist until the rows do. Every employment except the first two
// reports to one of them, which gives two real teams and — deliberately — leaves
// the two leads reporting to nobody, because "who does the head of the company
// report to" is a question every org chart has to answer with silence.
//
// Without these, `Surface\Scope`'s direct_reports scope is correct and invisible:
// a manager's team is empty, so the manager reads exactly what a viewer reads and
// every screen agrees with every check while proving nothing. Seed data that
// cannot tell two behaviours apart is the file-store problem wearing a different
// hat.
$reportsTo = [];

foreach (array_slice($employees, 2) as $n => $employee) {
    $reportsTo[(string) $employee['id']] = $id($n % 2);
}

foreach ($reportsTo as $employment => $manager) {
    $pdo->prepare('UPDATE employees SET reporting_manager_id = ? WHERE id = ?')
        ->execute([$manager, $employment]);
}

printf("  reporting lines   %d employment(s) under %s and %s\n",
    count($reportsTo), $employees[0]['name'], $employees[1]['name']);

$leaveTypes = $seed('leave_types', [
    ['name' => 'Casual Leave', 'days_per_year' => 12, 'carry_forward' => 0, 'paid' => 1],
    ['name' => 'Sick Leave', 'days_per_year' => 8, 'carry_forward' => 0, 'paid' => 1],
    ['name' => 'Loss of Pay', 'days_per_year' => 0, 'carry_forward' => 0, 'paid' => 0],
    // EVERY type in config/leave-policy.json needs a row here: `leaves.type_id`
    // points at this table, and `Leave::request()` refuses a type the tenant has
    // no row for rather than storing a request with no type — which payroll
    // reads as not-in-the-paid-list and counts as loss of pay.
    ['name' => 'Earned leave', 'days_per_year' => 18, 'carry_forward' => 1, 'paid' => 1],
]);

$seed('leaves', [
    ['employee_id' => $id(0), 'type_id' => $leaveTypes[0]['id'], 'from_date' => $day(-12),
        'to_date' => $day(-11), 'days' => 2, 'status' => 'approved',
        'reason' => 'Family function in Warangal', 'decided_at' => $day(-14) . ' 10:20:00'],
    ['employee_id' => $id(4), 'type_id' => $leaveTypes[1]['id'], 'from_date' => $day(-4),
        'to_date' => $day(-4), 'days' => 1, 'status' => 'approved', 'reason' => 'Viral fever',
        'decided_at' => $day(-4) . ' 09:05:00'],
    ['employee_id' => $id(7), 'type_id' => $leaveTypes[0]['id'], 'from_date' => $day(3),
        'to_date' => $day(5), 'days' => 3, 'status' => 'pending', 'reason' => 'Wedding at Kochi'],
    ['employee_id' => $id(9), 'type_id' => $leaveTypes[2]['id'], 'from_date' => $day(-25),
        'to_date' => $day(-24), 'days' => 2, 'status' => 'rejected',
        'reason' => 'Personal', 'decided_at' => $day(-26) . ' 17:40:00'],
]);

$attendance = [];

foreach ([0, 1, 4, 7, 9] as $slot => $i) {
    foreach ([-1, -2, -3] as $back) {
        $late = ($slot + $back) % 3 === 0;
        $in = $day($back) . ($late ? ' 10:24:00' : ' 09:32:00');
        $out = $day($back) . ' 18:45:00';

        $attendance[] = [
            'employee_id' => $id($i),
            'date' => $day($back),
            'punch_in' => $in,
            'punch_out' => $out,
            'source' => $slot % 2 === 0 ? 'biometric' : 'mobile',
            'hours' => $late ? 8.35 : 9.2,
            'lates_mtd' => $late ? 2 : 0,
            'status' => $late ? 'late' : 'present',
            'geo_ok' => 1,
            'biometric_ok' => $slot % 2 === 0 ? 1 : 0,
        ];
    }
}

// One absence, so the screen has a row that is not a punch pair.
$attendance[] = ['employee_id' => $id(2), 'date' => $day(-2), 'status' => 'absent',
    'source' => 'manual', 'hours' => 0, 'lates_mtd' => 0];

$seed('attendance', $attendance);

$seed('loans', [
    ['employee_id' => $id(1), 'amount' => 150000, 'emi' => 12500, 'tenure' => 12,
        'outstanding' => 87500, 'installments_paid' => 5, 'status' => 'active',
        'approver_name' => 'Rajesh Chandrasekhar', 'decided_by' => 'Rajesh Chandrasekhar',
        'remarks' => 'Two-wheeler purchase', 'decided_at' => $day(-160) . ' 12:00:00'],
    ['employee_id' => $id(3), 'amount' => 300000, 'emi' => 25000, 'tenure' => 12,
        'outstanding' => 300000, 'installments_paid' => 0, 'status' => 'pending',
        'remarks' => 'Home renovation'],
    ['employee_id' => $id(11), 'amount' => 100000, 'emi' => 10000, 'tenure' => 10,
        'outstanding' => 0, 'installments_paid' => 10, 'status' => 'closed',
        'approver_name' => 'Priya Deshmukh', 'decided_by' => 'Priya Deshmukh',
        'decided_at' => $day(-540) . ' 11:15:00'],
]);

$seed('advances', [
    ['employee_id' => $id(0), 'amount' => 20000, 'per_month' => 5000, 'balance' => 10000,
        'status' => 'active', 'approver_name' => 'Priya Deshmukh', 'decided_by' => 'Priya Deshmukh',
        'remarks' => 'Festival advance', 'decided_at' => $day(-70) . ' 15:30:00'],
    ['employee_id' => $id(5), 'amount' => 15000, 'per_month' => 5000, 'balance' => 15000,
        'status' => 'pending', 'remarks' => 'Medical'],
    ['employee_id' => $id(9), 'amount' => 25000, 'per_month' => 12500, 'balance' => 0,
        'status' => 'recovered', 'approver_name' => 'Rajesh Chandrasekhar',
        'decided_by' => 'Rajesh Chandrasekhar', 'decided_at' => $day(-180) . ' 10:00:00'],
]);

$seed('expenses', [
    ['employee_id' => $id(0), 'type' => 'Field travel', 'amount' => 1840, 'date' => $day(-9),
        'status' => 'approved', 'approver_name' => 'Priya Deshmukh', 'decided_by' => 'Priya Deshmukh',
        'remarks' => 'Auto fare, Kukatpally allocation', 'decided_at' => $day(-8) . ' 16:00:00'],
    ['employee_id' => $id(4), 'type' => 'Mobile recharge', 'amount' => 599, 'date' => $day(-20),
        'status' => 'approved', 'approver_name' => 'Priya Deshmukh', 'decided_by' => 'Priya Deshmukh',
        'decided_at' => $day(-19) . ' 09:45:00'],
    ['employee_id' => $id(7), 'type' => 'Field travel', 'amount' => 2350, 'date' => $day(-3),
        'status' => 'pending', 'remarks' => 'Whitefield visits'],
    ['employee_id' => $id(11), 'type' => 'Client meeting', 'amount' => 4100, 'date' => $day(-33),
        'status' => 'rejected', 'decided_by' => 'Priya Deshmukh',
        'remarks' => 'No bill attached', 'decided_at' => $day(-31) . ' 18:10:00'],
]);

$seed('letters', [
    ['employee_id' => $id(6), 'letter_type' => 'Notice period acceptance',
        'issued_on' => $day(-15), 'status' => 'issued', 'issued_by' => 'Anjali Nair',
        'body' => 'This is to confirm acceptance of your resignation dated '
            . $day(-18) . ', with the last working day set per the notice period in your terms.'],
    ['employee_id' => $id(1), 'letter_type' => 'Appraisal letter',
        'issued_on' => $day(-90), 'status' => 'issued', 'issued_by' => 'Anjali Nair',
        'body' => 'Your annual compensation stands revised with effect from ' . $day(-90) . '.'],
]);

foreach ($counts as $table => $count) {
    printf("  seeded  %-14s %d row(s)\n", $table, $count);
}
