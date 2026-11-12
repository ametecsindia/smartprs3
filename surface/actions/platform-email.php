<?php
/** PLATFORM EMAIL (SMTP): save the platform mailbox, or send a test through it. Super Admin console only. */
declare(strict_types=1);

$pmSay = static function (bool $ok, string $msg): never {
    $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ?r=platform-email');
    exit;
};

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $pmSay(false, 'The platform mailbox is managed from the Super Admin console.');
}

$pmMail = new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');

if (post('op') === 'save') {
    $pmMail->save($_POST);
    $audit('platform.smtp_saved', 'platform_settings', 'smtp', null, ['host' => post('host'), 'from' => post('from_email')], 'platform staff');
    $pmSay(true, 'Platform mailbox saved.');
}

if (post('op') === 'test') {
    $to = strtolower(trim(post('to')));

    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $pmSay(false, 'Give a valid address to send the test to.');
    }

    $r = $pmMail->send([$to], 'SmartPRS platform mailbox — test', 'This is a test from the SmartPRS Super Admin console. If you can read it, the platform mailbox works.');
    $pmSay($r['ok'], $r['ok'] ? $r['why'] : 'Not sent: ' . $r['why']);
}

$pmSay(false, 'Nothing to do.');
