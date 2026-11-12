<?php declare(strict_types=1);
/**
 * APPROVALS INBOX — everything waiting on the signed-in person: workflow
 * requests they may decide, leave requests (when they hold leave.request.decide),
 * pending transfers (employee.update) — plus what they raised themselves.
 * Included by the `inbox` route block and by surface/actions/people-export.php.
 * Sets $ibMine, $ibRaisedByMe, $ibLeaves, $ibTransfers, $ibTypes, $ibType and $export.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Leave;
use SmartPRS\Surface\Transfers;

$ibMe = (string) ($session->user()['id'] ?? '');
// Administrator decides its own (D-OWN-01), so its own items wait on it like any other.
$ibSelfOk = $access->maySelfApprove((string) ($session->user()['role'] ?? ''));
$ibMine = [];
$ibRaisedByMe = [];
$ibType = trim((string) ($params['type'] ?? ''));

foreach ($requests->open() as $r) {
    // The requester's own request is shown SEPARATELY: it is not waiting on them.
    if (!$ibSelfOk && (string) $r['raised_by_id'] === $ibMe) {
        $ibRaisedByMe[] = $r;

        continue;
    }

    // AND ONLY WHAT YOU MAY READ — an inbox of dead links names subjects and amounts.
    $wf = $requests->workflow((string) $r['workflow']);
    $gate = $wf === null ? ['permission' => null] : $requests->permissionFor($wf, $modules);

    if ($gate['permission'] !== null && $may($gate['permission'])) {
        $r['wf_label'] = (string) ($wf['label'] ?? $r['workflow']);
        $ibMine[] = $r;
    }
}

usort($ibMine, static fn (array $a, array $b): int => strcmp((string) ($b['raised_at'] ?? $b['created_at'] ?? ''), (string) ($a['raised_at'] ?? $a['created_at'] ?? '')));
$ibPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $ibPeople[(string) $e['id']] = $e;
}

$ibLeaves = [];

if ($may('leave.request.decide')) {
    $ibTypeNames = array_column(liveRows($store, 'leave_types'), 'name', 'id');

    foreach ($store->all('leaves') as $lv) {
        if ((string) ($lv['status'] ?? '') === Leave::PENDING && isset($ibPeople[(string) ($lv['employee_id'] ?? '')])) {
            $lv['employee'] = $ibPeople[(string) $lv['employee_id']];
            $lv['type_name'] = (string) ($ibTypeNames[(string) ($lv['type_id'] ?? '')] ?? ('type #' . (string) ($lv['type_id'] ?? '')));
            $ibLeaves[] = $lv;
        }
    }

    usort($ibLeaves, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
}

$ibTransfers = [];

if ($may('employee.update')) {
    foreach ($transfers->all(Transfers::PENDING) as $t) {
        if (isset($ibPeople[(string) $t['employee_id']]) && ($ibSelfOk || !str_contains((string) ($t['remarks'] ?? ''), 'raised by ' . (string) $session->user()['email']))) {
            $t['employee'] = $ibPeople[(string) $t['employee_id']];
            $ibTransfers[] = $t;
        }
    }
}

// MANPOWER REQUISITIONS waiting on this person's step (owner, 12 Oct): the department's
// Director / the branch's Branch Admin, then HR Admin; a final approver at any step.
$ibRequisitions = array_values(array_filter($recruitment->requisitions(), static fn (array $rq): bool =>
    $recruitment->waitingOn($rq) !== null && $recruitment->mayDecide($rq, $requisitionDecider())));

$ibTypes = ['request' => 'Requests', 'leave' => 'Leave', 'transfer' => 'Transfers', 'requisition' => 'Requisitions'];
$ibType = isset($ibTypes[$ibType]) ? $ibType : '';

$export = ['title' => 'Approvals Inbox', 'filter' => $ibType !== '' ? ['type' => $ibType] : [], 'columns' => ['TYPE', 'WHAT', 'WHO', 'DETAIL', 'AMOUNT / DAYS', 'WAITING AT', 'RAISED'], 'rows' => []];

if ($ibType === '' || $ibType === 'request') {
    foreach ($ibMine as $r) {
        $wf = $requests->workflow((string) $r['workflow']);
        $step = '';

        foreach ((array) ($wf['steps'] ?? []) as $st) {
            (string) $st['key'] === (string) $r['at_step'] && $step = (string) $st['label'];
        }

        $export['rows'][] = ['Request', (string) $r['wf_label'], (string) $r['raised_by'], (string) $r['note'], (string) $r['amount'], $step, substr((string) ($r['raised_at'] ?? $r['created_at'] ?? ''), 0, 16)];
    }
}

if ($ibType === '' || $ibType === 'leave') {
    foreach ($ibLeaves as $lv) {
        $export['rows'][] = ['Leave', (string) $lv['type_name'], Employment::label($lv['employee']), (string) ($lv['reason'] ?? '') . ' (' . (string) $lv['from_date'] . ' to ' . (string) $lv['to_date'] . ')', str_contains((string) ($lv['days'] ?? ''), '.') ? rtrim(rtrim((string) $lv['days'], '0'), '.') : (string) ($lv['days'] ?? ''), 'Leave approver', substr((string) ($lv['created_at'] ?? ''), 0, 16)];
    }
}

if ($ibType === '' || $ibType === 'requisition') {
    foreach ($ibRequisitions as $rq) {
        $export['rows'][] = ['Requisition', (string) $rq['req_code'] . ' ' . (string) $rq['title'], (string) ($rq['requested_by'] ?? ''), trim((string) ($rq['department'] ?? '') . ' · ' . (string) ($rq['location'] ?? ''), ' ·'), (string) $rq['openings'], (string) $recruitment->waitingOn($rq), substr((string) ($rq['created_at'] ?? ''), 0, 16)];
    }
}

if ($ibType === '' || $ibType === 'transfer') {
    foreach ($ibTransfers as $t) {
        $export['rows'][] = ['Transfer', ucfirst((string) ($t['type'] ?? '')) . ' transfer', Employment::label($t['employee']), 'to ' . implode(', ', array_filter([(string) ($t['to_company'] ?? ''), (string) ($t['to_branch'] ?? ''), (string) ($t['to_department'] ?? '')])) . ' from ' . (string) $t['effective_date'] . ' — ' . (string) $t['reason'], '', 'Approver', substr((string) ($t['created_at'] ?? ''), 0, 16)];
    }
}
