<?php
/**
 * OFF-ROLL AGENT EARNINGS: record an entry (op=record, employee.update — HR)
 * or approve / reject one (op=decide, payroll.run — the approver). The amount
 * is priced by surface/OffrollAgents.php from the payout type and rate; the
 * recorder cannot approve their own entry.
 */
declare(strict_types=1);

use SmartPRS\Surface\OffrollAgents;

$oeBack = '?r=' . (post('back') === 'offroll-agent' ? 'offroll-agent' : 'offroll-kyc') . '&id=' . rawurlencode(post('id')) . (post('month') !== '' ? '&month=' . rawurlencode(post('month')) : '');
$oeOp = post('op') === 'decide' ? 'decide' : 'record';
$oePermission = $oeOp === 'decide' ? 'payroll.run' : 'employee.update';

if (!$may($oePermission)) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $oePermission)->summary;
    header('Location: ' . $oeBack);
    exit;
}

$oa = new OffrollAgents($store, __DIR__ . '/../../config/offroll-agents.json');
$oeAgent = $oa->find(post('id'));

if ($oeAgent === null) {
    $_SESSION['flash_problem'] = 'No such off-roll agent.';
    header('Location: ?r=offroll-kyc');
    exit;
}

$oeBy = (string) $session->user()['email'];
$result = $oeOp === 'decide'
    ? $oa->decide(post('entry'), post('decision') === 'approve', $oeBy, post('remarks'), isAdministrator())
    : $oa->record($oeAgent, $_POST, $oeBy);

if ($result['ok']) {
    $audit('offroll.earning.' . ($oeOp === 'decide' ? (post('decision') === 'approve' ? 'approved' : 'rejected') : 'recorded'), 'agent_earning',
        $oeOp === 'decide' ? post('entry') : (string) ($result['row']['id'] ?? ''), null, array_diff_key($_POST, ['csrf' => 1, 'action' => 1]), $result['why']);
    $_SESSION['flash_notice'] = $result['why'];
} else {
    $_SESSION['flash_problem'] = $result['why'];
}

header('Location: ' . $oeBack);
exit;
