<?php declare(strict_types=1);
/**
 * PF & ESI REMITTANCES (owner, 14 Oct): what was deducted from employees and what was paid to EPFO /
 * ESIC, read ONLY from the uploaded remittance files (table pf_esi_remittances, one line per employee
 * per wage month). The per-employee lines stay hidden; this screen shows the totals and one row per
 * Type × Month × Company. Nothing here is computed from payroll or from any rate.
 *
 * A line counts as REMITTED once it carries its paid-on date (PF paid on / ESI paid on); a line
 * without one is BALANCE TO BE REMITTED.
 */

use SmartPRS\Surface\Amount;
use SmartPRS\Surface\StatutoryReports;
use SmartPRS\Surface\Employment;
use SmartPRS\Platform\Support\Money;

if (!$may('payroll.view')) { ?>
<div class="page-header"><div><h2>PF &amp; ESI Remittances</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; }

$rmFy = financialYearStart($customisation);
$rmPeriod = isset($_GET['p']) ? reportPeriod($_GET, $rmFy, date('Y-m-d')) : null;
$rmFilter = orgFilter();
$rmType = in_array((string) ($_GET['type'] ?? ''), ['PF', 'ESIC'], true) ? (string) $_GET['type'] : '';
$rmScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$rmOptions = orgFilterOptions($store, $rmScoped);
$rmEmp = [];
foreach ($rmScoped as $e) { $rmEmp[(string) ($e['id'] ?? '')] = $e; }

try {
    $rmAll = $store->all('pf_esi_remittances');
    $rmWhy = null;
} catch (\Throwable $ex) {
    $rmAll = [];
    $rmWhy = $ex->getMessage();
}

// IN PERIOD AND IN REACH — before the organisation filter, so the Company / Branch buttons can count.
$rmInPeriod = [];
foreach ($rmAll as $r) {
    $m = (string) ($r['month'] ?? '');
    $e = $rmEmp[(string) ($r['employee_id'] ?? '')] ?? null;
    if (isRetired($r) || $e === null || $m === '') { continue; }
    if ($rmPeriod !== null && ($m < substr($rmPeriod['from'], 0, 7) || $m > substr($rmPeriod['to'], 0, 7))) { continue; }
    $rmInPeriod[] = $r + ['_e' => $e];
}

$rmPass = static function (array $r, array $filter) use ($store): bool {
    foreach ($filter as $k => $v) {
        if ($v !== '' && strcasecmp(orgValueOf($store, $r['_e'], $k), $v) !== 0) { return false; }
    }
    return true;
};
$rmRows = array_values(array_filter($rmInPeriod, static fn (array $r): bool => $rmPass($r, $rmFilter)));

$rmM = static fn (array $r, string ...$cols): Money => Money::sum(Money::zero(), ...array_map(static fn (string $c): Money => Amount::of($r[$c] ?? ''), $cols));
$rmPaid = static fn (array $r, string $type): bool => trim((string) ($r[$type === 'PF' ? 'pf_paid_on' : 'esi_paid_on'] ?? '')) !== '';
$rmHeads = [
    'PF' => ['ee' => ['epf_ee_share'], 'er' => ['eps_er_share', 'epf_er_share'], 'challan' => 'pf_trrn', 'paid' => 'pf_paid_on'],
    'ESIC' => ['ee' => ['esi_ee_share'], 'er' => ['esi_er_share'], 'challan' => 'esi_challan_no', 'paid' => 'esi_paid_on'],
];

// THE FOUR TOTALS — for the period and organisation picked (the Type picker narrows only the table).
$rmT = ['PF' => ['ded' => Money::zero(), 'rem' => Money::zero(), 'bal' => Money::zero()], 'ESIC' => ['ded' => Money::zero(), 'rem' => Money::zero(), 'bal' => Money::zero()]];
// ONE ROW PER TYPE × MONTH × COMPANY.
$rmGroups = [];
$rmNew = static fn (string $type, string $month, string $co): array => ['type' => $type, 'month' => $month, 'company' => $co, 'emps' => [],
    'ee' => Money::zero(), 'er' => Money::zero(), 'rem' => Money::zero(), 'bal' => Money::zero(), 'pay' => null, 'dates' => [], 'challans' => []];
foreach ($rmRows as $r) {
    $co = orgValueOf($store, $r['_e'], 'company') ?: 'No company';
    foreach ($rmHeads as $type => $h) {
        $ee = $rmM($r, ...$h['ee']);
        $er = $rmM($r, ...$h['er']);
        $due = $ee->plus($er);
        if ($due->isZero()) { continue; } // no contribution of this type on this line
        $paid = $rmPaid($r, $type);
        $rmT[$type]['ded'] = $rmT[$type]['ded']->plus($ee);
        $rmT[$type][$paid ? 'rem' : 'bal'] = $rmT[$type][$paid ? 'rem' : 'bal']->plus($due);
        $k = $type . '|' . $r['month'] . '|' . $co;
        $g = $rmGroups[$k] ?? $rmNew($type, (string) $r['month'], $co);
        $g['emps'][(string) $r['employee_id']] = true;
        $g['ee'] = $g['ee']->plus($ee);
        $g['er'] = $g['er']->plus($er);
        $g[$paid ? 'rem' : 'bal'] = $g[$paid ? 'rem' : 'bal']->plus($due);
        $paid && $g['dates'][substr((string) $r[$h['paid']], 0, 10)] = true;
        ($c = trim((string) ($r[$h['challan']] ?? ''))) === '' || $g['challans'][$c] = true;
        $rmGroups[$k] = $g;
    }
}
// TO BE PAID — from the COMPLETED payroll (every closed run that was not discarded) for the same
// months: the employee + employer lines of each head, as config/compliance-calendar.json names them
// (line_codes / employer_line_codes). A month with no closed run carries no figure and says so.
$rmCal = StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
$rmByKey = [];
foreach ($rmScoped as $e) { $rmByKey[(string) ($e['id'] ?? '')] = $e; ($c = Employment::code($e)) === '' || $rmByKey[$c] = $e; }
$rmPayT = ['PF' => ['ee' => Money::zero(), 'pay' => Money::zero()], 'ESIC' => ['ee' => Money::zero(), 'pay' => Money::zero()]];
$rmRuns = 0;
foreach ($register->runs() as $run) {
    $per = (string) $run['period'];
    if ($rmPeriod !== null && ($per < substr($rmPeriod['from'], 0, 7) || $per > substr($rmPeriod['to'], 0, 7))) { continue; }
    $rmRuns++;
    foreach ((array) ($run['rows'] ?? []) as $key => $row) {
        $e = $rmByKey[(string) $key] ?? null;
        if ($e === null || !$rmPass(['_e' => $e], $rmFilter)) { continue; }
        $co = orgValueOf($store, $e, 'company') ?: 'No company';
        foreach (['PF' => 'PF', 'ESIC' => 'ESI'] as $type => $head) {
            $ee = Money::ofMinor(StatutoryReports::linesMinor((array) $row, (array) ($rmCal['heads'][$head]['line_codes'] ?? [])));
            $pay = $ee->plus(Money::ofMinor(StatutoryReports::linesMinor((array) $row, (array) ($rmCal['heads'][$head]['employer_line_codes'] ?? []))));
            if ($pay->isZero()) { continue; }
            $rmPayT[$type]['ee'] = $rmPayT[$type]['ee']->plus($ee);
            $rmPayT[$type]['pay'] = $rmPayT[$type]['pay']->plus($pay);
            $k = $type . '|' . $per . '|' . $co;
            $g = $rmGroups[$k] ?? $rmNew($type, $per, $co);
            $g['pay'] = ($g['pay'] ?? Money::zero())->plus($pay);
            $g['emps'][(string) ($e['id'] ?? '')] = true;
            $rmGroups[$k] = $g;
        }
    }
}
// BALANCE TO BE REMITTED = to be paid (payroll) − remitted (file). Without a completed payroll for
// that month, it falls back to the file's own unpaid lines.
$rmBalT = ['PF' => Money::zero(), 'ESIC' => Money::zero()];
foreach ($rmGroups as $k => $g) {
    $rmGroups[$k]['balance'] = $g['pay'] !== null ? $g['pay']->minus($g['rem']) : $g['bal'];
    $rmBalT[$g['type']] = $rmBalT[$g['type']]->plus($rmGroups[$k]['balance']);
}
$rmGroups = array_filter($rmGroups, static fn (array $g): bool => $rmType === '' || $g['type'] === $rmType);
uasort($rmGroups, static fn (array $a, array $b): int => [$b['month'], $a['type'], $a['company']] <=> [$a['month'], $b['type'], $b['company']]);

$rmFyOf = static function (string $month) use ($rmFy): string {
    $y = (int) substr($month, 0, 4);
    $start = (int) substr($month, 5, 2) >= $rmFy ? $y : $y - 1;
    return $rmFy === 1 ? (string) $y : sprintf('FY %d-%02d', $start, ($start + 1) % 100);
};

// WHAT EVERY LINK CARRIES: the period and the type, beside the organisation filter.
$rmKeep = ($rmType !== '' ? ['type' => $rmType] : []);
foreach (array_keys(PERIOD_KEYS) as $pk) { isset($_GET[$pk]) && $rmKeep[$pk] = (string) $_GET[$pk]; }
$rmKeepQs = '';
foreach ($rmKeep as $kk => $vv) { $rmKeepQs .= '&amp;' . $kk . '=' . e(rawurlencode($vv)); }
$rmHref = static fn (array $over): string => '?r=pf-esi-remittances' . orgFilterQuery(array_merge($rmFilter, ['employee' => ''], $over)) . $rmKeepQs;

// EVERY COMPANY AND BRANCH ON FILE IS A BUTTON (from the employees in reach), counting the rows
// in the period — so a company with nothing yet still shows, with 0.
$rmCount = static function (string $k, ?string $company = null) use ($rmInPeriod, $rmScoped, $store): array {
    $n = [];
    foreach ($rmScoped as $e) {
        if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $e, 'company'), $company) !== 0) { continue; }
        ($v = orgValueOf($store, $e, $k)) === '' || $n[$v] ??= 0;
    }
    foreach ($rmInPeriod as $r) {
        if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $r['_e'], 'company'), $company) !== 0) { continue; }
        $v = orgValueOf($store, $r['_e'], $k);
        $v === '' || $n[$v] = ($n[$v] ?? 0) + 1;
    }
    ksort($n, SORT_NATURAL | SORT_FLAG_CASE);
    return $n;
};
$rmCo = $rmCount('company');
$rmBr = $rmCount('branch', $rmFilter['company']);
$rmBrCo = [];
foreach ($rmScoped as $e) { $b = orgValueOf($store, $e, 'branch'); $b === '' || $rmBrCo[$b] ??= orgValueOf($store, $e, 'company'); }
$rmMayEdit = $may('payroll.run');
// EACH COMPANY'S PF ESTABLISHMENT CODE AND ESIC EMPLOYER CODE (Organization Configuration → Companies), beside its rows.
$rmCodes = [];
foreach (liveRows($store, 'companies') as $c) { $rmCodes[trim((string) ($c['name'] ?? ''))] = ['PF' => (string) ($c['pf_establishment_code'] ?? ''), 'ESIC' => (string) ($c['esic_employer_code'] ?? '')]; }
?>
  <div class="page-header">
    <div>
      <h2>PF &amp; ESI Remittances</h2>
      <p>What was deducted from employees and paid to EPFO and ESIC, from your uploaded remittance files &middot; <b>To be paid</b> comes from the completed payroll of the same month &middot; a file line counts as remitted once it has its paid-on date</p>
    </div>
<?php if ($rmMayEdit): ?>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <form method="post" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-template">
        <input type="month" name="month" value="<?= e(date('Y-m', strtotime(date('Y-m-01') . ' -1 month'))) ?>" title="Wage month the template is filled for" style="width:175px">
        <button class="btn btn-outline" type="submit"><i class="fas fa-file-excel"></i> Template – all employees</button></form>
      <form method="post" enctype="multipart/form-data" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pf-esi-upload">
        <input type="file" name="sheet" accept=".xlsx,.xls,.csv" required style="max-width:210px">
        <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload</button></form>
    </div>
<?php endif; ?>
  </div>

<?php if ($rmWhy !== null): ?>
  <div class="card"><div class="problem" style="margin:0"><?= e($rmWhy) ?></div></div>
<?php return; endif; ?>

  <style>
    .dc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:16px}
    .dc-card{padding:16px 18px;border-radius:10px;background:var(--card);border:1px solid var(--border);box-shadow:0 1px 2px rgba(15,23,42,.04)}
    .dc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
    .dc-lab{font-size:11.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3)}
    .dc-val{font-size:26px;font-weight:700;line-height:1.15;color:var(--text1);margin-top:4px;font-variant-numeric:tabular-nums}
    .dc-ic{flex:0 0 36px;height:36px;border-radius:8px;display:grid;place-items:center;font-size:15px;color:var(--text2);background:color-mix(in srgb,var(--text1) 6%,transparent)}
    .dc-sub{margin-top:10px;padding-top:8px;border-top:1px solid var(--border);font-size:12px;color:var(--text2)}
    .ch-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
    .ch-row > b{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);min-width:74px}
    .ch{--c:var(--text2);display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s,box-shadow .15s;
      background:var(--card);border:1px solid color-mix(in srgb,var(--c) 45%,var(--border));color:var(--c)}
    .ch:hover{background:color-mix(in srgb,var(--c) 8%,var(--card))}
    .ch i{font-size:11.5px}
    .ch span{font-size:11px;font-weight:600;padding:0 7px;border-radius:6px;background:color-mix(in srgb,var(--c) 12%,transparent);color:var(--c);font-variant-numeric:tabular-nums}
    .ch.on{background:var(--c);border-color:var(--c);color:#fff}
    .ch.on span{background:rgba(255,255,255,.22);color:#fff}
    /* A TICK BOX ON EVERY BUTTON (owner, 14 Oct): empty when not picked, ticked when picked. */
    .ch::before{content:"\f0c8";font-family:"Font Awesome 6 Free";font-weight:400;font-size:13px;line-height:1}
    .ch.on::before{content:"\f14a";font-weight:900}
    .ch.all{--c:var(--text1)}
    .ch.all:not(.on){color:var(--text2);border-color:var(--border)}
    #rm-filter .org-filter > div:has(#of_company), #rm-filter .org-filter > div:has(#of_branch){display:none}
    .rm-num{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
  </style>
  <div class="dc-grid">
<?php foreach ([
    ['fa-user-minus', 'PF deducted from employees', $rmRuns > 0 ? $rmPayT['PF']['ee'] : $rmT['PF']['ded'], $rmRuns > 0 ? 'Employee share, completed payroll' : 'From the uploaded files — no payroll completed in this period'],
    ['fa-user-minus', 'ESIC deducted from employees', $rmRuns > 0 ? $rmPayT['ESIC']['ee'] : $rmT['ESIC']['ded'], $rmRuns > 0 ? 'Employee share, completed payroll' : 'From the uploaded files — no payroll completed in this period'],
    ['fa-building-columns', 'Total EPF remitted', $rmT['PF']['rem'], 'To be paid ' . ($rmRuns > 0 ? $rmPayT['PF']['pay']->format() : '—') . ' · Balance ' . $rmBalT['PF']->format()],
    ['fa-building-columns', 'Total ESIC remitted', $rmT['ESIC']['rem'], 'To be paid ' . ($rmRuns > 0 ? $rmPayT['ESIC']['pay']->format() : '—') . ' · Balance ' . $rmBalT['ESIC']->format()],
] as [$ic, $lab, $val, $sub]): ?>
    <div class="dc-card"><div class="dc-top"><div><div class="dc-lab"><?= e($lab) ?></div><div class="dc-val"><?= e($val->format()) ?></div></div><div class="dc-ic"><i class="fas <?= $ic ?>"></i></div></div>
      <div class="dc-sub"><?= e($sub) ?></div></div>
<?php endforeach; ?>
  </div>

  <div class="card" id="rm-filter">
    <div class="ch-row"><b>Company</b>
      <a class="ch all<?= $rmFilter['company'] === '' ? ' on' : '' ?>" href="<?= $rmHref(['company' => '', 'branch' => '']) ?>"><i class="fas fa-layer-group"></i> All companies <span><?= array_sum($rmCo) ?></span></a>
<?php foreach ($rmCo as $n => $c): ?>
      <a class="ch<?= strcasecmp($rmFilter['company'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) $n) ?>" href="<?= $rmHref(['company' => (string) $n, 'branch' => '']) ?>"><i class="fas fa-building"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <div class="ch-row"><b>Branch</b>
      <a class="ch all<?= $rmFilter['branch'] === '' ? ' on' : '' ?>" href="<?= $rmHref(['branch' => '']) ?>"><i class="fas fa-map-location-dot"></i> All branches <span><?= array_sum($rmBr) ?></span></a>
<?php foreach ($rmBr as $n => $c): ?>
      <a class="ch<?= strcasecmp($rmFilter['branch'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) ($rmBrCo[(string) $n] ?? '')) ?>" href="<?= $rmHref(['branch' => (string) $n]) ?>"><i class="fas fa-location-dot"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <?= orgFilterBar('pf-esi-remittances', $rmFilter, $rmOptions, [], 'Show', null, $rmPeriod ?? allPeriod(), PERIOD_MODES_ALL,
        '<div><label for="rm_type">Type</label><select id="rm_type" name="type"><option value="">PF &amp; ESIC</option>'
        . '<option' . ($rmType === 'PF' ? ' selected' : '') . '>PF</option><option' . ($rmType === 'ESIC' ? ' selected' : '') . '>ESIC</option></select></div>') ?>
    <p class="note" style="margin:-4px 0 0">The counts on the buttons are remittance lines (one per employee per month) in the period picked.</p>
  </div>

  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Remittances by month</h3>
      <p class="note" style="margin:0">One row per type, wage month and company &middot; totals of the uploaded lines</p></div>
      <div class="actions"><span class="badge badge-gray"><?= count($rmGroups) ?> row(s)</span></div></div>
<?php if ($rmGroups === []): ?>
    <div class="empty"><?= array_filter($rmAll, static fn (array $r): bool => !isRetired($r)) === [] ? 'Nothing uploaded yet. Download the template, fill the month from your ECR and ESIC contribution files, and upload it.' : 'No remittances match these filters.' ?></div>
<?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Type</th><th>FY</th><th>Month</th><th>Company</th><th class="rm-num">Employees</th><th class="rm-num">Employee share</th><th class="rm-num">Employer share</th><th class="rm-num">As per file</th><th class="rm-num">To be paid (payroll)</th><th class="rm-num">Total remitted</th><th class="rm-num">Balance to be remitted</th><th>Date remitted</th><th>Challan / TRRN</th></tr></thead>
      <tbody>
<?php foreach ($rmGroups as $g): $due = $g['ee']->plus($g['er']); ?>
        <tr>
          <td><span class="badge badge-<?= $g['type'] === 'PF' ? 'blue' : 'green' ?>"><?= e($g['type']) ?></span></td>
          <td style="white-space:nowrap"><?= e($rmFyOf($g['month'])) ?></td>
          <td style="white-space:nowrap"><?= e(date('M Y', (int) strtotime($g['month'] . '-01'))) ?></td>
          <td><?= e($g['company']) ?><br><span class="note"><?= ($rmCode = (string) ($rmCodes[$g['company']][$g['type']] ?? '')) === '' ? ($g['type'] === 'PF' ? 'PF establishment code not set' : 'ESIC employer code not set') : e(($g['type'] === 'PF' ? 'Est. ' : 'Code ') . $rmCode) ?></span></td>
          <td class="rm-num"><?= count($g['emps']) ?></td>
          <td class="rm-num"><?= e($g['ee']->format()) ?></td>
          <td class="rm-num"><?= e($g['er']->format()) ?></td>
          <td class="rm-num"><?= e($due->format()) ?></td>
          <td class="rm-num"><?= $g['pay'] === null ? '<span class="note" title="No completed payroll for this month">not run</span>' : '<b>' . e($g['pay']->format()) . '</b>' ?></td>
          <td class="rm-num"><?= e($g['rem']->format()) ?></td>
          <td class="rm-num" style="font-weight:600;color:<?= $g['balance']->isZero() ? '#047857' : ($g['balance']->isNegative() ? '#b45309' : '#b91c1c') ?>"><?= $g['balance']->isZero() ? 'Nil' : ($g['balance']->isNegative() ? 'Excess ' . e($g['balance']->absolute()->format()) : e($g['balance']->format())) ?></td>
          <td style="white-space:nowrap"><?= $g['dates'] === [] ? '<span class="note">&mdash;</span>' : e(implode(', ', array_map(static fn (string $d): string => SmartPRS\Surface\Dates::dmy($d), array_keys($g['dates'])))) ?></td>
          <td><?= $g['challans'] === [] ? '<span class="note">&mdash;</span>' : e(implode(', ', array_keys($g['challans']))) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
