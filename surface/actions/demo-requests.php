<?php
/** DEMO REQUESTS: demo days, extend one, resend a passkey, delete one now, the Play reviewer login. Super Admin console only. */
declare(strict_types=1);

$drSay = static function (bool $ok, string $msg): never {
    $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ?r=demo-requests');
    exit;
};

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $drSay(false, 'The demo register is managed from the Super Admin console.');
}

$drGate = new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');

if (post('op') === 'days') {
    $d = (int) post('days');

    if ($d < 1 || $d > $drGate->maxDays()) {
        $drSay(false, 'Days must be 1–' . $drGate->maxDays() . '.');
    }

    $drGate->setDays($d);
    $drSay(true, 'New demo companies will stay for ' . $d . ' day(s).');
}

if (post('op') === 'reviewer') {
    $em = strtolower(trim(post('email')));

    if (filter_var($em, FILTER_VALIDATE_EMAIL) === false) {
        $drSay(false, 'Give a valid login email for the reviewer.');
    }

    $rv = $drGate->setReviewer($em, (string) ($_POST['password'] ?? ''), (string) ($session->user()['id'] ?? 'platform'));
    $rv['ok'] && $audit('platform.demo_reviewer_set', 'tenants', 'DEMO-PLAY', null, ['login' => $em], 'platform staff');
    $drSay($rv['ok'], $rv['why']);
}

$drRow = null;
foreach ($drGate->requests() as $r) {
    if ((string) $r['id'] === post('id')) {
        $drRow = $r;
    }
}

if ($drRow === null || trim((string) ($drRow['wiped_at'] ?? '')) !== '') {
    $drSay(false, 'That demo has already been deleted — the visitor should submit a fresh request.');
}

if (post('op') === 'convert') {
    $em = strtolower(trim(post('email')));
    $cv = filter_var($em, FILTER_VALIDATE_EMAIL) === false ? ['ok' => false, 'why' => 'Give a valid administrator email.']
        : $drGate->convert($pdo, $drRow, post('code'), post('slug'), $em, (string) ($_POST['password'] ?? ''), post('remove_sample') === '1', (string) ($session->user()['id'] ?? 'platform'));
    $cv['ok'] && $audit('platform.demo_converted', 'demo_requests', (string) $drRow['id'], null,
        ['code' => strtoupper(post('code')), 'admin' => $em, 'sample_removed' => post('remove_sample') === '1'], 'platform staff');
    $drSay($cv['ok'], $cv['why']);
}

if ((string) $drRow['status'] === 'converted') {
    $drSay(false, 'That demo is already a client company — manage it under Tenants.');
}

if (post('op') === 'extend') {
    $until = $drGate->extend($drRow, $drGate->days());
    $audit('platform.demo_extended', 'demo_requests', (string) $drRow['id'], ['expires_at' => $drRow['expires_at']], ['expires_at' => $until], 'platform staff');
    $drSay(true, (string) $drRow['name'] . "'s demo now ends " . SmartPRS\Surface\Dates::dmy(substr($until, 0, 10)) . ' ' . substr($until, 11, 5) . '.');
}

if ((string) $drRow['status'] !== 'active') {
    $drSay(false, 'That demo has ended — extend it to open it again.');
}

if (post('op') === 'resend') {
    $ch = $drGate->sendPin($drRow);
    $drSay($ch !== [], $ch !== [] ? 'Passkey resent by ' . implode(' and ', $ch) . '.' : 'Sending failed on both channels — check Platform Email (SMTP) and WhatsApp API (Interakt).');
}

if (post('op') === 'revoke') {
    $platform->put('demo_requests', ['id' => $drRow['id'], 'status' => 'revoked', 'updated_at' => date('Y-m-d H:i:s')]);
    $audit('platform.demo_revoked', 'demo_requests', (string) $drRow['id'], null, null, 'platform staff');
    $drGate->sweep($pdo);
    $drSay(true, 'Demo ended and its demo company deleted — the visitor is signed out on their next page load.');
}

$drSay(false, 'Nothing to do.');
