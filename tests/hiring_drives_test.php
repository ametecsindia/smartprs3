<?php

declare(strict_types=1);

/** A drive is a day and a list; nothing on it is deleted, and the outcome vocabulary is closed. */

use SmartPRS\Surface\HiringDrives;
use SmartPRS\Surface\RecruitCampaigns;
use SmartPRS\Surface\Store;

group('hiring drives and campaigns');

$store = (new Store(sys_get_temp_dir() . '/prs3-drv-' . bin2hex(random_bytes(4))))->forTenant('1');
$hd = new HiringDrives($store);

ok(!$hd->save(['title' => 'x', 'drive_date' => '20-09-2026'], 'hr')['ok'], 'a date that is not Y-m-d is refused');
ok(!$hd->save(['title' => '', 'drive_date' => '2026-09-20'], 'hr')['ok'], 'no title and no requisition, no drive');
ok(!$hd->save(['title' => 'x', 'drive_date' => '2026-09-20', 'date_to' => '2026-09-19'], 'hr')['ok'], 'ending before it starts is refused');
$d = $hd->save(['title' => 'Walk-in', 'drive_date' => '2026-09-20', 'venue' => 'Office'], 'hr')['drive'];
same('planned', $d['status'], 'a new drive is planned');
same(2, $hd->invite((string) $d['id'], [['id' => '5', 'name' => 'A', 'mobile' => '1'], ['id' => '6', 'name' => 'B', 'mobile' => '2']])['added'], 'two invited');
same(0, $hd->invite((string) $d['id'], [['id' => '5', 'name' => 'A', 'mobile' => '1']])['added'], 'inviting again adds nobody');
$row = $hd->candidates((string) $d['id'])[0];
ok(!$hd->mark((string) $row['id'], ['outcome' => 'maybe'])['ok'], 'an outcome outside the vocabulary is refused');
ok(!$hd->mark((string) $row['id'], ['outcome' => 'selected', 'score' => '101'])['ok'], '101 is not a score');
ok($hd->mark((string) $row['id'], ['outcome' => 'selected', 'score' => '80', 'attended' => '1'])['ok'], 'marked');
$row = $hd->candidates((string) $d['id'])[0];
same(1, (int) $row['attended'], 'attended');
ok((string) $row['attended_at'] !== '', 'with the time it was first marked');
ok(!$hd->setStatus((string) $d['id'], 'deleted')['ok'], 'there is no deleted status');
ok($hd->setStatus((string) $d['id'], 'cancelled')['ok'], 'a drive is cancelled, its list kept');
same(2, count($hd->candidates((string) $d['id'])), 'still two on the list');
ok(str_contains($hd->csv((string) $d['id']), 'A,1,yes'), 'the export lists attendance');

$rc = new RecruitCampaigns($store);
ok(!$rc->create('x', 'Hi {nam}', 'general', [['name' => 'A', 'mobile' => '9999999999']], [], 'hr')['ok'], 'an unknown placeholder is refused');
ok(!$rc->create('x', 'Hi {name}', 'general', [['name' => 'A', 'mobile' => '12']], [], 'hr')['ok'], 'no usable mobile, no campaign');
$c = $rc->create('Drive', 'Dear {name}, {position} at {company} on {date}', 'drive_invite',
    [['name' => 'A', 'mobile' => '9999999999', 'position' => 'Caller'], ['name' => 'B', 'mobile' => '88888 88888'], ['name' => 'C', 'mobile' => '']],
    ['company' => 'Acme', 'date' => '20 Sep'], 'hr');
ok($c['ok'], 'a campaign is created');
same(2, $c['created'], 'two messages — the third had no mobile');
same(1, $c['skipped'], 'one skipped');
$ms = $rc->messages((string) $c['campaign']['id']);
same('Dear A, Caller at Acme on 20 Sep', $ms[0]['template'], 'rendered per person');
same(RecruitCampaigns::QUEUED, $ms[0]['status'], 'queued, never sent');
same(0, (int) $c['campaign']['sent'], 'sent stays zero without a provider');
ok(!$rc->respond((string) $ms[0]['id'], 'yes')['ok'], 'a response outside the vocabulary is refused');
ok($rc->respond((string) $ms[0]['id'], 'will_attend')['ok'], 'a response is recorded');
same(2, count(RecruitCampaigns::recipientsFromCsv("name,mobile\nA,9999999999\nB,8888888888")), 'a name,mobile file parses, header skipped');
