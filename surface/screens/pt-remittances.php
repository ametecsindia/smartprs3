<?php declare(strict_types=1);
/**
 * PROFESSIONAL TAX (owner, 15 Oct: "build this also similar to PF ESIC remittance format and structure").
 * TO BE PAID is the PT the COMPLETED payroll deducted (closed, not-discarded runs; the PT head's line codes
 * in config/compliance-calendar.json); PAID is what the uploaded challan lines say (a line counts once its
 * paid-on date is filled). PT is a state levy, so one row per wage month × company × state — the state
 * is the employee's branch state (the company's state when the branch has none).
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\StatutoryReports;

include __DIR__ . '/../partials/tds-lib.php';

if (!$may('payroll.view')) { ?>
<div class="page-header"><div><h2>Professional Tax</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; }

$prPeriod = isset($_GET['p']) ? reportPeriod($_GET, financialYearStart($customisation), date('Y-m-d')) : null;
$prFilter = orgFilter();
$prScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$prByKey = [];
foreach ($prScoped as $e) { $prByKey[(string) $e['id']] = $e; ($c = Employment::code($e)) === '' || $prByKey[$c] = $e; }
$prIn = static fn (string $m): bool => $prPeriod === null || ($m >= substr($prPeriod['from'], 0, 7) && $m <= substr($prPeriod['to'], 0, 7));
$prPass = static function (array $e) use ($store, $prFilter): bool {
    foreach ($prFilter as $k => $v) { if ($v !== '' && strcasecmp(orgValueOf($store, $e, $k), $v) !== 0) { return false; } }
    return true;
};
$prType = in_array((string) ($_GET['state'] ?? ''), ['', 'all'], true) ? '' : (string) $_GET['state'];

// THE EMPLOYEE'S PT STATE: their branch's state, else their company's.
$prCompanies = [];
foreach (liveRows($store, 'companies') as $c) { $prCompanies[trim((string) ($c['name'] ?? ''))] = $c; }
$prBranchState = [];
foreach (liveRows($store, 'branches') as $b) { $prBranchState[(string) $b['id']] = trim((string) ($b['state'] ?? '')); $prBranchState['n:' . strtolower(trim((string) ($b['name'] ?? '')))] = trim((string) ($b['state'] ?? '')); }
$prStateOf = static function (array $e) use ($store, $prBranchState, $prCompanies): string {
    $s = $prBranchState[(string) ($e['branch_id'] ?? '')] ?? $prBranchState['n:' . strtolower(orgValueOf($store, $e, 'branch'))] ?? '';
    return $s !== '' ? $s : (trim((string) ($prCompanies[orgValueOf($store, $e, 'company')]['state'] ?? '')) ?: 'State not set');
};

try { $prAll = array_values(array_filter($store->all('pt_remittances'), static fn (array $r): bool => !isRetired($r))); $prWhy = null; }
catch (\Throwable $x) { $prAll = []; $prWhy = $x->getMessage(); }

$prGroups = [];
$prNew = static fn (string $m, string $co, string $st): array => ['month' => $m, 'company' => $co, 'state' => $st, 'emps' => [], 'wages' => 0, 'pay' => null, 'paid' => 0, 'unpaid' => 0, 'dates' => [], 'challans' => []];
$prCounts = [];
$prT = ['payroll' => 0, 'paid' => 0];
$prStates = [];

// THE UPLOADED CHALLAN LINES.
foreach ($prAll as $r) {
    $e = $prByKey[(string) $r['employee_id']] ?? null;
    $m = (string) ($r['month'] ?? '');
    if ($e === null || $m === '' || !$prIn($m)) { continue; }
    $prCounts[(string) $e['id']] = ($prCounts[(string) $e['id']] ?? 0) + 1;
    $st = trim((string) ($r['state'] ?? '')) ?: $prStateOf($e);
    $prStates[$st] = true;
    if (!$prPass($e) || ($prType !== '' && strcasecmp($st, $prType) !== 0)) { continue; }
    $co = orgValueOf($store, $e, 'company') ?: 'No company';
    $k = $m . '|' . $co . '|' . $st;
    $g = $prGroups[$k] ?? $prNew($m, $co, $st);
    $g['emps'][(string) $e['id']] = true;
    $g['wages'] += IncomeTax::p($r['gross_wages'] ?? '');
    $paid = trim((string) ($r['challan_date'] ?? '')) !== '';
    $amt = IncomeTax::p($r['pt_paid'] ?? '') ?: IncomeTax::p($r['pt_deducted'] ?? '');
    $paid ? $g['paid'] += $amt : $g['unpaid'] += $amt;
    $paid && $prT['paid'] += $amt;
    $paid && $g['dates'][substr((string) $r['challan_date'], 0, 10)] = true;
    ($ch = trim((string) ($r['challan_no'] ?? ''))) === '' || $g['challans'][$ch] = true;
    $prGroups[$k] = $g;
}

// TO BE PAID — the completed payroll's PT lines, same months and filters.
$prCal = StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
$prCodes = (array) ($prCal['heads']['PT']['line_codes'] ?? ['PT']);
$prRuns = 0;
foreach ($register->runs() as $run) {
    $per = (string) $run['period'];
    if (!$prIn($per)) { continue; }
    $prRuns++;
    foreach ((array) ($run['rows'] ?? []) as $key => $row) {
        $e = $prByKey[(string) $key] ?? null;
        if ($e === null) { continue; }
        $t = StatutoryReports::linesMinor((array) $row, $prCodes);
        if ($t === 0) { continue; }
        $st = $prStateOf($e);
        $prStates[$st] = true;
        if (!$prPass($e) || ($prType !== '' && strcasecmp($st, $prType) !== 0)) { continue; }
        $co = orgValueOf($store, $e, 'company') ?: 'No company';
        $k = $per . '|' . $co . '|' . $st;
        $g = $prGroups[$k] ?? $prNew($per, $co, $st);
        $g['pay'] = ($g['pay'] ?? 0) + $t;
        $g['emps'][(string) $e['id']] = true;
        $g['wages_run'] = ($g['wages_run'] ?? 0) + IncomeTax::p($row['earnings'] ?? '');
        $prGroups[$k] = $g;
        $prT['payroll'] += $t;
    }
}

$prBal = 0;
foreach ($prGroups as $k => $g) {
    $prGroups[$k]['balance'] = $g['pay'] !== null ? $g['pay'] - $g['paid'] : $g['unpaid'];
    $prBal += $prGroups[$k]['balance'];
}
uasort($prGroups, static fn (array $a, array $b): int => [$b['month'], $a['company'], $a['state']] <=> [$a['month'], $b['company'], $b['state']]);
ksort($prStates, SORT_NATURAL | SORT_FLAG_CASE);
$prFyOf = static fn (string $m): string => 'FY ' . IncomeTax::fyOf($m . '-01', financialYearStart($customisation));
$prKeepQs = '';
foreach (array_keys(PERIOD_KEYS) as $pk) { isset($_GET[$pk]) && $prKeepQs .= '&amp;' . $pk . '=' . e(rawurlencode((string) $_GET[$pk])); }
$prStateSel = '<div><label>State</label><select name="state"><option value="">All states</option>';
foreach (array_keys($prStates) as $s) { $prStateSel .= '<option' . (strcasecmp($s, $prType) === 0 ? ' selected' : '') . '>' . e((string) $s) . '</option>'; }
$prStateSel .= '</select></div>';
$prType === '' || $prKeepQs .= '&amp;state=' . e(rawurlencode($prType));
$prMay = $may('payroll.run') || isAdministrator();
echo tdsCss();
?>
  <div class="page-header">
    <div><h2>Professional Tax</h2><p>PT deducted (completed payroll) and paid to the state (uploaded challans) &middot; a line counts as paid once its paid-on date is filled &middot; one row per month, company and state &middot; <a href="?r=pt-slabs">PT slabs</a></p></div>
<?php if ($prMay): ?>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <form method="post" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-template"><input type="hidden" name="k" value="pt-remittances">
        <input type="month" name="month" value="<?= e(date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))) ?>" style="width:175px">
        <button class="btn btn-outline" type="submit"><i class="fas fa-file-excel"></i> Template – from payroll</button></form>
      <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-upload"><input type="hidden" name="k" value="pt-remittances">
        <input type="file" name="sheet" accept=".xlsx,.xls,.csv" required style="max-width:210px">
        <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload</button></form>
    </div>
<?php endif; ?>
  </div>
<?php if ($prWhy !== null): ?><div class="card"><div class="problem" style="margin:0">The PT remittance table is not there yet — run the database update (migrations). <?= e($prWhy) ?></div></div><?php return; endif; ?>
  <div class="tds-foot" style="margin:0 0 16px">
    <div><span class="note">PT deducted from employees (payroll)</span><b><?= tdsMoney($prT['payroll']) ?></b><span class="note"><?= $prRuns ?> completed payroll month(s)</span></div>
    <div><span class="note">PT paid (challans)</span><b><?= tdsMoney($prT['paid']) ?></b></div>
    <div><span class="note">Balance to be paid</span><b style="color:<?= $prBal > 0 ? '#b91c1c' : '#047857' ?>"><?= $prBal === 0 ? 'Nil' : ($prBal < 0 ? 'Excess ' . tdsMoney(-$prBal) : tdsMoney($prBal)) ?></b></div>
  </div>
  <div class="card"><?= tdsOrgButtons('pt-remittances', $prFilter, $prScoped, $prCounts, $prKeepQs, $prStateSel, $prPeriod ?? allPeriod()) ?>
    <p class="note" style="margin:-4px 0 0">Button counts = uploaded challan lines in the period.</p></div>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Remittances by month</h3><p class="note" style="margin:0">One row per wage month, company and state · totals of the employee lines</p></div><div class="actions"><span class="badge badge-gray"><?= count($prGroups) ?> row(s)</span></div></div>
<?php if ($prGroups === []): ?>
    <div class="empty"><?= $prAll === [] && $prRuns === 0 ? 'Nothing yet. Once a payroll month is completed, download the template — wages and PT are filled in from it — add the challan details and upload.' : 'Nothing matches these filters.' ?></div>
<?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>FY</th><th>Month</th><th>Company / PT reg. no.</th><th>State</th><th class="tds-n">Employees</th><th class="tds-n">Gross wages</th><th class="tds-n">To be paid (payroll)</th><th class="tds-n">PT paid</th><th class="tds-n">Balance to be paid</th><th>Paid on</th><th>Challan no.</th></tr></thead>
      <tbody>
<?php foreach ($prGroups as $g): $reg = (string) ($prCompanies[$g['company']]['pt_registration_no'] ?? ''); $b = (int) $g['balance']; $w = (int) ($g['wages_run'] ?? 0) ?: $g['wages']; ?>
        <tr><td style="white-space:nowrap"><?= e($prFyOf($g['month'])) ?></td><td style="white-space:nowrap"><?= e(date('M Y', (int) strtotime($g['month'] . '-01'))) ?></td>
          <td><?= e($g['company']) ?><br><span class="note"><?= $reg === '' ? 'PT registration not set' : 'PT reg. ' . e($reg) ?></span></td><td><?= e($g['state']) ?></td>
          <td class="tds-n"><?= count($g['emps']) ?></td><td class="tds-n"><?= $w > 0 ? tdsMoney($w) : '—' ?></td>
          <td class="tds-n"><?= $g['pay'] === null ? '<span class="note">not run</span>' : '<b>' . tdsMoney((int) $g['pay']) . '</b>' ?></td>
          <td class="tds-n"><?= tdsMoney((int) $g['paid']) ?></td>
          <td class="tds-n" style="font-weight:600;color:<?= $b === 0 ? '#047857' : ($b < 0 ? '#b45309' : '#b91c1c') ?>"><?= $b === 0 ? 'Nil' : ($b < 0 ? 'Excess ' . tdsMoney(-$b) : tdsMoney($b)) ?></td>
          <td style="white-space:nowrap"><?= $g['dates'] === [] ? '<span class="note">—</span>' : e(implode(', ', array_map(static fn (string $d): string => SmartPRS\Surface\Dates::dmy($d), array_keys($g['dates'])))) ?></td>
          <td><?= $g['challans'] === [] ? '<span class="note">—</span>' : e(implode(', ', array_keys($g['challans']))) ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
