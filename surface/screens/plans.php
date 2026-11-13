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
// THE PRICE LIST (owner, 13 Nov 2026): the SmartPRS 3.0 prices the website, sign-up and renewals charge —
// config/landing.json "pricing" as edited — shown and edited here, as SmartPRS 2's Plans & Pricing did.
$plLc = new SmartPRS\Surface\LandingContent(dirname(__DIR__, 2) . '/config/landing.json', new SmartPRS\Surface\PlatformSettings($platform));
$pr = (array) ($plLc->content()['pricing'] ?? []);
$pkgs = array_values((array) ($pr['cloud']['packages'] ?? []));
// ponytail: plan rows follow the price list on open, so the list is never blank; same upsert sign-up already does.
$saas->syncPackages($pkgs);
$rows = $platform->all('plans');
usort($rows, static fn (array $a, array $b): int => (int) ($a['seat_max'] ?? 0) <=> (int) ($b['seat_max'] ?? 0));
$pcy = array_values((array) ($pr['cloud']['cycles'] ?? []));
$n = static fn (mixed $x): string => e((string) $x);
$annualOff = 0;
foreach ($pcy as $c) { (int) ($c['months'] ?? 0) === 12 && $annualOff = (int) ($c['discount'] ?? 0); }
?>
<div class="page-header"><div><h2>Plans &amp; Pricing</h2>
  <p><?= count($rows) ?> plan(s). Edit the price list below; the plans follow it. Use Edit on a plan to choose the modules it unlocks.</p></div>
  <div><a class="btn btn-primary" href="?r=plans&edit=new#form"><i class="fas fa-plus"></i> New plan</a></div></div>

<div class="card"><h3 style="margin-top:0">Price list <span class="note" style="font-weight:400;font-size:13px">— what smartprs.com, sign-up and renewals charge. Save publishes at once. GST extra.</span></h3>
<form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="plan"><input type="hidden" name="op" value="pricing">
  <b>Cloud plans</b> <span class="note">— price per month includes the employees shown; above the largest plan the website says <?= $n($pr['cloud']['above_text'] ?? 'Contact Sales') ?>. Leave the last row blank, or fill it to add a plan.</span>
  <div class="table-wrap"><table><thead><tr><th>Plan name</th><th>Employees included</th><th>₹ per month</th><th>Extra employee ₹/month</th><th>Highlight on website</th></tr></thead><tbody>
<?php foreach (array_merge($pkgs, [[]]) as $i => $p): ?>
    <tr><td><input name="pkg[<?= $i ?>][name]" value="<?= $n($p['name'] ?? '') ?>"></td>
      <td><input name="pkg[<?= $i ?>][employees]" inputmode="numeric" pattern="\d+" value="<?= $n($p['employees'] ?? '') ?>"></td>
      <td><input name="pkg[<?= $i ?>][monthly]" inputmode="numeric" pattern="\d+" value="<?= $n($p['monthly'] ?? '') ?>"></td>
      <td><input name="pkg[<?= $i ?>][extra]" inputmode="numeric" pattern="\d+" value="<?= $n($p['extra'] ?? '') ?>"></td>
      <td><input type="radio" name="highlight" value="<?= $i ?>" style="width:auto"<?= !empty($p['highlight']) ? ' checked' : '' ?>></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
  <div class="row" style="flex-wrap:wrap;gap:10px;margin-top:10px">
    <div><label>Each extra company ₹/month</label><input name="company_fee" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['cloud']['company_fee'] ?? 0) ?>"></div>
  </div>
  <b style="display:block;margin-top:14px">Advance payment periods</b>
  <div class="table-wrap"><table><thead><tr><th>Label</th><th>Months</th><th>Discount %</th></tr></thead><tbody>
<?php foreach (array_merge($pcy, [[]]) as $i => $c): ?>
    <tr><td><input name="cyc[<?= $i ?>][label]" value="<?= $n($c['label'] ?? '') ?>"></td>
      <td><input name="cyc[<?= $i ?>][months]" inputmode="numeric" pattern="\d+" value="<?= $n($c['months'] ?? '') ?>"></td>
      <td><input name="cyc[<?= $i ?>][discount]" inputmode="numeric" pattern="\d+" value="<?= $n($c['discount'] ?? '') ?>"></td></tr>
<?php endforeach; ?>
  </tbody></table></div>
  <div class="row" style="flex-wrap:wrap;gap:10px;margin-top:14px">
    <div><label>On-premises lifetime licence ₹ (one time)</label><input name="licence" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['onprem']['licence'] ?? 0) ?>"></div>
    <div><label>AMC % of licence per year</label><input name="amc_percent" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['onprem']['amc_percent'] ?? 0) ?>"></div>
    <div><label>Installation ₹ (on-premises)</label><input name="installation" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['onprem']['installation'] ?? 0) ?>"></div>
    <div><label>Setup &amp; onboarding ₹ (minimum)</label><input name="ob_base" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['onboarding']['base'] ?? 0) ?>"></div>
    <div><label>+ ₹ per employee</label><input name="ob_per" inputmode="numeric" pattern="\d+" required value="<?= $n($pr['onboarding']['per_employee'] ?? 0) ?>"></div>
  </div>
  <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-save"></i> Save prices</button> <a class="btn btn-outline" href="landing.php#pricing" target="_blank" rel="noopener">View on website</a></p>
</form></div>

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
    <td><?= e(SmartPRS\Surface\Amount::of($p['base_price'] ?? '0')->multipliedBy(12 * (100 - $annualOff))->dividedBy(100, SmartPRS\Platform\Support\RoundingMode::HalfUp)->format()) ?> <span class="note" style="font-size:11px">× 12<?= $annualOff > 0 ? ' − ' . $annualOff . '%' : '' ?></span></td>
    <td><?= e($money($p['per_user_price'] ?? '0')) ?></td>
    <td><?= (int) ($p['seat_max'] ?? 0) > 0 ? (int) $p['seat_max'] : 'Unlimited' ?></td>
    <td class="note" style="max-width:320px;white-space:normal"><?= $mods === [] ? '—' : e(count($mods) . ' module(s): ' . implode(', ', $mods)) ?></td>
    <td><span class="badge badge-<?= $st === 'offered' ? 'green' : ($st === 'withdrawn' ? 'red' : 'gray') ?>"><?= e($st ?: '—') ?></span></td>
    <td><a class="btn btn-sm btn-outline" href="?r=plans&edit=<?= e((string) $p['id']) ?>#form">Edit</a></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
