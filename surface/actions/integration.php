<?php
/**
 * API KEYS & SMARTEPT WEBHOOKS (SmartPRS 2 ApiKeyController): mint / revoke an
 * sk_prs_… key, mint / rotate / revoke a SmartEPT endpoint. A key is shown once
 * and kept as a sha256 hash; a webhook secret is kept encrypted (Secret) because
 * the signature check has to read it back.
 */
declare(strict_types=1);

$intBack = '?r=integrations';

if (!$may('tenant.settings.update') || $tenantId === '') {
    $_SESSION['flash_problem'] = 'Denied: integrations belong to a company workspace.';
    header('Location: ' . $intBack);
    exit;
}

$intOp = post('op');
$intSay = static function (string $kind, string $msg) use ($intBack): never {
    $_SESSION[$kind === 'ok' ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ' . $intBack);
    exit;
};
$intCompany = post('company') !== '' && $store->find('companies', post('company')) !== null ? post('company') : null;

if ($intOp === 'mint-key') {
    $scopes = array_values(array_intersect(['ingest', 'read'], array_map('strval', (array) ($_POST['scopes'] ?? []))));

    if (trim(post('name')) === '' || $scopes === []) {
        $intSay('bad', 'A key needs a name and at least one scope.');
    }

    $prefix = 'sk_prs_' . bin2hex(random_bytes(2));
    $secret = $prefix . '_' . bin2hex(random_bytes(20));
    $row = $store->put('api_keys', ['name' => mb_substr(trim(post('name')), 0, 120), 'prefix' => $prefix, 'key_hash' => hash('sha256', $secret),
        'scopes' => json_encode($scopes), 'active' => 1, 'employer_company_id' => $intCompany,
        'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', post('expires_at')) === 1 ? post('expires_at') . ' 23:59:59' : null]);
    $audit('api_key.minted', 'api_key', $prefix, null, ['name' => $row['name'], 'prefix' => $prefix, 'scopes' => $scopes], 'SmartPRS 2-style key (X-Api-Key)');
    $_SESSION['int_minted'] = ['what' => 'API key "' . (string) $row['name'] . '"', 'lines' => ['X-Api-Key' => $secret]];
    $intSay('ok', 'Key created.');
}

if ($intOp === 'revoke-key') {
    $k = $store->find('api_keys', post('id'));

    if ($k === null || !str_starts_with((string) $k['prefix'], 'sk_prs_')) {
        $intSay('bad', 'No such key in this organisation.');
    }

    $store->put('api_keys', ['id' => $k['id'], 'active' => 0]);
    $audit('api_key.revoked', 'api_key', (string) $k['prefix'], ['active' => 1], ['active' => 0], 'revoked');
    $intSay('ok', 'Key ' . (string) $k['name'] . ' revoked — refused from the next request.');
}

if ($intOp === 'mint-hook') {
    $events = array_values(array_intersect(SmartPRS\Surface\SmartEpt::EVENTS, array_map('strval', (array) ($_POST['events'] ?? []))));

    if (trim(post('name')) === '' || $events === []) {
        $intSay('bad', 'An endpoint needs a name and at least one event.');
    }

    $m = SmartPRS\Surface\SmartEpt::mint();
    $row = $store->put('smartept_webhook_endpoints', ['name' => mb_substr(trim(post('name')), 0, 120), 'slug' => $m['slug'],
        'secret' => SmartPRS\Surface\Secret::encrypt($m['secret']), 'events' => json_encode($events), 'active' => 1, 'employer_company_id' => $intCompany]);
    $audit('smartept_webhook.created', 'smartept_webhook_endpoints', (string) $row['id'], null, ['name' => $row['name'], 'slug' => $m['slug']], 'SmartEPT webhook');
    $_SESSION['int_minted'] = ['what' => 'SmartEPT endpoint "' . (string) $row['name'] . '"', 'lines' => ['Secret' => $m['secret'], 'Slug' => $m['slug']]];
    $intSay('ok', 'Endpoint created.');
}

if ($intOp === 'rotate-hook' || $intOp === 'revoke-hook') {
    $w = $store->find('smartept_webhook_endpoints', post('id'));

    if ($w === null) {
        $intSay('bad', 'No such endpoint in this organisation.');
    }

    if ($intOp === 'revoke-hook') {
        $store->put('smartept_webhook_endpoints', ['id' => $w['id'], 'active' => 0]);
        $audit('smartept_webhook.revoked', 'smartept_webhook_endpoints', (string) $w['id'], ['active' => 1], ['active' => 0], 'revoked');
        $intSay('ok', 'Endpoint ' . (string) $w['name'] . ' revoked.');
    }

    $secret = SmartPRS\Surface\SmartEpt::mint()['secret'];
    $store->put('smartept_webhook_endpoints', ['id' => $w['id'], 'secret' => SmartPRS\Surface\Secret::encrypt($secret)]);
    $audit('smartept_webhook.rotated', 'smartept_webhook_endpoints', (string) $w['id'], null, null, 'secret rotated');
    $_SESSION['int_minted'] = ['what' => 'New secret for "' . (string) $w['name'] . '"', 'lines' => ['Secret' => $secret]];
    $intSay('ok', 'Secret rotated — update SmartEPT with it.');
}

$intSay('bad', 'Nothing to do.');
