<?php

declare(strict_types=1);

/**
 * COMPLIANCE CUSTOMISATION: the warning period a customer may move, and the
 * three things they must not touch.
 *
 * How much warning is useful before a credential lapses is a local question — a
 * DRA refresher has to be booked, sat and certified; a police clearance is issued
 * on a police station's timetable. That is a setting. Which table the check
 * reads, which column holds the date and which rows the obligation is on are the
 * check itself: point it at another column and every row comes back as "no date
 * recorded", which reads as two hundred people who never submitted a date and is
 * in fact one wrong word. The last section proves the overlay reaches the
 * counting, not only the settings page.
 */

use SmartPRS\Surface\Compliance;
use SmartPRS\Surface\Customisation;

group('compliance customisation');

$shipped = [
    'key' => 'dra',
    'label' => 'DRA certification',
    'group' => 'Recovery agents',
    'table' => 'employees',
    'expiry' => 'dra_expiry',
    'lead_days' => 60,
    'show' => ['emp_code', 'name'],
    'applies_when' => ['status' => ['active', 'probation', 'notice']],
    'blocks' => 'allocation of recovery work',
    'why' => 'Sixty days because a refresher has to be booked, sat and certified.',
];

// NON-VACUITY: the change an administrator actually wants must PASS, or every
// refusal below proves only that the checker refuses everything.
ok(Compliance::checkProblems(['lead_days' => 90, 'label' => 'DRA certificate',
    'blocks' => 'allocation of recovery work and any client-site visit'], $shipped) === [],
    'Lengthening the warning period, renaming the check and rewording what it blocks is the whole '
    . 'point and must pass.');
ok(Compliance::checkProblems([], $shipped) === [], 'Changing nothing is not a problem.');
ok(Compliance::checkProblems(['lead_days' => '45'], $shipped) === [],
    'A number arriving from a form as a string is still a number.');

// THE WARNING PERIOD MUST WARN.
foreach ([0, -1, '0', -30, 'soon', '', 0.5] as $bad) {
    $problems = Compliance::checkProblems(['lead_days' => $bad], $shipped);
    ok($problems !== [], sprintf('A lead time of "%s" must be refused.', var_export($bad, true)));
    ok(str_contains($problems[0], 'DRA certification'),
        'and the refusal must name the check, because a screen has four of them.');
}
ok(str_contains(Compliance::checkProblems(['lead_days' => 0], $shipped)[0], 'too late'),
    'and say WHY zero is wrong: the first warning would arrive on the day it was already too late '
    . 'to renew.');

// A FINDING WITH NO STATED CONSEQUENCE IS A LINE NOBODY ACTS ON. The shipped
// checks are asserted to carry one in tests/compliance_test.php; a settings form
// must not be the way round that.
foreach (['', '   '] as $blank) {
    ok(Compliance::checkProblems(['blocks' => $blank], $shipped) !== [],
        'Emptying what a lapse blocks must be refused — "DRA: 2026-08-26" is a fact nobody acts '
        . 'on, and the sentence is what makes it an instruction.');
}
ok(Compliance::checkProblems(['label' => ''], $shipped) !== [],
    'A check with no name must be refused too.');

// WHAT THE CHECK *IS* IS NOT A SETTING. Refused BY NAME.
foreach (['table' => 'documents', 'expiry' => 'pcc_expiry',
    'applies_when' => ['status' => ['exited']], 'subject_id' => 'id', 'show' => ['id'],
    'key' => 'other'] as $key => $value) {
    $refusal = Compliance::checkProblems([$key => $value], $shipped);
    ok($refusal !== [], "Changing {$key} through a settings form must be refused.");
    ok(str_contains($refusal[0], $key), "and the refusal must name \"{$key}\".");
}
ok(str_contains(Compliance::checkProblems(['expiry' => 'pcc_expiry'], $shipped)[0],
    'no date recorded'),
    'and say what repointing the date column would actually produce: every row reported as having '
    . 'no date, which looks like a data problem and is one wrong word.');

// ---- the overlay actually reaches the COUNTING, not just the screen ---------

$directory = sys_get_temp_dir() . '/prs3-check-' . bin2hex(random_bytes(4));
$store = (new SmartPRS\Surface\Store($directory))->forTenant('1');

// Two agents: one whose certificate runs out in 40 days, one in 200.
foreach ([['id' => '1', 'emp_code' => 'A1', 'name' => 'Asha', 'status' => 'active',
    'dra_expiry' => '2026-10-11'],
    ['id' => '2', 'emp_code' => 'A2', 'name' => 'Bala', 'status' => 'active',
        'dra_expiry' => '2027-03-20']] as $row) {
    $store->put('employees', $row);
}

$file = $directory . '/checks.json';
file_put_contents($file, json_encode(['checks' => [$shipped]]));

$customisation = new Customisation($store);
$compliance = new Compliance($store, $file, $customisation);
$today = '2026-09-01';
$before = $compliance->run((array) $compliance->all()[0], $today);

same(1, $before['counts'][Compliance::DUE],
    'As shipped, sixty days of warning catches the certificate running out in forty — the baseline '
    . 'the change is measured against.');
same(1, $before['counts'][Compliance::OK], 'and the other one is not yet anybody\'s problem.');

$result = $compliance->customise('dra', ['lead_days' => 250, 'label' => 'DRA certificate'],
    $shipped, '1', 'admin@example.com', 'the certifier now takes eight months');
ok($result['ok'], 'A valid customisation saves: ' . $result['why']);

$compliance = new Compliance($store, $file, new Customisation($store));
$after = $compliance->run((array) $compliance->all()[0], $today);

same('DRA certificate', $after['label'], 'The overlay changes what the check is called.');
same(2, $after['counts'][Compliance::DUE],
    'and the longer warning period reaches the COUNTING: both certificates are now flagged in '
    . 'time, which is the entire purpose of moving it — a settings page that changed the number '
    . 'without changing the finding would be a form with nothing behind it.');
same(0, $after['counts'][Compliance::OK], 'and neither is reported as nothing to worry about.');
same(true, $after['customised'], 'and the check says it has been customised.');
same('allocation of recovery work', $after['blocks'],
    'What it blocks is untouched by a change that did not mention it — an override is laid over '
    . 'the declaration, not substituted for it.');

same(60, $compliance->shipped('dra')['lead_days'],
    'The shipped check is unchanged by any customisation, which is what the next change is '
    . 'compared against.');

// Refusals at the point of saving, not only in the static checker.
$refused = $compliance->customise('dra', ['lead_days' => 0], $shipped, '1', 'a@b.c', '');
ok(!$refused['ok'], 'Saving a zero warning period is refused.');

$refused = $compliance->customise('dra', ['blocks' => ''], $shipped, '1', 'a@b.c', '');
ok(!$refused['ok'], 'Saving an emptied "what it blocks" is refused.');

$refused = $compliance->customise('dra', ['expiry' => 'pcc_expiry'], $shipped, '1', 'a@b.c', '');
ok(!$refused['ok'] && str_contains($refused['why'], 'expiry'),
    'Saving a change to the date column is refused and names it.');

$refused = $compliance->customise('nope', ['lead_days' => 10], null, '1', 'a@b.c', '');
ok(!$refused['ok'], 'A check that does not exist cannot be customised.');

array_map('unlink', (array) glob($directory . '/*'));
array_map('unlink', (array) glob($directory . '/*/*'));
