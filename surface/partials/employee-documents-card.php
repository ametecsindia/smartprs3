<?php declare(strict_types=1);
// DOCUMENTS ON THE EMPLOYEE PROFILE (owner, 22 Oct 2026): every document of this employee (Document Tracker rows
// and the files kept on the record by the employee form / self-onboarding), with an Upload form. Shown on the
// Directory profile and the Employee view. Who sees it: the employee themself and their reporting line (the
// pages are already narrowed by Scope), or anyone holding employee.document.view. Who uploads: HR
// (employee.update) or the employee for their own record (a self-upload is always "pending" for HR to approve).
// In: $docEmp (employee row), $docBack ('profile' | 'me').
use SmartPRS\Surface\Employment;

$docId = (string) $docEmp['id'];
$docOwn = (string) ($scope->own() ?? '') === $docId;
$docMaySee = $docOwn || $scope->covers($docId, false) || $may('employee.document.view');
$docMayUpload = $may('employee.update') || $docOwn;
if (!$docMaySee) {
    return;
}
$docRows = [];
try {
    $docRows = array_values(array_filter($store->all('documents'), static fn (array $d): bool => (string) ($d['employee_id'] ?? '') === $docId && trim((string) ($d['deleted_at'] ?? '')) === ''));
} catch (\Throwable) {
    $docRows = [];
}
$docHeld = $employeeExtras->forEmployment(Employment::code($docEmp));
$docFields = $employeeFields->documents();
$docOnRecord = [];
foreach ($docFields as $docF) {
    $docV = trim((string) (($docF['storage'] ?? 'extra') === 'column' ? ($docEmp[(string) ($docF['column'] ?? '')] ?? '') : ($docHeld[(string) $docF['code']] ?? '')));
    $docV === '' || $docOnRecord[] = [(string) $docF['label'], $docV];
}
$docKinds = array_values(array_unique(array_map(static fn (array $f): string => (string) $f['label'], $docFields)));
$docBadge = ['approved' => 'badge-green', 'rejected' => 'badge-red', 'pending' => 'badge-amber'];
?>
  <div class="card" id="emp-docs">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h2 style="margin:0"><i class="fas fa-folder-open" style="color:var(--accent)"></i> Documents <span class="note"><?= count($docRows) + count($docOnRecord) ?> on file</span></h2>
<?php if ($docMayUpload): ?>      <button type="button" class="btn btn-primary btn-sm" onclick="var f=document.getElementById('emp-docs-up');f.hidden=!f.hidden"><i class="fas fa-upload"></i> Upload documents</button><?php endif; ?>
    </div>
<?php if ($docMayUpload): ?>
    <form method="post" enctype="multipart/form-data" id="emp-docs-up" hidden style="margin-top:12px;padding:12px;border:1px dashed var(--border);border-radius:10px">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="document-add">
      <input type="hidden" name="id" value="<?= e($docId) ?>"><input type="hidden" name="back" value="<?= e($docBack) ?>">
      <div id="emp-docs-rows">
        <div class="row emp-doc-row" style="align-items:flex-end">
          <div><label>Document</label><select name="docs[0][kind]" required><option value="">— pick —</option><?php foreach ($docKinds as $k): ?><option><?= e($k) ?></option><?php endforeach; ?><option>Other</option></select></div>
          <div style="flex:2"><label>File (PDF / image)</label><input type="file" name="doc_0" accept="<?= e(EMPLOYEE_FILE_ACCEPT) ?>" required></div>
          <div><label>Expiry (if any)</label><input type="date" name="docs[0][expiry]"></div>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button type="button" class="btn btn-outline btn-sm" onclick="var w=document.getElementById('emp-docs-rows'),r=w.querySelector('.emp-doc-row').cloneNode(true),i=w.children.length;r.querySelectorAll('[name]').forEach(function(x){x.name=x.name.replace(/\d+/,i);x.value='';});w.appendChild(r);"><i class="fas fa-plus"></i> Another document</button>
        <button class="btn btn-primary btn-sm"><i class="fas fa-upload"></i> Upload</button>
        <?php if (!$may('employee.update')): ?><span class="note" style="align-self:center">HR will check and approve what you upload.</span><?php endif; ?>
      </div>
    </form>
<?php endif; ?>
<?php if ($docRows === [] && $docOnRecord === []): ?>
    <p class="note" style="margin:10px 0 0">No documents on file yet.</p>
<?php else: ?>
    <div class="table-wrap" style="margin-top:10px"><table>
      <thead><tr><th>Document</th><th>Status</th><th>Expiry</th><th>Uploaded</th><th>File</th></tr></thead><tbody>
<?php foreach ($docRows as $d): $dPath = trim((string) (($d['file_path'] ?? '') ?: ($d['path'] ?? ''))); ?>
        <tr><td><b><?= e((string) $d['kind']) ?></b></td><td><span class="badge <?= $docBadge[(string) ($d['status'] ?? 'pending')] ?? 'badge-gray' ?>"><?= e((string) ($d['status'] ?? 'pending')) ?></span></td>
          <td><?= ($d['expiry'] ?? '') !== '' && $d['expiry'] !== null ? e(SmartPRS\Surface\Dates::dmy(substr((string) $d['expiry'], 0, 10))) : '<span class="note">—</span>' ?></td>
          <td class="note"><?= e(SmartPRS\Surface\Dates::dmy(substr((string) ($d['created_at'] ?? ''), 0, 10))) ?></td>
          <td><?= $dPath !== '' ? '<a href="' . e($dPath) . '" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> Open</a>' : '<span class="note">no file</span>' ?></td></tr>
<?php endforeach; ?>
<?php foreach ($docOnRecord as [$dL, $dP]): ?>
        <tr><td><b><?= e($dL) ?></b> <span class="note">on the record</span></td><td><span class="badge badge-gray">on file</span></td><td><span class="note">—</span></td><td class="note">—</td>
          <td><a href="<?= e($dP) ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip"></i> Open</a></td></tr>
<?php endforeach; ?>
      </tbody></table></div>
<?php endif; ?>
  </div>
