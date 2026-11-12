<?php
/**
 * PLATFORM STAFF: add / set password / disable / enable a `platform_users` login.
 * Super Admin console only. A login cannot disable itself, and the last enabled
 * Super Admin cannot be disabled (the console would lock everyone out).
 */
declare(strict_types=1);

$psBack = '?r=platform-staff';

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $_SESSION['flash_problem'] = 'Platform Staff is managed from the Super Admin console.';
    header('Location: ' . $psBack);
    exit;
}

$psOp = post('op');
$psHasher = SmartPRS\Platform\Identity\PasswordHasher::platformDefault();
$psRow = post('id') === '' ? null : $platform->find('platform_users', post('id'));
$psSay = static function (string $kind, string $msg) use ($psBack): never {
    $_SESSION[$kind === 'ok' ? 'flash_notice' : 'flash_problem'] = $msg;
    header('Location: ' . $psBack);
    exit;
};

if ($psOp === 'add') {
    $email = strtolower(trim(post('email')));

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen((string) ($_POST['password'] ?? '')) < SmartPRS\Surface\Session::PASSWORD_FLOOR) {
        $psSay('bad', 'A valid email and a password of at least ' . SmartPRS\Surface\Session::PASSWORD_FLOOR . ' characters are needed.');
    }

    foreach ($platform->all('platform_users') as $u) {
        if (strcasecmp((string) $u['email'], $email) === 0) {
            $psSay('bad', $email . ' already has a platform login.');
        }
    }

    $saved = $platform->put('platform_users', ['name' => trim(post('name')) ?: $email, 'email' => $email,
        'password' => $psHasher->hash((string) $_POST['password']), 'role' => post('role') === 'super_admin' ? 'super_admin' : 'staff']);
    $audit('platform_user.created', 'platform_users', (string) $saved['id'], null, ['email' => $email, 'role' => $saved['role'] ?? post('role')], 'platform staff');
    $psSay('ok', 'Platform login ' . $email . ' added. They sign in at /super.');
}

if ($psRow === null) {
    $psSay('bad', 'No such platform login.');
}

if ($psOp === 'password') {
    if (strlen((string) ($_POST['password'] ?? '')) < SmartPRS\Surface\Session::PASSWORD_FLOOR) {
        $psSay('bad', 'The password must be at least ' . SmartPRS\Surface\Session::PASSWORD_FLOOR . ' characters.');
    }

    $platform->put('platform_users', ['id' => $psRow['id'], 'password' => $psHasher->hash((string) $_POST['password'])]);
    $audit('platform_user.password_set', 'platform_users', (string) $psRow['id'], null, null, 'platform staff');
    $psSay('ok', 'Password changed for ' . (string) $psRow['email'] . '.');
}

if ($psOp === 'disable' || $psOp === 'enable') {
    if (strcasecmp((string) $psRow['email'], (string) ($session->user()['email'] ?? '')) === 0) {
        $psSay('bad', 'You cannot disable your own login.');
    }

    if ($psOp === 'disable' && (string) $psRow['role'] === 'super_admin'
        && count(array_filter($platform->all('platform_users'), static fn (array $u): bool => (string) $u['role'] === 'super_admin' && trim((string) ($u['disabled_at'] ?? '')) === '')) <= 1) {
        $psSay('bad', 'This is the last enabled Super Admin — add another before disabling it.');
    }

    $platform->put('platform_users', ['id' => $psRow['id'], 'disabled_at' => $psOp === 'disable' ? date('Y-m-d H:i:s') : null]);
    $audit('platform_user.' . $psOp . 'd', 'platform_users', (string) $psRow['id'], null, null, 'platform staff');
    $psSay('ok', (string) $psRow['email'] . ' ' . $psOp . 'd.');
}

$psSay('bad', 'Nothing to do.');
