<?php
/**
 * EXPLORE DEMO — BUILD THIS DEMO COMPANY'S SAMPLE DATA (owner, 12 Nov 2026). Posted once by the demo-setup screen right
 * after a visitor's (or the Play reviewer's) first sign-in. Only a demo company (tenant code DEMO-…), only its
 * administrator, only once (a lock file stops a second tab building it twice). See surface/DemoSeed.php.
 */
declare(strict_types=1);

$dsBack = static function (): never {
    header('Location: ?r=demo-setup');
    exit;
};
$dsTenant = $platform->find('tenants', (string) $tenantId);

if (!SmartPRS\Surface\DemoGate::isDemoTenant($dsTenant) || !isAdministrator()) {
    $_SESSION['flash_problem'] = 'Sample data is only built for an Explore Demo company, by its administrator.';
    $dsBack();
}

if (isset($customisation->live()[SmartPRS\Surface\DemoSeed::DONE_KEY])) {
    $dsBack();
}

$dsLock = fopen(dirname(__DIR__, 2) . '/storage/demo-seed-' . preg_replace('/\D/', '', (string) $tenantId) . '.lock', 'c');

if ($dsLock === false || !flock($dsLock, LOCK_EX | LOCK_NB)) {
    $dsBack(); // another tab is building it; the screen keeps waiting
}

set_time_limit(900);
ignore_user_abort(true);
$dsBy = (string) ($session->user()['email'] ?? 'demo');

try {
    $dsCfg = (new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))->config();
    $dsResult = SmartPRS\Surface\DemoSeed::run($store, $dsCfg,
        // The roles are the demo designations' roles, named straight from the config (orgNames() cached the empty company).
        static fn (): SmartPRS\Surface\Users => new SmartPRS\Surface\Users($store, new SmartPRS\Surface\Authorisation($customisation,
            array_values(array_unique(array_column((array) $dsCfg['designations'], 'role')))), SmartPRS\Platform\Identity\PasswordHasher::platformDefault()),
        $leave, $calendar, new SmartPRS\Surface\Shifts($store), $register, $customisation, (string) $tenantId, date('Y-m-d'), $dsBy);
    $audit('demo.seeded', 'tenants', (string) $tenantId, null, ['people' => $dsResult['people'], 'punches' => $dsResult['punches'],
        'leaves' => $dsResult['leaves'], 'payroll' => implode(', ', $dsResult['payroll'])], 'Explore Demo sample data');
    $_SESSION['demo_seed'] = $dsResult;
} catch (\Throwable $dsX) {
    error_log('demo-setup tenant ' . $tenantId . ': ' . $dsX->getMessage() . ' @ ' . $dsX->getFile() . ':' . $dsX->getLine());
    $_SESSION['demo_seed_failed'] = 'The sample data could not be built: ' . $dsX->getMessage();
} finally {
    flock($dsLock, LOCK_UN);
}

$dsBack();
