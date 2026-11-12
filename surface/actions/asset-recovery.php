<?php
/**
 * SETTLE A DAMAGED/LOST RETURN — final status, recovery amount, recovery
 * status — from the Exit & FnF screen (or Asset Issue & Returns). Until it is
 * settled the leaver's exit and FnF stay on hold (Assets::exitBlockers()).
 */
declare(strict_types=1);

use SmartPRS\Surface\Assets;

$back = preg_match('/^\d{1,12}$/', post('exit')) === 1 ? '?r=exits&id=' . post('exit') : '?r=asset-issue';

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');
$row = post('id') === '' ? null : $store->find((string) $assets->declared()['table'], post('id'));

if ($row === null) {
    $result = ['ok' => false, 'why' => 'No such asset.'];
} else {
    $result = $assets->settleRecovery($row, ['damage_resolution' => post('damage_resolution'), 'recovery_amount' => post('recovery_amount'),
        'recovery_status' => post('recovery_status')], (string) $session->user()['email'], date('Y-m-d H:i:s'));

    if ($result['ok']) {
        $audit('asset.recovery', 'assets', (string) $row['id'],
            ['damage_resolution' => (string) ($row['damage_resolution'] ?? ''), 'recovery_amount' => (string) ($row['recovery_amount'] ?? ''), 'recovery_status' => (string) ($row['recovery_status'] ?? '')],
            ['damage_resolution' => post('damage_resolution'), 'recovery_amount' => post('recovery_amount'), 'recovery_status' => post('recovery_status')],
            'Recovery for a damaged/lost return');
    }
}

$_SESSION[$result['ok'] ? 'flash_notice' : 'flash_problem'] = $result['why'];
header('Location: ' . $back);
exit;
