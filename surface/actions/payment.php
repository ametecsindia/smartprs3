<?php
/**
 * RECORD A PAYMENT AGAINST AN INVOICE. A succeeded payment that clears the
 * balance marks the invoice paid; one that does not marks it part paid.
 * The payment row lands on the invoice's tenant through the platform store.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=payments');
    exit;
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$invoice = $platform->find('invoices', post('invoice_id'));

if (post('op') !== 'save' || $invoice === null) {
    $_SESSION['flash_problem'] = 'Pick an invoice that exists.';
    header('Location: ?r=payments&edit=new');
    exit;
}

$balance = SmartPRS\Surface\SaasPlatform::balance($invoice, $platform->all('payments'));

try {
    $amount = post('amount') === '' ? $balance : SmartPRS\Surface\Amount::of(post('amount'));
} catch (\InvalidArgumentException $e) {
    $_SESSION['flash_problem'] = $e->getMessage();
    header('Location: ?r=payments&edit=new');
    exit;
}

if ($amount->isZero() || $amount->isNegative()) {
    $_SESSION['flash_problem'] = 'A payment is a positive amount.';
    header('Location: ?r=payments&edit=new');
    exit;
}

$method = in_array(post('method'), (array) $declared['payment_methods'], true) ? post('method') : 'bank transfer';
$status = in_array(post('status'), (array) $declared['payment_statuses'], true) ? post('status') : 'succeeded';
$paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('paid_on')) === 1 ? post('paid_on') : date('Y-m-d');
$row = ['tenant_id' => (string) $invoice['tenant_id'], 'invoice_id' => (string) $invoice['id'], 'amount' => $amount->toDecimalString(),
    'gateway' => $method === 'gateway' ? (string) ($invoice['gateway'] ?? '') : 'manual', 'method' => $method,
    'gateway_txn_id' => trim(post('reference')) ?: null, 'status' => $status, 'paid_at' => $paidOn . ' ' . date('H:i:s')];
$saved = $platform->put('payments', $row);
$audit('payment.recorded', 'payments', (string) $saved['id'], null, $row, $method . ' ' . trim(post('reference')));
$notice = 'Payment of ' . $amount->format() . ' recorded against ' . (string) $invoice['number'] . '.';

if ($status === 'succeeded') {
    $left = $balance->minus($amount);
    $to = $left->isZero() || $left->isNegative() ? 'paid' : 'part paid';
    $platform->put('invoices', ['id' => (string) $invoice['id'], 'status' => $to, 'paid_on' => $to === 'paid' ? $paidOn : null]);
    $audit('invoice.' . ($to === 'paid' ? 'paid' : 'part-paid'), 'invoices', (string) $invoice['id'], ['status' => $invoice['status']],
        ['status' => $to], 'balance left ' . $left->toDecimalString());
    $notice .= $to === 'paid' ? ' Invoice settled.' : ' Balance left: ' . $left->format() . '.';
}

$_SESSION['flash_notice'] = $notice;
header('Location: ?r=payments');
exit;
