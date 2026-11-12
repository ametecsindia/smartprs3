<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>General Settings</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Settings was one form of switches. Here every rule is a file
// under config/ and a tenant's change to it is a versioned customisation, so
// "settings" is the list of those files with their status. Editing happens on
// the screen that owns the rule (Configuration, the module's own Customise,
// Probation Settings, Financial Year…), never by writing a file from here.
$files = (new SmartPRS\Surface\RuleFiles(__DIR__ . '/../../config', $customisation))->inventory();
$customised = count(array_filter($files, static fn (array $f): bool => $f['customised']));
$tenant = $platform->find('tenants', $tenantId) ?? [];
?>
<div class="page-header">
  <div><h2>General Settings</h2>
    <p><?= count($files) ?> rule file(s) ship with the product; <?= $customised ?> customised by this organisation. Storage: <?= e($storageIs) ?>.</p></div>
  <div><a class="btn btn-outline" href="?r=configuration"><i class="fas fa-sliders"></i> Configuration (customisations)</a>
    <a class="btn btn-outline" href="?r=configure-menu"><i class="fas fa-bars"></i> Menu</a></div>
</div>

<div class="card">
  <h3 style="margin-top:0">This organisation</h3>
  <table style="font-size:13px">
    <tr><td class="note" style="padding:2px 12px 2px 0">Name</td><td><b><?= e((string) ($tenant['name'] ?? $company)) ?></b> <span class="badge badge-gray"><?= e((string) ($tenant['code'] ?? '')) ?></span></td></tr>
    <tr><td class="note" style="padding:2px 12px 2px 0">Timezone / currency / locale</td><td><?= e((string) ($tenant['business_timezone'] ?? '')) ?> &middot; <?= e((string) ($tenant['currency_code'] ?? '')) ?> &middot; <?= e((string) ($tenant['locale'] ?? '')) ?></td></tr>
    <tr><td class="note" style="padding:2px 12px 2px 0">Status</td><td><span class="badge badge-<?= (string) ($tenant['status_code'] ?? '') === 'active' ? 'green' : 'amber' ?>"><?= e((string) ($tenant['status_code'] ?? '')) ?></span></td></tr>
  </table>
  <p class="note" style="margin:8px 0 0">Tenant identity is provisioned, not edited from a screen &mdash; it is what every row is scoped by (Law 6).</p>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Rule file</th><th>What it declares</th><th>Customisation key</th><th>Status</th><th>Changed</th><th></th></tr></thead>
    <tbody>
<?php foreach ($files as $f): ?>
      <tr>
        <td><code>config/<?= e($f['file']) ?>.json</code></td>
        <td class="note" style="max-width:520px"><?= e($f['note']) /* in full (owner, 23 Oct) */ ?></td>
        <td><?= $f['key'] === null ? '<span class="note">&mdash;</span>' : '<code>' . e($f['key']) . '</code>' ?></td>
        <td><?php if ($f['customised']): ?><span class="badge badge-amber">customised v<?= (int) $f['version'] ?></span><?php elseif ($f['key'] === null): ?><span class="badge badge-gray">as shipped</span><?php else: ?><span class="badge badge-green">as shipped</span><?php endif; ?></td>
        <td class="note"><?= $f['customised'] ? e($f['changed_by'] . ' · ' . substr($f['at'], 0, 16)) : '' ?></td>
        <td><?php if ($f['key'] !== null): ?><a class="btn btn-sm btn-outline" href="?r=configuration">History</a><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note" style="padding:10px 14px;margin:0">A file with no customisation key is changed only in the repository (it is a product rule, not a tenant setting).
    Files with a key are changed from their own screen and every version is kept &mdash; nothing here writes a file (Law 3).</p>
</div>
