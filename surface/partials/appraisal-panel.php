<?php
/**
 * INCREMENT / APPRAISAL → APPRAISAL RATINGS (owner, 8 Oct): pick the Performance
 * Appraisal criteria and the review period, download its rating sample, import
 * it (ratings finalised + CTC raised + increment logged), and export previous
 * CTC, rating, % per rating, amount increased and CTC after the appraisal.
 * Rendered on the generic register screen for the `increments` module.
 */
declare(strict_types=1);

use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;

require_once __DIR__ . '/appraisal-common.php';

$apAppraisal = new PerformanceAppraisal($customisation);
$apFyStart = financialYearStart($customisation);
$apPeriods = (new Performance(__DIR__ . '/../../config/performance.json'))->periods(date('Y-m-d'));
$apChoices = appraisalChoices($apAppraisal, $apAppraisal->fys(), $apPeriods, $apFyStart);
$apCycle = (string) ($_GET['pa_cycle'] ?? '');
$apCycleOk = false;

foreach ($apChoices as $c) {
    $apCycleOk = $apCycleOk || $c['fy'] . '|' . $c['box']['id'] === $apCycle;
}

$apCycle = $apCycleOk ? $apCycle : ($apChoices === [] ? '' : $apChoices[0]['fy'] . '|' . $apChoices[0]['box']['id']);
[$apFy] = array_pad(explode('|', $apCycle, 2), 1, '');
$apPeriod = (string) ($_GET['pa_period'] ?? '');
$apPeriodsOf = [];

foreach ($apChoices as $c) {
    $apPeriodsOf[$c['fy'] . '|' . $c['box']['id']] = $c['periods'];
}

in_array($apPeriod, $apPeriodsOf[$apCycle] ?? [], true) || $apPeriod = (string) (($apPeriodsOf[$apCycle] ?? [])[0] ?? '');
$apMayImport = $may('employee.update') && $may('employee.salary.update');
$apSeeMoney = $may('employee.salary.view');
$apFilter = orgFilter();
$apRows = $apFy === '' || $apPeriod === '' || !$apSeeMoney ? []
    : appraisalResults($store, $apAppraisal, orgFilterApply($store, $scope->only(liveRows($store, 'employees'), $may('employee.list')), $apFilter), $apFy, $apPeriod);
$apMoney = static fn (string $v): string => $v === '' ? '' : SmartPRS\Surface\Amount::of($v)->format();
?>
  <div class="card" id="appraisal-ratings">
    <div class="card-header"><div>
      <h3 style="font-size:15px;font-weight:700"><i class="fas fa-star-half-stroke" style="color:var(--accent)"></i> Appraisal ratings &mdash; import &amp; export</h3>
      <p class="note" style="margin:0">Pick the Performance Appraisal criteria and the review period, download the sample, fill in each employee's rating and upload it.
        Each rating finalises that employee's Performance review, raises CTC by the % the rating carries, and logs the increment here with its effective date.
        Payroll pays months before the effective date on the old CTC.</p></div></div>
<?php if ($apChoices === []): ?>
    <p class="problem" style="margin:0">No appraisal criteria yet. Add one under Organization Configuration &rarr; <a href="?r=performance-appraisal">Performance Appraisal</a> first.</p>
<?php else: ?>
    <form method="post" enctype="multipart/form-data" id="ap-form" style="margin:0">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="back" value="increments">
<?php foreach ($apFilter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
      <div style="display:grid;grid-template-columns:minmax(260px,2.2fr) minmax(140px,.8fr) minmax(150px,.8fr);gap:12px;align-items:end">
        <div><label for="ap-cycle">Appraisal criteria (Performance Appraisal)</label>
          <select id="ap-cycle" name="cycle" onchange="apGo()">
<?php foreach ($apChoices as $c): $v = $c['fy'] . '|' . $c['box']['id']; ?>
            <option value="<?= e($v) ?>" data-periods="<?= e(implode(' ', $c['periods'])) ?>" data-effective="<?= e(appraisalDefaultEffective($c['fy'], $apFyStart)) ?>"<?= $v === $apCycle ? ' selected' : '' ?>><?= e($c['label']) ?></option>
<?php endforeach; ?>
          </select></div>
        <div><label for="ap-period">Review period</label>
          <select id="ap-period" name="period" onchange="apGo()">
<?php foreach ($apPeriodsOf[$apCycle] ?? [] as $p): ?><option<?= $p === $apPeriod ? ' selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
<?php if (($apPeriodsOf[$apCycle] ?? []) === []): ?><option value="">No review period ends inside these dates</option><?php endif; ?>
          </select></div>
        <div><label for="ap-eff">Effective date (rows left blank)</label>
          <input id="ap-eff" type="date" name="effective" value="<?= e($apFy === '' ? '' : appraisalDefaultEffective($apFy, $apFyStart)) ?>"></div>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border)">
        <button class="btn btn-outline" type="submit" name="action" value="appraisal-sample" formnovalidate><i class="fas fa-file-excel" style="color:#15803d"></i> Download rating sample</button>
<?php if ($apMayImport): ?>
        <span style="display:inline-flex;gap:6px;align-items:center;border:1px solid var(--border);border-radius:9px;padding:4px 6px">
          <input type="file" name="sheet" accept=".xlsx,.xls,.csv" style="max-width:220px">
          <button class="btn btn-primary" type="submit" name="action" value="appraisal-import" onclick="if(!document.querySelector('#ap-form input[name=sheet]').files.length){alert('Choose the filled-in sample first.');return false}return confirm('Import these ratings? Each rated employee\'s review is finalised and CTC raised.')"><i class="fas fa-upload"></i> Import ratings</button></span>
<?php endif; ?>
<?php if ($apSeeMoney): ?>
        <span style="margin-left:auto;display:inline-flex;gap:8px;align-items:center">
          <span style="font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b">Export</span>
          <button class="btn btn-outline" type="submit" name="action" value="appraisal-export" formnovalidate onclick="this.form.fmt.value='xlsx'"><i class="fas fa-file-excel" style="color:#15803d"></i> Excel</button>
          <button class="btn btn-outline" type="submit" name="action" value="appraisal-export" formnovalidate onclick="this.form.fmt.value='csv'"><i class="fas fa-file-csv"></i> CSV</button>
          <input type="hidden" name="fmt" value="xlsx"></span>
<?php endif; ?>
      </div>
    </form>
<?php if ($apSeeMoney && $apPeriod !== ''): ?>
    <div class="table-wrap" style="margin-top:14px"><table>
      <thead><tr><th>Employee</th><th>Company</th><th>Branch</th><th>Department</th><th>Designation</th><th>Rating</th>
        <th class="n">Previous CTC</th><th class="n">% increase</th><th class="n">Amount increased</th><th class="n">CTC after appraisal</th><th>Effective</th></tr></thead>
      <tbody>
<?php foreach (array_slice($apRows, 0, 500) as $r): ?>
      <tr><td><b><?= e($r['emp_code']) ?></b> &middot; <?= e($r['name']) ?></td><td><?= e($r['company']) ?></td><td><?= e($r['branch']) ?></td><td><?= e($r['department']) ?></td><td><?= e($r['designation']) ?></td>
        <td><span class="badge badge-gray"><?= e($r['rating']) ?></span><?= $r['description'] === '' ? '' : ' <span class="note">' . e($r['description']) . '</span>' ?></td>
        <td class="n"><?= e($apMoney($r['old'])) ?></td><td class="n"><?= e($r['pct']) ?>%</td><td class="n" style="color:#15803d"><?= e($apMoney($r['amount'])) ?></td>
        <td class="n"><b><?= e($apMoney($r['new'])) ?></b></td><td><?= e($r['effective']) ?></td></tr>
<?php endforeach; ?>
<?php if ($apRows === []): ?>
      <tr><td colspan="11" class="note">Nobody has been appraised for this criteria and period yet.</td></tr>
<?php endif; ?>
      </tbody></table></div>
<?php endif; ?>
    <script>
    function apGo() {
      var c = document.getElementById('ap-cycle'), p = document.getElementById('ap-period');
      var u = new URL(location.href); u.searchParams.set('pa_cycle', c.value);
      var ok = (c.selectedOptions[0].getAttribute('data-periods') || '').split(' ');
      u.searchParams.set('pa_period', ok.indexOf(p.value) >= 0 ? p.value : (ok[0] || ''));
      u.hash = 'appraisal-ratings'; location.href = u.toString();
    }
    </script>
<?php endif; ?>
  </div>
