<?php declare(strict_types=1);
// LOANS / SALARY ADVANCE RECOVERY (owner, 22 Sep) — Compensation & Claims.
// Two buttons: Advance Salary Recovery and Loan Recovery. Pick the employee (ID ·
// name), then the advance / loan, then the amount: deducted from a month's payroll
// (becomes that month's ADV-<id> / LOAN-<id> payslip line in place of the EMI) or
// already collected outside payroll (lowers the balance). Entries are never edited
// or deleted — a mistake is cancelled, with who/when/why (law 3). The engine side
// is SmartPRS\Surface\RecoveryEntries + Recoveries.
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\RecoveryEntries;
use SmartPRS\Surface\Recoveries;

if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2>Loans / Salary Advance Recovery</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif;

$lrMayEdit = $may('payroll.run');
$lrEntries = RecoveryEntries::over($store);
$lrRec = new Recoveries($store, $lrEntries);
$lrPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
usort($lrPeople, static fn (array $a, array $b): int => strcmp(Employment::code($a), Employment::code($b)));
$lrById = [];
foreach ($lrPeople as $p) { $lrById[(string) $p['id']] = $p; }
$lrOpen = ['disbursed', 'recovering', 'active'];
$lrMoney = static fn (int $minor): string => Money::ofMinor($minor)->format();

// Every open advance / loan in scope, with its figures — for the picker and the form.
$lrObligations = ['advance' => [], 'loan' => []];
foreach (RecoveryEntries::KINDS as $lrKind => $lrK) {
    foreach ($store->all($lrK['table']) as $row) {
        $emp = $lrById[(string) ($row['employee_id'] ?? '')] ?? null;
        if ($emp === null || !in_array((string) ($row['status'] ?? ''), $lrOpen, true)) { continue; }
        $opening = (string) ($row[$lrKind === 'loan' ? 'outstanding' : 'balance'] ?? '') !== '' ? (string) $row[$lrKind === 'loan' ? 'outstanding' : 'balance'] : (string) ($row['amount'] ?? '0');
        $out = $lrRec->outstandingMinor($lrKind, $row, $emp);
        if ($out <= 0) { continue; }
        $lrObligations[$lrKind][(string) $row['id']] = ['row' => $row, 'emp' => $emp, 'opening' => Amount::of($opening)->minor,
            'outstanding' => $out, 'available' => $lrRec->availableMinor($lrKind, $row, $emp)];
    }
}

$lrAdd = in_array((string) ($_GET['add'] ?? ''), ['advance', 'loan'], true) ? (string) $_GET['add'] : '';
$lrEmp = (string) ($_GET['emp'] ?? post('employee_id'));
$lrOb = (string) ($_GET['ob'] ?? post('obligation_id'));
// The payroll month offered first: this month, or the next one if this month is already generated.
$lrNextMonth = date('Y-m');
while ($register->find($lrNextMonth) !== null) { $lrNextMonth = date('Y-m', strtotime($lrNextMonth . '-01 +1 month')); }

$lrFilter = orgFilter();
$lrInView = array_flip(array_map(static fn (array $p): string => (string) $p['id'], orgFilterApply($store, $lrPeople, $lrFilter)));
$lrKindFilter = in_array((string) ($_GET['kind'] ?? ''), ['advance', 'loan'], true) ? (string) $_GET['kind'] : '';
$lrRows = array_values(array_filter($lrEntries->all(), static fn (array $r): bool => isset($lrInView[(string) ($r['employee_id'] ?? '')])
    && ($lrKindFilter === '' || (string) ($r['kind'] ?? '') === $lrKindFilter)));
usort($lrRows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')) ?: strcmp((string) $b['id'], (string) $a['id']));

// Where a payroll entry stands against its month.
$lrState = static function (array $r) use ($register): array {
    if ((string) ($r['status'] ?? '') === RecoveryEntries::CANCELLED) { return ['Cancelled', 'badge-gray', 'Cancelled by ' . ($r['cancelled_by'] ?? '') . ': ' . ($r['cancel_reason'] ?? '')]; }
    if ((string) ($r['mode'] ?? '') === RecoveryEntries::COLLECTED) { return ['Collected', 'badge-green', 'Received outside payroll — balance reduced']; }
    $run = $register->find((string) $r['period']);
    if ($run === null) { return ['Waiting for payroll', 'badge-amber', 'Deducted when ' . $r['period'] . ' payroll is generated']; }
    $after = strtotime((string) ($run['closed_at'] ?? '')) >= strtotime((string) ($r['created_at'] ?? '1970-01-01'));
    if ($after) { return ['In payroll', 'badge-green', 'On the ' . $r['period'] . ' payslip']; }
    if (isset($run['approved_by'])) { return ['Not in payroll', 'badge-red', $r['period'] . ' was approved before this entry — cancel it and record it against a later month']; }
    return ['Recompute payroll', 'badge-amber', $r['period'] . ' was generated before this entry — open Generate Payroll and click Edit on ' . $r['period'] . ' to include it'];
};
?>
<div class="page-header">
  <div><h2>Loans / Salary Advance Recovery</h2><p>Record an amount against a salary advance or a loan — deducted from a month's payroll, or already collected. Balances, payroll and payslips follow automatically.</p></div>
<?php if ($lrMayEdit): ?>
  <div class="actions">
    <a class="btn" style="background:#fff7ed;color:#c2410c;border:1px solid #fdba74" href="?r=loan-recovery&amp;add=advance#lr-form"><i class="fas fa-hand-holding-dollar"></i> Advance Salary Recovery</a>
    <a class="btn" style="background:#eff6ff;color:#1F3A5F;border:1px solid #93c5fd" href="?r=loan-recovery&amp;add=loan#lr-form"><i class="fas fa-building-columns"></i> Loan Recovery</a>
  </div>
<?php endif; ?>
</div>
<?php if ($lrEntries->inFiles()): ?>
<div class="card" style="border-left:4px solid #f59e0b"><b>Kept in the file store for now.</b> The database table for recovery entries arrives with the next update — run <code>composer db:migrate</code> on the server. Entries saved until then are kept and keep working.</div>
<?php endif; ?>

<?php if ($lrAdd !== '' && $lrMayEdit):
    $lrK = RecoveryEntries::KINDS[$lrAdd];
    $lrMine = array_filter($lrObligations[$lrAdd], static fn (array $o): bool => (string) $o['emp']['id'] === $lrEmp);
    $lrChoices = [];
    foreach ($lrObligations[$lrAdd] as $o) { $lrChoices[(string) $o['emp']['id']] = Employment::label($o['emp']); }
    if (count($lrMine) === 1 && !isset($lrMine[$lrOb])) { $lrOb = (string) array_key_first($lrMine); }
?>
<div class="card" id="lr-form" style="border-top:4px solid <?= $lrAdd === 'advance' ? '#f97316' : '#1F3A5F' ?>">
  <h2 style="margin-top:0"><?= e($lrK['title']) ?></h2>
  <form method="get" style="margin-bottom:12px"><input type="hidden" name="r" value="loan-recovery"><input type="hidden" name="add" value="<?= e($lrAdd) ?>">
    <label>Employee ID · Name <span style="color:#dc2626">*</span></label>
    <?= employeePicker('lr-emp', 'emp', $lrChoices, $lrEmp, '— pick the employee —', true, 'onchange="this.form.submit()" style="max-width:420px"') ?>
<?php if ($lrChoices === []): ?><div class="note" style="margin-top:6px">Nobody in your scope has a disbursed <?= e($lrK['noun']) ?> with a balance to recover.</div><?php endif; ?>
  </form>
<?php if ($lrEmp !== '' && $lrMine === []): ?>
  <div class="note">This employee has no disbursed <?= e($lrK['noun']) ?> with a balance to recover.</div>
<?php elseif ($lrMine !== []): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="loan-recovery-save">
    <input type="hidden" name="kind" value="<?= e($lrAdd) ?>"><input type="hidden" name="employee_id" value="<?= e($lrEmp) ?>">
    <div class="table-wrap"><table>
      <thead><tr><th></th><th><?= e(ucfirst($lrK['noun'])) ?></th><th>Recovery</th><th class="n">Sanctioned</th><th class="n">Recovered so far</th><th class="n">Balance</th><th class="n">Booked for coming payroll</th><th class="n">Can still record</th></tr></thead>
      <tbody>
<?php foreach ($lrMine as $id => $o): $r = $o['row']; $sanc = Amount::of((string) ($r['amount'] ?? '0'))->minor; ?>
      <tr>
        <td><input type="radio" name="obligation_id" value="<?= e((string) $id) ?>" <?= (string) $id === $lrOb ? 'checked' : '' ?> required style="width:auto" data-avail="<?= e(Money::ofMinor($o['available'])->toDecimalString()) ?>" onchange="lrAvail()"></td>
        <td><b>#<?= e((string) $id) ?></b> <span class="note"><?= e((string) ($r['remarks'] ?? '')) ?></span></td>
        <td><?= (string) ($r['recovery_mode'] ?? '') === 'manual' ? '<span class="badge badge-amber">Manual entry</span>' : 'EMI ' . e((string) ($r[$lrAdd === 'loan' ? 'emi' : 'per_month'] ?? '') === '' ? '—' : Amount::of((string) $r[$lrAdd === 'loan' ? 'emi' : 'per_month'])->format()) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($lrMoney($sanc)) ?></td>
        <td class="n" style="font-family:var(--mono)"><?= e($lrMoney(max(0, $o['opening'] - $o['outstanding']))) ?></td>
        <td class="n" style="font-family:var(--mono)"><b><?= e($lrMoney($o['outstanding'])) ?></b></td>
        <td class="n" style="font-family:var(--mono)"><?= e($lrMoney($o['outstanding'] - $o['available'])) ?></td>
        <td class="n" style="font-family:var(--mono);color:#15803d"><b><?= e($lrMoney($o['available'])) ?></b></td>
      </tr>
<?php endforeach; ?>
      </tbody></table></div>
    <fieldset class="form-section" style="margin-top:12px"><legend>Recovery</legend>
<?php $lrMode = post('mode') === 'collected' ? 'collected' : 'payroll'; ?>
      <div class="row" style="margin-bottom:10px">
        <label style="flex:1;display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input type="radio" name="mode" value="payroll" <?= $lrMode === 'payroll' ? 'checked' : '' ?> onchange="lrMode()" style="width:auto;margin-top:3px">
          <span><b>Deduct from payroll</b><br><span class="note">Taken from the chosen month's salary, in place of that month's EMI, and shown on the payslip.</span></span></label>
        <label style="flex:1;display:flex;gap:8px;align-items:flex-start;cursor:pointer"><input type="radio" name="mode" value="collected" <?= $lrMode === 'collected' ? 'checked' : '' ?> onchange="lrMode()" style="width:auto;margin-top:3px">
          <span><b>Amount collected</b><br><span class="note">Already received in cash / bank / UPI. Not on a payslip; the balance goes down and later EMIs adjust.</span></span></label>
      </div>
      <div class="row">
        <div><label>Amount (₹) <span style="color:#dc2626">*</span></label><input name="amount" id="lr-amount" required inputmode="decimal" placeholder="e.g. 5000" value="<?= e(post('amount')) ?>"><div class="note" id="lr-avail"></div></div>
        <div class="lr-payroll"><label>Payroll month <span style="color:#dc2626">*</span></label><input type="month" name="period" value="<?= e(post('period') ?: $lrNextMonth) ?>"></div>
        <div class="lr-collected"><label>Collected on <span style="color:#dc2626">*</span></label><input type="date" name="collected_on" value="<?= e(post('collected_on') ?: date('Y-m-d')) ?>"></div>
        <div class="lr-collected"><label>Reference (receipt / UTR)</label><input name="reference" maxlength="120" value="<?= e(post('reference')) ?>"></div>
      </div>
      <div class="row"><div style="flex-basis:100%"><label>Remarks</label><input name="remarks" maxlength="255" value="<?= e(post('remarks')) ?>"></div></div>
    </fieldset>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><a class="btn btn-outline" href="?r=loan-recovery">Cancel</a><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Save recovery</button></div>
  </form>
<script>
function lrMode() { var c = (document.querySelector('input[name=mode]:checked') || {}).value === 'collected';
  document.querySelectorAll('.lr-payroll').forEach(function (x) { x.style.display = c ? 'none' : ''; x.querySelectorAll('input').forEach(function (i) { i.required = !c; }); });
  document.querySelectorAll('.lr-collected').forEach(function (x) { x.style.display = c ? '' : 'none'; });
  var d = document.querySelector('input[name=collected_on]'); if (d) { d.required = c; } }
function lrAvail() { var r = document.querySelector('input[name=obligation_id]:checked');
  document.getElementById('lr-avail').textContent = r ? 'Up to ₹' + r.dataset.avail + ' can still be recorded.' : ''; }
lrMode(); lrAvail();
</script>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="card"><?= orgFilterBar('loan-recovery', $lrFilter, orgFilterOptions($store, $lrPeople), ['kind' => $lrKindFilter]) ?>
  <div style="margin-top:8px;display:flex;gap:6px">
<?php foreach (['' => 'All', 'advance' => 'Salary Advance', 'loan' => 'Loans'] as $k => $l): ?>
    <a class="btn btn-sm <?= $lrKindFilter === $k ? 'btn-primary' : 'btn-outline' ?>" href="?r=loan-recovery&amp;kind=<?= e($k) ?>"><?= e($l) ?></a>
<?php endforeach; ?>
  </div>
</div>
<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Entered</th><th>Employee</th><th>For</th><th>How</th><th>Month</th><th class="n">Amount</th><th>Reference / remarks</th><th>Status</th><th>By</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
<?php if ($lrRows === []): ?><tr><td colspan="10" class="note" style="padding:18px">No recovery recorded yet.</td></tr><?php endif; ?>
<?php foreach ($lrRows as $r): [$stLabel, $stBadge, $stWhy] = $lrState($r); $emp = $lrById[(string) $r['employee_id']] ?? null; $live = (string) ($r['status'] ?? '') !== RecoveryEntries::CANCELLED; ?>
    <tr<?= $live ? '' : ' style="opacity:.6"' ?>>
      <td class="note"><?= e(substr((string) ($r['created_at'] ?? ''), 0, 16)) ?></td>
      <td><b><?= e($emp === null ? '#' . $r['employee_id'] : Employment::label($emp)) ?></b></td>
      <td><?= (string) $r['kind'] === 'loan' ? 'Loan' : 'Salary advance' ?> #<?= e((string) $r['obligation_id']) ?></td>
      <td><?= (string) $r['mode'] === RecoveryEntries::COLLECTED ? 'Collected' . ((string) ($r['collected_on'] ?? '') !== '' ? ' on ' . e((string) $r['collected_on']) : '') : 'Payroll deduction' ?></td>
      <td><?= e((string) $r['period']) ?></td>
      <td class="n" style="font-family:var(--mono)"><b><?= e(Amount::of((string) $r['amount'])->format()) ?></b></td>
      <td class="note"><?= e(trim((string) ($r['reference'] ?? '') . ' ' . (string) ($r['remarks'] ?? ''))) ?></td>
      <td><span class="badge <?= $stBadge ?>" title="<?= e($stWhy) ?>"><?= e($stLabel) ?></span></td>
      <td class="note"><?= e((string) ($r['created_by'] ?? '')) ?></td>
      <td style="text-align:right;white-space:nowrap">
<?php if ($live && $lrMayEdit && ((string) $r['mode'] === RecoveryEntries::COLLECTED || !isset(($register->find((string) $r['period']) ?? [])['approved_by']))): ?>
        <details style="display:inline-block;text-align:left"><summary class="btn btn-sm" style="color:#b91c1c" title="Cancel this entry — it is kept in the history"><i class="fas fa-ban"></i></summary>
          <form method="post" style="margin-top:6px;display:flex;gap:4px"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="loan-recovery-cancel"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
            <input name="reason" required placeholder="Why?" style="width:160px"><button class="btn btn-sm" type="submit" style="color:#b91c1c">Cancel entry</button></form></details>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<p class="note">A payroll deduction replaces that month's EMI for the advance / loan and is never more than the balance. A collected amount lowers the balance from the month received, so later EMIs and the last instalment adjust. Entries are never edited or deleted — cancel one and record it again; if the month's payroll was already generated, use Edit on Generate Payroll so the payslip follows.</p>
