<?php
// SEND FROM THIS PAGE (owner, 12 Nov 2026: "Send button is not available … check all letters and fix"). Pick the employee,
// see the letter filled in right here, and Send it by email (or issue without email) — the same letter-issue action as
// the Letters screen, back to this page. OFFER LETTERS also offer a NEW CANDIDATE: typed fields instead of an employee.
$lkFor = $kind === 'offer' && (string) ($_GET['for'] ?? '') === 'new' ? 'new' : 'existing';
$lkE = (string) ($_GET['e'] ?? '');
$lkSubject = $lkE !== '' && $lkE !== 'all' && isset($byId[$lkE]) ? orgEnrich($store, $byId[$lkE]) : null;
$lkEff = trim((string) ($_GET['eff'] ?? ''));
$lkSig = (string) ($_GET['sig'] ?? '');
// BLANKS TYPED HERE (owner, 12 Nov 2026: a letter refused for a blank Address showed no Send): the fields this letter needs
// that are blank on the employee get boxes on this page; the letter uses them, and they are saved on the record when ticked.
$lkFill = [];
foreach ((array) ($_GET['fill'] ?? []) as $k => $v) {
    is_string($v) && preg_match('/^[a-z0-9_]{1,40}$/', (string) $k) === 1 && trim($v) !== '' && $lkFill[$k] = mb_substr(trim($v), 0, 400);
}
if ($lkSubject !== null) {
    foreach ($lkFill as $k => $v) { trim((string) ($lkSubject[$k] ?? '')) === '' && $lkSubject[$k] = $v; }
}
$lkCtx = $lkFor === 'existing' && $lkSubject !== null ? letterContextFor($template, $lkSubject, $lkEff, $company) : [];
$lkPreview = $lkFor === 'existing' && $lkSubject !== null ? $letters->render($template, $lkSubject, $lkCtx) : null;
$lkBlank = [];
if ($lkPreview !== null && !$lkPreview['ok']) {
    $lkVals = $lkSubject + $lkCtx;
    $lkNeed = array_merge(array_column(SmartPRS\Surface\Letters::placeholders((string) $template['body']), 'field'), array_map('strval', (array) ($template['requires'] ?? [])));
    foreach (array_unique($lkNeed) as $k) {
        !in_array($k, ['today', 'effective_date'], true) && trim((string) ($lkVals[$k] ?? '')) === '' && $lkBlank[] = $k;
    }
}
$lkFieldLabel = static fn (string $k): string => ucfirst(str_replace('_', ' ', (string) preg_replace('/_id$/', '', $k)));
$lkLopHalves = (int) SmartPRS\Surface\Letters::lopHalves((string) ($template['lop_days'] ?? ''));
$lkSigSelect = static function (string $current): string {
    $h = '<select name="sig"><option value="">— no name —</option>';
    foreach (LETTER_SIGNATORIES as $k => $l) { $h .= '<option value="' . e($k) . '"' . ($current === $k ? ' selected' : '') . '>' . e($l) . '</option>'; }
    return $h . '</select>';
};
$lkCc = static fn (): string => '<div style="flex:2;min-width:240px"><label>CC <span class="note">(optional, separated by commas)</span></label><input name="cc" placeholder="e.g. hr@company.com"></div>';
?>
<style>
  .lk-pills{display:flex;gap:6px;margin:0 0 14px}
  .lk-pills a{padding:7px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text1);font-weight:600;font-size:13px;background:var(--card)}
  .lk-pills a.on{background:#1F3A5F;border-color:#1F3A5F;color:#fff}
  .lk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;align-items:end}
  .lk-grid label{display:block}
  .lk-grid input,.lk-grid select,.lk-grid textarea{width:100%;box-sizing:border-box}
  .lk-acts{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
  .lk-letter{white-space:pre-wrap;background:#fff;border:1px solid var(--line);padding:18px;border-radius:6px;font:13px/1.7 Georgia,serif;margin:0}
</style>
<div class="card no-print" id="lk-issue">
<?php if ($kind === 'offer'): ?>
  <div class="lk-pills">
    <a href="?r=letters-offer&amp;for=existing#lk-issue" class="<?= $lkFor === 'existing' ? 'on' : '' ?>"><i class="fas fa-user-check"></i> Existing employee</a>
    <a href="?r=letters-offer&amp;for=new#lk-issue" class="<?= $lkFor === 'new' ? 'on' : '' ?>"><i class="fas fa-user-plus"></i> New candidate</a>
  </div>
<?php endif; ?>
<?php if ($lkFor === 'existing'): ?>
  <form method="get" class="lk-grid">
    <input type="hidden" name="r" value="letters-<?= e($kind) ?>"><?= $kind === 'offer' ? '<input type="hidden" name="for" value="existing">' : '' ?>
    <div style="grid-column:span 2"><label for="e">Send the <?= e(strtolower($label)) ?> to</label>
      <?= employeePicker('e', 'e', ['all' => 'All employees (' . count(letterRecipients($store, $scope, $may('employee.list'))) . ' active)'] + employeeChoices($people), $lkE, '— type to search, or pick All employees —', true) ?></div>
    <div><label>Signed for the company by</label><?= $lkSigSelect($lkSig) ?></div>
    <div><label for="eff">Effective date</label><input type="date" id="eff" name="eff" value="<?= e($lkEff) ?>" title="Blank = the employee's salary-structure effective date"></div>
    <div><button class="go" type="submit"<?= $mayIssue ? '' : ' disabled title="Needs ' . e(implode(', ', $needed)) . '"' ?>><i class="fas fa-file-lines"></i> Draft it</button></div>
  </form>
<?php if ($lkE === 'all'): ?>
  <p style="margin:12px 0 0"><a class="btn btn-outline" href="?<?= e(http_build_query(['r' => 'letters', 'e' => 'all', 't' => $kind, 'eff' => $lkEff, 'sig' => $lkSig])) ?>"><i class="fas fa-users"></i> Review and issue to all employees</a></p>
<?php elseif ($lkPreview !== null && !$lkPreview['ok']): ?>
  <form method="get" style="margin-top:12px;padding:12px 14px;border:1px solid #FCA5A5;border-radius:10px;background:#FEF2F2">
    <input type="hidden" name="r" value="letters-<?= e($kind) ?>"><?= $kind === 'offer' ? '<input type="hidden" name="for" value="existing">' : '' ?>
    <input type="hidden" name="e" value="<?= e($lkE) ?>"><input type="hidden" name="eff" value="<?= e($lkEff) ?>"><input type="hidden" name="sig" value="<?= e($lkSig) ?>">
<?php foreach ($lkFill as $k => $v): if (!in_array($k, $lkBlank, true)): ?><input type="hidden" name="fill[<?= e($k) ?>]" value="<?= e($v) ?>"><?php endif; endforeach; ?>
    <div style="font-weight:700;color:#991B1B;margin-bottom:8px">Blank on <?= e(SmartPRS\Surface\Employment::label($lkSubject)) ?> — fill in to draft and send:</div>
    <div class="lk-grid">
<?php foreach ($lkBlank as $k): ?>
      <div<?= $k === 'address' ? ' style="grid-column:1/-1"' : '' ?>><label><?= e($lkFieldLabel($k)) ?> *</label><?= $k === 'address'
        ? '<textarea name="fill[' . e($k) . ']" rows="2" required maxlength="400"></textarea>'
        : '<input name="fill[' . e($k) . ']" required maxlength="400"' . (in_array($k, ['doj', 'dob'], true) || str_ends_with($k, '_date') ? ' type="date"' : '') . '>' ?></div>
<?php endforeach; ?>
      <div><button class="go" type="submit"><i class="fas fa-file-lines"></i> Draft it</button></div>
    </div>
<?php if ($lkBlank === []): ?><div class="note">Still blank: <?= e(implode(', ', $lkPreview['missing'])) ?> — fill it on the <a href="?r=employees&amp;edit=<?= e($lkE) ?>#add-employment">employee</a>.</div><?php endif; ?>
  </form>
<?php elseif ($lkPreview !== null): $lkMail = trim((string) ($lkSubject['email'] ?? '')); $lkChain = reportingChainOf($store, $lkSubject); ?>
  <h3 style="margin:16px 0 8px"><?= e($label) ?> for <?= e(SmartPRS\Surface\Employment::label($lkSubject)) ?></h3>
  <pre class="lk-letter"><?= e($lkPreview['body']) ?></pre>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="letter-issue">
    <input type="hidden" name="e" value="<?= e($lkE) ?>"><input type="hidden" name="t" value="<?= e($kind) ?>">
    <input type="hidden" name="eff" value="<?= e($lkEff) ?>"><input type="hidden" name="sig" value="<?= e($lkSig) ?>">
    <input type="hidden" name="return_route" value="letters-<?= e($kind) ?>">
<?php foreach ($lkFill as $k => $v): ?><input type="hidden" name="fill[<?= e($k) ?>]" value="<?= e($v) ?>"><?php endforeach; ?>
    <div class="lk-grid">
      <div style="grid-column:span 2"><label>Email subject</label><input name="email_subject" value="<?= e(trim((string) ($template['email_subject'] ?? '')) ?: $label . ' — {{name}} ({{emp_code}})') ?>"></div>
      <?= $lkCc() ?>
<?php if ($lkFill !== []): ?>
      <div><label style="display:flex;gap:6px;align-items:center;font-weight:600"><input type="checkbox" name="save_fill" value="1" checked style="width:auto"> Also save <?= e(implode(', ', array_map($lkFieldLabel, array_keys($lkFill)))) ?> on the employee record</label></div>
<?php endif; ?>
      <div><label style="display:flex;gap:6px;align-items:center;font-weight:600"><input type="checkbox" name="cc_hierarchy" value="1"<?= $lkChain === [] ? ' disabled' : '' ?> style="width:auto"> Copy the Reporting Manager and up</label></div>
<?php if ($lkLopHalves > 0): ?>
      <div><label style="display:flex;gap:6px;align-items:center;font-weight:700;color:var(--red)"><input type="checkbox" name="apply_lop" value="1" checked style="width:auto"> Deduct loss of pay</label></div>
      <div><label>LOP days</label><input name="lop_days" type="number" min="0.5" step="0.5" value="<?= e((string) $template['lop_days']) ?>"></div>
      <div><label>LOP on</label><input name="lop_date" type="date" value="<?= e($lkEff !== '' ? $lkEff : date('Y-m-d')) ?>"></div>
<?php endif; ?>
    </div>
    <div class="lk-acts">
      <button class="go" type="submit" name="send_email" value="1"<?= $lkMail === '' ? ' disabled title="No email address on the employee record"' : '' ?>><i class="fas fa-paper-plane"></i> Send<?= $lkMail !== '' ? ' to ' . e($lkMail) : '' ?></button>
      <button class="btn btn-outline" type="submit" name="send_email" value="0"><i class="fas fa-file-signature"></i> Issue without email</button>
      <button class="btn btn-outline" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
      <?= $lkMail === '' ? '<span class="note">No email on this employee — add it in the Directory to send.</span>' : '' ?>
    </div>
  </form>
<?php endif; ?>
<?php else:
    // NEW CANDIDATE (offer letters only): every field the offer wording uses, typed here; dropdowns for the organisation.
    $nd = (array) ($_SESSION['offer_new'] ?? []);
    $ndv = static fn (string $k): string => e((string) ($nd[$k] ?? ''));
    $ndCos = liveRows($store, 'companies');
    $ndBrs = liveRows($store, 'branches');
    $ndDes = liveRows($store, 'designations');
    usort($ndDes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name'])); ?>
  <form method="post" id="lk-new">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="offer-new">
    <div class="lk-grid">
      <div><label>Candidate name *</label><input name="name" required maxlength="120" value="<?= $ndv('name') ?>"></div>
      <div><label>Email *</label><input name="email" type="email" required maxlength="160" value="<?= $ndv('email') ?>"></div>
      <div><label>Mobile</label><input name="mobile" inputmode="tel" maxlength="20" value="<?= $ndv('mobile') ?>"></div>
      <div><label>Company *</label><select name="employer_company_id" required data-lk-co><option value="">— choose —</option><?php foreach ($ndCos as $c): ?><option value="<?= e((string) $c['id']) ?>"<?= (string) ($nd['employer_company_id'] ?? '') === (string) $c['id'] ? ' selected' : '' ?>><?= e((string) $c['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Branch *</label><select name="branch_id" required data-lk-br><option value="">— choose —</option><?php foreach ($ndBrs as $b): ?><option value="<?= e((string) $b['id']) ?>" data-co="<?= e((string) (($b['employer_company_id'] ?? '') ?: ($b['company_id'] ?? ''))) ?>"<?= (string) ($nd['branch_id'] ?? '') === (string) $b['id'] ? ' selected' : '' ?>><?= e((string) $b['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Designation *</label><select name="designation_id" required><option value="">— choose —</option><?php foreach ($ndDes as $d): ?><option value="<?= e((string) $d['id']) ?>"<?= (string) ($nd['designation_id'] ?? '') === (string) $d['id'] ? ' selected' : '' ?>><?= e((string) $d['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Date of joining *</label><input name="doj" type="date" required value="<?= $ndv('doj') ?>"></div>
      <div><label>Annual CTC (₹) *</label><input name="ctc" inputmode="decimal" required value="<?= $ndv('ctc') ?>" placeholder="e.g. 360000"></div>
      <div><label>Signed for the company by</label><?= $lkSigSelect((string) ($nd['sig'] ?? '')) ?></div>
      <div style="grid-column:1/-1"><label>Address *</label><textarea name="address" rows="2" required maxlength="400"><?= $ndv('address') ?></textarea></div>
      <?= $lkCc() ?>
    </div>
    <div class="lk-acts">
      <button class="btn btn-outline" type="submit" name="op" value="preview"<?= $mayIssue ? '' : ' disabled' ?>><i class="fas fa-eye"></i> Preview</button>
      <button class="go" type="submit" name="op" value="send"<?= $mayIssue ? '' : ' disabled title="Needs ' . e(implode(', ', $needed)) . '"' ?>><i class="fas fa-paper-plane"></i> Send to the candidate</button>
      <?= $nd !== [] ? '<button class="btn btn-outline" type="submit" name="op" value="clear" formnovalidate>Clear</button>' : '' ?>
    </div>
  </form>
<?php if (isset($nd['body']) && (string) $nd['body'] !== ''): ?>
  <h3 style="margin:16px 0 8px">Preview</h3><pre class="lk-letter"><?= e((string) $nd['body']) ?></pre>
<?php elseif (($nd['missing'] ?? []) !== []): ?>
  <div class="problem" style="margin-top:12px"><b>Not drafted.</b> Still needed: <b><?= e(implode(', ', (array) $nd['missing'])) ?></b>.</div>
<?php endif; ?>
  <script>
  (function () { // Branch lists only the chosen company's branches (dropdowns are dependent everywhere)
    var co = document.querySelector('[data-lk-co]'), br = document.querySelector('[data-lk-br]');
    var sync = function () { [].forEach.call(br.options, function (o) { var hide = o.value !== '' && co.value !== '' && o.dataset.co !== '' && o.dataset.co !== co.value; o.hidden = o.disabled = hide; }); if (br.selectedOptions[0] && br.selectedOptions[0].disabled) { br.value = ''; } };
    co.addEventListener('change', sync); sync();
  })();
  </script>
<?php endif; ?>
</div>
