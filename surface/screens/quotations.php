<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Quotations</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Quotations: a priced offer to a lead — plan × seats × cycle,
// less a coupon or a typed discount, plus GST for the buyer's state — with a
// validity date and a status. The figures are SaasPlatform::quote(), stored
// on the row when saved so the quote a client accepted never changes under
// them (Law 3). Accepting converts it into a tenant + subscription
// (surface/actions/quotation.php).
$saas = new SmartPRS\Surface\SaasPlatform($platform, __DIR__ . '/../../config');
$declared = $saas->declared();
$leads = $plans = [];
foreach ($platform->all('leads') as $l) { $leads[(string) $l['id']] = $l; }
foreach ($platform->all('plans') as $p) { $plans[(string) $p['id']] = $p; }
$states = array_keys((array) $saas->gst()['state_codes']);
$rows = $platform->all('quotations');
usort($rows, static fn (array $a, array $b): int => (int) $b['id'] - (int) $a['id']);
$editing = isset($_GET['edit']) ? $platform->find('quotations', (string) $_GET['edit']) : null;
$v = static fn (string $k): string => (string) ($editing[$k] ?? '');
$today = date('Y-m-d');
$Amt = SmartPRS\Surface\Amount::class;
?>
<div class="page-header"><div><h2>Quotations</h2>
  <p><?= count($rows) ?> quotation(s). Plan × seats × cycle months, less coupon, plus GST for the buyer's state. Accept creates the tenant and its subscription; the quoted figures are kept as quoted.</p></div>
  <div><a class="btn btn-primary" href="?r=quotations&edit=new#form"><i class="fas fa-plus"></i> New quotation</a></div></div>

<?php if (isset($_GET['edit'])): ?>
<div class="card" id="form"><h3 style="margin-top:0"><?= $editing === null ? 'New quotation' : 'Edit quotation #' . e($v('id')) ?></h3>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="quotation"><input type="hidden" name="op" value="save"><input type="hidden" name="id" value="<?= e($v('id')) ?>">
    <div class="row" style="flex-wrap:wrap;gap:10px">
      <div style="min-width:220px"><label>Lead</label><select name="lead_id"><option value="">— none —</option><?php foreach ($leads as $id => $l): ?><option value="<?= e($id) ?>"<?= $id === $v('lead_id') ? ' selected' : '' ?>><?= e((string) $l['name'] . ((string) ($l['company'] ?? '') === '' ? '' : ' — ' . (string) $l['company'])) ?></option><?php endforeach; ?></select><span class="note">fills company and email when blank</span></div>
      <div><label>Company</label><input name="company" value="<?= e($v('company')) ?>"></div>
      <div><label>Email</label><input name="email" type="email" value="<?= e($v('email')) ?>"></div>
      <div><label>State (GST)</label><input name="state" list="q-states" value="<?= e($v('state')) ?>" placeholder="Telangana"><datalist id="q-states"><?php foreach ($states as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
      <div><label>Plan</label><select name="plan_id" required><option value="">— choose —</option><?php foreach ($plans as $id => $p): ?><option value="<?= e($id) ?>"<?= $id === $v('plan_id') ? ' selected' : '' ?>><?= e((string) $p['name'] . ' · ' . $Amt::of($p['base_price'] ?? '0')->format() . '/mo') ?></option><?php endforeach; ?></select></div>
      <div><label>Seats</label><input type="number" min="1" name="seats" required value="<?= e($v('seats') ?: '10') ?>" style="width:90px"></div>
      <div><label>Cycle</label><select name="cycle"><?php foreach (array_keys((array) $declared['cycles']) as $c): ?><option<?= $c === ($v('cycle') ?: 'annual') ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label>Coupon code</label><input name="coupon_code" value="<?= e($v('coupon_code')) ?>" style="text-transform:uppercase;width:130px"><span class="note">checked against Discount Coupons</span></div>
      <div><label>or flat discount ₹</label><input name="discount" inputmode="decimal" pattern="\d+(\.\d{1,2})?" value="<?= e($v('coupon_code') === '' && $v('discount') !== '' ? $Amt::of($v('discount'))->toDecimalString() : '') ?>" style="width:110px"></div>
      <div><label>Valid till</label><input type="date" name="valid_till" value="<?= e($v('valid_till') ?: (new DateTimeImmutable($today))->modify('+' . (int) $declared['quotation_valid_days'] . ' days')->format('Y-m-d')) ?>"></div>
      <div><label>Status</label><select name="status"><?php foreach ((array) $declared['quotation_statuses'] as $s): ?><option<?= $s === ($v('status') ?: 'draft') ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
      <div style="flex-basis:100%"><label>Notes</label><input name="notes" value="<?= e($v('notes')) ?>" style="width:100%"></div>
    </div>
    <p style="margin:12px 0 0"><button class="go" type="submit"><i class="fas fa-calculator"></i> Save &amp; price</button> <a class="btn btn-outline" href="?r=quotations">Cancel</a></p>
  </form></div>
<?php endif; ?>

<div class="card" style="padding:0"><div class="table-wrap"><table>
  <thead><tr><th>#</th><th>For</th><th>Plan</th><th>Subtotal</th><th>Discount</th><th>GST</th><th>Total</th><th>Valid till</th><th>Status</th><th></th></tr></thead><tbody>
<?php if ($rows === []): ?><tr><td colspan="10" class="note">No quotations yet.</td></tr><?php endif; ?>
<?php foreach ($rows as $q): $st = (string) ($q['status'] ?? ''); $plan = $plans[(string) ($q['plan_id'] ?? '')] ?? null;
    $lapsed = in_array($st, ['draft', 'sent'], true) && (string) ($q['valid_till'] ?? '') !== '' && (string) $q['valid_till'] < $today; ?>
  <tr>
    <td><code>Q-<?= e((string) $q['id']) ?></code></td>
    <td><b><?= e((string) ($q['company'] ?? '') ?: ($leads[(string) ($q['lead_id'] ?? '')]['name'] ?? '—')) ?></b><div class="note" style="font-size:11px"><?= e(trim(implode(' · ', array_filter([(string) ($q['email'] ?? ''), (string) ($q['state'] ?? '')])))) ?></div></td>
    <td><?= e($plan === null ? 'Plan #' . (string) ($q['plan_id'] ?? '?') : (string) $plan['name']) ?><div class="note" style="font-size:11px"><?= (int) ($q['seats'] ?? 0) ?> seats · <?= e((string) ($q['cycle'] ?? '')) ?></div></td>
    <td><?= e($Amt::of($q['subtotal'] ?? '0')->format()) ?></td>
    <td><?= e($Amt::of($q['discount'] ?? '0')->format()) ?><?php if ((string) ($q['coupon_code'] ?? '') !== ''): ?><div class="note" style="font-size:11px"><?= e((string) $q['coupon_code']) ?></div><?php endif; ?></td>
    <td><?= e($Amt::of($q['tax'] ?? '0')->format()) ?></td>
    <td><b><?= e($Amt::of($q['total'] ?? '0')->format()) ?></b></td>
    <td class="note" style="<?= $lapsed ? 'color:#dc2626;font-weight:700' : '' ?>"><?= e((string) ($q['valid_till'] ?? '') ?: '—') ?></td>
    <td><span class="badge badge-<?= in_array($st, ['accepted', 'paid'], true) ? 'green' : ($lapsed || in_array($st, ['expired', 'declined'], true) ? 'red' : 'amber') ?>"><?= e($lapsed ? 'lapsed' : $st) ?></span><?php if ((string) ($q['converted_tenant_id'] ?? '') !== ''): ?><div class="note" style="font-size:11px"><a href="?r=tenants&edit=<?= e((string) $q['converted_tenant_id']) ?>">tenant #<?= e((string) $q['converted_tenant_id']) ?></a></div><?php endif; ?></td>
    <td style="white-space:nowrap"><a class="btn btn-sm btn-outline" href="?r=quotations&edit=<?= e((string) $q['id']) ?>#form">Edit</a>
<?php if (in_array($st, ['draft', 'sent', 'accepted'], true) && (string) ($q['converted_tenant_id'] ?? '') === ''): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="quotation"><input type="hidden" name="op" value="accept"><input type="hidden" name="id" value="<?= e((string) $q['id']) ?>"><button class="btn btn-sm btn-primary" type="submit" onclick="return confirm('Accept: create the tenant and its subscription from this quote?')">Accept → tenant</button></form>
<?php endif; ?></td>
  </tr>
<?php endforeach; ?>
  </tbody></table></div></div>
