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
