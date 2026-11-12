<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Payments</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Payments: the register gateway money and book money are
// reconciled against — every payment, gateway or manual, against its
// invoice. Recording one here settles the invoice when the balance reaches
// zero (surface/actions/payment.php).
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$tenants = $invoices = [];
foreach ($platform->all('tenants') as $t) { $tenants[(string) $t['id']] = (string) $t['name']; }
foreach ($platform->all('invoices') as $i) { $invoices[(string) $i['id']] = $i; }
$rows = $platform->all('payments');
usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['paid_at'] ?? ''), (string) ($a['paid_at'] ?? '')));
$open = array_filter($invoices, static fn (array $i): bool => in_array((string) ($i['status'] ?? ''), (array) $declared['invoice_open'], true));
$Amt = SmartPRS\Surface\Amount::class;
$received = SmartPRS\Platform\Support\Money::zero();
foreach ($rows as $p) { if ((string) ($p['status'] ?? '') === 'succeeded') { $received = $received->plus($Amt::of($p['amount'] ?? '0')); } }
?>
<div class="page-header"><div><h2>Payments</h2>
  <p><?= count($rows) ?> payment(s), newest first · <?= e($received->format()) ?> succeeded in total. Gateway payments arrive by webhook; bank transfers, UPI, cheques and cash are recorded here against their invoice.</p></div>
  <div><a class="btn btn-primary" href="?r=payments&edit=new#form"><i class="fas fa-plus"></i> Record payment</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0">Record a payment</h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="payment"><input type="hidden" name="op" value="save">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div style="min-width:280px"><label>Invoice</label><select name="invoice_id" required><option value="">— open invoices —</option>
<?php foreach ($open as $i): ?>
        <option value="<?= e((string) $i['id']) ?>"><?= e((string) $i['number'] . ' — ' . ($tenants[(string) $i['tenant_id']] ?? 'tenant #' . (string) $i['tenant_id']) . ' · balance ' . SmartPRS\Surface\SaasPlatform::balance($i, $rows)->format()) ?></option>
<?php endforeach; ?></select></div>
      <div><label>Amount ₹</label><input name="amount" inputmode="decimal" pattern="\d+(\.\d{1,2})?" placeholder="blank = the balance"></div>
      <div><label>Mode</label><select name="method"><?php foreach ((array) $declared['payment_methods'] as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label>Reference</label><input name="reference" placeholder="UTR / txn id / cheque no"></div>
      <div><label>Paid on</label><input type="date" name="paid_on" value="<?= e(date('Y-m-d')) ?>"></div>
      <div><label>Status</label><select name="status"><?php foreach ((array) $declared['payment_statuses'] as $s): ?><option<?= $s === 'succeeded' ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Record</button> <a class="btn btn-outline" href="?r=payments">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Paid at</th><th>Tenant</th><th>Invoice</th><th>Amount</th><th>Mode</th><th>Gateway</th><th>Reference</th><th>Status</th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="8" class="note">No payments yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $p): $st = (string) ($p['status'] ?? ''); $inv = $invoices[(string) ($p['invoice_id'] ?? '')] ?? null; ?>
  <tr>
    <td class="note"><?= e(substr((string) ($p['paid_at'] ?? ''), 0, 16)) ?></td>
    <td><b><?= e($tenants[(string) ($p['tenant_id'] ?? '')] ?? ('Tenant #' . (string) ($p['tenant_id'] ?? '?'))) ?></b></td>
    <td><code><?= e($inv === null ? '#' . (string) ($p['invoice_id'] ?? '?') : (string) $inv['number']) ?></code></td>
    <td><b><?= e($Amt::of($p['amount'] ?? '0')->format()) ?></b></td>
    <td><?= e((string) ($p['method'] ?? '')) ?></td>
    <td class="note"><?= e((string) ($p['gateway'] ?? '') ?: '—') ?></td>
    <td class="note" style="font-family:monospace;font-size:11px"><?= e((string) ($p['gateway_txn_id'] ?? '') ?: '—') ?></td>
    <td><span class="badge badge-<?= $st === 'succeeded' ? 'green' : ($st === 'failed' ? 'red' : ($st === 'refunded' ? 'gray' : 'amber')) ?>"><?= e($st) ?></span></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
