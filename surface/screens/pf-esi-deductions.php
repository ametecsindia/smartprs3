<?php declare(strict_types=1);
/**
 * PF & ESI DEDUCTED, PER EMPLOYEE PER MONTH (owner, 14 Oct: "just have the company and all filters
 * along with date range and employee ID, Name, Month and the PF, ESIC deducted"). Read from the
 * COMPLETED payroll — every closed run that was not discarded — summing each payslip's employee
 * lines for the head (config/compliance-calendar.json `line_codes`). Nothing is projected from a rate.
 */

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;
use SmartPRS\Platform\Support\Money;

if (!$may('payroll.view')) { ?>
<div class="page-header"><div><h2>PF &amp; ESI</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; }

$pdPeriod = isset($_GET['p']) ? reportPeriod($_GET, financialYearStart($customisation), date('Y-m-d')) : null;
$pdFilter = orgFilter();
$pdScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$pdOptions = orgFilterOptions($store, $pdScoped);
$pdCal = StatutoryReports::config(__DIR__ . '/../../config/compliance-calendar.json');
$pdByKey = [];
foreach ($pdScoped as $e) { $pdByKey[(string) ($e['id'] ?? '')] = $e; ($c = Employment::code($e)) === '' || $pdByKey[$c] = $e; }

// EVERY EMPLOYEE-MONTH WITH A PF OR ESI DEDUCTION, in period and in reach — before the
// organisation filter, so the Company / Branch buttons can count.
$pdAll = [];
$pdRuns = 0;
foreach ($register->runs() as $run) {
    $per = (string) $run['period'];
    if ($pdPeriod !== null && ($per < substr($pdPeriod['from'], 0, 7) || $per > substr($pdPeriod['to'], 0, 7))) { continue; }
    $pdRuns++;
    foreach ((array) ($run['rows'] ?? []) as $key => $row) {
        $e = $pdByKey[(string) $key] ?? null;
        if ($e === null) { continue; }
        $pf = StatutoryReports::linesMinor((array) $row, (array) ($pdCal['heads']['PF']['line_codes'] ?? []));
        $esi = StatutoryReports::linesMinor((array) $row, (array) ($pdCal['heads']['ESI']['line_codes'] ?? []));
        if ($pf === 0 && $esi === 0) { continue; }
        $pdAll[] = ['e' => $e, 'month' => $per, 'pf' => $pf, 'esi' => $esi];
    }
}

$pdRows = array_values(array_filter($pdAll, static function (array $r) use ($store, $pdFilter): bool {
    foreach ($pdFilter as $k => $v) {
        if ($v !== '' && strcasecmp(orgValueOf($store, $r['e'], $k), $v) !== 0) { return false; }
    }
    return true;
}));
usort($pdRows, static fn (array $a, array $b): int => [$b['month'], Employment::code($a['e'])] <=> [$a['month'], Employment::code($b['e'])]);
$pdPf = array_sum(array_column($pdRows, 'pf'));
$pdEsi = array_sum(array_column($pdRows, 'esi'));

// WHAT EVERY LINK CARRIES: the period, beside the organisation filter.
$pdKeepQs = '';
foreach (array_keys(PERIOD_KEYS) as $pk) { isset($_GET[$pk]) && $pdKeepQs .= '&amp;' . $pk . '=' . e(rawurlencode((string) $_GET[$pk])); }
$pdHref = static fn (array $over): string => '?r=pf-esi-deductions' . orgFilterQuery(array_merge($pdFilter, ['employee' => ''], $over)) . $pdKeepQs;
// EVERY COMPANY AND BRANCH ON FILE IS A BUTTON (from the employees in reach), counting the rows
// in the period — so a company with nothing yet still shows, with 0.
$pdCount = static function (string $k, ?string $company = null) use ($pdAll, $pdScoped, $store): array {
    $n = [];
    foreach ($pdScoped as $e) {
        if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $e, 'company'), $company) !== 0) { continue; }
        ($v = orgValueOf($store, $e, $k)) === '' || $n[$v] ??= 0;
    }
    foreach ($pdAll as $r) {
        if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $r['e'], 'company'), $company) !== 0) { continue; }
        $v = orgValueOf($store, $r['e'], $k);
        $v === '' || $n[$v] = ($n[$v] ?? 0) + 1;
    }
    ksort($n, SORT_NATURAL | SORT_FLAG_CASE);
    return $n;
};
$pdCo = $pdCount('company');
$pdBr = $pdCount('branch', $pdFilter['company']);
$pdBrCo = [];
foreach ($pdScoped as $e) { $b = orgValueOf($store, $e, 'branch'); $b === '' || $pdBrCo[$b] ??= orgValueOf($store, $e, 'company'); }

// 100 A PAGE; `pg`, because `p` is the period mode.
$pdPages = max(1, (int) ceil(count($pdRows) / 100));
$pdPage = min($pdPages, max(1, (int) ($_GET['pg'] ?? 1)));
$pdShown = array_slice($pdRows, ($pdPage - 1) * 100, 100);
?>
  <div class="page-header">
    <div>
      <h2>PF &amp; ESI</h2>
      <p>PF and ESIC deducted from each employee, month by month, as the completed payroll recorded it</p>
    </div>
  </div>
  <style>
    .ch-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px}
    .ch-row > b{font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--text3);min-width:74px}
    .ch{--c:var(--text2);display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s;
      background:var(--card);border:1px solid color-mix(in srgb,var(--c) 45%,var(--border));color:var(--c)}
    .ch:hover{background:color-mix(in srgb,var(--c) 8%,var(--card))}
    .ch i{font-size:11.5px}
    .ch span{font-size:11px;font-weight:600;padding:0 7px;border-radius:6px;background:color-mix(in srgb,var(--c) 12%,transparent);color:var(--c);font-variant-numeric:tabular-nums}
    .ch.on{background:var(--c);border-color:var(--c);color:#fff}
    .ch.on span{background:rgba(255,255,255,.22);color:#fff}
    .ch::before{content:"\f0c8";font-family:"Font Awesome 6 Free";font-weight:400;font-size:13px;line-height:1}
    .ch.on::before{content:"\f14a";font-weight:900}
    .ch.all{--c:var(--text1)}
    .ch.all:not(.on){color:var(--text2);border-color:var(--border)}
    #pd-filter .org-filter > div:has(#of_company), #pd-filter .org-filter > div:has(#of_branch){display:none}
    .pd-num{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
  </style>
  <div class="card" id="pd-filter">
    <div class="ch-row"><b>Company</b>
      <a class="ch all<?= $pdFilter['company'] === '' ? ' on' : '' ?>" href="<?= $pdHref(['company' => '', 'branch' => '']) ?>"><i class="fas fa-layer-group"></i> All companies <span><?= array_sum($pdCo) ?></span></a>
<?php foreach ($pdCo as $n => $c): ?>
      <a class="ch<?= strcasecmp($pdFilter['company'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) $n) ?>" href="<?= $pdHref(['company' => (string) $n, 'branch' => '']) ?>"><i class="fas fa-building"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <div class="ch-row"><b>Branch</b>
      <a class="ch all<?= $pdFilter['branch'] === '' ? ' on' : '' ?>" href="<?= $pdHref(['branch' => '']) ?>"><i class="fas fa-map-location-dot"></i> All branches <span><?= array_sum($pdBr) ?></span></a>
<?php foreach ($pdBr as $n => $c): ?>
      <a class="ch<?= strcasecmp($pdFilter['branch'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) ($pdBrCo[(string) $n] ?? '')) ?>" href="<?= $pdHref(['branch' => (string) $n]) ?>"><i class="fas fa-location-dot"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <?= orgFilterBar('pf-esi-deductions', $pdFilter, $pdOptions, [], 'Show', null, $pdPeriod ?? allPeriod(), PERIOD_MODES_ALL) ?>
  </div>

  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">PF &amp; ESIC deducted</h3>
      <p class="note" style="margin:0"><?= $pdRuns ?> completed payroll month(s) in the period &middot; employees with no PF or ESIC deduction in a month are not listed</p></div>
      <div class="actions"><span class="badge badge-gray"><?= count($pdRows) ?> row(s)</span></div></div>
<?php if ($pdRows === []): ?>
    <div class="empty"><?= $pdRuns === 0 ? 'No payroll is completed in this period. Close a month on Payroll and its PF and ESIC deductions appear here.' : 'No PF or ESIC deduction matches these filters.' ?></div>
<?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Employee ID</th><th>Name</th><th>Month</th><th class="pd-num">PF deducted</th><th class="pd-num">ESIC deducted</th></tr></thead>
      <tbody>
<?php foreach ($pdShown as $r): ?>
        <tr><td><b><?= e(Employment::code($r['e'])) ?></b></td><td><?= e((string) ($r['e']['name'] ?? '')) ?></td>
          <td style="white-space:nowrap"><?= e(date('M Y', (int) strtotime($r['month'] . '-01'))) ?></td>
          <td class="pd-num"><?= e(Money::ofMinor($r['pf'])->format()) ?></td><td class="pd-num"><?= e(Money::ofMinor($r['esi'])->format()) ?></td></tr>
<?php endforeach; ?>
        <tr style="font-weight:700"><td colspan="3">Total<?= $pdPages > 1 ? ' (all pages)' : '' ?></td><td class="pd-num"><?= e(Money::ofMinor($pdPf)->format()) ?></td><td class="pd-num"><?= e(Money::ofMinor($pdEsi)->format()) ?></td></tr>
      </tbody>
    </table></div>
<?php if ($pdPages > 1): ?>
    <p style="padding:14px 22px;border-top:1px solid var(--border);margin:0">
<?php for ($i = 1; $i <= $pdPages; $i++): ?>
      <a class="btn" style="<?= $i === $pdPage ? 'background:var(--accent);color:#fff;border:0' : '' ?>" href="?r=pf-esi-deductions<?= orgFilterQuery($pdFilter) . $pdKeepQs ?>&amp;pg=<?= $i ?>"><?= $i ?></a>
<?php endfor; ?>
    </p>
<?php endif; ?>
<?php endif; ?>
  </div>
