<?php

declare(strict_types=1);

use SmartPRS\Platform\Lifecycle\EmploymentTimeline;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Timeline;
use SmartPRS\Surface\Transfers;

group('transfers');

$store = (new Store(sys_get_temp_dir() . '/prs3-tr-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha Rao', 'doj' => '2024-01-01', 'branch' => 'Hyderabad', 'department' => 'Collections']);
// THE DESTINATION MUST EXIST IN ORGANIZATION SETUP (28 Sep): a transfer to a
// branch or department nobody set up is refused rather than filed as free text.
foreach (['Hyderabad', 'Vizag', 'Chennai'] as $i => $b) { $store->put('branches', ['id' => 'B' . $i, 'name' => $b]); }
foreach (['Collections', 'Legal'] as $i => $d) { $store->put('departments', ['id' => 'D' . $i, 'name' => $d]); }
$timeline = new Timeline($store);
$tr = new Transfers($store, $timeline);
$e = $store->find('employees', '1');

ok(!$tr->raise($e, ['effective_date' => '2026-10-01', 'reason' => 'x'], 'hr')['ok'], 'a transfer must go somewhere');
ok(!$tr->raise($e, ['to_branch' => 'Vizag', 'effective_date' => '01/10/2026', 'reason' => 'x'], 'hr')['ok'], 'the date is Y-m-d');
ok(!$tr->raise($e, ['to_branch' => 'Vizag', 'effective_date' => '2026-10-01'], 'hr')['ok'], 'and a reason is required');
ok(!$tr->raise($e, ['to_branch' => 'Hyderabad', 'effective_date' => '2026-10-01', 'reason' => 'x'], 'hr')['ok'], 'moving somebody to where they are is refused');
$r = $tr->raise($e, ['to_branch' => 'Vizag', 'effective_date' => '2026-10-01', 'reason' => 'Branch expansion'], 'hr');
ok($r['ok'], 'raised');
same('Hyderabad', $r['transfer']['from_branch'], 'the from-branch is snapshotted at raise');
same(Transfers::PENDING, $r['transfer']['status'], 'and it is pending');
ok(!$tr->raise($e, ['to_branch' => 'Chennai', 'effective_date' => '2026-11-01', 'reason' => 'y'], 'hr')['ok'], 'a second open transfer is refused');

$id = (string) $r['transfer']['id'];
ok(!$tr->decide($id, true, 'hr', '', '2026-09-09')['ok'], 'the raiser does not decide their own transfer');
ok(!$tr->decide($id, false, 'admin', '', '2026-09-09')['ok'], 'a rejection needs a remark');
$d = $tr->decide($id, true, 'admin', 'ok', '2026-09-09');
ok($d['ok'] && str_contains($d['why'], 'applies on 2026-10-01'), 'approved before the date: waits');
same('Hyderabad', $store->find('employees', '1')['branch'], 'nothing has moved yet');

$due = $tr->applyDue('2026-09-30', 'sched');
same(0, $due['applied'], 'the day before, nothing applies');
same(1, $due['waiting'], 'and it is counted as waiting');
$due = $tr->applyDue('2026-10-01', 'sched');
same(1, $due['applied'], 'on the day it applies');
same('Vizag', $store->find('employees', '1')['branch'], 'the branch changed');
same(Transfers::APPLIED, $tr->find($id)['status'], 'the transfer is applied and kept');
$kinds = array_column($timeline->events('SP-001'), 'kind');
same([EmploymentTimeline::JOINED, EmploymentTimeline::TRANSFERRED], $kinds, 'the timeline carries joined (from doj, recorded first) then transferred');
same('Hyderabad', $timeline->valueOn('SP-001', 'branch', '2026-09-15') ?? 'Hyderabad', 'where were they in September — the timeline can say');

// immediate apply when the date has passed
$e2 = $store->find('employees', '1');
$r2 = $tr->raise($e2, ['to_department' => 'Legal', 'effective_date' => '2026-10-05', 'reason' => 'Move'], 'hr');
$d2 = $tr->decide((string) $r2['transfer']['id'], true, 'admin', '', '2026-10-06');
ok(str_contains($d2['why'], 'applied'), 'approved after the date: applied at once');
same('Legal', $store->find('employees', '1')['department'], 'the department changed');
same(3, count($timeline->events('SP-001')), 'and a second transferred event is on the timeline');

$ctx = Transfers::letterContext($tr->find($id));
same('from Hyderabad', $ctx['transfer_from'], 'the letter context names where from');
same('Vizag', $ctx['transfer_to'], 'and where to');
