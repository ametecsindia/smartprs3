<?php declare(strict_types=1);
// RESIGNATION ON THE EMPLOYEE PROFILE (owner, 22 Oct 2026): Submit Resignation / Revoke submitted resignation.
// The employee themself, or HR (employee.update), submits or revokes. Submitting tells every level above them in
// the reporting line; the notice period ends on (submitted date + Notice Period (Days) on the record, else
// config/exits.json default_notice_days), HR Manager / Admin can move it on Hiring & Onboarding → Resignations, and
// on that day the employee is listed in Exit & FnF to initiate the settlement. In: $rsEmp, $rsBack ('profile'|'me').
use SmartPRS\Surface\Employment;

$rsId = (string) $rsEmp['id'];
$rsOwn = (string) ($scope->own() ?? '') === $rsId;
$rsMay = $rsOwn || $may('employee.update');
$rsActive = in_array(strtolower(trim((string) ($rsEmp['status'] ?? 'active'))), ['active', ''], true);
$rsRow = resignationOf($store, $rsId);
if (!$rsMay && $rsRow === null) {
    return;
}
$rsDays = resignationNoticeDays($employeeExtras, $rsEmp);
?>
  <div class="card" id="emp-resign" style="border-left:4px solid <?= $rsRow === null ? 'var(--border)' : '#e11d48' ?>">
<?php if ($rsRow === null): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div><h2 style="margin:0"><i class="fas fa-door-open" style="color:var(--text3)"></i> Resignation</h2>
        <p class="note" style="margin:2px 0 0">No resignation submitted. Notice period on the record: <b><?= $rsDays ?> day(s)</b>.</p></div>
<?php if ($rsMay && $rsActive): ?>      <button type="button" class="btn btn-outline btn-sm" style="border-color:#e11d48;color:#be123c" onclick="var f=document.getElementById('emp-resign-f');f.hidden=!f.hidden"><i class="fas fa-file-signature"></i> Submit Resignation</button><?php endif; ?>
    </div>
<?php if ($rsMay && $rsActive): ?>
    <form method="post" id="emp-resign-f" hidden style="margin-top:12px;padding:12px;border:1px dashed #fda4af;border-radius:10px;background:#fff1f2"
      onsubmit="return confirm('Submit the resignation of <?= e(addslashes(Employment::label($rsEmp))) ?>? Everyone above in the reporting line is told.')">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="resign-submit">
      <input type="hidden" name="id" value="<?= e($rsId) ?>"><input type="hidden" name="back" value="<?= e($rsBack) ?>">
      <div class="row" style="align-items:flex-end">
        <div><label>Date of resignation</label><input value="<?= e(date('d-m-Y')) ?>" readonly></div>
        <div><label>Notice ends on</label><input value="<?= e(date('d-m-Y', strtotime('+' . $rsDays . ' days'))) ?>" readonly title="HR Manager / Admin can change it on Resignations"></div>
        <div style="flex:2"><label>Reason <span class="no">*</span></label><input name="note" maxlength="80" required placeholder="why you are leaving"></div>
        <div><button class="btn btn-primary" style="background:#e11d48;border-color:#e11d48"><i class="fas fa-paper-plane"></i> Submit</button></div>
      </div>
    </form>
<?php endif; ?>
<?php else: $rsRm = json_decode((string) ($rsRow['remarks'] ?? ''), true) ?: []; $rsLeft = (int) floor((strtotime((string) $rsRow['last_day']) - strtotime(date('Y-m-d'))) / 86400); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div><h2 style="margin:0"><i class="fas fa-door-open" style="color:#e11d48"></i> Resignation submitted <span class="badge badge-red">serving notice</span></h2>
        <p class="note" style="margin:2px 0 0">Submitted <b><?= e(SmartPRS\Surface\Dates::dmy(substr((string) ($rsRow['created_at'] ?? ''), 0, 10))) ?></b> &middot; notice ends on <b><?= e(SmartPRS\Surface\Dates::dmy((string) $rsRow['last_day'])) ?></b>
          (<?= $rsLeft >= 0 ? $rsLeft . ' day(s) left' : 'ended' ?>)<?= ($rsRm['note'] ?? '') !== '' ? ' &middot; ' . e((string) $rsRm['note']) : '' ?></p></div>
<?php if ($rsMay): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Revoke this resignation? The employee stays on, and the reporting line is told.')">
        <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="resign-revoke">
        <input type="hidden" name="id" value="<?= e((string) $rsRow['id']) ?>"><input type="hidden" name="back" value="<?= e($rsBack) ?>">
        <button class="btn btn-outline btn-sm"><i class="fas fa-rotate-left"></i> Revoke resignation</button></form>
<?php endif; ?>
    </div>
<?php endif; ?>
  </div>
