<?php
/**
 * /licence.php?t=<invoice token> — THE ON-PREM CLIENT'S PAY PAGE (SmartPRS 2 /licence/{token}: payShow, payOrder,
 * payComplete). Owner, 13 Nov 2026: on-prem licensing "exactly as SmartPRS2".
 *
 * The invoice email (Super Admin → On-Prem Clients → Invoice) links here. It shows the invoice and the balance and takes
 * the balance by Razorpay (keys: Super Admin → Payment Gateways). The payment is verified by its signature, recorded once
 * (idempotent on the payment id), and when the sale is then fully paid the licence key is generated and emailed by itself.
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

use SmartPRS\Surface\Amount;
use SmartPRS\Surface\LicenceAuthority;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['lic_csrf'] ??= bin2hex(random_bytes(32));
$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$client = null;

foreach ($token === '' ? [] : $platform->all('onprem_clients') as $c) {
    if (hash_equals((string) ($c['invoice_token'] ?? ''), $token) && trim((string) ($c['invoice_no'] ?? '')) !== '') {
        $client = $c;
    }
}

$saas = new SmartPRS\Surface\SaasPlatform($platform, $root . '/config');
$gst = json_decode((string) @file_get_contents($root . '/config/gst.json'), true) ?: [];
$gw = (new SmartPRS\Surface\Signup($platform, [], $gst, $root . '/.env'))->gateway();
$paise = static function (string $decimal): int {
    [$rupees, $fraction] = explode('.', $decimal . '.');

    return (int) $rupees * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($client === null) {
        json_out(404, ['ok' => false, 'error' => 'Invoice not found.']);
    }

    if (!hash_equals($_SESSION['lic_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        json_out(419, ['ok' => false, 'error' => 'This page expired — reload it and try again.']);
    }

    $t = LicenceAuthority::totals($client, $saas);

    if (($_POST['op'] ?? '') === 'order') {
        if ($t['balance']->isZero()) {
            json_out(422, ['ok' => false, 'error' => 'This invoice is already fully paid — thank you!']);
        }

        if ($gw === null) {
            json_out(422, ['ok' => false, 'error' => 'Online payment is not configured yet. Please pay by NEFT and share the reference with Ametecs.']);
        }

        $amount = $paise($t['balance']->toDecimalString());
        $order = (new SmartPRS\Surface\Signup($platform, [], $gst, $root . '/.env'))->razorpayOrder($gw, $amount, 'LIC-' . (string) $client['id'],
            ['invoice' => (string) $client['invoice_no'], 'company' => (string) $client['company']]);

        if (!$order['ok']) {
            json_out(422, ['ok' => false, 'error' => 'Could not start the payment: ' . $order['why']]);
        }

        $platform->put('onprem_clients', ['id' => $client['id'], 'gateway_order_id' => $order['id']]);
        json_out(200, ['ok' => true, 'orderId' => $order['id'], 'keyId' => $gw['key'], 'amountPaise' => $amount]);
    }

    if (($_POST['op'] ?? '') === 'complete') {
        $orderId = (string) ($_POST['razorpay_order_id'] ?? '');
        $paymentId = (string) ($_POST['razorpay_payment_id'] ?? '');

        if ($orderId === '' || (string) ($client['gateway_order_id'] ?? '') !== $orderId) {
            json_out(422, ['ok' => false, 'error' => 'Payment/order mismatch.']);
        }

        if ($gw === null || !SmartPRS\Surface\Signup::signatureOk($gw['secret'], $orderId, $paymentId, (string) ($_POST['razorpay_signature'] ?? ''))) {
            json_out(422, ['ok' => false, 'error' => 'Payment signature verification failed.']);
        }

        $already = false;

        foreach ($platform->all('onprem_payments') as $p) {
            $already = $already || ((string) $p['client_id'] === (string) $client['id'] && (string) ($p['reference'] ?? '') === $paymentId);
        }

        if (!$already) {
            $platform->put('onprem_payments', ['client_id' => $client['id'], 'amount' => $t['balance']->toDecimalString(), 'mode' => 'gateway',
                'reference' => $paymentId, 'paid_on' => date('Y-m-d'), 'entered_by' => 'Razorpay']);
            $paidTotal = Amount::of($client['paid_total'] ?? '0')->plus($t['balance']);
            $platform->put('onprem_clients', ['id' => $client['id'], 'paid_total' => $paidTotal->toDecimalString()]);
            $client['paid_total'] = $paidTotal->toDecimalString();
        }

        // FULL PAYMENT → THE KEY GENERATES AND EMAILS ITSELF (SmartPRS 2 payComplete, FR-11 step 4).
        $authority = new LicenceAuthority($platform, $root);
        $t = LicenceAuthority::totals($client, $saas);
        $msg = 'Payment received — thank you!';

        if (!$t['total']->isZero() && $t['balance']->isZero() && $authority->liveFor((string) $client['id']) === null) {
            $expiry = LicenceAuthority::resolveExpiry($client);
            $key = LicenceAuthority::generateKey();
            $authority->issue((string) $client['id'], $key, $expiry, (string) ($client['expiry_mode'] ?? 'renew') === 'notify' ? 'notify' : 'renew',
                (int) ($client['seats'] ?? 0) ?: null, $key, 'Razorpay payment ' . $paymentId);
            $to = trim((string) ($client['email'] ?? ''));
            $mail = new SmartPRS\Surface\PlatformMail(new SmartPRS\Surface\PlatformSettings($platform), $root . '/.env');

            if ($to !== '' && $mail->ready()) {
                [$subject, $text] = LicenceAuthority::clientEmail('paid', $client, ['key' => $key, 'until' => date('d-m-Y', (int) strtotime($expiry))]);
                $sent = $mail->send([$to], $subject, $text);
                $msg .= $sent['ok'] ? ' Your licence key has been emailed to ' . $to . '.' : ' Ametecs will send your licence key shortly.';
            } else {
                $msg .= ' Ametecs will send your licence key shortly.';
            }
        }

        json_out(200, ['ok' => true, 'message' => $msg]);
    }

    json_out(400, ['ok' => false, 'error' => 'Unknown request.']);
}

// THE INVOICE PDF (SmartPRS 2 /licence/{token}/pdf).
if ($client !== null && isset($_GET['pdf'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $client['invoice_no']) . '.pdf"');
    echo LicenceAuthority::invoicePdf($client, $saas);
    exit;
}

http_response_code($client === null ? 404 : 200);
$t = $client === null ? null : LicenceAuthority::totals($client, $saas);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>SmartPRS licence — <?= $h($client === null ? 'invoice not found' : (string) $client['invoice_no']) ?></title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f1f5f9;color:#0f172a}
.box{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(15,23,42,.08);padding:28px 30px}
h1{font-size:20px;margin:0 0 4px}.sub{color:#64748b;font-size:14px;margin:0 0 20px}
table{width:100%;border-collapse:collapse;font-size:15px}td{padding:8px 0;border-bottom:1px solid #e2e8f0}td:last-child{text-align:right;font-variant-numeric:tabular-nums}
tr.total td{font-weight:700;border-bottom:0;font-size:17px}
button{margin-top:20px;width:100%;padding:14px;border:0;border-radius:10px;background:#f97316;color:#fff;font-size:16px;font-weight:700;cursor:pointer}
button:disabled{opacity:.6;cursor:default}.msg{margin-top:16px;padding:12px 14px;border-radius:10px;font-size:14px}
.ok{background:#dcfce7;color:#166534}.bad{background:#fee2e2;color:#991b1b}.note{color:#64748b;font-size:13px;margin-top:14px}
</style></head><body><div class="box">
<?php if ($client === null): ?>
  <h1>Invoice not found</h1><p class="sub">This link is not valid. Please use the link in your invoice email, or contact Ametecs.</p>
<?php else: ?>
  <h1>SmartPRS licence — <?= $h((string) $client['company']) ?></h1>
  <p class="sub">Invoice <?= $h((string) $client['invoice_no']) ?><?= trim((string) ($client['invoice_date'] ?? '')) !== '' ? ' · ' . $h(date('d-m-Y', (int) strtotime((string) $client['invoice_date']))) : '' ?></p>
  <table>
    <tr><td>Licence price</td><td><?= $h($t['price']->format()) ?></td></tr>
    <tr><td>GST</td><td><?= $h($t['tax']->format()) ?></td></tr>
    <tr><td>Total</td><td><?= $h($t['total']->format()) ?></td></tr>
    <tr><td>Paid</td><td><?= $h(Amount::of($client['paid_total'] ?? '0')->format()) ?></td></tr>
    <tr class="total"><td>Balance due</td><td><?= $h($t['balance']->format()) ?></td></tr>
  </table>
  <p class="note"><a href="?t=<?= $h(rawurlencode($token)) ?>&amp;pdf=1" target="_blank" rel="noopener">Download the invoice (PDF)</a></p>
<?php if ($t['balance']->isZero()): ?>
  <div class="msg ok">This invoice is fully paid — thank you!</div>
<?php elseif ($gw === null): ?>
  <div class="msg bad">Online payment is not configured yet. Please pay by NEFT and share the reference with Ametecs.</div>
<?php else: ?>
  <button id="pay" type="button">Pay <?= $h($t['balance']->format()) ?> securely</button>
  <div id="msg"></div>
  <p class="note">Payments are processed by Razorpay. On full payment your licence key is generated and emailed to you.</p>
  <script src="<?= $h(SmartPRS\Surface\Signup::CHECKOUT) ?>"></script>
  <script>
  (function () {
    var btn = document.getElementById('pay'), box = document.getElementById('msg'),
        base = {t: <?= json_encode($token) ?>, csrf: <?= json_encode($_SESSION['lic_csrf']) ?>};
    function say(ok, text) { box.className = 'msg ' + (ok ? 'ok' : 'bad'); box.textContent = text; }
    function post(data) {
      return fetch(location.pathname, {method: 'POST', body: new URLSearchParams(Object.assign({}, base, data))}).then(function (r) { return r.json(); });
    }
    btn.addEventListener('click', function () {
      btn.disabled = true;
      post({op: 'order'}).then(function (o) {
        if (!o.ok) { say(false, o.error); btn.disabled = false; return; }
        new Razorpay({key: o.keyId, amount: o.amountPaise, currency: 'INR', order_id: o.orderId, name: 'SmartPRS',
          description: <?= json_encode('Licence invoice ' . (string) $client['invoice_no']) ?>,
          prefill: {name: <?= json_encode((string) ($client['contact_name'] ?? '')) ?>, email: <?= json_encode((string) ($client['email'] ?? '')) ?>, contact: <?= json_encode((string) ($client['mobile'] ?? '')) ?>},
          handler: function (r) {
            post({op: 'complete', razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature})
              .then(function (c) { say(c.ok, c.ok ? c.message : c.error); if (c.ok) { btn.remove(); } else { btn.disabled = false; } });
          },
          modal: {ondismiss: function () { btn.disabled = false; }}}).open();
      }).catch(function () { say(false, 'Could not reach the server — check the connection and try again.'); btn.disabled = false; });
    });
  })();
  </script>
<?php endif; ?>
<?php endif; ?>
</div></body></html>
