<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Biometric Mapping &amp; Sync</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Biometric Mapping + Pending Punches + Sync/Test: every device id that punched and is
// nobody (held, not dropped — PunchIngest), mapped to an employee in one click (sets the employee's
// device user id and moves the held punches into attendance); and each Biometric Device Setup row
// with Sync now / Test. Written by actions/biometric.php.
$unmapped = $store->all('biometric_unmapped');
usort($unmapped, static fn (array $a, array $b): int => strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? '')));
$pendingBy = [];
foreach ($store->all('attendance_pending') as $p) {
    if (trim((string) ($p['resolved_at'] ?? '')) === '') {
        $pendingBy[strtolower((string) (($p['device_code'] ?? '') ?: ($p['device_user_id'] ?? '')))][] = $p;
    }
}
$emps = array_values(array_filter($store->all('employees'), static fn (array $e): bool => trim((string) ($e['deleted_at'] ?? '')) === '' && SmartPRS\Surface\Employment::code($e) !== ''));
usort($emps, static fn (array $a, array $b): int => strcmp(SmartPRS\Surface\Employment::code($a), SmartPRS\Surface\Employment::code($b)));
$configs = $store->all('biometric_configs');
$result = $_SESSION['bio_result'] ?? null;
unset($_SESSION['bio_result']);
$hid = static fn (string $op, string $id = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="biometric"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="id" value="' . e($id) . '">';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
?>
<div class="page-header"><div><h2>Biometric Mapping &amp; Sync</h2>
  <p>Devices: <?= count($configs) ?> · unmapped device ids: <?= count($unmapped) ?> · held punches: <?= array_sum(array_map('count', $pendingBy)) ?>. A punch from a device id that is nobody is held here, never guessed — map it and its punches move into attendance.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=biometric-setup"><i class="fas fa-gear"></i> Biometric Device Setup</a></div></div>

<?php if (is_array($result)): ?>
<div class="card" style="border-left:4px solid <?= $result['ok'] ? '#16a34a' : '#dc2626' ?>"><b><?= e((string) $result['title']) ?></b>
  <div class="note"><?= $result['ok'] ? (int) $result['fetched'] . ' punch(es) fetched · ' . (int) $result['accepted'] . ' new · ' . (int) $result['duplicates'] . ' already in · ' . (int) $result['pending'] . ' held (unmapped)' : e((string) $result['error']) ?></div>
  <?php if (($result['sample'] ?? '') !== ''): ?><details><summary class="note">Raw response (first 600 characters)</summary><pre style="white-space:pre-wrap;font-size:11px"><?= e((string) $result['sample']) ?></pre></details><?php endif; ?></div>
<?php endif; ?>

<div class="card"><h3 style="margin-top:0">Devices</h3>
  <div class="table-wrap"><table><thead><tr><th>Device</th><th>Provider</th><th>Serial</th><th>Mode</th><th>Last sync</th><th>Last status</th><th></th></tr></thead><tbody>
<?php if ($configs === []): ?><tr><td colspan="7" class="note">No devices yet — add one under Biometric Device Setup.</td></tr><?php endif; ?>
<?php foreach ($configs as $c): $pull = in_array(SmartPRS\Surface\BiometricSync::provider($c), ['etimeoffice', 'etimetracklite', 'generic'], true); ?>
    <tr><td><b><?= e((string) ($c['label'] ?? '')) ?></b><?= (bool) ($c['enabled'] ?? false) ? '' : ' <span class="badge badge-gray">disabled</span>' ?></td><td><?= e((string) ($c['provider'] ?? '')) ?></td>
      <td><code><?= e((string) ($c['serial_number'] ?? '') ?: '—') ?></code></td><td><?= e((string) ($c['device_mode'] ?? '') ?: '—') ?><?= ($c['emp_prefix'] ?? '') !== '' && $c['emp_prefix'] !== null ? ' · prefix ' . e((string) $c['emp_prefix']) : '' ?></td>
      <td class="note"><?= e(($c['last_sync_at'] ?? '') ? SmartPRS\Surface\Dates::dmy(substr((string) $c['last_sync_at'], 0, 10)) . ' ' . substr((string) $c['last_sync_at'], 11, 5) : 'never') ?></td>
      <td class="note" style="max-width:280px"><?= e((string) ($c['last_status'] ?? '')) ?></td>
      <td style="white-space:nowrap"><?php if ($pull): ?>
        <form method="post" style="display:inline-flex;gap:4px"><?= $hid('sync', (string) $c['id']) ?><select name="days" title="How many days back"><option>1</option><option>3</option><option>7</option><option>31</option></select><button class="btn btn-sm btn-primary" type="submit">Sync now</button></form>
        <form method="post" style="display:inline"><?= $hid('test', (string) $c['id']) ?><button class="btn btn-sm btn-outline" type="submit">Test</button></form>
      <?php else: ?><span class="note">pushes to <code><?= e($host) ?>/iclock/cdata</code></span><?php endif; ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
  <p class="note" style="margin:8px 0 0">ZKTeco / eSSL push: on the device, Menu → Comm → Cloud Server Setting → Server address <code><?= e(preg_replace('/:\d+$/', '', $host)) ?></code>, port <?= e((string) ($_SERVER['SERVER_PORT'] ?? '80')) ?>, HTTPS <?= ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' ? 'on' : 'off' ?>. The pull providers sync automatically by their “Sync frequency” when the server's cron runs <code>php tools/cron.php</code>.</p></div>

<div class="card"><h3 style="margin-top:0">Unmapped device ids</h3>
  <div class="table-wrap"><table><thead><tr><th>Device id</th><th>Source</th><th>Punches held</th><th>Last seen</th><th>Map to employee</th></tr></thead><tbody>
<?php if ($unmapped === []): ?><tr><td colspan="5" class="note">Every punch that arrived belongs to someone.</td></tr><?php endif; ?>
<?php foreach ($unmapped as $u): $code = (string) $u['device_code']; ?>
    <tr><td><code><?= e($code) ?></code></td><td><?= e((string) ($u['source'] ?? '')) ?></td><td><?= count($pendingBy[strtolower($code)] ?? []) ?> <span class="note">(<?= (int) ($u['punches'] ?? 0) ?> seen)</span></td>
      <td class="note"><?= e(($u['last_seen'] ?? '') ? SmartPRS\Surface\Dates::dmy(substr((string) $u['last_seen'], 0, 10)) . ' ' . substr((string) $u['last_seen'], 11, 5) : '') ?></td>
      <td><form method="post" style="display:inline-flex;gap:4px"><?= $hid('map') ?><input type="hidden" name="device_code" value="<?= e($code) ?>">
        <select name="employee" required><option value="">— choose —</option><?php foreach ($emps as $em): ?><option value="<?= e((string) $em['id']) ?>"><?= e(SmartPRS\Surface\Employment::label($em)) ?></option><?php endforeach; ?></select>
        <button class="btn btn-sm btn-primary" type="submit">Map</button></form></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>
