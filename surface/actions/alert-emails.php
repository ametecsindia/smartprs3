<?php
/**
 * ALERT EMAIL DRAFTS — save the mail server, send a test, save / reset one
 * alert's draft and settings, or run today's alerts now.
 */
declare(strict_types=1);

use SmartPRS\Surface\AlertEmails;

require_once __DIR__ . '/../partials/alert-emails-run.php';

$aeStep = post('step');
$aeBack = '?r=alert-emails' . (post('alert') !== '' ? '#ae-' . rawurlencode(post('alert')) : '');
$aeDone = static function (array $r) use ($aeBack): never {
    $_SESSION[$r['ok'] ? 'flash_notice' : 'flash_problem'] = $r['why'];
    header('Location: ' . $aeBack);
    exit;
};

if (!$may('tenant.settings.update')) {
    $aeDone(['ok' => false, 'why' => 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary]);
}

$aeAlerts = new AlertEmails($store, $customisation, __DIR__ . '/../../config/alert-emails.json');
$aeById = (string) ($session->user()['id'] ?? '');
$aeBy = (string) ($session->user()['email'] ?? '');

if ($aeStep === 'smtp') {
    $r = $aeAlerts->saveSmtp($_POST, $aeById, $aeBy);
    $r['ok'] && $audit('alert_emails.smtp_saved', 'customisation', 'alert-emails-smtp', null, array_diff_key($_POST, ['password' => 1, 'csrf' => 1]), $r['why']);
    $aeDone($r);
}

if ($aeStep === 'test') {
    $to = trim(post('test_to'));
    filter_var($to, FILTER_VALIDATE_EMAIL) === false && $aeDone(['ok' => false, 'why' => 'Give an email address to send the test to.']);
    $r = $aeAlerts->send([$to], [], 'SmartPRS test email', "This is a test email from SmartPRS Alert Email Drafts.\n\nIf you can read this, automatic alert emails will reach your people.");
    $aeDone(['ok' => $r['ok'], 'why' => $r['ok'] ? 'Test email ' . $r['why'] . '.' : 'Test email NOT sent: ' . $r['why']]);
}

if ($aeStep === 'save') {
    $r = $aeAlerts->saveAlert(post('alert'), $_POST, $access->roles(), $aeById, $aeBy);
    $r['ok'] && $audit('alert_emails.saved', 'customisation', post('alert'), null, array_diff_key($_POST, ['csrf' => 1]), $r['why']);
    $aeDone($r);
}

if ($aeStep === 'reset') {
    $aeDone($aeAlerts->resetAlert(post('alert'), $aeById, $aeBy));
}

if ($aeStep === 'run') {
    $r = alertEmailsRun($store, $aeAlerts, $probation, $users->all(), date('Y-m-d'), $absentOn);
    $aeDone(['ok' => $r['failed'] === 0, 'why' => "Today's alerts: {$r['due']} due, {$r['sent']} sent, {$r['skipped']} already sent earlier, {$r['failed']} failed."
        . ($r['problems'] === [] ? '' : ' ' . implode(' · ', array_slice($r['problems'], 0, 10)))]);
}

$aeDone(['ok' => false, 'why' => 'Unknown step.']);
