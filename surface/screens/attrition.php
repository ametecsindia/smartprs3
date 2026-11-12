<?php declare(strict_types=1); if (!$may('employee.list')): ?>
<div class="page-header"><div><h2>Attrition</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.list')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2 listed who was on notice / exited. This is the analytics it
// promised: joiners and leavers per month from the date of joining and the
// exit date (the record's column, else the timeline's exit event), and the
// rate = leavers ÷ average headcount, exact, with the working on every row.
$params = $_GET;
include __DIR__ . '/../partials/attrition-data.php';
$places = (int) ($cfg['percent_places'] ?? 2);
$totalJoin = array_sum(array_column($t['rows'], 'joiners'));
$totalLeave = array_sum(array_column($t['rows'], 'leavers'));
$showMonth = (string) ($_GET['m'] ?? '');
// THE PERIOD IN ONE LINE (owner, 30 Sep): opening at the start, closing at the
// end, rate = leavers ÷ average of the two — the same rule as each month row.
$atOpen = $t['rows'] === [] ? 0 : (int) $t['rows'][0]['opening'];
$atClose = $t['rows'] === [] ? 0 : (int) $t['rows'][count($t['rows']) - 1]['closing'];
$atRate = $atOpen + $atClose > 0 ? SmartPRS\Platform\Support\Rational::of(2 * $totalLeave, $atOpen + $atClose) : null;
$atRange = $months[0] . ($count > 1 ? ' to ' . $months[count($months) - 1] : '');
?>
<style>
  .at-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
  .at-tile { border:1px solid var(--line,#e2e8f0); border-radius:12px; padding:12px 14px; background:var(--card,#fff); min-width:0; }
  .at-tile .k { font-size:12px; color:#64748b; }
  .at-tile .v { font-size:24px; font-weight:800; line-height:1.2; margin-top:4px; }
  .at-tile .s { font-size:11px; color:#64748b; margin-top:2px; }
  /* Fields sit on a grid so selects, the employee box and the button never overlap at any width. */
  .at-filter form.row { display:grid !important; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:10px 12px; align-items:end; }
  .at-filter form.row > div { min-width:0; }
  .at-filter form.row > div[hidden] { display:none; }
  .at-filter form.row select, .at-filter form.row input:not([type=hidden]) { width:100% !important; max-width:100%; min-width:0 !important; box-sizing:border-box; }
  .at-filter form.row .note { grid-column:1 / -1; }
  .at-filter .emp-pick, .at-filter .emp-pick-box, .at-filter .emp-pick-text { width:100% !important; min-width:0 !important; box-sizing:border-box; }
  html[data-mode="dark"] .at-tile { background:#131c2b; border-color:#1e2a3d; }
</style>
<div class="page-header">
  <div><h2>Attrition</h2><p>Joiners and leavers for the period and people picked below. Rate = leavers ÷ average headcount, where average = (opening + closing) ÷ 2.</p></div>
  <div class="actions">
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="attrition-export"><input type="hidden" name="as" value="xlsx"><?= keepHidden($periodKeep + orgFilterKeep($filter)) ?><button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Excel</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="attrition-export"><input type="hidden" name="as" value="csv"><?= keepHidden($periodKeep + orgFilterKeep($filter)) ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form></div>
</div>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px;margin-bottom:12px">
    <h3 style="margin:0"><?= e($period['label']) ?></h3>
    <span class="note">Months counted: <?= e($atRange) ?><?= substr($period['to'], 0, 7) > $toMonth ? ' (up to this month)' : '' ?> &middot; <?= count($inView) ?> employee record(s) in view</span>
  </div>
  <div class="at-tiles">
    <div class="at-tile"><div class="k">Opening headcount</div><div class="v"><?= $atOpen ?></div><div class="s">at the start of <?= e($months[0]) ?></div></div>
    <div class="at-tile"><div class="k">Joined</div><div class="v" style="color:#15803d"><?= $totalJoin ?></div><div class="s">in <?= $count ?> month<?= $count === 1 ? '' : 's' ?></div></div>
    <div class="at-tile"><div class="k">Left</div><div class="v" style="color:#b91c1c"><?= $totalLeave ?></div><div class="s">in <?= $count ?> month<?= $count === 1 ? '' : 's' ?></div></div>
    <div class="at-tile"><div class="k">Closing headcount</div><div class="v"><?= $atClose ?></div><div class="s">at the end of <?= e($months[count($months) - 1]) ?></div></div>
    <div class="at-tile"><div class="k">Attrition rate</div><div class="v"><?= $atRate === null ? '—' : e($atRate->multipliedBy(SmartPRS\Platform\Support\Rational::of(100))->toDecimalString($places)) . '%' ?></div>
      <div class="s"><?= $totalLeave ?> ÷ ((<?= $atOpen ?> + <?= $atClose ?>) ÷ 2)</div></div>
  </div>
</div>

<div class="card at-filter">
<?= orgFilterBar('attrition', $filter, orgFilterOptions($store, $people), [], 'Show', null, $period) ?>
</div>
<script>
// ONE SHOW FOR BOTH ROWS: whichever Show is pressed, the other row's current
// choices go too (appended last, so they win over this row's carried copies).
(function () {
  var forms = document.querySelectorAll('.at-filter form');
  if (forms.length !== 2) { return; }
  forms.forEach(function (f, i) {
    var other = forms[1 - i];
    f.addEventListener('submit', function () {
      Array.prototype.forEach.call(other.elements, function (el) {
        // Skip the other row's carried copies (plain hidden inputs); the employee
        // picker's hidden input IS its live value, so it goes.
        if (!el.name || el.disabled || el.closest('[hidden]') || (el.type === 'hidden' && !el.closest('.emp-pick'))) { return; }
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = el.name; h.value = el.value;
        f.appendChild(h);
      });
    });
  });
})();
</script>
<div class="card">
  <h3 style="margin:0 0 10px">Month by month</h3>
  <div style="overflow-x:auto;margin-bottom:12px"><?= chartBars(array_column($t['rows'], 'month'), ['Joiners' => array_map('intval', array_column($t['rows'], 'joiners')), 'Leavers' => array_map('intval', array_column($t['rows'], 'leavers'))], ['Joiners' => '#16a34a', 'Leavers' => '#dc2626']) ?></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Month</th><th class="n">Opening</th><th class="n">Joiners</th><th class="n">Leavers</th><th class="n">Closing</th><th class="n">Avg headcount</th><th class="n">Rate</th><th>Working</th><th></th></tr></thead>
    <tbody>
<?php foreach ($t['rows'] as $r): ?>
      <tr>
        <td><b><?= e($r['month']) ?></b></td>
        <td class="n"><?= $r['opening'] ?></td>
        <td class="n"><?= $r['joiners'] ?></td>
        <td class="n"><?= $r['leavers'] ?></td>
        <td class="n"><?= $r['closing'] ?></td>
        <td class="n"><?= e($r['average']->toDecimalString(1)) ?></td>
        <td class="n" style="white-space:nowrap"><b><?= $r['rate'] === null ? '—' : e($r['rate']->multipliedBy(SmartPRS\Platform\Support\Rational::of(100))->toDecimalString($places)) . '%' ?></b></td>
        <td class="note" style="white-space:normal;font-size:11px;min-width:260px"><?= e($r['working']) ?></td>
        <td><?php if ($r['joiners'] + $r['leavers'] > 0): ?><a class="btn btn-outline btn-sm" href="?r=attrition<?php foreach ($periodKeep as $pk => $pv): ?>&amp;<?= e($pk) ?>=<?= e(rawurlencode((string) $pv)) ?><?php endforeach; ?>&amp;m=<?= e($r['month']) ?><?= orgFilterQuery($filter) ?>">Who</a><?php endif; ?></td>
      </tr>
<?php if ($showMonth === $r['month']): ?>
      <tr><td colspan="9" class="note">Joined: <?= $t['joiners'][$r['month']] === [] ? '—' : e(implode(', ', $t['joiners'][$r['month']])) ?> &middot; Left: <?= $t['leavers'][$r['month']] === [] ? '—' : e(implode(', ', $t['leavers'][$r['month']])) ?></td></tr>
<?php endif; ?>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php if ($t['unplaced'] !== []): ?>
  <div class="note" style="margin-top:10px"><b>Not counted:</b> <?= e(implode('; ', $t['unplaced'])) ?>. A leaver with no exit date cannot be placed in a month — record the exit under People → Exit &amp; FnF.</div>
<?php endif; ?>
  <p class="note">Headcount on a day = joined on or before it and not exited on or before it, so closing = opening + joiners − leavers on every row. Employees marked deleted are excluded; archived and exited ones are counted, because they are the point.</p>
</div>
