<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>Demo Requests</h2></div></div>
<div class="card"><div class="problem" style="margin:0">The demo register is managed from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// EXPLORE DEMO REQUESTS (owner, 12 Nov 2026; SmartPRS 2 rev 185's register): every request from /demo — each visitor's
// own demo company, its days, extend, resend, delete now — and the Google Play reviewer login.
// Written by surface/actions/demo-requests.php.
try {
    $drGate = new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), dirname(__DIR__, 2) . '/.env');
    $drRows = $drGate->requests();
    $drDays = $drGate->days();
    $drReviewer = $drGate->reviewer();
} catch (\Throwable) {
    $drRows = null;
}
$drChip = ['active' => 'green', 'expired' => 'gray', 'revoked' => 'red', 'converted' => 'blue'];
$drAt = static fn (?string $t): string => ($t ?? '') === '' ? '—' : SmartPRS\Surface\Dates::dmy(substr((string) $t, 0, 10)) . ' ' . substr((string) $t, 11, 5);
$hid = static fn (string $op, string $id = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="demo-requests"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="id" value="' . e($id) . '">';
$drBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/super.php'))), '/');
?>
<div class="page-header"><div><h2>Demo Requests</h2>
  <p><?= $drRows === null ? '' : count($drRows) . ' request(s) · ' ?>Every Explore Demo request from <a href="demo.php" target="_blank" rel="noopener">/demo</a>. Each visitor gets their own demo company with sample data; when its days end (or it is deleted here) the visitor is signed out and the company is deleted.</p></div>
  <a class="btn btn-outline" href="demo.php" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square"></i> Open /demo</a></div>
<?php if ($drRows === null): ?><div class="card"><div class="problem" style="margin:0">The demo register table is not there yet — run the database migration.</div></div><?php return; endif; ?>
<div class="card"><form method="post" class="row" style="gap:12px;align-items:center;flex-wrap:wrap"><?= $hid('days') ?>
  <i class="fas fa-hourglass-half" style="color:#f97316"></i><span>Demo company stays for</span>
  <input name="days" type="number" min="1" max="<?= (int) $drGate->maxDays() ?>" value="<?= (int) $drDays ?>" style="width:80px;text-align:center"> <span class="note">days</span>
  <button class="go" type="submit"><i class="fas fa-check"></i> Save</button></form></div>
<div class="card"><h3 style="margin:0 0 8px">Google Play reviewer login</h3>
  <?php if ($drReviewer !== null && $drReviewer['user'] !== null): ?>
  <table style="width:auto"><tbody>
    <tr><td class="note">Company address</td><td><?= e($drBase . '/c/' . (string) $drReviewer['tenant']['subdomain']) ?></td></tr>
    <tr><td class="note">Login</td><td><?= e((string) $drReviewer['user']['email']) ?></td></tr>
    <tr><td class="note">Data</td><td>Emptied every night and rebuilt on the next sign-in</td></tr></tbody></table>
  <?php else: ?><p class="note" style="margin:0 0 8px">Not set up yet. Give the login Google Play's reviewer will use — no activation code, no phone approval.</p><?php endif; ?>
  <form method="post" class="row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px"><?= $hid('reviewer') ?>
    <input name="email" type="email" required placeholder="Login email" value="<?= e((string) ($drReviewer['user']['email'] ?? '')) ?>" style="width:240px">
    <input name="password" type="password" required minlength="<?= (int) SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" placeholder="Password" autocomplete="new-password" style="width:200px">
    <button class="go" type="submit"><i class="fas fa-check"></i> Save</button></form></div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Requested</th><th>Name</th><th>Company</th><th>Phone</th><th>Email</th><th>Passkey</th><th>Status</th><th>Ends</th><th>First login</th><th>Demo company</th><th></th></tr></thead><tbody>
<?php foreach ($drRows as $r): $id = (string) $r['id']; $drT = $drGate->tenantOf($r); $drWiped = trim((string) ($r['wiped_at'] ?? '')) !== ''; ?>
  <tr><td class="note"><?= e($drAt((string) $r['created_at'])) ?></td>
    <td><?= e((string) $r['name']) ?></td><td><?= e((string) ($r['company'] ?? '')) ?></td>
    <td><?= e((string) $r['phone']) ?></td><td><?= e((string) $r['email']) ?></td>
    <td style="letter-spacing:2px"><?= e((string) $r['pin']) ?></td>
    <td><span class="badge badge-<?= $drChip[(string) $r['status']] ?? 'gray' ?>"><?= e(ucfirst((string) $r['status'])) ?></span></td>
    <td class="note"><?= e($drAt((string) $r['expires_at'])) ?></td><td class="note"><?= e($drAt($r['last_login_at'] ?? null)) ?></td>
    <td class="note"><?= (string) $r['status'] === 'converted' ? 'Client company' : ($drT !== null ? 'Made' : ($drWiped ? 'Deleted ' . e($drAt((string) $r['wiped_at'])) : 'Not opened yet')) ?></td>
    <td style="white-space:nowrap">
      <?php if ((string) $r['status'] === 'converted'): ?>—<?php elseif (!$drWiped): ?>
      <form method="post" style="display:inline"><?= $hid('extend', $id) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-calendar-plus"></i> Extend <?= (int) $drDays ?> days</button></form>
      <?php endif; if ((string) $r['status'] === 'active'): ?>
      <form method="post" style="display:inline"><?= $hid('resend', $id) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-paper-plane"></i> Resend</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('End this demo now and delete its demo company? This cannot be undone.')"><?= $hid('revoke', $id) ?><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-trash"></i> Delete now</button></form>
      <?php endif; if ($drT !== null && (string) $r['status'] !== 'converted'): ?>
      <details style="display:inline-block;vertical-align:top"><summary class="btn btn-sm btn-outline" style="list-style:none;cursor:pointer"><i class="fas fa-building-circle-check"></i> Convert to client</summary>
        <form method="post" style="display:grid;gap:6px;margin-top:8px;min-width:280px;white-space:normal" onsubmit="return confirm('Convert this demo into a client company?')"><?= $hid('convert', $id) ?>
          <input name="code" required placeholder="Company code (e.g. ACME)" maxlength="20">
          <input name="slug" required placeholder="Sign-in address /c/…" value="<?= e((string) preg_replace('/[^a-z0-9]/', '', strtolower((string) ($r['company'] ?? '')))) ?>">
          <input name="email" type="email" required placeholder="Administrator email" value="<?= e((string) $r['email']) ?>">
          <input name="password" type="password" required minlength="<?= (int) SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" placeholder="Administrator password" autocomplete="new-password">
          <label style="display:flex;gap:6px;align-items:center;font-weight:400"><input type="checkbox" name="remove_sample" value="1" checked style="width:auto"> Remove the sample data, keep what the visitor added</label>
          <button class="go" type="submit"><i class="fas fa-check"></i> Convert</button></form></details>
      <?php endif; ?></td></tr>
<?php endforeach; if ($drRows === []): ?><tr><td colspan="11" class="note" style="text-align:center;padding:30px">No demo requests yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
