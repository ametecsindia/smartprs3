<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Platform Dashboard</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's SaaS dashboard: the founder's scoreboard. Every figure is
// SaasPlatform::dashboard() over the platform-wide store, and every tile says
// where its number came from (Law 4).
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$today = date('Y-m-d');
$d = $saas->dashboard($today);
$tiles = [
    ['Tenants', (string) $d['tenants'], implode(' · ', array_map(static fn ($k, $v): string => "{$v} {$k}", array_keys($d['tenants_by_status']), $d['tenants_by_status'])) ?: 'none yet', 'tenants'],
    ['Active subscriptions', (string) $d['live'], 'trial, active or past due' . ($d['unpriced'] > 0 ? " — {$d['unpriced']} on a plan that no longer exists" : ''), 'subscriptions'],
    ['MRR', $d['mrr']->format(), 'Σ live subscriptions × plan monthly price at their seats', 'subscriptions'],
    ['Invoices due', (string) $d['invoices_due'], $d['invoices_due_amount']->format() . ' outstanding · ' . $d['invoices_overdue'] . ' past due date', 'invoices'],
    ['Leads this month', (string) $d['leads_this_month'], 'created in ' . date('F Y'), 'leads'],
    ['Licences expiring', (string) $d['licences_expiring'], 'AMC ends within ' . (int) ($saas->declared()['licence_expiring_days'] ?? 30) . ' days, or has', 'onprem'],
];
?>
<div class="page-header"><div><h2>Platform Dashboard</h2>
  <p>The SaaS business at a glance — tenants, subscriptions, revenue, dues, leads and licences, as of <?= e($today) ?>. Every tile is a link to the register it is counted from.</p></div></div>
<div class="row" style="flex-wrap:wrap;gap:12px">
<?php foreach ($tiles as [$label, $value, $how, $route]): ?>
  <a class="card" href="?r=<?= e($route) ?>" style="flex:1 1 220px;text-decoration:none;color:inherit">
    <div class="note" style="text-transform:uppercase;font-size:11px;letter-spacing:.4px"><?= e($label) ?></div>
    <div style="font-size:26px;font-weight:700;margin:4px 0"><?= e($value) ?></div>
    <div class="note" style="font-size:12px"><?= e($how) ?></div>
  </a>
<?php endforeach; ?>
</div>
