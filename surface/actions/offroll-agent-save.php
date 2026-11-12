<?php
/**
 * OFF-ROLL AGENT KYC: contact / bank / UPI (op=contact), a document into its
 * slot (op=upload), the email marked verified (op=verify-email — the
 * verification mail itself goes out when a transport is declared; marking is
 * HR's confirmation, on the trail).
 */
declare(strict_types=1);

use SmartPRS\Surface\OffrollAgents;

$oaBack = '?r=' . (post('back') === 'offroll-agent' ? 'offroll-agent' : 'offroll-kyc') . '&id=' . rawurlencode(post('id')) . (post('month') !== '' ? '&month=' . rawurlencode(post('month')) : '');

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $oaBack);
    exit;
}

$oa = new OffrollAgents($store, __DIR__ . '/../../config/offroll-agents.json');
$oaAgent = $oa->find(post('id'));

if ($oaAgent === null) {
    $_SESSION['flash_problem'] = 'No such off-roll agent.';
    header('Location: ?r=offroll-kyc');
    exit;
}

$oaOp = post('op');

if ($oaOp === 'upload') {
    $slot = post('slot');
    $files = employeeFileValues([['code' => $slot, 'label' => (string) ($oa->declared()['documents'][$slot] ?? $slot), 'type' => 'Image/PDF/Doc', 'storage' => 'extra']],
        'offroll-' . (string) $oaAgent['id'], $tenantId, '');
    $path = (string) ($files['extras'][$slot] ?? '');
    $result = $files['problems'] !== [] ? ['ok' => false, 'why' => implode(' ', $files['problems'])]
        : ($path === '' ? ['ok' => false, 'why' => 'Choose a file to upload.'] : $oa->attach($oaAgent, $slot, $path));
} elseif ($oaOp === 'verify-email') {
    $store->put(OffrollAgents::AGENTS, ['id' => $oaAgent['id'], 'email_verified_at' => date('Y-m-d H:i:s')]);
    $result = ['ok' => true, 'why' => (string) $oaAgent['email'] . ' marked verified for ' . (string) $oaAgent['name'] . '.'];
} else {
    $result = $oa->saveContact($oaAgent, $_POST);
}

if ($result['ok']) {
    $audit('offroll.' . $oaOp, 'offroll_agent', (string) $oaAgent['id'], null, array_diff_key($_POST, ['csrf' => 1, 'action' => 1]), $result['why']);
    $_SESSION['flash_notice'] = $result['why'];
} else {
    $_SESSION['flash_problem'] = $result['why'];
}

header('Location: ' . $oaBack);
exit;
