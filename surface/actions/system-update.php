<?php
/**
 * CHECK FOR UPDATE / DOWNLOAD & INSTALL on an on-prem server (owner, 12 Nov 2026) — Administration → Updates & Licence.
 * Check asks central (/update/check) for the newest release sent to this licence; Install downloads it
 * (/update/download), verifies checksum + Ametecs signature, installs it (surface/Updater.php) and reports back.
 * The cloud is updated from Super Admin → Releases → Install on cloud server, never from a company's screen.
 */
declare(strict_types=1);

$suBack = static function (): never { header('Location: ?r=sys-updates'); exit; };
$suLicence = new SmartPRS\Surface\Licence(dirname(__DIR__, 2));

if (!$suLicence->isOnPrem()) {
    $_SESSION['flash_problem'] = 'Cloud workspaces are updated by Ametecs for every company at once.';
    $suBack();
}

$suUpdater = new SmartPRS\Surface\Updater(dirname(__DIR__, 2));
$suOp = post('op');

if ($suOp === 'check' && $may('tenant.settings.view')) {
    $res = $suLicence->checkUpdate($suUpdater->runningVersion());
    $_SESSION['update_check'] = $res + ['checked_at' => date('d-m-Y H:i')];
    $suBack();
}

if ($suOp !== 'install' || !$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: installing an update needs Organization settings — update.';
    $suBack();
}

$suVersion = post('version');
$suOffer = (array) ($_SESSION['update_check'] ?? []);

if (($suOffer['version'] ?? '') !== $suVersion || empty($suOffer['update_available'])) {
    $_SESSION['flash_problem'] = 'Check for update again first.';
    $suBack();
}

session_write_close();
$suZip = $suUpdater->updatesDir() . '/' . SmartPRS\Surface\Updater::PACKAGE_PREFIX . $suVersion . '.zip';
$suErr = $suLicence->downloadUpdate($suVersion, $suZip);
$suRes = null;

if ($suErr === '') {
    $chk = SmartPRS\Surface\Updater::verify($suZip, (string) ($suOffer['package_hash'] ?? ''), (string) ($suOffer['signature'] ?? ''));
    $chk['ok'] ? $suRes = $suUpdater->install($suZip, (string) $session->user()['email']) : $suErr = $chk['error'];
}

session_start();
unset($_SESSION['update_check']);

if ($suRes !== null && $suRes['ok']) {
    $suLicence->reportUpdate($suVersion, 'applied', 'from ' . $suRes['from']);
    $_SESSION['flash_notice'] = $suRes['message'];
    $_SESSION['update_log'] = $suRes['log'];
} else {
    $why = $suRes['message'] ?? $suErr;
    $suLicence->reportUpdate($suVersion, 'failed', $why);
    $_SESSION['flash_problem'] = 'Update not installed: ' . $why;
    $_SESSION['update_log'] = (string) ($suRes['log'] ?? '');
}

$suBack();
