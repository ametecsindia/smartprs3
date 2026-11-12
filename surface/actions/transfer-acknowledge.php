<?php
/**
 * THE EMPLOYEE ACKNOWLEDGES A TRANSFER ORDER. SmartPRS 2 does this from a
 * token link in the order email; here the same token (`t`) or the signed-in
 * employee's own row unlocks it. An acknowledgement is a receipt, not an
 * approval: the transfer still applies on its effective date.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;

$ackId = post('id');
$ackToken = post('t');
$ackRow = $transfers->find($ackId);
$ackBack = 'transfers';

if ($ackRow === null) {
    $_SESSION['flash_problem'] = 'No such transfer.';
    header('Location: ?r=' . $ackBack);
    exit;
}

$ackOwn = (string) ($session->user()['employee_id'] ?? '') !== '' && (string) $session->user()['employee_id'] === (string) $ackRow['employee_id'];
$ackByToken = $ackToken !== '' && hash_equals((string) ($ackRow['accept_token'] ?? ''), $ackToken);
// HR may record an acknowledgement handed over on paper — with the paper named.
$ackByHr = $may('employee.update') && $scope->covers((string) $ackRow['employee_id'], $may('employee.list')) && trim(post('remarks')) !== '';

if (!$ackOwn && !$ackByToken && !$ackByHr) {
    $_SESSION['flash_problem'] = 'Only the employee being moved acknowledges a transfer order (or HR, naming the signed copy in remarks).';
    header('Location: ?r=' . $ackBack);
    exit;
}

$ackEmp = $store->find('employees', (string) $ackRow['employee_id']);
$ackBy = $ackOwn || $ackByToken ? ($ackEmp !== null ? Employment::label($ackEmp) : 'employee') : (string) $session->user()['email'] . ' (' . trim(post('remarks')) . ')';
$result = $transfers->acknowledge($ackId, $ackBy, date('Y-m-d H:i:s'));

if ($result['ok']) {
    $audit('employment.transfer_acknowledged', 'transfer', $ackId, null, ['by' => $ackBy], $result['why']);

    try {
        $notifications->queue('transfer.acknowledged', 'hr', 'transfer.acknowledged:' . $ackId, ['name' => trim((string) ($ackEmp['name'] ?? '')),
            'code' => $ackEmp !== null ? Employment::code($ackEmp) : '', 'effective' => (string) ($ackRow['effective_date'] ?? ''), 'when' => date('Y-m-d H:i')]);
    } catch (\InvalidArgumentException) { // gate:allow-swallow config/notifications.d/people.json does not declare the event on this installation: the acknowledgement stands, the note does not
    }

    $_SESSION['flash_notice'] = $result['why'];
} else {
    $_SESSION['flash_problem'] = $result['why'];
}

header('Location: ?r=' . $ackBack);
exit;
