<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Asset Stock</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ASSET STOCK — the library's catalogue (owner, 29 Sep). Every physical item
// is ONE row with its own tag; the quantities below are COUNTED from those
// rows, never typed, so Available + Issued + Under repair + Lost always equals
// the total and a return shows up here the moment it is recorded. Issuing and
// taking back happen on Asset Issue & Returns (asset-issue.php); this screen
// adds stock and handles what is on the shelf: repair, back in stock, retire.
use SmartPRS\Surface\Assets;

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');
$declared = $assets->declared();
$statuses = (array) $declared['statuses'];
$available = (string) ($declared['initial_status'] ?? 'in stock');
$canMove = $may('employee.update');
$showCost = $may('employee.salary.view');
// COMPANY / BRANCH (owner, 29 Sep). Branch is a column from migration
// 2026_09_29_000100_asset_branch; until it has run the Branch box is hidden
// rather than posting a column the table does not have.
$names = orgNames($store);
$companyNames = (array) ($names['companies'] ?? []);
$branchNames = (array) ($names['branches'] ?? []);
$hasBranch = in_array('branch_id', $store->columnsOf('assets'), true);
$branchCompany = [];
foreach (liveRows($store, 'branches') as $b) { $branchCompany[(string) $b['id']] = (string) ($b['employer_company_id'] ?? $b['company_id'] ?? ''); }
$fCompany = (string) ($_GET['company'] ?? '');
$fBranch = $hasBranch ? (string) ($_GET['branch'] ?? '') : '';
$assets->onlyAt($fCompany, $fBranch);
$book = $assets->stockBook();
$shown = array_values(array_filter(array_keys($statuses), static fn (string $s): bool => $s !== 'retired'));
$totals = array_fill_keys($shown, 0);
$grand = 0;

foreach ($book as $b) {
    foreach ($shown as $s) {
        $totals[$s] += (int) ($b['counts'][$s] ?? 0);
    }

    $grand += $b['total'];
}

$byStatus = [];

foreach ($shown as $s) {
    $byStatus[$s] = $assets->inStatus($s);
    usort($byStatus[$s], static fn (array $a, array $b): int => strcmp((string) ($a['asset_id'] ?? ''), (string) ($b['asset_id'] ?? '')));
}

$movable = array_map('strval', (array) ($declared['transfer']['from'] ?? []));
$model = static fn (array $r): string => (string) ($r['make_model'] ?? '') ?: ((string) ($r['asset_name'] ?? '') ?: '—');
?>
<div class="page-header">
  <div><h2>Asset Stock</h2>
    <p>Every item you own, one row per tagged unit. Quantities are counted from those units, so they always add up. Issue and take back on <a href="?r=asset-issue">Asset Issue &amp; Returns</a>.</p></div>
  <div><?php foreach ($shown as $s): ?><span class="badge badge-<?= e((string) $statuses[$s]['badge']) ?>"><?= (int) $totals[$s] ?> <?= e((string) $statuses[$s]['label']) ?></span> <?php endforeach; ?></div>
</div>

<div class="card" style="padding:12px 16px">
<?php $exportReports = ['summary' => 'Stock summary (by category & model)', 'units' => 'All units — full register', 'available' => 'Available stock', 'repair' => 'Under repair', 'lost' => 'Lost', 'issued' => 'Issued — to be returned'];
      $exportKeep = ['company' => $fCompany, 'branch' => $fBranch];
      include __DIR__ . '/../partials/asset-export-bar.php'; ?>
</div>

<?php
    // STOCK DASHBOARD (owner, 29 Sep): one tile per category — total units and
    // how many are Available, Issued, Under repair, Lost — counted from the
    // same unit rows as everything below, for the company/branch picked.
    $dash = [];
    foreach ($book as $b) {
        $dash[$b['category']] ??= ['total' => 0, 'counts' => []];
        $dash[$b['category']]['total'] += $b['total'];
        foreach ($b['counts'] as $s => $n) { $dash[$b['category']]['counts'][$s] = ($dash[$b['category']]['counts'][$s] ?? 0) + $n; }
    }
    ksort($dash);
    $dashColour = ['gray' => '#475569', 'green' => '#15803d', 'amber' => '#b45309', 'red' => '#b91c1c'];
    $dashScope = trim(($fCompany !== '' ? ($companyNames[$fCompany] ?? '') : 'All companies') . ($fBranch !== '' ? ' · ' . ($branchNames[$fBranch] ?? '') : ''));
?>
<div class="card">
  <h3 style="margin:0 0 12px">Stock dashboard <span class="note"><?= e($dashScope) ?> &middot; <?= $grand ?> item(s)</span></h3>
<?php if ($dash === []): ?>
  <p class="note">No stock yet.</p>
<?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px">
<?php foreach ($dash as $cat => $d): ?>
    <div style="border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:12px 14px;background:var(--card,#fff)">
      <div style="display:flex;justify-content:space-between;align-items:baseline">
        <b><?= e((string) $cat) ?></b>
        <span style="font-size:22px;font-weight:800"><?= (int) $d['total'] ?></span>
      </div>
      <div class="note" style="margin:-2px 0 8px;text-align:right">total</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;font-size:13px">
<?php foreach ($shown as $s): $n = (int) ($d['counts'][$s] ?? 0); ?>
        <div style="display:flex;justify-content:space-between"><span style="color:<?= $dashColour[(string) $statuses[$s]['badge']] ?? '#475569' ?>"><?= e((string) $statuses[$s]['label']) ?></span><b><?= $n ?></b></div>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0"><input type="hidden" name="r" value="asset-stock">
    <div><label for="st_co">Company</label><select id="st_co" name="company"><option value="">ALL</option><?php foreach ($companyNames as $cid => $cn): ?><option value="<?= e((string) $cid) ?>"<?= (string) $cid === $fCompany ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
<?php if ($hasBranch): ?>    <div><label for="st_br">Branch</label><select id="st_br" name="branch" data-follow="st_co"><option value="">ALL</option><?php foreach ($branchNames as $bid => $bn): ?><option value="<?= e((string) $bid) ?>" data-company="<?= e($branchCompany[(string) $bid] ?? '') ?>"<?= (string) $bid === $fBranch ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><button class="go" type="submit">Show</button></div>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px">Stock summary</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Category</th><th>Make / model</th><th style="text-align:right">Total</th>
<?php foreach ($shown as $s): ?><th style="text-align:right"><?= e((string) $statuses[$s]['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
<?php if ($book === []): ?><tr><td colspan="<?= 3 + count($shown) ?>" class="note">No stock yet. Add an item below.</td></tr><?php endif; ?>
<?php foreach ($book as $b): ?>
      <tr><td><?= e($b['category']) ?></td><td><?= e($b['model']) ?></td><td style="text-align:right"><b><?= (int) $b['total'] ?></b></td>
<?php foreach ($shown as $s): $n = (int) ($b['counts'][$s] ?? 0); ?><td style="text-align:right"><?= $s === $available && $n > 0 ? '<b>' . $n . '</b>' : ($n ?: '<span class="note">0</span>') ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
    </tbody>
<?php if ($book !== []): ?><tfoot><tr><td colspan="2"><b>All items</b></td><td style="text-align:right"><b><?= $grand ?></b></td>
<?php foreach ($shown as $s): ?><td style="text-align:right"><b><?= (int) $totals[$s] ?></b></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
  </table></div>
</div>

<?php if ($canMove): ?>
<div class="card">
  <h3 style="margin:0 0 4px">Add to stock</h3>
  <p class="note" style="margin:0 0 12px">One row per physical item, each with its own tag. It starts <b><?= e($assets->label($available)) ?></b>.</p>
  <form method="post" class="row" style="align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="module-save">
    <input type="hidden" name="k" value="assets">
    <input type="hidden" name="back" value="asset-stock">
    <div><label for="as_id">Asset ID <span class="note">*</span></label><input id="as_id" name="asset_id" required placeholder="LAP-0042" style="width:130px"></div>
    <div><label for="as_type">Category <span class="note">*</span></label><input id="as_type" name="asset_type" required list="as_types" placeholder="Laptop, Phone, SIM…" style="width:150px">
      <datalist id="as_types"><?php foreach (array_unique(array_column($book, 'category')) as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
    <div><label for="as_model">Make / model</label><input id="as_model" name="make_model" list="as_models" placeholder="Dell Latitude 5420" style="width:170px">
      <datalist id="as_models"><?php foreach (array_unique(array_column($book, 'model')) as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></div>
    <div><label for="as_serial">Serial number</label><input id="as_serial" name="serial_no" style="width:150px"></div>
    <div><label for="as_co">Company <span class="note">*</span></label><select id="as_co" name="employer_company_id" required><option value="">— company —</option><?php foreach ($companyNames as $cid => $cn): ?><option value="<?= e((string) $cid) ?>"<?= (string) $cid === $fCompany ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
<?php if ($hasBranch): ?>    <div><label for="as_br">Branch <span class="note">*</span></label><select id="as_br" name="branch_id" required data-follow="as_co"><option value="">— branch —</option><?php foreach ($branchNames as $bid => $bn): ?><option value="<?= e((string) $bid) ?>" data-company="<?= e($branchCompany[(string) $bid] ?? '') ?>"<?= (string) $bid === $fBranch ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if ($showCost): ?><div><label for="as_cost">Cost (₹)</label><input id="as_cost" name="cost" type="number" min="0" step="0.01" style="width:110px"></div><?php endif; ?>
    <div><label for="as_pdate">Purchase date</label><input id="as_pdate" name="purchase_date" type="date" style="width:150px"></div>
    <div><button class="go" type="submit"><i class="fas fa-plus"></i> Add to stock</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($canMove && $movable !== []): ?>
<div class="card">
  <h3 style="margin:0 0 4px">Transfer to another branch / company</h3>
  <p class="note" style="margin:0 0 12px">Tick the items in the <?= e(implode(' / ', array_map(static fn (string $m): string => $assets->label($m), $movable))) ?> lists below, then pick where they go. Issued items must be returned first.</p>
  <form method="post" id="xfer-form" class="row" style="align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="asset-transfer">
    <input type="hidden" name="f_company" value="<?= e($fCompany) ?>"><input type="hidden" name="f_branch" value="<?= e($fBranch) ?>">
    <div><label for="xf_co">To company <span class="note">*</span></label><select id="xf_co" name="to_company" required><option value="">— company —</option><?php foreach ($companyNames as $cid => $cn): ?><option value="<?= e((string) $cid) ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
<?php if ($hasBranch): ?>    <div><label for="xf_br">To branch <span class="note">*</span></label><select id="xf_br" name="to_branch" required data-follow="xf_co"><option value="">— branch —</option><?php foreach ($branchNames as $bid => $bn): ?><option value="<?= e((string) $bid) ?>" data-company="<?= e($branchCompany[(string) $bid] ?? '') ?>"><?= e($bn) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div><label for="xf_note">Reason</label><input id="xf_note" name="note" placeholder="e.g. new joiners at the branch" style="width:220px"></div>
    <div><button class="go" type="submit" id="xf_go" disabled><i class="fas fa-right-left"></i> <span id="xf_lbl">Tick items to transfer</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php foreach ($shown as $s): if ($s === 'assigned') { continue; } $list = $byStatus[$s]; $tick = $canMove && in_array($s, $movable, true); ?>
<div class="card">
  <h3 style="margin:0 0 10px"><?= e((string) $statuses[$s]['label']) ?> <span class="note">(<?= count($list) ?>)</span></h3>
  <div class="table-wrap"><table>
    <thead><tr><?php if ($tick): ?><th style="width:28px"><input type="checkbox" class="xf-all" title="Tick all in this list"<?= $list === [] ? ' disabled' : '' ?>></th><?php endif; ?><th>Asset ID</th><th>Category</th><th>Make / model</th><th>Serial number</th><th>Company</th><?php if ($hasBranch): ?><th>Branch</th><?php endif; ?><?php if ($showCost): ?><th style="text-align:right">Cost</th><?php endif; ?><th>Purchased</th><th>Condition</th><?php if ($canMove): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
<?php if ($list === []): ?><tr><td colspan="11" class="note">None.</td></tr><?php endif; ?>
<?php foreach ($list as $r): ?>
      <tr>
<?php if ($tick): ?>        <td><input type="checkbox" class="xf-one" form="xfer-form" name="ids[]" value="<?= e((string) $r['id']) ?>"></td><?php endif; ?>
        <td><b><?= e((string) ($r['asset_id'] ?? '')) ?></b></td>
        <td><?= e((string) ($r['asset_type'] ?? '') ?: '—') ?></td>
        <td><?= e($model($r)) ?></td>
        <td><code><?= e((string) ($r['serial_no'] ?? '') ?: '—') ?></code></td>
        <td class="note"><?= e($companyNames[(string) ($r['employer_company_id'] ?? '')] ?? '—') ?></td>
<?php if ($hasBranch): ?>        <td class="note"><?= e($branchNames[(string) ($r['branch_id'] ?? '')] ?? '—') ?></td><?php endif; ?>
<?php if ($showCost): ?><td style="text-align:right"><?= e(trim((string) ($r['cost'] ?? '')) === '' ? '—' : SmartPRS\Surface\Amount::of($r['cost'])->format()) ?></td><?php endif; ?>
        <td class="note"><?= e((string) ($r['purchase_date'] ?? '') ?: '—') ?></td>
        <td class="note"><?= e(ucfirst((string) ($r['condition'] ?? '')) ?: '—') ?></td>
<?php if ($canMove): ?>
        <td style="white-space:nowrap">
          <a class="btn btn-outline btn-sm" href="?r=m&amp;k=assets&amp;edit=<?= e((string) $r['id']) ?>" title="Correct this item's details"><i class="fas fa-pen"></i></a>
<?php foreach ($assets->movesFrom($s) as $op): if (!empty($declared['transitions'][$op]['needs_employee'])) { continue; } $t = (array) $declared['transitions'][$op]; ?>
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
            <input type="hidden" name="action" value="asset-move"><input type="hidden" name="back" value="asset-stock">
            <input type="hidden" name="op" value="<?= e($op) ?>"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
            <button class="btn btn-outline btn-sm" type="submit"><?= e((string) $t['label']) ?></button></form>
<?php endforeach; ?>
        </td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
<?php if ($showCost && $list !== []): ?><tfoot><tr><td colspan="<?= ($hasBranch ? 6 : 5) + ($tick ? 1 : 0) ?>"><b><?= count($list) ?> item(s)</b></td><td style="text-align:right"><b><?= e(Assets::cost($list)->format()) ?></b></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
  </table></div>
</div>
<?php endforeach; ?>
<script>
// TRANSFER: "tick all" per list, and the button counts what is ticked.
(function () {
  var go = document.getElementById('xf_go');
  if (!go) { return; }
  var lbl = document.getElementById('xf_lbl');
  function count() {
    var n = document.querySelectorAll('input.xf-one:checked').length;
    go.disabled = n === 0;
    lbl.textContent = n === 0 ? 'Tick items to transfer' : 'Transfer ' + n + ' item' + (n === 1 ? '' : 's');
  }
  document.querySelectorAll('input.xf-all').forEach(function (all) {
    all.addEventListener('change', function () {
      all.closest('table').querySelectorAll('input.xf-one').forEach(function (c) { c.checked = all.checked; });
      count();
    });
  });
  document.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('xf-one')) { count(); } });
  count();
})();
// BRANCH FOLLOWS COMPANY: a branch select lists only the picked company's branches.
document.querySelectorAll('select[data-follow]').forEach(function (br) {
  var co = document.getElementById(br.dataset.follow);
  if (!co) { return; }
  function sync() {
    Array.prototype.forEach.call(br.options, function (o) {
      var own = !o.value || !co.value || !o.dataset.company || o.dataset.company === co.value;
      o.hidden = !own;
      if (!own && o.selected) { br.value = ''; }
    });
  }
  co.addEventListener('change', sync);
  sync();
});
</script>
