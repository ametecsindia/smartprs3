<?php
/**
 * EXPLORE DEMO — "PREPARING YOUR DEMO COMPANY" (owner, 12 Nov 2026). The first page after a demo sign-in: it posts
 * surface/actions/demo-setup.php once to build the sample data, then shows the ready-made logins for each role.
 */
declare(strict_types=1);

$dsTenant = $platform->find('tenants', (string) $tenantId);

if (!SmartPRS\Surface\DemoGate::isDemoTenant($dsTenant)): ?>
<div class="page-header"><div><h2>Explore Demo</h2></div></div>
<div class="card"><p class="note" style="margin:0">This page is only for an Explore Demo company.</p></div>
<?php return; endif;

$dsDone = $customisation->live()[SmartPRS\Surface\DemoSeed::DONE_KEY] ?? null;
$dsGate = new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');
$dsCfg = $dsGate->config();
$dsRun = $_SESSION['demo_seed'] ?? null;
$dsPass = is_array($_SESSION['demo_pass'] ?? null) ? $platform->find('demo_requests', (string) $_SESSION['demo_pass']['id']) : null;
$dsSignIn = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/app.php'))), '/') . '/c/' . (string) ($dsTenant['subdomain'] ?? '');
$dsRoles = ['admin' => 'Branch Admin', 'manager' => 'Operations Manager', 'leader' => 'Team Lead', 'member' => 'Agent'];
?>
<style>#ds-card .ds-row{display:flex;gap:14px;padding:7px 0;border-bottom:1px solid var(--line,#e2e8f0)}#ds-card .ds-row span:first-child{width:170px;color:var(--muted,#64748b)}</style>
<div class="page-header"><div><h2>Your demo company</h2>
  <p><?= e((string) ($dsTenant['name'] ?? '')) ?><?= $dsPass !== null ? ' · available until ' . e(SmartPRS\Surface\Dates::dmy(substr((string) $dsPass['expires_at'], 0, 10))) : '' ?></p></div></div>
<?php if ($dsDone === null && ($dsFail = (string) ($_SESSION['demo_seed_failed'] ?? '')) !== ''): unset($_SESSION['demo_seed_failed']); ?>
<div class="card" id="ds-card"><div class="problem" style="margin:0 0 10px"><?= e($dsFail) ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="demo-setup">
    <button class="go" type="submit">Try again</button></form></div>
<?php return; endif; ?>
<?php if ($dsDone === null): ?>
<div class="card" id="ds-card">
  <p style="margin:0 0 10px"><i class="fas fa-spinner fa-spin"></i> Preparing your demo company — branches, teams, logins, two months of attendance, leaves and payroll. This takes about a minute; keep this page open.</p>
  <form method="post" id="ds-build"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="demo-setup">
    <noscript><button class="go" type="submit">Build my demo company</button></noscript></form>
</div>
<script>setTimeout(function(){document.getElementById('ds-build').submit();},300);</script>
<?php return; endif; ?>
<div class="card" id="ds-card">
  <p style="margin:0 0 12px"><i class="fas fa-circle-check" style="color:#15803d"></i> Your demo company is ready. Everything is open — add your own employees, change settings, run payroll, and test anything.</p>
  <?php if (is_array($dsRun)): ?>
  <div class="ds-row"><span>People</span><span><?= (int) $dsRun['people'] ?></span></div>
  <div class="ds-row"><span>Punches</span><span><?= (int) $dsRun['punches'] ?></span></div>
  <div class="ds-row"><span>Leaves approved</span><span><?= (int) $dsRun['leaves'] ?></span></div>
  <div class="ds-row"><span>Payroll generated</span><span><?= e(implode(', ', array_map(static fn (string $p): string => date('M Y', strtotime($p . '-01')), (array) $dsRun['payroll']))) ?: '—' ?></span></div>
  <?php endif; ?>
  <p style="margin:16px 0 6px">To see the application as each role sees it, sign out and sign in at <b><?= e($dsSignIn) ?></b> with one of these logins:</p>
  <?php foreach ($dsRoles as $k => $label): $em = (string) (((array) ($dsDone['logins'] ?? []))[$k] ?? ''); if ($em === '') { continue; } ?>
  <div class="ds-row"><span><?= e($label) ?></span><span><?= e($em) ?></span></div>
  <?php endforeach; ?>
  <div class="ds-row"><span>Password (all)</span><span><?= e((string) ($dsCfg['password'] ?? '')) ?></span></div>
  <p style="margin:16px 0 0"><a class="btn go" href="?r=dashboard"><i class="fas fa-gauge"></i> Open the dashboard</a></p>
</div>
