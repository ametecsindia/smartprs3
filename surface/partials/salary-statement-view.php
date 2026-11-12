<?php declare(strict_types=1);
/** The Monthly Salary Statement tab of Salary Breakup — see salary-statement-lib.php. Included by screens/salary-breakup.php. */
use SmartPRS\Platform\Support\Money;

include_once __DIR__ . '/salary-statement-lib.php';
$ssFilter = orgFilter();
$ssData = ssData(preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) === 1 ? (string) $_GET['month'] : '', $ssFilter);
$ssMonth = $ssData['month'];
$ssM = static fn (int $x): string => Money::ofMinor($x)->format();
$ssHref = static fn (array $over): string => '?r=salary-breakup&amp;tab=month&amp;month=' . e($ssMonth) . orgFilterQuery(array_merge($ssFilter, ['employee' => ''], $over));
$ssTot = ['gross' => 0, 'earn' => 0, 'ded' => 0, 'net' => 0, 'lost' => 0, 'er' => 0, 'lines' => []];
foreach ($ssData['rows'] as $r) {
    foreach (['gross', 'earn', 'ded', 'net', 'lost', 'er'] as $k) { $ssTot[$k] += $r[$k]; }
    foreach ($r['lines'] as $c => $v) { $ssTot['lines'][$c] = ($ssTot['lines'][$c] ?? 0) + $v; }
}
// THE INTERACTIVE DASHBOARD: each company / branch / department as a button with its head-count and net,
// counted within the filters above it (a branch button counts inside the company chosen, and so on).
$ssGroup = static function (string $k, array $within) use ($ssData, $store): array {
    $n = [];
    foreach ($ssData['all'] as $r) {
        foreach ($within as $wk => $wv) { if ($wv !== '' && strcasecmp(orgValueOf($store, $r['e'], $wk), $wv) !== 0) { continue 2; } }
        $v = orgValueOf($store, $r['e'], $k);
        if ($v === '') { continue; }
        $n[$v] ??= ['n' => 0, 'net' => 0];
        $n[$v]['n']++;
        $n[$v]['net'] += $r['net'];
    }
    ksort($n, SORT_NATURAL | SORT_FLAG_CASE);
    return $n;
};
$ssCo = $ssGroup('company', []);
$ssBr = $ssGroup('branch', ['company' => $ssFilter['company']]);
$ssDe = $ssGroup('department', ['company' => $ssFilter['company'], 'branch' => $ssFilter['branch']]);
$ssBtn = static function (string $label, string $key, array $groups, string $icon, array $clear) use ($ssFilter, $ssHref, $ssM, $store): string {
    $h = '<div class="ch-row"><b>' . $label . '</b><a class="ch all' . ($ssFilter[$key] === '' ? ' on' : '') . '" href="' . $ssHref([$key => ''] + $clear) . '"><i class="fas fa-layer-group"></i> All <span>' . array_sum(array_column($groups, 'n')) . ' emp</span></a>';
    foreach ($groups as $name => $g) {
        $h .= '<a class="ch' . (strcasecmp($ssFilter[$key], (string) $name) === 0 ? ' on' : '') . '"' . ($key === 'company' ? ' style="--c:' . companyColor($store, (string) $name) . '"' : '')
            . ' href="' . $ssHref([$key => (string) $name] + $clear) . '"><i class="fas ' . $icon . '"></i> ' . e((string) $name) . ' <span>' . $g['n'] . ' emp</span></a>';
    }
    return $h . '</div>';
};
?>
<?php if ($ssData['run'] === null): ?>
  <div class="card"><div class="empty">No payroll has been generated yet. Generate a month on <a href="?r=generate-payroll">Generate Payroll</a> and its salary statement appears here.</div></div>
<?php return; endif; ?>
  <div class="card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <form method="get" style="display:flex;gap:8px;align-items:end"><input type="hidden" name="r" value="salary-breakup"><input type="hidden" name="tab" value="month">
<?php foreach ($ssFilter as $k => $v): if ($v !== ''): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
      <div><label>Payroll month</label><select name="month" onchange="this.form.submit()"><?php foreach ($ssData['months'] as $mo): ?><option value="<?= e($mo) ?>"<?= $mo === $ssMonth ? ' selected' : '' ?>><?= e(date('F Y', (int) strtotime($mo . '-01'))) ?></option><?php endforeach; ?></select></div></form>
    <span class="note" style="margin-bottom:8px">From the generated payroll for <?= e(date('F Y', (int) strtotime($ssMonth . '-01'))) ?> &middot; <?= isset($ssData['run']['approved_by']) && $ssData['run']['approved_by'] !== null && $ssData['run']['approved_by'] !== '' ? 'approved' : 'awaiting approval' ?> &middot; the payslip figures, not a recomputation</span>
  </div>
  <div class="kpi-row">
    <div class="kpi" style="--k:#1F3A5F"><span class="k-l"><i class="fas fa-wallet"></i> Gross salary</span><span class="k-v"><?= e($ssM($ssTot['gross'])) ?></span><span class="k-h">Full-month salary before any day is cut &middot; <?= count($ssData['rows']) ?> employee(s)</span></div>
    <div class="kpi" style="--k:#15803d"><span class="k-l"><i class="fas fa-arrow-trend-up"></i> Earnings</span><span class="k-v"><?= e($ssM($ssTot['earn'])) ?></span><span class="k-h">After LOP and late-login days (<?= e($ssM($ssTot['lost'])) ?> cut), plus incentives and reimbursements</span></div>
    <div class="kpi" style="--k:#b91c1c"><span class="k-l"><i class="fas fa-arrow-trend-down"></i> Deductions</span><span class="k-v"><?= e($ssM($ssTot['ded'])) ?></span><span class="k-h">PF, ESI, Professional Tax, TDS, loans, DRA / PCC and others</span></div>
    <div class="kpi" style="--k:#c2410c"><span class="k-l"><i class="fas fa-money-bill-wave"></i> Net salary paid</span><span class="k-v"><?= e($ssM($ssTot['net'])) ?></span><span class="k-h">Earnings minus deductions — what reached employees' bank accounts</span></div>
  </div>
  <div class="card" id="sb-filter"><p class="note" style="margin:0 0 8px">Click to filter &middot; each button shows its number of employees</p>
    <?= $ssBtn('Company', 'company', $ssCo, 'fa-building', ['branch' => '', 'department' => '']) ?>
    <?= $ssBtn('Branch', 'branch', $ssBr, 'fa-location-dot', ['department' => '']) ?>
    <?= $ssBtn('Department', 'department', $ssDe, 'fa-sitemap', []) ?>
    <?= orgFilterBar('salary-breakup', $ssFilter, orgFilterOptions($store, array_map(static fn (array $r): array => $r['e'], $ssData['all'])), ['tab' => 'month', 'month' => $ssMonth], 'Show') ?>
    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)"><span class="note" style="margin-right:auto">Export downloads exactly the employees filtered above</span>
<?php foreach (['xlsx' => ['fa-file-excel', 'Excel'], 'csv' => ['fa-file-csv', 'CSV']] as $f => [$ic, $lb]): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="salary-breakup-export"><input type="hidden" name="kind" value="month"><input type="hidden" name="month" value="<?= e($ssMonth) ?>"><input type="hidden" name="format" value="<?= $f ?>">
<?php foreach ($ssFilter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <button class="btn"><i class="fas <?= $ic ?>"></i> <?= $lb ?></button></form>
<?php endforeach; ?>
    </div>
  </div>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Salary statement &middot; <?= e(date('F Y', (int) strtotime($ssMonth . '-01'))) ?></h3>
      <p class="note" style="margin:0">Earnings are already net of LOP and late-login days — the "Pay lost" column shows how much those days took off</p></div>
      <div class="actions"><span class="badge badge-gray"><?= count($ssData['rows']) ?> employee(s)</span></div></div>
<?php if ($ssData['rows'] === []): ?>
    <div class="empty">No employee in this payroll matches these filters.</div>
<?php else: ?>
    <div class="table-wrap"><table class="sb-t">
      <thead><tr><th>Employee</th><th>Company / Branch / Dept.</th><th class="sb-n">Days</th><th class="sb-n">Present</th><th class="sb-n">W.Off</th><th class="sb-n">Holidays</th><th class="sb-n">Paid leave</th><th class="sb-n">LOP</th><th class="sb-n">Absent</th><th class="sb-n">Late-login</th><th class="sb-n">Payable</th><th class="sb-n">Pay lost</th><th class="sb-n">Gross salary</th>
<?php foreach ($ssData['cols']['earning'] as $c => $l): ?><th class="sb-n g-e" title="<?= e($c) ?>"><?= e($l) ?></th><?php endforeach; ?><th class="sb-n g-e">Total earnings</th>
<?php foreach ($ssData['cols']['deduction'] as $c => $l): ?><th class="sb-n g-d" title="<?= e($c) ?>"><?= e($l) ?></th><?php endforeach; ?><th class="sb-n g-d">Total deductions</th><th class="sb-n">Net salary</th>
<?php foreach ($ssData['cols']['employer'] as $c => $l): ?><th class="sb-n g-r" title="<?= e($c) ?> — paid by the employer, not from the salary"><?= e($l) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
<?php foreach ($ssData['rows'] as $r): ?>
        <tr><td style="white-space:nowrap"><b><?= e($r['code']) ?></b> <?= e((string) ($r['e']['name'] ?? '')) ?></td>
          <td class="note" style="white-space:nowrap"><?= e(orgValueOf($store, $r['e'], 'company')) ?><br><?= e(implode(' · ', array_filter([orgValueOf($store, $r['e'], 'branch'), orgValueOf($store, $r['e'], 'department')]))) ?></td>
          <td class="sb-n"><?= (int) $r['period'] ?></td><td class="sb-n"><?= e($r['worked']) ?></td><td class="sb-n"><?= e($r['woff']) ?></td><td class="sb-n"><?= (int) $r['hol'] ?></td>
          <td class="sb-n"><?= e($r['paid_leave']) ?></td><td class="sb-n"><?= e($r['lop']) ?></td><td class="sb-n"><?= (int) $r['absent'] ?></td>
          <td class="sb-n"><?= e($r['late']) ?><?= $r['late_marks'] > 0 ? ' <span class="note">(' . $r['late_marks'] . ' late)</span>' : '' ?></td>
          <td class="sb-n tot"><?= e($r['payable']) ?></td>
          <td class="sb-n"><?= $r['lost'] > 0 ? e($ssM($r['lost'])) : '' ?></td><td class="sb-n"><?= e($ssM($r['gross'])) ?></td>
<?php foreach (array_keys($ssData['cols']['earning']) as $c): ?><td class="sb-n"><?= isset($r['lines'][$c]) ? e($ssM($r['lines'][$c])) : '' ?></td><?php endforeach; ?>
          <td class="sb-n tot"><?= e($ssM($r['earn'])) ?></td>
<?php foreach (array_keys($ssData['cols']['deduction']) as $c): ?><td class="sb-n"><?= isset($r['lines'][$c]) ? e($ssM($r['lines'][$c])) : '' ?></td><?php endforeach; ?>
          <td class="sb-n tot"><?= e($ssM($r['ded'])) ?></td><td class="sb-n tot" style="color:var(--accent)"><?= e($ssM($r['net'])) ?></td>
<?php foreach (array_keys($ssData['cols']['employer']) as $c): ?><td class="sb-n"><?= isset($r['lines'][$c]) ? e($ssM($r['lines'][$c])) : '' ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
        <tr style="font-weight:800"><td colspan="11">Total (<?= count($ssData['rows']) ?>)</td><td class="sb-n"><?= e($ssM($ssTot['lost'])) ?></td><td class="sb-n"><?= e($ssM($ssTot['gross'])) ?></td>
<?php foreach (array_keys($ssData['cols']['earning']) as $c): ?><td class="sb-n"><?= e($ssM($ssTot['lines'][$c] ?? 0)) ?></td><?php endforeach; ?><td class="sb-n"><?= e($ssM($ssTot['earn'])) ?></td>
<?php foreach (array_keys($ssData['cols']['deduction']) as $c): ?><td class="sb-n"><?= e($ssM($ssTot['lines'][$c] ?? 0)) ?></td><?php endforeach; ?><td class="sb-n"><?= e($ssM($ssTot['ded'])) ?></td><td class="sb-n"><?= e($ssM($ssTot['net'])) ?></td>
<?php foreach (array_keys($ssData['cols']['employer']) as $c): ?><td class="sb-n"><?= e($ssM($ssTot['lines'][$c] ?? 0)) ?></td><?php endforeach; ?></tr>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
