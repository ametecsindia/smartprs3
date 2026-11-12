<?php

declare(strict_types=1);

/**
 * COMPLIANCE: that a blank date is NOT counted as compliant.
 *
 * This is the whole reason the class exists. Every compliance screen reports two
 * numbers — in date, expired — and a row with no date falls into neither, so it
 * is silently counted as fine. An agency with forty blank DRA expiry dates reads
 * "160 in date, 0 expired" and believes it; the forty are what the audit finds.
 */

use SmartPRS\Surface\Compliance;

group('compliance');

$check = [
    'key' => 'dra',
    'label' => 'DRA certification',
    'group' => 'Recovery agents',
    'table' => 'employees',
    'expiry' => 'dra_expiry',
    'lead_days' => 60,
    'show' => ['emp_code', 'name'],
    'applies_when' => ['status' => ['active']],
    'blocks' => 'allocation of recovery work',
];

$today = '2026-09-07';
$store = (new SmartPRS\Surface\Store(
    sys_get_temp_dir() . '/prs3-comp-' . bin2hex(random_bytes(4))))->forTenant('1');

foreach ([
    ['emp_code' => 'A-1', 'name' => 'In date', 'status' => 'active', 'dra_expiry' => '2027-06-30'],
    ['emp_code' => 'A-2', 'name' => 'Due soon', 'status' => 'active', 'dra_expiry' => '2026-10-01'],
    ['emp_code' => 'A-3', 'name' => 'Lapsed', 'status' => 'active', 'dra_expiry' => '2026-08-26'],
    ['emp_code' => 'A-4', 'name' => 'Long lapsed', 'status' => 'active', 'dra_expiry' => '2025-01-01'],
    // THE ROW THIS CLASS EXISTS FOR: on the books, obligation applies, no date.
    ['emp_code' => 'A-5', 'name' => 'No date at all', 'status' => 'active', 'dra_expiry' => ''],
    // MySQL's old "no date". Read as a date it is the year zero, which would
    // report as two thousand years lapsed and bury every real finding.
    ['emp_code' => 'A-6', 'name' => 'Zero date', 'status' => 'active', 'dra_expiry' => '0000-00-00'],
    // Not on the books: a lapsed certificate for somebody who left is not a finding.
    ['emp_code' => 'A-7', 'name' => 'Exited', 'status' => 'exited', 'dra_expiry' => '2020-01-01'],
] as $row) {
    $store->put('employees', $row);
}

$compliance = new Compliance($store, __DIR__ . '/../config/compliance.json');
$result = $compliance->run($check, $today);

same(1, $result['counts'][Compliance::OK], 'one in date');
same(1, $result['counts'][Compliance::DUE], 'one inside the 60-day lead');
same(2, $result['counts'][Compliance::LAPSED], 'two already lapsed');

// THE ASSERTION THE CLASS IS FOR.
same(2, $result['counts'][Compliance::UNKNOWN],
    'A blank date and a zero date are NOT DETERMINED — never counted as compliant, because '
    . '"no expiry recorded" and "in date" are opposite facts that look identical in a total.');
ok($result['counts'][Compliance::OK] === 1,
    'and they are not quietly added to the in-date count either');

// The exited agent is out of scope, not a finding.
$named = array_column($result['findings'], 'subject');
ok(!str_contains(implode(' ', $named), 'Exited'),
    'An obligation that no longer applies is not a finding — a screen full of them is one nobody '
    . 'reads.');

// Worst first, and the "not determined" rows are still ON the list.
ok(str_contains((string) $result['findings'][0]['subject'], 'A-4'),
    'The longest-lapsed comes first, because that is the one that has been wrong the longest.');
ok(str_contains(implode(' ', $named), 'A-5') && str_contains(implode(' ', $named), 'A-6'),
    'A row nobody can answer for is LISTED, not just counted — a number with no names is not '
    . 'something anybody can go and fix.');

// A finding names a person, not a row id.
foreach ($result['findings'] as $finding) {
    ok(!str_starts_with((string) $finding['subject'], 'row '),
        'Findings name the person: "row 4182 lapsed" is a line somebody has to decode before they '
        . 'can act, which means they do not act.');
}

// Days are signed: lapsed is negative, due is positive.
$byCode = [];

foreach ($result['findings'] as $finding) {
    $byCode[substr((string) $finding['subject'], 0, 3)] = $finding;
}

ok($byCode['A-3']['days'] < 0, 'A lapsed date is a negative number of days.');
ok($byCode['A-2']['days'] > 0, 'A date still to come is positive.');
same(null, $byCode['A-5']['days'],
    'and a date nobody recorded has no number of days — not zero, which would read as "expires '
    . 'today".');

// The state machine, directly.
same(Compliance::UNKNOWN, Compliance::state('', $today, 60), 'blank');
same(Compliance::UNKNOWN, Compliance::state('0000-00-00', $today, 60), 'the zero date');
same(Compliance::UNKNOWN, Compliance::state('not a date', $today, 60), 'unparseable');
same(Compliance::LAPSED, Compliance::state('2026-09-06', $today, 60), 'yesterday is lapsed');
same(Compliance::DUE, Compliance::state('2026-09-07', $today, 60), 'today is due, not lapsed');
same(Compliance::DUE, Compliance::state('2026-11-06', $today, 60), 'the last day of the lead');
same(Compliance::OK, Compliance::state('2026-11-07', $today, 60), 'the day after it');

// A check that cannot run says so. Reporting zero findings would put a clean
// line on a compliance screen for a check that never looked at anything.
$broken = $compliance->run(['key' => 'x', 'label' => 'X', 'table' => 'no_such_table',
    'expiry' => 'e'], $today);
ok($broken['error'] !== null, 'A check that cannot run must report that, not report nothing wrong.');

// AND A CHECK POINTED AT THE WRONG COLUMN. This one is worse than a missing
// table: it runs, finds every row, and reports all of them as "no date
// recorded" — which reads as two hundred people who never submitted a date and
// is in fact one wrong word in the declaration.
$wrongColumn = $compliance->run(array_merge($check, ['expiry' => 'dra_expiry_date']), $today);
ok($wrongColumn['error'] !== null,
    'A check naming a column the table does not have must refuse, not report every row as '
    . 'undetermined.');
ok(str_contains((string) $wrongColumn['error'], 'dra_expiry_date'),
    'and the refusal must name the column, since fixing it is a one-word edit to the declaration.');
ok(str_contains(Compliance::summary([$broken]), 'could not run'),
    'and the summary must say so — a compliance screen that looks clean because nothing ran is the '
    . 'most dangerous screen in the product.');

// The summary never says "all clear" while rows have no date.
$summary = Compliance::summary([$result]);
ok(str_contains($summary, 'no date recorded'),
    'The summary must surface the undetermined rows, or it lies by omission.');
ok(str_contains(Compliance::summary([['error' => null,
    'counts' => [Compliance::LAPSED => 0, Compliance::DUE => 0, Compliance::UNKNOWN => 0]]]),
    'every check ran'),
    'A genuinely clean result must say WHY it is clean — because the checks ran, not because none '
    . 'did.');

// Every shipped check must name what it blocks. A date with no consequence
// attached is a line on a screen nobody acts on.
foreach ($compliance->all() as $shipped) {
    ok(trim((string) ($shipped['blocks'] ?? '')) !== '', sprintf(
        'Check "%s" must say what it stops.', (string) $shipped['key']));
    ok((int) ($shipped['lead_days'] ?? 0) > 0, sprintf(
        'Check "%s" must give some warning before the date, not on it.', (string) $shipped['key']));
}
