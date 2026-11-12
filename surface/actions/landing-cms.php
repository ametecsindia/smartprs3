<?php
/** LANDING PAGE (CMS): publish the edited landing-page wording, or reset it to config/landing.json. */
declare(strict_types=1);

if (!$may('tenant.settings.update') || !$isPlatformAdmin) {
    $_SESSION['flash_problem'] = 'The landing page is edited from the Super Admin console.';
} else {
    $lcAct = new SmartPRS\Surface\LandingContent(dirname(__DIR__, 2) . '/config/landing.json', new SmartPRS\Surface\PlatformSettings($platform));

    if (post('op') === 'reset') {
        $lcAct->reset();
        $audit('platform.landing_reset', 'platform_settings', 'landing', null, null, 'platform staff');
        $_SESSION['flash_notice'] = 'Landing page reset to the shipped wording.';
    } else {
        $lcAct->save((array) ($_POST['content'] ?? []));
        $audit('platform.landing_saved', 'platform_settings', 'landing', null, null, 'platform staff');
        $_SESSION['flash_notice'] = 'Landing page saved and published.';
    }
}

header('Location: ?r=landing-cms');
exit;
