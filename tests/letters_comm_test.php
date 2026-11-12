<?php

declare(strict_types=1);

/**
 * HR LETTERS + COMMUNICATION: recipient resolution, greetings due, notice
 * validity, and the two templates added for the letter kinds SmartPRS 2 had.
 */

use SmartPRS\Surface\Greetings;
use SmartPRS\Surface\Letters;
use SmartPRS\Surface\Messaging;
use SmartPRS\Surface\Notices;
use SmartPRS\Surface\Store;

group('letters-comm');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-lcm-' . bin2hex(random_bytes(4))))->forTenant('1');
$messaging = new Messaging($store, $root . '/config/communication.json');
$letters = new Letters($store, $root . '/config/letters.json');
$greetings = new Greetings($messaging, $letters);

// ---- recipient resolution -------------------------------------------------
$a = ['id' => '1', 'emp_code' => 'A1', 'name' => 'Asha', 'email' => 'a@x.test', 'mobile' => '9000000001', 'whatsapp' => ''];
$b = ['id' => '2', 'emp_code' => 'B1', 'name' => 'Bala', 'email' => '', 'mobile' => '', 'whatsapp' => '9000000002'];
$c = ['id' => '3', 'emp_code' => 'C1', 'name' => 'Chitra'];

same('a@x.test', $messaging->addressOf($a, 'email'), 'email resolves from the email column');
same('9000000001', $messaging->addressOf($a, 'whatsapp'), 'whatsapp falls back to mobile when the whatsapp column is blank');
same('9000000002', $messaging->addressOf($b, 'whatsapp'), 'whatsapp uses its own column first');
same('', $messaging->addressOf($b, 'sms'), 'no mobile = no sms address');
same('', $messaging->addressOf($a, 'pigeon'), 'an undeclared channel resolves nowhere');

$r = $messaging->resolve([$a, $b, $c], ['email', 'whatsapp']);
same(3, count($r['to']), 'A on email + A on whatsapp (via mobile) + B on whatsapp = 3 queue rows');
same(['B1', 'C1'], $r['missing']['email'], 'the ones with no email are NAMED per channel, not dropped silently');
same(['C1'], $r['missing']['whatsapp'], 'C has neither whatsapp nor mobile');
throws(InvalidArgumentException::class, static fn () => $messaging->resolve([$a], ['fax']), 'a channel nobody declared is refused');

$q = $messaging->queue($r['to'], 'ALL employees', 'Hello', 'Body', '1', 'admin@test');
same(3, $q['queued'], 'one row per resolved recipient-channel pair');
$log = $messaging->log();
same(3, count($log), 'the log holds every queued row');
same('queued', $log[0]['status'], 'nothing is sent: the status is the declared queued status');
same($q['batch'], $log[0]['batch_id'], 'rows of one send share a batch id');
same(1, count(array_unique(array_column($log, 'batch_id'))), 'exactly one batch');
same([], $messaging->log('greeting'), 'a message is not a greeting');

// ---- greetings due --------------------------------------------------------
$people = [
    ['id' => '10', 'emp_code' => 'D1', 'name' => 'Dev', 'dob' => '1990-03-05', 'doj' => '2020-03-08'],
    ['id' => '11', 'emp_code' => 'E1', 'name' => 'Esha', 'dob' => '1992-02-29', 'doj' => '2025-09-01'],
    ['id' => '12', 'emp_code' => 'F1', 'name' => 'Faiz', 'dob' => '', 'doj' => '2025-03-05'],
    ['id' => '13', 'emp_code' => 'G1', 'name' => 'Gita', 'dob' => '0000-00-00', 'doj' => '2019-12-31'],
];
$due = $greetings->due($people, '2026-03-05');
$keys = array_map(static fn (array $d): string => $d['employee']['emp_code'] . ':' . $d['kind'] . ':' . $d['in_days'], $due);
same(['D1:birthday:0', 'F1:anniversary:0', 'D1:anniversary:3'], $keys,
    'today and the week ahead from DOB/DOJ, nearest first; blank and 0000-00-00 dates never make anybody due; F1 joined a year ago exactly');
same(6, $due[2]['years'], 'D1 completes 6 years on 2026-03-08');
same(1, $due[1]['years'], 'F1 completes the first year — min_years 1 admits it');
same([], $greetings->due([['id' => '14', 'emp_code' => 'H1', 'doj' => '2025-12-01']], '2026-03-05'),
    'somebody with less than min_years of service is not due an anniversary');
same([], $greetings->due([['id' => '15', 'emp_code' => 'I1', 'doj' => '2026-03-01']], '2026-02-25'),
    'a joining date within the last year is not an anniversary yet (0 years < min_years)');
same('2027-03-01', $greetings->due([['id' => '15', 'emp_code' => 'I1', 'doj' => '2026-03-01']], '2027-02-25')[0]['on'] ?? 'no',
    'an anniversary later this week is due with its date');
$leap = $greetings->due([$people[1]], '2026-02-28', 0);
same('birthday', $leap[0]['kind'] ?? 'none', 'a 29 February birthday is due on 28 February in a year without one');
same('2028-02-29', Greetings::nextOccurrence(new DateTimeImmutable('1992-02-29'), new DateTimeImmutable('2028-01-01'))->format('Y-m-d'),
    'and on 29 February when the year has one');
same('2027-01-02', Greetings::nextOccurrence(new DateTimeImmutable('2000-01-02'), new DateTimeImmutable('2026-01-03'))->format('Y-m-d'),
    'a date already past this year rolls to next year');

// ---- greeting rendering through the letter engine -------------------------
$g = $greetings->render($due[0], 'Alpha Ltd');
ok($g['ok'], 'the birthday greeting renders when name and company resolve');
ok(str_contains($g['subject'], 'Dev') && str_contains($g['body'], 'Alpha Ltd'), 'name and company are filled in');
$an = $greetings->render($due[2], 'Alpha Ltd');
ok(str_contains($an['body'], '6 year'), 'the anniversary greeting prints the years of service');
$blank = $greetings->render(['employee' => ['id' => '99', 'name' => ''], 'kind' => 'birthday', 'years' => 0], 'Alpha Ltd');
ok(!$blank['ok'], 'a greeting with a blank where the name belongs is refused, not sent');

ok(!$greetings->alreadyQueued('10', 'birthday', '2026-03-05'), 'not queued yet');
$messaging->queue([['employee' => $people[0], 'channel' => 'email', 'address' => 'd@x.test']], 'birthday 2026-03-05', $g['subject'], $g['body'], '1', 'admin', 'greeting');
ok($greetings->alreadyQueued('10', 'birthday', '2026-03-05'), 'queued once is remembered, so a second click cannot send a second wish');
same(1, count($messaging->log('greeting')), 'the greetings log is the messages log filtered by kind');

// ---- letter kinds ---------------------------------------------------------
foreach (['offer', 'increment', 'warning', 'relieving', 'nda'] as $kind) {
    ok($letters->find($kind) !== null, "config/letters.json declares the '{$kind}' template SmartPRS 2's sub-menu needs");
}

$full = ['id' => '7', 'emp_code' => 'SP-001', 'name' => 'Priya', 'designation' => 'Team Lead', 'department' => 'Collections',
    'branch' => 'Kondapur', 'doj' => '2021-04-01', 'ctc' => '480000.00', 'address' => '12 MG Road'];
// 15 Oct 2026: the increment letter also prints its effective date and the salary-structure annexure
// (the screens fill both through letterContextFor()).
$inc = $letters->render($letters->find('increment'), $full, ['company' => 'Alpha Ltd', 'effective_date' => '2026-10-01', 'salary_structure' => 'Salary structure annexure']);
ok($inc['ok'], 'the increment letter renders for a complete employment');
ok(str_contains($inc['body'], '4,80,000') && str_contains($inc['body'], 'Lakh'), 'CTC printed as money and in words on the Indian scale');
same(['employee.update', 'employee.salary.view'], Letters::permissionsFor($letters->find('increment')), 'an increment letter prints a salary, so it needs the salary permission');
same(['employee.update'], Letters::permissionsFor($letters->find('nda')), 'the NDA prints no money and needs only employee.update');
$nda = $letters->render($letters->find('nda'), $full, ['company' => 'Alpha Ltd']);
ok($nda['ok'] && str_contains($nda['body'], 'PRIYA'), 'the NDA renders with the name in capitals');
ok(!$letters->render($letters->find('increment'), ['id' => '8', 'name' => 'No Pay', 'emp_code' => 'X', 'designation' => 'Agent'], ['company' => 'A'])['ok'],
    'an increment letter for somebody with no CTC is refused rather than blank');

// ---- notices --------------------------------------------------------------
$notices = new Notices($store);
$store->put('notices', ['id' => '1', 'title' => 'Holiday list', 'status' => 'published', 'posted_on' => '2026-01-01', 'valid_to' => '2026-01-31']);
$store->put('notices', ['id' => '2', 'title' => 'Fire drill', 'status' => 'published', 'posted_on' => '2026-02-20', 'valid_from' => '2026-02-20', 'valid_to' => '']);
$store->put('notices', ['id' => '3', 'title' => 'Town hall', 'status' => 'published', 'posted_on' => '2026-03-01', 'valid_from' => '2026-03-10', 'valid_to' => '2026-03-10']);
$store->put('notices', ['id' => '4', 'title' => 'Draft', 'status' => 'draft', 'posted_on' => '2026-03-01']);
$store->put('notices', ['id' => '5', 'title' => 'Gone', 'status' => 'published', 'posted_on' => '2026-03-01', 'deleted_at' => '2026-03-02 00:00:00']);
$split = $notices->split('2026-03-05');
same(['Fire drill'], array_column($split['current'], 'title'), 'published, started, no expiry = current; a deleted notice is not on the board');
same(['Town hall'], array_column($split['upcoming'], 'title'), 'valid from a later date = upcoming');
same(['Holiday list'], array_column($split['expired'], 'title'), 'past valid_to = expired');
same(['Draft'], array_column($split['draft'], 'title'), 'unpublished = draft');
same('current', Notices::stateOf(['status' => 'Published', 'posted_on' => '2026-03-05'], '2026-03-05'), 'valid_from blank falls back to posted_on; status compared case-insensitively');
same(['Town hall', 'Fire drill'], array_column($notices->current('2026-03-10'), 'title'), 'on its one valid day the town hall notice is current beside the open-ended one, newest posted first — current() is what a dashboard wires');
