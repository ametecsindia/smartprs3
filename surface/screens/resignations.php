<?php declare(strict_types=1);
// HIRING & ONBOARDING → RESIGNATIONS (owner, 22 Oct 2026): everyone who submitted a resignation and is serving
// notice; the notice end date is editable by HR Manager / Admin (config/exits.json notice_edit_roles). On that date
// the record moves to Exit & FnF by itself (resignationSweep()), where HR edits it and initiates the FnF. The rows are
// the exit records with status "notice" (serving), "pending"/"cleared"/"withheld" (in Exit & FnF) or "revoked".
use SmartPRS\Surface\Employment;

if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Resignations</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif;

resignationSweep($store);
$rsVisible = [];
// EVERY EMPLOYEE RECORD (not only active ones): someone serving notice may already be marked inactive.
foreach ($scope->only($store->all('employees'), $may('employee.list')) as $rsE) { trim((string) ($rsE['deleted_at'] ?? '')) === '' && $rsVisible[(string) $rsE['id']] = $rsE; }
$rsAllEmp = [];
foreach ($store->all('employees') as $rsE) { $rsAllEmp[(string) $rsE['id']] = $rsE; }
$rsTab = in_array((string) ($_GET['s'] ?? ''), ['notice', 'ended', 'revoked'], true) ? (string) $_GET['s'] : 'notice';
$rsAll = array_values(array_filter($store->all('exits'), static fn (array $x): bool => (string) ($x['reason'] ?? '') === 'Resignation' && isset($rsVisible[(string) ($x['employee_id'] ?? '')])));
// ONE ROW PER EMPLOYEE (owner, 22 Oct): only the latest resignation counts — a revoked one that was followed by a
// new submission is history, not a second state, so the same person never shows as both Revoked and in Exit & FnF.
$rsLatest = [];
foreach ($rsAll as $x) { $k = (string) $x['employee_id']; $o = $rsLatest[$k] ?? null; if ($o === null || [(string) ($x['created_at'] ?? ''), (int) $x['id']] > [(string) ($o['created_at'] ?? ''), (int) $o['id']]) { $rsLatest[$k] = $x; } }
$rsAll = array_values($rsLatest);
$rsGroup = static fn (array $x): string => match ((string) ($x['status'] ?? '')) { 'notice' => 'notice', 'revoked' => 'revoked', default => 'ended' };
$rsCounts = ['notice' => 0, 'ended' => 0, 'revoked' => 0];
foreach ($rsAll as $x) { $rsCounts[$rsGroup($x)]++; }
$rsRows = array_values(array_filter($rsAll, static fn (array $x): bool => $rsGroup($x) === $rsTab));
usort($rsRows, static fn (array $a, array $b): int => strcmp((string) $a['last_day'], (string) $b['last_day']));
$rsMayEdit = resignationNoticeEditor($session);
$rsToday = date('Y-m-d');
?>
<div class="page-header">
  <div><h2>Resignations</h2><p>People who submitted a resignation &middot; on the notice end date they move to <a href="?r=exits">Exit &amp; FnF</a> by themselves to initiate the full &amp; final settlement</p></div>
  <div class="actions"><a class="btn btn-outline btn-sm" href="?r=exits"><i class="fas fa-person-walking-arrow-right"></i> Exit &amp; FnF</a></div>
</div>
<div style="display:flex;gap:8px;margin-bottom:12px">
<?php foreach (['notice' => 'Under notice', 'ended' => 'Notice ended (in Exit & FnF)', 'revoked' => 'Revoked'] as $rsK => $rsL): ?>
  <a href="?r=resignations&amp;s=<?= $rsK ?>" class="badge" style="padding:6px 12px;font-size:12px;text-decoration:none;background:<?= $rsTab === $rsK ? '#0f172a' : '#e2e8f0' ?>;color:<?= $rsTab === $rsK ? '#fff' : '#0f172a' ?>"><?= e($rsL) ?> <?= $rsCounts[$rsK] ?></a>
<?php endforeach; ?>
</div>
<?php
// APPLY RESIGNATION FOR — the Leave pattern (owner, 22 Oct): "— None —" by default, "— Myself —" when this login has an
// employee record, and (Admin / HR, employee.update) every active employee in scope. Picking somebody opens the
// Reason + Submit form; nothing is submitted until Submit. Uses the same resign-submit action as the profile card.
$rsBusy = [];
foreach ($store->all('exits') as $x) { (string) ($x['status'] ?? '') !== 'revoked' && $rsBusy[(string) $x['employee_id']] = true; }
$rsOwn = (string) ($scope->own() ?? '');
$rsPick = [];
if ($may('employee.update')) {
    foreach ($rsVisible as $rsId => $rsE) { if ($rsId !== $rsOwn && !isset($rsBusy[$rsId]) && in_array(strtolower(trim((string) ($rsE['status'] ?? 'active'))), ['active', ''], true)) { $rsPick[$rsId] = Employment::label($rsE); } }
    asort($rsPick);
}
$rsFor = (string) ($_GET['for'] ?? '');
$rsForOk = $rsFor !== '' && (isset($rsPick[$rsFor]) || ($rsFor === $rsOwn && !isset($rsBusy[$rsOwn])));
if ($rsPick !== [] || ($rsOwn !== '' && !isset($rsBusy[$rsOwn]))): ?>
<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin:0"><input type="hidden" name="r" value="resignations"><input type="hidden" name="s" value="<?= e($rsTab) ?>">
    <div style="min-width:300px"><label for="rs-for">Apply resignation for</label>
      <select id="rs-for" name="for" onchange="this.form.submit()">
        <option value=""<?= $rsForOk ? '' : ' selected' ?>>&mdash; None &mdash;</option>
<?php if ($rsOwn !== '' && !isset($rsBusy[$rsOwn])): ?>
        <option value="<?= e($rsOwn) ?>"<?= $rsForOk && $rsFor === $rsOwn ? ' selected' : '' ?>>&mdash; Myself &mdash;</option>
<?php endif; ?>
<?php foreach ($rsPick as $rsId => $rsLabel): ?>
        <option value="<?= e((string) $rsId) ?>"<?= $rsForOk && (string) $rsId === $rsFor ? ' selected' : '' ?>><?= e($rsLabel) ?></option>
<?php endforeach; ?>
      </select></div>
    <noscript><button class="go" type="submit">Open</button></noscript>
  </form>
<?php if ($rsForOk): $rsE = $rsAllEmp[$rsFor] ?? []; $rsDays = resignationNoticeDays($employeeExtras, $rsE, $store); ?>
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin:12px 0 0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="resign-submit"><input type="hidden" name="back" value="resignations"><input type="hidden" name="id" value="<?= e($rsFor) ?>">
    <div style="min-width:300px"><label>Employee</label><div style="padding:8px 0"><b><?= e(Employment::label($rsE)) ?></b> &middot; <?= e(orgValueOf($store, $rsE, 'designation')) ?> &middot; <?= e(orgValueOf($store, $rsE, 'branch')) ?></div></div>
    <div><label for="rs-start">Start date</label><input type="date" id="rs-start" name="start_date" value="<?= e($rsToday) ?>" required style="width:auto"></div>
    <div><label>Last working day</label><div style="padding:8px 0"><b id="rs-lwd"><?= e(SmartPRS\Surface\Dates::dmy(date('Y-m-d', strtotime($rsToday . ' +' . $rsDays . ' days')))) ?></b> <span class="note">(<?= $rsDays ?> days' notice)</span></div></div>
    <div style="flex:1;min-width:220px"><label>Reason</label><input type="text" name="note" maxlength="80" required placeholder="Reason for resigning"></div>
    <div><label>Document (optional)</label><input type="file" name="resign_doc" accept="<?= e(EMPLOYEE_FILE_ACCEPT) ?>" style="max-width:220px"></div>
    <button class="btn btn-outline btn-sm" onclick="return confirm('Submit this resignation? The notice period starts today.')"><i class="fas fa-door-open"></i> Submit resignation</button>
  </form>
  <script>(function () { var s = document.getElementById('rs-start'), o = document.getElementById('rs-lwd'), n = <?= (int) $rsDays ?>;
    s.addEventListener('change', function () { var p = s.value.split('-'); if (p.length !== 3) { return; } var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n));
      o.textContent = ('0' + d.getUTCDate()).slice(-2) + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + d.getUTCFullYear(); }); })();</script>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="card" style="padding:0">
<?php if ($rsRows === []): ?><div class="empty"><i class="fas fa-door-open"></i>Nobody in this list.</div><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee ID</th><th>Name</th><th>Designation</th><th>Branch</th><th>Company</th><th>Reporting Manager</th><th>Date of Joining</th><th>Resignation submitted</th><th>Notice ends on</th><th>Reason</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rsRows as $x): $e = $rsVisible[(string) $x['employee_id']]; $rm = (string) ($e['reporting_manager_id'] ?? ''); $rmk = json_decode((string) ($x['remarks'] ?? ''), true) ?: [];
      $left = (int) floor((strtotime((string) $x['last_day']) - strtotime($rsToday)) / 86400); ?>
      <tr><td><code><?= e(Employment::code($e)) ?></code></td><td><a href="?r=employees&amp;sel=<?= e(rawurlencode(Employment::code($e))) ?>#emp-resign"><b><?= e(trim((string) ($e['name'] ?? ''))) ?></b></a></td>
        <td><?= e(orgValueOf($store, $e, 'designation')) ?></td><td><?= e(orgValueOf($store, $e, 'branch')) ?></td><td><?= e(orgValueOf($store, $e, 'company')) ?></td>
        <td><?= isset($rsAllEmp[$rm]) ? e(Employment::label($rsAllEmp[$rm])) : '<span class="note">—</span>' ?></td>
        <td><?= e(SmartPRS\Surface\Dates::dmy(Employment::joinedOn($e))) ?></td>
        <td><?= e(SmartPRS\Surface\Dates::dmy((string) ($rmk['start_date'] ?? '') ?: substr((string) ($x['created_at'] ?? ''), 0, 10))) ?></td>
        <td style="white-space:nowrap">
<?php if ($rsMayEdit && $rsTab === 'notice'): ?>
          <form method="post" style="display:inline-flex;gap:4px;align-items:center;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="resign-notice"><input type="hidden" name="id" value="<?= e((string) $x['id']) ?>">
            <input type="date" name="last_day" value="<?= e((string) $x['last_day']) ?>" required style="width:auto"><button class="btn btn-outline btn-sm">Save</button></form>
<?php else: ?><b><?= e(SmartPRS\Surface\Dates::dmy((string) $x['last_day'])) ?></b><?php endif; ?>
<?php if ($rsTab === 'notice'): ?><div class="note"><?= $left >= 0 ? $left . ' day(s) left' : 'ended' ?></div><?php endif; ?></td>
        <td class="note"><?= e((string) ($rmk['note'] ?? '')) ?><?= (string) ($rmk['document'] ?? '') !== '' ? ' &middot; <a href="' . e((string) $rmk['document']) . '" target="_blank"><i class="fas fa-paperclip"></i> Document</a>' : '' ?><?= $rsTab === 'revoked' && ($x['decided_by'] ?? '') !== '' ? '<br>revoked by ' . e((string) $x['decided_by']) . ' ' . e(SmartPRS\Surface\Dates::dmy(substr((string) ($x['decided_at'] ?? ''), 0, 10))) : '' ?></td>
        <td style="white-space:nowrap">
<?php if ($rsTab === 'notice' && $may('employee.update')): ?>
          <form method="post" style="display:inline;margin:0" onsubmit="return confirm('Revoke this resignation?')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="resign-revoke"><input type="hidden" name="id" value="<?= e((string) $x['id']) ?>"><input type="hidden" name="back" value="resignations"><button class="btn btn-outline btn-sm">Revoke</button></form>
<?php elseif ($rsTab === 'ended'): ?>
          <a class="btn btn-outline btn-sm" href="?r=exits&amp;id=<?= e((string) $x['id']) ?>"><i class="fas fa-pen"></i> Exit &amp; FnF</a>
<?php endif; ?></td></tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php if (!$rsMayEdit): ?><p class="note">The notice end date is changed by HR Manager / Admin.</p><?php endif; ?>
