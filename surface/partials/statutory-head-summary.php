<?php declare(strict_types=1);
/**
 * THE MONTHLY SUMMARY OF STATUTORY HEADS PER EMPLOYEE, read from closed runs.
 * Included by surface/screens/pf-esi.php and tds.php with $heads (a list of
 * head keys from config/compliance-calendar.json), $route and $title set.
 *
 * THE FIGURES COME FROM THE CLOSED RUNS — nothing is projected. Layout (owner,
 * 15 Oct): tiles, chart, then one row per employee with Designation, Branch, a
 * column per month and a total; Excel export carries the same columns.
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;

$params = $_GET;
include __DIR__ . '/statutory-head-data.php';
$shLabel = static fn (string $p): string => date('M Y', (int) strtotime($p . '-01'));
$shMonthMinor = [];
foreach ($heads as $h) { foreach ($periods as $p) { $t = 0; foreach ($rows as $r) { $t += (int) ($byHead[$h][$r['code']][$p] ?? 0); } $shMonthMinor[$h][$p] = $t; } }
$shWith = array_values(array_filter($rows, static fn (array $r): bool => array_sum($r['cells']) !== 0));
$shIsTds = $heads === ['TDS'];
?>
<style>
  .sh-n{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
  .sh-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px}
  .sh-tiles .card{margin:0;padding:14px 16px}
  .sh-tiles .note{text-transform:uppercase;font-size:11px;letter-spacing:.05em}
  .sh-tiles b{display:block;font-size:24px;font-family:var(--mono);margin-top:4px}
  .sh-sub{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:0 0 6px}
  .sh-table td,.sh-table th{vertical-align:middle}
  .sh-table tfoot td{font-weight:700;background:color-mix(in srgb,var(--text1) 4%,var(--card))}
</style>
<div class="page-header">
  <div><h2><?= e($title) ?></h2><p><?= e(implode(' and ', array_map(static fn (string $h): string => (string) $cal['heads'][$h]['label'], $heads))) ?> deducted from each employee, month by month, as the completed payroll recorded it &middot; <?= e($shLabel($fromPeriod)) ?> to <?= e($shLabel($toPeriod)) ?></p></div>
  <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<?php if ($shIsTds): ?>
    <a class="btn btn-outline" href="?r=tds-rules"><i class="fas fa-scale-balanced"></i> Income Tax Rules</a>
<?php endif; ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="statutory-summary-export"><input type="hidden" name="as" value="xlsx"><input type="hidden" name="route" value="<?= e($route) ?>"><?= keepHidden($shKeep + orgFilterKeep($filter)) ?><button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Export Excel</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="statutory-summary-export"><input type="hidden" name="as" value="csv"><input type="hidden" name="route" value="<?= e($route) ?>"><?= keepHidden($shKeep + orgFilterKeep($filter)) ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form>
  </div>
</div>

<div class="card">
  <p class="sh-sub">Period</p>
  <?= periodBar($route, $shPeriod ?? reportPeriod(['p' => 'fy'], financialYearStart($customisation), date('Y-m-d')), orgFilterKeep($filter)) ?>
  <p class="note" style="margin:6px 0 14px"><?= $shPeriod === null ? 'Showing the last ' . $months . ' months to ' . e($shLabel($toPeriod)) . ' — pick a period above.' : '<a href="?r=' . e($route) . orgFilterQuery($filter) . '">Show the last ' . (int) $cal['periods_shown'] . ' months</a>' ?></p>
  <p class="sh-sub">Who</p>
  <?= orgFilterBar($route, $filter, orgFilterOptions($store, $people), $shKeep) ?>
</div>

<?php foreach ($packState as $h => $state): if ($shIsTds || $state['ok'] || $totals[$h] > 0) { continue; } ?>
<div class="card"><div class="problem" style="margin:0"><b><?= e($h) ?> is not being deducted:</b> no closed payroll run in this period holds a <?= e($h) ?> line. Set <?= e($h) ?> up in the Salary Structure rules (Organization Configuration).</div></div>
<?php endforeach; ?>

<div class="sh-tiles">
  <div class="card"><span class="note">Completed payroll months</span><b><?= count($periods) ?></b></div>
<?php foreach ($heads as $h): ?>
  <div class="card"><span class="note"><?= e($h) ?> deducted</span><b style="color:var(--accent)"><?= e(Money::ofMinor($totals[$h])->format()) ?></b></div>
<?php endforeach; ?>
  <div class="card"><span class="note">Employees with a deduction</span><b><?= count($shWith) ?> <span class="note" style="font-size:12px;text-transform:none;letter-spacing:0">of <?= count($rows) ?></span></b></div>
</div>

<?php if ($periods === []): ?>
<div class="card"><p class="note" style="margin:0">No payroll is completed between <?= e($shLabel($fromPeriod)) ?> and <?= e($shLabel($toPeriod)) ?>. Close a month on Payroll and its deductions appear here.</p></div>
<?php else: ?>
<?php
  $shByMonth = [];
  foreach ($heads as $h) { $shByMonth[$h] = array_map(static function (string $p) use ($byHead, $h, $inView): int { $sum = 0; foreach ($inView as $e) { $sum += (int) ($byHead[$h][\SmartPRS\Surface\Employment::code($e)][$p] ?? 0); } return intdiv($sum, 100); }, $periods); }
  $shPalette = ['#1e3a5f', '#f97316', '#16a34a', '#a855f7'];
  $shColours = [];
  foreach (array_values($heads) as $i => $h) { $shColours[$h] = $shPalette[$i % 4]; }
?>
<div class="card">
  <h3 style="font-size:15px;font-weight:700;margin:0 0 10px">Deducted per month <span class="note" style="font-weight:400">(₹, whole rupees)</span></h3>
  <div style="overflow-x:auto"><?= chartBars(array_map($shLabel, $periods), $shByMonth, $shColours) ?></div>
</div>

<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700"><?= e($title) ?> deducted &middot; per employee</h3>
    <p class="note" style="margin:0">Employees with no <?= e(implode(' / ', $heads)) ?> in these months are not listed (<?= count($rows) - count($shWith) ?>).</p></div>
    <div class="actions"><span class="badge badge-gray"><?= count($shWith) ?> employee(s)</span></div></div>
<?php if ($shWith === []): ?>
  <div class="empty">No <?= e(implode(' / ', $heads)) ?> deduction matches these filters.</div>
<?php else: ?>
  <div class="table-wrap"><table class="sh-table">
    <thead><tr><th>Employee ID</th><th>Name</th><th>Designation</th><th>Branch</th>
<?php foreach ($periods as $p): foreach ($heads as $h): ?><th class="sh-n"><?= e($shLabel($p)) ?><?= count($heads) > 1 ? ' ' . e($h) : '' ?></th><?php endforeach; endforeach; ?>
<?php foreach ($heads as $h): ?><th class="sh-n">Total<?= count($heads) > 1 ? ' ' . e($h) : '' ?></th><?php endforeach; ?></tr></thead>
    <tbody>
<?php foreach ($shWith as $r): ?>
      <tr><td><b><?= e($r['code']) ?></b></td><td><?= e((string) ($r['e']['name'] ?? '')) ?></td>
        <td><?= e(orgValueOf($store, $r['e'], 'designation')) ?: '<span class="note">—</span>' ?></td><td><?= e(orgValueOf($store, $r['e'], 'branch')) ?: '<span class="note">—</span>' ?></td>
<?php foreach ($periods as $p): foreach ($heads as $h): $v = (int) ($byHead[$h][$r['code']][$p] ?? 0); ?><td class="sh-n"><?= $v === 0 ? '<span class="note">—</span>' : e(Money::ofMinor($v)->format()) ?></td><?php endforeach; endforeach; ?>
<?php foreach ($heads as $h): ?><td class="sh-n"><b><?= e(Money::ofMinor($r['cells'][$h])->format()) ?></b></td><?php endforeach; ?></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">Total &middot; <?= count($shWith) ?> employee(s)</td>
<?php foreach ($periods as $p): foreach ($heads as $h): ?><td class="sh-n"><?= e(Money::ofMinor($shMonthMinor[$h][$p])->format()) ?></td><?php endforeach; endforeach; ?>
<?php foreach ($heads as $h): ?><td class="sh-n"><?= e(Money::ofMinor($totals[$h])->format()) ?></td><?php endforeach; ?></tr></tfoot>
  </table></div>
<?php endif; ?>
</div>
<?php endif; ?>
