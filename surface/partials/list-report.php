<?php declare(strict_types=1);
/**
 * ONE LIST REPORT, DECLARED IN config/field-reports.json. Included by
 * surface/screens/report-<key>.php with $reportKey set. The Organization Setup
 * filter (ALL first) scopes the people; the month picker scopes the rows; the
 * CSV (surface/actions/field-report-csv.php) is exactly the table shown.
 *
 * The figures come from FieldReports — pure functions over the rows the
 * tenant-scoped store returned — so what the screen prints and what the test
 * checks is the same code path.
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\FieldReports;

$params = $_GET;
include __DIR__ . '/list-report-data.php';

if ($report === null): ?>
<div class="card"><div class="problem">No report <code><?= e($reportKey) ?></code> is declared in <code>config/field-reports.json</code>.</div></div>
<?php return; endif;

$permission = (string) $report['permission'];

if ($result === null): ?>
<div class="page-header"><div><h2><?= e((string) $report['label']) ?></h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], $permission)->summary) ?></div></div>
<?php return; endif;

$cell = static fn (mixed $c): string => $c instanceof Money ? e($c->format()) : e((string) $c);
// THE CHART: every counted column of the table as a stacked bar per row (a
// money register is read, not drawn). Decimal day counts are drawn to the day.
$chartSeries = [];
$chartLabels = [];

if (!($report['money'] ?? false) && $result['rows'] !== []) {
    $chartLabels = array_map(static fn (array $r): string => (string) $r[0] . (isset($r[1]) && !is_int($r[1]) && !is_numeric((string) $r[1]) ? ' · ' . (string) $r[1] : ''), array_slice($result['rows'], 0, 40));

    foreach ($result['columns'] as $i => $name) {
        if ($i === 0 || (isset($result['rows'][0][$i]) && !is_int($result['rows'][0][$i]) && preg_match('/^\d+(\.\d+)?$/', (string) $result['rows'][0][$i]) !== 1)) {
            continue;
        }

        $chartSeries[(string) $name] = array_map(static fn (array $r): int => is_int($r[$i] ?? 0) ? (int) ($r[$i] ?? 0) : (int) explode('.', (string) ($r[$i] ?? '0'))[0], array_slice($result['rows'], 0, 40));
    }
}

// A trailing total column (the sum of the others on every row) would draw every bar twice as long.
if (count($chartSeries) > 1) {
    $last = array_key_last($chartSeries);
    $others = array_slice($chartSeries, 0, -1, true);
    $isSum = true;

    foreach ($chartSeries[$last] as $i => $v) {
        $isSum = $isSum && $v === array_sum(array_column($others, $i));
    }

    $isSum && array_pop($chartSeries);
}

$chartPalette = ['#16a34a', '#2563eb', '#f59e0b', '#dc2626', '#a855f7', '#0891b2', '#94a3b8', '#e11d48', '#65a30d', '#7c3aed'];
$chartColours = [];

foreach (array_keys($chartSeries) as $i => $name) {
    $chartColours[$name] = $chartPalette[$i % count($chartPalette)];
}

$showChart = (string) ($params['chart'] ?? '1') !== '0';
$exportHidden = static fn (string $as): string => '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="field-report-csv"><input type="hidden" name="as" value="' . $as . '"><input type="hidden" name="report" value="' . e($reportKey) . '">' . keepHidden($keep + array_combine(array_map(static fn (string $k): string => 'f_' . $k, array_keys($filter)), $filter));
?>
<div class="page-header">
  <div><h2><?= e((string) $report['label']) ?></h2><p><?= e((string) $report['question']) ?> Scoped by Organization Setup; the Excel and the CSV are exactly this table.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=reports"><i class="fas fa-chart-column"></i> Reports</a>
    <form method="post" style="display:inline"><?= $exportHidden('xlsx') ?><button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Excel</button></form>
    <form method="post" style="display:inline"><?= $exportHidden('csv') ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form></div>
</div>
<div class="card">
<?php // ONE FORM (owner, 13 Oct): period, month, group and who together, one Show.
      $lrExtra = (($report['month'] ?? false) ? '<div><label for="month">Month</label><input type="month" id="month" name="month" value="' . e($month) . '"></div>' : '')
          . ($groups !== [] ? '<div><label for="g">By</label><select id="g" name="g">' . implode('', array_map(static fn ($g): string => '<option value="' . e((string) $g) . '"' . ($g === $group ? ' selected' : '') . '>' . e(ucfirst((string) $g)) . '</option>', $groups)) . '</select></div>' : ''); ?>
<?= orgFilterBar($route, $filter, orgFilterOptions($store, $people), array_diff_key($keep, ['g' => 1, 'month' => 1]) + ($showChart ? [] : ['chart' => '0']), 'Show', null, $period, null, $lrExtra) ?>
</div>
<?php if ($reportKey === 'salary-register' && ($result['run'] ?? null) === null): ?>
<div class="card"><div class="problem" style="margin:0">No closed payroll run for <?= e($month) ?>. The register reads the closed run's own lines and shows nothing it did not compute — a projected salary looks exactly like a paid one.</div></div>
<?php endif; ?>
<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center">
    <p class="note" style="margin:0"><b><?= count($result['rows']) ?></b> row(s) from <b><?= count($inView) ?></b> of <?= count($people) ?> live employee(s)<?= $period !== null ? ' · ' . e($period['label']) . ' (' . e($period['from']) . ' to ' . e($period['to']) . ')' : (($report['month'] ?? false) ? ' · ' . e($month) : '') ?>.</p>
<?php if ($chartSeries !== []): ?>
    <a class="btn btn-outline btn-sm" href="?r=<?= e($route) ?><?= orgFilterQuery($filter) ?><?php foreach ($keep as $k => $v): ?>&amp;<?= e($k) ?>=<?= e(rawurlencode((string) $v)) ?><?php endforeach; ?>&amp;chart=<?= $showChart ? '0' : '1' ?>"><i class="fas fa-chart-bar"></i> <?= $showChart ? 'Hide chart' : 'Show chart' ?></a>
<?php endif; ?>
  </div>
<?php if ($showChart && $chartSeries !== []): ?>
  <div style="overflow-x:auto;margin:10px 0"><?= chartBars($chartLabels, $chartSeries, $chartColours) ?></div>
<?php if (count($result['rows']) > count($chartLabels)): ?><p class="note">The chart draws the first <?= count($chartLabels) ?> rows; the table and the exports hold all <?= count($result['rows']) ?>.</p><?php endif; ?>
<?php endif; ?>
<?php if ($result['rows'] === []): ?>
  <p class="note">No rows matched. That is an empty result, not a failure.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <tr><?php foreach ($result['columns'] as $c): ?><th><?= e((string) $c) ?></th><?php endforeach; ?></tr>
<?php foreach ($result['rows'] as $row): ?>
    <tr><?php foreach ($row as $c): ?><td<?= is_int($c) || $c instanceof Money ? ' class="n"' : '' ?>><?= $cell($c) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
<?php if ($result['totals'] !== null): ?>
    <tr><?php foreach ($result['totals'] as $c): ?><th<?= is_int($c) || $c instanceof Money ? ' class="n"' : '' ?>><?= $cell($c) ?></th><?php endforeach; ?></tr>
<?php endif; ?>
  </table></div>
<?php endif; ?>
</div>
