<?php declare(strict_types=1); if (!$scope->seesEveryone($may('employee.list')) || !$may('attendance.day.view')): ?>
<div class="page-header"><div><h2>On leave today</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'attendance.day.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ON LEAVE TODAY (owner, 23 Oct 2026) — opened from the dashboard's "On leave" tile.
// Same muster and same rule as the tile (approved leave, holiday / weekly off left out),
// so the list and the count agree. Optional ?d=YYYY-MM-DD looks at another day.
use SmartPRS\Surface\AttendanceReport;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Leave;

$olDay = (string) ($_GET['d'] ?? '');
$olDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $olDay) === 1 ? $olDay : date('Y-m-d');
$olFilter = orgFilter();
$olScoped = array_values(array_filter($scope->only(liveRows($store, 'employees'), true),
    static fn (array $e): bool => strtolower(trim((string) ($e['status'] ?? 'active'))) === 'active'));
$olOptions = orgFilterOptions($store, $olScoped);
$olPeople = orgFilterApply($store, $olScoped, $olFilter);
$olByCode = [];
foreach ($olPeople as $olE) { $olByCode[Employment::code($olE)] = $olE; }

$olRows = [];
$olError = null;
try {
    $olLookups = attendanceLookups($store, employmentLabels($store));
    foreach ($attendanceReport->muster($olPeople, $olDay, $olDay, $calendar->shiftStart(), $calendar->shiftEnd(), date('Y-m-d'))['rows'] as $olR) {
        $olC = $olR['cells'][$olDay] ?? null;
        if ($olC === null || !in_array((string) $olC['status'], AttendanceReport::LEAVE_STATUSES, true)) { continue; }
        $olE = $olByCode[(string) $olR['code']] ?? null;
        if ($olE === null) { continue; }
        $olReq = null;
        foreach ($leave->requestsFor((string) $olR['code']) as $q) {
            if (($q['state'] ?? '') === Leave::APPROVED && (string) $q['from'] <= $olDay && (string) $q['to'] >= $olDay) { $olReq = $q; break; }
        }
        $olType = (string) ($olReq['leave_type'] ?? '');
        $olTypes = $olType !== '' ? $policy->typesFor(leaveScopeContext($store, $olE)) : [];
        $olHalf = $olReq !== null ? Leave::halfOf($olReq) : null;
        $olCells = employeeOrgCells($store, $olE, $olLookups);
        $olRows[] = [
            'code' => (string) $olR['code'], 'name' => trim((string) ($olE['name'] ?? '')),
            'company' => $olCells['company'], 'branch' => $olCells['branch'], 'department' => $olCells['department'],
            'rm' => $olCells['reports_to'],
            'type' => ($olType !== '' ? (string) ($olTypes[$olType]['name'] ?? $olType) : 'Leave') . ($olHalf !== null ? ' (' . Leave::HALVES[$olHalf] . ')' : ''),
            'period' => $olReq !== null ? date('d-m-Y', strtotime((string) $olReq['from'])) . ((string) $olReq['to'] !== (string) $olReq['from'] ? ' to ' . date('d-m-Y', strtotime((string) $olReq['to'])) : '') : '',
        ];
    }
} catch (\Throwable $e) {
    $olError = $e->getMessage();
}
usort($olRows, static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));
$olDmy = date('d-m-Y', strtotime($olDay));
?>
<div class="page-header">
  <div><h2>On leave <?= $olDay === date('Y-m-d') ? 'today' : 'on ' . e($olDmy) ?></h2>
    <p>Employees on approved leave on <?= e($olDmy) ?></p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=dashboard"><i class="fas fa-arrow-left"></i> Dashboard</a></div>
</div>
<?= str_replace('<form method="get" class="row org-filter"', '<form method="get" id="ol-filter" class="row org-filter"', orgFilterBar('on-leave-today', $olFilter, $olOptions)) ?>
<div class="row" style="margin:-6px 0 14px;align-items:flex-end">
  <div style="flex:0 0 200px;max-width:200px"><label for="ol-d">Date</label><input type="date" style="width:100%" id="ol-d" name="d" form="ol-filter" value="<?= e($olDay) ?>" onchange="this.form.submit()"></div>
</div>
<?php if ($olError !== null): ?><div class="problem"><b>Attendance could not be read.</b> <?= e($olError) ?></div><?php endif; ?>
<div class="card" style="padding:0">
  <div class="card-header">
    <div><h3 style="font-size:15px;font-weight:700"><?= count($olRows) ?> employee(s) on leave</h3></div>
    <div class="actions"><button type="button" class="btn btn-outline" onclick="olCsv()"><i class="fas fa-file-excel"></i> Export Excel</button></div>
  </div>
<?php if ($olRows === []): ?>
  <div class="empty"><i class="fas fa-user-check"></i>Nobody is on approved leave on <?= e($olDmy) ?>.</div>
<?php else: ?>
  <div class="table-wrap"><table id="ol-table">
    <thead><tr><th>Emp ID</th><th>Name</th><th>Company</th><th>Branch</th><th>Department</th><th>Reporting Manager</th><th>Date</th><th>Leave type</th><th>Leave period</th></tr></thead>
    <tbody>
<?php foreach ($olRows as $r): ?>
    <tr><td><code><?= e($r['code']) ?></code></td><td><?= e($r['name']) ?></td><td><?= e($r['company']) ?></td><td><?= e($r['branch']) ?></td>
      <td><?= e($r['department']) ?></td><td><?= e($r['rm'] !== '' ? $r['rm'] : '—') ?></td><td><?= e($olDmy) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['period']) ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<script>
function olCsv(){ // the table on screen, same rows — handed back as a formatted Excel file
  var t=document.getElementById('ol-table'); if(!t){return;}
  var csv=[].map.call(t.rows,function(r){return [].map.call(r.cells,function(c){return '"'+c.innerText.trim().replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
  var f=document.createElement('form'); f.method='post'; f.action='?r=on-leave-today'; // formatted Excel via csv-xlsx
  [['csrf',<?= json_encode((string) $session->token()) ?>],['action','csv-xlsx'],['name','on-leave-<?= e($olDmy) ?>.csv'],['csv',csv]].forEach(function(kv){var i=document.createElement('input');i.type='hidden';i.name=kv[0];i.value=kv[1];f.appendChild(i);});
  document.body.appendChild(f); f.submit(); f.remove();
}
</script>
