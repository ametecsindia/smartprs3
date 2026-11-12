<?php declare(strict_types=1); if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Statutory Bonus</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's statutory bonus report: per employee, the months of the financial
// year that qualify, the wage counted and the bonus at both ends of the band.
// Ceilings and the band are config/statutory-bonus.json; the bonus wage per
// month is read from the closed payroll runs, never projected.
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;

$cfg = StatutoryReports::config(__DIR__ . '/../../config/statutory-bonus.json');
$fyStart = (int) $cfg['financial_year_start_month'];
$fy = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['fy'] ?? '')) === 1 ? (string) $_GET['fy'] : StatutoryReports::financialYear(date('Y-m'), $fyStart);
$fyFirst = (int) substr($fy, 0, 4);
$fromPeriod = sprintf('%04d-%02d', $fyFirst, $fyStart);
$toPeriod = (new DateTimeImmutable($fromPeriod . '-01'))->modify('+11 months')->format('Y-m');
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$filter = orgFilter();
$inView = orgFilterApply($store, $people, $filter);
$runs = array_values(array_filter($register->runs(), static fn (array $r): bool
    => strcmp((string) $r['period'], $fromPeriod) >= 0 && strcmp((string) $r['period'], $toPeriod) <= 0));
// The flagged codes per run, from the structure in force at that period's end.
$codesByPeriod = [];

foreach ($runs as $run) {
    try {
        $codesByPeriod[(string) $run['period']] = StatutoryReports::flaggedCodes(
            $structure->versionOn((new DateTimeImmutable((string) $run['period'] . '-01'))->format('Y-m-t')), (string) $cfg['bonus_wage_flag']);
    } catch (\Throwable) {
        // No structure in force for that month: the run itself could not have
        // been closed against one, so there is nothing flagged to read.
        $codesByPeriod[(string) $run['period']] = [];
    }
}

$rows = [];
$totalMin = Money::zero();
$totalMax = Money::zero();

foreach ($inView as $e) {
    $code = Employment::code($e);
    $wages = [];

    foreach ($runs as $run) {
        if (isset($run['rows'][$code])) {
            $wages[(string) $run['period']] = Money::ofMinor(StatutoryReports::linesMinor((array) $run['rows'][$code], $codesByPeriod[(string) $run['period']]));
        }
    }

    ksort($wages);
    $b = StatutoryReports::bonus($cfg, $wages);
    $totalMin = $totalMin->plus($b['at_min']);
    $totalMax = $totalMax->plus($b['at_max']);
    $rows[] = ['e' => $e, 'code' => $code, 'b' => $b];
}

$fyOptions = [];

for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--) {
    $fyOptions[] = StatutoryReports::financialYear(sprintf('%04d-%02d', $y, $fyStart), $fyStart);
}
?>
<div class="page-header">
  <div><h2>Statutory Bonus</h2><p>Payment of Bonus Act working for <?= e($fy) ?> (<?= e($fromPeriod) ?> to <?= e($toPeriod) ?>): a month counts when the bonus wage is at most <?= e(Money::of((string) $cfg['eligibility_wage_ceiling'])->format()) ?>, the wage counted is capped at <?= e(Money::of((string) $cfg['calculation_wage_ceiling'])->format()) ?>, and the bonus is between <?= e((string) $cfg['min_percent']) ?>% and <?= e((string) $cfg['max_percent']) ?>% of the year's counted wage. Every figure is <code>config/statutory-bonus.json</code>.</p></div>
</div>
<?php // ONE FORM (owner, 13 Oct): this screen's own choice sits in the filter bar — one Show, no second form. ?>
<?= orgFilterBar('statutory-bonus', $filter, orgFilterOptions($store, $people), [], 'Show', null, null, null,
    '<div><label for="fy">Financial year</label><select id="fy" name="fy">' . implode('', array_map(static fn ($o): string => '<option' . ($o === $fy ? ' selected' : '') . '>' . e((string) $o) . '</option>', $fyOptions)) . '</select></div>') ?>
<div class="card">
  <div class="row" style="gap:26px;margin-bottom:12px">
    <div><span class="note">Closed runs in <?= e($fy) ?></span><br><b style="font-size:22px"><?= count($runs) ?></b></div>
    <div><span class="note">At <?= e((string) $cfg['min_percent']) ?>%</span><br><b style="font-size:22px;font-family:var(--mono)"><?= e($totalMin->format()) ?></b></div>
    <div><span class="note">At <?= e((string) $cfg['max_percent']) ?>%</span><br><b style="font-size:22px;font-family:var(--mono)"><?= e($totalMax->format()) ?></b></div>
  </div>
<?php if ($runs === []): ?>
  <div class="problem">No payroll run is closed in <?= e($fy) ?>, so no month qualifies yet. The bonus wage is read from closed runs only — nothing here is projected from CTC, because a projection looks exactly like a liability once it is on a screen.</div>
<?php elseif ($rows === []): ?>
  <p class="note">No employees in view.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Code</th><th>Employee</th><th class="n">Months eligible</th><th class="n">Wage counted</th><th class="n">At <?= e((string) $cfg['min_percent']) ?>%</th><th class="n">At <?= e((string) $cfg['max_percent']) ?>%</th><th>Working</th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><b><?= e($r['code']) ?></b></td>
        <td><?= e((string) ($r['e']['name'] ?? '')) ?></td>
        <td class="n"><?= (int) $r['b']['eligible_months'] ?> / <?= count($r['b']['months']) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($r['b']['base']->format()) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($r['b']['at_min']->format()) ?></td>
        <td class="n" style="font-family:var(--mono)"><b><?= e($r['b']['at_max']->format()) ?></b></td>
        <td class="note" style="white-space:normal;font-size:11px"><?= e($r['b']['working']) ?><?php if ($r['b']['months'] !== []): ?><details><summary>months</summary><?php foreach ($r['b']['months'] as $m): ?><?= e($m['period']) ?>: <?= e($m['wage']->format()) ?> → <?= $m['eligible'] ? e($m['counted']->format()) : 'over the ceiling' ?><br><?php endforeach; ?></details><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
  <p class="note">The bonus wage of a month is the closed run's lines flagged <code><?= e((string) $cfg['bonus_wage_flag']) ?></code> in the salary structure in force then. An employee with fewer than <?= (int) $cfg['min_working_days'] ?> working days in the year is outside the Act — that is not judged here, because attendance is the Time section's word, not payroll's. Record what was declared and paid on the Bonus Register.</p>
</div>
