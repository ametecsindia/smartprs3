<?php declare(strict_types=1); if (!$may('employee.letters.view')): ?>
<div class="page-header"><div><h2>Letter Templates</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.letters.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Letter Templates register: reusable bodies per letter type. Here
// every template is config/letters.json laid over by the tenant's own wording
// (Change this wording → configure-letter), so this screen lists rather than stores.
use SmartPRS\Surface\Letters;

$all = $letters->all();
$counts = [];

try {
    foreach ($store->all('letters') as $row) {
        $counts[(string) ($row['letter_type'] ?? '')] = ($counts[(string) ($row['letter_type'] ?? '')] ?? 0) + 1;
    }
} catch (\Throwable) {
    $counts = []; // no letters table yet: nothing issued
}
?>
<?php $ltDraft = (array) ($_SESSION['letter_new_draft'] ?? []); unset($_SESSION['letter_new_draft']);
      $ltOwn = count(array_filter($all, static fn (array $t): bool => (bool) ($t['created'] ?? false))); ?>
<div class="page-header">
  <div><h2>Letter Templates</h2><p><?= count($all) ?> templates<?= $ltOwn > 0 ? ' (' . $ltOwn . ' created by you)' : '' ?>. A placeholder is <code>{{field}}</code> from the employee, with <code>|money</code>, <code>|money_words</code>, <code>|date</code> or <code>|upper</code>; a placeholder that resolves to nothing refuses the letter.</p></div>
</div>

<?php // SEND A LETTER (owner, 23 Oct): every letter type, shipped or created, in one dropdown. ?>
<div class="card">
  <h2 style="margin-top:0"><i class="fas fa-paper-plane" style="color:var(--accent)"></i> Send a letter to an employee</h2>
  <form method="get" class="row" style="align-items:flex-end;margin:0">
    <input type="hidden" name="r" value="letters">
    <div style="flex:2"><label for="lt-t">Letter type</label>
      <select id="lt-t" name="t" required><option value="">— choose —</option>
<?php foreach ($all as $t): ?>
        <option value="<?= e((string) $t['key']) ?>"><?= e((string) $t['label']) ?><?= ($t['created'] ?? false) ? ' (your template)' : '' ?></option>
<?php endforeach; ?>
      </select></div>
    <div style="flex:3"><label for="lt-e">Employee</label>
      <?= employeePicker('lt-e', 'e', ['all' => 'All employees (' . count(letterRecipients($store, $scope, $may('employee.letters.view'))) . ' active)'] + employeeChoices($scope->only(liveRows($store, 'employees'), $may('employee.letters.view'))), '', '— type to search —', true) ?></div>
    <div><label for="lt-sig">Signed for the company by</label>
      <select id="lt-sig" name="sig"><option value="">— no name —</option><?php foreach (LETTER_SIGNATORIES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label for="lt-eff">Effective date</label><input type="date" id="lt-eff" name="eff" title="Leave blank to use the employee's salary-structure effective date"></div>
    <div><button type="submit" class="btn btn-primary"><i class="fas fa-file-signature"></i> Draft it</button></div>
  </form>
  <p class="note" style="margin:8px 0 0">The draft opens with every placeholder filled in from the employee's record. Issue it there; tick <b>Email it to the employee</b> to send it to their email as well.</p>
</div>

<?php if ($may('tenant.settings.update')): ?>
<div class="card">
  <details<?= $ltDraft !== [] ? ' open' : '' ?>>
    <summary class="btn btn-primary" style="display:inline-block;cursor:pointer"><i class="fas fa-plus"></i> New letter template</summary>
    <form method="post" style="margin-top:14px">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
      <input type="hidden" name="action" value="letter-create">
<?php $ltFields = letterFieldChoices(); ?>
      <div class="row">
        <div style="flex:2"><label for="lt-label">Letter name</label>
          <input id="lt-label" name="label" required placeholder="e.g. Half Day Deduction" value="<?= e((string) ($ltDraft['label'] ?? '')) ?>"></div>
        <div style="max-width:200px"><label for="lt-lop">Loss of pay (days)</label>
          <input id="lt-lop" name="lop_days" type="number" min="0" step="0.5" placeholder="blank = none" value="<?= e((string) ($ltDraft['lop_days'] ?? '')) ?>"
            title="For a deduction letter: e.g. 0.5 for a half day. Deducted in payroll when the letter is issued."></div>
        <div style="flex:2"><label for="lt-ins">Insert an employee field into the letter</label>
          <select id="lt-ins" onchange="ltInsert(this)"><option value="">— pick a field, it goes where your cursor is —</option>
<?php foreach ($ltFields as $ltTok => $ltLabel): ?>
            <option value="{{<?= e((string) $ltTok) ?>}}"><?= e($ltLabel) ?></option>
<?php endforeach; ?>
          </select></div>
      </div>
      <div class="row" style="margin-top:12px;align-items:flex-end">
        <div style="flex:3"><label for="lt-subj">Email subject <span class="note">(blank = "letter name — employee name (code)")</span></label>
          <input id="lt-subj" name="email_subject" placeholder="e.g. Half-day deduction — {{name}} ({{emp_code}})" value="<?= e((string) ($ltDraft['email_subject'] ?? '')) ?>"></div>
        <div style="flex:2"><label>Insert a field into the subject</label><?= letterFieldPicker('lt-subj') ?></div>
      </div>
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-weight:600"><input type="checkbox" name="needs_ack" value="1"<?= ($ltDraft['needs_ack'] ?? '') === '1' ? ' checked' : '' ?>> Employee must acknowledge ("I acknowledge" in My Letters; tracked in the Sign-off report)</label>
      <label for="lt-body" style="margin-top:12px">The letter</label>
      <textarea id="lt-body" name="body" rows="16" required style="width:100%;font:13px/1.7 ui-monospace,Menlo,monospace"><?= e((string) ($ltDraft['body'] ?? "Date: {{today|date}}

To,
{{name}}
{{designation}}

Dear {{name}},



For {{company}}

Authorised Signatory")) ?></textarea>
      <p class="note" style="margin:6px 0 0">Each <code>{{…}}</code> is filled from the employee's record when the letter is drafted. If a field is blank for an employee, the letter tells you which one to fill in before it can be issued.</p>
      <script>
      function ltInsert(sel){ var t=document.getElementById('lt-body'), v=sel.value; if(!v){return;}
        var a=t.selectionStart||0, b=t.selectionEnd||0; t.value=t.value.slice(0,a)+v+t.value.slice(b); t.focus(); t.selectionStart=t.selectionEnd=a+v.length; sel.value=''; }
      </script>
      <div class="row" style="margin-top:12px">
        <div style="flex:3"><label for="lt-note">Note (kept with the template)</label><input id="lt-note" name="note" placeholder="optional"></div>
        <div style="align-self:end"><button type="submit" class="btn btn-primary">Create template</button></div>
      </div>
    </form>
  </details>
</div>
<?php endif; ?>
<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Key</th><th>Template</th><th>Requires</th><th>Placeholders</th><th>Needs</th><th class="n">Issued</th><th>Wording</th><th></th></tr></thead>
    <tbody>
<?php foreach ($all as $t): $key = (string) $t['key']; $ph = array_values(array_unique(array_column(Letters::placeholders((string) $t['body']), 'field'))); ?>
      <tr>
        <td><code><?= e($key) ?></code></td>
        <td><b><?= e((string) $t['label']) ?></b><?php if (SmartPRS\Surface\Letters::needsAck($t)): ?> <span class="badge badge-blue">needs acknowledgement</span><?php endif; ?><?php if (($ltH = (int) SmartPRS\Surface\Letters::lopHalves((string) ($t['lop_days'] ?? ''))) > 0): ?> <span class="badge badge-red">LOP <?= e((string) $t['lop_days']) ?> day(s)</span><?php endif; ?><?php if (($t['note'] ?? '') !== ''): ?><br><span class="note"><?= e((string) $t['note']) ?></span><?php endif; ?></td>
        <td class="note"><?= e(implode(', ', (array) ($t['requires'] ?? []))) ?></td>
        <td class="note" style="white-space:normal"><?= e(implode(' ', array_map(static fn (string $f): string => '{{' . $f . '}}', $ph))) ?></td>
        <td class="note"><?= e(implode(', ', Letters::permissionsFor($t))) ?></td>
        <td class="n"><?= (int) ($counts[$key] ?? 0) ?></td>
        <td><?= ($t['created'] ?? false) ? '<span class="badge badge-blue">your template</span>' : (($t['customised'] ?? false) ? '<span class="badge badge-amber">your wording</span>' : '<span class="badge badge-gray">as shipped</span>') ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-sm btn-outline" href="?r=letters&amp;t=<?= e($key) ?>">Issue</a>
<?php if ($may('tenant.settings.update')): ?>
          <a class="btn btn-sm btn-outline" href="?r=configure-letter&amp;k=<?= e($key) ?>">Change wording</a>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note">New letter template adds a letter type; Change wording edits one. Both are versioned with who and when, and a placeholder naming a field no employee has is refused at save time.</p>
</div>
