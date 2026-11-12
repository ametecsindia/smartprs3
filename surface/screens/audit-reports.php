<?php declare(strict_types=1);
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\StatutoryReports;

$audit_cfg = StatutoryReports::config(__DIR__ . '/../../config/audit-reports.json');
$auditPermission = (string) $audit_cfg['permission'];
if (!$may($auditPermission)): ?>
<div class="page-header"><div><h2>Audit Reports</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], $auditPermission)->summary) ?></div></div>
<?php return; endif; ?>
<?php
// AUDIT REPORTS — SmartPRS 2's screen (owner, 30 Sep: "use PRS2 Audit report
// format and same logic"): RBI recovery-agent compliance audit reports on the
// employer's letterhead, one per row or one bulk file with a summary page.
// Filters Company / Department / Team / Search, as there. The report itself is
// surface/actions/agent-audit.php; the checks are surface/AgentAudit.php.
$arPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$arF = ['company' => trim((string) ($_GET['company'] ?? '')), 'department' => trim((string) ($_GET['department'] ?? '')),
    'team' => trim((string) ($_GET['team'] ?? '')), 'q' => trim((string) ($_GET['q'] ?? ''))];
$arRows = [];
foreach ($arPeople as $e) {
    $row = ['id' => (string) $e['id'], 'name' => (string) ($e['name'] ?? ''), 'code' => Employment::code($e),
        'company' => orgValueOf($store, $e, 'company'), 'department' => orgValueOf($store, $e, 'department'),
        'team' => orgValueOf($store, $e, 'team'), 'designation' => orgValueOf($store, $e, 'designation')];
    if (($arF['company'] !== '' && $row['company'] !== $arF['company']) || ($arF['department'] !== '' && $row['department'] !== $arF['department'])
        || ($arF['team'] !== '' && $row['team'] !== $arF['team'])
        || ($arF['q'] !== '' && mb_stripos($row['name'] . ' ' . $row['code'], $arF['q']) === false)) {
        continue;
    }
    $arRows[] = $row;
}
usort($arRows, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
$arNames = orgNames($store);
$arList = static fn (string $k): array => array_values(array_unique(array_filter(array_map('strval', (array) ($arNames[$k] ?? [])))));
$arMax = 150; // SmartPRS 2: "Maximum 150 agents per bulk report"
?>
<div class="page-header">
  <div><h2>Audit Reports</h2>
    <p>RBI recovery-agent compliance audit reports on your letterhead — single (per row) or one bulk file with a summary page. <b id="ar-inview"><?= count($arRows) ?></b> employee(s) in view, <b id="ar-sel">0</b> selected.</p></div>
</div>
<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0;flex-wrap:wrap"><input type="hidden" name="r" value="audit-reports">
<?php foreach (['company' => ['Company', 'companies'], 'department' => ['Department', 'departments'], 'team' => ['Team', 'teams']] as $k => [$label, $src]): ?>
    <div><label for="ar_<?= $k ?>"><?= $label ?></label><select id="ar_<?= $k ?>" name="<?= $k ?>" onchange="this.form.submit()"><option value="">All</option><?php foreach ($arList($src) as $o): ?><option<?= $o === $arF[$k] ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
    <div><label for="ar_q">Search</label><input id="ar_q" name="q" value="<?= e($arF['q']) ?>" placeholder="Name or code..." onchange="this.form.submit()" style="width:200px"></div>
  </form>
</div>
<form method="post" id="ar-form" target="_blank">
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="agent-audit">
  <input type="hidden" name="company" value="<?= e($arF['company']) ?>">
  <div class="card">
    <div class="row" style="gap:8px;margin:0 0 12px;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary" name="mode" value="selected" id="ar-gen-sel"><i class="fas fa-file-pdf"></i> Generate selected (<span>0</span>)</button>
      <button type="submit" class="btn btn-outline" name="mode" value="all" id="ar-gen-all"><i class="fas fa-layer-group"></i> Generate all filtered (<?= count($arRows) ?>)</button>
      <button type="submit" class="btn btn-outline" name="mode" value="sample" formnovalidate><i class="fas fa-eye"></i> View Sample Report</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th style="width:28px"><input type="checkbox" id="ar-all" title="Select all in view"></th><th>Employee</th><th>Code</th><th>Company</th><th>Department</th><th>Team</th><th>Designation</th><th>Audit</th></tr></thead>
      <tbody>
<?php if ($arRows === []): ?><tr><td colspan="8" class="note" style="text-align:center;padding:24px">No employees match this filter.</td></tr><?php endif; ?>
<?php foreach ($arRows as $r): ?>
        <tr>
          <td><input type="checkbox" class="ar-one" name="ids[]" value="<?= e($r['id']) ?>"></td>
          <td><b><?= e($r['name'] ?: '—') ?></b></td>
          <td><code><?= e($r['code'] ?: '—') ?></code></td>
          <td class="note"><?= e($r['company'] ?: '—') ?></td>
          <td class="note"><?= e($r['department'] ?: '—') ?></td>
          <td class="note"><?= e($r['team'] ?: '—') ?></td>
          <td class="note"><?= e($r['designation'] ?: '—') ?></td>
          <td><button type="submit" class="btn btn-outline btn-sm" name="one" value="<?= e($r['id']) ?>"><i class="fas fa-file-pdf"></i> Report</button></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="note" style="margin:10px 0 0">Each report opens in a new tab ready to print or <b>Save as PDF</b> (A4). Up to <?= $arMax ?> employees per bulk report.</p>
  </div>
</form>
<script>
(function () {
  var form = document.getElementById('ar-form');
  var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('input.ar-one')); };
  var selN = document.getElementById('ar-sel');
  var btnN = document.querySelector('#ar-gen-sel span');
  function count() { var n = boxes().filter(function (b) { return b.checked; }).length; selN.textContent = n; btnN.textContent = n; }
  document.getElementById('ar-all').addEventListener('change', function () { var on = this.checked; boxes().forEach(function (b) { b.checked = on; }); count(); });
  form.addEventListener('change', function (e) { if (e.target.classList.contains('ar-one')) { count(); } });
  var max = <?= $arMax ?>;
  form.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!btn || btn.name !== 'mode') { return; }
    var n = btn.value === 'all' ? boxes().length : (btn.value === 'selected' ? boxes().filter(function (b) { return b.checked; }).length : 1);
    if (n === 0) { e.preventDefault(); alert(btn.value === 'all' ? 'No employees in view.' : 'Tick at least one employee.'); return; }
    if (n > max) { e.preventDefault(); alert('Maximum ' + max + ' agents per bulk report - narrow the filter.'); return; }
    if (btn.value === 'all') {
      // Every row in view goes, ticked or not.
      boxes().forEach(function (b) { if (!b.checked) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = b.value; h.className = 'ar-tmp'; form.appendChild(h); } });
      setTimeout(function () { form.querySelectorAll('.ar-tmp').forEach(function (h) { h.remove(); }); }, 0);
    }
  });
  count();
})();
</script>
