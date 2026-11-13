<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Invoices</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Invoices: GST-correct, sequenced, per subscription. The tax is
// SaasPlatform::tax() from config/gst.json and the tenant's state — CGST+SGST
// at home, IGST elsewhere — and the number is prefix-year-sequence. Mark paid
// records a payment row and flips the status. Written by
// surface/actions/invoice.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$gst = $saas->gst();
$tenants = $plans = [];
foreach ($platform->all('tenants') as $t) { $tenants[(string) $t['id']] = $t; }
foreach ($platform->all('plans') as $p) { $plans[(string) $p['id']] = (string) $p['name']; }
$subs = $platform->all('subscriptions');
$payments = $platform->all('payments');
$rows = $platform->all('invoices');
usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['issued_on'] ?? ''), (string) ($a['issued_on'] ?? '')) ?: (int) $b['id'] - (int) $a['id']);
$today = date('Y-m-d');
$open = (array) $declared['invoice_open'];
$next = $saas->invoiceNumber(count($rows) + 1, $today);
$Amt = SmartPRS\Surface\Amount::class;
// Each tenant's administrator email, the default "send to" for its invoices.
$adminMail = [];
foreach ($platform->all('users') as $u) { if ((string) ($u['role'] ?? $u['status'] ?? '') === 'administrator' && !isset($adminMail[(string) ($u['tenant_id'] ?? '')])) { $adminMail[(string) ($u['tenant_id'] ?? '')] = (string) ($u['email'] ?? ''); } }
$hidI = static fn (string $op, string $iid): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="invoice"><input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . e($iid) . '">';
?>
<div class="page-header"><div><h2>Invoices</h2>
  <p><?= count($rows) ?> invoice(s), newest first. GST at <?= (int) $gst['rate_percent'] ?>% — CGST+SGST for tenants in state <?= e((string) $gst['home_state_code']) ?>, IGST for every other state — computed when the invoice is raised, from the tenant's state on the Tenants screen.</p></div>
  <div><a class="btn btn-primary" href="?r=invoices&edit=new#form"><i class="fas fa-plus"></i> Raise invoice</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0">Raise invoice <code><?= e($next) ?></code></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="invoice"><input type="hidden" name="op" value="save">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div style="min-width:260px"><label>Subscription</label><select name="subscription_id" required><option value="">— choose —</option>
<?php foreach ($subs as $s): $t = $tenants[(string) $s['tenant_id']] ?? null; ?>
        <option value="<?= e((string) $s['id']) ?>"><?= e(($t === null ? 'Tenant #' . (string) $s['tenant_id'] : (string) $t['name']) . ' — ' . ($plans[(string) $s['plan_id']] ?? 'plan #' . (string) $s['plan_id']) . ', ' . (int) $s['seats'] . ' seats, ' . (string) $s['cycle'] . ' · ' . $Amt::of($s['amount'] ?? '0')->format()) ?></option>
<?php endforeach; ?></select><span class="note">the tenant's state decides the GST split</span></div>
      <div><label>Taxable amount ₹</label><input name="amount" inputmode="decimal" pattern="\d+(\.\d{1,2})?" placeholder="blank = subscription amount"><span class="note">GST is added on top</span></div>
      <div><label>Period from</label><input type="date" name="period_from" value="<?= e($today) ?>"></div>
      <div><label>Period to</label><input type="date" name="period_to"></div>
      <div><label>Issued on</label><input type="date" name="issued_on" value="<?= e($today) ?>"></div>
      <div><label>Due on</label><input type="date" name="due_on" value="<?= e((new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d')) ?>"></div>
      <div style="flex-basis:100%"><label>Notes</label><input name="notes" style="width:100%"></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Raise as issued</button> <a class="btn btn-outline" href="?r=invoices">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Number</th><th>Tenant</th><th>Period</th><th>Taxable</th><th>GST</th><th>Total</th><th>Balance</th><th>Issued / due</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="10" class="note">No invoices yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $i): $st = (string) ($i['status'] ?? ''); $t = $tenants[(string) ($i['tenant_id'] ?? '')] ?? null;
    $total = $Amt::of($i['amount'] ?? '0')->plus($Amt::of($i['tax'] ?? '0')); $bal = SmartPRS\Surface\SaasPlatform::balance($i, $payments);
    $overdue = in_array($st, $open, true) && (string) ($i['due_on'] ?? '') !== '' && (string) $i['due_on'] < $today; ?>
  <tr>
    <td><code><?= e((string) ($i['number'] ?? '')) ?></code></td>
    <td><b><?= e($t === null ? 'Tenant #' . (string) ($i['tenant_id'] ?? '?') : (string) $t['name']) ?></b><div class="note" style="font-size:11px"><?= e((string) ($t['state'] ?? '') ?: 'state not set') ?></div></td>
    <td class="note"><?= e(((string) ($i['period_from'] ?? '') ?: '—') . ' → ' . ((string) ($i['period_to'] ?? '') ?: '—')) ?></td>
    <td><?= e($Amt::of($i['amount'] ?? '0')->format()) ?></td>
    <td><?= e($Amt::of($i['tax'] ?? '0')->format()) ?><div class="note" style="font-size:11px"><?= e($saas->tax($Amt::of($i['amount'] ?? '0'), (string) ($t['state'] ?? ''))['mode']) ?></div></td>
    <td><b><?= e($total->format()) ?></b></td>
    <td><?= e($bal->format()) ?></td>
    <td class="note"><?= e((string) ($i['issued_on'] ?? '')) ?><div style="font-size:11px;<?= $overdue ? 'color:#dc2626;font-weight:700' : '' ?>">due <?= e((string) ($i['due_on'] ?? '') ?: '—') ?></div></td>
    <td><span class="badge badge-<?= $st === 'paid' ? 'green' : ($overdue || $st === 'overdue' ? 'red' : ($st === 'cancelled' ? 'gray' : 'amber')) ?>"><?= e($overdue && $st !== 'overdue' ? 'overdue' : $st) ?></span><?php if ((string) ($i['paid_on'] ?? '') !== ''): ?><div class="note" style="font-size:11px">paid <?= e((string) $i['paid_on']) ?></div><?php endif; ?></td>
    <td style="white-space:nowrap">
      <form method="post" style="display:inline"><?= $hidI('pdf', (string) $i['id']) ?><button class="btn btn-sm btn-outline" type="submit" title="Download the invoice as a PDF"><i class="fas fa-file-pdf"></i> PDF</button></form>
      <button class="btn btn-sm btn-outline" type="button" onclick="this.nextElementSibling.showModal()" title="Email the PDF invoice"><i class="fas fa-envelope"></i> Email</button>
      <dialog class="help-dlg" style="width:min(420px,94vw);height:fit-content;padding:20px 22px;white-space:normal">
        <h3 style="margin:0 0 10px">Email invoice <?= e((string) ($i['number'] ?? '')) ?></h3>
        <form method="post"><?= $hidI('email', (string) $i['id']) ?><label>Send the PDF to</label>
          <input type="email" name="to" required value="<?= e($adminMail[(string) ($i['tenant_id'] ?? '')] ?? '') ?>" style="width:100%">
          <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-paper-plane"></i> Send</button> <button class="btn btn-outline" type="button" onclick="this.closest('dialog').close()">Close</button></p></form>
      </dialog>
<?php if (in_array($st, $open, true)): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="invoice"><input type="hidden" name="op" value="mark-paid"><input type="hidden" name="id" value="<?= e((string) $i['id']) ?>">
        <select name="method" style="width:auto"><?php foreach ((array) $declared['payment_methods'] as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select>
        <input name="reference" placeholder="UTR / cheque no" style="width:130px">
        <button class="btn btn-sm btn-primary" type="submit">Mark paid</button></form>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="invoice"><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= e((string) $i['id']) ?>"><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Cancel this invoice?')">Cancel</button></form>
<?php endif; ?></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
