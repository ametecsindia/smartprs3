<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Asset Issue &amp; Returns</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ASSET ISSUE & RETURNS — the library's counter (owner, 29 Sep). Issue offers
// ONLY what Asset Stock has available right now, grouped with its quantity; a
// unit issued here leaves the available count, and a return puts it straight
// back (or into repair / lost, by the condition it came back in — see
// config/assets.json `by_condition`). Assets::move() re-checks the unit's
// status on every post, so two people issuing the same unit cannot both win.
// With ?emp=<id> it is the "assets held" list Exit & FnF clearance links to.
use SmartPRS\Surface\Assets;
use SmartPRS\Surface\Employment;

$assets = new Assets($store, __DIR__ . '/../../config/assets.json');
$declared = $assets->declared();
$statuses = (array) $declared['statuses'];
$available = (string) ($declared['initial_status'] ?? 'in stock');
$held = (array) ($declared['held_statuses'] ?? ['assigned']);
$canMove = $may('employee.update');
$showCost = $may('employee.salary.view');
$emp = preg_match('/^\d{1,12}$/', (string) ($_GET['emp'] ?? '')) === 1 ? (string) $_GET['emp'] : '';
$people = [];
$peopleRows = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $people[(string) $e['id']] = Employment::code($e) . ' ' . (string) ($e['name'] ?? '');
    $peopleRows[(string) $e['id']] = $e;
}

// THE DIRECTORY, per employee: what the Issue form shows the moment someone
// is picked, and what the Issued table lists — read from the employee record,
// never typed here.
$names = orgNames($store);
$branchNames = (array) ($names['branches'] ?? []);
$companyNames = (array) ($names['companies'] ?? []);
$dir = [];
// Which stock each person may be handed: the unit's `issue_within` columns
// (company + branch, config/assets.json) must equal the employee's.
$within = $assets->issueWithin();
$whereKey = static fn (array $r): string => implode('|', array_map(static fn (string $c): string => trim((string) ($r[$c] ?? '')), $within));
foreach ($peopleRows as $pid => $pe) {
    $mid = (string) ($pe['reporting_manager_id'] ?? '');
    $dir[$pid] = ['code' => Employment::code($pe), 'name' => (string) ($pe['name'] ?? ''),
        'company' => orgValueOf($store, $pe, 'company'), 'branch' => orgValueOf($store, $pe, 'branch'),
        'department' => orgValueOf($store, $pe, 'department'), 'designation' => orgValueOf($store, $pe, 'designation'),
        'manager' => $mid !== '' ? ($people[$mid] ?? '#' . $mid) : '', 'w' => $whereKey($pe)];
}
// What each person already holds, for the "already issued" question on the Issue form.
$watched = array_map('strtolower', array_map('strval', (array) ($declared['reissue_confirm_categories'] ?? [])));
$holding = [];
foreach ($assets->live() as $h) {
    $hid = (string) ($h['issued_to_employee_id'] ?? '');
    if ($hid !== '' && in_array($assets->statusOf($h), $held, true) && in_array(strtolower(trim((string) ($h['asset_type'] ?? ''))), $watched, true)) {
        $holding[$hid][] = ['category' => strtolower(trim((string) $h['asset_type'])), 'type' => (string) $h['asset_type'],
            'model' => (string) ($h['make_model'] ?? '') ?: (string) ($h['asset_name'] ?? ''), 'serial' => (string) ($h['serial_no'] ?? ''), 'on' => (string) ($h['issue_date'] ?? '')];
    }
}
$model = static fn (array $r): string => (string) ($r['make_model'] ?? '') ?: ((string) ($r['asset_name'] ?? '') ?: '—');

// WHAT CAN BE HANDED OUT: available units only, grouped Category · Make/model.
$shelf = [];

foreach ($assets->inStatus($available) as $r) {
    $shelf[(string) ($r['asset_type'] ?? '') ?: '—'][$model($r)][] = $r;
}

ksort($shelf);
$availableCount = count($assets->inStatus($available));

// WHAT IS OUT: issued units (all, or one holder's).
$out = array_values(array_filter($assets->live(), static fn (array $r): bool
    => in_array($assets->statusOf($r), $held, true)
    && ($emp === '' || (string) ($r['issued_to_employee_id'] ?? '') === $emp)));
usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['issue_date'] ?? ''), (string) ($b['issue_date'] ?? '')) ?: strcmp((string) ($a['asset_id'] ?? ''), (string) ($b['asset_id'] ?? '')));
?>
<div class="page-header">
  <div><h2>Asset Issue &amp; Returns</h2>
    <p>Hand out what is available, take it back when it returns. Stock numbers live on <a href="?r=asset-stock">Asset Stock</a> and update with every move here.</p></div>
  <div><span class="badge badge-gray"><?= $availableCount ?> <?= e($assets->label($available)) ?></span>
    <span class="badge badge-green"><?= count(array_filter($assets->live(), static fn (array $r): bool => in_array($assets->statusOf($r), $held, true))) ?> <?= e($assets->label('assigned')) ?></span></div>
</div>

<div class="card" style="padding:12px 16px">
<?php $exportReports = ['issued' => 'Issued — to be returned' . ($emp !== '' ? ' (this employee)' : ''), 'available' => 'Available stock', 'summary' => 'Stock summary (by category & model)', 'units' => 'All units — full register',
          'returned' => 'Returned items' . ($emp !== '' ? ' (this employee)' : '')];
      $exportKeep = ['emp' => $emp];
      include __DIR__ . '/../partials/asset-export-bar.php'; ?>
</div>

<?php if ($canMove): ?>
<div class="card">
  <h3 style="margin:0 0 4px">Issue an asset</h3>
  <p class="note" style="margin:0 0 12px">Pick the employee first — only stock at that employee's company and branch is listed, with how many of each are left. Use <b>Add another item</b> to hand several to the same employee in one go.</p>
<?php if ($shelf === []): ?>
  <p class="problem" style="margin:0 0 10px">Nothing is available to issue right now. Add stock on <a href="?r=asset-stock">Asset Stock</a>, or wait for a return.</p>
<?php endif; ?>
  <?php // SEVERAL ITEMS IN ONE ISSUE (owner, 30 Sep): the employee and the date
        // are picked once; each item line has its own item, condition and purpose.
        // "Add another item" clones the first line; an item picked on one line is
        // greyed out on the others so the same unit cannot be issued twice. ?>
  <form method="post" id="ai-form">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="asset-move"><input type="hidden" name="op" value="issue">
    <input type="hidden" name="reissue_ok" value="">
    <div class="row" style="align-items:flex-start;flex-wrap:wrap">
      <div><label for="ai_to">Employee ID / name <span class="note">*</span></label>
        <?= employeePicker('ai_to', 'employee_id', array_map(static fn (array $d): string => $d['code'] . ' · ' . $d['name'], $dir), '', 'Type employee ID or name', true, 'style="min-width:240px"') ?>
        <div id="ai-who" style="margin-top:4px;font-size:12.5px;color:#334155;display:none"></div></div>
      <div><label for="ai_on">Issued on</label><input id="ai_on" type="date" name="on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" style="width:150px"></div>
    </div>
    <div id="ai-lines">
      <div class="row ai-line" style="align-items:flex-end;flex-wrap:wrap;margin-top:10px">
        <div><label for="ai_item">Item <span class="ai-no note">1</span> <span class="note">*</span></label>
          <select id="ai_item" class="ai-item" name="items[0][id]" required style="width:340px;max-width:100%"<?= $shelf === [] ? ' disabled' : '' ?>><option value="">— pick the employee first —</option>
<?php foreach ($shelf as $category => $models): ksort($models); foreach ($models as $m => $units): ?>
          <optgroup label="<?= e($category . ' · ' . $m . ' — ' . count($units) . ' available') ?>" data-base="<?= e($category . ' · ' . $m) ?>">
<?php foreach ($units as $u): $where = trim(($companyNames[(string) ($u['employer_company_id'] ?? '')] ?? '') . ' / ' . ($branchNames[(string) ($u['branch_id'] ?? '')] ?? ''), ' /'); ?>            <option value="<?= e((string) $u['id']) ?>" data-category="<?= e(strtolower(trim((string) ($u['asset_type'] ?? '')))) ?>" data-type="<?= e((string) ($u['asset_type'] ?? '')) ?>" data-w="<?= e($whereKey($u)) ?>"><?= e((string) ($u['asset_id'] ?? '')) ?><?= trim((string) ($u['serial_no'] ?? '')) !== '' ? ' · S/N ' . e((string) $u['serial_no']) : '' ?><?= $where !== '' ? ' · ' . e($where) : '' ?></option>
<?php endforeach; ?>          </optgroup>
<?php endforeach; endforeach; ?>
          </select></div>
        <div><label for="ai_cond">Condition</label><select id="ai_cond" class="ai-c" name="items[0][condition]"><option value="">—</option><?php foreach ((array) $declared['conditions'] as $c): if (in_array($c, ['damaged', 'lost'], true)) { continue; } ?><option value="<?= e((string) $c) ?>"><?= e(ucfirst((string) $c)) ?></option><?php endforeach; ?></select></div>
        <div><label for="ai_purpose">Purpose</label><input id="ai_purpose" class="ai-p" name="items[0][purpose]" placeholder="e.g. field work" style="width:160px"></div>
        <div><button type="button" class="btn btn-sm ai-del" title="Remove this item" style="display:none"><i class="fas fa-xmark"></i> Remove</button></div>
      </div>
    </div>
    <div class="row" style="align-items:center;flex-wrap:wrap;margin-top:12px">
      <div><button type="button" id="ai-add" class="btn btn-sm"<?= $availableCount < 2 ? ' disabled' : '' ?>><i class="fas fa-plus"></i> Add another item</button></div>
      <div><button class="go" type="submit"<?= $shelf === [] ? ' disabled' : '' ?>><i class="fas fa-right-from-bracket"></i> <span id="ai-go">Issue</span></button></div>
    </div>
  </form>
  <div id="ai-elsewhere" class="note" style="margin-top:8px;display:none"></div>
  <div id="ai-none" class="problem" style="margin-top:10px;display:none">No stock is available at this employee's company and branch. Add stock there on <a href="?r=asset-stock">Asset Stock</a>, or wait for a return.</div>
</div>
<script>
(function () {
  var form = document.getElementById('ai-form');
  if (!form) { return; }
  var dir = <?= json_encode($dir, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var holding = <?= json_encode($holding, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var watched = <?= json_encode(array_values($watched)) ?>;
  var avail = 0;
  var none = document.getElementById('ai-none');
  var emp = form.querySelector('input[type=hidden][name=employee_id]');
  var who = document.getElementById('ai-who');
  var box = document.getElementById('ai-lines');
  var addBtn = document.getElementById('ai-add');
  var goLbl = document.getElementById('ai-go');
  var first = box.querySelector('.ai-line');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s || '—'; return d.innerHTML; };
  var lines = function () { return Array.prototype.slice.call(box.querySelectorAll('.ai-line')); };
  // EMPLOYEE DETAILS FROM THE DIRECTORY, shown the moment somebody is picked.
  function show() {
    var d = dir[emp.value];
    if (!d) { who.style.display = 'none'; return; }
    // Company | Branch | Designation | Reporting Manager, right under the picker (owner, 22 Oct 2026).
    who.innerHTML = [d.company, d.branch, d.designation, d.manager].map(esc).join(' &nbsp;|&nbsp; ');
    who.style.display = '';
  }
  // Renumber lines, grey out units already picked on another line, and keep
  // the Add / Remove buttons and the Issue count in step.
  // ONLY THIS EMPLOYEE'S COMPANY + BRANCH STOCK: other units are hidden, a
  // pick that no longer fits is cleared, and each group shows its own count.
  function sync() {
    var all = lines();
    var d = dir[emp.value];
    var here = function (o) { return !!d && o.dataset.w === d.w; };
    all.forEach(function (l) {
      var sel = l.querySelector('.ai-item');
      var cur = sel.selectedOptions[0];
      if (cur && cur.value !== '' && !here(cur)) { sel.value = ''; }
      sel.options[0].textContent = d ? '— pick an available item —' : '— pick the employee first —';
    });
    avail = 0;
    Array.prototype.forEach.call(first.querySelectorAll('.ai-item optgroup'), function (g) {
      var n = Array.prototype.filter.call(g.querySelectorAll('option'), here).length;
      avail += n;
    });
    none.style.display = d && avail === 0 ? '' : 'none';
    // STOCK AT OTHER BRANCHES IS NOT MISSING (owner, 22 Oct 2026): a unit is issued only within the employee's own
    // company and branch, so say how many available units sit elsewhere and where, instead of leaving them unexplained.
    var away = {};
    Array.prototype.forEach.call(first.querySelectorAll('.ai-item option[value]'), function (o) {
      if (!d || o.value === '' || here(o)) { return; }
      var where = (o.textContent.split(' · ').pop() || '').trim();
      away[where] = (away[where] || 0) + 1;
    });
    var awayList = Object.keys(away).sort().map(function (k) { return away[k] + ' at ' + esc(k); });
    var elsewhere = document.getElementById('ai-elsewhere');
    elsewhere.innerHTML = awayList.length ? 'Also available, but at another branch/company (issued only within the employee\'s own): ' + awayList.join(', ')
      + '. To hand one over here, move it with <b>Transfer</b> on <a href="?r=asset-stock">Asset Stock</a> first.' : '';
    elsewhere.style.display = awayList.length ? '' : 'none';
    var picked = all.map(function (l) { return l.querySelector('.ai-item').value; });
    all.forEach(function (l, i) {
      l.querySelector('.ai-no').textContent = all.length > 1 ? String(i + 1) : '';
      l.querySelector('.ai-item').name = 'items[' + i + '][id]';
      l.querySelector('.ai-c').name = 'items[' + i + '][condition]';
      l.querySelector('.ai-p').name = 'items[' + i + '][purpose]';
      l.querySelector('.ai-del').style.display = all.length > 1 ? '' : 'none';
      Array.prototype.forEach.call(l.querySelectorAll('.ai-item option[value]'), function (o) {
        if (o.value === '') { return; }
        o.hidden = !here(o);
        o.disabled = !here(o) || (picked.indexOf(o.value) !== -1 && picked[i] !== o.value);
      });
      Array.prototype.forEach.call(l.querySelectorAll('.ai-item optgroup'), function (g) {
        var n = Array.prototype.filter.call(g.querySelectorAll('option'), here).length;
        g.hidden = n === 0;
        g.label = g.dataset.base + ' — ' + n + ' available';
      });
    });
    addBtn.disabled = !d || all.length >= avail;
    var n = picked.filter(function (v) { return v !== ''; }).length;
    goLbl.textContent = n > 1 ? 'Issue ' + n + ' items' : 'Issue';
  }
  addBtn.addEventListener('click', function () {
    var l = first.cloneNode(true);
    Array.prototype.forEach.call(l.querySelectorAll('[id]'), function (el) { el.removeAttribute('id'); });
    Array.prototype.forEach.call(l.querySelectorAll('label'), function (el) { el.removeAttribute('for'); });
    l.querySelector('.ai-item').value = '';
    l.querySelector('.ai-c').value = '';
    l.querySelector('.ai-p').value = '';
    box.appendChild(l);
    sync();
    l.querySelector('.ai-item').focus();
  });
  box.addEventListener('click', function (e) {
    var del = e.target.closest('.ai-del');
    if (!del || lines().length < 2) { return; }
    var l = del.closest('.ai-line');
    if (l === first) {
      // Keep the id-bearing first line: move the second line's answers up and drop that one.
      var next = lines()[1];
      ['.ai-item', '.ai-c', '.ai-p'].forEach(function (q) { first.querySelector(q).value = next.querySelector(q).value; });
      l = next;
    }
    l.remove();
    sync();
  });
  box.addEventListener('change', sync);
  emp.addEventListener('change', function () { show(); sync(); });
  form.addEventListener('input', function () { setTimeout(function () { show(); sync(); }, 0); });
  // ALREADY HOLDS ONE? Ask before a second laptop/CPU — counting what the
  // employee holds AND the other lines of this same issue. One question for
  // all of them; No puts the form back as it was.
  form.addEventListener('submit', function (e) {
    form.reissue_ok.value = '';
    var list = (holding[emp.value] || []).slice();
    var asks = [];
    lines().forEach(function (l) {
      var opt = l.querySelector('.ai-item').selectedOptions[0];
      var cat = opt ? (opt.dataset.category || '') : '';
      if (cat === '') { return; }
      var prior = list.filter(function (h) { return h.category === cat; })[0];
      if (prior) {
        asks.push(prior.on === 'this issue'
          ? 'Another ' + prior.type.toLowerCase() + ' is already on this issue (' + (prior.model || '—') + ').'
          : 'A ' + prior.type + ' (Make/Model: ' + (prior.model || '—') + ', serial number: ' + (prior.serial || '—') + ') was already issued to this employee on ' + (prior.on || '—') + '.');
      }
      if (watched.indexOf(cat) !== -1) {
        list.push({category: cat, type: opt.dataset.type || cat, model: opt.textContent.trim(), serial: '', on: 'this issue'});
      }
    });
    if (asks.length === 0) { return; }
    if (window.confirm(asks.join('\n') + '\n\nReissue anyway?')) { form.reissue_ok.value = '1'; return; }
    e.preventDefault();
    lines().slice(1).forEach(function (l) { l.remove(); });
    form.reset();
    emp.value = '';
    var t = form.querySelector('.emp-pick-text'); if (t) { t.value = ''; }
    sync();
    show();
  });
  sync();
})();
</script>
<?php endif; ?>

<div class="card">
  <div class="page-header" style="margin:0 0 12px">
    <div><h3 style="margin:0">Issued — to be returned <span class="note">(<?= count($out) ?>)</span></h3>
      <p style="margin:4px 0 0"><?= $emp !== '' ? 'What ' . e($people[$emp] ?? '#' . $emp) . ' still holds. Every item here must be returned before clearance.' : 'Everything currently with an employee. Returning an item puts it back into Asset Stock.' ?></p></div>
  </div>
  <form method="get" class="row" style="align-items:flex-end;margin-bottom:14px"><input type="hidden" name="r" value="asset-issue">
    <div><label for="ai_emp">Held by</label><select id="ai_emp" name="emp"><option value="">ALL employees</option>
<?php foreach ($people as $id => $label): ?><option value="<?= e($id) ?>"<?= (string) $id === $emp ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></div><div><button class="go" type="submit">Show</button></div>
  </form>
  <?php // TWO BANDS (owner, 29 Sep): everything up to Purpose is one colour — the
        // issued item and who holds it — and the Return inputs are a second
        // colour, one column each. The inputs sit in their own cells and belong to
        // the row's form through form="…", so each row still posts only itself. ?>
  <style>
    table.ai-table th.ai-i, table.ai-table td.ai-i { background:#f8fafc; }
    table.ai-table thead th.ai-i { background:#e2e8f0; }
    table.ai-table th.ai-r, table.ai-table td.ai-r { background:#fff7ed; }
    table.ai-table thead th.ai-r { background:#fed7aa; }
    table.ai-table td.ai-r select, table.ai-table td.ai-r input { width:100%; min-width:110px; }
    table.ai-table td.ai-r :disabled { opacity:.45; }
    html[data-mode="dark"] table.ai-table td.ai-i { background:#131c2b; } html[data-mode="dark"] table.ai-table thead th.ai-i { background:#1e2a3d; }
    html[data-mode="dark"] table.ai-table td.ai-r { background:#1f1810; } html[data-mode="dark"] table.ai-table thead th.ai-r { background:#3a2a14; }
  </style>
  <div class="table-wrap"><table class="ai-table">
    <thead>
    <tr><th class="ai-i" colspan="<?= $showCost ? 14 : 13 ?>">Issued item &amp; employee</th><?php if ($canMove): ?><th class="ai-r" colspan="6" style="text-align:center">Return</th><?php endif; ?></tr>
    <tr>
      <th class="ai-i">Asset ID</th><th class="ai-i">Category</th><th class="ai-i">Make / model</th><th class="ai-i">Serial number</th>
<?php if ($showCost): ?><th class="ai-i" style="text-align:right">Cost</th><?php endif; ?>
      <th class="ai-i">Issued on</th><th class="ai-i">Employee ID</th><th class="ai-i">Employee name</th><th class="ai-i">Designation</th><th class="ai-i">Department</th><th class="ai-i">Reporting manager</th><th class="ai-i">Company</th><th class="ai-i">Branch</th><th class="ai-i">Purpose</th>
<?php if ($canMove): ?><th class="ai-r">Return date</th><th class="ai-r">Condition</th><th class="ai-r">Final status (if damaged / lost)</th><th class="ai-r">Recovery amount (₹)</th><th class="ai-r">Recovery status</th><th class="ai-r"></th><?php endif; ?>
    </tr></thead>
    <tbody>
<?php if ($out === []): ?><tr><td class="ai-i note" colspan="<?= ($showCost ? 14 : 13) + ($canMove ? 6 : 0) ?>"><?= $emp !== '' ? 'Nothing held — clearance can proceed on assets.' : 'Nothing is issued right now.' ?></td></tr><?php endif; ?>
<?php foreach ($out as $r):
    $holder = (string) ($r['issued_to_employee_id'] ?? '');
    $holderRow = $peopleRows[$holder] ?? null;
    $mgrId = (string) ($holderRow['reporting_manager_id'] ?? '');
    $fid = 'ret-' . (string) $r['id'];
?>
      <tr>
        <td class="ai-i"><b><?= e((string) ($r['asset_id'] ?? '')) ?></b></td>
        <td class="ai-i"><?= e((string) ($r['asset_type'] ?? '') ?: '—') ?></td>
        <td class="ai-i"><?= e($model($r)) ?></td>
        <td class="ai-i"><code><?= e((string) ($r['serial_no'] ?? '') ?: '—') ?></code></td>
<?php if ($showCost): ?><td class="ai-i" style="text-align:right"><?= e(trim((string) ($r['cost'] ?? '')) === '' ? '—' : SmartPRS\Surface\Amount::of($r['cost'])->format()) ?></td><?php endif; ?>
        <td class="ai-i note"><?= e((string) ($r['issue_date'] ?? '') ?: '—') ?><?= trim((string) ($r['issued_by'] ?? '')) !== '' ? '<div style="font-size:11px">by ' . e((string) $r['issued_by']) . '</div>' : '' ?></td>
        <td class="ai-i note"><?= $holder === '' ? '—' : e($holderRow !== null ? Employment::code($holderRow) : '#' . $holder) ?></td>
        <td class="ai-i"><?= $holder === '' ? '—' : e((string) ($holderRow['name'] ?? ('#' . $holder))) ?></td>
        <td class="ai-i note"><?= $holderRow !== null ? e(orgValueOf($store, $holderRow, 'designation') ?: '—') : '—' ?></td>
        <td class="ai-i note"><?= $holderRow !== null ? e(orgValueOf($store, $holderRow, 'department') ?: '—') : '—' ?></td>
        <td class="ai-i note"><?= $mgrId !== '' ? e($people[$mgrId] ?? '#' . $mgrId) : '—' ?></td>
        <td class="ai-i note"><?= $holderRow !== null ? e(orgValueOf($store, $holderRow, 'company') ?: '—') : '—' ?></td>
        <td class="ai-i note"><?= $holderRow !== null ? e(orgValueOf($store, $holderRow, 'branch') ?: '—') : '—' ?></td>
        <td class="ai-i note"><?= e((string) ($r['purpose'] ?? '') ?: '—') ?></td>
<?php if ($canMove): ?>
        <td class="ai-r"><input form="<?= e($fid) ?>" type="date" name="on" value="<?= e(date('Y-m-d')) ?>" min="<?= e((string) ($r['issue_date'] ?? '')) ?>" max="<?= e(date('Y-m-d')) ?>" required title="Return date"></td>
        <td class="ai-r"><select form="<?= e($fid) ?>" name="condition" required class="ai-cond" title="Damaged goes to repair, Lost is taken out of stock"><option value="">— condition —</option><?php foreach ((array) $declared['conditions'] as $c): ?><option value="<?= e((string) $c) ?>"><?= e(ucfirst((string) $c)) ?></option><?php endforeach; ?></select></td>
        <td class="ai-r"><select form="<?= e($fid) ?>" name="damage_resolution" class="ai-dmg" disabled><option value="">—</option><?php foreach ((array) ($declared['damage_resolutions'] ?? []) as $dr): ?><option><?= e((string) $dr) ?></option><?php endforeach; ?></select></td>
        <td class="ai-r"><input form="<?= e($fid) ?>" type="number" min="0" step="0.01" name="recovery_amount" class="ai-dmg" placeholder="0.00" data-cost="<?= e(trim((string) ($r['cost'] ?? '')) === '' ? '' : SmartPRS\Surface\Amount::of((string) $r['cost'])->toDecimalString()) ?>" title="The unit's cost by default — change it if a different amount is to be recovered" disabled></td>
        <td class="ai-r"><select form="<?= e($fid) ?>" name="recovery_status" class="ai-dmg" disabled><option value="">—</option><?php foreach ((array) ($declared['recovery_statuses'] ?? []) as $rs2): ?><option><?= e((string) $rs2) ?></option><?php endforeach; ?></select></td>
        <td class="ai-r"><form method="post" id="<?= e($fid) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
            <input type="hidden" name="action" value="asset-move"><input type="hidden" name="op" value="return"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><input type="hidden" name="emp" value="<?= e($emp) ?>">
            <button class="btn btn-primary btn-sm" type="submit">Return</button></form></td>
<?php endif; ?>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<script>
// The damage / recovery answers only apply to a Damaged or Lost return: they
// open when that condition is picked (and Final status becomes required), and
// close — and clear — for New / Good / Fair, so nothing stray is posted.
document.querySelectorAll('table.ai-table select.ai-cond').forEach(function (cond) {
  var row = cond.closest('tr');
  function sync() {
    var bad = cond.value === 'damaged' || cond.value === 'lost';
    row.querySelectorAll('.ai-dmg').forEach(function (el) {
      el.disabled = !bad;
      if (!bad) { el.value = ''; }
      if (el.name === 'damage_resolution') { el.required = bad; }
      // DAMAGED / LOST: the recovery amount starts at the unit's cost and stays editable (owner, 22 Oct 2026).
      if (bad && el.name === 'recovery_amount' && el.value === '' && el.dataset.cost) { el.value = el.dataset.cost; }
    });
  }
  cond.addEventListener('change', sync);
  sync();
});
</script>
