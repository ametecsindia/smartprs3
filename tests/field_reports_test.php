<?php

declare(strict_types=1);

/**
 * FIELD FORCE expiry bands and the REPORTS & ANALYTICS list reports — the
 * computations, over arrays, without a database.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\FieldReports;

$cfg = FieldReports::config(__DIR__ . '/../config/field-reports.json');
$window = (int) $cfg['expiry_window_days'];

group('expiry bands');
ok($window > 0, 'the window is a positive number of days in config');
same(FieldReports::NONE, FieldReports::band('', '2026-09-09', $window), 'no date: none');
same(FieldReports::NONE, FieldReports::band('soon', '2026-09-09', $window), 'garbage: none, not expired');
same(FieldReports::EXPIRED, FieldReports::band('2026-09-09', '2026-09-09', $window), 'expires today: expired today');
same(FieldReports::EXPIRED, FieldReports::band('2025-01-01', '2026-09-09', $window), 'long past: expired');
same(FieldReports::DUE, FieldReports::band('2026-09-10', '2026-09-09', $window), 'tomorrow: due');
$edge = (new DateTimeImmutable('2026-09-09'))->modify("+{$window} days")->format('Y-m-d');
same(FieldReports::DUE, FieldReports::band($edge, '2026-09-09', $window), 'exactly the window: still due');
$past = (new DateTimeImmutable('2026-09-09'))->modify('+' . ($window + 1) . ' days')->format('Y-m-d');
same(FieldReports::VALID, FieldReports::band($past, '2026-09-09', $window), 'one day past the window: valid');
same(-3, FieldReports::daysLeft('2026-09-06', '2026-09-09'), 'days left is negative when passed');
same(21, FieldReports::daysLeft('2026-09-30 00:00:00', '2026-09-09'), 'a datetime column is read by its date');

group('expiring first');
$rows = [
    ['who' => 'valid', 'to' => $past],
    ['who' => 'expired-old', 'to' => '2026-01-01'],
    ['who' => 'due-late', 'to' => $edge],
    ['who' => 'none', 'to' => ''],
    ['who' => 'expired-recent', 'to' => '2026-09-08'],
    ['who' => 'due-soon', 'to' => '2026-09-10'],
];
$ordered = FieldReports::expiringFirst($rows, 'to', '2026-09-09', $window);
same(['due-soon', 'due-late', 'expired-recent', 'expired-old', 'valid', 'none'], array_column($ordered, 'who'),
    'due (soonest first), then expired (most recent first), then valid, then undated');
same(['expired' => 2, 'due' => 2, 'valid' => 1, 'none' => 1], FieldReports::bandCounts($ordered), 'counts per band, all bands present');
same([], FieldReports::expiringFirst([], 'to', '2026-09-09', $window), 'nothing to order is nothing');

group('headcount');
$people = [
    ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'branch' => 'Vizag', 'dob' => '1990-09-15', 'pf_applicable' => '1', 'esi_applicable' => 'yes', 'uan' => 'U1'],
    ['id' => '2', 'emp_code' => 'B1', 'name' => 'B', 'branch' => 'Vizag', 'dob' => '1985-09-02', 'pf_applicable' => '0', 'esi_applicable' => 'no'],
    ['id' => '3', 'emp_code' => 'C1', 'name' => 'C', 'branch' => '', 'dob' => '1985-10-02', 'pf_applicable' => 'YES', 'esi_applicable' => ''],
];
$byName = static fn (array $e, string $g): string => (string) ($e[$g] ?? '');
$hc = FieldReports::headcount($people, 'branch', $byName);
same([['Vizag', 2], ['(not set)', 1]], $hc['rows'], 'grouped by name, biggest first, the blank counted and named');
same(['Total', 3], $hc['totals'], 'the total is the people, as an int');
same(['Total', 0], FieldReports::headcount([], 'branch', $byName)['totals'], 'nobody: zero, not an error');

group('attendance summary');
$att = [
    ['employee_id' => '1', 'date' => '2026-09-01', 'status' => 'present', 'hours' => '8.5000'],
    ['employee_id' => '1', 'date' => '2026-09-02', 'status' => 'late', 'hours' => '7.2500'],
    ['employee_id' => '1', 'date' => '2026-08-31', 'status' => 'present', 'hours' => '9.0000'],
    ['employee_id' => '2', 'date' => '2026-09-03', 'status' => 'absent', 'hours' => ''],
    ['employee_id' => '9', 'date' => '2026-09-03', 'status' => 'present', 'hours' => '8'],
];
$as = FieldReports::attendanceSummary($people, $att, '2026-09');
same(['Code', 'Name', 'Absent', 'Late', 'Present', 'Hours'], $as['columns'], 'status columns come from the month\'s rows, sorted');
same(['A1', 'A', 0, 1, 1, '15.75'], $as['rows'][0], 'A: one present, one late, hours summed exactly; August ignored');
same(['B1', 'B', 1, 0, 0, '0.00'], $as['rows'][1], 'B: one absent, blank hours read as zero');
same(['C1', 'C', 0, 0, 0, '0.00'], $as['rows'][2], 'C: no rows is a row of zeros, not a missing person');
same(['Total', '3 employee(s)', 1, 1, 1, '15.75'], $as['totals'], 'totals: employee 9 (outside the filter) is not counted');

group('leave summary');
$leaves = [
    ['employee_id' => '1', 'type_id' => '5', 'from_date' => '2026-09-05', 'days' => '1.5000', 'status' => 'approved'],
    ['employee_id' => '1', 'type_id' => '5', 'from_date' => '2026-09-20', 'days' => '2', 'status' => 'Approved'],
    ['employee_id' => '1', 'type_id' => '6', 'from_date' => '2026-09-20', 'days' => '1', 'status' => 'pending'],
    ['employee_id' => '2', 'type_id' => '7', 'from_date' => '2026-09-01', 'days' => '0.5', 'status' => 'approved'],
    ['employee_id' => '2', 'type_id' => '5', 'from_date' => '2026-10-01', 'days' => '3', 'status' => 'approved'],
];
$ls = FieldReports::leaveSummary($people, $leaves, ['5' => 'Casual Leave', '6' => 'Sick Leave'], '2026-09', 'approved');
same(['Code', 'Name', 'Casual Leave', 'type 7', 'Days'], $ls['columns'], 'type columns from the approved rows; an unnamed type is named by id, not dropped');
same(['A1', 'A', '3.50', '0.00', '3.50'], $ls['rows'][0], 'A: 1.5 + 2 casual, pending sick not counted, exact');
same(['B1', 'B', '0.00', '0.50', '0.50'], $ls['rows'][1], 'B: half a day; October not counted');
same(['Total', '3 employee(s)', '3.50', '0.50', '4.00'], $ls['totals'], 'totals exact to the fraction');

group('salary register');
$runs = [
    ['period' => '2026-08', 'rows' => ['A1' => ['lines' => [['kind' => 'earning', 'minor' => 100]], 'net_minor' => 100]]],
    ['period' => '2026-09', 'rows' => [
        'A1' => ['lines' => [
            ['kind' => 'earning', 'minor' => 5000000], ['kind' => 'earning', 'minor' => 1234567],
            ['kind' => 'deduction', 'minor' => 180000], ['kind' => 'recovery', 'minor' => 50000],
            ['kind' => 'employer_contribution', 'minor' => 180000],
        ], 'net_minor' => 6004567],
        'B1' => ['lines' => [['kind' => 'earning', 'minor' => 2000000]], 'net_minor' => 2000000],
    ]],
];
$sr = FieldReports::salaryRegister($people, $runs, '2026-09', ['earning'], ['deduction', 'recovery']);
same('2026-09', $sr['run']['period'], 'the run of the month, not the newest');
[$code, , $gross, $ded, $net] = $sr['rows'][0];
ok($gross instanceof Money && $gross->equals(Money::ofMinor(6234567)), 'gross is the earning lines summed in paise: 62,345.67');
ok($ded->equals(Money::ofMinor(230000)), 'deductions = deduction + recovery lines; the employer contribution is neither');
ok($net->equals(Money::ofMinor(6004567)), 'net is the run\'s own net_minor');
same(['C1', 'C', '—', '—', '—'], $sr['rows'][2], 'an employee the run has no row for is dashes, never zero pay');
ok($sr['totals'][2]->equals(Money::ofMinor(8234567)) && $sr['totals'][4]->equals(Money::ofMinor(8004567)), 'totals sum the people in the run');
same('2 of 3 in the run', $sr['totals'][1], 'and say how many that was');
$none = FieldReports::salaryRegister($people, $runs, '2026-07', ['earning'], ['deduction']);
same(null, $none['run'], 'no run for the month: said, not zero');
same(['—', '—', '—'], array_slice($none['rows'][0], 2), 'every row is dashes then');

group('pf / esi summary');
$pe = FieldReports::pfEsiSummary($people, (array) $cfg['applicable_values']);
same(['A1', 'A', 'yes', 'U1', 'yes', ''], $pe['rows'][0], 'A: in both');
same(['C1', 'C', 'yes', '', 'no', ''], $pe['rows'][2], 'C: "YES" counts case-insensitively; blank ESI is no');
same(['Total', '3 employee(s)', 2, '', 1, ''], $pe['totals'], 'counts as ints');

group('birthdays');
$bd = FieldReports::birthdays($people, '2026-09');
same([[2, 'B1', 'B', '1985-09-02', 41], [15, 'A1', 'A', '1990-09-15', 36]], $bd['rows'], 'September birthdays in day order with the age turned; October left out');
same(['Total', '2 birthday(s)', '', '', ''], $bd['totals'], 'the count');
same([], FieldReports::birthdays([['id' => '1', 'dob' => '']], '2026-09')['rows'], 'no date of birth: no row');

group('csv');
$csv = FieldReports::csv(['Code', 'Net'], [['A1', Money::ofMinor(6004567)], ['B, "quoted"', 3]], ['Total', Money::ofMinor(6004570)]);
same("Code,Net\nA1,60045.67\n\"B, \"\"quoted\"\"\",3\nTotal,60045.70\n", $csv, 'money as its exact decimal, text escaped, totals last');

group('config');
foreach ((array) $cfg['reports'] as $r) {
    ok(is_file(__DIR__ . '/../surface/screens/report-' . $r['key'] . '.php'), 'report ' . $r['key'] . ' has its screen');
    ok(trim((string) ($r['permission'] ?? '')) !== '', 'report ' . $r['key'] . ' names the permission it sits behind');
}
