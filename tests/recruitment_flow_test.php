<?php

declare(strict_types=1);

/**
 * REQUISITION → … → ONBOARDING → BGV IN ONE LINE (owner, 21 Oct 2026): self-onboarding started from a
 * candidate is linked to it; the Approve form starts from the offer and requisition; approval marks the
 * candidate hired and fills the requisition once; a BGV opened on joining is all-pending.
 */

use SmartPRS\Surface\Bgv;
use SmartPRS\Surface\Recruitment;
use SmartPRS\Surface\SelfOnboarding;
use SmartPRS\Surface\Store;

group('recruitment flow');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/rcflow-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => 'C1', 'name' => 'Ametecs India Pvt Ltd']);
$store->put('branches', ['id' => 'B1', 'name' => 'Kondapur']);
$store->put('departments', ['id' => 'D1', 'name' => 'Collections']);
$store->put('designations', ['id' => 'G1', 'name' => 'Field Agent']);
$r = new Recruitment($store, $root . '/config/recruitment.json');
$so = new SelfOnboarding($store, $root . '/config/self-onboarding.json');

$req = $r->raise(['title' => 'Field Agent', 'openings' => 1, 'company' => 'Ametecs India Pvt Ltd', 'department' => 'Collections', 'location' => 'Kondapur'], 'hr')['requisition'];
$r->decideRequisition((string) $req['id'], true, 'admin');
$c = $r->addCandidate(['name' => 'Training Joiner', 'email' => 'joiner@example.com', 'job_id' => (string) $req['id']])['candidate'];
foreach (['screening', 'interview', 'offer'] as $st) { $r->moveStage((string) $c['id'], $st); }
ok($r->recordOffer((string) $c['id'], ['offered_ctc' => '360000', 'offer_doj' => '2026-11-02'])['ok'], 'the offer is recorded');

same(['employer_company_id' => 'C1', 'branch_id' => 'B1', 'department_id' => 'D1', 'designation_id' => 'G1', 'doj' => '2026-11-02', 'ctc' => '360000'],
    array_map('strval', $r->prefillFor((string) $c['id'])), 'the Approve form starts from the requisition and the offer');

$inv = $so->invite('Training Joiner', 'joiner@example.com', '', 'hr', 'new', null, '', ['candidate_id' => (string) $c['id']]);
ok($inv['ok'], 'self-onboarding is started from the candidate');
same((string) $inv['row']['id'], (string) $so->forCandidate((string) $c['id'])['id'], 'and is found from the candidate');
same(null, $so->forCandidate('nobody'), 'no invitation for another candidate');

// approval: the employee is created by the caller; markHired is the candidate side
$emp = $store->put('employees', ['emp_code' => 'AIPL-2001', 'name' => 'Training Joiner', 'doj' => '2026-11-02', 'ctc' => '360000']);
$note = $r->markHired($r->candidate((string) $c['id']), 'AIPL-2001', '2026-11-02', '360000', 'hr');
same('hired', $r->candidate((string) $c['id'])['stage'], 'the candidate becomes Hired');
same('accepted', $r->candidate((string) $c['id'])['offer_status'], 'the offer is accepted');
same(1, (int) $r->requisition((string) $req['id'])['filled'], 'the requisition fills');
same(Recruitment::CLOSED, $r->requisition((string) $req['id'])['status'], 'and closes when full');
ok(str_contains($note, 'filled'), 'and says so');
ok(!$r->hire((string) $c['id'], ['emp_code' => 'AIPL-2002', 'doj' => '2026-11-02', 'ctc' => '360000'], 'hr')['ok'], 'a hired candidate cannot be hired a second time');

$bgv = new Bgv($store, $root . '/config/bgv.json');
$opened = $bgv->save($emp, ['notes' => 'Opened on joining'], 'hr', '2026-11-02');
ok($opened['ok'], 'a background verification opens on joining');
same('pending', $bgv->forEmployment((string) $emp['id'])['status'], 'with every check pending');
$docs = json_decode((string) file_get_contents($root . '/config/bgv.json'), true)['documents'];
same(array_keys($bgv->declared()['checks']), array_keys($docs), 'every declared check has its documents listed');
