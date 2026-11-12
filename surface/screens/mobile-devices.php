<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Mobile Devices</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Mobile Devices: the phones registered for the attendance app,
// pending / approved / rejected / revoked, approve / reject / revoke per row,
// the short code the phone shows, the push-enabled indicator. Which buttons a
// row gets is config/mobile-devices.json's transitions; the move is written by
// surface/actions/mobile-device.php and never here. Rows come from
// surface/partials/people-mobile-devices-data.php — the Excel/CSV too.
$params = $_GET;
include __DIR__ . '/../partials/people-mobile-devices-data.php';
$devices = $mdDevices;
$declared = $mdDeclared;
$statuses = $mdStatuses;
$transitions = (array) $declared['transitions'];
$canDecide = $may('tenant.settings.update');
$people = $mdPeople;
$rows = $mdRows;
$counts = [];

foreach ($mdAll as $r) {
    $counts[(string) $r['status']] = ($counts[(string) $r['status']] ?? 0) + 1;
}

$peopleExport = ['screen' => 'mobile-devices', 'keep' => $mdStatus !== '' ? ['s' => $mdStatus] : []];
?>
<div class="page-header">
  <div><h2>Mobile Devices</h2>
    <p>Approve the phones that may use the attendance app. Only an approved device can sign in and punch; revoke a lost phone to lock it out on its next check. Match the code shown on the phone before approving.</p></div>
  <div class="actions"><?php include __DIR__ . '/../partials/people-export-buttons.php'; ?></div>
</div>
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
  <a href="?r=mobile-devices" class="badge" style="padding:6px 12px;font-size:12px;text-decoration:none;background:<?= $mdStatus === '' ? '#0f172a' : '#e2e8f0' ?>;color:<?= $mdStatus === '' ? '#fff' : '#0f172a' ?>">ALL <?= count($mdAll) ?></a>
<?php foreach ($statuses as $s => $meta): ?>
  <a href="?r=mobile-devices&amp;s=<?= e((string) $s) ?>" class="badge <?= $mdStatus === $s ? '' : 'badge-' . e((string) $meta['badge']) ?>" style="padding:6px 12px;font-size:12px;text-decoration:none<?= $mdStatus === $s ? ';background:#0f172a;color:#fff' : '' ?>"><?= (int) ($counts[$s] ?? 0) ?> <?= e((string) $meta['label']) ?></a>
<?php endforeach; ?>
</div>

<?php $maBackTo = 'mobile-devices'; include __DIR__ . '/../partials/mobile-activation-form.php'; ?>
<?php if ($canDecide): ?>
<details class="card"><summary style="cursor:pointer;font-weight:700">Register a device by hand</summary>
  <p class="note">A phone normally registers itself from the app and lands here as pending. Register by hand when the app cannot reach the server.</p>
  <form method="post" class="row" style="align-items:flex-end;gap:10px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="mobile-device"><input type="hidden" name="op" value="register">
    <div><label>Device id</label><input name="device_id" required placeholder="IMEI / install id"></div>
    <div><label>Employee</label><select name="employee_id"><option value="">—</option><?php foreach ($people as $id => $label): ?><option value="<?= e($id) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label>Device name</label><input name="device_name" placeholder="Ravi's phone"></div>
    <div><label>Model</label><input name="model" placeholder="Redmi Note 12"></div>
    <div><label>Platform</label><select name="platform"><?php foreach ((array) $declared['platforms'] as $p): ?><option><?= e((string) $p) ?></option><?php endforeach; ?></select></div>
    <div><label>OS version</label><input name="os_version" style="width:90px"></div>
    <button class="go" type="submit">Register</button>
  </form>
</details>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Device</th><th>Employee</th><th>Code</th><th>Push</th><th>Registered</th><th>Status</th><th></th></tr></thead>
    <tbody>
<?php if ($rows === []): ?><tr><td colspan="7" class="note">No phone <?= $mdStatus === '' ? 'has registered yet. Install the app, sign in, and it appears here as pending.' : 'is ' . e((string) $statuses[$mdStatus]['label']) . '.' ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): $st = (string) $r['status']; $meta = (array) ($statuses[$st] ?? ['label' => $st, 'badge' => 'gray']); ?>
      <tr>
        <td><b><?= e((string) ($r['device_name'] ?? '') ?: 'Mobile device') ?></b>
          <div class="note" style="font-size:11px"><?= e(trim(implode(' · ', array_filter([(string) ($r['model'] ?? ''), (string) ($r['platform'] ?? ''), (string) ($r['os_version'] ?? ''), (string) ($r['host'] ?? '')])))) ?></div>
          <div class="note" style="font-family:monospace;font-size:11px"><?= e((string) $r['device_id']) ?></div></td>
        <td><?= e($people[(string) ($r['employee_id'] ?? '')] ?? ((string) ($r['employee_id'] ?? '') === '' ? '—' : '#' . (string) $r['employee_id'])) ?></td>
        <td><code><?= e((string) ($r['code'] ?? '') ?: '—') ?></code></td>
        <td title="Push notifications reach this phone when a push token is registered"><?= trim((string) ($r['push_token'] ?? '')) !== '' ? '<span class="badge badge-green"><i class="fas fa-bell"></i> Yes</span>' : '<span class="badge badge-red"><i class="fas fa-bell-slash"></i> No</span>' ?></td>
        <td class="note"><?= e(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?><?= trim((string) ($r['last_seen_at'] ?? '')) !== '' ? '<br>seen ' . e(substr((string) $r['last_seen_at'], 0, 16)) : '' ?></td>
        <td><span class="badge badge-<?= e((string) $meta['badge']) ?>"><?= e((string) $meta['label']) ?></span>
<?php if (trim((string) ($r['approved_by'] ?? '')) !== ''): ?><div class="note" style="font-size:11px">by <?= e((string) $r['approved_by']) ?> <?= e(substr((string) ($r['approved_at'] ?? ''), 0, 16)) ?></div><?php endif; ?>
<?php if (trim((string) ($r['reject_reason'] ?? '')) !== ''): ?><div class="note" style="font-size:11px"><?= e((string) $r['reject_reason']) ?></div><?php endif; ?></td>
        <td style="white-space:nowrap">
<?php if ($canDecide): foreach ($devices->movesFrom($st) as $op): $t = (array) $transitions[$op]; ?>
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
            <input type="hidden" name="action" value="mobile-device"><input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
<?php if (!empty($t['reason_required'])): ?><input name="reason" placeholder="reason (shown on the phone)" required style="width:180px"><?php endif; ?>
            <button class="btn btn-sm" style="background:transparent;border:0;color:<?= ['green' => '#15803d', 'red' => '#b91c1c'][(string) ($statuses[(string) $t['to']]['badge'] ?? '')] ?? '#475569' ?>" type="submit" title="<?= e((string) $t['label']) ?>"><i class="fas <?= ['approve' => 'fa-check', 'reject' => 'fa-xmark', 'revoke' => 'fa-ban'][$op] ?? 'fa-arrow-right' ?>"></i> <?= e((string) $t['label']) ?></button>
          </form>
<?php endforeach; endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
