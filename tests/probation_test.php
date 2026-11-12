<?php

declare(strict_types=1);

use SmartPRS\Platform\Lifecycle\EmploymentTimeline;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Probation;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Timeline;

group('probation');

$store = (new Store(sys_get_temp_dir() . '/prs3-prob-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'New Joiner', 'doj' => '2026-03-15', 'employment_stage' => 'probation']);
$store->put('employees', ['id' => '2', 'emp_code' => 'SP-002', 'name' => 'Extended', 'doj' => '2026-01-01', 'employment_stage' => 'probation', 'probation_end' => '2026-09-30']);
$store->put('employees', ['id' => '3', 'emp_code' => 'SP-003', 'name' => 'Old Hand', 'doj' => '2020-01-01', 'employment_stage' => 'confirmed']);
$store->put('employees', ['id' => '4', 'emp_code' => 'SP-004', 'name' => 'Overdue', 'doj' => '2025-12-01', 'employment_stage' => 'probation']);

$timeline = new Timeline($store);
$p = new Probation($store, dirname(__DIR__) . '/config/probation.json', new Customisation($store), $timeline);

same(6, $p->config()['default_months'], 'six months by default, as declared');
$board = $p->board($store->all('employees'), '2026-09-09');
same(3, count($board['rows']), 'three on probation; the confirmed one is not on the board');
$byCode = [];
foreach ($board['rows'] as $r) { $byCode[$r['code']] = $r; }
same('2026-09-15', $byCode['SP-001']['end'], 'the end is doj plus the default months');
same('due', $byCode['SP-001']['bucket'], 'six days out is within the 30-day reminder window');
same('2026-09-30', $byCode['SP-002']['end'], 'an override on the row wins');
ok($byCode['SP-002']['extended'], 'and is shown as extended');
same('overdue', $byCode['SP-004']['bucket'], 'an end in the past is overdue');
same(1, $board['buckets']['overdue'], 'buckets count');

// extend
ok(!$p->extend($store->find('employees', '1'), '2026-09-01', 'hr', 'x')['ok'], 'an extension must move the end later');
ok(!$p->extend($store->find('employees', '1'), '2026-12-15', 'hr', '')['ok'], 'and needs a reason');
ok($p->extend($store->find('employees', '1'), '2026-12-15', 'hr', 'Targets not met')['ok'], 'extended');
same('2026-12-15', $p->endDate($store->find('employees', '1'), $p->config()), 'the new end is on the row');
same(1, count($p->history('SP-001')), 'and in the history');

// confirm — an empty timeline gets its joining first
$confirmed = $p->confirm($store->find('employees', '1'), '2026-09-09', 'hr', 'Good work');
ok($confirmed['ok'], 'confirmed');
ok(str_contains($confirmed['why'], 'joining on 2026-03-15 was recorded first'), 'the joining was recorded from doj and SAID');
$events = $timeline->events('SP-001');
same([EmploymentTimeline::JOINED, EmploymentTimeline::CONFIRMED], array_column($events, 'kind'), 'the timeline carries joined then confirmed');
same('Permanent', $store->find('employees', '1')['employment_stage'], 'the stage is Permanent (owner, 28 Sep)');
same('2026-09-09', $store->find('employees', '1')['confirmation_date'], 'with the date');
same(2, count($p->board($store->all('employees'), '2026-09-09')['rows']), 'and the person leaves the board');
ok(!$p->confirm($store->find('employees', '1'), '2026-09-10', 'hr')['ok'], 'a confirmed employment is not confirmed again');
ok(!$p->confirm($store->find('employees', '3'), '2026-09-10', 'hr')['ok'], 'nor somebody who was never on probation');
same(2, count($p->history('SP-001')), 'history has the extension and the confirmation');

// config
ok(!$p->saveConfig(['default_months' => '0', 'reminder_days' => '30'], 'u', 'U')['ok'], 'zero months is refused');
ok($p->saveConfig(['default_months' => '3', 'reminder_days' => '15, 7, 0', 'notify_manager' => '1'], 'u', 'U')['ok'], 'settings save');
same(3, $p->config()['default_months'], 'and read back');
same([15, 7, 0], $p->config()['reminder_days'], 'with the milestones in descending order');
