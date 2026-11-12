<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Payout Reconciliation</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// PAYOUT RECONCILIATION = what each generated payroll says is owed (net pay,
// per employee per month) against what was actually paid out (Salary Payments
// recorded against that employee and month), plus whether the bank credit can
// go at all (account + IFSC on the employee). Grouped by any org level.
// Bank / portfolio receivables keep their own register, linked below.
use SmartPRS\Surface\Employment;

$prFilter = orgFilter();
$prScoped = $scope->only($store->all('employees'), $may('payroll.view'));
$prOptions = orgFilterOptions($store, $prScoped);
$prPeriod = reportPeriod($_GET + ['p' => 'ytd'], financialYearStart($customisation), date('Y-m-d'));
$prBy = in_array((string) ($_GET['by'] ?? ''), ['company', 'branch', 'department', 'role', 'designation', 'team'], true) ? (string) $_GET['by'] : 'company';
$prState = in_array((string) ($_GET['state'] ?? ''), ['matched', 'unpaid', 'short', 'excess', 'no-bank'], true) ? (string) $_GET['state'] : '';
$prPeriodKeep = ['p' => $prPeriod['mode'], 'month' => $prPeriod['month'], 'year' => $prPeriod['year'], 'fy' => (string) ($_GET['fy'] ?? ''), 'from' => $prPeriod['from'], 'to' => $prPeriod['to']];

// Paid, per employee id + month, in paise (the stored decimal split on the point, never a float).
$prPaid = [];
try { $prPayments = $store->all('salary_payments'); } catch (\Throwable) { $prPayments = []; }
foreach ($prPayments as $pay) {
    [$w, $f] = array_pad(explode('.', (string) ($pay['amount'] ?? '0'), 2), 2, '0');
    $m = substr((string) (($pay['month'] ?? '') !== '' ? $pay['month'] : ($pay['paid_on'] ?? '')), 0, 7);
    $k = (string) ($pay['employee_id'] ?? '') . '|' . $m;
    $prPaid[$k] = ($prPaid[$k] ?? 0) + ((int) $w * 100 + ((str_starts_with($w, '-') ? -1 : 1) * (int) substr(str_pad($f, 2, '0'), 0, 2)));
}

$prRows = [];
$prSum = ['net' => 0, 'paid' => 0, 'people' => [], 'matched' => 0, 'unpaid' => 0, 'short' => 0, 'excess' => 0, 'no-bank' => 0];
$prGroups = [];
foreach (payrollFacts($store, $register, $prScoped, $prFilter, $prPeriod['from'], $prPeriod['to']) as $fct) {
    $net = (int) ($fct['row']['net_minor'] ?? 0);
    $paid = $prPaid[(string) ($fct['employment']['id'] ?? '') . '|' . $fct['period']] ?? 0;
    $bankOk = trim((string) Employment::value($fct['employment'], 'bank_acc')) !== '' && trim((string) Employment::value($fct['employment'], 'ifsc')) !== '';
    $state = $paid === 0 ? ($net > 0 ? 'unpaid' : 'matched') : ($paid === $net ? 'matched' : ($paid < $net ? 'short' : 'excess'));
    $row = $fct + ['net' => $net, 'paid' => $paid, 'variance' => $paid - $net, 'state' => $state, 'bank' => $bankOk];
    $prRows[] = $row;

    $prSum['net'] += $net; $prSum['paid'] += $paid; $prSum['people'][$fct['code']] = true; $prSum[$state]++;
    $bankOk || $prSum['no-bank']++;
    $g = $fct['org'][$prBy] !== '' ? $fct['org'][$prBy] : '(not set)';
    $prGroups[$g] ??= ['net' => 0, 'paid' => 0, 'people' => [], 'open' => 0, 'no-bank' => 0];
    $prGroups[$g]['net'] += $net; $prGroups[$g]['paid'] += $paid; $prGroups[$g]['people'][$fct['code']] = true;
    $state !== 'matched' && $prGroups[$g]['open']++;
    $bankOk || $prGroups[$g]['no-bank']++;
}
uasort($prGroups, static fn (array $a, array $b): int => $b['net'] <=> $a['net']);
$prShown = array_values(array_filter($prRows, static fn (array $r): bool => $prState === '' || ($prState === 'no-bank' ? !$r['bank'] : $r['state'] === $prState)));
usort($prShown, static fn (array $a, array $b): int => [$b['period'], $a['code']] <=> [$a['period'], $b['code']]);
$prKeep = $prPeriodKeep + orgFilterKeep($prFilter);
$prBadge = ['matched' => ['badge-green', 'Matched'], 'unpaid' => ['badge-amber', 'Not paid yet'], 'short' => ['badge-red', 'Short paid'], 'excess' => ['badge-blue', 'Over paid']];
$prLink = static function (array $extra) use ($prKeep, $prBy): string {
    $q = '?r=payout-reconciliation&amp;by=' . e($prBy);
    foreach ($prKeep + $extra as $k => $v) { $q .= '&amp;' . e((string) $k) . '=' . e(rawurlencode((string) $v)); }
    return $q;
};
?>
<div class="page-header">
  <div><h2>Payout Reconciliation</h2>
    <p>Net pay owed by each generated payroll against what was actually paid &mdash; <?= e($prPeriod['label']) ?></p></div>
  <div class="actions" style="display:flex;gap:8px"><a class="btn btn-tint navy" href="?r=m&amp;k=payout-recon"><i class="fas fa-building-columns"></i> Bank / portfolio receivables</a></div>
</div>

<div class="card">
  <?= orgFilterBar('payout-reconciliation', $prFilter, $prOptions, ['by' => $prBy, 'state' => $prState], 'Show', null, $prPeriod) ?>
</div>

<div class="kpi-strip">
  <div class="kpi blue"><h3><?= e(payPaise($prSum['net'])) ?></h3><p>net payable</p><div class="sub"><?= count($prSum['people']) ?> employee(s), <?= count($prRows) ?> payslip(s)</div></div>
  <div class="kpi green"><h3><?= e(payPaise($prSum['paid'])) ?></h3><p>paid out</p><div class="sub">recorded salary payments</div></div>
  <div class="kpi <?= $prSum['net'] - $prSum['paid'] === 0 ? 'green' : 'red' ?>"><h3><?= e(payPaise($prSum['net'] - $prSum['paid'])) ?></h3><p>still to pay</p><div class="sub">net payable minus paid</div></div>
  <a class="kpi orange" href="<?= $prLink(['state' => 'unpaid']) ?>" style="text-decoration:none"><h3><?= $prSum['unpaid'] + $prSum['short'] ?></h3><p>open payslips</p><div class="sub"><?= $prSum['unpaid'] ?> unpaid &middot; <?= $prSum['short'] ?> short &middot; <?= $prSum['excess'] ?> over</div></a>
  <a class="kpi purple" href="<?= $prLink(['state' => 'no-bank']) ?>" style="text-decoration:none"><h3><?= $prSum['no-bank'] ?></h3><p>no bank details</p><div class="sub">cannot be credited</div></a>
</div>

<?php if ($prRows === []): ?>
<div class="card"><div class="empty"><i class="fas fa-scale-balanced"></i>No generated payroll in this range. Payslips appear here once a month in the range is generated (Payroll &rarr; Generate Payroll).</div></div>
<?php return; endif; ?>

<div class="card" style="padding:0">
  <div class="card-header" style="flex-wrap:wrap;gap:10px"><div><h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0"><i class="fas fa-sitemap"></i> Consolidated by <?= e(ucfirst($prBy)) ?></h3></div>
    <?= groupByTabs('payout-reconciliation', $prBy, $prKeep + ['state' => $prState]) ?></div>
  <div class="table-wrap"><table>
    <thead><tr style="background:#1e3a5f"><th style="color:#fff"><?= e(ucfirst($prBy)) ?></th><th class="n" style="color:#fff">Employees</th><th class="n" style="color:#fff">Net payable</th><th class="n" style="color:#fff">Paid</th><th class="n" style="color:#fff">Still to pay</th><th class="n" style="color:#fff">Open payslips</th><th class="n" style="color:#fff">No bank details</th><th style="color:#fff;width:16%">Paid %</th></tr></thead><tbody>
<?php $i = 0; foreach ($prGroups as $g => $d): $pct = $d['net'] > 0 ? min(100, round($d['paid'] * 100 / $d['net'], 1)) : 100; ?>
    <tr style="<?= $i++ % 2 ? 'background:#f8fafc' : '' ?>"><td><b><?= e((string) $g) ?></b></td><td class="n"><?= count($d['people']) ?></td>
      <td class="n" style="font-family:var(--mono)"><?= e(payPaise($d['net'])) ?></td><td class="n" style="font-family:var(--mono);color:#15803d"><?= e(payPaise($d['paid'])) ?></td>
      <td class="n" style="font-family:var(--mono);font-weight:700;color:<?= $d['net'] - $d['paid'] > 0 ? '#b91c1c' : '#15803d' ?>"><?= e(payPaise($d['net'] - $d['paid'])) ?></td>
      <td class="n"><?= $d['open'] ?></td><td class="n"><?= $d['no-bank'] > 0 ? '<span class="badge badge-red">' . $d['no-bank'] . '</span>' : '0' ?></td>
      <td><div style="height:8px;background:#f1f5f9;border-radius:4px"><div style="height:8px;width:<?= $pct ?>%;background:<?= $pct >= 100 ? '#16a34a' : '#f97316' ?>;border-radius:4px"></div></div><span class="note" style="font-size:11px"><?= $pct ?>%</span></td></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total</th><th class="n"><?= count($prSum['people']) ?></th><th class="n" style="font-family:var(--mono)"><?= e(payPaise($prSum['net'])) ?></th><th class="n" style="font-family:var(--mono)"><?= e(payPaise($prSum['paid'])) ?></th><th class="n" style="font-family:var(--mono)"><?= e(payPaise($prSum['net'] - $prSum['paid'])) ?></th><th class="n"><?= $prSum['unpaid'] + $prSum['short'] + $prSum['excess'] ?></th><th class="n"><?= $prSum['no-bank'] ?></th><th></th></tr></tfoot>
  </table></div>
</div>

<div class="card" style="padding:0">
  <div class="card-header" style="flex-wrap:wrap;gap:10px"><div><h3 style="font-size:15px;font-weight:700;color:#1e3a5f;margin:0"><i class="fas fa-list"></i> Every payslip</h3>
    <p class="note" style="margin:0"><?= count($prShown) ?> of <?= count($prRows) ?> shown</p></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
<?php foreach (['' => 'All', 'unpaid' => 'Not paid yet', 'short' => 'Short paid', 'excess' => 'Over paid', 'matched' => 'Matched', 'no-bank' => 'No bank details'] as $sk => $sl): ?>
      <a class="btn btn-tint <?= $sk === $prState ? 'navy-solid' : 'navy' ?>" href="<?= $prLink(['state' => $sk]) ?>"><?= e($sl) ?></a>
<?php endforeach; ?>
    </div></div>
<?php $prCanPay = $may('payroll.run'); ?>
<?php if ($prCanPay): ?>
  <form method="post" id="pr-pay">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="payout-mark-paid">
    <input type="hidden" name="back" value="<?= e(html_entity_decode(substr($prLink(['state' => $prState]), 1))) ?>">
    <div class="row" style="align-items:flex-end;margin:0;padding:12px 16px;background:#fff7ed;border-bottom:1px solid #fed7aa">
      <div style="flex:0 0 auto"><label for="pr-on">Paid on</label><input type="date" id="pr-on" name="paid_on" value="<?= e(date('Y-m-d')) ?>" required></div>
      <div style="flex:0 0 auto"><label for="pr-mode">Mode</label><select id="pr-mode" name="mode"><option value="bank">Bank transfer</option><option value="upi">UPI</option><option value="cheque">Cheque</option><option value="cash">Cash</option></select></div>
      <div><label for="pr-ref">Reference (UTR / batch no.)</label><input id="pr-ref" name="reference" placeholder="e.g. HDFC batch 0921"></div>
      <div style="flex:0 0 auto"><button type="submit" style="background:#16a34a" onclick="if(!document.querySelector('#pr-pay input[name=\'items[]\']:checked')){alert('Tick at least one payslip below.');return false;}"><i class="fas fa-check"></i> Mark ticked as paid</button></div>
      <p class="note" style="flex-basis:100%;margin:4px 0 0">Records what is still due on each ticked payslip (net payable less anything already paid) &mdash; the amount is worked out from the payroll, not typed.</p>
    </div>
<?php endif; ?>
  <div class="table-wrap"><table>
    <thead><tr style="background:#1e3a5f"><?php if ($prCanPay): ?><th style="width:28px"><input type="checkbox" title="Tick every open payslip shown" onclick="document.querySelectorAll('#pr-pay input[name=\'items[]\']').forEach(function(c){c.checked=this.checked;}.bind(this))"></th><?php endif; ?><th style="color:#fff">Month</th><th style="color:#fff">Employee</th><th style="color:#fff">Company</th><th style="color:#fff">Branch</th><th style="color:#fff">Department</th><th style="color:#fff">Role</th><th class="n" style="color:#fff">Net payable</th><th class="n" style="color:#fff">Paid</th><th class="n" style="color:#fff">Variance</th><th style="color:#fff">Status</th><th style="color:#fff">Bank</th></tr></thead><tbody>
<?php foreach (array_slice($prShown, 0, 1000) as $i => $r): ?>
    <tr style="<?= $i % 2 ? 'background:#f8fafc' : '' ?>"><?php if ($prCanPay): ?><td><?php if (in_array($r['state'], ['unpaid', 'short'], true)): ?><input type="checkbox" name="items[]" value="<?= e((string) $r['employment']['id'] . '|' . $r['period']) ?>"><?php endif; ?></td><?php endif; ?><td><a href="?r=generate-payroll&amp;period=<?= e($r['period']) ?>#run-<?= e($r['period']) ?>" title="Open this payroll<?= $r['approved'] ? ' (approved)' : ' (awaiting approval)' ?>"><?= e(date('M Y', strtotime($r['period'] . '-01') ?: time())) ?></a></td>
      <td><code><?= e($r['code']) ?></code> <?= e($r['name']) ?></td>
      <td><?= e($r['org']['company']) ?></td><td><?= e($r['org']['branch']) ?></td><td><?= e($r['org']['department']) ?></td><td><?= e($r['org']['role']) ?></td>
      <td class="n" style="font-family:var(--mono)"><?= e(payPaise($r['net'])) ?></td>
      <td class="n" style="font-family:var(--mono);color:#15803d"><?= $r['paid'] === 0 ? '<span class="note">—</span>' : e(payPaise($r['paid'])) ?></td>
      <td class="n" style="font-family:var(--mono);font-weight:600;color:<?= $r['variance'] === 0 ? '#15803d' : '#b91c1c' ?>"><?= e(payPaise($r['variance'])) ?></td>
      <td><span class="badge <?= $prBadge[$r['state']][0] ?>"><?= $prBadge[$r['state']][1] ?></span></td>
      <td><?= $r['bank'] ? '<i class="fas fa-circle-check" style="color:#16a34a" title="Account and IFSC on file"></i>' : '<i class="fas fa-circle-exclamation" style="color:#dc2626" title="No account / IFSC — cannot be credited"></i>' ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php if ($prCanPay): ?></form><?php endif; ?>
<?php if (count($prShown) > 1000): ?><p class="note" style="padding:10px 16px;margin:0">First 1,000 of <?= count($prShown) ?> shown &mdash; narrow the date range or filters to see the rest.</p><?php endif; ?>
</div>
