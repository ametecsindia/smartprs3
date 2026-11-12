<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Tenants</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Tenants: the customer book. Plan and employee count come from
// SaasPlatform::tenants() — the count through a store scoped to THAT tenant.
// Suspend / reactivate are surface/actions/tenant.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$statuses = (array) ($saas->declared()['tenant_statuses'] ?? []);
$states = array_keys((array) ($saas->gst()['state_codes'] ?? []));
$rows = $saas->tenants();
$editing = isset($_GET['edit']) ? $platform->find('tenants', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
// WHO SIGNS IN FIRST, AND WHERE (owner, 5 Nov 2026): each tenant's administrator login(s) and its full sign-in link.
$adminsOf = [];
foreach ($platform->all('users') as $u) {
    if ((string) ($u['role'] ?? $u['status'] ?? '') === 'administrator' && trim((string) ($u['disabled_at'] ?? '')) === '') {
        $adminsOf[(string) ($u['tenant_id'] ?? '')][] = (string) $u['email'];
    }
}
$linkBase = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$signInLink = static fn (array $t): string => trim((string) ($t['custom_domain'] ?? '')) !== ''
    ? 'https://' . trim((string) $t['custom_domain']) . (trim((string) ($t['subdomain'] ?? '')) !== '' ? '/c/' . $t['subdomain'] : '')
    : (trim((string) ($t['subdomain'] ?? '')) !== '' ? $linkBase . '/c/' . $t['subdomain'] : '');
$editAdmins = $editing === null ? [] : ($adminsOf[(string) $editing['id']] ?? []);
?>
<div class="page-header"><div><h2>Tenants</h2>
  <p><?= count($rows) ?> tenant(s). Each row is a customer whose whole HR runs here — plan, seats, GST state, status. The GST state decides CGST+SGST or IGST on their invoices.</p></div>
  <div><a class="btn btn-primary" href="?r=tenants&edit=new#form"><i class="fas fa-plus"></i> Add tenant</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New tenant' : 'Edit ' . e($v('name')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="tenant"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div><label>Code</label><input name="code" required value="<?= e($v('code')) ?>" placeholder="T-ACME"></div>
      <div style="flex:1;min-width:220px"><label>Name</label><input name="name" required value="<?= e($v('name')) ?>" style="width:100%"></div>
      <div><label>State (GST)</label><input name="state" list="tenant-states" value="<?= e($v('state')) ?>" placeholder="Telangana"><datalist id="tenant-states"><?php foreach ($states as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
      <div><label>Workspace slug</label><input name="subdomain" value="<?= e($v('subdomain')) ?>" placeholder="acme" pattern="[a-z0-9]*" title="lowercase letters and digits — sign-in at /c/slug"></div>
      <div><label>Custom domain</label><input name="custom_domain" value="<?= e($v('custom_domain')) ?>" placeholder="hr.client.in"></div>
      <div style="flex-basis:100%;border-top:1px solid var(--border);padding-top:8px"><b>Administrator login</b></div>
<?php if ($editAdmins !== []): ?>
      <div style="flex-basis:100%"><label>Admin email (sign-in ID)</label><input value="<?= e(implode(', ', $editAdmins)) ?>" readonly style="width:100%"></div>
      <div style="flex-basis:100%" class="note">For a new one-time password, use <b>Admin password</b> on this tenant's row below.</div>
<?php else: ?>
<?php if ($editing === null): ?>
      <div><label>Company name</label><input name="company_name" placeholder="same as tenant"></div>
<?php endif; ?>
      <div style="flex:1;min-width:240px"><label>Admin email (sign-in ID)</label><input name="admin_email" type="email" placeholder="admin@client.in" style="width:100%"></div>
      <div><label>Admin password</label><input name="admin_password" type="password" minlength="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" autocomplete="new-password" placeholder="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+ characters"></div>
      <div style="flex-basis:100%" class="note">On Save, the administrator login is created with this password. Nothing is emailed; give the client the sign-in link, email and password yourself.</div>
<?php endif; ?>
      <div><label>Onboarded on</label><input type="date" name="onboarded_on" value="<?= e($v('onboarded_on')) ?>"></div>
      <div><label>Status</label><select name="status_code"><?php foreach ($statuses as $s): ?><option<?= $s === ($v('status_code') ?: 'active') ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=tenants">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Code</th><th>Tenant</th><th>Admin login</th><th>Plan</th><th>Seats</th><th>Employees</th><th>State</th><th>Renewal</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="10" class="note">No tenants yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $t): $st = (string) ($t['status_code'] ?? ''); $sub = $t['subscription']; ?>
  <tr>
    <td><code><?= e((string) $t['code']) ?></code></td>
    <td><b><?= e((string) $t['name']) ?></b><?php if (trim((string) ($t['custom_domain'] ?? '')) !== ''): ?><div class="note" style="font-size:11px"><?= e((string) $t['custom_domain']) ?></div><?php endif; ?><?php if (($tLink = $signInLink($t)) !== ''): ?><div style="font-size:12px"><a href="<?= e($tLink) ?>" target="_blank" rel="noopener"><?= e($tLink) ?></a></div><?php endif; ?></td>
    <td><?= ($adminsOf[(string) $t['id']] ?? []) === [] ? '<span class="badge badge-amber">none yet</span>' : e(implode(', ', $adminsOf[(string) $t['id']])) ?></td>
    <td><?= e((string) $t['plan'] ?: '—') ?></td>
    <td><?= $sub === null ? '—' : (int) ($sub['seats'] ?? 0) ?></td>
    <td><?= (int) $t['employees'] ?></td>
    <td><?= e((string) ($t['state'] ?? '') ?: '—') ?></td>
    <td class="note"><?= e((string) ($sub['next_renewal'] ?? $sub['current_period_end'] ?? '') ?: '—') ?></td>
    <td><span class="badge badge-<?= $st === 'active' ? 'green' : ($st === 'suspended' || $st === 'closed' ? 'red' : 'amber') ?>"><?= e($st) ?></span></td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="?r=tenants&edit=<?= e((string) $t['id']) ?>#form">Edit</a>
<?php if (in_array($st, ['active', 'suspended'], true)): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
        <input type="hidden" name="action" value="tenant"><input type="hidden" name="op" value="<?= $st === 'active' ? 'suspend' : 'reactivate' ?>"><input type="hidden" name="id" value="<?= e((string) $t['id']) ?>">
        <button class="btn btn-sm <?= $st === 'active' ? 'btn-outline' : 'btn-primary' ?>" type="submit" onclick="return confirm('<?= $st === 'active' ? 'Suspend' : 'Reactivate' ?> this tenant?')"><?= $st === 'active' ? 'Suspend' : 'Reactivate' ?></button></form>
<?php endif; ?>
<?php $tAdmins = $adminsOf[(string) $t['id']] ?? []; $hidT = '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="tenant"><input type="hidden" name="id" value="' . e((string) $t['id']) . '">'; ?>
<?php if ($tAdmins === []): ?>
      <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= $hidT ?><input type="hidden" name="op" value="reset-admin">
        <input name="admin_email" type="email" required placeholder="admin email" style="width:170px">
        <input name="password" type="password" required minlength="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" placeholder="password" autocomplete="new-password" style="width:120px">
        <button class="btn btn-sm btn-primary" type="submit" title="Create this workspace's administrator login"><i class="fas fa-user-plus"></i> Create admin</button></form></td>
<?php else: ?>
      <button class="btn btn-sm btn-outline" type="button" onclick="this.nextElementSibling.showModal()"><i class="fas fa-key"></i> Admin password</button>
      <dialog class="help-dlg" style="width:min(440px,94vw);height:fit-content;padding:20px 22px;white-space:normal">
        <h3 style="margin:0 0 4px">Admin password — <?= e((string) $t['name']) ?></h3>
        <p class="note" style="margin:0 0 14px">Sign-in ID: <b><?= e($tAdmins[0]) ?></b></p>
        <form method="post"><?= $hidT ?><input type="hidden" name="op" value="admin-set-password">
          <label>New password (<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>+ characters)</label>
          <input name="password" type="password" required minlength="<?= SmartPRS\Surface\Session::PASSWORD_FLOOR ?>" autocomplete="new-password" style="width:100%">
          <p style="margin:10px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Update password</button></p></form>
        <div style="border-top:1px solid var(--border);margin:16px 0 12px"></div>
        <form method="post"><?= $hidT ?><input type="hidden" name="op" value="admin-reset-link">
          <p class="note" style="margin:0 0 8px">Or email <?= e($tAdmins[0]) ?> a link to set their own password (needs Platform Email).</p>
          <button class="btn btn-outline" type="submit"><i class="fas fa-envelope"></i> Email reset link</button></form>
        <p style="margin:14px 0 0;text-align:right"><button class="btn btn-outline" type="button" onclick="this.closest('dialog').close()">Close</button></p>
      </dialog></td>
<?php endif; ?>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
