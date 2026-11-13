<?php
/**
 * SAVE, RENEW OR CANCEL A SUBSCRIPTION — over the platform-wide store, so
 * the write lands on the subscription's own tenant and not the operator's.
 *
 * The amount is never typed: plan monthly price at the seats × cycle months
 * (SaasPlatform), so the register and the dashboard's MRR agree. Renew moves
 * the period end forward by one cycle from wherever it is (or today if it
 * has lapsed) and sets the row active. Cancel is a status, not a delete.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=subscriptions');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$op = post('op');
$id = post('id');
$before = $id === '' ? null : $platform->find('subscriptions', $id);
$today = date('Y-m-d');
$plus = static fn (string $from, int $months): string => (new DateTimeImmutable($from))->modify("+{$months} months")->format('Y-m-d');

if ($op === 'save') {
    $plan = $platform->find('plans', post('plan_id'));
    $tenant = $platform->find('tenants', post('tenant_id'));

    if ($tenant === null || (post('plan_id') !== '' && $plan === null)) {
        $_SESSION['flash_problem'] = 'Pick a tenant (and, if any, a plan) that exist.';
        header('Location: ?r=subscriptions&edit=' . ($id === '' ? 'new' : $id));
        exit;
    }

    $cycle = array_key_exists(post('cycle'), (array) $declared['cycles']) ? post('cycle') : 'monthly';
    $seats = max(1, (int) post('seats'));
    // VALID UNTIL is typed directly (owner, 13 Nov 2026); blank = today + the cycle's months.
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('valid_until')) === 1 ? post('valid_until') : $plus($today, $saas->months($cycle));
    $row = ['tenant_id' => (string) $tenant['id'], 'plan_id' => $plan === null ? null : (string) $plan['id'], 'seats' => $seats,
        'companies' => max(1, (int) post('companies')), 'cycle' => $cycle,
        'amount' => $plan === null ? '0' : SmartPRS\Surface\SaasPlatform::monthly($plan, $seats)->multipliedBy($saas->months($cycle))->toDecimalString(),
        'current_period_end' => $end, 'next_renewal' => $end,
        'status' => in_array(post('status'), (array) $declared['subscription_statuses'], true) ? post('status') : 'active'];

    if ($before !== null) {
        $row['id'] = $id;
    }

    $saved = $platform->put('subscriptions', $row);
    $audit($before === null ? 'subscription.created' : 'subscription.updated', 'subscriptions', (string) $saved['id'], $before, $row, 'platform staff');
    $_SESSION['flash_notice'] = 'Licence for ' . (string) $tenant['name'] . " saved: {$seats} seat(s), " . $row['companies'] . ' company(ies), valid until '
        . date('d-m-Y', (int) strtotime($end)) . ($plan === null ? '.' : ' — ' . SmartPRS\Surface\Amount::of($row['amount'])->format() . ' per ' . $cycle . '.');
} elseif ($op === 'renew' && $before !== null) {
    $plan = $platform->find('plans', (string) $before['plan_id']);
    $months = $saas->months((string) ($before['cycle'] ?? ''));
    $from = max((string) ($before['current_period_end'] ?? ''), $today);
    $end = $plus($from, $months);
    $after = ['id' => $id, 'current_period_end' => $end, 'next_renewal' => $end, 'status' => 'active'];

    if ($plan !== null) {
        $after['amount'] = SmartPRS\Surface\SaasPlatform::monthly($plan, (int) $before['seats'])->multipliedBy($months)->toDecimalString();
    }

    $platform->put('subscriptions', $after);
    $audit('subscription.renewed', 'subscriptions', $id, ['current_period_end' => $before['current_period_end'], 'status' => $before['status']], $after, "renewed {$months} month(s) from {$from}");
    $_SESSION['flash_notice'] = "Renewed to {$end}.";
} elseif ($op === 'cancel' && $before !== null) {
    $platform->put('subscriptions', ['id' => $id, 'status' => 'cancelled']);
    $audit('subscription.cancelled', 'subscriptions', $id, ['status' => $before['status']], ['status' => 'cancelled'], 'cancelled by platform staff');
    $_SESSION['flash_notice'] = 'Subscription cancelled; the row is kept.';
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown subscription or operation.';
}

header('Location: ?r=subscriptions');
exit;
