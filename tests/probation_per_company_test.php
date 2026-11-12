<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\Probation;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Timeline;

/**
 * PROBATION IS A COMPANY'S RULE (owner, 23 Sep: "The probation settings to be
 * part of Company table ... have it part of each company added and for the
 * previously added companies, give option to edit this setting").
 *
 * Three things are worth pinning, and they are the three that are easy to get
 * wrong when one setting becomes many:
 *
 * 1. INHERITANCE IS LIVE, not a copy. A company with no settings of its own
 *    follows the default AND KEEPS FOLLOWING IT when the default changes —
 *    which is the whole difference between inheriting and having the same
 *    numbers typed in. So "Follow the default again" REMOVES the entry rather
 *    than writing today's values back.
 * 2. SAVING ONE COMPANY MUST NOT DELETE THE OTHERS. The customisation record is
 *    written whole, so a save built from a fresh array would wipe the tenant
 *    default and every other company in the same breath and look like it
 *    worked (Law 3).
 * 3. THE BOARD MUST DATE EACH EMPLOYEE BY THEIR OWN COMPANY. One config read
 *    for the whole board gives everybody the first company's end date — a
 *    wrong date that looks exactly like a right one.
 */

group('probation · per company');

$dir = sys_get_temp_dir() . '/prs3-prb-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('companies', ['id' => 'C1', 'name' => 'Ametecs India Pvt Ltd']);
$store->put('companies', ['id' => 'C2', 'name' => 'Ametecs Services']);

$customisation = new Customisation($store);
$probation = new Probation($store, dirname(__DIR__) . '/config/probation.json',
    $customisation, new Timeline($store));

$fresh = static fn (): Probation => new Probation($store, dirname(__DIR__) . '/config/probation.json',
    new Customisation($store), new Timeline($store));

// --- the shipped default, before anybody has saved anything ------------------

same(6, $probation->config()['default_months'], 'the declared default is what an untouched tenant gets');
same(6, $probation->config('C1')['default_months'], 'and what a company with no settings of its own gets');
ok(!$probation->isOverridden('C1'), 'no company starts out overriding anything');

// --- the tenant default ------------------------------------------------------

$saved = $probation->saveConfig(['default_months' => 4, 'default_days' => 0,
    'reminder_days' => '30, 7', 'notify_hr' => '1'], 'u1', 'hr@example.com');
ok($saved['ok'], 'the tenant default saves: ' . $saved['why']);
same(4, $fresh()->config()['default_months'], 'and is read back');
same(4, $fresh()->config('C1')['default_months'], 'a company with none of its own follows it');
same(4, $fresh()->config('C2')['default_months'], 'all of them do');

// --- one company goes its own way --------------------------------------------

$saved = $fresh()->saveConfig(['default_months' => 3, 'default_days' => 15,
    'reminder_days' => '14, 0', 'notify_manager' => '1'], 'u1', 'hr@example.com', 'C1');
ok($saved['ok'], 'a company saves its own: ' . $saved['why']);

$p = $fresh();
same(3, $p->config('C1')['default_months'], 'C1 uses its own months');
same(15, $p->config('C1')['default_days'], 'and its own extra days');
same([14, 0], $p->config('C1')['reminder_days'], 'and its own milestones');
ok($p->config('C1')['notify_manager'], 'and its own notification choice');

// THE OTHERS ARE UNTOUCHED. This is the assertion that catches a save built
// from a fresh array instead of a read-merge-write.
same(4, $p->config()['default_months'], 'the tenant default survives a company save');
same(4, $p->config('C2')['default_months'], 'and so does the other company');
ok($p->isOverridden('C1'), 'C1 is marked as having its own');
ok(!$p->isOverridden('C2'), 'C2 is not');

// --- two companies at once ---------------------------------------------------

$saved = $fresh()->saveConfig(['default_months' => 12, 'default_days' => 0,
    'reminder_days' => '60'], 'u1', 'hr@example.com', 'C2');
ok($saved['ok'], 'the second company saves too');

$p = $fresh();
same(3, $p->config('C1')['default_months'], 'C1 kept its three months');
same(12, $p->config('C2')['default_months'], 'C2 has twelve');
same(4, $p->config()['default_months'], 'and the default is still four');

// --- INHERITANCE IS LIVE ------------------------------------------------------
// Change the default; the company that never overrode follows, the one that did
// does not. A "copy the values in" implementation passes every assertion above
// and fails this one.

ok($fresh()->saveConfig(['default_months' => 9, 'default_days' => 0,
    'reminder_days' => '30'], 'u1', 'hr@example.com')['ok'], 'the default moves to nine');

$p = $fresh();
same(3, $p->config('C1')['default_months'], 'a company with its own does NOT follow the new default');
same(12, $p->config('C2')['default_months'], 'nor does the other');
same(9, $p->config('C3-unknown')['default_months'], 'a company nobody configured does');

// --- letting go of an override ------------------------------------------------

ok($fresh()->clearCompany('C1', 'u1', 'hr@example.com')['ok'], 'C1 stops overriding');
$p = $fresh();
ok(!$p->isOverridden('C1'), 'and is no longer marked as having its own');
same(9, $p->config('C1')['default_months'], 'it follows the default again — the CURRENT default, not the old one');
same(12, $p->config('C2')['default_months'], 'clearing one company leaves the other alone');

// --- THE BOARD DATES EACH EMPLOYEE BY THEIR OWN COMPANY -----------------------

$store->put('employees', ['id' => '1', 'emp_code' => 'A-1', 'name' => 'In C2',
    'employer_company_id' => 'C2', 'doj' => '2026-01-01', 'employment_stage' => 'probation']);
$store->put('employees', ['id' => '2', 'emp_code' => 'A-2', 'name' => 'In C1',
    'employer_company_id' => 'C1', 'doj' => '2026-01-01', 'employment_stage' => 'probation']);
$store->put('employees', ['id' => '3', 'emp_code' => 'A-3', 'name' => 'No company',
    'doj' => '2026-01-01', 'employment_stage' => 'probation']);

$rows = [];

foreach ($fresh()->board($store->all('employees'), '2026-06-01')['rows'] as $row) {
    $rows[$row['code']] = $row['end'];
}

same('2027-01-01', $rows['A-1'] ?? '', 'C2 employee ends after twelve months, their company\'s figure');
same('2026-10-01', $rows['A-2'] ?? '', 'C1 employee ends after nine — C1 follows the default again');
same('2026-10-01', $rows['A-3'] ?? '', 'an employee attached to no company gets the default, not a crash');

// And a company's extra DAYS land too, which the months-only path would drop.
ok($fresh()->saveConfig(['default_months' => 3, 'default_days' => 15,
    'reminder_days' => '7'], 'u1', 'hr@example.com', 'C1')['ok'], 'C1 takes 3 months + 15 days');

$rows = [];

foreach ($fresh()->board($store->all('employees'), '2026-06-01')['rows'] as $row) {
    $rows[$row['code']] = $row['end'];
}

same('2026-04-16', $rows['A-2'] ?? '', 'doj + 3 months + 15 days, from that company\'s settings');

// --- the guards still guard ---------------------------------------------------
// Per-company saves go through the same validation; a bad one must not be
// written for a company any more than for the tenant.

$bad = $fresh()->saveConfig(['default_months' => 0, 'reminder_days' => '30'], 'u1', 'x', 'C2');
ok(!$bad['ok'], 'zero months is refused for a company too');
same(12, $fresh()->config('C2')['default_months'], 'and nothing was written');

$bad = $fresh()->saveConfig(['default_months' => 6, 'reminder_days' => ''], 'u1', 'x', 'C2');
ok(!$bad['ok'], 'a company with no reminder milestone is refused');
same(12, $fresh()->config('C2')['default_months'], 'and again nothing was written');

// --- THE COMPANY ROW IS WHERE A COMPANY'S SETTINGS LIVE ----------------------
// Owner, 23 Sep: "Include this Probation along with the Companies itself as we
// have other settings of the companies including time zone". So the six are
// columns on `companies` (migration 2026_09_23_000100), which is what lets the
// company's own Edit form and the boxes under the Companies list edit ONE
// policy rather than two copies of it.
//
// The file store has no column list, so it accepts whatever is written — which
// is exactly the shape a pre-migration MySQL install is NOT in. Both paths are
// covered: the row when the columns are there, the old map when they are not.

group('probation · settings on the company row');

$rowDir = sys_get_temp_dir() . '/prs3-prbrow-' . bin2hex(random_bytes(4));
$rowStore = (new Store($rowDir))->forTenant('1');
$rowStore->put('companies', ['id' => 'C9', 'name' => 'Row Co']);

$rowProbation = static fn (): Probation => new Probation($rowStore,
    dirname(__DIR__) . '/config/probation.json', new Customisation($rowStore), new Timeline($rowStore));

// Written straight onto the row, as the company's own Edit form would.
$rowStore->put('companies', ['id' => 'C9', 'probation_months' => 2, 'probation_days' => 10,
    'probation_reminder_days' => '21, 3', 'probation_notify_hr' => 1,
    'probation_notify_manager' => 0, 'probation_congratulate' => 1]);

$cfg = $rowProbation()->config('C9');
same(2, $cfg['default_months'], 'months typed on the company row are the company\'s policy');
same(10, $cfg['default_days'], 'and so are its extra days');
same([21, 3], $cfg['reminder_days'], 'its milestones are parsed out of the comma-separated column');
ok($cfg['notify_hr'], 'its notify-HR flag is read');
ok(!$cfg['notify_manager'], 'and a 0 is FALSE, not missing');
ok($cfg['congratulate_employee'], 'and its congratulate flag');
ok($rowProbation()->isOverridden('C9'), 'a company with months on its row has its own settings');

// THE BOX AND THE FORM WRITE THE SAME PLACE. Saving through saveConfig() must
// land in the columns, or the two screens become two answers.
ok($rowProbation()->saveConfig(['default_months' => 5, 'default_days' => 0,
    'reminder_days' => '45, 5'], 'u1', 'hr@example.com', 'C9')['ok'], 'the box saves for a company');
same(5, (int) $rowStore->find('companies', 'C9')['probation_months'],
    'and it lands in the COLUMN, where the company form reads it');
same('45, 5', (string) $rowStore->find('companies', 'C9')['probation_reminder_days'],
    'milestones go back as they were typed, into the box they were typed into');
same('Row Co', (string) $rowStore->find('companies', 'C9')['name'],
    'and the rest of the company row is untouched');

// BLANK MONTHS MEANS INHERIT, and clearing blanks the other five with it so no
// half a policy is left behind for a later reader to find.
ok($rowProbation()->clearCompany('C9', 'u1', 'hr@example.com')['ok'], 'the company follows the default again');
ok(!$rowProbation()->isOverridden('C9'), 'and is no longer marked as having its own');
same(6, $rowProbation()->config('C9')['default_months'], 'so it reads the default');
same('', trim((string) ($rowStore->find('companies', 'C9')['probation_reminder_days'] ?? '')),
    'and its milestones were blanked too, not left behind');

// A company nobody has touched still inherits without a row read going wrong.
$rowStore->put('companies', ['id' => 'C8', 'name' => 'Untouched Co']);
same(6, $rowProbation()->config('C8')['default_months'], 'an untouched company inherits');
ok(!$rowProbation()->isOverridden('C8'), 'and says so');

// Saving for a company that does not exist is refused rather than creating one.
ok(!$rowProbation()->saveConfig(['default_months' => 6, 'reminder_days' => '30'],
    'u1', 'hr@example.com', 'C-nope')['ok'], 'settings cannot be saved for a company that is not there');
