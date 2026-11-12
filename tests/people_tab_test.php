<?php

declare(strict_types=1);

/** Employees-tab audit (10 Sep): the logic behind the screens, not the HTML. */

use SmartPRS\Surface\Bgv;
use SmartPRS\Surface\Notifications;
use SmartPRS\Surface\OffrollAgents;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Transfers;

group('people tab: off-roll agent earnings are priced exactly from the payout type');

$store = (new Store(sys_get_temp_dir() . '/prs3-people-' . bin2hex(random_bytes(4))))->forTenant('1');
$oa = new OffrollAgents($store, dirname(__DIR__) . '/config/offroll-agents.json');
$perVisit = $store->put(OffrollAgents::AGENTS, ['name' => 'Ravi', 'payout_type' => 'per_visit', 'rate' => '150.5000', 'status' => 'active']);
$percent = $store->put(OffrollAgents::AGENTS, ['name' => 'Sita', 'payout_type' => 'percent', 'rate' => '2.5000', 'status' => 'active']);
same('1505.00', $oa->priced($perVisit, 'visit', '10', '', '')->toDecimalString(), '10 visits × 150.50');
same('250.03', $oa->priced($percent, 'collection', '', '10001', '')->toDecimalString(), '2.5% of 10,001 rounds half-up to the paise');
same('999.99', $oa->priced($perVisit, 'adjustment', '', '', '999.99')->toDecimalString(), 'a typed amount is taken as typed');
ok(!$oa->record($perVisit, ['kind' => 'visit', 'date' => '2026-09-10', 'qty' => '0'], 'hr')['ok'], 'an entry that comes to zero is refused');
ok(!$oa->record($perVisit, ['kind' => 'visit', 'date' => '10/09/2026', 'qty' => '2'], 'hr')['ok'], 'a d/m/Y date is refused');
$r = $oa->record($perVisit, ['kind' => 'visit', 'date' => '2026-09-10', 'qty' => '4', 'note' => 'Kukatpally route'], 'hr');
ok($r['ok'], 'four visits recorded: ' . $r['why']);
$r2 = $oa->record($perVisit, ['kind' => 'deduction', 'date' => '2026-09-11', 'amount' => '100'], 'hr');
ok(!$oa->decide((string) $r['row']['id'], true, 'hr', '')['ok'], 'the recorder cannot approve their own entry');
ok($oa->decide((string) $r['row']['id'], true, 'admin', '')['ok'], 'somebody else approves');
$led = $oa->ledger((string) $perVisit['id'], '2026-09');
same('602.00', $led['net']->toDecimalString(), 'approved net = 4 × 150.50');
same('-100.00', $led['pending']->toDecimalString(), 'the pending deduction carries its sign');
same(2, count($led['entries']), 'two entries in the month');
same([0, 6], $oa->kycProgress($perVisit), 'six document slots, none uploaded');
ok($oa->attach($perVisit, 'doc_pan', 'uploads/x.pdf')['ok'], 'a PAN lands in its slot');
same([1, 6], $oa->kycProgress($oa->find((string) $perVisit['id'])), 'one of six');
ok(!$oa->attach($perVisit, 'doc_nope', 'x')['ok'], 'an undeclared slot is refused');

group('people tab: the transfer tracker and acknowledgement');

$t = ['id' => '9', 'status' => Transfers::PENDING, 'created_at' => '2026-09-01 10:00:00', 'type' => 'branch', 'effective_date' => '2026-10-01'];
$steps = Transfers::tracker($t);
same(['Raised', 'Approval', 'Order letter', 'Acknowledged', 'Applied'], array_column($steps, 'label'), 'five stages for a branch move');
same('todo', $steps[1]['state'], 'approval still to come');
$t['status'] = Transfers::REJECTED;
same(['done', 'fail'], array_column(Transfers::tracker($t), 'state'), 'a rejection ends the track');
$t['status'] = Transfers::APPLIED; $t['type'] = 'company'; $t['order_sent_at'] = '2026-09-02 09:00:00'; $t['accepted_at'] = '2026-09-03 09:00:00'; $t['applied_at'] = '2026-10-01 00:01:00';
$steps = Transfers::tracker($t, 'in_progress');
same('Onboarding', end($steps)['label'], 'a company move adds the onboarding stage');
same('prog', end($steps)['state'], 'in progress while the card is open');
same(['done', 'done', 'done', 'done', 'done', 'prog'], array_column($steps, 'state'), 'everything else done');

group('people tab: BGV writes PRS2\'s six check columns');

$bgv = new Bgv($store, dirname(__DIR__) . '/config/bgv.json');
$e = ['id' => '1', 'emp_code' => 'A1', 'name' => 'A'];
$bgv->save($e, ['verified_on' => '2026-01-01', 'revalidate_months' => '12', 'chk_identity' => 'clear', 'chk_address' => 'flag', 'chk_education' => 'na', 'chk_employment' => 'clear', 'chk_criminal' => 'clear', 'chk_references' => 'pending'], 'hr', '2026-09-10');
$row = $store->find('bgv', (string) $bgv->forEmployment('1')['id']);
same('flag', $row['chk_address'], 'the check is a real column');
same('2027-01-01', $row['next_due'], 'next_due is a real column');
same('12', (string) $row['revalidate_months'], 'the interval too');
same('discrepancy', $bgv->forEmployment('1')['status'], 'derived from the columns');
$legacy = $store->put('bgv', ['employee_id' => '2', 'checks' => json_encode(['o' => ['identity' => 'c', 'address' => 'f'], 'd' => 'discrepancy', 'm' => 6, 'n' => '2026-12-01']), 'status' => 'discrepancy']);
same('flag', $bgv->forEmployment('2')['outcomes']['address'], 'a row saved before the columns existed still reads');
same('2026-12-01', $bgv->forEmployment('2')['next_due'], 'and its due date');

group('people tab: notification drop-ins');

$n = new Notifications($store, dirname(__DIR__) . '/config/notifications.json');
ok(isset($n->events()['transfer.approved']), 'config/notifications.d/people.json events are declared');
ok(isset($n->events()['leave.approved']), 'beside the shipped ones');
ok($n->queue('probation.reminder', 'hr', 'probation.reminder:A1:30:hr', ['name' => 'A', 'code' => 'A1', 'end' => '2026-10-01', 'days' => '20']), 'a reminder queues');
ok(!$n->queue('probation.reminder', 'hr', 'probation.reminder:A1:30:hr', []), 'and dedupes on the milestone key');
same('?r=probation', Notifications::routeFor(['kind' => 'probation.reminder'], ['probation.' => 'probation', 'leave.' => 'leave']), 'a kind routes by prefix');
same('?r=x', Notifications::routeFor(['kind' => 'probation.reminder', 'url' => '?r=x'], ['probation.' => 'probation']), 'a stored url wins');
same('', Notifications::routeFor(['kind' => 'other'], ['probation.' => 'probation']), 'no match, no link');
$q = $n->queued(1)[0];
ok($n->markRead((string) $q['id'], 'hr', false, '2026-09-10 10:00:00'), 'the recipient marks it read');
ok(!$n->markRead((string) $q['id'], 'B2', false, '2026-09-10 10:00:00'), 'somebody else cannot');
same('2026-09-10 10:00:00', $store->find('notification_log', (string) $q['id'])['read_at'], 'read_at is written once');
