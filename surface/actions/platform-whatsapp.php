<?php
/** WHATSAPP API (INTERAKT): save the platform key, or send one test template. Super Admin console only. */
declare(strict_types=1);

$waSay = static function (bool $ok, string $msg): never {
    $_SESSION[$ok ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ?r=platform-whatsapp');
    exit;
};

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $waSay(false, 'The platform WhatsApp key is managed from the Super Admin console.');
}

$waApi = new SmartPRS\Surface\WhatsApp($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');

if (post('op') === 'save') {
    $waApi->save($_POST);
    $audit('platform.whatsapp_saved', 'platform_settings', 'whatsapp', null, ['url' => post('url'), 'enabled' => post('enabled')], 'platform staff');
    $waSay(true, 'WhatsApp (Interakt) settings saved.');
}

if (post('op') === 'test') {
    $values = array_values(array_filter(array_map('trim', explode(',', post('values'))), static fn (string $v): bool => $v !== ''));
    $r = $waApi->send(post('mobile'), trim(post('template')), $values, 'test');
    $waSay($r['ok'], $r['ok'] ? $r['why'] : 'Not sent: ' . $r['why']);
}

$waSay(false, 'Nothing to do.');
