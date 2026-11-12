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

<?php
// CHECK FOR UPDATE / INSTALL ONLINE (owner, 12 Nov 2026). On-prem: ask central for the newest release sent to this
// licence, then Download & install (surface/actions/system-update.php). Cloud: the newest published release on this
// server; Ametecs installs it for every cloud company at once (Super Admin → Releases → Install on cloud server).
$suRunning = (string) ($version['version'] ?? '');
$suCheck = (array) ($_SESSION['update_check'] ?? []);
$suLog = (string) ($_SESSION['update_log'] ?? '');
unset($_SESSION['update_log']);
$suMayInstall = $may('tenant.settings.update');
if (!$onPrem) {
    $suLatest = null; $suNotes = '';
    foreach ($platform->all('releases') as $r) {
        if ((string) ($r['published_at'] ?? '') === '') { continue; }
        (string) $r['version'] === $suRunning && $suNotes = (string) ($r['notes'] ?? '');
        if ($suLatest === null || version_compare((string) $r['version'], (string) $suLatest['version'], '>')) { $suLatest = $r; }
    }
}
?>
<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <b><i class="fas fa-rotate"></i> Software update</b>
<?php if ($onPrem): ?>
    <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="system-update"><input type="hidden" name="op" value="check">
      <button class="btn btn-outline" type="submit"><i class="fas fa-magnifying-glass"></i> Check for update</button></form>
<?php endif; ?>
  </div>
<?php if ($onPrem): ?>
<?php if ($suCheck === []): ?>
  <p class="note" style="margin:8px 0 0">Click <b>Check for update</b> to see whether Ametecs has released a newer version to your licence.</p>
<?php elseif (!empty($suCheck['update_available'])): ?>
  <p style="margin:8px 0 4px"><b>Version <?= e((string) $suCheck['version']) ?> is available</b> <span class="note">(you have <?= e($suRunning) ?><?= !empty($suCheck['released_at']) ? ' · released ' . e(SmartPRS\Surface\Dates::dmy(substr((string) $suCheck['released_at'], 0, 10))) : '' ?><?= (int) ($suCheck['size_bytes'] ?? 0) > 0 ? ' · ' . round((int) $suCheck['size_bytes'] / 1048576, 1) . ' MB' : '' ?>)</span></p>
  <div class="note" style="white-space:pre-wrap"><?= e((string) ($suCheck['notes'] ?? '')) ?></div>
<?php if ($suMayInstall): ?>
  <form method="post" style="margin:10px 0 0" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='Downloading and installing — keep this page open…';return confirm('Download and install <?= e((string) $suCheck['version']) ?> now? Files being replaced are backed up first. Users may see a brief pause.')">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="system-update"><input type="hidden" name="op" value="install"><input type="hidden" name="version" value="<?= e((string) $suCheck['version']) ?>">
    <button class="btn btn-primary" type="submit"><i class="fas fa-download"></i> Download &amp; install <?= e((string) $suCheck['version']) ?></button></form>
<?php else: ?><p class="note">Ask your administrator to install it.</p><?php endif; ?>
<?php else: ?>
  <p class="note" style="margin:8px 0 0"><?= e((string) ($suCheck['message'] ?? 'You are on the latest version.')) ?> <span style="font-size:11px">(checked <?= e((string) ($suCheck['checked_at'] ?? '')) ?>)</span></p>
<?php endif; ?>
<?php else: ?>
<?php if ($suLatest !== null && version_compare((string) $suLatest['version'], $suRunning, '>')): ?>
  <p style="margin:8px 0 4px"><b>Version <?= e((string) $suLatest['version']) ?> has been released</b> <span class="note">— Ametecs is installing it on the cloud for every company; nothing to do on your side.</span></p>
  <div class="note" style="white-space:pre-wrap"><?= e((string) ($suLatest['notes'] ?? '')) ?></div>
<?php else: ?>
  <p style="margin:8px 0 4px"><i class="fas fa-circle-check" style="color:#16a34a"></i> You are on the latest version (<?= e($suRunning) ?>). Ametecs installs every update on the cloud for you.</p>
<?php if ($suNotes !== ''): ?><div class="note" style="white-space:pre-wrap"><b>What's new:</b> <?= e($suNotes) ?></div><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($suLog !== ''): ?><pre style="white-space:pre-wrap;font-size:12px;max-height:300px;overflow:auto;margin:10px 0 0"><?= e($suLog) ?></pre><?php endif; ?>
</div>
