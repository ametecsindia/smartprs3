<?php

declare(strict_types=1);

use SmartPRS\Surface\ApprovalAuthority;
use SmartPRS\Surface\Recruitment;
use SmartPRS\Surface\Requests;
use SmartPRS\Surface\Store;

/**
 * REQUISITION APPROVAL (owner, 12 Oct): raised → the department's Director or
 * the Branch Admin → HR Admin. A Company Admin or HR Admin approval overrides
 * every other approval: even with the earlier steps not yet done, it is
 * approved and moves on. Same rule for every multi-step approval (Requests).
 */
group('requisition approval');

$root = dirname(__DIR__);
$authority = new ApprovalAuthority($root . '/config/approval-authority.json');
ok($authority->isFinal('Company Admin') && $authority->isFinal('hr manager') && $authority->isFinal('HR Admin') && $authority->isFinal('administrator'),
    'Company Admin, HR Admin / HR Manager and the administrator are final approvers (case-insensitive)');
ok(!$authority->isFinal('Director') && !$authority->isFinal('Branch Admin') && !$authority->isFinal(''), 'a Director or Branch Admin is not');

$store = (new Store(sys_get_temp_dir() . '/prs3-reqap-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => '7', 'name' => 'Ametecs India Pvt Ltd']);
$r = new Recruitment($store, $root . '/config/recruitment.json', $authority);
$raise = static fn (): array => $r->raise(['title' => 'Field Agent', 'openings' => 2, 'company' => 'Ametecs India Pvt Ltd',
    'department' => 'Collections', 'location' => 'KONDAPUR', 'justification' => "Two agents left in September.\nVolumes up 30%."], 'hr@x')['requisition'];

$req = $raise();
same('7', (string) $req['employer_company_id'], 'the company picked is stored on the requisition');
same("Two agents left in September.\nVolumes up 30%.", $req['justification'], 'a justification keeps its line breaks');
same('pending', $req['approval_status'], 'it waits on the department step');
same('Department Director / Branch Admin', $r->waitingOn($req), 'and says who it waits on');

$director = ['role' => 'Director', 'department' => 'Collections', 'branch' => 'MINDSPACE'];
$otherDirector = ['role' => 'Director', 'department' => 'Sales', 'branch' => 'KONDAPUR'];
$branchAdmin = ['role' => 'Branch Admin', 'department' => '', 'branch' => 'KONDAPUR'];
$otherBranch = ['role' => 'Branch Admin', 'department' => '', 'branch' => 'MINDSPACE'];
$hr = ['role' => 'HR Manager', 'department' => '', 'branch' => ''];
$companyAdmin = ['role' => 'Company Admin', 'department' => '', 'branch' => ''];
$teamLead = ['role' => 'Team Lead', 'department' => 'Collections', 'branch' => 'KONDAPUR'];

ok(!$r->mayDecide($req, $otherDirector), 'another department\'s Director cannot decide it');
ok(!$r->mayDecide($req, $otherBranch), 'nor another branch\'s Branch Admin');
ok(!$r->mayDecide($req, $teamLead), 'nor a Team Lead');
ok(!$r->decideRequisition((string) $req['id'], true, 'tl@x', '', $teamLead)['ok'], 'and the engine refuses them, not only the screen');
ok($r->mayDecide($req, $director) && $r->mayDecide($req, $branchAdmin), 'the department\'s Director and the branch\'s Branch Admin can');

ok($r->decideRequisition((string) $req['id'], true, 'dir@x', '', $director)['ok'], 'the Director approves the department step');
$req = $r->requisition((string) $req['id']);
same('pending_hr', $req['approval_status'], 'it then waits on HR');
same('HR Admin', $r->waitingOn($req), 'and says so');
ok(!$r->mayDecide($req, $branchAdmin), 'the department step is done: the Branch Admin cannot decide the HR step');
ok(!$r->addCandidate(['name' => 'Ravi', 'job_id' => (string) $req['id']])['ok'], 'still nobody can be linked to it');
ok($r->decideRequisition((string) $req['id'], true, 'hr@x', '', $hr)['ok'], 'HR approves');
$req = $r->requisition((string) $req['id']);
same('approved', $req['approval_status'], 'approved');
ok(str_contains((string) $req['approved_by'], 'dir@x') && str_contains((string) $req['approved_by'], 'hr@x'), 'both approvers are on record: ' . $req['approved_by']);

// THE OVERRIDE: HR Admin or Company Admin approves with the department step not done.
foreach (['HR Admin' => $hr, 'Company Admin' => $companyAdmin] as $who => $decider) {
    $q = $raise();
    ok($r->mayDecide($q, $decider), "{$who} may decide at the department step");
    ok($r->decideRequisition((string) $q['id'], true, 'boss@x', '', $decider)['ok'], "{$who} approves straight away");
    same('approved', $r->requisition((string) $q['id'])['approval_status'], "{$who}'s approval is final — the department step is not waited for");
    ok($r->addCandidate(['name' => 'Ravi ' . $who, 'job_id' => (string) $q['id']])['ok'], 'and candidates can be added at once');
}

// A REJECTION AT ANY STEP CLOSES IT.
$q = $raise();
ok(!$r->decideRequisition((string) $q['id'], false, 'ba@x', '', $branchAdmin)['ok'], 'a rejection needs a reason');
ok($r->decideRequisition((string) $q['id'], false, 'ba@x', 'No budget', $branchAdmin)['ok'], 'the Branch Admin rejects');
same(['rejected', 'closed'], [$r->requisition((string) $q['id'])['approval_status'], $r->requisition((string) $q['id'])['status']], 'rejected and closed');

// A requisition with no department / location: any Director or Branch Admin may take the department step.
$q = $r->raise(['title' => 'Analyst', 'openings' => 1], 'hr@x')['requisition'];
ok($r->mayDecide($q, $otherDirector), 'with no department named, any Director may decide the department step');

// THE SAME OVERRIDE ON WORKFLOW REQUESTS: an expense claim of ₹60,000 is manager → finance → director.
$rqStore = (new Store(sys_get_temp_dir() . '/prs3-reqov-' . bin2hex(random_bytes(4))))->forTenant('1');
$requests = new Requests($rqStore, $root . '/config/workflows.json');
$raised = $requests->raise('expense-claim', '9', '60000', 'Client visit', '5', 'emp@x');
ok($raised['ok'], 'a ₹60,000 claim is raised: ' . $raised['why']);
$rid = (string) $raised['request']['id'];
$step = $requests->decide($rid, true, 'ok', '11', 'mgr@x');
same('open', (string) $step['request']['state'], 'the manager\'s approval moves it on, not closes it');
$final = $requests->decide($rid, true, 'ok', '12', 'hr@x', false, true);
ok($final['ok'], 'HR Admin approves at the finance step');
same('approved', (string) $final['request']['state'], 'and that closes it: the director step is not waited for');

// THE ROLE IS A DESIGNATION FROM ORGANIZATION CONFIGURATION (owner, 12 Oct): never a typed title.
$dsStore = (new Store(sys_get_temp_dir() . '/prs3-reqds-' . bin2hex(random_bytes(4))))->forTenant('1');
$dsStore->put('designations', ['id' => '3', 'name' => 'Field Agent']);
$ds = new Recruitment($dsStore, $root . '/config/recruitment.json', $authority);
ok(!$ds->raise(['title' => 'Feild agent', 'openings' => 1], 'hr@x')['ok'], 'a title that is not a designation in Organization Configuration is refused');
same('Field Agent', $ds->raise(['title' => 'field agent', 'openings' => 1], 'hr@x')['requisition']['title'] ?? null,
    'a designation is accepted and stored with its configured spelling');
