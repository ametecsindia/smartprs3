<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Releases &amp; Updates</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Releases: a version with its notes, published on a date,
// granted to on-prem clients (release_grants) who then report what they
// applied (client_updates). Cloud tenants run whatever config/version.json
// says on this server — a release here is the RECORD of a build, never the
// deployment of one (the operator does that). Written by
// surface/actions/release.php.
$rows = $platform->all('releases');
usort($rows, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
$clients = [];
foreach ($platform->all('onprem_clients') as $c) { $clients[(string) $c['id']] = (string) $c['company']; }
$grants = $applied = [];
foreach ($platform->all('release_grants') as $g) { $grants[(string) $g['release_id']][] = $g; }
foreach ($platform->all('client_updates') as $u) { $applied[(string) $u['version']][] = $u; }
$editing = isset($_GET['edit']) ? $platform->find('releases', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$running = (string) ((array) json_decode((string) file_get_contents(__DIR__ . '/../../config/version.json'), true))['version'] ?? '';
?>
<div class="page-header"><div><h2>Releases &amp; Updates</h2>
  <p><?= count($rows) ?> release(s), newest first. This server runs <code><?= e($running ?: '?') ?></code>. Make the package with <b>make-release.bat</b> (it lands in C:\SmartPRS3-Releases), add it here, Publish, then <b>Install on cloud server</b> and tick the on-prem clients to send it to. Steps: deploy/RELEASE-AND-UPDATE.md.</p></div>
  <div><a class="btn btn-primary" href="?r=releases&edit=new#form"><i class="fas fa-plus"></i> New release</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New release' : 'Edit ' . e($v('version')) ?></h3>
  <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="release"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
<?php $relOnServer = array_map('basename', array_merge(glob(dirname(__DIR__, 2) . '/storage/releases/*.sprs') ?: [], glob(dirname(__DIR__, 2) . '/storage/releases/*.zip') ?: [])); rsort($relOnServer, SORT_NATURAL); ?>
      <div style="flex-basis:100%"><label>Release file (.sprs) from C:\SmartPRS3-Releases</label><input type="file" name="package" accept=".sprs,.zip"><span class="note">one file — the package and its signature together</span></div>
      <details style="flex-basis:100%"><summary class="note" style="cursor:pointer">Older two-file package (.zip + .sig)?</summary>
        <label style="margin-top:6px">Signature (.sig) — only with an older .zip</label><input type="file" name="signature" accept=".sig"></details>
<?php if ($relOnServer !== []): ?>
      <div style="flex-basis:100%"><label>…or a package already copied into storage/releases on this server</label><select name="server_file"><option value="">—</option><?php foreach ($relOnServer as $rf): ?><option><?= e($rf) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
      <div><label>Version <span class="note">(read from the package)</span></label><input name="version" value="<?= e($v('version')) ?>" placeholder="3.1.0" pattern="[0-9]+(\.[0-9]+)*[A-Za-z0-9.-]*"></div>
      <div><label>Build file path</label><input name="file_path" value="<?= e($v('file_path')) ?>" placeholder="releases/smartprs-3.1.0.zip"></div>
      <div><label>Checksum (SHA-256)</label><input name="checksum" value="<?= e($v('checksum')) ?>" style="font-family:monospace;width:260px"></div>
      <div style="flex-basis:100%"><label>Release notes <span class="note">(blank = the notes typed in make-release.bat)</span></label><textarea name="notes" rows="4" style="width:100%"><?= e($v('notes')) ?></textarea></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=releases">Cancel</a></p>
  </form></div>
<?php endif; ?>

<?php if (($relLog = (string) ($_SESSION['release_log'] ?? '')) !== ''): unset($_SESSION['release_log']); ?>
<div class="card"><b>Last install on this server</b><pre style="white-space:pre-wrap;font-size:12px;max-height:300px;overflow:auto;margin:6px 0 0"><?= e($relLog) ?></pre></div>
<?php endif; ?>
<?php if ($rows === []): ?><div class="card"><p class="note" style="margin:0">No releases recorded yet.</p></div><?php endif; ?>
<?php foreach ($rows as $r): $rid = (string) $r['id']; $ver = (string) $r['version']; $published = (string) ($r['published_at'] ?? '') !== '';
    $granted = array_map(static fn (array $g): string => (string) $g['client_id'], $grants[$rid] ?? []); ?>
<div class="card">
  <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px;align-items:flex-start">
    <div><h3 style="margin:0"><?= e($ver) ?> <?php if ($ver === $running): ?><span class="badge badge-green">running here</span><?php endif; ?>
      <span class="badge badge-<?= $published ? 'green' : 'gray' ?>"><?= $published ? 'published ' . e(substr((string) $r['published_at'], 0, 10)) : 'draft' ?></span>
      <?php if ((string) ($r['applied_platform_at'] ?? '') !== ''): ?><span class="badge badge-gray">cloud applied <?= e(substr((string) $r['applied_platform_at'], 0, 10)) ?></span><?php endif; ?></h3>
      <div class="note" style="white-space:pre-wrap;margin-top:6px"><?= e((string) ($r['notes'] ?? '') ?: 'no notes') ?></div>
      <?php if ((string) ($r['file_path'] ?? '') !== ''): ?><div class="note" style="font-size:11px;font-family:monospace"><?= e((string) $r['file_path']) ?> <?= e((string) ($r['checksum'] ?? '') === '' ? '' : 'sha256 ' . substr((string) $r['checksum'], 0, 12) . '…') ?></div><?php endif; ?></div>
    <div style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="?r=releases&edit=<?= e($rid) ?>#form">Edit</a>
<?php if (!$published): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="op" value="publish"><input type="hidden" name="id" value="<?= e($rid) ?>"><button class="btn btn-sm btn-primary" type="submit">Publish</button></form>
<?php elseif ($ver !== $running && (string) ($r['file_path'] ?? '') !== ''): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Install <?= e($ver) ?> on the cloud server now? Every cloud company moves to it. Files being replaced are backed up first.')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="op" value="install-cloud"><input type="hidden" name="id" value="<?= e($rid) ?>"><button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-cloud-arrow-up"></i> Install on cloud server</button></form>
<?php endif; ?></div>
  </div>
  <div class="row" style="gap:16px;flex-wrap:wrap;margin-top:10px;align-items:flex-start">
    <div style="flex:1;min-width:260px"><b class="note">Granted to</b>
<div class="note" style="font-size:12px"><b>Cloud server</b> (every cloud company) · <?= (string) ($r['applied_platform_at'] ?? '') !== '' ? 'installed ' . e(substr((string) $r['applied_platform_at'], 0, 10)) : ($ver === $running ? 'running' : 'not installed') ?></div>
<?php if ($granted === []): ?><div class="note">no on-prem client yet</div><?php endif; ?>
<?php foreach ($grants[$rid] ?? [] as $g): ?><div class="note" style="font-size:12px"><?= e($clients[(string) $g['client_id']] ?? 'client #' . (string) $g['client_id']) ?> · <?= e(substr((string) $g['created_at'], 0, 10)) ?> by <?= e((string) ($g['granted_by'] ?? '')) ?></div><?php endforeach; ?>
<?php if ($published && array_diff_key($clients, array_flip($granted)) !== []): ?>
      <form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="op" value="grant"><input type="hidden" name="id" value="<?= e($rid) ?>">
        <b class="note">Send this update to</b>
        <label style="display:block;font-weight:600"><input type="checkbox" name="client_ids[]" value="*" onchange="this.form.querySelectorAll('[data-rc]').forEach(function(c){c.checked=this.checked}.bind(this))"> All on-prem clients</label>
<?php foreach ($clients as $cid => $name): if (in_array($cid, $granted, true)) { continue; } ?>
        <label style="display:block"><input type="checkbox" data-rc name="client_ids[]" value="<?= e($cid) ?>"> <?= e($name) ?></label>
<?php endforeach; ?>
        <button class="btn btn-sm btn-primary" type="submit" style="margin-top:4px"><i class="fas fa-paper-plane"></i> Send to ticked clients</button></form>
<?php endif; ?></div>
    <div style="flex:1;min-width:260px"><b class="note">Applied by clients</b>
<?php if (($applied[$ver] ?? []) === []): ?><div class="note">no client has reported this version</div><?php endif; ?>
<?php foreach ($applied[$ver] ?? [] as $u): ?><div class="note" style="font-size:12px"><?= e($clients[(string) ($u['client_id'] ?? '')] ?? 'client #' . (string) ($u['client_id'] ?? '?')) ?> · <?= e((string) $u['action']) ?> <?= e(substr((string) $u['created_at'], 0, 16)) ?> <?= e((string) ($u['detail'] ?? '')) ?></div><?php endforeach; ?></div>
  </div>
</div>
<?php endforeach; ?>
