<?php

declare(strict_types=1);

use SmartPRS\Surface\Recruitment;
use SmartPRS\Surface\Store;

group('recruitment');

$store = (new Store(sys_get_temp_dir() . '/prs3-recruit-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha Rao']);
$r = new Recruitment($store, dirname(__DIR__) . '/config/recruitment.json');

same(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected', 'offer_rejected'], $r->stages(), 'the stages are the declared ones');

// requisition
ok(!$r->raise(['title' => '', 'openings' => 2], 'hr')['ok'], 'a requisition needs a title');
ok(!$r->raise(['title' => 'Tele-caller', 'openings' => 0], 'hr')['ok'], 'and at least one opening');
ok(!$r->raise(['title' => 'Tele-caller', 'openings' => 2, 'priority' => 'asap'], 'hr')['ok'], 'an undeclared priority is refused');
$raised = $r->raise(['title' => 'Tele-caller', 'openings' => 2, 'department' => 'Collections', 'ctc_min' => '240000', 'ctc_max' => '300000', 'priority' => 'urgent'], 'hr');
ok($raised['ok'], 'a requisition is raised');
$req = $raised['requisition'];
same('REQ-' . date('Y') . '-001', $req['req_code'], 'with a code from the declared prefix');
same('pending', $req['approval_status'], 'and waits for approval');

ok(!$r->addCandidate(['name' => 'Ravi', 'job_id' => (string) $req['id']])['ok'], 'nobody can be linked to an unapproved requisition');
ok(!$r->decideRequisition((string) $req['id'], false, 'admin')['ok'], 'a rejection needs a reason');
ok($r->decideRequisition((string) $req['id'], true, 'admin')['ok'], 'the administrator approves');
ok(!$r->decideRequisition((string) $req['id'], true, 'admin')['ok'], 'and cannot approve twice');

// candidates and stages
$c1 = $r->addCandidate(['name' => 'Ravi Kumar', 'job_id' => (string) $req['id'], 'mobile' => '9000000001', 'source' => 'referral'])['candidate'];
same('applied', $c1['stage'], 'a new candidate is at the first stage');
same('Tele-caller', $c1['position'], 'and takes the requisition\'s title as position');
$pool = $r->addCandidate(['name' => 'Pooled Person'])['candidate'];
same(1, (int) $pool['pool'], 'a candidate with no requisition is in the talent pool');
ok(!$r->addCandidate(['name' => 'X', 'source' => 'billboard'])['ok'], 'an undeclared source is refused');

ok(!$r->moveStage((string) $c1['id'], 'offer')['ok'], 'a candidate does not jump two stages');
ok(!$r->moveStage((string) $c1['id'], 'hired')['ok'], 'and is never MOVED to hired — hiring is an act, not a stage');
ok($r->moveStage((string) $c1['id'], 'screening')['ok'], 'one step forward');
ok($r->moveStage((string) $c1['id'], 'applied')['ok'], 'one step back is allowed');
$r->moveStage((string) $c1['id'], 'screening');
$r->moveStage((string) $c1['id'], 'interview');

// interviews
ok(!$r->schedule((string) $c1['id'], ['mode' => 'carrier_pigeon', 'scheduled_at' => '2026-09-10 10:00'])['ok'], 'an undeclared mode is refused');
ok($r->schedule((string) $c1['id'], ['mode' => 'video', 'scheduled_at' => '2026-09-10 10:00', 'panel' => 'SP-001'])['ok'], 'a round is scheduled');
$iv = $r->interviews((string) $c1['id'])[0];
same(1, (int) $iv['round'], 'rounds are numbered');
ok(!$r->outcome((string) $iv['id'], ['status' => 'completed', 'score' => 11, 'recommendation' => 'hire'])['ok'], 'a score above the declared maximum is refused');
ok($r->outcome((string) $iv['id'], ['status' => 'completed', 'score' => 8, 'recommendation' => 'hire', 'feedback' => 'Good'])['ok'], 'an outcome is recorded');

// hire
ok(!$r->hire((string) $c1['id'], ['emp_code' => 'SP-002', 'doj' => '2026-10-01', 'ctc' => '260000'], 'hr')['ok'], 'no hire from interview — the offer stage is where the CTC is recorded');
$r->moveStage((string) $c1['id'], 'offer');
ok(!$r->hire((string) $c1['id'], ['emp_code' => 'SP-001', 'doj' => '2026-10-01', 'ctc' => '260000'], 'hr')['ok'], 'a code that is already an employment is refused — never linked by name');
ok(!$r->hire((string) $c1['id'], ['emp_code' => '', 'doj' => '2026-10-01', 'ctc' => '260000'], 'hr')['ok'], 'and a code is required');
ok(!$r->hire((string) $c1['id'], ['emp_code' => 'SP-002', 'doj' => '2026-10-01', 'ctc' => '2.6 lakh'], 'hr')['ok'], 'CTC is digits');
$hired = $r->hire((string) $c1['id'], ['emp_code' => 'SP-002', 'doj' => '2026-10-01', 'ctc' => '260000', 'department' => 'Collections'], 'hr');
ok($hired['ok'], 'hired from offer');
same('hired', $r->candidate((string) $c1['id'])['stage'], 'the candidate is hired');
same('SP-002', $hired['emp_code'], 'and an employment exists');
same(2, count($store->all('employees')), 'exactly one new employee row');
same(1, (int) $r->requisition((string) $req['id'])['filled'], 'the requisition counts the fill');
same(Recruitment::OPEN, $r->requisition((string) $req['id'])['status'], 'and stays open with one of two filled');
ok(!$r->hire((string) $c1['id'], ['emp_code' => 'SP-003', 'doj' => '2026-10-01', 'ctc' => '260000'], 'hr')['ok'], 'a hired candidate is not hired twice');
ok(!$r->moveStage((string) $c1['id'], 'offer')['ok'], 'and is not moved back');

$c2 = $r->addCandidate(['name' => 'Second', 'job_id' => (string) $req['id']])['candidate'];
foreach (['screening', 'interview', 'offer'] as $st) { $r->moveStage((string) $c2['id'], $st); }
$r->hire((string) $c2['id'], ['emp_code' => 'SP-003', 'doj' => '2026-10-01', 'ctc' => '250000'], 'hr');
same(Recruitment::CLOSED, $r->requisition((string) $req['id'])['status'], 'the requisition closes itself when full');

ok(!$r->moveStage((string) $pool['id'], 'rejected')['ok'], 'a rejection needs a reason');
ok($r->moveStage((string) $pool['id'], 'rejected', 'No response')['ok'], 'with one it is recorded');
same('No response', $r->candidate((string) $pool['id'])['reject_reason'], 'and kept — nothing is deleted');
same(['applied' => 0, 'screening' => 0, 'interview' => 0, 'offer' => 0, 'hired' => 2, 'rejected' => 1, 'offer_rejected' => 0], $r->funnel(), 'the funnel counts every stage');
ok(!$r->moveStage((string) $pool['id'], 'offer_rejected', 'x')['ok'], 'offer rejected only from offer (and never from a final stage)');

// HIRING DRIVE → PIPELINE and OFFER REJECTED (owner, 22 Oct 2026)
$walk = $r->addCandidate(['name' => 'Walk In', 'email' => 'walk@in.com'])['candidate'];
ok($r->advanceTo((string) $walk['id'], 'offer')['ok'] && $r->candidate((string) $walk['id'])['stage'] === 'offer', 'advanceTo walks forward one step at a time to offer');
ok($r->advanceTo((string) $walk['id'], 'screening')['ok'] && $r->candidate((string) $walk['id'])['stage'] === 'offer', 'and never backwards');
ok(!$r->moveStage((string) $walk['id'], 'offer_rejected')['ok'], 'offer rejected needs a reason');
ok($r->moveStage((string) $walk['id'], 'offer_rejected', 'Compensation not acceptable — wants 20% more')['ok'], 'from offer it is recorded');
same('rejected', $r->candidate((string) $walk['id'])['offer_status'], 'and the offer is marked rejected');
