<?php declare(strict_types=1);
/**
 * SALARY BREAKUP (owner, 15 Oct: "the entire salary structure of the employees based on their CTC
 * package … the breakup of all … should match the calculation set in the Organization Configuration
 * Salary structure for the respective employee based on the city, branch, company, gender"). Every
 * active employee's full month, from the engine a payroll close uses — see partials/salary-breakup-lib.php.
 * Monthly or annual; Excel and CSV export of exactly what is filtered; ?id= shows one employee with
 * the rule each figure came from.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Platform\Support\Money;

if (!$may('payroll.view')) { ?>
<div class="page-header"><div><h2>Salary Breakup</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; }

$sbCss = <<<'CSS'
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
    #sb-filter .org-filter > div:has(#of_company), #sb-filter .org-filter > div:has(#of_branch){display:none}
    .sb-n{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums;white-space:nowrap}
    .sb-t th{white-space:nowrap;font-size:11.5px}
    .sb-t th.g-e{background:color-mix(in srgb,#15803d 10%,var(--card))} .sb-t th.g-d{background:color-mix(in srgb,#b91c1c 9%,var(--card))} .sb-t th.g-r{background:color-mix(in srgb,#1d4ed8 9%,var(--card))}
    .sb-t td.tot{font-weight:700}
    .sb-t td:first-child,.sb-t th:first-child{position:sticky;left:0;background:var(--card);z-index:1;width:200px;min-width:170px;max-width:220px;white-space:normal!important}
    .sb-t td:first-child a{display:block;white-space:normal;overflow-wrap:anywhere} /* never cut off (owner, 23 Oct) */
    .sb-ic{text-align:center;width:36px}.sb-ic a{display:inline-flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:7px;border:1px solid var(--border);color:var(--accent)}.sb-ic a:hover{background:color-mix(in srgb,var(--accent) 10%,var(--card))}
    .sb-na{display:inline-block;margin-top:2px;font-size:10.5px;line-height:1.3;color:var(--text3)}
    .sb-seg{display:inline-flex;border:1px solid var(--border);border-radius:8px;overflow:hidden}
    .sb-seg a{padding:6px 14px;font-size:13px;font-weight:600;text-decoration:none;color:var(--text2)}
    .sb-seg a.on{background:var(--accent);color:#fff}
    .sb-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border);margin:-4px 0 16px}
    .sb-tabs a{padding:9px 16px;font-size:13.5px;font-weight:600;text-decoration:none;color:var(--text2);border-bottom:2px solid transparent;margin-bottom:-1px}
    .sb-tabs a.on{color:var(--accent);border-bottom-color:var(--accent)}
    .ss-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
    .ss-tiles > div{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}
    .ss-tiles span{font-size:11.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--text3)}
    .ss-tiles b{font-size:22px;font-family:var(--mono);font-variant-numeric:tabular-nums}
    .ss-tiles em{font-style:normal;font-size:11.5px;color:var(--text3)}
  </style>
CSS;
$sbTab = ($_GET['tab'] ?? '') === 'month' ? 'month' : 'ctc';
/** "Provident Fund (PF) (on probation), ESIC (on probation)" → "PF, ESIC — on probation". */
$sbShort = static function (array $off): string {
    $heads = [];
    $why = [];
    foreach ($off as $l) {
        preg_match_all('/\(([^)]*)\)/', (string) $l, $m);
        $abbr = '';
        foreach ($m[1] as $p) { strlen($p) <= 6 && strtoupper($p) === $p ? $abbr = $p : $why[$p] = true; }
        $heads[] = $abbr !== '' ? $abbr : trim((string) preg_replace('/\s*\(.*$/', '', (string) $l));
    }
    return implode(', ', $heads) . ($why === [] ? '' : ' — ' . implode(', ', array_keys($why)));
};

$sbTabs = '<div class="sb-tabs"><a class="' . ($sbTab === 'ctc' ? 'on' : '') . '" href="?r=salary-breakup"><i class="fas fa-file-contract"></i> CTC Structure</a>'
    . '<a class="' . ($sbTab === 'month' ? 'on' : '') . '" href="?r=salary-breakup&amp;tab=month"><i class="fas fa-calendar-check"></i> Monthly Salary Statement</a></div>';

// TAB 2 — WHAT WAS PAID IN A MONTH (owner, 15 Oct), from the generated payroll.
if ($sbTab === 'month') { ?>
  <div class="page-header"><div><h2>Salary Breakup</h2><p>Two views of salary: <b>CTC Structure</b> (what each person is contracted to earn) and <b>Monthly Salary Statement</b> (what payroll actually paid in a month)</p></div></div>
  <?= $sbCss ?><?= $sbTabs ?>
<?php include __DIR__ . '/../partials/salary-statement-view.php'; return; }

include __DIR__ . '/../partials/salary-breakup-lib.php';
include_once __DIR__ . '/../partials/salary-annexure.php';

// THE SALARY STRUCTURE ANNEXURE for one employee (the icon on each row) — printable.
if (($_GET['print'] ?? '') === 'annexure' && ($sbE = $store->find('employees', (string) ($_GET['id'] ?? ''))) !== null) {
    if (!$scope->covers((string) $sbE['id'], $may('employee.list'))) { ?><div class="card"><div class="problem"><?= e($scope->why((string) $sbE['id'])) ?></div></div><?php return; }
    $sbAsOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['as_of'] ?? '')) === 1 ? (string) $_GET['as_of'] : date('Y-m-d');
    echo salaryAnnexureCss(); ?>
  <div class="no-print" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
    <a class="btn btn-outline" href="?r=salary-breakup">&larr; Salary Breakup</a>
    <form method="get" style="display:inline-flex;gap:6px;align-items:center"><input type="hidden" name="r" value="salary-breakup"><input type="hidden" name="id" value="<?= e((string) $sbE['id']) ?>"><input type="hidden" name="print" value="annexure">
      <label style="margin:0">As on</label><input type="date" name="as_of" value="<?= e($sbAsOf) ?>" onchange="this.form.submit()"></form>
  </div>
  <?= salaryAnnexureHtml(salaryAnnexure(orgEnrich($store, $sbE), $sbAsOf)) ?>
<?php return; }
$sbFilter = orgFilter();
$sbAnnual = ($_GET['view'] ?? '') === 'annual';
$sbData = sbData($sbFilter);
$sbScoped = array_map(static fn (array $r): array => $r['e'], $sbData['all']);
$sbOptions = orgFilterOptions($store, $sbScoped);
$sbX = $sbAnnual ? 12 : 1;
$sbM = static fn (int $minor): string => Money::ofMinor($minor * $sbX)->format();
$sbHref = static fn (array $over, ?bool $annual = null): string => '?r=salary-breakup' . orgFilterQuery(array_merge($sbFilter, ['employee' => ''], $over))
    . (($annual ?? $sbAnnual) ? '&amp;view=annual' : '');
$sbCount = static function (string $k, ?string $company = null) use ($sbData, $store): array {
    $n = [];
    foreach ($sbData['all'] as $r) {
        if ($company !== null && $company !== '' && strcasecmp(orgValueOf($store, $r['e'], 'company'), $company) !== 0) { continue; }
        $v = orgValueOf($store, $r['e'], $k);
        $v === '' || $n[$v] = ($n[$v] ?? 0) + 1;
    }
    ksort($n, SORT_NATURAL | SORT_FLAG_CASE);
    return $n;
};
$sbCo = $sbCount('company');
$sbBr = $sbCount('branch', $sbFilter['company']);
$sbBrCo = [];
foreach ($sbData['all'] as $r) { $b = orgValueOf($store, $r['e'], 'branch'); $b === '' || $sbBrCo[$b] ??= orgValueOf($store, $r['e'], 'company'); }
$sbOne = null;
foreach ($sbData['all'] as $r) { if ((string) $r['e']['id'] === (string) ($_GET['id'] ?? '')) { $sbOne = $r; } }
$sbTot = ['gross' => 0, 'ded' => 0, 'er' => 0, 'all_ded' => 0, 'net' => 0, 'cost' => 0, 'ctc' => 0, 'lines' => []];
foreach ($sbData['rows'] as $r) {
    foreach (['gross', 'ded', 'er', 'all_ded', 'net', 'cost'] as $k) { $sbTot[$k] += $r[$k]; }
    $sbTot['ctc'] += $r['month_ctc'];
    foreach ($r['lines'] as $c => $v) { $sbTot['lines'][$c] = ($sbTot['lines'][$c] ?? 0) + $v; }
}
$sbOff = count(array_filter($sbData['rows'], static fn (array $r): bool => abs($r['cost'] - $r['month_ctc']) > 100 || $r['why'] !== null));
?>
  <div class="page-header">
    <div>
      <h2>Salary Breakup</h2>
      <p>Two views of salary: <b>CTC Structure</b> (what each person is contracted to earn) and <b>Monthly Salary Statement</b> (what payroll actually paid in a month)</p>
    </div>
  </div>
<?= $sbCss ?><?= $sbTabs ?>
<?php if ($sbOne !== null): $o = $sbOne; $sbRules = [];
    try { foreach ($componentRules->resolveFor($o['e'], date('Y-m-d'), $payCycles) as $ru) { $sbRules[(string) $ru['component_code']] = $ru; } } catch (\Throwable $x) { }
    $sbRuleText = static function (string $code) use ($sbRules): array {
        $ru = $sbRules[(string) preg_replace('/^[A-Z]-/', '', $code)] ?? $sbRules[$code] ?? null;
        if ($ru === null) { return ['Statutory / default rule', 'Everybody']; }
        $calc = (string) ($ru['calculation'] ?? '');
        $how = match ($calc) {
            'percent_of' => rtrim(rtrim((string) $ru['percent_value'], '0'), '.') . '% of ' . (string) $ru['basis_column'],
            'flat' => 'Flat ' . Money::of((string) ($ru['flat_amount'] ?? '0'))->format(),
            'employee_gender' => 'By gender' . (isset($ru['_gender_rule']['mode']) ? ' — ' . (string) $ru['_gender_rule']['mode'] : ''),
            default => ucfirst(str_replace('_', ' ', $calc)),
        };
        ($ru['_govt_branch']['city'] ?? '') !== '' && $how .= ' (branch city ' . $ru['_govt_branch']['city'] . ')';
        $who = [];
        foreach (['scope_company' => '', 'scope_branch' => '', 'scope_role' => 'role ', 'scope_team' => 'team ', 'scope_designation' => '', 'scope_employee' => 'employee '] as $col => $pre) {
            ($v = trim((string) ($ru[$col] ?? ''))) === '' || strcasecmp($v, 'All') === 0 || $who[] = $pre . $v;
        }
        ($d = trim((string) (((array) ($ru['detail'] ?? []))['scope_department'] ?? ''))) === '' || strcasecmp($d, 'All') === 0 || $who[] = $d;
        return [$how, $who === [] ? 'Everybody' : implode(' · ', $who)];
    };
?>
  <div class="card">
    <div class="card-header"><div><h3 style="font-size:16px;font-weight:700"><?= e((string) $o['e']['name']) ?> <span class="note">(<?= e($o['code']) ?>)</span></h3>
      <p class="note" style="margin:0"><?= e(implode(' · ', array_filter([orgValueOf($store, $o['e'], 'company'), orgValueOf($store, $o['e'], 'branch'), $o['city'], orgValueOf($store, $o['e'], 'department'), orgValueOf($store, $o['e'], 'designation'), $o['gender']]))) ?>
        &middot; Annual CTC <b><?= e(Money::ofMinor($o['annual_ctc'])->format()) ?></b></p></div>
      <div class="actions"><button class="btn" onclick="window.print()"><i class="fas fa-print"></i> Print</button><a class="btn" href="<?= $sbHref([]) ?>">&larr; All employees</a></div></div>
<?php if ($o['off'] !== []): ?><p class="note" style="padding:0 18px">Not applicable to this employee (Organization Configuration / employee record): <b><?= e(implode(', ', $o['off'])) ?></b></p><?php endif; ?>
<?php if ($o['why'] !== null): ?><div class="problem">The salary could not be worked out: <?= e($o['why']) ?></div><?php endif; ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Component</th><th class="sb-n">Monthly</th><th class="sb-n">Annual</th><th>How it is worked out</th><th>Rule applies to</th></tr></thead>
      <tbody>
<?php foreach (['earning' => 'Earnings', 'deduction' => 'Deductions from the employee', 'employer' => 'Employer contributions (part of CTC, not paid in hand)'] as $g => $gl): ?>
        <tr><td colspan="5" style="font-weight:700;background:var(--bg2,var(--bg))"><?= $gl ?></td></tr>
<?php foreach ($sbData['cols'][$g] as $c => $label): if (!isset($o['lines'][$c])) { continue; } [$how, $who] = $sbRuleText($c); ?>
        <tr><td><?= e($label) ?></td><td class="sb-n"><?= e(Money::ofMinor($o['lines'][$c])->format()) ?></td><td class="sb-n"><?= e(Money::ofMinor($o['lines'][$c] * 12)->format()) ?></td><td><?= e($how) ?></td><td class="note"><?= e($who) ?></td></tr>
<?php endforeach; ?>
<?php $t = ['earning' => ['Gross earnings', $o['gross']], 'deduction' => ['Total deductions', $o['ded']], 'employer' => ['Employer contributions', $o['er']]][$g]; ?>
        <tr style="font-weight:700"><td><?= $t[0] ?></td><td class="sb-n"><?= e(Money::ofMinor($t[1])->format()) ?></td><td class="sb-n"><?= e(Money::ofMinor($t[1] * 12)->format()) ?></td><td colspan="2"></td></tr>
<?php endforeach; ?>
        <tr style="font-weight:800;font-size:14px"><td>Total deductions</td><td class="sb-n"><?= e(Money::ofMinor($o['all_ded'])->format()) ?></td><td class="sb-n"><?= e(Money::ofMinor($o['all_ded'] * 12)->format()) ?></td><td colspan="2" class="note"><?= ctcIncludesEmployer() ? 'employee deductions + employer contributions (the CTC includes them — Salary structure setting)' : 'employee deductions (employer contributions are on top of the CTC — Salary structure setting)' ?></td></tr>
        <tr style="font-weight:800;font-size:14px"><td>Net take-home</td><td class="sb-n"><?= e(Money::ofMinor($o['net'])->format()) ?></td><td class="sb-n"><?= e(Money::ofMinor($o['net'] * 12)->format()) ?></td><td colspan="2" class="note">before income tax (TDS), loans and other one-off lines</td></tr>
        <tr style="font-weight:800;font-size:14px"><td>Cost to company</td><td class="sb-n"><?= e(Money::ofMinor($o['cost'])->format()) ?></td><td class="sb-n"><?= e(Money::ofMinor($o['cost'] * 12)->format()) ?></td><td colspan="2" class="note">gross + employer contributions<?= abs($o['cost'] - $o['month_ctc']) > 100 ? ' — differs from the contracted CTC by ' . e(Money::ofMinor(($o['cost'] - $o['month_ctc']) * 12)->format()) . ' a year' : ' — matches the contracted CTC' ?></td></tr>
      </tbody>
    </table></div>
  </div>
<?php else: ?>
  <div class="card" id="sb-filter">
    <div class="ch-row"><b>Company</b>
      <a class="ch all<?= $sbFilter['company'] === '' ? ' on' : '' ?>" href="<?= $sbHref(['company' => '', 'branch' => '']) ?>"><i class="fas fa-layer-group"></i> All companies <span><?= array_sum($sbCo) ?></span></a>
<?php foreach ($sbCo as $n => $c): ?>
      <a class="ch<?= strcasecmp($sbFilter['company'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) $n) ?>" href="<?= $sbHref(['company' => (string) $n, 'branch' => '']) ?>"><i class="fas fa-building"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <div class="ch-row"><b>Branch</b>
      <a class="ch all<?= $sbFilter['branch'] === '' ? ' on' : '' ?>" href="<?= $sbHref(['branch' => '']) ?>"><i class="fas fa-map-location-dot"></i> All branches <span><?= array_sum($sbBr) ?></span></a>
<?php foreach ($sbBr as $n => $c): ?>
      <a class="ch<?= strcasecmp($sbFilter['branch'], (string) $n) === 0 ? ' on' : '' ?>" style="--c:<?= companyColor($store, (string) ($sbBrCo[(string) $n] ?? '')) ?>" href="<?= $sbHref(['branch' => (string) $n]) ?>"><i class="fas fa-location-dot"></i> <?= e((string) $n) ?> <span><?= $c ?></span></a>
<?php endforeach; ?>
    </div>
    <div class="ch-row"><b>Show</b>
      <span class="sb-seg"><a class="<?= $sbAnnual ? '' : 'on' ?>" href="<?= $sbHref($sbFilter, false) ?>">Monthly</a><a class="<?= $sbAnnual ? 'on' : '' ?>" href="<?= $sbHref($sbFilter, true) ?>">Annual</a></span>
    </div>
    <?= orgFilterBar('salary-breakup', $sbFilter, $sbOptions, $sbAnnual ? ['view' => 'annual'] : [], 'Show') ?>
    <div style="display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)"><span class="note" style="margin-right:auto">Export downloads exactly the employees filtered above (<?= $sbAnnual ? 'annual' : 'monthly' ?> view)</span>
<?php foreach (['xlsx' => ['fa-file-excel', 'Excel'], 'csv' => ['fa-file-csv', 'CSV']] as $sbFmt => [$sbIc, $sbLb]): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="salary-breakup-export">
        <input type="hidden" name="format" value="<?= $sbFmt ?>"><input type="hidden" name="view" value="<?= $sbAnnual ? 'annual' : 'monthly' ?>">
<?php foreach ($sbFilter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <button class="btn"><i class="fas <?= $sbIc ?>"></i> <?= $sbLb ?></button></form>
<?php endforeach; ?>
    </div>
  </div>

  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700"><?= $sbAnnual ? 'Annual' : 'Monthly' ?> breakup &middot; <?= e(date('F Y')) ?> rules</h3>
      <p class="note" style="margin:0">Full month, no loss of pay &middot; click a name for the rule behind each figure<?= $sbOff > 0 ? ' &middot; <b style="color:var(--amber,#b45309)">' . $sbOff . ' employee(s) where cost to company differs from CTC</b>' : '' ?></p></div>
      <div class="actions"><span class="badge badge-gray"><?= count($sbData['rows']) ?> employee(s)</span></div></div>
<?php if ($sbData['rows'] === []): ?>
    <div class="empty">No active employee matches these filters.</div>
<?php else: ?>
    <div class="table-wrap"><table class="sb-t">
      <thead><tr><th>Employee</th><th>Company / Branch</th><th class="sb-n">CTC (<?= $sbAnnual ? 'year' : 'month' ?>)</th>
<?php foreach ($sbData['cols']['earning'] as $c => $l): ?><th class="sb-n g-e" title="<?= e($c) ?>"><?= e($l) ?></th><?php endforeach; ?><th class="sb-n g-e">Gross earnings</th>
<?php foreach ($sbData['cols']['deduction'] as $c => $l): ?><th class="sb-n g-d" title="<?= e($c) ?>"><?= e($l) ?></th><?php endforeach; ?>
<?php foreach ($sbData['cols']['employer'] as $c => $l): ?><th class="sb-n g-r" title="<?= e($c) ?>"><?= e($l) ?></th><?php endforeach; ?>
        <th class="sb-n g-d">Total deductions</th><th class="sb-n">Net take-home</th><th class="sb-n">Cost to company</th><th class="sb-n">Diff. from CTC</th><th>Effective from</th><th class="sb-ic" title="Salary structure (offer / increment letter annexure)"><i class="fas fa-file-invoice"></i></th></tr></thead>
      <tbody>
<?php foreach ($sbData['rows'] as $r): $d = $r['cost'] - $r['month_ctc']; ?>
        <tr><td><a href="?r=salary-breakup&amp;id=<?= e((string) $r['e']['id']) ?>"><b><?= e($r['code']) ?></b> <?= e((string) ($r['e']['name'] ?? '')) ?></a><?php if ($r['why'] !== null): ?><br><span class="note" style="color:#b91c1c"><?= e(mb_strimwidth((string) $r['why'], 0, 80, '…')) ?></span><?php endif; ?><?php if ($r['off'] !== []): ?><span class="sb-na" title="Not applicable: <?= e(implode(', ', $r['off'])) ?>">N/A: <?= e($sbShort($r['off'])) ?></span><?php endif; ?></td>
          <td class="note" style="white-space:nowrap"><?= e(orgValueOf($store, $r['e'], 'company')) ?><br><?= e(orgValueOf($store, $r['e'], 'branch')) ?></td>
          <td class="sb-n"><?= e($sbM($r['month_ctc'])) ?></td>
<?php foreach (array_keys($sbData['cols']['earning']) as $c): ?><td class="sb-n"><?= isset($r['lines'][$c]) ? e($sbM($r['lines'][$c])) : '<span class="note">—</span>' ?></td><?php endforeach; ?>
          <td class="sb-n tot"><?= e($sbM($r['gross'])) ?></td>
<?php foreach (['deduction', 'employer'] as $g): foreach (array_keys($sbData['cols'][$g]) as $c): ?><td class="sb-n"><?= isset($r['lines'][$c]) ? e($sbM($r['lines'][$c])) : '<span class="note">—</span>' ?></td><?php endforeach; endforeach; ?>
          <td class="sb-n tot"><?= e($sbM($r['all_ded'])) ?></td><td class="sb-n tot"><?= e($sbM($r['net'])) ?></td><td class="sb-n tot"><?= e($sbM($r['cost'])) ?></td>
          <td class="sb-n"<?= abs($d) > 100 ? ' style="color:#b45309;font-weight:700"' : '' ?>><?= abs($d) > 100 ? e($sbM($d)) : 'Nil' ?></td>
          <td class="note" style="white-space:nowrap" title="<?= e((string) $r['eff']['why']) ?>"><?= $r['eff']['date'] === '' ? '—' : e(SmartPRS\Surface\Dates::dmy((string) $r['eff']['date'])) ?></td>
          <td class="sb-ic"><a href="?r=salary-breakup&amp;id=<?= e((string) $r['e']['id']) ?>&amp;print=annexure" target="_blank" title="Salary structure — offer / increment letter annexure"><i class="fas fa-file-invoice"></i></a></td></tr>
<?php endforeach; ?>
        <tr style="font-weight:800"><td colspan="2">Total (<?= count($sbData['rows']) ?>)</td><td class="sb-n"><?= e($sbM($sbTot['ctc'])) ?></td>
<?php foreach (array_keys($sbData['cols']['earning']) as $c): ?><td class="sb-n"><?= e($sbM($sbTot['lines'][$c] ?? 0)) ?></td><?php endforeach; ?>
          <td class="sb-n"><?= e($sbM($sbTot['gross'])) ?></td>
<?php foreach (['deduction', 'employer'] as $g): foreach (array_keys($sbData['cols'][$g]) as $c): ?><td class="sb-n"><?= e($sbM($sbTot['lines'][$c] ?? 0)) ?></td><?php endforeach; endforeach; ?>
          <td class="sb-n"><?= e($sbM($sbTot['all_ded'])) ?></td><td class="sb-n"><?= e($sbM($sbTot['net'])) ?></td><td class="sb-n"><?= e($sbM($sbTot['cost'])) ?></td><td class="sb-n"><?= e($sbM($sbTot['cost'] - $sbTot['ctc'])) ?></td><td></td><td></td></tr>
      </tbody>
    </table></div>
<?php endif; ?>
  </div>
<?php endif; ?>
