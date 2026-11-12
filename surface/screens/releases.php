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
  <p><?= count($rows) ?> release(s), newest first. This server runs <code><?= e($running ?: '?') ?></code> (config/version.json). Publish a release, then grant it to the on-prem clients whose AMC covers it; their servers report back what they applied.</p></div>
  <div><a class="btn btn-primary" href="?r=releases&edit=new#form"><i class="fas fa-plus"></i> New release</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New release' : 'Edit ' . e($v('version')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="release"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div><label>Version</label><input name="version" required value="<?= e($v('version')) ?>" placeholder="3.1.0" pattern="[0-9]+(\.[0-9]+)*[A-Za-z0-9.-]*"></div>
      <div><label>Build file path</label><input name="file_path" value="<?= e($v('file_path')) ?>" placeholder="releases/smartprs-3.1.0.zip"></div>
      <div><label>Checksum (SHA-256)</label><input name="checksum" value="<?= e($v('checksum')) ?>" style="font-family:monospace;width:260px"></div>
      <div style="flex-basis:100%"><label>Release notes</label><textarea name="notes" rows="4" style="width:100%"><?= e($v('notes')) ?></textarea></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=releases">Cancel</a></p>
  </form></div>
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
<?php elseif ((string) ($r['applied_platform_at'] ?? '') === ''): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="op" value="applied"><input type="hidden" name="id" value="<?= e($rid) ?>"><button class="btn btn-sm btn-outline" type="submit" title="record that the cloud now runs this build">Mark applied on cloud</button></form>
<?php endif; ?></div>
  </div>
  <div class="row" style="gap:16px;flex-wrap:wrap;margin-top:10px;align-items:flex-start">
    <div style="flex:1;min-width:260px"><b class="note">Granted to</b>
<?php if ($granted === []): ?><div class="note">nobody yet</div><?php endif; ?>
<?php foreach ($grants[$rid] ?? [] as $g): ?><div class="note" style="font-size:12px"><?= e($clients[(string) $g['client_id']] ?? 'client #' . (string) $g['client_id']) ?> · <?= e(substr((string) $g['created_at'], 0, 10)) ?> by <?= e((string) ($g['granted_by'] ?? '')) ?></div><?php endforeach; ?>
<?php if ($published && array_diff_key($clients, array_flip($granted)) !== []): ?>
      <form method="post" class="row" style="gap:6px;align-items:flex-end;margin-top:6px"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="op" value="grant"><input type="hidden" name="id" value="<?= e($rid) ?>">
        <select name="client_id"><option value="*">every on-prem client</option><?php foreach ($clients as $cid => $name): if (in_array($cid, $granted, true)) { continue; } ?><option value="<?= e($cid) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-outline" type="submit">Grant</button></form>
<?php endif; ?></div>
    <div style="flex:1;min-width:260px"><b class="note">Applied by clients</b>
<?php if (($applied[$ver] ?? []) === []): ?><div class="note">no client has reported this version</div><?php endif; ?>
<?php foreach ($applied[$ver] ?? [] as $u): ?><div class="note" style="font-size:12px"><?= e($clients[(string) ($u['client_id'] ?? '')] ?? 'client #' . (string) ($u['client_id'] ?? '?')) ?> · <?= e((string) $u['action']) ?> <?= e(substr((string) $u['created_at'], 0, 16)) ?> <?= e((string) ($u['detail'] ?? '')) ?></div><?php endforeach; ?></div>
  </div>
</div>
<?php endforeach; ?>
