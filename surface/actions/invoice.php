<?php
/**
 * RAISE, MARK PAID OR CANCEL AN INVOICE.
 *
 * Raising: the taxable amount (typed, or the subscription's amount), GST
 * from config/gst.json against the tenant's state, a number from the running
 * sequence, status issued. Mark paid: a `payments` row for the balance with
 * the method and reference, then the invoice flips to paid with paid_on.
 * Both rows land on the invoice's tenant through the platform store.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=invoices');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$op = post('op');
$id = post('id');
$today = date('Y-m-d');
$before = $id === '' ? null : $platform->find('invoices', $id);

if ($op === 'save') {
    $sub = $platform->find('subscriptions', post('subscription_id'));
    $tenant = $sub === null ? null : $platform->find('tenants', (string) $sub['tenant_id']);

    if ($sub === null || $tenant === null) {
        $_SESSION['flash_problem'] = 'Pick a subscription that exists.';
        header('Location: ?r=invoices&edit=new');
        exit;
    }

    try {
        $taxable = SmartPRS\Surface\Amount::of(post('amount') === '' ? (string) ($sub['amount'] ?? '0') : post('amount'));
    } catch (\InvalidArgumentException $e) {
        $_SESSION['flash_problem'] = $e->getMessage();
        header('Location: ?r=invoices&edit=new');
        exit;
    }

    $gst = $saas->tax($taxable, (string) ($tenant['state'] ?? ''));
    $issued = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('issued_on')) === 1 ? post('issued_on') : $today;
    $row = ['tenant_id' => (string) $tenant['id'], 'subscription_id' => (string) $sub['id'],
        'number' => $saas->invoiceNumber($platform->count('invoices') + 1, $issued),
        'amount' => $taxable->toDecimalString(), 'tax' => $gst['tax']->toDecimalString(), 'status' => 'issued',
        'issued_on' => $issued, 'due_on' => post('due_on') ?: null,
        'period_from' => post('period_from') ?: null, 'period_to' => post('period_to') ?: null,
        'notes' => trim(post('notes')) ?: null];
    $saved = $platform->put('invoices', $row);
    $audit('invoice.raised', 'invoices', (string) $saved['id'], null, $row,
        $gst['mode'] . ' ' . $gst['rate'] . '% on ' . $taxable->toDecimalString());
    $_SESSION['flash_notice'] = 'Invoice ' . $row['number'] . ' raised: ' . $gst['total']->format() . ' (' . $gst['mode'] . ' ' . $gst['tax']->format() . ').';
} elseif ($op === 'mark-paid' && $before !== null) {
    $balance = SmartPRS\Surface\SaasPlatform::balance($before, $platform->all('payments'));
    $method = in_array(post('method'), (array) $saas->declared()['payment_methods'], true) ? post('method') : 'bank transfer';
    $payment = ['tenant_id' => (string) $before['tenant_id'], 'invoice_id' => $id, 'amount' => $balance->toDecimalString(),
        'gateway' => $method === 'gateway' ? (string) ($before['gateway'] ?? '') : 'manual', 'method' => $method,
        'gateway_txn_id' => trim(post('reference')) ?: null, 'status' => 'succeeded', 'paid_at' => date('Y-m-d H:i:s')];
    $paid = $platform->put('payments', $payment);
    $platform->put('invoices', ['id' => $id, 'status' => 'paid', 'paid_on' => $today]);
    $audit('invoice.paid', 'invoices', $id, ['status' => $before['status']], ['status' => 'paid', 'payment_id' => $paid['id']],
        $method . ' ' . trim(post('reference')));
    $_SESSION['flash_notice'] = 'Invoice ' . (string) $before['number'] . ' marked paid; payment of ' . $balance->format() . ' recorded.';
} elseif ($op === 'cancel' && $before !== null) {
    $platform->put('invoices', ['id' => $id, 'status' => 'cancelled']);
    $audit('invoice.cancelled', 'invoices', $id, ['status' => $before['status']], ['status' => 'cancelled'], 'cancelled by platform staff');
    $_SESSION['flash_notice'] = 'Invoice ' . (string) $before['number'] . ' cancelled; the row is kept.';
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown invoice or operation.';
}

header('Location: ?r=invoices');
exit;
