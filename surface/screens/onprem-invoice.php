<?php declare(strict_types=1); if (!$may('tenant.settings.update')) { return; }
// The on-prem licence TAX INVOICE (SmartPRS 2's licence invoice PDF), printable from the browser.
// Seller from config/gst.json (seller block), buyer from the client, GST split by SaasPlatform::tax.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$c = $platform->find('onprem_clients', (string) ($_GET['client'] ?? ''));
if ($c === null || trim((string) ($c['invoice_no'] ?? '')) === '') { echo '<div class="card"><div class="problem" style="margin:0">No invoice for that client yet — raise it from On-Prem Clients.</div></div>'; return; }
$gst = $saas->gst();
$seller = (array) ($gst['seller'] ?? []);
$price = SmartPRS\Surface\Amount::of($c['price'] ?? '0');
$tax = $saas->tax($price, (string) ($c['state'] ?? ''));
$paid = SmartPRS\Surface\Amount::of($c['paid_total'] ?? '0');
$bal = $tax['total']->minus($paid);
?>
<div class="page-header no-print"><div><h2>Licence invoice <?= e((string) $c['invoice_no']) ?></h2></div>
  <div class="actions"><button class="btn btn-primary" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button> <a class="btn btn-outline" href="?r=onprem&client=<?= e((string) $c['id']) ?>">Back</a></div></div>
<div class="card" style="max-width:820px">
  <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div><h3 style="margin:0"><?= e((string) ($seller['name'] ?? 'Ametecs India Private Limited')) ?></h3>
      <div class="note"><?= e((string) ($seller['address'] ?? '')) ?></div>
      <div class="note"><?= ($seller['gstin'] ?? '') !== '' ? 'GSTIN ' . e((string) $seller['gstin']) : '' ?></div></div>
    <div style="text-align:right"><h3 style="margin:0">TAX INVOICE</h3>
      <div>No. <b><?= e((string) $c['invoice_no']) ?></b></div>
      <div>Date <?= e(SmartPRS\Surface\Dates::dmy(substr((string) ($c['invoice_date'] ?? date('Y-m-d')), 0, 10))) ?></div></div>
  </div>
  <hr>
  <div><b>Bill to:</b> <?= e((string) $c['company']) ?><?= ($c['contact_name'] ?? '') !== '' ? ' · ' . e((string) $c['contact_name']) : '' ?>
    <div class="note"><?= e((string) ($c['address'] ?? '')) ?> <?= e((string) ($c['state'] ?? '')) ?></div>
    <div class="note"><?= ($c['gstin'] ?? '') !== '' ? 'GSTIN ' . e((string) $c['gstin']) : 'Unregistered' ?> <?= ($c['email'] ?? '') !== '' ? ' · ' . e((string) $c['email']) : '' ?></div></div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <thead><tr><th>Description</th><th style="text-align:right">Amount</th></tr></thead>
    <tbody>
      <tr><td>SmartPRS on-premise licence<?= ($c['employee_band'] ?? '') !== '' ? ' — ' . e((string) $c['employee_band']) . ' employees' : '' ?><?= (int) ($c['seats'] ?? 0) > 0 ? ' (cap ' . (int) $c['seats'] . ')' : '' ?>, perpetual, with <?= (int) ($c['licence_term_months'] ?? 12) ?: 12 ?> months' updates &amp; support (SAC 997331)</td><td style="text-align:right"><?= e($price->format()) ?></td></tr>
<?php if ($tax['mode'] === 'CGST+SGST'): ?>
      <tr><td>CGST <?= e((string) ($tax['rate'] / 2)) ?>%</td><td style="text-align:right"><?= e($tax['cgst']->format()) ?></td></tr>
      <tr><td>SGST <?= e((string) ($tax['rate'] / 2)) ?>%</td><td style="text-align:right"><?= e($tax['sgst']->format()) ?></td></tr>
<?php else: ?>
      <tr><td>IGST <?= e((string) $tax['rate']) ?>%</td><td style="text-align:right"><?= e($tax['igst']->format()) ?></td></tr>
<?php endif; ?>
      <tr><td><b>Total</b></td><td style="text-align:right"><b><?= e($tax['total']->format()) ?></b></td></tr>
      <tr><td>Paid</td><td style="text-align:right"><?= e($paid->format()) ?></td></tr>
      <tr><td><b>Balance due</b></td><td style="text-align:right"><b><?= e(($bal->isNegative() ? SmartPRS\Platform\Support\Money::zero() : $bal)->format()) ?></b></td></tr>
    </tbody></table></div>
  <p class="note" style="margin-top:12px">Annual maintenance (AMC) after the first term: <?= e((string) (int) ($c['amc_percent'] ?? 18)) ?>% of the licence price per year. This is a computer-generated invoice.</p>
</div>
