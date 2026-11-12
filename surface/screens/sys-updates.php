<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Updates &amp; Licence</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Updates & Licence: current version, deployment, licensed-to, validity, seats and
// this server's fingerprint. On-premise the licence is surface/Licence.php's state (activated on
// /activate against the licence server); on the cloud there is no licence — the subscription is
// My Subscription. Installing an update stays an operator's act on the server (deploy/DEPLOY.md).
$version = json_decode((string) file_get_contents(__DIR__ . '/../../config/version.json'), true);
$version = is_array($version) ? $version : [];
$ls = $licence->status();
$onPrem = $licence->isOnPrem();
$active = count(array_filter(liveRows($store, 'employees'), static fn (array $se): bool
    => !in_array(strtolower(trim((string) ($se['status'] ?? ''))), SmartPRS\Surface\Licence::SEAT_FREE, true)));
$st = $licence->state();
$label = ['none' => 'Not activated', 'ok' => 'Active', 'expired' => 'Expired'][$ls['state']] ?? $ls['state'];
// CLOUD: the workspace's plan instead of a licence — its live subscription (seats = the employee cap).
$cloudSub = null;
$cloudPlan = '';
if (!$onPrem && $tenantId !== '') {
    $subLive = (array) (json_decode((string) @file_get_contents(__DIR__ . '/../../config/plans.json'), true)['subscription_live'] ?? []);
    foreach ($platform->forTenant($tenantId)->all('subscriptions') as $s) {
        if (in_array((string) ($s['status'] ?? ''), $subLive, true)
            && ($cloudSub === null || (string) ($s['current_period_end'] ?? '') > (string) ($cloudSub['current_period_end'] ?? ''))) {
            $cloudSub = $s;
        }
    }
    $cloudPlan = $cloudSub === null ? '' : (string) ($platform->find('plans', (string) ($cloudSub['plan_id'] ?? ''))['name'] ?? '');
}
?>
<div class="page-header">
  <div><h2>Updates &amp; Licence</h2><p>What is installed and what it is licensed for.</p></div>
  <div><a class="btn btn-outline" href="?r=health"><i class="fas fa-heart-pulse"></i> System Health</a>
<?php if ($onPrem): ?><a class="btn btn-primary" href="activate.php"><i class="fas fa-key"></i> Activate / renew licence</a><?php else: ?>
    <a class="btn btn-outline" href="?r=my-subscription"><i class="fas fa-receipt"></i> My Subscription</a><?php endif; ?></div>
</div>

<div class="row" style="gap:12px;flex-wrap:wrap;margin-bottom:12px">
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Current version</div><div style="font-size:20px;font-weight:800"><?= e((string) ($version['product'] ?? 'SmartPRS')) ?> <?= e((string) ($version['version'] ?? '—')) ?></div><span class="note"><?= e((string) ($version['channel'] ?? '')) ?></span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Deployment</div><div style="font-size:20px;font-weight:800"><?= $onPrem ? 'On-premise' : 'Cloud' ?></div><span class="note"><?= $onPrem ? 'licence server ' . e($licence->server()) : 'subscription-billed' ?></span></div>
<?php if ($onPrem): ?>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Licensed to</div><div style="font-size:20px;font-weight:800"><?= e($ls['company'] ?: '—') ?></div><span class="note"><?= e($ls['source'] === 'file' ? '.lic file (offline)' : ($ls['source'] === 'online' ? 'License Code (online)' : '')) ?></span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Valid till</div><div style="font-size:20px;font-weight:800"><?= e($ls['expires_on'] ? SmartPRS\Surface\Dates::dmy($ls['expires_on']) : '—') ?></div><span class="note">on expiry: <?= $ls['mode'] === 'notify' ? 'keeps running' : 'stops until renewed' ?></span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Status</div><div style="font-size:20px;font-weight:800"><?= e($label) ?></div><span class="note">last check-in <?= e((string) ($st['last_ok'] ?? 'never')) ?></span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Employees</div><div style="font-size:20px;font-weight:800"><?= $active ?><?= $ls['seats'] !== null ? ' / ' . (int) $ls['seats'] : '' ?></div><span class="note"><?= $ls['seats'] !== null ? 'licence cap' : 'no cap' ?></span></div>
<?php elseif ($tenantId !== ''): ?>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Plan</div><div style="font-size:20px;font-weight:800"><?= e($cloudPlan ?: ($cloudSub === null ? 'No plan on record' : '—')) ?></div><span class="note"><?= e($cloudSub === null ? '' : ucfirst((string) $cloudSub['status'])) ?></span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Valid till</div><div style="font-size:20px;font-weight:800"><?= e(($cloudSub['current_period_end'] ?? '') !== '' ? SmartPRS\Surface\Dates::dmy(substr((string) $cloudSub['current_period_end'], 0, 10)) : '—') ?></div><span class="note">renew under My Subscription</span></div>
  <div class="card" style="flex:1;min-width:150px;margin:0"><div class="note">Employees</div><div style="font-size:20px;font-weight:800"><?= $active ?><?= (int) ($cloudSub['seats'] ?? 0) > 0 ? ' / ' . (int) $cloudSub['seats'] : '' ?></div><span class="note"><?= (int) ($cloudSub['seats'] ?? 0) > 0 ? 'active employees / plan limit' : 'no limit on record' ?></span></div>
<?php endif; ?>
</div>

<?php if ($onPrem): ?>
<div class="card"><b>This server's fingerprint</b> <code style="user-select:all"><?= e($licence->fingerprint()) ?></code>
  <p class="note" style="margin:6px 0 0">Ametecs needs it to issue a machine-locked .lic file. A License Code binds to it automatically on activation; moving to a new server needs Ametecs to release the old binding.</p></div>
<?php endif; ?>

<?php if ($onPrem): ?>
<div class="card">
  <b>Updates are applied by your IT team on this server.</b>
  <p class="note" style="margin:6px 0 0">Copy the new release over the install (or <code>git pull</code>), run <code>php tools/db/migrate.php</code> and
    <code>php tools/db/grant-application-account.php --apply</code> — deploy/DEPLOY.md has the steps.</p>
</div>
<?php else: ?>
<div class="card">
  <b><i class="fas fa-circle-check" style="color:#16a34a"></i> Your workspace is kept up to date automatically.</b>
  <p class="note" style="margin:6px 0 0">Ametecs installs every SmartPRS update on the cloud for you — there is nothing to download or run.</p>
</div>
<?php endif; ?>
