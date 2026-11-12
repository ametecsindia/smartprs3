<?php declare(strict_types=1);
// ATTENDANCE SUMMARY, THE OWNER'S WAY (9 Sep): any period (month / year /
// financial year / YTD / MTD / custom dates), every Organization Setup filter,
// grouped by employee or by any level, in the columns the viewer ticks, drawn as
// a bar chart, and exported to Excel or CSV as exactly the table shown. The
// figures are the muster's — the same settlement the register pays from — and
// config/attendance-summary.json declares every column.
if (!$may('attendance.report.view')): ?>
<div class="page-header"><div><h2>Attendance summary</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'attendance.report.view')->summary) ?></div></div>
<?php return; endif;

$params = $_GET;
include __DIR__ . '/../partials/attendance-summary-data.php';
$asRoute = 'report-attendance-summary';
$asKeep = ['p' => $asPeriod['mode'], 'month' => $asPeriod['month'], 'year' => $asPeriod['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $asPeriod['from'], 'to' => $asPeriod['to'], 'g' => $asGroupBy, 'c' => $asChosen];
$asShowChart = (string) ($params['chart'] ?? '1') !== '0';
$asShowChart || $asKeep['chart'] = '0';
$asFilterKeep = [];

foreach ($asFilter as $k => $v) {
    $v !== '' && $asFilterKeep['f_' . $k] = $v;
}

$asHidden = static fn (array $fields): string => keepHidden($fields);
$asChart = $asSummary->chart($asFolded['raw']);
?>
<div class="page-header">
  <div><h2>Attendance summary</h2><p>Per employee — or per company, branch, department, designation or team — the days of each status, payable days and hours over any period. The Excel and CSV are exactly this table.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=reports"><i class="fas fa-chart-column"></i> Reports</a> <a class="btn btn-outline" href="?r=attendance-report&amp;from=<?= e($asPeriod['from']) ?>&amp;to=<?= e($asPeriod['to']) ?><?= orgFilterQuery($asFilter) ?>"><i class="fas fa-table-cells"></i> Muster</a>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="attendance-summary-export"><input type="hidden" name="as" value="xlsx"><?= $asHidden($asKeep + $asFilterKeep) ?><button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Excel</button></form>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="attendance-summary-export"><input type="hidden" name="as" value="csv"><?= $asHidden($asKeep + $asFilterKeep) ?><button class="btn btn-outline" type="submit"><i class="fas fa-file-csv"></i> CSV</button></form></div>
</div>

<div class="card">
  <?= orgFilterBar($asRoute, $asFilter, orgFilterOptions($store, $asPeople), array_diff_key($asKeep, ['g' => 1]), 'Show', null, $asPeriod, null,
      '<div><label for="as_g">Group by</label><select id="as_g" name="g">' . implode('', array_map(static fn ($gk, $gl): string => '<option value="' . e((string) $gk) . '"' . ($gk === $asGroupBy ? ' selected' : '') . '>' . e((string) $gl) . '</option>', array_keys($asGroups), $asGroups)) . '</select></div>') ?>
</div>

<div class="card">
  <form method="get" style="margin:0"><input type="hidden" name="r" value="<?= e($asRoute) ?>"><?= $asHidden(array_diff_key($asKeep, ['c' => 1, 'chart' => 1]) + $asFilterKeep) ?>
    <div style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-bottom:6px">What to show</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px 16px">
<?php foreach ($asSummary->columns() as $ck => $col): ?>
      <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal"><input type="checkbox" name="c[]" value="<?= e($ck) ?>"<?= in_array($ck, $asChosen, true) ? ' checked' : '' ?>> <?= e((string) $col['label']) ?></label>
<?php endforeach; ?>
      <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal"><input type="checkbox" name="chart" value="1"<?= $asShowChart ? ' checked' : '' ?>> Chart</label>
    </div>
    <div style="margin-top:8px"><button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-sliders"></i> Apply</button> <span class="note">Nothing ticked shows the default columns.</span></div>
    <script>(function(f){f.addEventListener('submit',function(){var c=f.querySelector('input[name=chart][type=checkbox]');if(!c.checked){var h=document.createElement('input');h.type='hidden';h.name='chart';h.value='0';f.appendChild(h);}});})(document.currentScript.parentNode)</script>
  </form>
</div>

<div class="card">
  <p class="note" style="margin:0 0 10px"><b><?= count($asFolded['rows']) ?></b> row(s) · <b><?= count($asInView) ?></b> of <?= count($asPeople) ?> employee(s) · <b><?= e($asPeriod['label']) ?></b> (<?= e($asPeriod['from']) ?> to <?= e($asPeriod['to']) ?>)<?= implode('', array_map(static fn (string $k): string => $asFilter[$k] !== '' ? ' · ' . ORG_FILTERS[$k] . ': ' . e($asFilter[$k]) : '', array_keys($asFilter))) ?> · by <?= e((string) $asGroups[$asGroupBy]) ?></p>
<?php if ($asFolded['rows'] === []): ?>
  <p class="note">No rows matched. That is an empty result, not a failure.</p>
<?php else: ?>
<?php if ($asShowChart): ?>
  <div style="overflow-x:auto;margin-bottom:12px"><?= chartBars($asChart['labels'], $asChart['series'], $asChart['colours']) ?></div>
<?php if (count($asFolded['raw']) > count($asChart['labels'])): ?><p class="note">The chart draws the first <?= count($asChart['labels']) ?> rows; the table and the exports hold all <?= count($asFolded['raw']) ?>.</p><?php endif; ?>
<?php endif; ?>
  <div class="table-wrap"><table>
    <tr><?php foreach ($asFolded['columns'] as $c): ?><th><?= e((string) $c) ?></th><?php endforeach; ?></tr>
<?php foreach ($asFolded['rows'] as $row): ?>
    <tr><?php foreach ($row as $i => $c): ?><td<?= $i > 1 || ($asGroupBy !== 'employee' && $i === 1) ? ' class="n"' : '' ?>><?= e((string) $c) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
    <tr><?php foreach ($asFolded['totals'] as $i => $c): ?><th<?= $i > 1 ? ' class="n"' : '' ?>><?= e((string) $c) ?></th><?php endforeach; ?></tr>
  </table></div>
<?php endif; ?>
</div>
