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

<p class="note">To change plan or seats, contact the vendor; the change lands here as a new subscription row, the old one kept.
  SmartPRS 2's subscription alerts table has no tenant column, so it is not shown here.</p>
