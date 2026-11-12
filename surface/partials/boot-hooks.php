<?php
/**
 * WHAT HAPPENS ON EVERY APP REQUEST BEFORE ANY SCREEN — included by public/app.php
 * right after the session starts. Four SmartPRS 2 middlewares, in its order:
 *
 *   DeviceAutoLink        ?app=1&device=… from the mobile app is remembered, and the first
 *                         signed-in request links that phone to the person's employee record.
 *   LicenseGate           ON-PREMISE ONLY (SMARTPRS_DEPLOYMENT=onprem): no valid licence, or an
 *                         expired one in "renew" mode → signed out and sent to activate.php.
 *                         A once-a-day heartbeat to the licence server catches a revoked key.
 *   EnsureSubscriptionActive  CLOUD ONLY: a suspended/closed tenant is signed out; a tenant whose
 *                         subscription ended more than the grace days ago is "locked" — its admin
 *                         may open My Subscription only, everyone else is signed out.
 *
 * Fail-soft like SmartPRS 2: a licence check must never take a client's HR system down, so an
 * internal error lets the request through. Uses $session, $platform, $pdo from app.php; sets
 * $subscriptionLocked for the route guard further down.
 */
declare(strict_types=1);

$subscriptionLocked = false;
$hookBase = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/app.php'))), '/');
$licence = new SmartPRS\Surface\Licence(dirname(__DIR__, 2));

// /c/{slug} (branded sign-in, and where the mobile app lands) arrives rewritten; send the browser to
// app.php itself so the page's relative links resolve. The query (app=1&device=…) travels along.
if (preg_match('~/c/[A-Za-z0-9]+/?$~', (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH)) === 1) {
    header('Location: ' . $hookBase . '/app.php?' . (string) ($_SERVER['QUERY_STRING'] ?? 'r=signin'));
    exit;
}

if (isset($_GET['app'], $_GET['device']) && (string) $_GET['device'] !== '') {
    $_SESSION['app_device'] = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_GET['device']), 0, 80);
}

// THE APP'S ONE-TIME LOGIN LINK (owner, 12 Nov 2026 — SmartEPT-style phone sign-in): public/mobile.php checked the password
// and this phone, then minted a link good once, for a few seconds, for this phone only. It opens the web session as that
// login, so Home shows that profile's menus. Anything wrong = the ordinary sign-in page (fails closed).
if (isset($_GET['applogin'], $_GET['device']) && (string) $_GET['applogin'] !== '') {
    try {
        $alDev = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_GET['device']), 0, 80);
        $alHit = SmartPRS\Surface\MobileActivation::load(dirname(__DIR__, 2))->takeLogin((string) $_GET['applogin'], $alDev, time());
        $alUser = $alHit === null ? null : $platform->find('users', (string) $alHit['user']);
        if ($alUser !== null && (string) ($alUser['tenant_id'] ?? '') === (string) $alHit['tenant'] && $session->signInVerified($alUser)) {
            $_SESSION['app_device'] = $alDev;
        }
    } catch (\Throwable $alX) {
        error_log('boot-hooks app login link: ' . $alX->getMessage());
    }
    header('Location: ' . $hookBase . '/app.php?r=' . rawurlencode(preg_match('/^[a-z0-9-]{1,60}$/', (string) ($_GET['r'] ?? '')) === 1 ? (string) $_GET['r'] : 'dashboard'));
    exit;
}

$hookOut = static function (string $why) use ($session, $hookBase): never {
    $session->signOut();
    session_start();
    $_SESSION['flash_problem'] = $why;
    header('Location: ' . $hookBase . '/app.php?r=signin');
    exit;
};

try {
    if ($licence->enforced()) {
        $licence->sweep();

        if (!$licence->valid()) {
            if ($session->isSignedIn()) {
                $session->signOut();
            }

            header('Location: ' . $hookBase . '/activate.php');
            exit;
        }
    }

    // AN EXPLORE DEMO VISITOR (public/demo.php) is signed out the moment their demo's days end or the Super Admin
    // revokes it, and sent back to /demo (SmartPRS 2 rev 185); the sweep then deletes that demo company.
    if (is_array($_SESSION['demo_pass'] ?? null) && $platform instanceof SmartPRS\Surface\SqlStore
        && (new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))->passOver($_SESSION['demo_pass'])) {
        $session->signOut();
        header('Location: ' . $hookBase . '/demo.php?expired=1');
        exit;
    }

    $hookUser = $session->user();
    $hookTenant = (string) ($hookUser['tenant_id'] ?? '');

    if ($hookUser !== null && $hookTenant !== '' && !$licence->isOnPrem() && $pdo !== null) {
        $t = $platform->find('tenants', $hookTenant);

        if (in_array((string) ($t['status_code'] ?? ''), ['suspended', 'closed'], true)) {
            $hookOut('This workspace is currently suspended. Please contact your administrator.');
        }

        // EXPLORE DEMO (owner, 12 Nov 2026): a demo company with no sample data yet (just made, or the Play reviewer's company
        // after its nightly reset) opens on "Preparing your demo company", which builds it once.
        if (SmartPRS\Surface\DemoGate::isDemoTenant($t) && ($_GET['r'] ?? '') !== 'demo-setup' && ($_POST['action'] ?? '') !== 'demo-setup'
            && !isset((new SmartPRS\Surface\Customisation($platform->forTenant($hookTenant)))->live()[SmartPRS\Surface\DemoSeed::DONE_KEY])) {
            header('Location: ' . $hookBase . '/app.php?r=demo-setup');
            exit;
        }

        $sub = null;

        foreach ($platform->forTenant($hookTenant)->all('subscriptions') as $s) {
            if ($sub === null || (string) ($s['current_period_end'] ?? '') > (string) ($sub['current_period_end'] ?? '')) {
                $sub = $s;
            }
        }

        $end = (string) ($sub['current_period_end'] ?? '');
        $grace = (int) (json_decode((string) @file_get_contents(__DIR__ . '/../../config/plans.json'), true)['subscription_grace_days'] ?? 7);
        $locked = $sub !== null && $end !== '' && (in_array((string) ($sub['status'] ?? ''), ['cancelled', 'suspended'], true)
            || date('Y-m-d') > date('Y-m-d', strtotime($end . ' +' . $grace . ' days')));

        if ($locked) {
            if (!isAdministrator((string) ($hookUser['role'] ?? ''))) {
                $hookOut('This workspace is suspended (subscription expired). Please contact your administrator to renew.');
            }

            $subscriptionLocked = true;
        }
    }

    if ($hookUser !== null && $hookTenant !== '' && ($dev = (string) ($_SESSION['app_device'] ?? '')) !== '' && $pdo !== null) {
        $empId = (string) ($hookUser['employee_id'] ?? '');

        if ($empId !== '') {
            $emp = $platform->forTenant($hookTenant)->find('employees', $empId);
            $pdo->prepare('UPDATE mobile_devices SET employee_id = ?, emp_code = ?, user_id = ?, updated_at = NOW()
                WHERE device_id = ? AND tenant_id = ? AND employee_id IS NULL AND user_id IS NULL')
                ->execute([$empId, $emp === null ? null : SmartPRS\Surface\Employment::code($emp), is_numeric($hookUser['id'] ?? null) ? $hookUser['id'] : null, $dev, $hookTenant]);
        }

        unset($_SESSION['app_device']);
    }
} catch (\Throwable $hookX) {
    error_log('boot-hooks: ' . $hookX->getMessage()); // fail-soft: never take the HR system down over a gate
}

// THE EMPLOYEE APP'S DEVICE GATE (owner, 5 Nov 2026): the SmartPRS3 Android app sets the cookie
// sprs_app_device on its web view. Every signed-in request from it needs that phone approved in
// Administration → Mobile Devices and bound to this employee; a pending, rejected or revoked phone
// is signed out at once. Unlike the hooks above this one FAILS CLOSED (Law 5).
$appDevice = substr((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_COOKIE['sprs_app_device'] ?? '')), 0, 80);
$gateUser = $session->user();

if ($appDevice !== '' && $gateUser !== null && (string) ($gateUser['tenant_id'] ?? '') !== '') {
    try {
        // ponytail: reads the tenant's whole mobile_devices register per request; index by device_id if it grows past a few thousand
        $gateWhy = SmartPRS\Surface\MobileDevices::gate($platform->forTenant((string) $gateUser['tenant_id'])->all('mobile_devices'),
            $appDevice, (string) ($gateUser['employee_id'] ?? ''), (string) ($gateUser['id'] ?? ''));
    } catch (\Throwable $gateX) {
        error_log('boot-hooks device gate: ' . $gateX->getMessage());
        $gateWhy = 'This phone could not be checked just now. Please try again.';
    }

    if ($gateWhy !== null) {
        $hookOut($gateWhy);
    }
}
