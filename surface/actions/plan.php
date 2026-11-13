<?php
/**
 * SAVE A PLAN. Prices are decimal strings checked through Amount (Law 7);
 * the ticked modules become {"modules": [...]} in the features JSON column.
 * Plans are never deleted here — withdraw one instead, so the subscriptions
 * that name it keep pricing (Law 3).
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=plans');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');

if (post('op') === 'pricing') {
    // THE PRICE LIST (owner, 13 Nov 2026): whole rupees, written where the website, sign-up and renewals read it.
    $plLc = new SmartPRS\Surface\LandingContent(dirname(__DIR__, 2) . '/config/landing.json', new SmartPRS\Surface\PlatformSettings($platform));
    $content = $plLc->content();
    $pr = (array) ($content['pricing'] ?? []);
    $whole = static fn (string $v): bool => preg_match('/^\d{1,9}$/', $v) === 1;
    $bad = [];
    $pkgs = [];

    foreach ((array) ($_POST['pkg'] ?? []) as $i => $p) {
        $p = array_map(static fn (mixed $x): string => trim((string) $x), (array) $p);

        if (implode('', $p) === '') {
            continue;
        }

        if ($p['name'] === '' || !$whole($p['employees']) || (int) $p['employees'] < 1 || !$whole($p['monthly']) || ($p['extra'] !== '' && !$whole($p['extra']))) {
            $bad[] = 'Plan row ' . ((int) $i + 1) . ': name, employees (1 or more) and ₹ per month are needed, in whole rupees';
            continue;
        }

        $pkgs[] = ['name' => $p['name'], 'employees' => $p['employees'], 'monthly' => $p['monthly'], 'extra' => $p['extra'] === '' ? '0' : $p['extra']]
            + (post('highlight') === (string) $i ? ['highlight' => '1'] : []);
    }

    $cycles = [];

    foreach ((array) ($_POST['cyc'] ?? []) as $i => $c) {
        $c = array_map(static fn (mixed $x): string => trim((string) $x), (array) $c);

        if (implode('', $c) === '') {
            continue;
        }

        if ($c['label'] === '' || !$whole($c['months']) || (int) $c['months'] < 1 || (int) $c['months'] > 36 || !$whole($c['discount'] ?: '0') || (int) $c['discount'] > 100) {
            $bad[] = 'Period row ' . ((int) $i + 1) . ': label, months 1–36 and discount 0–100 % are needed';
            continue;
        }

        $cycles[] = ['months' => $c['months'], 'discount' => $c['discount'] ?: '0', 'label' => $c['label']];
    }

    foreach (['company_fee' => 'Extra company fee', 'licence' => 'Licence', 'amc_percent' => 'AMC %', 'installation' => 'Installation', 'ob_base' => 'Setup & onboarding', 'ob_per' => 'Per employee'] as $k => $label) {
        $whole(post($k)) || $bad[] = $label . ' must be whole rupees (0 or more)';
    }

    $pkgs === [] && $bad[] = 'Keep at least one cloud plan';
    $cycles === [] && $bad[] = 'Keep at least one advance payment period';
    (int) post('amc_percent') > 100 && $bad[] = 'AMC % must be 100 or less';

    if ($bad !== []) {
        $_SESSION['flash_problem'] = 'Prices not saved — ' . implode('; ', $bad) . '.';
        header('Location: ?r=plans');
        exit;
    }

    usort($pkgs, static fn (array $a, array $b): int => (int) $a['employees'] <=> (int) $b['employees']);
    usort($cycles, static fn (array $a, array $b): int => (int) $a['months'] <=> (int) $b['months']);
    $pr['cloud'] = array_replace((array) ($pr['cloud'] ?? []), ['packages' => $pkgs, 'cycles' => $cycles, 'company_fee' => post('company_fee')]);
    $pr['onprem'] = array_replace((array) ($pr['onprem'] ?? []), ['licence' => post('licence'), 'amc_percent' => post('amc_percent'), 'installation' => post('installation')]);
    $pr['onboarding'] = array_replace((array) ($pr['onboarding'] ?? []), ['base' => post('ob_base'), 'per_employee' => post('ob_per')]);
    $before = $content['pricing'] ?? null;
    $content['pricing'] = $pr;
    $plLc->save($content);
    $saas->syncPackages((array) (($plLc->content()['pricing']['cloud']['packages'] ?? [])));
    $audit('platform.pricing_saved', 'platform_settings', 'landing', ['pricing' => $before], ['pricing' => $pr], 'platform staff');
    $_SESSION['flash_notice'] = 'Prices saved — the website, sign-up and renewals now use them.';
    header('Location: ?r=plans');
    exit;
}
$declared = $saas->declared();
$id = post('id');
$before = $id === '' ? null : $platform->find('plans', $id);
$back = '?r=plans&edit=' . ($id === '' ? 'new' : $id);

try {
    $base = SmartPRS\Surface\Amount::of(post('base_price'));
    $perUser = SmartPRS\Surface\Amount::of(post('per_user_price'));
} catch (\InvalidArgumentException $e) {
    $_SESSION['flash_problem'] = $e->getMessage();
    header('Location: ' . $back);
    exit;
}

$known = array_map(static fn (array $m): string => (string) $m['key'], $modules->all());
$row = [
    'name' => trim(post('name')),
    'base_price' => $base->toDecimalString(),
    'per_user_price' => $perUser->toDecimalString(),
    'billing_cycle' => array_key_exists(post('billing_cycle'), (array) $declared['cycles']) ? post('billing_cycle') : 'monthly',
    'seat_max' => post('seat_max') === '' ? null : max(0, (int) post('seat_max')),
    'status' => in_array(post('status'), (array) $declared['plan_statuses'], true) ? post('status') : 'draft',
    'features' => json_encode(['modules' => array_values(array_intersect($known, array_map('strval', (array) ($_POST['modules'] ?? []))))]),
];

if ($row['name'] === '') {
    $_SESSION['flash_problem'] = 'A plan needs a name.';
    header('Location: ' . $back);
    exit;
}

if ($before !== null) {
    $row['id'] = $id;
}

$saved = $platform->put('plans', $row);
$audit($before === null ? 'plan.created' : 'plan.updated', 'plans', (string) $saved['id'], $before, $row, 'platform staff');
$_SESSION['flash_notice'] = 'Plan ' . $row['name'] . ' saved.';
header('Location: ?r=plans');
exit;
