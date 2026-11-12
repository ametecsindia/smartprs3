<?php
/**
 * PRICE AND SAVE A QUOTATION, OR ACCEPT IT INTO A TENANT + SUBSCRIPTION.
 *
 * Saving prices the quote through SaasPlatform::quote() — a coupon code is
 * checked with couponProblem() and refused with its reason — and stores
 * every figure on the row. Accepting creates the tenant (from the company),
 * the subscription at the quoted plan/seats/cycle, bumps the coupon's
 * used_count, and stamps the quotation and its lead converted.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=quotations');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$op = post('op');
$id = post('id');
$before = $id === '' ? null : $platform->find('quotations', $id);
$today = date('Y-m-d');
$back = '?r=quotations&edit=' . ($id === '' ? 'new' : $id);
$couponByCode = static function (string $code) use ($platform): ?array {
    foreach ($platform->all('coupons') as $c) {
        if (strcasecmp((string) $c['code'], $code) === 0) {
            return $c;
        }
    }

    return null;
};

if ($op === 'save') {
    $plan = $platform->find('plans', post('plan_id'));
    $lead = post('lead_id') === '' ? null : $platform->find('leads', post('lead_id'));

    if ($plan === null) {
        $_SESSION['flash_problem'] = 'Pick a plan that exists.';
        header('Location: ' . $back);
        exit;
    }

    $code = strtoupper(trim(post('coupon_code')));
    $coupon = $code === '' ? null : $couponByCode($code);
    $problem = $code === '' ? null : ($coupon === null ? "No coupon {$code}." : SmartPRS\Surface\SaasPlatform::couponProblem($coupon, $today, 'signup', (string) $plan['id']));

    if ($problem !== null) {
        $_SESSION['flash_problem'] = $problem;
        header('Location: ' . $back);
        exit;
    }

    $cycle = array_key_exists(post('cycle'), (array) $declared['cycles']) ? post('cycle') : 'annual';
    $seats = max(1, (int) post('seats'));
    $state = trim(post('state'));

    try {
        $q = $saas->quote($plan, $seats, $cycle, $coupon, $state, post('discount'));
    } catch (\InvalidArgumentException $e) {
        $_SESSION['flash_problem'] = $e->getMessage();
        header('Location: ' . $back);
        exit;
    }

    $row = ['lead_id' => $lead === null ? null : (string) $lead['id'], 'plan_id' => (string) $plan['id'],
        'company' => trim(post('company')) ?: (string) ($lead['company'] ?? '') ?: null,
        'email' => trim(post('email')) ?: (string) ($lead['email'] ?? '') ?: null, 'state' => $state ?: null,
        'seats' => $seats, 'cycle' => $cycle, 'coupon_code' => $coupon === null ? null : (string) $coupon['code'],
        'subtotal' => $q['subtotal']->toDecimalString(), 'discount' => $q['discount']->toDecimalString(),
        'tax' => $q['tax']->toDecimalString(), 'total' => $q['total']->toDecimalString(),
        'valid_till' => post('valid_till') ?: null, 'notes' => trim(post('notes')) ?: null,
        'status' => in_array(post('status'), (array) $declared['quotation_statuses'], true) ? post('status') : 'draft'];

    if ($before !== null) {
        $row['id'] = $id;
    }

    $saved = $platform->put('quotations', $row);
    $audit($before === null ? 'quotation.created' : 'quotation.updated', 'quotations', (string) $saved['id'], $before, $row,
        $q['months'] . ' month(s) × ' . SmartPRS\Surface\SaasPlatform::monthly($plan, $seats)->toDecimalString() . ' − ' . $q['discount']->toDecimalString() . ' + ' . $q['mode']);
    $_SESSION['flash_notice'] = 'Quotation Q-' . (string) $saved['id'] . ' priced at ' . $q['total']->format() . ' (' . $q['mode'] . ' ' . $q['tax']->format() . ').';
} elseif ($op === 'accept' && $before !== null) {
    if ((string) ($before['converted_tenant_id'] ?? '') !== '') {
        $_SESSION['flash_problem'] = 'Already accepted into tenant #' . (string) $before['converted_tenant_id'] . '.';
        header('Location: ?r=quotations');
        exit;
    }

    $plan = $platform->find('plans', (string) $before['plan_id']);

    if ($plan === null) {
        $_SESSION['flash_problem'] = 'The quoted plan no longer exists; re-price the quotation first.';
        header('Location: ?r=quotations');
        exit;
    }

    $name = trim((string) ($before['company'] ?? '')) ?: ('Quotation Q-' . $id);
    $base = 'T-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? 'NEW', 0, 12));
    $codes = array_map(static fn (array $t): string => (string) $t['code'], $platform->all('tenants'));
    $code = $base;

    for ($n = 2; in_array($code, $codes, true); $n++) {
        $code = $base . '-' . $n;
    }

    $tenant = $platform->put('tenants', ['code' => $code, 'name' => $name, 'state' => $before['state'] ?? null, 'status_code' => 'active', 'onboarded_on' => $today]);
    $months = $saas->months((string) $before['cycle']);
    $end = (new DateTimeImmutable($today))->modify("+{$months} months")->format('Y-m-d');
    $sub = $platform->put('subscriptions', ['tenant_id' => (string) $tenant['id'], 'plan_id' => (string) $plan['id'], 'seats' => (int) $before['seats'],
        // the subscription carries the TAXABLE amount; the invoice adds GST on top
        'companies' => 1, 'cycle' => (string) $before['cycle'],
        'amount' => SmartPRS\Surface\Amount::of($before['subtotal'] ?? '0')->minus(SmartPRS\Surface\Amount::of($before['discount'] ?? '0'))->toDecimalString(),
        'current_period_end' => $end, 'next_renewal' => $end, 'status' => 'active']);
    $platform->put('quotations', ['id' => $id, 'status' => 'accepted', 'converted_tenant_id' => (string) $tenant['id']]);

    if ((string) ($before['lead_id'] ?? '') !== '' && $platform->find('leads', (string) $before['lead_id']) !== null) {
        $platform->put('leads', ['id' => (string) $before['lead_id'], 'status' => 'converted', 'converted_tenant_id' => (string) $tenant['id']]);
    }

    if ((string) ($before['coupon_code'] ?? '') !== '' && ($coupon = $couponByCode((string) $before['coupon_code'])) !== null) {
        $platform->put('coupons', ['id' => (string) $coupon['id'], 'used_count' => (int) ($coupon['used_count'] ?? 0) + 1]);
        $platform->put('coupon_redemptions', ['coupon_id' => (string) $coupon['id'], 'code' => (string) $coupon['code'], 'context' => 'signup',
            'email' => (string) ($before['email'] ?? ''), 'company' => $name, 'amount_discounted' => (string) $before['discount']]);
    }

    $audit('tenant.created', 'tenants', (string) $tenant['id'], null, ['code' => $code, 'name' => $name], 'accepted quotation Q-' . $id);
    $audit('subscription.created', 'subscriptions', (string) $sub['id'], null, ['plan_id' => $plan['id'], 'seats' => $before['seats'], 'amount' => $before['subtotal']], 'accepted quotation Q-' . $id);
    $audit('quotation.accepted', 'quotations', $id, ['status' => $before['status']], ['status' => 'accepted', 'converted_tenant_id' => $tenant['id']], 'tenant ' . $code);
    $_SESSION['flash_notice'] = "Tenant {$code} created with its subscription to {$end}. Raise the invoice next.";
    header('Location: ?r=invoices&edit=new');
    exit;
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown quotation or operation.';
}

header('Location: ?r=quotations');
exit;
