<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Plans &amp; Pricing</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Plans: name, base price per month (includes seat_max
// employees), extra employee price, default cycle, and the modules the plan
// unlocks — ticked from the module registry's groups, stored as
// {"modules": [...]} in the plan's features column. Hand-built because the
// register engine has no multi-select. Saved by surface/actions/plan.php.
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$cycles = array_keys((array) $declared['cycles']);
$groups = $modules->byGroup();
$rows = $platform->all('plans');
$editing = isset($_GET['edit']) ? $platform->find('plans', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$on = $editing === null ? [] : SmartPRS\Surface\SaasPlatform::planModules($editing);
$money = static fn (mixed $s): string => SmartPRS\Surface\Amount::of($s)->format();
?>
<div class="page-header"><div><h2>Plans &amp; Pricing</h2>
  <p><?= count($rows) ?> plan(s). A plan is the public offer: what it costs per month for the employees included, what each extra employee costs, and which modules it unlocks. Annual and other cycles bill the monthly price × months from config/plans.json.</p></div>
  <div><a class="btn btn-primary" href="?r=plans&edit=new#form"><i class="fas fa-plus"></i> New plan</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New plan' : 'Edit ' . e($v('name')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="plan"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div style="flex:1;min-width:200px"><label>Plan name</label><input name="name" required value="<?= e($v('name')) ?>" style="width:100%"></div>
      <div><label>Base price ₹/month</label><input name="base_price" required inputmode="decimal" pattern="\d+(\.\d{1,2})?" value="<?= e($v('base_price') === '' ? '' : SmartPRS\Surface\Amount::of($v('base_price'))->toDecimalString()) ?>"><span class="note">includes the employees below</span></div>
      <div><label>Extra employee ₹/month</label><input name="per_user_price" inputmode="decimal" pattern="\d+(\.\d{1,2})?" value="<?= e($v('per_user_price') === '' ? '' : SmartPRS\Surface\Amount::of($v('per_user_price'))->toDecimalString()) ?>"></div>
      <div><label>Employees included</label><input type="number" min="0" name="seat_max" value="<?= e($v('seat_max')) ?>" placeholder="blank = unlimited"></div>
      <div><label>Default cycle</label><select name="billing_cycle"><?php foreach ($cycles as $c): ?><option<?= $c === $v('billing_cycle') ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label>Status</label><select name="status"><?php foreach ((array) $declared['plan_statuses'] as $s): ?><option<?= $s === ($v('status') ?: 'offered') ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    </div>
    <fieldset class="form-section" style="margin-top:12px"><legend>Included modules — tick what this package unlocks</legend>
      <div class="row" style="flex-wrap:wrap;gap:6px 18px">
<?php foreach ($groups as $group => $list): ?>
        <div style="min-width:200px"><b class="note"><?= e((string) $group) ?></b>
<?php foreach ($list as $m): ?>
          <label style="display:block;font-weight:400"><input type="checkbox" name="modules[]" value="<?= e((string) $m['key']) ?>" style="width:auto"<?= in_array((string) $m['key'], $on, true) ? ' checked' : '' ?>> <?= e((string) $m['label']) ?></label>
<?php endforeach; ?>
        </div>
<?php endforeach; ?>
      </div></fieldset>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save</button> <a class="btn btn-outline" href="?r=plans">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>Plan</th><th>Monthly</th><th>Annual</th><th>Extra / employee</th><th>Employees included</th><th>Modules</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="8" class="note">No plans yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $p): $mods = SmartPRS\Surface\SaasPlatform::planModules($p); $st = (string) ($p['status'] ?? ''); ?>
  <tr>
    <td><b><?= e((string) $p['name']) ?></b><div class="note" style="font-size:11px">default cycle: <?= e((string) ($p['billing_cycle'] ?? '') ?: '—') ?></div></td>
    <td><?= e($money($p['base_price'] ?? '0')) ?></td>
    <td><?= e(SmartPRS\Surface\Amount::of($p['base_price'] ?? '0')->multipliedBy($saas->months('annual'))->format()) ?> <span class="note" style="font-size:11px">× <?= $saas->months('annual') ?></span></td>
    <td><?= e($money($p['per_user_price'] ?? '0')) ?></td>
    <td><?= (int) ($p['seat_max'] ?? 0) > 0 ? (int) $p['seat_max'] : 'Unlimited' ?></td>
    <td class="note" style="max-width:320px;white-space:normal"><?= $mods === [] ? '—' : e(count($mods) . ' module(s): ' . implode(', ', $mods)) ?></td>
    <td><span class="badge badge-<?= $st === 'offered' ? 'green' : ($st === 'withdrawn' ? 'red' : 'gray') ?>"><?= e($st ?: '—') ?></span></td>
    <td><a class="btn btn-sm btn-outline" href="?r=plans&edit=<?= e((string) $p['id']) ?>#form">Edit</a></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
