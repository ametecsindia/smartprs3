<?php
/**
 * RENEW ONLINE — a cloud subscriber's own renewal (owner, 13 Nov 2026; SmartPRS 2 TenantBillingController quote /
 * renewOrder / renewComplete / applyRenewal). Answers JSON to My Subscription:
 *
 *   step=quote     the price for employees, companies and period — computed HERE from the website price list
 *                  (Signup::price, no setup charge on a renewal), never trusted from the browser
 *   step=order     a pending `renewals` row + a Razorpay order for that price
 *   step=complete  the Razorpay signature checked, then (once — idempotent) the subscription extended from its
 *                  current end (renewing early never loses days), a PAID invoice + payment recorded, the invoice PDF
 *                  emailed to the administrators
 *
 * Can't renew below what is in use (SmartPRS 2 rev 76). Every row goes through the tenant store, so a workspace only
 * ever touches its own subscription, renewals and invoices.
 */
declare(strict_types=1);

$rnOut = static function (int $status, array $body): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (!$may('tenant.settings.update')) {
    $rnOut(403, ['ok' => false, 'error' => 'Only the workspace administrator can renew.']);
}

$rnTenant = (string) ($session->user()['tenant_id'] ?? '');
$rnRoot = dirname(__DIR__, 2);

if ($rnTenant === '' || $licence->isOnPrem()) {
    $rnOut(422, ['ok' => false, 'error' => 'Online renewal is for cloud workspaces.']);
}

$rnLanding = (new SmartPRS\Surface\LandingContent($rnRoot . '/config/landing.json', new SmartPRS\Surface\PlatformSettings($platform)))->content();
$rnSg = new SmartPRS\Surface\Signup($platform, (array) ($rnLanding['pricing'] ?? []), json_decode((string) file_get_contents($rnRoot . '/config/gst.json'), true) ?: [], $rnRoot . '/.env');
$rnT = (array) $platform->find('tenants', $rnTenant);
$rnSubs = liveRows($store, 'subscriptions');
usort($rnSubs, static fn (array $a, array $b): int => strcmp((string) ($b['current_period_end'] ?? ''), (string) ($a['current_period_end'] ?? '')));
$rnSub = $rnSubs[0] ?? null;
$rnUsedEmp = count(array_filter(liveRows($store, 'employees'), static fn (array $e): bool
    => !in_array(strtolower(trim((string) ($e['status'] ?? ''))), SmartPRS\Surface\Licence::SEAT_FREE, true)));
$rnUsedCo = count(liveRows($store, 'companies'));
$rnStep = post('step');

// THE PRICE — server-side, from what was posted (employees, companies, months) and the plan that covers them.
$rnPrice = static function () use ($rnSg, $rnT, $rnUsedEmp, $rnUsedCo, $rnOut): array {
    $emp = max(1, (int) post('employees'));
    $co = max(1, (int) post('companies'));
    $months = (int) post('months');

    if ($emp < $rnUsedEmp) {
        $rnOut(422, ['ok' => false, 'error' => "You have {$rnUsedEmp} active employees — the renewal must cover at least that many (exited employees free their seat)."]);
    }

    if ($co < $rnUsedCo) {
        $rnOut(422, ['ok' => false, 'error' => "You have {$rnUsedCo} companies — the renewal must cover at least that many."]);
    }

    $plan = $rnSg->bestFit($emp);

    if ($plan === null) {
        $rnOut(422, ['ok' => false, 'error' => 'No price list is published yet — please contact Ametecs to renew.']);
    }

    return $rnSg->price($plan, $emp, $co, $months, null, (string) ($rnT['state'] ?? ''), false);
};

if ($rnStep === 'quote') {
    $p = $rnPrice();
    $rnOut(200, ['ok' => true, 'plan' => $p['plan'], 'period' => $p['cycle_label'] . ' (' . $p['months'] . ' months)', 'discount' => $p['discount_percent'],
        'subscription' => SmartPRS\Surface\Signup::rupees($p['subscription']), 'tax' => SmartPRS\Surface\Signup::rupees($p['tax']) . ' (' . $p['tax_mode'] . ')',
        'total' => SmartPRS\Surface\Signup::rupees($p['total'])]);
}

$rnGw = $rnSg->gateway();

if ($rnStep === 'order') {
    if ($rnGw === null) {
        $rnOut(422, ['ok' => false, 'error' => 'Online payment is not configured yet. Please contact Ametecs to renew.']);
    }

    $p = $rnPrice();
    $uuid = bin2hex(random_bytes(16));
    $cycleKey = (string) (array_search($p['months'], (array) (json_decode((string) file_get_contents($rnRoot . '/config/plans.json'), true)['cycles'] ?? []), true) ?: 'quarterly');
    $planId = $rnSg->planRow(['name' => $p['plan']] + ((array) $rnSg->plan($p['plan'])));
    $row = $store->put('renewals', ['uuid' => $uuid, 'plan_id' => $planId, 'seats' => $p['employees'], 'companies' => $p['companies'], 'mode' => 'renew',
        'cycle' => $cycleKey, 'amount' => SmartPRS\Platform\Support\Money::ofMinor((int) $p['taxable'])->toDecimalString(), 'tax' => SmartPRS\Platform\Support\Money::ofMinor((int) $p['tax'])->toDecimalString(), 'status' => 'pending']);
    $order = $rnSg->razorpayOrder($rnGw, (int) $p['total'], 'RENEW-' . (string) $row['id'], ['renewal_uuid' => $uuid, 'tenant_id' => $rnTenant, 'kind' => 'renewal']);

    if (!$order['ok']) {
        $rnOut(422, ['ok' => false, 'error' => 'Could not start the payment: ' . $order['why']]);
    }

    $store->put('renewals', ['id' => $row['id'], 'gateway_order_id' => $order['id']]);
    $rnOut(200, ['ok' => true, 'orderId' => $order['id'], 'keyId' => $rnGw['key'], 'amountPaise' => (int) $p['total'], 'uuid' => $uuid,
        'company' => (string) ($rnT['name'] ?? ''), 'email' => (string) ($session->user()['email'] ?? '')]);
}

if ($rnStep === 'complete') {
    $r = null;

    foreach ($store->all('renewals') as $x) {
        (string) $x['uuid'] === post('uuid') && $r = $x;
    }

    if ($r === null) {
        $rnOut(404, ['ok' => false, 'error' => 'Renewal not found.']);
    }

    if ((string) $r['status'] === 'done') {
        $rnOut(200, ['ok' => true, 'message' => 'This payment is already applied to your subscription.']);
    }

    if ((string) ($r['gateway_order_id'] ?? '') === '' || (string) $r['gateway_order_id'] !== post('razorpay_order_id')
        || $rnGw === null || !SmartPRS\Surface\Signup::signatureOk($rnGw['secret'], post('razorpay_order_id'), post('razorpay_payment_id'), post('razorpay_signature'))) {
        $rnOut(422, ['ok' => false, 'error' => 'Payment verification failed. If money was taken, contact Ametecs with the payment reference ' . post('razorpay_payment_id') . '.']);
    }

    // APPLY (SmartPRS 2 applyRenewal): extend from the current end when it is still ahead, else from today.
    $months = (int) ((json_decode((string) file_get_contents($rnRoot . '/config/plans.json'), true)['cycles'][(string) $r['cycle']] ?? 3));
    $today = date('Y-m-d');
    $from = $rnSub !== null && (string) ($rnSub['current_period_end'] ?? '') > $today ? (string) $rnSub['current_period_end'] : $today;
    $end = (new DateTimeImmutable($from))->modify('+' . $months . ' months')->format('Y-m-d');
    $subRow = ['plan_id' => (string) $r['plan_id'], 'seats' => (int) $r['seats'], 'companies' => (int) $r['companies'], 'cycle' => (string) $r['cycle'],
        'amount' => (string) $r['amount'], 'status' => 'active', 'current_period_end' => $end, 'next_renewal' => $end];
    $sub = $store->put('subscriptions', $rnSub === null ? $subRow : ['id' => $rnSub['id']] + $subRow);
    $issued = count(array_filter($platform->all('invoices'), static fn (array $i): bool => trim((string) ($i['number'] ?? '')) !== ''))
        + count(array_filter($platform->all('onprem_clients'), static fn (array $c): bool => trim((string) ($c['invoice_no'] ?? '')) !== ''));
    $saas = new SmartPRS\Surface\SaasPlatform($platform, $rnRoot . '/config');
    $inv = $store->put('invoices', ['subscription_id' => (string) $sub['id'], 'number' => $saas->invoiceNumber($issued + 1, $today),
        'amount' => (string) $r['amount'], 'tax' => (string) $r['tax'], 'status' => 'paid', 'issued_on' => $today, 'paid_on' => $today,
        'period_from' => $from, 'period_to' => $end, 'gateway' => 'razorpay', 'notes' => 'Online renewal — ' . (int) $r['seats'] . ' employees, '
        . (int) $r['companies'] . ' compan' . ((int) $r['companies'] === 1 ? 'y' : 'ies') . ', ' . $months . ' months']);
    $store->put('payments', ['invoice_id' => (string) $inv['id'], 'amount' => SmartPRS\Surface\Amount::of($r['amount'] ?? '0')->plus(SmartPRS\Surface\Amount::of($r['tax'] ?? '0'))->toDecimalString(),
        'gateway' => 'razorpay', 'method' => 'gateway', 'gateway_txn_id' => post('razorpay_payment_id'), 'status' => 'succeeded', 'paid_at' => date('Y-m-d H:i:s')]);
    $store->put('renewals', ['id' => $r['id'], 'status' => 'done']);
    in_array((string) ($rnT['status_code'] ?? ''), ['suspended'], true) || $platform->put('tenants', ['id' => $rnTenant, 'status_code' => 'active']);
    $audit('subscription.renewed_online', 'subscriptions', (string) $sub['id'], $rnSub, $subRow, 'Razorpay ' . post('razorpay_payment_id'));

    // The receipt: the paid GST invoice as a PDF, to the administrators (best effort — the screen shows it too).
    $to = [];

    foreach ($platform->all('users') as $u) {
        (string) ($u['tenant_id'] ?? '') === $rnTenant && (string) ($u['role'] ?? $u['status'] ?? '') === 'administrator'
            && filter_var((string) ($u['email'] ?? ''), FILTER_VALIDATE_EMAIL) && $to[] = (string) $u['email'];
    }

    $mail = new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), $rnRoot . '/.env');
    $sent = $to !== [] && $mail->ready() && $mail->send($to, 'SmartPRS renewal — payment received (invoice ' . (string) $inv['number'] . ')',
        'Thank you! Your SmartPRS subscription for ' . (string) ($rnT['name'] ?? '') . ' is renewed until ' . date('d-m-Y', (int) strtotime($end)) . '. Your tax invoice is attached.',
        [], [[preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $inv['number']) . '.pdf',
            $saas->invoicePdf($inv, $rnT, liveRows($store, 'payments'), (string) ($platform->find('plans', (string) $r['plan_id'])['name'] ?? ''))]])['ok'];
    $rnOut(200, ['ok' => true, 'message' => 'Payment received — your subscription is active until ' . date('d-m-Y', (int) strtotime($end)) . '.'
        . ($sent ? ' The tax invoice has been emailed.' : ' Download the tax invoice below.')]);
}

$rnOut(400, ['ok' => false, 'error' => 'Unknown request.']);
