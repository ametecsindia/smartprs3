<?php

declare(strict_types=1);

/**
 * THE JOINER FILLS THE REGISTER; HR VERIFIES; THEN IT IS AN EMPLOYMENT.
 *
 * The cases: the form is the Employee Fields list minus what HR keeps; a link is a
 * random token that expires and can be disabled; a submit with a required field
 * missing is a draft, not a submission; a second save merges rather than
 * replaces; a correction reopens the link with the note; injection is recorded
 * against the employment the caller made, never invented here.
 */

use SmartPRS\Surface\SelfOnboarding;
use SmartPRS\Surface\Store;

group('self-onboarding');

$store = (new Store(sys_get_temp_dir() . '/prs3-onb-' . bin2hex(random_bytes(4))))->forTenant('1');
$so = new SelfOnboarding($store, dirname(__DIR__) . '/config/self-onboarding.json');

same(14, $so->config()['link_days'], 'the link life comes from config, not code');
throws(RuntimeException::class, static fn () => (new SelfOnboarding($store, '/nope.json'))->config(), 'a missing config is refused');

ok(!$so->invite('', 'a@b.c', '', 'hr')['ok'], 'no name, no invitation');
ok(!$so->invite('Asha', '', '', 'hr')['ok'], 'no email and no mobile, no way to reach them');
ok(!$so->invite('Asha', 'not-an-email', '', 'hr')['ok'], 'a bad email is refused');
// Invited TODAY: a fixed date expires once the configured days pass and the token check below goes stale.
$today = date('Y-m-d');
$inv = $so->invite('Asha Rao', 'asha@example.com', '', 'hr@x', 'new', null, $today);
ok($inv['ok'], 'a joiner is invited');
$row = $inv['row'];
same('TMP-' . substr($today, 0, 4) . '-00001', $row['temp_emp_code'], 'a temporary reference, numbered within the year');
same(SelfOnboarding::INVITED, $row['status'], 'invited');
same(64, strlen((string) $row['token']), 'the token is 32 random bytes');
same(date('Y-m-d 00:00:00', strtotime($today . ' +14 days')), $row['link_expires_at'], 'open for the configured days');
ok(!$so->invite('Asha Rao', 'asha@example.com', '', 'hr@x')['ok'], 'a second open invitation for the same email is refused');
same('TMP-' . substr($today, 0, 4) . '-00002', $so->invite('Ravi', '', '9999', 'hr@x', 'existing', '7', $today)['row']['temp_emp_code'], 'numbering continues');

same($row['id'], $so->byToken((string) $row['token'])['id'], 'the token opens the row');
same(null, $so->byToken('short'), 'a short token opens nothing');
same(null, $so->byToken(str_repeat('a', 64)), 'a wrong token opens nothing');

$fields = [
    ['code' => 'name', 'label' => 'Name', 'column' => 'name', 'storage' => 'column', 'required' => true, 'type' => 'Text'],
    ['code' => 'pan', 'label' => 'PAN', 'column' => 'pan', 'storage' => 'column', 'required' => true, 'type' => 'Text'],
    ['code' => 'ctc', 'label' => 'CTC', 'column' => 'ctc', 'storage' => 'column', 'required' => true, 'type' => 'Text'],
    ['code' => 'hobby', 'label' => 'Hobby', 'storage' => 'extra', 'required' => false, 'type' => 'Text'],
    ['code' => 'doc_pan', 'label' => 'PAN copy', 'storage' => 'extra', 'required' => false, 'type' => 'Image/PDF/Doc'],
];

$first = $so->save($row, ['name' => 'Asha Rao'], ['doc_pan' => 'uploads/1/employees/x.pdf'], $fields, true);
ok(!$first['ok'], 'submitting without the required PAN is refused');
ok(str_contains($first['why'], 'PAN'), 'and names what is missing');
$row = $so->find((string) $row['id']);
same(SelfOnboarding::DRAFT, $row['status'], 'kept as a draft');
same(50, $row['progress'], 'half the askable fields are filled (ctc is not askable)');
same(1, SelfOnboarding::documentCount($row, $fields), 'one document on file');

$second = $so->save($row, ['pan' => 'ABCDE1234F'], [], $fields, true);
ok($second['ok'], 'with PAN it submits');
$row = $so->find((string) $row['id']);
same(SelfOnboarding::SUBMITTED, $row['status'], 'submitted');
same('Asha Rao', SelfOnboarding::data($row)['columns']['name'], 'the first save survives the second — merge, not replace');
same('uploads/1/employees/x.pdf', SelfOnboarding::data($row)['extras']['doc_pan'], 'and so does the file');
ok(!$so->save($row, ['name' => 'X'], [], $fields, false)['ok'], 'a submitted form no longer accepts changes');

ok(!$so->correction($row, '', 'hr')['ok'], 'a correction needs a note');
ok($so->correction($row, 'PAN copy is unreadable', 'hr')['ok'], 'sent back');
$row = $so->find((string) $row['id']);
same(SelfOnboarding::CORRECTION, $row['status'], 'in correction');
same('PAN copy is unreadable', SelfOnboarding::flags($row)['correction'], 'with the note');
ok($so->byToken((string) $row['token']) !== null, 'and the link opens again');
ok($so->save($row, [], [], $fields, true)['ok'], 'the joiner resubmits');

$row = $so->find((string) $row['id']);
$so->setFlags($row, ['name', 'pan'], true, false, 'hr');
$row = $so->find((string) $row['id']);
same(['name', 'pan'], SelfOnboarding::flags($row)['verified'], 'HR ticks fields verified');
same(1, (int) $row['email_verified'], 'and the email');

$so->markInjected($row, '42', 'hr');
$row = $so->find((string) $row['id']);
same(SelfOnboarding::INJECTED, $row['status'], 'injected');
same('42', (string) $row['employee_id'], 'against the employment the caller created');
ok(!$so->disable($row, 'hr')['ok'], 'an injected record is not disabled here');
ok(!$so->save($row, ['name' => 'X'], [], $fields, false)['ok'], 'nor edited');

$other = $so->invite('Zed', 'zed@example.com', '', 'hr@x')['row'];
ok($so->disable($other, 'hr')['ok'], 'an open invitation is disabled');
same(null, $so->byToken((string) $other['token']), 'and its link no longer opens');
same(2, count($so->all(SelfOnboarding::INJECTED)) + count($so->all(SelfOnboarding::DISABLED)), 'both remain on the list — nothing deleted');

$bulk = $so->bulkInvite("name,email,mobile\nMeena,meena@example.com,\n,nobody@example.com,\nRaj,,8888", 'hr');
same(2, $bulk['invited'], 'two of three lines invited');
same(1, count($bulk['notes']), 'the nameless line is reported by number');
ok(str_contains($bulk['notes'][0], 'Line 3'), 'as line 3');
same(0, $so->bulkInvite("foo,bar\nx,y", 'hr')['invited'], 'a file without a name column invites nobody');
