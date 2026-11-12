<?php declare(strict_types=1); if (!$may('tenant.settings.update') || !$isPlatformAdmin): ?>
<div class="page-header"><div><h2>Platform Staff</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Platform Staff is managed from the Super Admin console (/super).</div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Platform Staff (/admin/staff): the logins of the Super Admin console itself —
// `platform_users`, not any tenant's users. Add, reset a password, disable / enable.
// Written by surface/actions/platform-staff.php.
$staff = $platform->all('platform_users');
usort($staff, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
$me = (string) ($session->user()['email'] ?? '');
$hid = static fn (string $op, string $id = ''): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="platform-staff"><input type="hidden" name="op" value="' . e($op) . '"><input type="hidden" name="id" value="' . e($id) . '">';
?>
<div class="page-header"><div><h2>Platform Staff</h2>
  <p><?= count($staff) ?> login(s) can open this console at <code>/super</code>. Each can see every tenant and every on-prem licence — keep this list tiny.</p></div></div>
<div class="card"><h3 style="margin-top:0">Add a platform login</h3>
  <form method="post" class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"><?= $hid('add') ?>
    <div><label>Name</label><input name="name" required></div>
    <div><label>Email</label><input name="email" type="email" required></div>
    <div><label>Role</label><select name="role"><option value="staff">Staff</option><option value="super_admin">Super Admin</option></select></div>
    <div><label>Password (<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+)</label><input name="password" type="password" minlength="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" required autocomplete="new-password"></div>
    <button class="go" type="submit"><i class="fas fa-user-plus"></i> Add</button></form></div>
<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($staff as $u): $id = (string) $u['id']; $off = trim((string) ($u['disabled_at'] ?? '')) !== ''; ?>
  <tr><td><b><?= e((string) ($u['name'] ?? '')) ?></b></td><td><?= e((string) $u['email']) ?></td>
    <td><span class="badge badge-<?= (string) $u['role'] === 'super_admin' ? 'green' : 'gray' ?>"><?= (string) $u['role'] === 'super_admin' ? 'Super Admin' : 'Staff' ?></span></td>
    <td class="note"><?= e(($u['last_login_at'] ?? '') ? SmartPRS\Surface\Dates::dmy(substr((string) $u['last_login_at'], 0, 10)) . ' ' . substr((string) $u['last_login_at'], 11, 5) : 'never') ?></td>
    <td><span class="badge badge-<?= $off ? 'red' : 'green' ?>"><?= $off ? 'Disabled' : 'Active' ?></span></td>
    <td style="white-space:nowrap">
      <form method="post" style="display:inline-flex;gap:4px"><?= $hid('password', $id) ?><input name="password" type="password" minlength="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" placeholder="new password" required autocomplete="new-password" style="width:140px"><button class="btn btn-sm btn-outline" type="submit">Set</button></form>
<?php if (strcasecmp((string) $u['email'], $me) !== 0): ?>
      <form method="post" style="display:inline"><?= $hid($off ? 'enable' : 'disable', $id) ?><button class="btn btn-sm btn-outline" type="submit"><?= $off ? 'Enable' : 'Disable' ?></button></form>
<?php endif; ?></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>
