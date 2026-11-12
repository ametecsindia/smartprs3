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
