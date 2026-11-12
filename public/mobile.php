<?php
/**
 * THE SMARTPRS MOBILE APP'S DEVICE GATE — SmartPRS 2's MobileDeviceController
 * register/status/push-token, same contract, so the published Android/iOS app
 * (com.ametecs.smartprs) works against SmartPRS 3 unchanged:
 *
 *   POST /api/mobile/register    {device_id, device_name, platform, workspace, emp_code}
 *   POST /api/mobile/status      {device_id, workspace}
 *   POST /api/mobile/push-token  {device_id, token, platform}
 *
 * SmartEPT-STYLE SIGN-IN (owner, 12 Nov 2026), used by the SmartPRS3 app from v1.2:
 *
 *   POST /api/mobile/signin    {workspace, login, password, device_id}
 *        → registered + approved: a one-time login link (the web session opens with that profile's menus);
 *          registered + pending: the match code; not registered: the app asks for the activation code.
 *   POST /api/mobile/activate  {workspace, login, password, device_id, device_name, platform, activation_code}
 *        → the phone is registered to that login (approved or waiting, per QR Attendance → Phone approval).
 *
 * The workspace is found by the address the phone typed: a tenant's custom domain
 * (hr.client.in), else the /c/{slug} the app sends as `workspace` (tenants.subdomain),
 * else — on an on-premise install with one tenant — that tenant. Approval is
 * Administration → Mobile Devices; on approval the app opens /login?app=1&device=…
 * and the first sign-in links the phone to that employee (surface/partials/boot-hooks.php).
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$op = ($p = strpos($uri, '/api/mobile/')) !== false ? trim(substr($uri, $p + 12), '/') : trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$in = body_form((string) file_get_contents('php://input'));
$str = static fn (string $k, int $max): string => mb_substr(trim((string) (is_scalar($in[$k] ?? null) ? $in[$k] : '')), 0, $max);

/** The tenant this phone is talking to, or null. */
$tenant = (static function () use ($platform, $str): ?array {
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    $slug = (string) preg_replace('/[^a-z0-9]/', '', strtolower($str('workspace', 60)));
    $tenants = array_values(array_filter($platform->all('tenants'), static fn (array $t): bool
        => !in_array((string) ($t['status_code'] ?? ''), ['closed'], true)));

    foreach ($tenants as $t) {
        if ($host !== '' && strtolower(trim((string) ($t['custom_domain'] ?? ''))) === $host) {
            return $t;
        }
    }

    foreach ($tenants as $t) {
        if ($slug !== '' && strtolower(trim((string) ($t['subdomain'] ?? ''))) === $slug) {
            return $t;
        }
    }

    return count($tenants) === 1 ? $tenants[0] : null;
})();

$deviceId = $str('device_id', 80);

/**
 * PHONE APPROVAL EXCEPTION (owner, 9 Nov 2026 — demo client, Android review): a company set to "No phone approval" on
 * Time & Attendance → QR Attendance, or an employee with a live phone exception, is approved without the Admin. Still a
 * registration the Admin sees and can revoke on Mobile Devices. Any failure = the phone waits for approval (fails closed).
 */
$phoneAuto = static function (SmartPRS\Surface\RowStore $store, ?array $emp) use ($root): bool {
    try {
        $companies = array_map(static fn (array $c): string => (string) $c['id'],
            array_values(array_filter($store->all('companies'), static fn (array $c): bool => trim((string) ($c['deleted_at'] ?? '')) === '')));

        return SmartPRS\Surface\QrAttendance::load(new SmartPRS\Surface\Customisation($store), $root)->phoneAutoApproved(
            $emp === null ? null : (string) $emp['id'], $emp === null ? null : (string) ($emp['employer_company_id'] ?? ''), $companies, date('Y-m-d'));
    } catch (\Throwable $e) {
        error_log('Phone approval exception not read (the phone waits for approval): ' . $e->getMessage());

        return false;
    }
};
$autoMark = static fn (string $now): array => ['status' => 'approved', 'approved_by' => 'Auto — no phone approval needed', 'approved_at' => $now];

$notifyPending = static function (SmartPRS\Surface\RowStore $store, array $saved, ?array $emp, ?array $appUser) use ($root): void {
    // A PHONE WAITING FOR APPROVAL IS A REQUEST (owner, 5 Nov 2026: "the admin should get the notification every time any
    // request is generated"). Told the same way as every other request — RequestNotices: the employee's reporting line,
    // their branch's Branch Admins and every company-wide role (administrator, Company Admin, HR Admin…), in the bell and
    // on Notifications. Once per phone registration (deduped by the device row). A failure here never stops the phone.
    if ((string) $saved['status'] === 'pending' && $emp !== null) {
        try {
            $mdRoles = array_values(array_unique(array_filter(array_map(static fn (array $d): string => trim((string) ($d['role'] ?? '')), $store->all('designations')))));
            $mdAccess = new SmartPRS\Surface\Authorisation(new SmartPRS\Surface\Customisation($store), $mdRoles);
            (new SmartPRS\Surface\RequestNotices($store, new SmartPRS\Surface\Notifications($store, $root . '/config/notifications.json'),
                static fn (string $role): string => $mdAccess->reach($role), $mdAccess->roles(), static fn (string $code): array => []))
                ->notify($emp, 'Mobile app approval', 'mobile-device', 'device:' . (string) $saved['id'],
                    'Phone ' . (trim((string) ($saved['device_name'] ?? '')) ?: 'unknown') . ' (phone code ' . (string) ($saved['code'] ?? '') . ') is waiting for approval in Administration → Mobile Devices.');
        } catch (\Throwable $mdNoticeError) {
            error_log('Mobile device approval notice not queued: ' . $mdNoticeError->getMessage());
        }
    }

    // A LOGIN WITH NO EMPLOYEE RECORD has no reporting line: the administrators are told.
    if ((string) $saved['status'] === 'pending' && $emp === null && $appUser !== null) {
        try {
            (new SmartPRS\Surface\Notifications($store, $root . '/config/notifications.json'))->queue('request.raised.mobile-device',
                SmartPRS\Surface\Notifications::ROLE_PREFIX . 'administrator', 'request.raised:mobile-device:device:' . (string) $saved['id'],
                ['code' => (string) $appUser['email'], 'name' => trim((string) ($appUser['name'] ?? '')), 'what' => 'Mobile app approval',
                    'details' => 'Phone ' . (trim((string) ($saved['device_name'] ?? '')) ?: 'unknown') . ' (phone code ' . (string) ($saved['code'] ?? '') . ') is waiting for approval in Administration → Mobile Devices.']);
        } catch (\Throwable $mdNoticeError) {
            error_log('Mobile device approval notice not queued: ' . $mdNoticeError->getMessage());
        }
    }

};

if ($op === 'register') {
    if ($deviceId === '') {
        json_out(422, ['ok' => false, 'error' => 'Invalid request.']);
    }

    if ($tenant === null) {
        json_out(404, ['ok' => false, 'error' => 'We could not find your workspace at this address. Enter your full company address (for example hr.youragency.com), or app.smartprs.com/c/your-name.']);
    }

    $store = $platform->forTenant((string) $tenant['id']);
    $emp = null;
    $empCode = $str('emp_code', 191);
    $appUser = null;

    // ONE APP FOR EVERYBODY (owner, 6 Nov 2026): the phone is registered with an Employee ID or a SmartPRS login email —
    // an Admin / HR login without an employee record registers with its email. What the app then shows is decided by
    // whoever signs in, exactly as on the web.
    if (str_contains($empCode, '@')) {
        foreach ($store->all('users') as $u) {
            if ((string) ($u['tenant_id'] ?? '') === (string) $tenant['id'] && strcasecmp(trim((string) ($u['email'] ?? '')), $empCode) === 0) {
                $appUser = $u;
                break;
            }
        }

        $emp = $appUser === null || trim((string) ($appUser['employee_id'] ?? '')) === '' ? null : $store->find('employees', (string) $appUser['employee_id']);

        // …OR THE EMPLOYEE'S OWN EMAIL ON THE DIRECTORY (6 Nov 2026: vinayametecs@gmail.com is Vinay Mane's Official Email
        // but was refused because no login carried it yet) — registers as that employee.
        if ($emp === null) {
            foreach ($store->all('employees') as $e) {
                if (trim((string) ($e['deleted_at'] ?? '')) === '' && strcasecmp(trim((string) ($e['email'] ?? '')), $empCode) === 0) {
                    $emp = $e;
                    $appUser = null;
                    break;
                }
            }
        }

        if ($appUser === null && $emp === null) {
            json_out(422, ['ok' => false, 'error' => 'No employee or SmartPRS login with the email "' . $empCode . '" was found in this workspace. Check it with your administrator and try again.']);
        }
    } elseif ($empCode !== '') {
        foreach ($store->all('employees') as $e) {
            if (trim((string) ($e['deleted_at'] ?? '')) === '' && (strcasecmp(SmartPRS\Surface\Employment::code($e), $empCode) === 0
                || strcasecmp(trim((string) ($e['device_user_id'] ?? '')), $empCode) === 0)) {
                $emp = $e;
                break;
            }
        }

        if ($emp === null) {
            json_out(422, ['ok' => false, 'error' => 'Employee ID "' . $empCode . '" was not found in this workspace. Check it with HR and try again.']);
        }
    }

    $existing = null;

    foreach ($store->all('mobile_devices') as $d) {
        if ((string) $d['device_id'] === $deviceId && trim((string) ($d['deleted_at'] ?? '')) === '') {
            $existing = $d;
        }
    }

    // REGISTERING AGAIN AFTER A REVOKE / REJECT IS A NEW REQUEST (owner, 6 Nov 2026: the phone sat on "not allowed"): the
    // old registration stays as history and a fresh Pending one is made, which the Admin approves or rejects as usual.
    if ($existing !== null && in_array(strtolower((string) ($existing['status'] ?? '')), ['revoked', 'rejected'], true)) {
        $existing = null;
    }

    $now = date('Y-m-d H:i:s');
    $row = ['device_name' => $str('device_name', 160) ?: ($existing['device_name'] ?? null), 'platform' => $str('platform', 20) ?: ($existing['platform'] ?? null),
        'host' => mb_substr((string) ($_SERVER['HTTP_HOST'] ?? ''), 0, 191), 'last_seen_at' => $now];

    if ($emp !== null && ($existing === null || (string) $existing['status'] !== 'approved')) {
        $row += ['employee_id' => $emp['id'], 'emp_code' => SmartPRS\Surface\Employment::code($emp)];
    } elseif ($appUser !== null && ($existing === null || (string) $existing['status'] !== 'approved')) {
        $row += ['user_id' => $appUser['id']];
    }

    $qaAuto = $phoneAuto($store, $emp);

    if ($existing !== null) {
        $saved = $store->put('mobile_devices', ['id' => $existing['id']] + $row + ($qaAuto && (string) $existing['status'] === 'pending' ? $autoMark($now) : []));
    } else {
        $code = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $deviceId), -6)) ?: strtoupper(bin2hex(random_bytes(3)));
        $saved = $store->put('mobile_devices', ($qaAuto ? $autoMark($now) : []) + $row + ['device_id' => $deviceId, 'status' => 'pending', 'code' => $code, 'created_at' => $now]);
    }

    $saved = $store->find('mobile_devices', (string) $saved['id']) ?? $saved;

    $notifyPending($store, $saved, $emp, $appUser);

    json_out(200, ['ok' => true, 'status' => (string) $saved['status'], 'code' => (string) ($saved['code'] ?? ''),
        'token' => (string) $saved['status'] === 'approved' ? ($saved['token'] ?? null) : null]);
}

if ($op === 'status') {
    if ($deviceId === '') {
        json_out(422, ['status' => 'unknown']);
    }

    $hit = null;

    foreach ($platform->all('mobile_devices') as $d) {
        if ((string) $d['device_id'] === $deviceId && ($tenant === null || (string) $d['tenant_id'] === (string) $tenant['id'])
            && trim((string) ($d['deleted_at'] ?? '')) === '' && ($hit === null || (int) $d['id'] > (int) $hit['id'])) {
            $hit = $d;
        }
    }

    if ($hit === null) {
        json_out(200, ['status' => 'unknown']);
    }

    $upd = ['id' => $hit['id'], 'last_seen_at' => date('Y-m-d H:i:s')];
    $status = (string) $hit['status'];
    $hitStore = $platform->forTenant((string) $hit['tenant_id']);

    // A phone that was already waiting when its company / employee got the exception is approved on its next check.
    if ($status === 'pending' && $phoneAuto($hitStore, trim((string) ($hit['employee_id'] ?? '')) === '' ? null : $hitStore->find('employees', (string) $hit['employee_id']))) {
        $upd += $autoMark((string) $upd['last_seen_at']);
        $status = 'approved';
    }

    // The gate token is issued on first sight of the approval (the screen's approve does not mint one).
    if ($status === 'approved' && trim((string) ($hit['token'] ?? '')) === '') {
        $upd['token'] = $hit['token'] = bin2hex(random_bytes(24));
    }

    $hitStore->put('mobile_devices', $upd);
    // A revoked phone is a rejected one to the app: it shows the reason and stops.
    $appStatus = $status === 'revoked' ? 'rejected' : $status;
    json_out(200, ['status' => $appStatus, 'reason' => $appStatus === 'rejected' ? (($hit['reject_reason'] ?? '') ?: null) : null,
        'token' => $status === 'approved' ? $hit['token'] : null]);
}

// ── SIGN IN / ACTIVATE (owner, 12 Nov 2026: "similar to SmartEPT logic") ─────────────────────────────────────────────
if ($op === 'signin' || $op === 'activate') {
    $act = SmartPRS\Surface\MobileActivation::load($root);
    $nowTs = time();

    if ($deviceId === '' || $str('login', 191) === '' || (string) ($in['password'] ?? '') === '') {
        json_out(422, ['ok' => false, 'error' => 'Enter the company address, your login and your password.']);
    }
    if ($tenant === null) {
        json_out(404, ['ok' => false, 'error' => 'We could not find your workspace at this address. Enter your full company address (for example app.smartprs.com/c/your-name).']);
    }
    if (($wait = $act->locked($deviceId, $nowTs)) > 0) {
        json_out(429, ['ok' => false, 'error' => 'Too many wrong tries from this phone. Try again in ' . (int) ceil($wait / 60) . ' minute(s).']);
    }

    $store = $platform->forTenant((string) $tenant['id']);
    $login = $str('login', 191);
    $emp = null;
    $user = null;

    // THE LOGIN: a SmartPRS login email, or the Employee ID of an employee who has a SmartPRS login.
    foreach ($store->all('employees') as $e) {
        if (trim((string) ($e['deleted_at'] ?? '')) === '' && !str_contains($login, '@')
            && (strcasecmp(SmartPRS\Surface\Employment::code($e), $login) === 0 || strcasecmp(trim((string) ($e['device_user_id'] ?? '')), $login) === 0)) {
            $emp = $e;
        }
    }
    foreach ($store->all('users') as $u) {
        if ((string) ($u['tenant_id'] ?? '') === (string) $tenant['id'] && ($emp !== null
            ? (string) ($u['employee_id'] ?? '') === (string) $emp['id'] : strcasecmp(trim((string) ($u['email'] ?? '')), $login) === 0)) {
            $user = $u;
        }
    }
    if ($emp === null && $user !== null && trim((string) ($user['employee_id'] ?? '')) !== '') {
        $emp = $store->find('employees', (string) $user['employee_id']);
    }

    // The verify runs even with no such login, so both paths cost the same (no account oracle).
    $pwOk = SmartPRS\Platform\Identity\PasswordHasher::platformDefault()->verify((string) $in['password'], $user['password'] ?? $user['password_hash'] ?? null);
    if (!$pwOk || $user === null || trim((string) ($user['disabled_at'] ?? '')) !== '') {
        $act->failed($deviceId, $nowTs);
        json_out(401, ['ok' => false, 'error' => 'Company address, login or password is wrong.']);
    }

    $name = trim((string) (($emp['name'] ?? '') ?: ($user['name'] ?? '') ?: $user['email']));
    $mine = static fn (array $d): bool => $emp !== null ? (string) ($d['employee_id'] ?? '') === (string) $emp['id']
        : trim((string) ($d['employee_id'] ?? '')) === '' && (string) ($d['user_id'] ?? '') === (string) $user['id'];
    $row = null;
    foreach ($store->all('mobile_devices') as $d) {
        if ((string) $d['device_id'] === $deviceId && trim((string) ($d['deleted_at'] ?? '')) === '' && ($row === null || (int) $d['id'] > (int) $row['id'])) {
            $row = $d;
        }
    }
    $rowStatus = $row === null ? '' : strtolower((string) $row['status']);
    $now = date('Y-m-d H:i:s', $nowTs);

    if ($row !== null && $rowStatus === 'approved' && !$mine($row)) {
        json_out(409, ['ok' => false, 'error' => 'This phone is registered to another SmartPRS login. Ask your administrator to revoke it first (Administration → Mobile Devices).']);
    }

    // An approved phone signs straight in: a one-time link opens the web session with this profile's menus.
    $signedIn = static function (array $d) use ($store, $act, $user, $tenant, $deviceId, $nowTs, $name, $now): never {
        $upd = ['id' => $d['id'], 'last_seen_at' => $now];
        trim((string) ($d['token'] ?? '')) === '' && $upd['token'] = bin2hex(random_bytes(24));
        $store->put('mobile_devices', $upd);
        $act->cleared($deviceId);
        json_out(200, ['ok' => true, 'registered' => true, 'status' => 'approved', 'name' => $name,
            'login' => $act->mintLogin((string) $user['id'], (string) $tenant['id'], $deviceId, $nowTs)]);
    };

    if ($row !== null && $mine($row) && $rowStatus === 'approved') {
        $signedIn($row);
    }
    if ($row !== null && $mine($row) && $rowStatus === 'pending' && $op === 'signin') {
        $act->cleared($deviceId);
        json_out(200, ['ok' => true, 'registered' => true, 'status' => 'pending', 'code' => (string) ($row['code'] ?? ''), 'name' => $name]);
    }

    // NOT REGISTERED YET: the activation code the administrator issued — except a company / employee that needs no phone
    // approval (QR Attendance → Phone approval: demo client, Android review), which registers at once.
    $auto = $phoneAuto($store, $emp);
    $custom = new SmartPRS\Surface\Customisation($store);
    $codes = (array) ($custom->live()[SmartPRS\Surface\MobileActivation::KEY] ?? []);
    $subject = $emp !== null ? 'e:' . $emp['id'] : 'u:' . $user['id'];

    if (!$auto && $op === 'signin') {
        $act->cleared($deviceId);
        json_out(200, ['ok' => true, 'registered' => false, 'name' => $name]);
    }
    if (!$auto && !SmartPRS\Surface\MobileActivation::matches($codes, $subject, $str('activation_code', 40), $nowTs)) {
        $act->failed($deviceId, $nowTs);
        json_out(422, ['ok' => false, 'error' => 'That activation code is wrong or has expired. Ask your administrator for a new one.']);
    }

    // REGISTER THIS PHONE to this login (a revoked / rejected registration stays as history; a waiting one is reused).
    $fields = ['device_name' => $str('device_name', 160) ?: ($row['device_name'] ?? null), 'platform' => $str('platform', 20) ?: ($row['platform'] ?? null),
        'host' => mb_substr((string) ($_SERVER['HTTP_HOST'] ?? ''), 0, 191), 'last_seen_at' => $now,
        'employee_id' => $emp['id'] ?? null, 'emp_code' => $emp === null ? null : SmartPRS\Surface\Employment::code($emp), 'user_id' => $emp === null ? $user['id'] : ($user['id'] ?? null)];
    if ($row !== null && $rowStatus === 'pending') {
        $saved = $store->put('mobile_devices', ['id' => $row['id']] + $fields + ($auto ? $autoMark($now) : []));
    } else {
        $code = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $deviceId), -6)) ?: strtoupper(bin2hex(random_bytes(3)));
        $saved = $store->put('mobile_devices', ($auto ? $autoMark($now) : []) + $fields + ['device_id' => $deviceId, 'status' => 'pending', 'code' => $code, 'created_at' => $now]);
    }
    $saved = $store->find('mobile_devices', (string) $saved['id']) ?? $saved;

    if (!$auto) {
        $custom->saveRaw(SmartPRS\Surface\MobileActivation::KEY, SmartPRS\Surface\MobileActivation::consume($codes, $subject), '', 'Mobile app',
            'Activation code used on phone ' . $deviceId);
    }
    $notifyPending($store, $saved, $emp, $emp === null ? $user : null);

    if (strtolower((string) $saved['status']) === 'approved') {
        $signedIn($saved);
    }
    $act->cleared($deviceId);
    json_out(200, ['ok' => true, 'registered' => true, 'status' => (string) $saved['status'], 'code' => (string) ($saved['code'] ?? ''), 'name' => $name]);
}

if ($op === 'push-token') {
    $token = $str('token', 255);

    if ($deviceId !== '' && $token !== '') {
        foreach ($platform->all('mobile_devices') as $d) {
            if ((string) $d['device_id'] === $deviceId) {
                $platform->forTenant((string) $d['tenant_id'])->put('mobile_devices', ['id' => $d['id'], 'push_token' => $token,
                    'platform' => $str('platform', 20) ?: $d['platform']]);
            }
        }
    }

    json_out(200, ['ok' => true]); // never block the app on this (SmartPRS 2)
}

json_out(404, ['ok' => false, 'error' => 'There is no such mobile endpoint.']);
