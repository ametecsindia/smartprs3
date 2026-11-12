<?php
/**
 * COMPLAINT WRITES (owner, 13 Oct): raise (the signed-in employee's own
 * complaint) and respond (HR / Admin: a status and a reply, appended to
 * `complaint_responses`). Nobody adds a complaint for someone else.
 * Vocabulary is config/complaints.json.
 */
declare(strict_types=1);

// Back to the same list: only the filter, period and status keys survive.
parse_str(post('back_q'), $backQ);
$backQ = array_intersect_key((array) $backQ, ORG_FILTERS + PERIOD_KEYS + ['st' => 1, 'sel' => 1]);
$back = '?r=complaints' . ($backQ === [] ? '' : '&' . http_build_query($backQ));
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

if (!$may('employee.view')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary);
}

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/complaints.json'), true);
$statuses = array_map('strval', (array) ($cfg['statuses'] ?? []));
$step = post('step');
$now = date('Y-m-d H:i:s');
$user = $session->user();

if ($step === 'raise') {
    $own = $scope->own();
    $own === null && $fail('Your account is not linked to an employee, so a complaint cannot be raised in your name.');
    in_array(post('source'), array_map('strval', (array) ($cfg['categories'] ?? [])), true) || $fail('Pick a category from the list.');
    in_array(post('severity'), array_map('strval', (array) ($cfg['severities'] ?? [])), true) || $fail('Pick a severity from the list.');
    $date = post('date');
    (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date <= date('Y-m-d')) || $fail('The date of the incident must be a date, today or earlier.');
    trim(post('description')) === '' && $fail('Describe what happened — a complaint with no description is one HR cannot act on.');
    $employee = $store->find('employees', (string) $own);
    $row = $store->put('complaints', [
        'employee_id' => (string) $own,
        'employer_company_id' => trim((string) ($employee['employer_company_id'] ?? '')) === '' ? null : (string) $employee['employer_company_id'], // an integer column: blank is NULL, never ''
        'source' => post('source'),
        'severity' => post('severity'),
        'channel' => (string) ($cfg['channel'] ?? ''),
        'date' => $date,
        'description' => post('description'),
        'status' => (string) ($cfg['default_status'] ?? ''),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $audit('complaint.raised', 'complaints', (string) $row['id'], null, $row, 'Complaint raised');
    $_SESSION['flash_notice'] = 'Complaint #' . (string) $row['id'] . ' raised. HR will reply here.';
    header('Location: ' . $back . '&c=' . rawurlencode((string) $row['id']));
    exit;
}

$step === 'respond' || $fail('Unknown step.');

if (!$may('employee.update')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary);
}

$complaint = post('id') === '' ? null : $store->find('complaints', post('id'));
$complaint === null && $fail('No such complaint.');
$scope->covers((string) ($complaint['employee_id'] ?? ''), $may('employee.list')) || $fail('That complaint is outside your reach.');
in_array(post('status'), $statuses, true) || $fail('Pick a status from the list.');
trim(post('response')) === '' && $fail('Write the reply the employee will read.');
$before = $complaint;
$reply = $complaintReplies->put('complaint_responses', [
    'complaint_id' => (string) $complaint['id'],
    'status' => post('status'),
    'response' => post('response'),
    'responded_by' => (string) ($user['name'] ?? $user['email'] ?? ''),
    'created_at' => $now,
    'updated_at' => $now,
]);
$complaint['status'] = post('status');
$complaint['updated_at'] = $now;
$stored = $store->put('complaints', $complaint);
$audit('complaint.responded', 'complaints', (string) $stored['id'], $before, $stored + ['reply_id' => (string) $reply['id']], 'By ' . (string) ($user['email'] ?? ''));
$_SESSION['flash_notice'] = 'Response saved on complaint #' . (string) $stored['id'] . '.';
header('Location: ' . $back . '&c=' . rawurlencode((string) $stored['id']));
exit;
