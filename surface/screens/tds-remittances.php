<?php declare(strict_types=1);
/**
 * TDS REMITTANCES (owner, 14 Oct) — the TDS sibling of PF & ESI Remittances. TO BE PAID is the TDS
 * the COMPLETED payroll deducted (closed, not-discarded runs; config/income-tax.json `salary_codes.tds`);
 * DEPOSITED is what the uploaded challan lines say (a line counts once its challan date is filled).
 * One row per wage month × company; the per-employee lines stay hidden here and feed Form 138 (old 24Q).
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/tds-lib.php';

if (!$may('payroll.view')) { ?>
<div class="page-header"><div><h2>TDS Remittances</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; }

$trPeriod = isset($_GET['p']) ? reportPeriod($_GET, financialYearStart($customisation), date('Y-m-d')) : null;
$trFilter = orgFilter();
$trScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$trByKey = [];
foreach ($trScoped as $e) { $trByKey[(string) $e['id']] = $e; ($c = Employment::code($e)) === '' || $trByKey[$c] = $e; }
$trIn = static fn (string $m): bool => $trPeriod === null || ($m >= substr($trPeriod['from'], 0, 7) && $m <= substr($trPeriod['to'], 0, 7));
$trPass = static function (array $e) use ($store, $trFilter): bool {
    foreach ($trFilter as $k => $v) { if ($v !== '' && strcasecmp(orgValueOf($store, $e, $k), $v) !== 0) { return false; } }
    return true;
};

try { $trAll = array_values(array_filter($store->all('tds_remittances'), static fn (array $r): bool => !isRetired($r))); $trWhy = null; }
catch (\Throwable $x) { $trAll = []; $trWhy = $x->getMessage(); }

$trCompanies = [];
foreach (liveRows($store, 'companies') as $c) { $trCompanies[trim((string) ($c['name'] ?? ''))] = $c; }
$trGroups = [];
$trNew = static fn (string $m, string $co): array => ['month' => $m, 'company' => $co, 'emps' => [], 'paid' => 0, 'pay' => null, 'ded' => 0, 'dep' => 0, 'undep' => 0, 'dates' => [], 'challans' => []];
$trCounts = [];
$trT = ['payroll' => 0, 'dep' => 0];

// THE UPLOADED CHALLAN LINES.
foreach ($trAll as $r) {
    $e = $trByKey[(string) $r['employee_id']] ?? null;
    $m = (string) ($r['month'] ?? '');
    if ($e === null || $m === '' || !$trIn($m)) { continue; }
    $trCounts[(string) $e['id']] = ($trCounts[(string) $e['id']] ?? 0) + 1;
    if (!$trPass($e)) { continue; }
    $co = orgValueOf($store, $e, 'company') ?: 'No company';
    $k = $m . '|' . $co;
    $g = $trGroups[$k] ?? $trNew($m, $co);
    $g['emps'][(string) $e['id']] = true;
    $g['paid'] += IncomeTax::p($r['amount_paid'] ?? '');
    $g['ded'] += IncomeTax::p($r['tds_deducted'] ?? '');
    $deposited = trim((string) ($r['challan_date'] ?? '')) !== '';
    $amt = IncomeTax::p($r['tds_deposited'] ?? '') ?: IncomeTax::p($r['tds_deducted'] ?? '');
    $deposited ? $g['dep'] += $amt : $g['undep'] += $amt;
    $deposited && $trT['dep'] += $amt;
    $deposited && $g['dates'][substr((string) $r['challan_date'], 0, 10)] = true;
    $ch = trim((string) ($r['bsr_code'] ?? '') . ' / ' . (string) ($r['challan_serial'] ?? ''), ' /');
    $ch === '' || $g['challans'][$ch] = true;
    $trGroups[$k] = $g;
}

// TO BE PAID — the completed payroll's TDS lines, same months and filters.
$trRuns = 0;
foreach ($taxDeclarations->runRows() as $per => $rows) {
    if (!$trIn((string) $per)) { continue; }
    $trRuns++;
    $codes = (array) (($taxDeclarations->year(IncomeTax::fyOf($per . '-01'))['salary_codes'] ?? [])['tds'] ?? ['TDS']);
    foreach ((array) $rows as $key => $row) {
        $e = $trByKey[(string) $key] ?? null;
        if ($e === null || !$trPass($e)) { continue; }
        $t = 0;
        foreach ((array) ($row['lines'] ?? []) as $l) { if (($l['kind'] ?? '') !== 'earning' && in_array((string) preg_replace('/^[A-Z]-/', '', (string) ($l['code'] ?? '')), $codes, true)) { $t += (int) ($l['minor'] ?? 0); } }
        if ($t === 0) { continue; }
        $co = orgValueOf($store, $e, 'company') ?: 'No company';
        $k = $per . '|' . $co;
        $g = $trGroups[$k] ?? $trNew((string) $per, $co);
        $g['pay'] = ($g['pay'] ?? 0) + $t;
        $g['emps'][(string) $e['id']] = true;
        $trGroups[$k] = $g;
        $trT['payroll'] += $t;
    }
}

$trBal = 0;
foreach ($trGroups as $k => $g) {
    $trGroups[$k]['balance'] = $g['pay'] !== null ? $g['pay'] - $g['dep'] : $g['undep'];
    $trBal += $trGroups[$k]['balance'];
}
uasort($trGroups, static fn (array $a, array $b): int => [$b['month'], $a['company']] <=> [$a['month'], $b['company']]);
$trFyOf = static function (string $m) use ($customisation): string { return 'FY ' . IncomeTax::fyOf($m . '-01', financialYearStart($customisation)); };
$trKeepQs = '';
foreach (array_keys(PERIOD_KEYS) as $pk) { isset($_GET[$pk]) && $trKeepQs .= '&amp;' . $pk . '=' . e(rawurlencode((string) $_GET[$pk])); }
$trMay = $may('payroll.run') || isAdministrator();
echo tdsCss();
?>
  <div class="page-header">
    <div><h2>TDS Remittances</h2><p>TDS on salary deducted (completed payroll) and deposited (uploaded challans) &middot; a line counts as deposited once its challan date is filled &middot; due by the 7th of the next month</p></div>
<?php if ($trMay): ?>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <form method="post" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-template"><input type="hidden" name="k" value="tds-remittances">
        <input type="month" name="month" value="<?= e(date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))) ?>" style="width:175px">
        <button class="btn btn-outline" type="submit"><i class="fas fa-file-excel"></i> Template – from payroll</button></form>
      <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-upload"><input type="hidden" name="k" value="tds-remittances">
        <input type="file" name="sheet" accept=".xlsx,.xls,.csv" required style="max-width:210px">
        <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload</button></form>
    </div>
<?php endif; ?>
  </div>
<?php if ($trWhy !== null): ?><div class="card"><div class="problem" style="margin:0"><?= e($trWhy) ?></div></div><?php return; endif; ?>
  <div class="tds-foot" style="margin:0 0 16px">
    <div><span class="note">TDS deducted from employees (payroll)</span><b><?= tdsMoney($trT['payroll']) ?></b><span class="note"><?= $trRuns ?> completed payroll month(s)</span></div>
    <div><span class="note">TDS deposited (challans)</span><b><?= tdsMoney($trT['dep']) ?></b></div>
    <div><span class="note">Balance to be deposited</span><b style="color:<?= $trBal > 0 ? '#b91c1c' : '#047857' ?>"><?= $trBal === 0 ? 'Nil' : ($trBal < 0 ? 'Excess ' . tdsMoney(-$trBal) : tdsMoney($trBal)) ?></b></div>
  </div>
  <div class="card"><?= tdsOrgButtons('tds-remittances', $trFilter, $trScoped, $trCounts, $trKeepQs, '', $trPeriod ?? allPeriod()) ?>
    <p class="note" style="margin:-4px 0 0">Button counts = uploaded challan lines in the period.</p></div>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Remittances by month</h3><p class="note" style="margin:0">One row per wage month and company · totals of the employee lines</p></div><div class="actions"><span class="badge badge-gray"><?= count($trGroups) ?> row(s)</span></div></div>
<?php if ($trGroups === []): ?>
    <div class="empty"><?= $trAll === [] && $trRuns === 0 ? 'Nothing yet. Once a payroll month is completed, download the template — salary and TDS are filled in from it — add the challan details and upload.' : 'Nothing matches these filters.' ?></div>
<?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>FY</th><th>Month</th><th>Company / TAN</th><th class="tds-n">Employees</th><th class="tds-n">Salary paid</th><th class="tds-n">To be paid (payroll)</th><th class="tds-n">TDS deposited</th><th class="tds-n">Balance to be deposited</th><th>Deposit date</th><th>BSR / challan no.</th></tr></thead>
      <tbody>
<?php foreach ($trGroups as $g): $tan = (string) ($trCompanies[$g['company']]['tan'] ?? ''); $b = (int) $g['balance']; ?>
        <tr><td style="white-space:nowrap"><?= e($trFyOf($g['month'])) ?></td><td style="white-space:nowrap"><?= e(date('M Y', (int) strtotime($g['month'] . '-01'))) ?></td>
          <td><?= e($g['company']) ?><br><span class="note"><?= $tan === '' ? 'TAN not set' : 'TAN ' . e($tan) ?></span></td>
          <td class="tds-n"><?= count($g['emps']) ?></td><td class="tds-n"><?= $g['paid'] > 0 ? tdsMoney($g['paid']) : '—' ?></td>
          <td class="tds-n"><?= $g['pay'] === null ? '<span class="note">not run</span>' : '<b>' . tdsMoney((int) $g['pay']) . '</b>' ?></td>
          <td class="tds-n"><?= tdsMoney((int) $g['dep']) ?></td>
          <td class="tds-n" style="font-weight:600;color:<?= $b === 0 ? '#047857' : ($b < 0 ? '#b45309' : '#b91c1c') ?>"><?= $b === 0 ? 'Nil' : ($b < 0 ? 'Excess ' . tdsMoney(-$b) : tdsMoney($b)) ?></td>
          <td style="white-space:nowrap"><?= $g['dates'] === [] ? '<span class="note">—</span>' : e(implode(', ', array_map(static fn (string $d): string => SmartPRS\Surface\Dates::dmy($d), array_keys($g['dates'])))) ?></td>
          <td><?= $g['challans'] === [] ? '<span class="note">—</span>' : e(implode(', ', array_keys($g['challans']))) ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
