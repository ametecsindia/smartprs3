<?php
/**
 * SAVE, SUSPEND OR REACTIVATE A TENANT — over the platform-wide store.
 *
 * Suspend and reactivate are status moves, never deletes: a tenant's rows
 * stay exactly where they are (Law 3), the door is simply closed.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=tenants');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$op = post('op');
$id = post('id');
$before = $id === '' ? null : $platform->find('tenants', $id);
// The workspace's administrators (users is platform-scoped; the role lives in `status`).
$adminsOf = static fn (string $tid): array => $tid === '' ? [] : array_values(array_filter($platform->all('users'), static fn (array $u): bool
    => (string) ($u['tenant_id'] ?? '') === $tid && (string) ($u['role'] ?? $u['status'] ?? '') === 'administrator'));

if ($op === 'save') {
    $row = ['code' => strtoupper(trim(post('code'))), 'name' => trim(post('name')), 'state' => trim(post('state')),
        'custom_domain' => strtolower(trim(post('custom_domain'))) ?: null, 'onboarded_on' => post('onboarded_on') ?: null,
        // The workspace slug the mobile app and the branded sign-in use: app.smartprs.com/c/{slug}.
        'subdomain' => (string) preg_replace('/[^a-z0-9]/', '', strtolower(post('subdomain') ?: post('name'))) ?: null,
        'status_code' => in_array(post('status_code'), (array) $saas->declared()['tenant_statuses'], true) ? post('status_code') : 'active'];

    if ($row['code'] === '' || $row['name'] === '') {
        $_SESSION['flash_problem'] = 'A tenant needs a code and a name.';
        header('Location: ?r=tenants&edit=' . ($id === '' ? 'new' : $id));
        exit;
    }

    foreach ($platform->all('tenants') as $t) {
        if ($row['subdomain'] !== null && (string) ($t['subdomain'] ?? '') === $row['subdomain'] && (string) $t['id'] !== $id) {
            $row['subdomain'] .= (string) random_int(100, 999);
        }

        if ((string) $t['code'] === $row['code'] && (string) $t['id'] !== $id) {
            $_SESSION['flash_problem'] = "Code {$row['code']} is already tenant " . (string) $t['name'] . '.';
            header('Location: ?r=tenants&edit=' . ($id === '' ? 'new' : $id));
            exit;
        }
    }

    if ($before !== null) {
        $row['id'] = $id;
    }

    $adminEmail = strtolower(trim(post('admin_email')));
    $provision = $adminEmail !== '' && $adminsOf($id) === [];

    if ($provision) {
        if (strlen((string) ($_POST['admin_password'] ?? '')) < SmartPRS\Surface\Session::PASSWORD_FLOOR) {
            $_SESSION['flash_problem'] = 'Give the admin a password of at least ' . SmartPRS\Surface\Session::PASSWORD_FLOOR . ' characters.';
            header('Location: ?r=tenants&edit=' . ($id === '' ? 'new' : $id) . '#form');
            exit;
        }

        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['flash_problem'] = 'The admin email is not valid.';
            header('Location: ?r=tenants&edit=' . ($id === '' ? 'new' : $id) . '#form');
            exit;
        }

        foreach (array_merge($platform->all('users'), $platform->all('platform_users')) as $u) {
            if (strcasecmp((string) ($u['email'] ?? ''), $adminEmail) === 0) {
                $_SESSION['flash_problem'] = 'That admin email is already in use by another account.';
                header('Location: ?r=tenants&edit=' . ($id === '' ? 'new' : $id) . '#form');
                exit;
            }
        }
    }

    $saved = $platform->put('tenants', $row);
    $audit($before === null ? 'tenant.created' : 'tenant.updated', 'tenants', (string) $saved['id'], $before, $row, 'platform staff');
    $_SESSION['flash_notice'] = 'Tenant ' . $row['name'] . ' saved.';

    // PROVISION (SmartPRS 2 provisionTenantRecord): the master company and the first administrator,
    // whose one-time password is shown once here — they change it from their own profile.
    if ($provision) {
        $tStore = $platform->forTenant((string) $saved['id']);

        if ($tStore->all('companies') === []) {
            $tStore->put('companies', ['name' => trim(post('company_name')) ?: $row['name']]);
        }

        // The password the Super Admin typed (owner, 5 Nov 2026: nothing is emailed unless asked — Admin password
        // on the row can email a set-your-own-password link later).
        $made = (new SmartPRS\Surface\Users($tStore, $access, SmartPRS\Platform\Identity\PasswordHasher::platformDefault()))
            ->create($adminEmail, (string) $_POST['admin_password'], 'administrator');
        $_SESSION['flash_notice'] .= $made['ok']
            ? ' Administrator ' . $adminEmail . ' created with the password you typed. Sign-in: /c/' . (string) $row['subdomain']
            : ' The admin login was not created: ' . $made['why'];
        $audit('tenant.provisioned', 'tenants', (string) $saved['id'], null, ['admin' => $adminEmail, 'subdomain' => $row['subdomain']], 'platform staff');
    }
} elseif (($op === 'suspend' || $op === 'reactivate') && $before !== null) {
    $to = $op === 'suspend' ? 'suspended' : 'active';
    $platform->put('tenants', ['id' => $id, 'status_code' => $to]);
    $audit('tenant.' . $op, 'tenants', $id, ['status_code' => $before['status_code']], ['status_code' => $to], $op);
    $_SESSION['flash_notice'] = (string) $before['name'] . ' is now ' . $to . '.';
} elseif ($op === 'reset-admin' && $before !== null) {
    // NO ADMINISTRATOR YET (provisioning skipped or failed): create one with the email and password typed on the row.
    $tStore = $platform->forTenant($id);
    $newAdmin = strtolower(trim(post('admin_email')));

    if ($adminsOf($id) !== []) {
        $_SESSION['flash_problem'] = (string) $before['name'] . ' already has an administrator — use Admin password.';
    } elseif (filter_var($newAdmin, FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['flash_problem'] = 'Type a valid admin email beside Create admin.';
    } else {
        if ($tStore->all('companies') === []) {
            $tStore->put('companies', ['name' => (string) $before['name']]);
        }

        $made = (new SmartPRS\Surface\Users($tStore, $access, SmartPRS\Platform\Identity\PasswordHasher::platformDefault()))
            ->create($newAdmin, (string) ($_POST['password'] ?? ''), 'administrator');
        $made['ok'] && $audit('tenant.admin_created', 'tenants', $id, null, ['admin' => $newAdmin], 'platform staff');
        $_SESSION[$made['ok'] ? 'flash_notice' : 'flash_problem'] = $made['ok']
            ? (string) $before['name'] . ': administrator ' . $newAdmin . ' created with the password you typed. Sign-in: /c/' . (string) ($before['subdomain'] ?? '')
            : 'The admin login was not created: ' . $made['why'];
    }
} elseif (in_array($op, ['admin-set-password', 'admin-reset-link'], true) && $before !== null) {
    // ADMIN PASSWORD (owner, 5 Nov 2026): set it here, or email the administrator a set-your-own-password link — the
    // Super Admin chooses; nothing is emailed unless asked.
    $admins = $adminsOf($id);
    usort($admins, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
    $tUsers = new SmartPRS\Surface\Users($platform->forTenant($id), $access, SmartPRS\Platform\Identity\PasswordHasher::platformDefault());

    if ($admins === []) {
        $_SESSION['flash_problem'] = (string) $before['name'] . ' has no administrator login yet: use Create admin on its row.';
    } elseif ($op === 'admin-set-password') {
        $set = $tUsers->setPassword((string) $admins[0]['id'], (string) ($_POST['password'] ?? ''), (string) ($session->user()['id'] ?? 'platform'));
        $set['ok'] && $audit('tenant.admin_password_set', 'tenants', $id, null, ['admin' => $admins[0]['email']], 'platform staff');
        $_SESSION[$set['ok'] ? 'flash_notice' : 'flash_problem'] = $set['ok']
            ? (string) $before['name'] . ': password updated for ' . (string) $admins[0]['email'] . '.' : $set['why'];
    } else {
        $mail = new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');

        if (!$mail->ready()) {
            $_SESSION['flash_problem'] = 'Platform Email (SMTP) is not set up yet, so no link can be emailed. Set it up under SaaS Platform → Platform Email, or use Update password.';
        } else {
            $hours = max(1, (int) (json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/password-reset.json'), true)['link_hours'] ?? 0));
            $inv = $tUsers->invite((string) $admins[0]['id'], $hours);
            $base = trim((string) ($before['custom_domain'] ?? '')) !== '' ? 'https://' . trim((string) $before['custom_domain'])
                : (rtrim((string) (SmartPRS\Surface\Database::env(dirname(__DIR__, 2) . '/.env')['APP_URL'] ?? ''), '/')
                    ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                        . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/'));
            $sent = $inv['ok'] ? $mail->send([(string) $admins[0]['email']], 'Set your SmartPRS password — ' . (string) $before['name'],
                'Use the link below to set a new password for your SmartPRS login. It works once, for ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . '.',
                ['Set password' => $base . '/app.php?r=set-password&t=' . rawurlencode((string) $inv['token']), 'Sign-in ID' => (string) $admins[0]['email']])
                : ['ok' => false, 'why' => $inv['why']];
            $sent['ok'] && $audit('tenant.admin_reset_link_sent', 'tenants', $id, null, ['admin' => $admins[0]['email']], 'platform staff');
            $_SESSION[$sent['ok'] ? 'flash_notice' : 'flash_problem'] = $sent['ok']
                ? 'Reset link emailed to ' . (string) $admins[0]['email'] . '.' : 'The reset link was not sent: ' . $sent['why'];
        }
    }
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown tenant or operation.';
}

header('Location: ?r=tenants');
exit;
