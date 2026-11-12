<?php declare(strict_types=1);
// DATASETS (SmartPRS 2's Reports screen): pick a dataset, narrow by Organization
// Setup and period, preview, export to Excel or CSV. config/report-datasets.json
// declares the datasets and their columns.
$params = $_GET;
include __DIR__ . '/../partials/report-datasets-data.php';
$dsPreview = (int) ($dsCfg['preview_rows'] ?? 200);
?>
<div class="page-header">
  <div><h2>Datasets</h2><p>Preview any dataset and export it — the rows that belong to the employees in your scope and the filters, nothing else.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=reports"><i class="fas fa-chart-pie"></i> Reports</a>
<?php if ($dsWhy === ''): ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="report-dataset-export"><input type="hidden" name="as" value="xlsx"><?= keepHidden($dsKeep + orgFilterKeep($dsFilter)) ?><button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Excel</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="report-dataset-export"><input type="hidden" name="as" value="csv"><?= keepHidden($dsKeep + orgFilterKeep($dsFilter)) ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form>
<?php endif; ?></div>
</div>
<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0 0 10px"><input type="hidden" name="r" value="report-datasets"><?= keepHidden(orgFilterKeep($dsFilter)) ?>
    <div><label for="ds_d">Dataset</label><select id="ds_d" name="d" onchange="this.form.submit()"><?php foreach ($dsAll as $dk => $dd): ?><option value="<?= e((string) $dk) ?>"<?= $ds !== null && $dk === $ds['key'] ? ' selected' : '' ?><?= $may((string) $dd['permission']) ? '' : ' disabled' ?>><?= e((string) $dd['label']) ?><?= $may((string) $dd['permission']) ? '' : ' (not yours to open)' ?></option><?php endforeach; ?></select></div>
    <div><button class="go" type="submit">Show</button></div></form>
<?php if ($ds !== null && ($ds['date_column'] ?? '') !== ''): ?>
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:6px">Period</div>
  <?= periodBar('report-datasets', $dsPeriod ?? reportPeriod(['p' => 'month'], financialYearStart($customisation), date('Y-m-d')), ['d' => (string) $ds['key']] + orgFilterKeep($dsFilter)) ?>
  <p class="note" style="margin:6px 0 10px"><?= $dsPeriod === null ? 'Showing every period — pick one above to narrow by <code>' . e((string) $ds['date_column']) . '</code>.' : '<a href="?r=report-datasets&amp;d=' . e((string) $ds['key']) . orgFilterQuery($dsFilter) . '">Show every period</a>' ?></p>
<?php endif; ?>
  <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin:14px 0 6px">Who</div>
  <?= orgFilterBar('report-datasets', $dsFilter, orgFilterOptions($store, $scope->only(liveRows($store, 'employees'), $may('employee.list'))), $dsKeep) ?>
</div>
<div class="card">
<?php if ($dsWhy !== ''): ?>
  <div class="problem" style="margin:0"><?= e($dsWhy) ?></div>
<?php else: ?>
  <p class="note" style="margin:0 0 10px"><b><?= e((string) $ds['label']) ?></b>: <b><?= count($dsRows) ?></b> row(s)<?= count($dsRows) > $dsPreview ? ' · showing the first ' . $dsPreview . ' (the full set is in the Excel / CSV)' : '' ?><?= $dsPeriod !== null ? ' · ' . e($dsPeriod['label']) : '' ?><?= implode('', array_map(static fn (string $k): string => $dsFilter[$k] !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . e($dsFilter[$k]) : '', array_keys($dsFilter))) ?></p>
<?php if ($dsRows === []): ?>
  <p class="note">No data for this selection. That is an empty result, not a failure.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <tr><?php foreach ($dsColumns as $dc => $dl): ?><th><?= e((string) $dl) ?></th><?php endforeach; ?></tr>
<?php foreach (array_slice($dsRows, 0, $dsPreview) as $dsLine): ?>
    <tr><?php foreach ($dsLine as $i => $c): ?><td<?= isset($dsMoney[array_keys($dsColumns)[$i]]) ? ' class="n"' : '' ?>><?= $c === '' ? '—' : e((string) $c) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
  </table></div>
<?php endif; ?>
<?php endif; ?>
</div>
