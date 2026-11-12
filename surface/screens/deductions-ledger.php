<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Deductions Ledger</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// DEDUCTIONS LEDGER = every deduction line on every generated payroll in the
// period (salary-structure deductions, statutory PF/ESI/PT/TDS, loan and
// advance instalments), read from the closed runs themselves — so it is what
// was actually deducted, grouped any way the org is cut. Manual adjustments
// (late, PCC, escalation…) still live in their own register, linked below.
use SmartPRS\Surface\Employment;

$dlFilter = orgFilter();
$dlScoped = $scope->only($store->all('employees'), $may('payroll.view'));
$dlOptions = orgFilterOptions($store, $dlScoped);
$dlPeriod = reportPeriod($_GET + ['p' => 'ytd'], financialYearStart($customisation), date('Y-m-d'));
$dlBy = in_array((string) ($_GET['by'] ?? ''), ['company', 'branch', 'department', 'role', 'designation', 'team'], true) ? (string) $_GET['by'] : 'company';
$dlComp = (string) ($_GET['component'] ?? '');
$dlPeriodKeep = ['p' => $dlPeriod['mode'], 'month' => $dlPeriod['month'], 'year' => $dlPeriod['year'], 'fy' => (string) ($_GET['fy'] ?? ''), 'from' => $dlPeriod['from'], 'to' => $dlPeriod['to']];

$dlLines = [];
foreach (payrollFacts($store, $register, $dlScoped, $dlFilter, $dlPeriod['from'], $dlPeriod['to']) as $f) {
    foreach ((array) ($f['row']['lines'] ?? []) as $l) {
        if ((string) ($l['kind'] ?? '') !== 'deduction' || (int) ($l['minor'] ?? 0) === 0) { continue; }
        $dlLines[] = $f + ['component' => (string) $l['code'], 'minor' => (int) $l['minor']];
    }
}

$dlTotal = 0; $dlByComp = []; $dlByGroup = []; $dlPeople = []; $dlMonths = [];
foreach ($dlLines as $l) {
    $dlTotal += $l['minor'];
    $dlByComp[$l['component']] = ($dlByComp[$l['component']] ?? 0) + $l['minor'];
    $g = $l['org'][$dlBy] !== '' ? $l['org'][$dlBy] : '(not set)';
    $dlByGroup[$g]['total'] = ($dlByGroup[$g]['total'] ?? 0) + $l['minor'];
    $dlByGroup[$g]['comp'][$l['component']] = ($dlByGroup[$g]['comp'][$l['component']] ?? 0) + $l['minor'];
    $dlByGroup[$g]['people'][$l['code']] = true;
    $dlPeople[$l['code']] = true;
    $dlMonths[$l['period']] = true;
}
arsort($dlByComp);
uasort($dlByGroup, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
$dlTopComps = array_slice(array_keys($dlByComp), 0, 5);
$dlShown = array_values(array_filter($dlLines, static fn (array $l): bool => $dlComp === '' || $l['component'] === $dlComp));
usort($dlShown, static fn (array $a, array $b): int => [$b['period'], $a['code']] <=> [$a['period'], $b['code']]);
$dlColors = ['#1e3a5f', '#f97316', '#16a34a', '#7c3aed', '#0891b2', '#dc2626'];
$dlKeep = $dlPeriodKeep + orgFilterKeep($dlFilter);
?>
<div class="page-header">
  <div><h2>Deductions Ledger</h2>
    <p>Every deduction on every generated payroll &mdash; <?= e($dlPeriod['label']) ?> &middot; by company, branch, department, role and more</p></div>
  <div class="actions" style="display:flex;gap:8px"><a class="btn btn-tint navy" href="?r=m&amp;k=deductions"><i class="fas fa-pen-to-square"></i> Manual deductions (late, PCC, escalation…)</a></div>
</div>

<div class="card">
  <?= orgFilterBar('deductions-ledger', $dlFilter, $dlOptions, ['by' => $dlBy, 'component' => $dlComp], 'Show', null, $dlPeriod) ?>
</div>

<div class="kpi-strip" style="grid-template-columns:repeat(4,1fr)">
  <div class="kpi red"><h3><?= e(payPaise($dlTotal)) ?></h3><p>total deducted</p><div class="sub"><?= count($dlLines) ?> line(s)</div></div>
  <div class="kpi blue"><h3><?= count($dlPeople) ?></h3><p>employees</p><div class="sub">with at least one deduction</div></div>
  <div class="kpi orange"><h3><?= count($dlMonths) ?></h3><p>payroll month(s)</p><div class="sub"><?= e($dlPeriod['from']) ?> to <?= e($dlPeriod['to']) ?></div></div>
  <div class="kpi purple"><h3><?= e(payPaise(count($dlPeople) > 0 ? intdiv($dlTotal, max(1, count($dlPeople))) : 0)) ?></h3><p>per employee</p><div class="sub">average over the range</div></div>
</div>

<?php if ($dlLines === []): ?>
<div class="card"><div class="empty"><i class="fas fa-money-bill-transfer"></i>No deductions in this range. Deductions appear here once a payroll month in the range is generated (Payroll &rarr; Generate Payroll).</div></div>
<?php return; endif; ?>

<div style="display:grid;grid-template-columns:minmax(260px,1fr) 2fr;gap:16px;align-items:start">
  <div class="card">
    <h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0 0 12px"><i class="fas fa-layer-group"></i> By deduction type</h3>
<?php $i = 0; foreach ($dlByComp as $c => $m): $pct = $dlTotal > 0 ? round($m * 100 / $dlTotal, 1) : 0; ?>
    <a href="?r=deductions-ledger&amp;component=<?= e(rawurlencode($c === $dlComp ? '' : $c)) ?>&amp;by=<?= e($dlBy) ?><?php foreach ($dlKeep as $k => $v) { echo '&amp;' . e($k) . '=' . e(rawurlencode((string) $v)); } ?>" style="display:block;text-decoration:none;color:inherit;margin-bottom:10px;<?= $c === $dlComp ? 'background:#fff7ed;border-radius:8px;padding:4px 6px' : '' ?>">
      <div style="display:flex;justify-content:space-between;font-size:13px"><b><?= e($c) ?></b><span style="font-family:var(--mono)"><?= e(payPaise($m)) ?></span></div>
      <div style="height:7px;background:#f1f5f9;border-radius:4px;margin-top:4px"><div style="height:7px;width:<?= $pct ?>%;background:<?= $dlColors[$i++ % count($dlColors)] ?>;border-radius:4px"></div></div>
      <div class="note" style="font-size:11px"><?= $pct ?>% of all deductions</div></a>
<?php endforeach; ?>
    <?= $dlComp !== '' ? '<p class="note" style="margin:0">Showing <b>' . e($dlComp) . '</b> only in the table below &mdash; click it again to show all.</p>' : '<p class="note" style="margin:0">Click a type to show only it in the table below.</p>' ?>
  </div>

  <div class="card" style="padding:0">
    <div class="card-header" style="flex-wrap:wrap;gap:10px"><div><h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0"><i class="fas fa-sitemap"></i> Consolidated by <?= e(ucfirst($dlBy)) ?></h3></div>
      <?= groupByTabs('deductions-ledger', $dlBy, $dlKeep + ['component' => $dlComp]) ?></div>
    <div class="table-wrap"><table>
      <thead><tr style="background:#1e3a5f"><th style="color:#fff"><?= e(ucfirst($dlBy)) ?></th><th class="n" style="color:#fff">Employees</th>
<?php foreach ($dlTopComps as $c): ?><th class="n" style="color:#fff"><?= e($c) ?></th><?php endforeach; ?>
        <th class="n" style="color:#fff">Total</th><th style="color:#fff;width:18%">Share</th></tr></thead><tbody>
<?php $i = 0; foreach ($dlByGroup as $g => $d): $pct = $dlTotal > 0 ? round($d['total'] * 100 / $dlTotal, 1) : 0; ?>
      <tr style="<?= $i++ % 2 ? 'background:#f8fafc' : '' ?>"><td><b><?= e((string) $g) ?></b></td><td class="n"><?= count($d['people']) ?></td>
<?php foreach ($dlTopComps as $c): ?><td class="n" style="font-family:var(--mono)"><?= isset($d['comp'][$c]) ? e(payPaise($d['comp'][$c])) : '<span class="note">—</span>' ?></td><?php endforeach; ?>
        <td class="n" style="font-family:var(--mono);font-weight:700"><?= e(payPaise($d['total'])) ?></td>
        <td><div style="height:8px;background:#f1f5f9;border-radius:4px"><div style="height:8px;width:<?= $pct ?>%;background:#f97316;border-radius:4px"></div></div><span class="note" style="font-size:11px"><?= $pct ?>%</span></td></tr>
<?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th class="n"><?= count($dlPeople) ?></th>
<?php foreach ($dlTopComps as $c): ?><th class="n" style="font-family:var(--mono)"><?= e(payPaise($dlByComp[$c])) ?></th><?php endforeach; ?>
        <th class="n" style="font-family:var(--mono)"><?= e(payPaise($dlTotal)) ?></th><th></th></tr></tfoot>
    </table></div>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0"><i class="fas fa-list"></i> Every deduction line<?= $dlComp !== '' ? ' &middot; ' . e($dlComp) : '' ?></h3>
    <p class="note" style="margin:0"><?= count($dlShown) ?> line(s) &middot; <?= e(payPaise(array_sum(array_column($dlShown, 'minor')))) ?></p></div></div>
  <div class="table-wrap"><table>
    <thead><tr style="background:#1e3a5f"><th style="color:#fff">Month</th><th style="color:#fff">Employee</th><th style="color:#fff">Company</th><th style="color:#fff">Branch</th><th style="color:#fff">Department</th><th style="color:#fff">Role</th><th style="color:#fff">Deduction</th><th class="n" style="color:#fff">Amount</th><th style="color:#fff">Payroll</th></tr></thead><tbody>
<?php foreach (array_slice($dlShown, 0, 1000) as $i => $l): ?>
    <tr style="<?= $i % 2 ? 'background:#f8fafc' : '' ?>"><td><?= e(date('M Y', strtotime($l['period'] . '-01') ?: time())) ?></td>
      <td><code><?= e($l['code']) ?></code> <?= e($l['name']) ?></td>
      <td><?= e($l['org']['company']) ?></td><td><?= e($l['org']['branch']) ?></td><td><?= e($l['org']['department']) ?></td><td><?= e($l['org']['role']) ?></td>
      <td><span class="badge badge-red"><?= e($l['component']) ?></span></td>
      <td class="n" style="font-family:var(--mono);font-weight:600"><?= e(payPaise($l['minor'])) ?></td>
      <td><a href="?r=generate-payroll&amp;period=<?= e($l['period']) ?>#run-<?= e($l['period']) ?>" title="Open this payroll"><span class="badge <?= $l['approved'] ? 'badge-green' : 'badge-amber' ?>"><?= $l['approved'] ? 'approved' : 'awaiting approval' ?></span></a></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php if (count($dlShown) > 1000): ?><p class="note" style="padding:10px 16px;margin:0">First 1,000 of <?= count($dlShown) ?> lines shown &mdash; narrow the date range or filters to see the rest.</p><?php endif; ?>
</div>
