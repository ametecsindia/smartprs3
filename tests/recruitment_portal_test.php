<?php

declare(strict_types=1);

use SmartPRS\Surface\JobPostings;
use SmartPRS\Surface\Recruitment;
use SmartPRS\Surface\Sheet;
use SmartPRS\Surface\Store;

group('recruitment_portal');

$store = (new Store(sys_get_temp_dir() . '/prs3-portal-' . bin2hex(random_bytes(4))))->forTenant('1');
$cfg = dirname(__DIR__) . '/config/recruitment.json';
$r = new Recruitment($store, $cfg);
$jp = new JobPostings($store, $cfg);

// ------------------------------------------------ portal values, read exactly
same('550000', Recruitment::amount('INR 5.5 Lacs'), 'Naukri "INR 5.5 Lacs" is 5,50,000');
same('1250000', Recruitment::amount('12 Lakhs 50 Thousand'), 'lakhs and thousands');
same('420000', Recruitment::amount('₹ 4,20,000'), 'Indian grouping');
same('325000', Recruitment::amount('3.25 LPA'), 'LPA');
same('', Recruitment::amount('Not disclosed'), 'nothing guessed from words');
same('5.5', Recruitment::years('5 Year(s) 6 Month(s)'), 'Naukri experience');
same('0', Recruitment::years('Fresher'), 'a fresher is 0 years');
same('2.5', Recruitment::years('2y 6m'), 'short form');
same('0.5', Recruitment::years('6 Month(s)'), 'months only');
same('7', Recruitment::years('7+ Years'), 'a plus sign is ignored');

// ------------------------------------------------ requisition → job post
$req = $r->raise(['title' => 'Tele-caller', 'openings' => 3, 'department' => 'Collections', 'location' => 'Hyderabad',
    'ctc_min' => '180000', 'ctc_max' => '240000'], 'hr')['requisition'];
ok(!$jp->save(['job_id' => (string) $req['id'], 'description' => 'Calls'], $req, 'hr')['ok'], 'no job post before the requisition is approved');
$r->decideRequisition((string) $req['id'], true, 'admin');
$req = $r->requisition((string) $req['id']);
ok(!$jp->save(['job_id' => (string) $req['id'], 'description' => ''], $req, 'hr')['ok'], 'a job post needs a description');
$saved = $jp->save(['job_id' => (string) $req['id'], 'description' => 'Make outbound collection calls.', 'skills' => 'Telugu, Hindi',
    'experience_min' => '0', 'experience_max' => '2'], $req, 'hr');
ok($saved['ok'], 'a job post is written for an approved requisition');
$post = $saved['posting'];
same('Tele-caller', $post['title'], 'title comes from the requisition');
same('180000', (string) $post['ctc_min'], 'salary range comes from the requisition');

$pack = $jp->pack($jp->forRequisition((string) $req['id']));
ok(count($pack) >= 6, 'the portal pack lists the fields every portal asks for');
same('Tele-caller', $pack['Job title'] ?? null, 'pack field: job title');
same('0 – 2 years', $pack['Work experience'] ?? null, 'pack field: experience range');

ok($jp->save(['job_id' => (string) $req['id'], 'description' => 'Again'], $req, 'hr')['posting']['id'] === $post['id'], 'one job post per requisition — saving again updates it');
ok(!$jp->markPosted((string) $post['id'], ['portal' => 'nowhere'], 'hr')['ok'], 'only a declared portal');
ok($jp->markPosted((string) $post['id'], ['portal' => 'naukri', 'ref' => 'NK-991', 'posted_on' => '2026-10-01', 'expires_on' => '2026-10-31'], 'hr')['ok'], 'posted on Naukri is recorded');
$jp->markPosted((string) $post['id'], ['portal' => 'indeed', 'posted_on' => '2026-10-02'], 'hr');
same(['naukri', 'indeed'], array_column($jp->channels($jp->forRequisition((string) $req['id'])), 'portal'), 'both postings are on record');
ok($jp->closeChannel((string) $post['id'], 0, 'hr')['ok'], 'a posting is closed, not deleted');
same('closed', $jp->channels($jp->forRequisition((string) $req['id']))[0]['status'], 'and shows closed');

// ------------------------------------------------ import a portal download
$naukri = "Name,Email ID,Phone Number,Current Location,Total Experience,Annual Salary,Current Company Name,Key Skills,Notice Period\n"
    . "Kiran Kumar,kiran@example.com,+91 98480 11111,Hyderabad,1 Year(s) 6 Month(s),INR 2.1 Lacs,Some BPO,Telugu,30 days\n"
    . "Sita Devi,sita@example.com,9848022222,Vijayawada,Fresher,,,Hindi,Immediate\n"
    . ",nobody@example.com,,,,,,,\n";
$im = $r->import($naukri, ['source' => 'naukri', 'job_id' => (string) $req['id']]);
same(2, $im['imported'], 'two Naukri rows imported, the nameless row refused');
$kiran = array_values(array_filter($r->candidates(), static fn (array $c): bool => $c['name'] === 'Kiran Kumar'))[0];
same('naukri', $kiran['source'], 'the portal is the source');
same((string) $req['id'], (string) $kiran['job_id'], 'onto the requisition chosen in the dialog');
same('1.5', (string) $kiran['experience_years'], 'experience read from portal text');
same('210000', (string) $kiran['current_ctc'], 'salary read from portal text');
ok(str_contains((string) $kiran['notes'], '30 days'), 'notice period kept in notes');
ok(in_array('Phone Number → Mobile', $im['understood'], true), 'the dialog says which column became what');

$again = $r->import("First Name,Last Name,Email,Phone\nKiran,Kumar,KIRAN@example.com,9848011111\nAnil,Rao,anil@example.com,9000000001\n", ['source' => 'linkedin']);
same(1, $again['imported'], 'the same person from another portal is not added twice');
same(1, $again['duplicates'], 'and is counted as a duplicate');
ok(count(array_filter($r->candidates(), static fn (array $c): bool => $c['name'] === 'Anil Rao')) === 1, 'First + Last Name make the name');

// An .xls a portal sends that is really an HTML table.
$html = '<html><body><table><tr><th>Name</th><th>Mobile No.</th></tr><tr><td>Ravi &amp; Co</td><td>9111111111</td></tr></table></body></html>';
same("Name,\"Mobile No.\"\n\"Ravi & Co\",9111111111\n", Sheet::toCsv($html, 'responses.xls'), 'an HTML-table .xls reads as CSV');

// ------------------------------------------------ offer, then source report
$c = $r->addCandidate(['name' => 'Offer Person', 'job_id' => (string) $req['id'], 'source' => 'indeed'])['candidate'];
ok(!$r->recordOffer((string) $c['id'], ['offered_ctc' => '200000', 'offer_doj' => '2026-11-01'])['ok'], 'an offer is made at the Offer stage only');
foreach (['screening', 'interview', 'offer'] as $to) { $r->moveStage((string) $c['id'], $to); }
ok(!$r->recordOffer((string) $c['id'], ['offered_ctc' => 'two lakh', 'offer_doj' => '2026-11-01'])['ok'], 'the offered CTC is an amount');
ok($r->recordOffer((string) $c['id'], ['offered_ctc' => '200000', 'offer_doj' => '2026-11-01'])['ok'], 'an offer is recorded');
same('sent', (string) $r->candidate((string) $c['id'])['offer_status'], 'offer status: sent');

$rep = $r->sourceReport();
same(2, $rep['naukri']['total'] ?? null, 'source report: Naukri brought two');
same(1, $rep['indeed']['offer'] ?? null, 'source report: one Indeed candidate at offer');
