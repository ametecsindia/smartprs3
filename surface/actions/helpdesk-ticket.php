<?php
/**
 * HR HELPDESK WRITES: raise (the signed-in employee's own ticket), work
 * (HR sets priority / status / assignee / reply), resolve (HR, one click).
 * Statuses, priorities and what counts as closed are config/helpdesk.json.
 */
declare(strict_types=1);

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : post('f_' . $k);
}

$back = '?r=helpdesk' . orgFilterQuery($filter);
$fail = static function (string $why) use ($back): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $back);
    exit;
};

if (!$may('employee.view')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary);
}

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/helpdesk.json'), true);
$statuses = array_map('strval', array_keys((array) ($cfg['statuses'] ?? [])));
$priorities = array_map('strval', array_keys((array) ($cfg['priorities'] ?? [])));
$closed = array_map('strval', (array) ($cfg['closed_statuses'] ?? []));
$step = post('step');
$now = date('Y-m-d H:i:s');
$user = $session->user();

if ($step === 'raise') {
    $own = $scope->own();
    $own === null && $fail('Your account is not linked to an employee, so a ticket cannot be raised in your name.');
    post('subject') === '' && $fail('A ticket needs a subject.');
    post('description') === '' && $fail('Describe the issue — a ticket with no description is one HR cannot act on.');
    $employee = $store->find('employees', (string) $own);
    $row = $store->put('helpdesk', [
        'employee_id' => (string) $own,
        'employer_company_id' => trim((string) ($employee['employer_company_id'] ?? '')) === '' ? null : (string) $employee['employer_company_id'], // an integer column: blank is NULL, never ''
        'category' => in_array(post('category'), array_map('strval', (array) ($cfg['categories'] ?? [])), true) ? post('category') : '',
        'subject' => post('subject'),
        'description' => post('description'),
        'priority' => (string) ($cfg['default_priority'] ?? ''),
        'status' => (string) ($cfg['default_status'] ?? ''),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $audit('helpdesk.raised', 'helpdesk', (string) $row['id'], null, $row, 'Ticket raised');
    $_SESSION['flash_notice'] = 'Ticket #' . (string) $row['id'] . ' raised. HR will reply here.';
    header('Location: ' . $back . '&t=' . rawurlencode((string) $row['id']));
    exit;
}

if (!$may('employee.update')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary);
}

$ticket = post('id') === '' ? null : $store->find('helpdesk', post('id'));
$ticket === null && $fail('No such ticket.');
$scope->covers((string) ($ticket['employee_id'] ?? ''), $may('employee.list')) || $fail('That ticket is outside your reach.');
$before = $ticket;

if ($step === 'work') {
    in_array(post('status'), $statuses, true) || $fail('Pick a status from the list.');
    in_array(post('priority'), $priorities, true) || $fail('Pick a priority from the list.');
    post('assigned_to') !== '' && $store->find('employees', post('assigned_to')) === null && $fail('No such employee to assign to.');
    $ticket['status'] = post('status');
    $ticket['priority'] = post('priority');
    $ticket['assigned_to'] = post('assigned_to') === '' ? null : post('assigned_to');
    $ticket['hr_comment'] = post('hr_comment');
} elseif ($step === 'resolve') {
    $ticket['status'] = $closed[0] ?? $fail('config/helpdesk.json names no closed status.');
} else {
    $fail('Unknown step.');
}

if (in_array((string) $ticket['status'], $closed, true) && trim((string) ($ticket['resolved_at'] ?? '')) === '') {
    $ticket['resolved_at'] = $now;
} elseif (!in_array((string) $ticket['status'], $closed, true)) {
    $ticket['resolved_at'] = null; // reopened
}

$ticket['updated_at'] = $now;
$stored = $store->put('helpdesk', $ticket);
$audit('helpdesk.' . $step, 'helpdesk', (string) $stored['id'], $before, $stored, 'By ' . (string) ($user['email'] ?? ''));
$_SESSION['flash_notice'] = 'Ticket #' . (string) $stored['id'] . ' ' . ($step === 'resolve' ? 'resolved' : 'updated') . '.';
header('Location: ' . $back . '&t=' . rawurlencode((string) $stored['id']));
exit;
