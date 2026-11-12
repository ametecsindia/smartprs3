<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Subscriptions</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Subscriptions: every tenant's plan, seats, cycle, period end
// and renewal date, with Renew and Cancel. The amount is the plan's monthly
// price at those seats × the cycle's months, computed by SaasPlatform when
// saved. Written by surface/actions/subscription.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$tenants = $plans = [];
foreach ($platform->all('tenants') as $t) { $tenants[(string) $t['id']] = (string) $t['name']; }
foreach ($platform->all('plans') as $p) { $plans[(string) $p['id']] = $p; }
$rows = $platform->all('subscriptions');
usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['current_period_end'] ?? ''), (string) ($b['current_period_end'] ?? '')));
$editing = isset($_GET['edit']) ? $platform->find('subscriptions', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$today = date('Y-m-d');
$live = (array) $declared['subscription_live'];
?>
<div class="page-header"><div><h2>Subscriptions</h2>
  <p><?= count($rows) ?> subscription(s), soonest period end first. Renew extends the period by the cycle's months and marks it active; Cancel keeps the row and closes it.</p></div>
  <div><a class="btn btn-primary" href="?r=subscriptions&edit=new#form"><i class="fas fa-plus"></i> Add subscription</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New subscription' : 'Edit subscription #' . e($v('id')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="subscription"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div><label>Tenant</label><select name="tenant_id" required><option value="">— choose —</option><?php foreach ($tenants as $id => $n): ?><option value="<?= e($id) ?>"<?= $id === $v('tenant_id') ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
      <div><label>Plan</label><select name="plan_id" required><option value="">— choose —</option><?php foreach ($plans as $id => $p): ?><option value="<?= e($id) ?>"<?= $id === $v('plan_id') ? ' selected' : '' ?>><?= e((string) $p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Seats</label><input type="number" min="1" name="seats" required value="<?= e($v('seats') ?: '1') ?>" style="width:90px"></div>
      <div><label>Companies</label><input type="number" min="1" name="companies" value="<?= e($v('companies') ?: '1') ?>" style="width:90px"></div>
      <div><label>Cycle</label><select name="cycle"><?php foreach (array_keys((array) $declared['cycles']) as $c): ?><option<?= $c === $v('cycle') ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label>Period start</label><input type="date" name="period_start" value="<?= e($today) ?>"><span class="note">period end = start + cycle months</span></div>
      <div><label>Status</label><select name="status"><?php foreach ((array) $declared['subscription_statuses'] as $s): ?><option<?= $s === ($v('status') ?: 'active') ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=subscriptions">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Tenant</th><th>Plan</th><th>Seats</th><th>Cycle</th><th>Amount / cycle</th><th>Period end</th><th>Next renewal</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="9" class="note">No subscriptions yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $s): $st = (string) ($s['status'] ?? ''); $end = (string) ($s['current_period_end'] ?? ''); $plan = $plans[(string) ($s['plan_id'] ?? '')] ?? null; ?>
  <tr>
    <td><b><?= e($tenants[(string) ($s['tenant_id'] ?? '')] ?? ('Tenant #' . (string) $s['tenant_id'])) ?></b></td>
    <td><?= e($plan === null ? 'Plan #' . (string) ($s['plan_id'] ?? '?') . ' (gone)' : (string) $plan['name']) ?></td>
    <td><?= (int) ($s['seats'] ?? 0) ?></td>
    <td><?= e((string) ($s['cycle'] ?? '')) ?></td>
    <td><?= e(SmartPRS\Surface\Amount::of($s['amount'] ?? '0')->format()) ?><?php if ($plan !== null): ?><div class="note" style="font-size:11px"><?= e(SmartPRS\Surface\SaasPlatform::monthly($plan, (int) ($s['seats'] ?? 0))->format()) ?>/mo × <?= $saas->months((string) ($s['cycle'] ?? '')) ?></div><?php endif; ?></td>
    <td class="note" style="<?= $end !== '' && $end < $today && in_array($st, $live, true) ? 'color:#dc2626;font-weight:700' : '' ?>"><?= e($end ?: '—') ?><?php if ($end !== ''): ?><div style="font-size:11px"><?= SmartPRS\Surface\Licensing::daysBetween($today, $end) ?> days</div><?php endif; ?></td>
    <td class="note"><?= e((string) ($s['next_renewal'] ?? '') ?: '—') ?></td>
    <td><span class="badge badge-<?= $st === 'active' ? 'green' : ($st === 'trial' ? 'amber' : ($st === 'past due' ? 'red' : 'gray')) ?>"><?= e($st) ?></span></td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="?r=subscriptions&edit=<?= e((string) $s['id']) ?>#form">Edit</a>
<?php if (in_array($st, $live, true) || $st === 'expired'): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="subscription"><input type="hidden" name="op" value="renew"><input type="hidden" name="id" value="<?= e((string) $s['id']) ?>"><button class="btn btn-sm btn-primary" type="submit">Renew</button></form>
<?php endif; if (in_array($st, $live, true)): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="subscription"><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= e((string) $s['id']) ?>"><button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Cancel this subscription?')">Cancel</button></form>
<?php endif; ?></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
