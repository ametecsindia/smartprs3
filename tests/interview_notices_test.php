<?php

declare(strict_types=1);

use SmartPRS\Surface\AlertEmails;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\InterviewNotices;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\Recruitment;
use SmartPRS\Surface\Store;

/**
 * THE PANEL IS TOLD AND THE STAGE FOLLOWS (owner, 21 Oct 2026): scheduling a round moves an applied /
 * screening candidate to Interview, every panelist gets a bell message (and an email attempt), and
 * the round pops up for them at its time.
 */

group('interview notices');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/ivn-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'AIPL-1002', 'name' => 'Manoj Krishna Reddy', 'email' => 'manoj@example.com']);
$store->put('employees', ['id' => '2', 'emp_code' => 'AI2001', 'name' => 'Ejaz Hussain']);
$r = new Recruitment($store, $root . '/config/recruitment.json');

same(['AIPL-1002', 'AI2001'], InterviewNotices::codes('AIPL-1002 · Manoj Krishna Reddy, AI2001 · Ejaz Hussain'), 'codes are read back from the stored panel');
same([], InterviewNotices::codes(''), 'an empty panel names nobody');

$c = $r->addCandidate(['name' => 'Ankita', 'mobile' => '8143839531'])['candidate'];
same('applied', $c['stage'], 'a new candidate is applied');
$at = date('Y-m-d H:i', time() + 3600);
$s = $r->schedule((string) $c['id'], ['mode' => 'phone', 'scheduled_at' => $at, 'panel' => 'AIPL-1002 · Manoj Krishna Reddy, AI2001 · Ejaz Hussain']);
ok($s['ok'] && isset($s['interview']['id']), 'the round is scheduled and returned');
same('interview', $r->candidate((string) $c['id'])['stage'], 'scheduling moves the candidate to Interview');

$r->moveStage((string) $c['id'], 'offer');
$r->schedule((string) $c['id'], ['mode' => 'phone', 'scheduled_at' => $at]);
same('offer', $r->candidate((string) $c['id'])['stage'], 'a candidate already past Interview stays where they are');

// existing data: an interview on record, stage left behind
$old = $r->addCandidate(['name' => 'Old'])['candidate'];
$store->put(Recruitment::INTERVIEWS, ['candidate_id' => (string) $old['id'], 'round' => 1, 'round_label' => 'Round 1',
    'scheduled_at' => '2026-10-20 10:00:00', 'mode' => 'phone', 'status' => 'completed']);
same(1, $r->syncInterviewStages(), 'the backfill moves the candidate left at Applied');
same('interview', $r->candidate((string) $old['id'])['stage'], 'to Interview');
same(0, $r->syncInterviewStages(), 'and is a no-op the second time');

$bell = new Notifications($store, $root . '/config/notifications.json');
$mail = new AlertEmails($store, new Customisation($store), $root . '/config/alert-emails.json');
$n = InterviewNotices::notify($store, $bell, $mail, $s['interview'], $s['candidate'], null);
same(2, $n['told'], 'each panelist gets a bell message');
same(1, count($bell->queuedFor('AI2001')), 'in their own feed');
ok(str_contains((string) $bell->queuedFor('AIPL-1002')[0]['title'], 'Ankita'), 'naming the candidate');
same(0, $n['emailed'], 'no email while the mail server is off');
ok((bool) array_filter($n['problems'], static fn (string $p): bool => str_contains($p, 'AI2001') && str_contains($p, 'no email')), 'a panelist without an email is named');
same(0, InterviewNotices::notify($store, $bell, $mail, $s['interview'], $s['candidate'], null)['told'], 'nobody is told twice');

$due = InterviewNotices::dueFor($store, 'AI2001', time());
same(1, count($due), 'the round pops up for a panelist');
same((int) strtotime($at), $due[0]['at'], 'at the scheduled time');
same([], InterviewNotices::dueFor($store, 'NOBODY', time()), 'not for someone off the panel');
same([], InterviewNotices::dueFor($store, 'AI2001', time() + 7200), 'nor once it is long past');

// EDIT A ROUND (owner, 21 Oct): a round on hold is edited back to scheduled at a new time
$ed = $r->addCandidate(['name' => 'Edited'])['candidate'];
$e1 = $r->schedule((string) $ed['id'], ['mode' => 'phone', 'scheduled_at' => '2026-10-21 14:00', 'panel' => 'AI2001 · Ejaz Hussain'])['interview'];
ok($r->editInterview((string) $e1['id'], ['mode' => 'in_person', 'scheduled_at' => '2026-10-21 14:00', 'status' => 'on_hold', 'panel' => 'AI2001 · Ejaz Hussain'])['ok'], 'a round can be put on hold');
same('on_hold', $store->find(Recruitment::INTERVIEWS, (string) $e1['id'])['status'], 'and is stored on hold');
$back = $r->editInterview((string) $e1['id'], ['mode' => 'video', 'scheduled_at' => '2026-10-23T11:30', 'status' => 'scheduled', 'round_label' => 'Ops Round', 'panel' => 'AIPL-1002 · Manoj Krishna Reddy']);
ok($back['ok'] && $back['renotify'], 'rescheduled, and the panel is to be told again');
$e1 = $store->find(Recruitment::INTERVIEWS, (string) $e1['id']);
same(['2026-10-23 11:30:00', 'video', 'Ops Round', 'AIPL-1002 · Manoj Krishna Reddy'], [$e1['scheduled_at'], $e1['mode'], $e1['round_label'], $e1['panel']], 'with the new time, mode, label and panel');
ok(!$r->editInterview((string) $e1['id'], ['mode' => 'video', 'scheduled_at' => '2026-10-23 11:30', 'status' => 'completed', 'score' => 0])['ok'], 'completed still needs a score');
same(false, $r->editInterview((string) $e1['id'], ['mode' => 'video', 'scheduled_at' => '2026-10-23 11:30', 'status' => 'scheduled', 'panel' => 'AIPL-1002 · Manoj Krishna Reddy'])['renotify'], 'saving unchanged tells nobody again');

same('', Recruitment::roundStatus([]), 'no rounds, no round status');
same('Round 1 · Ops Round · Scheduled · 23-10-2026 11:30', Recruitment::roundStatus($r->interviews((string) $ed['id'])), 'the latest round: number, label, status and time');
