<?php
/**
 * SWITCH A FEATURE FLAG on or off, globally (no tenant) or for one tenant;
 * `clear` blanks a tenant's override so it follows global again. Only a key
 * declared in config/feature-flags.json can be switched. One row per
 * key+tenant is reused; the trail keeps every flip.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=feature-flags');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$key = post('key');
$tenant = post('tenant_id');
$op = post('op');
$value = $op === 'clear' ? '' : (post('value') === 'on' ? 'on' : 'off');

if (!isset($saas->flagKeys()[$key]) || ($tenant !== '' && $platform->find('tenants', $tenant) === null)) {
    $_SESSION['flash_problem'] = 'That flag is not declared, or that tenant does not exist.';
    header('Location: ?r=feature-flags');
    exit;
}

$existing = null;

foreach ($platform->all('feature_flags') as $r) {
    if ((string) $r['flag_key'] === $key && (string) ($r['tenant_ref'] ?? '') === $tenant) {
        $existing = $r;
    }
}

$row = ['flag_key' => $key, 'tenant_ref' => $tenant === '' ? null : $tenant, 'value' => $value,
    'updated_by' => (string) $session->user()['email']];

if ($existing !== null) {
    $row['id'] = (string) $existing['id'];
}

$saved = $platform->put('feature_flags', $row);
$audit('feature_flag.' . ($op === 'clear' ? 'cleared' : $value), 'feature_flags', (string) $saved['id'],
    $existing === null ? null : ['value' => $existing['value']], ['value' => $value], $tenant === '' ? 'global' : 'tenant ' . $tenant);
$_SESSION['flash_notice'] = $key . ($op === 'clear' ? ' now follows global' : ' is ' . $value) . ($tenant === '' ? ' globally.' : ' for that tenant.');
header('Location: ?r=feature-flags');
exit;
