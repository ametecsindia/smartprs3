<?php declare(strict_types=1); if (!$may('employee.letters.view') || !$may('employee.update') || !$scope->seesEveryone($may('employee.list'))): ?>
<div class="page-header"><div><h2>NDA Sign-off Report</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: this report is for Admin and HR Admins. <?= e($access->decide((string) $session->user()['role'], 'employee.letters.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// NDA SIGN-OFF REPORT (owner, 23 Oct 2026): for a letter that needs acknowledgement (NDA by default), every
// active employee in view — signed off, pending (issued, not acknowledged) or not issued — with counts,
// filters and an Excel download. Reads letters + letter_acks; computes nothing else.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Letters;

$soTypes = array_values(array_filter($letters->all(), static fn (array $t): bool => Letters::needsAck($t)));
$soKey = (string) ($_GET['t'] ?? '');
$soKey = in_array($soKey, array_column($soTypes, 'key'), true) ? $soKey : (in_array('nda', array_column($soTypes, 'key'), true) ? 'nda' : (string) ($soTypes[0]['key'] ?? ''));
$soT = $soKey === '' ? null : $letters->find($soKey);
$soStatus = (string) ($_GET['status'] ?? '');
$soFilter = orgFilter();
$soScoped = array_values(array_filter($scope->only(liveRows($store, 'employees'), true),
    static fn (array $e): bool => strtolower(trim((string) ($e['status'] ?? 'active'))) === 'active'));
$soOptions = orgFilterOptions($store, $soScoped);
$soPeople = orgFilterApply($store, $soScoped, $soFilter);

$soAcks = [];
try {
    foreach ($store->all('letter_acks') as $a) { $soAcks[(string) ($a['letter_id'] ?? '')] = $a; }
} catch (\Throwable) {
    $soAcks = [];   // letter_acks not migrated yet: nobody has signed off
}
$soLatest = [];   // employee id => their latest letter of this type
try {
    foreach ($store->all('letters') as $l) {
        if ((string) ($l['letter_type'] ?? '') !== $soKey) { continue; }
        $k = (string) ($l['employee_id'] ?? '');
        if (!isset($soLatest[$k]) || strcmp((string) $l['issued_on'] . str_pad((string) $l['id'], 12, '0', STR_PAD_LEFT), (string) $soLatest[$k]['issued_on'] . str_pad((string) $soLatest[$k]['id'], 12, '0', STR_PAD_LEFT)) > 0) {
            $soLatest[$k] = $l;
        }
    }
} catch (\Throwable) {
    $soLatest = [];   // no letters table yet: nothing issued
}

$soRows = [];
$soCount = ['signed' => 0, 'pending' => 0, 'not_issued' => 0];
foreach ($soPeople as $e) {
    $l = $soLatest[(string) $e['id']] ?? null;
    $a = $l === null ? null : ($soAcks[(string) $l['id']] ?? null);
    $st = $l === null ? 'not_issued' : ($a === null ? 'pending' : 'signed');
    $soCount[$st]++;
    if ($soStatus !== '' && $soStatus !== $st) { continue; }
    $cells = employeeOrgCells($store, $e);
    $soRows[] = ['code' => Employment::code($e), 'name' => trim((string) ($e['name'] ?? '')), 'company' => $cells['company'], 'branch' => $cells['branch'],
        'department' => $cells['department'], 'designation' => $cells['designation'], 'issued' => $l === null ? '' : date('d-m-Y', (int) strtotime((string) $l['issued_on'])),
        'status' => $st, 'ack' => $a === null ? '' : date('d-m-Y H:i', (int) strtotime((string) $a['acknowledged_at']))];
}
usort($soRows, static fn (array $x, array $y): int => strnatcasecmp($x['code'], $y['code']));
$soLabel = ['signed' => 'Signed off', 'pending' => 'Pending', 'not_issued' => 'Not issued'];
$soBadge = ['signed' => 'badge-green', 'pending' => 'badge-red', 'not_issued' => 'badge-gray'];
$soIssued = $soCount['signed'] + $soCount['pending'];
?>
<div class="page-header">
  <div><h2><?= e($soT === null ? 'NDA Sign-off Report' : (string) $soT['label'] . ' — Sign-off Report') ?></h2>
    <p>Who has acknowledged, who is pending. A letter type appears here when "Employee must acknowledge" is ticked on its template.</p></div>
</div>
<?php if ($soTypes === []): ?>
<div class="card"><p class="note" style="margin:0">No letter template needs acknowledgement yet. Tick <b>Employee must acknowledge</b> on a template (HR Letters → Letter Templates → Change wording).</p></div>
<?php return; endif; ?>
<?= str_replace('<form method="get" class="row org-filter"', '<form method="get" id="so-filter" class="row org-filter"', orgFilterBar('letter-signoff', $soFilter, $soOptions)) ?>
<div class="row" style="margin:-6px 0 14px;align-items:flex-end">
  <div><label for="so-t">Letter</label><select id="so-t" name="t" form="so-filter" onchange="this.form.submit()">
<?php foreach ($soTypes as $t): ?><option value="<?= e((string) $t['key']) ?>"<?= (string) $t['key'] === $soKey ? ' selected' : '' ?>><?= e((string) $t['label']) ?></option><?php endforeach; ?>
  </select></div>
  <div><label for="so-s">Status</label><select id="so-s" name="status" form="so-filter" onchange="this.form.submit()"><option value="">All</option>
<?php foreach ($soLabel as $k => $v): ?><option value="<?= e($k) ?>"<?= $soStatus === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></div>
</div>
<div class="kpi-strip dash-kpis" style="grid-template-columns:repeat(4,1fr)">
  <div class="kpi dk blue"><div class="dk-top"><span class="dk-ic"><i class="fas fa-paper-plane"></i></span><span class="dk-label">Issued</span></div><div class="dk-val"><?= $soIssued ?></div><div class="dk-foot"><span>employees sent the letter</span></div></div>
  <div class="kpi dk green"><div class="dk-top"><span class="dk-ic"><i class="fas fa-signature"></i></span><span class="dk-label">Signed off</span></div><div class="dk-val"><?= $soCount['signed'] ?></div><div class="dk-foot"><span><?= $soIssued > 0 ? round($soCount['signed'] * 100 / $soIssued) . '% of issued' : '—' ?></span></div></div>
  <div class="kpi dk red"><div class="dk-top"><span class="dk-ic"><i class="fas fa-hourglass-half"></i></span><span class="dk-label">Pending</span></div><div class="dk-val"><?= $soCount['pending'] ?></div><div class="dk-foot"><span>issued, not acknowledged</span></div></div>
  <div class="kpi dk orange"><div class="dk-top"><span class="dk-ic"><i class="fas fa-user-clock"></i></span><span class="dk-label">Not issued</span></div><div class="dk-val"><?= $soCount['not_issued'] ?></div><div class="dk-foot"><span>active, letter not sent yet</span></div></div>
</div>
<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700"><?= count($soRows) ?> employee(s)</h3></div>
    <div class="actions" style="display:flex;gap:8px">
<?php if ($soCount['pending'] > 0): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Send an in-app notice to the <?= $soCount['pending'] ?> pending employee(s)?')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="letter-ack-remind"><input type="hidden" name="t" value="<?= e($soKey) ?>">
        <button type="submit" class="btn btn-outline"><i class="fas fa-bell"></i> Notify pending (<?= $soCount['pending'] ?>)</button></form>
<?php endif; ?>
      <button type="button" class="btn btn-outline" onclick="soExport()"><i class="fas fa-file-excel"></i> Export Excel</button></div></div>
<?php if ($soRows === []): ?>
  <div class="empty"><i class="fas fa-filter"></i>No employees match these filters.</div>
<?php else: ?>
  <div class="table-wrap"><table id="so-table">
    <thead><tr><th>Emp ID</th><th>Name</th><th>Company</th><th>Branch</th><th>Department</th><th>Designation</th><th>Issued on</th><th>Status</th><th>Acknowledged on</th></tr></thead>
    <tbody>
<?php foreach ($soRows as $r): ?>
      <tr><td><code><?= e($r['code']) ?></code></td><td><?= e($r['name']) ?></td><td><?= e($r['company']) ?></td><td><?= e($r['branch']) ?></td><td><?= e($r['department']) ?></td>
        <td><?= e($r['designation']) ?></td><td><?= e($r['issued'] ?: '—') ?></td><td><span class="badge <?= $soBadge[$r['status']] ?>"><?= e($soLabel[$r['status']]) ?></span></td><td><?= e($r['ack'] ?: '—') ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<script>
function soExport(){ var t=document.getElementById('so-table'); if(!t){return;}
  var csv=[].map.call(t.rows,function(r){return [].map.call(r.cells,function(c){return '"'+c.innerText.trim().replace(/"/g,'""')+'"';}).join(',');}).join('\r\n');
  var f=document.createElement('form'); f.method='post'; f.action='?r=letter-signoff';
  [['csrf',<?= json_encode((string) $session->token()) ?>],['action','csv-xlsx'],['name',<?= json_encode(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($soT['label'] ?? 'letter'))) . '-signoff-' . date('d-m-Y') . '.csv') ?>],['csv',csv]].forEach(function(kv){var i=document.createElement('input');i.type='hidden';i.name=kv[0];i.value=kv[1];f.appendChild(i);});
  document.body.appendChild(f); f.submit(); f.remove(); }
</script>
