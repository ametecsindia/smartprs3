<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>My Subscription</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's My Subscription: plan, seats, cycle, renewal, and the alerts
// raised about it. Read-only — a plan is changed commercially, not by the
// customer clicking. `plans` is a platform catalogue (no tenant column); the
// subscription rows are the tenant's own.
use SmartPRS\Surface\Licensing;

$today = date('Y-m-d');
$seatsUsed = count(liveRows($store, 'employees'));
$sub = Licensing::subscription(liveRows($store, 'subscriptions'), $platform->all('plans'), $today, $seatsUsed);
$badge = static fn (?int $days): string => $days === null ? 'gray' : ($days < 0 ? 'red' : ($days <= 30 ? 'amber' : 'green'));
?>
<div class="page-header">
  <div><h2>My Subscription</h2><p>The plan this organisation is on, and how much of it is in use.</p></div>
  <div><a class="btn btn-outline" href="?r=sys-updates"><i class="fas fa-cloud-arrow-down"></i> Updates &amp; Licence</a></div>
</div>

<?php if ($sub === null): ?>
<div class="card"><p class="note" style="margin:0">No subscription row for this organisation &mdash; an on-premise installation is licensed instead (see Updates &amp; Licence).
  <?= $seatsUsed ?> active employee(s) in use.</p></div>
<?php else: ?>
<div class="row" style="gap:12px;flex-wrap:wrap;margin-bottom:12px">
  <div class="card" style="flex:1;min-width:160px;margin:0"><div class="note">Plan</div><div style="font-size:20px;font-weight:800"><?= e($sub['plan']) ?></div><span class="badge badge-<?= $sub['status'] === 'active' ? 'green' : 'amber' ?>"><?= e($sub['status']) ?></span> <span class="note"><?= e($sub['cycle']) ?></span></div>
  <div class="card" style="flex:1;min-width:160px;margin:0"><div class="note">Seats</div><div style="font-size:20px;font-weight:800"><?= (int) $sub['seats_used'] ?> / <?= (int) $sub['seats'] ?: '∞' ?></div>
    <span class="note"><?= $sub['seats_left'] === null ? 'unlimited' : ($sub['seats_left'] < 0 ? '<b style="color:#b91c1c">' . -$sub['seats_left'] . ' over</b>' : $sub['seats_left'] . ' free') ?> &middot; used = active employees</span></div>
  <div class="card" style="flex:1;min-width:160px;margin:0"><div class="note">Companies</div><div style="font-size:20px;font-weight:800"><?= count(liveRows($store, 'companies')) ?> / <?= (int) $sub['companies'] ?: '∞' ?></div></div>
  <div class="card" style="flex:1;min-width:160px;margin:0"><div class="note">Period ends</div><div style="font-size:20px;font-weight:800"><?= e($sub['period_end'] ?: '—') ?></div>
    <span class="badge badge-<?= $badge($sub['days_left']) ?>"><?= $sub['days_left'] === null ? 'no end date' : ($sub['days_left'] < 0 ? 'expired ' . -$sub['days_left'] . ' day(s) ago' : $sub['days_left'] . ' day(s) left') ?></span>
    <div class="note">next renewal <?= e($sub['next_renewal'] ?: '—') ?><?= $sub['amount'] !== '' ? ' · ' . e(SmartPRS\Surface\Amount::of($sub['amount'])->format()) : '' ?></div></div>
</div>
<?php endif; ?>

<?php // RENEW ONLINE (owner, 13 Nov 2026 — SmartPRS 2 My Subscription → Renew): the price is worked out on the server
// (surface/actions/my-renew.php) for the employees, companies and period chosen, then paid by Razorpay.
if ($may('tenant.settings.update') && !$licence->isOnPrem()):
    $rnUsed = count(array_filter(liveRows($store, 'employees'), static fn (array $e): bool => !in_array(strtolower(trim((string) ($e['status'] ?? ''))), SmartPRS\Surface\Licence::SEAT_FREE, true)));
    $rnCos = count(liveRows($store, 'companies'));
    $rnEmp = max($rnUsed, (int) ($sub['seats'] ?? 0), 1);
    $rnCo = max($rnCos, (int) ($sub['companies'] ?? 0), 1);
    $rnCycles = (array) (((new SmartPRS\Surface\LandingContent(__DIR__ . '/../../config/landing.json', new SmartPRS\Surface\PlatformSettings($platform)))->content()['pricing']['cloud']['cycles'] ?? [])); ?>
<div class="card" style="margin-bottom:12px" id="renew"><h3 style="margin:0 0 4px;font-size:15px">Renew online</h3>
  <p class="note" style="margin:0 0 10px">Renewing early never loses days — the new period starts when the current one ends. At least <?= $rnUsed ?> employee(s) and <?= $rnCos ?> company(ies), as in use now.</p>
  <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div><label>Employees</label><input type="number" id="rn-emp" min="<?= max(1, $rnUsed) ?>" value="<?= $rnEmp ?>" style="width:110px"></div>
    <div><label>Companies</label><input type="number" id="rn-co" min="<?= max(1, $rnCos) ?>" value="<?= $rnCo ?>" style="width:90px"></div>
    <div><label>Period</label><select id="rn-months"><?php foreach ($rnCycles as $c): ?><option value="<?= (int) $c['months'] ?>"<?= (int) $c['months'] === 12 ? ' selected' : '' ?>><?= e((string) $c['label']) ?><?= (int) $c['discount'] > 0 ? ' — ' . (int) $c['discount'] . '% off' : '' ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-outline" type="button" id="rn-quote">Get price</button>
    <button class="btn btn-primary" type="button" id="rn-pay" disabled><i class="fas fa-lock"></i> Pay &amp; renew</button>
  </div>
  <div id="rn-out" class="note" style="margin-top:10px"></div>
</div>
<script src="<?= e(SmartPRS\Surface\Signup::CHECKOUT) ?>"></script>
<script>
(function () {
  var out = document.getElementById('rn-out'), pay = document.getElementById('rn-pay'), csrf = <?= json_encode($session->token()) ?>;
  function call(step, extra) {
    var b = new URLSearchParams(Object.assign({action: 'my-renew', csrf: csrf, step: step, employees: document.getElementById('rn-emp').value,
      companies: document.getElementById('rn-co').value, months: document.getElementById('rn-months').value}, extra || {}));
    return fetch('?r=my-subscription', {method: 'POST', body: b, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }
  function say(ok, html) { out.innerHTML = html; out.style.color = ok ? '' : '#b91c1c'; }
  ['rn-emp', 'rn-co', 'rn-months'].forEach(function (id) { document.getElementById(id).addEventListener('change', function () { pay.disabled = true; say(true, ''); }); });
  document.getElementById('rn-quote').addEventListener('click', function () {
    call('quote').then(function (q) {
      if (!q.ok) { say(false, q.error); pay.disabled = true; return; }
      say(true, '<b>' + q.plan + '</b> · ' + q.period + (q.discount ? ' (' + q.discount + '% off)' : '') + ' — subscription ' + q.subscription + ' + GST ' + q.tax + ' = <b>' + q.total + '</b>');
      pay.disabled = false;
    }).catch(function () { say(false, 'Could not reach the server — try again.'); });
  });
  pay.addEventListener('click', function () {
    pay.disabled = true;
    call('order').then(function (o) {
      if (!o.ok) { say(false, o.error); pay.disabled = false; return; }
      new Razorpay({key: o.keyId, amount: o.amountPaise, currency: 'INR', order_id: o.orderId, name: 'SmartPRS', description: 'Subscription renewal — ' + o.company,
        prefill: {email: o.email}, modal: {ondismiss: function () { pay.disabled = false; }},
        handler: function (r) {
          call('complete', {uuid: o.uuid, razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature})
            .then(function (c) { say(c.ok, c.ok ? c.message : c.error); if (c.ok) { setTimeout(function () { location.reload(); }, 2500); } });
        }}).open();
    }).catch(function () { say(false, 'Could not reach the server — try again.'); pay.disabled = false; });
  });
})();
</script>
<?php endif; ?>

<?php // YOUR INVOICES (owner, 13 Nov 2026: PDF for cloud subscribers too) — this organisation's own, newest first.
$myInv = liveRows($store, 'invoices');
usort($myInv, static fn (array $a, array $b): int => strcmp((string) ($b['issued_on'] ?? ''), (string) ($a['issued_on'] ?? '')));
$myPay = liveRows($store, 'payments'); ?>
<div class="card" style="padding:0;margin-bottom:12px"><div class="card-header" style="padding:14px 16px 0"><h3 style="font-size:15px;margin:0">Invoices</h3></div>
  <div class="table-wrap"><table><thead><tr><th>Number</th><th>Issued</th><th>Period</th><th style="text-align:right">Total</th><th style="text-align:right">Balance</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($myInv === []): ?><tr><td colspan="7" class="note">No invoices yet.</td></tr><?php endif; ?>
<?php foreach ($myInv as $mi): $miTotal = SmartPRS\Surface\Amount::of($mi['amount'] ?? '0')->plus(SmartPRS\Surface\Amount::of($mi['tax'] ?? '0')); ?>
    <tr><td><code><?= e((string) ($mi['number'] ?? '')) ?></code></td><td><?= e(SmartPRS\Surface\Dates::dmy((string) ($mi['issued_on'] ?? ''))) ?></td>
      <td class="note"><?= e(SmartPRS\Surface\Dates::dmy((string) ($mi['period_from'] ?? '')) . ' → ' . SmartPRS\Surface\Dates::dmy((string) ($mi['period_to'] ?? ''))) ?></td>
      <td style="text-align:right"><?= e($miTotal->format()) ?></td><td style="text-align:right"><?= e(SmartPRS\Surface\SaasPlatform::balance($mi, $myPay)->format()) ?></td>
      <td><span class="badge badge-<?= (string) ($mi['status'] ?? '') === 'paid' ? 'green' : ((string) ($mi['status'] ?? '') === 'cancelled' ? 'gray' : 'amber') ?>"><?= e((string) ($mi['status'] ?? '')) ?></span></td>
      <td><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="my-invoice"><input type="hidden" name="id" value="<?= e((string) $mi['id']) ?>">
        <button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-file-pdf"></i> PDF</button></form></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>

<p class="note">To change plan or seats, contact the vendor; the change lands here as a new subscription row, the old one kept.
  SmartPRS 2's subscription alerts table has no tenant column, so it is not shown here.</p>
