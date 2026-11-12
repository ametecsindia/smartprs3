<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Gratuity</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// GRATUITY — Code on Social Security 2020 s.53 (in force 21-11-2025): what each employee would be paid on
// exit as at the date chosen. Every rule (5 full years / fixed-term 1 year / death-disablement, part year MORE
// than six months, 15/26, the notified cap, wages = Basic + DA + retaining allowance with the 50% add-back,
// the monthly RATE last drawn) is config/gratuity.json; each row prints its own working.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;

$cfg = StatutoryReports::config(__DIR__ . '/../../config/gratuity.json');
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['asof'] ?? '')) === 1 ? (string) $_GET['asof'] : date('Y-m-d');
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$filter = orgFilter();
$inView = orgFilterApply($store, $people, $filter);
$problem = '';
$version = ['components' => []];
$basis = 'annual';

try {
    $version = $structure->versionOn($asOf);
    $basis = $structure->contractedCostBasis($asOf);
} catch (\Throwable $ex) {
    $problem = $ex->getMessage();
}

$runs = $problem === '' ? $register->runs() : [];
$rows = [];
$totalMinor = 0;
$eligibleCount = 0;

foreach ($inView as $e) {
    $code = Employment::code($e);
    $drawn = StatutoryReports::lastDrawnWages($code, Employment::monthlyGross($e, $basis), $runs, $version, $cfg, $asOf);
    $g = StatutoryReports::gratuity($cfg, $drawn['wage'], Employment::joinedOn($e), $asOf, (string) ($e['employment_type'] ?? ''), (string) ($e['exit_reason'] ?? ''));
    $totalMinor += $g['amount']->minor;
    $eligibleCount += $g['eligible'] ? 1 : 0;
    $rows[] = ['e' => $e, 'code' => $code, 'drawn' => $drawn, 'g' => $g];
}
?>
<div class="page-header">
  <div><h2>Gratuity</h2><p>Payable on exit as at <?= e(SmartPRS\Surface\Dates::dmy($asOf)) ?>, under the Code on Social Security 2020 (s.53, in force 21-11-2025): eligible after <?= (int) $cfg['eligibility_years'] ?> full years of continuous service (fixed-term: <?= (int) ($cfg['fixed_term']['eligibility_years'] ?? 1) ?> year; death / disablement: no minimum); wages last drawn (Basic + DA + retaining allowance, with the 50% add-back) × <?= (int) $cfg['days_factor']['numerator'] ?>/<?= (int) $cfg['days_factor']['denominator'] ?> × completed years, a part year counting only when more than <?= (int) $cfg['part_year_more_than_months'] ?> months; maximum <?= e(SmartPRS\Platform\Support\Money::of((string) $cfg['statutory_cap'])->format()) ?>. Rules: <code>config/gratuity.json</code>.</p></div>
</div>
<?php // ONE FORM (owner, 13 Oct): this screen's own choice sits in the filter bar — one Show, no second form. ?>
<?= orgFilterBar('gratuity', $filter, orgFilterOptions($store, $people), [], 'Recompute', null, null, null,
    '<div><label for="asof">As at</label><input type="date" id="asof" name="asof" value="' . e($asOf) . '"></div>') ?>
<?php if ($problem !== ''): ?>
<div class="card"><div class="problem"><?= e($problem) ?></div></div>
<?php else: ?>
<div class="card">
  <div class="row" style="gap:26px;margin-bottom:12px">
    <div><span class="note">Employees in view</span><br><b style="font-size:22px"><?= count($rows) ?></b></div>
    <div><span class="note">Eligible</span><br><b style="font-size:22px"><?= $eligibleCount ?></b></div>
    <div><span class="note">Accrued liability</span><br><b style="font-size:22px;font-family:var(--mono)"><?= e(SmartPRS\Platform\Support\Money::ofMinor($totalMinor)->format()) ?></b></div>
  </div>
<?php if ($rows === []): ?>
  <p class="note">No employees in view.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Code</th><th>Employee</th><th>Joined</th><th class="n">Service</th><th class="n">Wages last drawn (monthly rate)</th><th class="n">Gratuity</th><th>Working</th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= e($r['code']) ?></b></td>
        <td><?= e((string) ($r['e']['name'] ?? '')) ?></td>
        <td class="note"><?= e(SmartPRS\Surface\Dates::dmy(Employment::joinedOn($r['e'], ''))) ?></td>
        <td class="n"><?= e($r['g']['service']) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($r['drawn']['wage']->format()) ?><br><span class="note" style="font-family:inherit;white-space:normal"><?= e(SmartPRS\Surface\Dates::dmy($r['drawn']['basis'])) ?></span></td>
        <td class="n" style="font-family:var(--mono)"><?php if ($r['g']['eligible']): ?><b><?= e($r['g']['amount']->format()) ?></b><?= $r['g']['capped'] ? ' <span class="badge badge-amber">capped</span>' : '' ?><?php else: ?><span class="badge badge-gray">not eligible</span><?php endif; ?></td>
        <td class="note" style="white-space:normal;font-size:11px"><?= e(SmartPRS\Surface\Dates::dmy($r['g']['working'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  <p class="note">Eligibility is counted in full years (4 years 11 months is not five years<?= ($cfg['count_240_days_in_final_year'] ?? false) ? ', except 4 years + 240 days, which this employer counts as five' : '' ?>). For the amount, a part year counts only when it is more than <?= (int) $cfg['part_year_more_than_months'] ?> months (exactly six does not). The same figure is paid by the employee's Full &amp; Final, within <?= (int) ($cfg['payment_due_days'] ?? 30) ?> days of exit.</p>
</div>
<?php endif; ?>
