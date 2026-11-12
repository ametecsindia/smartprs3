<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>API Keys &amp; Integrations</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Time & Attendance → API Keys: keys for the Smart Biometric Bridge / Caller app
// (/api/v1, X-Api-Key) and SmartEPT's signed webhook endpoints. The same keys and URLs a client
// already configured on SmartPRS 2 work here once re-issued. Written by actions/integration.php.
$base = ((($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
    . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/app.php'))), '/');
$keys = array_values(array_filter($store->all('api_keys'), static fn (array $k): bool => str_starts_with((string) ($k['prefix'] ?? ''), 'sk_prs_')));
$hooks = $store->all('smartept_webhook_endpoints');
$companies = $store->all('companies');
$minted = $_SESSION['int_minted'] ?? null;
unset($_SESSION['int_minted']);
$hid = static fn (string $op, string $id = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="integration"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="id" value="' . e($id) . '">';
$when = static fn (mixed $t): string => $t ? SmartPRS\Surface\Dates::dmy(substr((string) $t, 0, 10)) . ' ' . substr((string) $t, 11, 5) : 'never';
?>
<div class="page-header"><div><h2>API Keys &amp; Integrations</h2>
  <p>Biometric bridge (SBB), field-force (Caller) app and SmartEPT send attendance here. Same URLs and headers as SmartPRS 2 — only the host changes to this server.</p></div></div>

<?php if (is_array($minted)): ?>
<div class="card" style="border-left:4px solid #16a34a"><b><?= e((string) $minted['what']) ?> — copy it now, it is shown only once:</b>
  <?php foreach ((array) $minted['lines'] as $label => $val): ?><div style="margin-top:6px"><?= e((string) $label) ?>: <code style="user-select:all"><?= e((string) $val) ?></code></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card"><h3 style="margin-top:0">Connection details</h3>
  <div class="note">Base URL <code><?= e($base) ?>/api/v1</code> · Ping <code>GET <?= e($base) ?>/api/v1/ping</code> · Punches <code>POST <?= e($base) ?>/api/v1/attendance/punches</code></div>
  <div class="note">Field force <code>POST <?= e($base) ?>/api/v1/fieldforce/attendance</code>, <code>…/fieldforce/geofence/check</code> · Header <code>X-Api-Key: &lt;key&gt;</code> (or <code>Authorization: Bearer &lt;key&gt;</code>)</div>
  <div class="note">Biometric push (ZKTeco/eSSL ADMS): device Cloud Server = <code><?= e((string) ($_SERVER['HTTP_HOST'] ?? '')) ?></code>, path <code>/iclock/cdata</code> — see Biometric Mapping &amp; Sync · Times are this server's local time (<?= e(date_default_timezone_get()) ?>), format <code>YYYY-MM-DD HH:MM:SS</code>.</div></div>

<div class="card"><h3 style="margin-top:0">API keys</h3>
  <form method="post" class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"><?= $hid('mint-key') ?>
    <div><label>Name</label><input name="name" required placeholder="Main gate SBB"></div>
    <div><label>Company</label><select name="company"><option value="">All companies</option><?php foreach ($companies as $co): ?><option value="<?= e((string) $co['id']) ?>"><?= e((string) $co['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Scopes</label><label style="font-weight:400"><input type="checkbox" name="scopes[]" value="ingest" checked style="width:auto"> ingest</label><label style="font-weight:400"><input type="checkbox" name="scopes[]" value="read" checked style="width:auto"> read</label></div>
    <div><label>Expires (optional)</label><input type="date" name="expires_at"></div>
    <button class="go" type="submit"><i class="fas fa-key"></i> Create key</button></form>
  <div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Name</th><th>Key</th><th>Scopes</th><th>Last used</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($keys === []): ?><tr><td colspan="7" class="note">No keys yet.</td></tr><?php endif; ?>
<?php foreach ($keys as $k): $on = (bool) $k['active']; ?>
    <tr><td><b><?= e((string) $k['name']) ?></b></td><td><code><?= e((string) $k['prefix']) ?>_••••</code></td><td><?= e(implode(', ', (array) json_decode((string) ($k['scopes'] ?? '[]'), true))) ?></td>
      <td class="note"><?= e($when($k['last_used_at'] ?? null)) ?></td><td class="note"><?= e(($k['expires_at'] ?? '') ? SmartPRS\Surface\Dates::dmy(substr((string) $k['expires_at'], 0, 10)) : '—') ?></td>
      <td><span class="badge badge-<?= $on ? 'green' : 'gray' ?>"><?= $on ? 'Active' : 'Revoked' ?></span></td>
      <td><?php if ($on): ?><form method="post" style="display:inline"><?= $hid('revoke-key', (string) $k['id']) ?><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Revoke this key? Devices using it stop at once.')">Revoke</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>

<div class="card"><h3 style="margin-top:0">SmartEPT webhooks</h3>
  <p class="note" style="margin-top:0">In SmartEPT → Integrations, paste the URL and secret below. Each request is signed (<code>X-SmartEPT-Signature</code> = HMAC-SHA256 of the body) and names its event (<code>attendance.punch</code> or <code>attendance.daily</code>).</p>
  <form method="post" class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"><?= $hid('mint-hook') ?>
    <div><label>Name</label><input name="name" required placeholder="SmartEPT head office"></div>
    <div><label>Company</label><select name="company"><option value="">All companies</option><?php foreach ($companies as $co): ?><option value="<?= e((string) $co['id']) ?>"><?= e((string) $co['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Events</label><?php foreach (SmartPRS\Surface\SmartEpt::EVENTS as $ev): ?><label style="font-weight:400"><input type="checkbox" name="events[]" value="<?= e($ev) ?>" checked style="width:auto"> <?= e($ev) ?></label><?php endforeach; ?></div>
    <button class="go" type="submit"><i class="fas fa-link"></i> Create endpoint</button></form>
  <div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Name</th><th>URL</th><th>Last received</th><th>Counts</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($hooks === []): ?><tr><td colspan="6" class="note">No endpoints yet.</td></tr><?php endif; ?>
<?php foreach ($hooks as $w): $on = (bool) $w['active']; ?>
    <tr><td><b><?= e((string) $w['name']) ?></b></td><td><code style="font-size:11px"><?= e($base . '/api/v1/webhooks/smartept/' . (string) $w['slug']) ?></code></td>
      <td class="note"><?= e($when($w['last_received_at'] ?? null)) ?> <?= e((string) ($w['last_event'] ?? '')) ?><div style="font-size:11px"><?= e((string) ($w['last_status'] ?? '')) ?></div></td>
      <td class="note"><?= (int) $w['received_count'] ?> received · <?= (int) $w['accepted_count'] ?> accepted · <?= (int) $w['rejected_count'] ?> rejected</td>
      <td><span class="badge badge-<?= $on ? 'green' : 'gray' ?>"><?= $on ? 'Active' : 'Revoked' ?></span></td>
      <td style="white-space:nowrap"><?php if ($on): ?><form method="post" style="display:inline"><?= $hid('rotate-hook', (string) $w['id']) ?><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('New secret? SmartEPT must be updated with it.')">Rotate secret</button></form>
        <form method="post" style="display:inline"><?= $hid('revoke-hook', (string) $w['id']) ?><button class="btn btn-sm btn-outline" type="submit">Revoke</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>
