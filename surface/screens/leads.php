<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Leads (Demo Requests)</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Leads: every website enquiry with source and a status worked
// New → Contacted → Closed (config/leads.json), notes per lead, one-tap call /
// WhatsApp / email, plus Convert to tenant. The OTP-verified live-demo
// entries (demo_requests) are listed beneath. Written by
// surface/actions/lead.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$pipeline = $saas->leadPipeline();
$statuses = (array) $pipeline['statuses'];
$sources = (array) $pipeline['sources'];
$filter = (string) ($_GET['status'] ?? 'all');
$all = $platform->all('leads');
usort($all, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$counts = [];
foreach ($all as $l) { $counts[(string) ($l['status'] ?? 'new')] = ($counts[(string) ($l['status'] ?? 'new')] ?? 0) + 1; }
$rows = $filter === 'all' ? $all : array_values(array_filter($all, static fn (array $l): bool => (string) ($l['status'] ?? 'new') === $filter));
$editing = isset($_GET['edit']) ? $platform->find('leads', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$demos = $platform->all('demo_requests');
usort($demos, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$tel = static fn (string $n): string => preg_replace('/\D+/', '', $n) ?? '';
?>
<div class="page-header"><div><h2>Leads (Demo Requests)</h2>
  <p><?= count($all) ?> lead(s). Start each morning on New; call or WhatsApp from the row, note what happened, move the status forward. Convert creates the tenant from the lead's company.</p></div>
  <div><a class="btn btn-primary" href="?r=leads&edit=new#form"><i class="fas fa-plus"></i> Add lead</a></div></div>

<div class="row" style="gap:6px;flex-wrap:wrap;margin-bottom:10px">
  <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline' ?>" href="?r=leads">All <?= count($all) ?></a>
<?php foreach ($statuses as $s => $meta): ?>
  <a class="btn btn-sm <?= $filter === $s ? 'btn-primary' : 'btn-outline' ?>" href="?r=leads&status=<?= e((string) $s) ?>"><?= e((string) $meta['label']) ?> <?= (int) ($counts[$s] ?? 0) ?></a>
<?php endforeach; ?>
</div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New lead' : 'Edit ' . e($v('name')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="lead"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div><label>Name</label><input name="name" required value="<?= e($v('name')) ?>"></div>
      <div><label>Company</label><input name="company" value="<?= e($v('company')) ?>"></div>
      <div><label>Designation</label><input name="designation" value="<?= e($v('designation')) ?>"></div>
      <div><label>City</label><input name="city" value="<?= e($v('city')) ?>"></div>
      <div><label>Mobile</label><input name="mobile" type="tel" value="<?= e($v('mobile')) ?>"></div>
      <div><label>Email</label><input name="email" type="email" value="<?= e($v('email')) ?>"></div>
      <div><label>Employees</label><input name="employees" value="<?= e($v('employees')) ?>" placeholder="e.g. 50-100" style="width:110px"></div>
      <div><label>Source</label><select name="source"><?php foreach ($sources as $s): ?><option<?= $s === ($v('source') ?: 'manual') ? ' selected' : '' ?>><?= e((string) $s) ?></option><?php endforeach; ?></select></div>
      <div><label>Status</label><select name="status"><?php foreach ($statuses as $s => $meta): ?><option value="<?= e((string) $s) ?>"<?= $s === ($v('status') ?: 'new') ? ' selected' : '' ?>><?= e((string) $meta['label']) ?></option><?php endforeach; ?></select></div>
      <div style="flex-basis:100%"><label>Challenges (what they asked for)</label><textarea name="challenges" rows="2" style="width:100%"><?= e($v('challenges')) ?></textarea></div>
      <div style="flex-basis:100%"><label>Notes</label><textarea name="notes" rows="2" style="width:100%"><?= e($v('notes')) ?></textarea></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=leads">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>When</th><th>Lead</th><th>Contact</th><th>Source</th><th>Notes</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="7" class="note">No leads<?= $filter === 'all' ? ' yet — website enquiries land here' : ' in this status' ?>.</td></tr><?php endif; ?>
<?php foreach ($rows as $l): $st = (string) ($l['status'] ?? 'new'); $meta = (array) ($statuses[$st] ?? ['label' => $st, 'badge' => 'gray']); ?>
  <tr>
    <td class="note"><?= e(substr((string) ($l['created_at'] ?? ''), 0, 16)) ?></td>
    <td><b><?= e((string) $l['name']) ?></b><div class="note" style="font-size:11px"><?= e(trim(implode(' · ', array_filter([(string) ($l['company'] ?? ''), (string) ($l['designation'] ?? ''), (string) ($l['city'] ?? ''), (string) ($l['employees'] ?? '') === '' ? '' : (string) $l['employees'] . ' employees'])))) ?></div></td>
    <td style="white-space:nowrap;font-size:12px">
<?php if (trim((string) ($l['mobile'] ?? '')) !== ''): ?><a href="tel:<?= e($tel((string) $l['mobile'])) ?>"><i class="fas fa-phone"></i> <?= e((string) $l['mobile']) ?></a> <a href="https://wa.me/<?= e($tel((string) $l['mobile'])) ?>" target="_blank" title="WhatsApp"><i class="fab fa-whatsapp"></i></a><br><?php endif; ?>
<?php if (trim((string) ($l['email'] ?? '')) !== ''): ?><a href="mailto:<?= e((string) $l['email']) ?>"><i class="fas fa-envelope"></i> <?= e((string) $l['email']) ?></a><?php endif; ?></td>
    <td><?= e((string) ($l['source'] ?? '') ?: '—') ?></td>
    <td class="note" style="max-width:260px;white-space:normal;font-size:12px"><?= e((string) ($l['challenges'] ?? '')) ?><?= trim((string) ($l['notes'] ?? '')) !== '' ? '<br><b>' . e((string) $l['notes']) . '</b>' : '' ?></td>
    <td><span class="badge badge-<?= e((string) $meta['badge']) ?>"><?= e((string) $meta['label']) ?></span><?php if ((string) ($l['converted_tenant_id'] ?? '') !== ''): ?><div class="note" style="font-size:11px"><a href="?r=tenants&edit=<?= e((string) $l['converted_tenant_id']) ?>">tenant #<?= e((string) $l['converted_tenant_id']) ?></a></div><?php endif; ?></td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="?r=leads&edit=<?= e((string) $l['id']) ?>#form">Edit</a>
<?php if ($st === 'new'): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="lead"><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= e((string) $l['id']) ?>"><input type="hidden" name="status" value="contacted"><button class="btn btn-sm btn-outline" type="submit">Contacted</button></form>
<?php endif; if ($st !== 'converted'): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="lead"><input type="hidden" name="op" value="convert"><input type="hidden" name="id" value="<?= e((string) $l['id']) ?>"><button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Create a tenant from this lead?')">Convert to tenant</button></form>
<?php endif; if (!in_array($st, ['closed', 'converted'], true)): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="lead"><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= e((string) $l['id']) ?>"><input type="hidden" name="status" value="closed"><button class="btn btn-sm btn-outline" type="submit">Close</button></form>
<?php endif; ?></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>

<?php if ($demos !== []): ?>
<div class="card" style="padding:0;margin-top:14px"><div style="padding:12px 16px;border-bottom:1px solid var(--border)"><b>Live-demo entries</b> <span class="note">— OTP-verified mobile numbers; the warmest leads. Add one as a lead to work it.</span></div>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>Name</th><th>Company</th><th>Phone</th><th>Email</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach ($demos as $d): ?>
  <tr><td class="note"><?= e(substr((string) ($d['created_at'] ?? ''), 0, 16)) ?></td><td><b><?= e((string) $d['name']) ?></b></td><td><?= e((string) ($d['company'] ?? '')) ?></td>
    <td><a href="tel:<?= e($tel((string) ($d['phone'] ?? ''))) ?>"><?= e((string) ($d['phone'] ?? '')) ?></a></td><td><?= e((string) ($d['email'] ?? '')) ?></td>
    <td><span class="badge badge-gray"><?= e((string) ($d['status'] ?? '')) ?></span></td><td class="note"><?= e(substr((string) ($d['last_login_at'] ?? ''), 0, 16) ?: '—') ?></td>
    <td><form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="lead"><input type="hidden" name="op" value="from-demo"><input type="hidden" name="id" value="<?= e((string) $d['id']) ?>"><button class="btn btn-sm btn-outline" type="submit">Add as lead</button></form></td></tr>
<?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
