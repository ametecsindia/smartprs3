<?php

declare(strict_types=1);

/** A board is a checklist copied at start; a BGV's overall status is derived from its checks. */

use SmartPRS\Surface\Bgv;
use SmartPRS\Surface\Onboarding;
use SmartPRS\Surface\Store;

group('onboarding and bgv');

$store = (new Store(sys_get_temp_dir() . '/prs3-onb2-' . bin2hex(random_bytes(4))))->forTenant('1');
$ob = new Onboarding($store, dirname(__DIR__) . '/config/onboarding.json');
$e1 = ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'doj' => '2026-09-01'];
$e2 = ['id' => '2', 'emp_code' => 'B2', 'name' => 'B', 'doj' => '2026-01-01'];

same(['New joiner', 'Transfer'], array_keys($ob->checklists()), 'two checklists shipped');
ok(!$ob->start($e1, 'Nope')['ok'], 'an undeclared checklist is refused');
$b = $ob->start($e1, 'New joiner');
ok($b['ok'], 'a board starts');
ok(!$ob->start($e1, 'New joiner')['ok'], 'one board per employment');
$board = $ob->boardFor('1');
same(6, $board['total'], 'six items copied');
same('pending', $board['status'], 'pending until a tick');
ok($ob->toggle((string) $board['items'][0]['id'], true)['ok'], 'ticked');
same('in_progress', $ob->boardFor('1')['status'], 'in progress after one');
foreach ($ob->boardFor('1')['items'] as $it) { $ob->toggle((string) $it['id'], true); }
same('completed', $ob->boardFor('1')['status'], 'completed after all');
ok($ob->toggle((string) $board['items'][0]['id'], false)['ok'], 'and reopened');
same('in_progress', $ob->boardFor('1')['status'], 'back to in progress');
$r = $ob->startRecent([$e1, $e2], '2026-09-09');
same(0, $r['started'], 'A already has one; B joined too long ago');
same(1, $ob->startRecent([['id' => '3', 'emp_code' => 'C3', 'name' => 'C', 'doj' => '2026-08-20']], '2026-09-09')['started'], 'a joiner within the window gets one');

$bgv = new Bgv($store, dirname(__DIR__) . '/config/bgv.json');
same('pending', Bgv::derive(['identity' => 'pending', 'address' => 'pending']), 'nothing answered');
same('in_progress', Bgv::derive(['identity' => 'clear', 'address' => 'pending']), 'some answered');
same('clear', Bgv::derive(['identity' => 'clear', 'address' => 'na']), 'all answered, none flagged');
same('discrepancy', Bgv::derive(['identity' => 'clear', 'address' => 'flag']), 'any flag');
ok(!$bgv->save($e1, ['chk_identity' => 'maybe'], 'hr', '2026-09-09')['ok'], 'an unknown outcome is refused');
ok(!$bgv->save($e1, ['revalidate_months' => '0'], 'hr', '2026-09-09')['ok'], 'zero months is refused');
$s = $bgv->save($e1, ['agency' => 'X', 'verified_on' => '2026-09-01', 'revalidate_months' => '6', 'chk_identity' => 'clear', 'chk_address' => 'clear', 'chk_education' => 'na', 'chk_employment' => 'clear', 'chk_criminal' => 'clear', 'chk_references' => 'clear', 'notes' => 'fine'], 'hr', '2026-09-09');
ok($s['ok'], 'saved: ' . $s['why']);
$row = $bgv->forEmployment('1');
same('clear', $row['status'], 'derived clear');
same('2027-03-01', $row['next_due'], 'due six months on');
same('clear', $row['outcomes']['identity'], 'outcomes read back long-form');
ok(strlen((string) $row['checks']) <= 255, 'and the stored JSON fits the ported column');
$bgv->save($e1, ['chk_identity' => 'flag', 'status' => 'clear', 'verified_on' => '2026-01-01'], 'hr', '2026-09-09');
same('clear', $bgv->forEmployment('1')['status'], 'an override is honoured');
same('discrepancy', $bgv->forEmployment('1')['derived_status'], 'but the derived status is kept beside it');
same(1, count($bgv->all()), 'one row per employment, updated in place');
same(1, count($bgv->due('2027-12-31')), 'due lists it');
