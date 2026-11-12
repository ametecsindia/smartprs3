<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Platform Dashboard</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's SaaS dashboard: the founder's scoreboard (redesigned 12 Nov 2026 — owner). Every figure is still
// SaasPlatform::dashboard() over the platform-wide store, and every tile links to the register it is counted from (Law 4).
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$today = date('Y-m-d');
$d = $saas->dashboard($today);
$pdDmy = static fn (string $t): string => $t === '' ? '—' : SmartPRS\Surface\Dates::dmy(substr($t, 0, 10));
$tiles = [
    ['Tenants', (string) $d['tenants'], implode(' · ', array_map(static fn ($k, $v): string => "{$v} {$k}", array_keys($d['tenants_by_status']), $d['tenants_by_status'])) ?: 'none yet', 'tenants', 'fa-building', '#3b82f6'],
    ['Active subscriptions', (string) $d['live'], 'trial, active or past due' . ($d['unpriced'] > 0 ? " — {$d['unpriced']} on a plan that no longer exists" : ''), 'subscriptions', 'fa-rotate', '#10b981'],
    ['MRR', $d['mrr']->format(), 'live subscriptions × plan monthly price', 'subscriptions', 'fa-indian-rupee-sign', '#f97316'],
    ['Invoices due', (string) $d['invoices_due'], $d['invoices_due_amount']->format() . ' outstanding · ' . $d['invoices_overdue'] . ' past due', 'invoices', 'fa-file-invoice', '#ef4444'],
    ['Leads this month', (string) $d['leads_this_month'], 'created in ' . date('F Y'), 'leads', 'fa-user-plus', '#8b5cf6'],
    ['Licences expiring', (string) $d['licences_expiring'], 'AMC ends within ' . (int) ($saas->declared()['licence_expiring_days'] ?? 30) . ' days', 'onprem', 'fa-key', '#eab308'],
];
$pdTenants = $saas->tenants();
usort($pdTenants, static fn (array $a, array $b): int => (int) $b['employees'] <=> (int) $a['employees']);
$pdLeads = $platform->all('leads');
usort($pdLeads, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
try {
    $pdDemos = (new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env'))->requests();
} catch (\Throwable) {
    $pdDemos = [];
}
$pdDemoActive = count(array_filter($pdDemos, static fn (array $r): bool => (string) $r['status'] === 'active'));
$pdChip = ['active' => 'green', 'trial' => 'blue', 'suspended' => 'red', 'closed' => 'gray', 'expired' => 'gray', 'revoked' => 'red', 'converted' => 'blue'];
?>
<style>
#pd .pd-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
@media (max-width:900px){#pd .pd-tiles{grid-template-columns:repeat(2,1fr)}}@media (max-width:560px){#pd .pd-tiles{grid-template-columns:1fr}}
#pd .pd-tile{display:flex;gap:14px;align-items:flex-start;padding:18px;text-decoration:none;color:inherit;margin:0;transition:transform .12s,box-shadow .12s}
#pd .pd-tile:hover{transform:translateY(-2px);box-shadow:0 10px 24px -12px rgba(15,23,42,.35)}
#pd .pd-ic{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:18px;flex:none}
#pd .pd-lb{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text3,#94a3b8);font-weight:600}
#pd .pd-v{font-size:26px;font-weight:800;line-height:1.2;margin:2px 0}
#pd .pd-how{font-size:12px;color:var(--text3,#94a3b8)}
#pd .pd-grid{display:grid;grid-template-columns:2fr 1fr;gap:14px}
#pd .pd-grid>div{display:grid;gap:14px;align-content:start}
#pd .card{margin:0}
#pd h3{margin:0 0 10px;font-size:15px;display:flex;justify-content:space-between;align-items:center}
#pd h3 a{font-size:12px;font-weight:500}
#pd table td,#pd table th{padding:8px 10px}
#pd .pd-list{list-style:none;margin:0;padding:0}
#pd .pd-list li{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--border,#e2e8f0);font-size:13px}
#pd .pd-list li:last-child{border-bottom:0}
#pd .pd-qa{display:grid;grid-template-columns:1fr 1fr;gap:8px}
#pd .pd-qa a{display:flex;gap:8px;align-items:center;padding:10px;border:1px solid var(--border,#e2e8f0);border-radius:10px;text-decoration:none;color:inherit;font-size:13px}
#pd .pd-qa a:hover{border-color:#f97316}
@media (max-width:980px){#pd .pd-grid{grid-template-columns:1fr}}
</style>
<div id="pd">
<div class="page-header"><div><h2>Platform Dashboard</h2>
  <p>The SaaS business at a glance, as of <?= e($pdDmy($today)) ?>. Every tile opens the register it is counted from.</p></div></div>
<div class="pd-tiles">
<?php foreach ($tiles as [$label, $value, $how, $route, $icon, $color]): ?>
  <a class="card pd-tile" href="?r=<?= e($route) ?>">
    <span class="pd-ic" style="background:<?= e($color) ?>1f;color:<?= e($color) ?>"><i class="fas <?= e($icon) ?>"></i></span>
    <span><span class="pd-lb"><?= e($label) ?></span><div class="pd-v"><?= e($value) ?></div><span class="pd-how"><?= e($how) ?></span></span>
  </a>
<?php endforeach; ?>
</div>
<div class="pd-grid">
  <div>
    <div class="card"><h3>Tenants <a href="?r=tenants">All tenants &rarr;</a></h3>
      <div class="table-wrap"><table><thead><tr><th>Tenant</th><th>Plan</th><th style="text-align:right">Employees</th><th>Onboarded</th><th>Status</th></tr></thead><tbody>
      <?php foreach (array_slice($pdTenants, 0, 8) as $t): $st = (string) ($t['status_code'] ?? ''); ?>
        <tr><td><?= e((string) $t['name']) ?><?= trim((string) ($t['subdomain'] ?? '')) !== '' ? ' <span class="note">/c/' . e((string) $t['subdomain']) . '</span>' : '' ?></td>
          <td><?= e((string) $t['plan']) ?: '—' ?></td><td style="text-align:right"><?= (int) $t['employees'] ?></td>
          <td class="note"><?= e($pdDmy((string) ($t['onboarded_on'] ?? ''))) ?></td>
          <td><span class="badge badge-<?= $pdChip[$st] ?? 'gray' ?>"><?= e(ucfirst($st ?: '—')) ?></span></td></tr>
      <?php endforeach; if ($pdTenants === []): ?><tr><td colspan="5" class="note" style="text-align:center;padding:20px">No tenants yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <div class="card"><h3>Recent leads <a href="?r=leads">All leads &rarr;</a></h3>
      <div class="table-wrap"><table><thead><tr><th>Date</th><th>Name</th><th>Company</th><th>Mobile</th><th>Source</th></tr></thead><tbody>
      <?php foreach (array_slice($pdLeads, 0, 6) as $l): ?>
        <tr><td class="note"><?= e($pdDmy((string) ($l['created_at'] ?? ''))) ?></td><td><?= e((string) ($l['name'] ?? '')) ?></td><td><?= e((string) ($l['company'] ?? '')) ?></td>
          <td><?= e((string) ($l['mobile'] ?? '')) ?></td><td class="note"><?= e(str_replace('_', ' ', (string) ($l['source'] ?? ''))) ?></td></tr>
      <?php endforeach; if ($pdLeads === []): ?><tr><td colspan="5" class="note" style="text-align:center;padding:20px">No leads yet.</td></tr><?php endif; ?>
      </tbody></table></div></div>
  </div>
  <div>
    <div class="card"><h3>Explore Demo <a href="?r=demo-requests">Demo Requests &rarr;</a></h3>
      <div class="pd-v" style="margin-bottom:6px"><?= (int) $pdDemoActive ?> <span class="pd-how" style="font-weight:400">active of <?= count($pdDemos) ?></span></div>
      <ul class="pd-list"><?php foreach (array_slice($pdDemos, 0, 5) as $r): ?>
        <li><span><?= e((string) $r['name']) ?><?= trim((string) ($r['company'] ?? '')) !== '' ? ' <span class="note">· ' . e((string) $r['company']) . '</span>' : '' ?></span>
          <span class="badge badge-<?= $pdChip[(string) $r['status']] ?? 'gray' ?>"><?= e(ucfirst((string) $r['status'])) ?></span></li>
      <?php endforeach; if ($pdDemos === []): ?><li class="note">No demo requests yet.</li><?php endif; ?></ul></div>
    <div class="card"><h3>Quick actions</h3>
      <div class="pd-qa">
        <a href="?r=tenants&amp;edit=new#form"><i class="fas fa-plus" style="color:#3b82f6"></i> Add tenant</a>
        <a href="?r=plans"><i class="fas fa-tags" style="color:#10b981"></i> Plans &amp; pricing</a>
        <a href="?r=invoices"><i class="fas fa-file-invoice" style="color:#ef4444"></i> Invoices</a>
        <a href="?r=landing-cms"><i class="fas fa-globe" style="color:#8b5cf6"></i> Landing page</a>
        <a href="?r=platform-email"><i class="fas fa-envelope" style="color:#f97316"></i> Platform email</a>
        <a href="demo.php" target="_blank" rel="noopener"><i class="fas fa-play" style="color:#eab308"></i> Open /demo</a>
      </div></div>
  </div>
</div>
</div>
