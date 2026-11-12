<?php
/**
 * ORGANIZATION CONFIGURATION → OFF-ROLL AGENT PAYOUTS — save (23 Oct 2026). Every value is checked against the
 * declared choices; every save is a new Customisation version.
 */
declare(strict_types=1);

include __DIR__ . '/../partials/offroll-boot.php';

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    $opBack('?r=offroll-settings');
}

$chk = $op->checkSettings($_POST);

if (!$chk['ok']) {
    $_SESSION['flash_problem'] = $chk['why'];
    $opBack('?r=offroll-settings');
}

$res = $customisation->saveRaw($op->key(), $chk['clean'], (string) ($session->user()['id'] ?? ''), (string) $session->user()['email'], 'Off-roll Agent Payouts saved');
$res['ok'] && $audit('offroll.settings.saved', 'customisation', $op->key(), $opSet, $chk['clean'], 'Off-roll Agent Payouts');
$_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['ok'] ? 'Off-roll Agent Payouts saved (version ' . $res['version'] . '). Payouts generated from now follow it; approved ones keep theirs.' : $res['why'];
$opBack('?r=offroll-settings');
