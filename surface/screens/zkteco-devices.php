<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>ZKTeco Devices</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's "ZKTeco Devices": the ZKTeco rows of the device register with a
// Sync button. Here: those rows, the push URL each must be pointed at, the last
// punch received per serial from attendance_logs, and the configuration steps
// from config/zkteco.json. The register itself is the Biometric Devices module.
$zk = json_decode((string) file_get_contents(__DIR__ . '/../../config/zkteco.json'), true) ?: [];
$brandColumn = (string) ($zk['brand_column'] ?? 'brand');
$serialColumn = (string) ($zk['serial_column'] ?? 'unique_id');
$logsSerial = (string) ($zk['logs_serial_column'] ?? 'device_sn');
$devices = array_values(array_filter(liveRows($store, 'devices'), static fn (array $d): bool => strcasecmp(trim((string) ($d[$brandColumn] ?? '')), (string) ($zk['brand'] ?? '')) === 0));
usort($devices, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$base = $scheme . '://' . $host . rtrim(dirname(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?')), '/');
$pushUrl = $base . (string) ($zk['ingest_path'] ?? '');
$lastPunch = [];
$punchCount = [];

foreach ($devices as $d) {
    $serial = trim((string) ($d[$serialColumn] ?? ''));

    if ($serial === '') {
        continue;
    }

    try {
        $latest = $store->page('attendance_logs', 'punch_at', 1, 0, 'desc', [$logsSerial], $serial);
        $lastPunch[$serial] = $latest === [] ? '' : (string) ($latest[0]['punch_at'] ?? '');
        $punchCount[$serial] = $store->count('attendance_logs', [$logsSerial], $serial);
    } catch (\Throwable $e) {
        $lastPunch[$serial] = 'unreadable: ' . $e->getMessage(); // the column is missing on this installation; said, not hidden
    }
}
?>
<?= deviceSetupTabs('zkteco') ?>
<div class="page-header">
  <div><h2>ZKTeco Devices</h2>
    <p><?= e((string) ($zk['brand'] ?? '')) ?> rows of the <a href="?r=m&amp;k=<?= e((string) ($zk['devices_module'] ?? 'devices')) ?>">Biometric Devices</a> register, the address each pushes to, and the last punch heard from each serial. Unmatched punches wait on <a href="?r=m&amp;k=<?= e((string) ($zk['pending_punches_module'] ?? 'pending-punches')) ?>">Pending Punches</a>.</p></div>
  <div><a class="btn btn-outline btn-sm" href="?r=m&amp;k=<?= e((string) ($zk['devices_module'] ?? 'devices')) ?>&amp;add=1">Register a device</a></div>
</div>

<div class="card">
  <h2>Push URL</h2>
  <div class="row" style="align-items:center;gap:10px;flex-wrap:wrap"><code style="font-size:13px;padding:6px 10px;background:var(--bg2,#f8fafc);border:1px solid var(--border,#e2e8f0);border-radius:6px"><?= e((string) ($zk['ingest_method'] ?? 'POST')) ?> <?= e($pushUrl) ?></code></div>
  <p class="note" style="margin-top:8px">Headers: <?php foreach ((array) ($zk['ingest_headers'] ?? []) as $h): ?><code><?= e((string) $h) ?></code> <?php endforeach; ?><br>
    Body: <code><?= e((string) json_encode($zk['ingest_body_example'] ?? [], JSON_UNESCAPED_SLASHES)) ?></code><br>
    The credential comes from <a href="?r=<?= e((string) ($zk['api_keys_route'] ?? 'api-keys')) ?>">API Keys</a>; a device with no network path here exports its log to <a href="?r=<?= e((string) ($zk['offline_route'] ?? 'punch-import')) ?>">Punch Import</a>.</p>
  <p class="note"><?= e((string) ($zk['note'] ?? '')) ?></p>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Device</th><th>Serial</th><th>Model / verification</th><th>Location</th><th>Connection</th><th>Punches</th><th>Last punch received</th><th>Status</th></tr></thead>
    <tbody>
<?php if ($devices === []): ?><tr><td colspan="8" class="note">No live device with brand <?= e((string) ($zk['brand'] ?? '')) ?>. Register one with that brand on Biometric Devices and it appears here.</td></tr><?php endif; ?>
<?php foreach ($devices as $d): $serial = trim((string) ($d[$serialColumn] ?? '')); $last = $lastPunch[$serial] ?? ''; $st = strtolower((string) ($d['status'] ?? '')); ?>
      <tr>
        <td><b><?= e((string) ($d['name'] ?? '')) ?></b></td>
        <td><code><?= e($serial !== '' ? $serial : '— no serial: punches cannot be matched to it') ?></code></td>
        <td><?= e(trim(implode(' · ', array_filter([(string) ($d['model'] ?? ''), (string) ($d['device_type'] ?? '')])))) ?: '—' ?></td>
        <td><?= e((string) ($d['location'] ?? '')) ?: '—' ?></td>
        <td><?= e((string) ($d['connect_mode'] ?? '')) ?: '—' ?><?php if (trim((string) ($d['ip_address'] ?? '')) !== ''): ?><div class="note" style="font-size:11px"><?= e((string) $d['ip_address']) ?><?= trim((string) ($d['port'] ?? '')) !== '' ? ':' . e((string) $d['port']) : '' ?></div><?php endif; ?></td>
        <td><?= $serial === '' ? '—' : e((string) ($punchCount[$serial] ?? 0)) ?></td>
        <td><?php if ($serial === ''): ?><span class="note">—</span><?php elseif ($last === ''): ?><span class="badge badge-amber">nothing yet</span><?php elseif (str_starts_with($last, 'unreadable')): ?><span class="badge badge-red"><?= e($last) ?></span><?php else: ?><?= e(substr($last, 0, 16)) ?><?php endif; ?></td>
        <td><span class="badge badge-<?= $st === 'active' ? 'green' : ($st === 'error' ? 'red' : 'gray') ?>"><?= e((string) ($d['status'] ?? '')) ?: '—' ?></span></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h2>How to configure the device</h2>
  <p class="note">Protocol: <b><?= e((string) ($zk['protocol']['label'] ?? '')) ?></b> — device LAN port <?= e((string) ($zk['protocol']['lan_port'] ?? '')) ?>, push out on <?= e((string) ($zk['protocol']['adms_port'] ?? '')) ?> (<?= e((string) ($zk['protocol']['adms_https_port'] ?? '')) ?> with HTTPS). <?= e((string) ($zk['protocol']['note'] ?? '')) ?></p>
  <ol style="padding-left:20px">
<?php foreach ((array) ($zk['steps'] ?? []) as $step): ?>    <li style="margin:0 0 6px;line-height:1.5"><?= e((string) $step) ?></li>
<?php endforeach; ?>
  </ol>
  <h2 style="margin-top:14px">If nothing arrives</h2>
  <ul style="padding-left:20px">
<?php foreach ((array) ($zk['troubleshooting'] ?? []) as $tip): ?>    <li style="margin:0 0 6px;line-height:1.5"><?= e((string) $tip) ?></li>
<?php endforeach; ?>
  </ul>
  <p class="note">The words above are <code>config/zkteco.json</code>.</p>
</div>
