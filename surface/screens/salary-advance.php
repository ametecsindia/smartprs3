<?php declare(strict_types=1);
// SALARY ADVANCE — the owner's flow (9 Sep 2026): pick the employee (their reporting
// manager, designation, department and branch appear), reason, amount requested and
// sanctioned, three approval levels (Manager / Director / HR Admin — signers are the
// employees whose designation says so, from config/salary-advance.json), tenure → EMI
// computed, EMI start month. The table below lists every advance with status,
// recovered so far (from closed payroll runs) and balance.
// ONE SCREEN, TWO KINDS: surface/screens/loans.php sets $saKind and includes this file —
// the owner wants Loans to be exactly the Salary Advance flow (10 Sep).
$saKind ??= ['table' => 'advances', 'route' => 'salary-advance', 'config' => 'salary-advance.json', 'title' => 'Salary Advance', 'noun' => 'advance', 'Noun' => 'Advance'];
if (!$may('payroll.view')): ?>
<div class="page-header"><div><h2><?= e($saKind['title']) ?></h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.view')->summary) ?></div></div>
<?php return; endif;

$saMayEdit = $may('payroll.run');
$salaryAdvance = new SmartPRS\Surface\SalaryAdvance($store, $modules, __DIR__ . '/../../config/' . $saKind['config']);
$saCompensation = new SmartPRS\Surface\Compensation($store, __DIR__ . '/../../config/compensation.json');
$saCfg = $salaryAdvance->config();
$saPeople = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
usort($saPeople, static fn (array $a, array $b): int => strcmp(\SmartPRS\Surface\Employment::code($a), \SmartPRS\Surface\Employment::code($b)));
$saById = [];
foreach ($saPeople as $e) { $saById[(string) $e['id']] = $e; }
$saLabel = static fn (?array $e): string => $e === null ? '—' : \SmartPRS\Surface\Employment::label($e);
$saFilter = orgFilter();
$saOptions = orgFilterOptions($store, $saPeople);
$saInView = [];
foreach (orgFilterApply($store, $saPeople, $saFilter) as $e) { $saInView[(string) $e['id']] = true; }
$saRows = array_values(array_filter($store->all($saKind['table']), static fn (array $a): bool => isset($saInView[(string) ($a['employee_id'] ?? '')])));
usort($saRows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
$saEditId = (string) ($_GET['edit'] ?? '');
$saEdit = $saEditId === '' ? null : $store->find($saKind['table'], $saEditId);
$saShowForm = $saMayEdit && ($saEdit !== null || (string) ($_GET['add'] ?? '') === '1');
// The employee's record for the form: from the edited row, or the one just posted back.
$saPickedId = (string) ($saEdit['employee_id'] ?? post('employee_id'));
$saPicked = $saById[$saPickedId] ?? null;
$saStatusBadge = static fn (string $s): string => match ($s) { 'approved', 'disbursed', 'recovering' => 'badge-green', 'recovered' => 'badge-blue', 'rejected' => 'badge-red', 'on hold' => 'badge-amber', default => 'badge-gray' };
// Employee facts as JSON, so picking a name fills the four boxes without a round trip.
$saFacts = [];
foreach ($saPeople as $e) {
    $saFacts[(string) $e['id']] = ['manager' => $saLabel($saById[(string) ($e['reporting_manager_id'] ?? '')] ?? null), 'designation' => orgValueOf($store, $e, 'designation') ?: '—',
        'department' => orgValueOf($store, $e, 'department') ?: '—', 'branch' => orgValueOf($store, $e, 'branch') ?: '—'];
}
?>
<div class="page-header">
  <div><h2><?= e($saKind['title']) ?></h2><p><?= count($saRows) ?> <?= e($saKind['noun']) ?>(s) &middot; requested, sanctioned, approved by HR Admin / HR Manager (Manager and Director optional), recovered on the payslip</p></div>
  <div class="actions"><?php if ($saMayEdit): ?><a class="btn btn-primary" href="?r=<?= e($saKind['route']) ?>&amp;add=1#sa-form"><i class="fas fa-plus"></i> New <?= e($saKind['Noun']) ?></a><?php endif; ?>
    <a class="btn btn-outline" href="?r=claims"><i class="fas fa-check-double"></i> Approvals queue</a></div>
</div>
<div class="card"><?= orgFilterBar($saKind['route'], $saFilter, $saOptions) ?></div>

<?php if ($saShowForm): ?>
<div class="card" id="sa-form">
  <h2 style="margin-top:0"><?= $saEdit === null ? 'New ' . e($saKind['Noun']) : 'Edit ' . e($saKind['Noun']) . ' #' . e((string) $saEdit['id']) ?></h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="<?= e($saKind['route']) ?>-save"><input type="hidden" name="id" value="<?= e((string) ($saEdit['id'] ?? '')) ?>">
    <fieldset class="form-section"><legend>Employee</legend>
      <div class="row">
        <div><label>Employee ID <span style="color:#dc2626">*</span></label>
          <select name="employee_id" id="sa-emp" required onchange="saFill()"><option value="">— pick —</option>
<?php foreach ($saPeople as $e): ?><option value="<?= e((string) $e['id']) ?>" <?= (string) $e['id'] === $saPickedId ? 'selected' : '' ?>><?= e(\SmartPRS\Surface\Employment::label($e)) ?></option><?php endforeach; ?></select></div>
        <div><label>Reporting Manager</label><input id="sa-manager" readonly value="<?= e($saPicked === null ? '' : $saFacts[(string) $saPicked['id']]['manager']) ?>"></div>
        <div><label>Designation</label><input id="sa-designation" readonly value="<?= e($saPicked === null ? '' : $saFacts[(string) $saPicked['id']]['designation']) ?>"></div>
        <div><label>Department</label><input id="sa-department" readonly value="<?= e($saPicked === null ? '' : $saFacts[(string) $saPicked['id']]['department']) ?>"></div>
        <div><label>Branch</label><input id="sa-branch" readonly value="<?= e($saPicked === null ? '' : $saFacts[(string) $saPicked['id']]['branch']) ?>"></div>
      </div>
      <div class="row"><div style="flex-basis:100%"><label>Reason <span style="color:#dc2626">*</span></label><textarea name="remarks" rows="2" required style="width:100%"><?= e((string) ($saEdit['remarks'] ?? post('remarks'))) ?></textarea></div></div>
    </fieldset>
    <fieldset class="form-section"><legend>Amount</legend>
      <div class="row">
        <div><label>Requested on</label><input type="date" name="requested_on" value="<?= e((string) ($saEdit['requested_on'] ?? post('requested_on') ?: date('Y-m-d'))) ?>"></div>
        <div><label>Amount Requested (₹) <span style="color:#dc2626">*</span></label><input name="amount_requested" id="sa-req" required inputmode="decimal" placeholder="e.g. 15000" value="<?= e((string) ($saEdit['amount_requested'] ?? post('amount_requested'))) ?>"></div>
        <div><label>Amount Sanctioned / Approved (₹)</label><input name="amount" id="sa-sanc" inputmode="decimal" placeholder="≤ requested" value="<?= e((string) ($saEdit['amount'] ?? post('amount'))) ?>" oninput="saEmi()"></div>
      </div>
    </fieldset>
    <fieldset class="form-section"><legend>Approvals</legend>
      <div class="row">
<?php $saDeciding = (string) ($salaryAdvance->decidingLevel()['key'] ?? ''); ?>
<?php foreach ($salaryAdvance->levels() as $lvl): $k = (string) $lvl['key']; $signers = $salaryAdvance->signers($lvl, $saPeople); $saDecides = $saDeciding === '' || $saDeciding === $k; ?>
        <div style="border:<?= $saDeciding === $k ? '2px solid #15803d' : '1px solid #e2e8f0' ?>;border-radius:8px;padding:10px">
          <label style="font-size:13px"><?= e((string) $lvl['label']) ?>
<?php if ($saDeciding !== ''): ?> <span class="badge <?= $saDecides ? 'badge-green' : 'badge-gray' ?>" style="font-size:10px"><?= $saDecides ? 'Decides' : 'Optional' ?></span><?php endif; ?></label>
          <select name="<?= $k ?>_id" style="margin-bottom:6px"><option value="">— who —</option>
<?php foreach ($signers as $sg): ?><option value="<?= e((string) $sg['id']) ?>" <?= (string) ($saEdit[$k . '_id'] ?? post($k . '_id')) === (string) $sg['id'] ? 'selected' : '' ?>><?= e(\SmartPRS\Surface\Employment::label($sg)) ?></option><?php endforeach; ?>
</select>
<?php if (($signers[0]['_unmatched'] ?? false) === true): ?><div class="note">Nobody's designation or role says <?= e(implode(' / ', (array) $lvl['designation_words'])) ?> yet, so everyone is listed.</div><?php endif; ?>
          <select name="<?= $k ?>_status"><option value="">— status —</option>
<?php foreach ((array) $saCfg['decisions'] as $dec): ?><option <?= (string) ($saEdit[$k . '_status'] ?? post($k . '_status')) === $dec ? 'selected' : '' ?>><?= e($dec) ?></option><?php endforeach; ?></select>
<?php if (($saEdit[$k . '_at'] ?? '') !== ''): ?><div class="note" style="margin-top:4px">decided <?= e(substr((string) $saEdit[$k . '_at'], 0, 16)) ?></div><?php endif; ?>
        </div>
<?php endforeach; ?>
      </div>
    </fieldset>
<?php $saMode = (string) ($saEdit['recovery_mode'] ?? post('recovery_mode')) === 'manual' ? 'manual' : 'emi'; ?>
    <fieldset class="form-section"><legend>Recovery</legend>
      <div class="row" style="margin-bottom:10px">
        <label class="sa-opt" style="flex:1;display:flex;gap:10px;align-items:flex-start;border:2px solid #cbd5e1;border-radius:8px;padding:10px 12px;cursor:pointer;background:#fff">
          <input type="radio" name="recovery_mode" value="emi" <?= $saMode === 'emi' ? 'checked' : '' ?> onchange="saMode()" style="width:auto;margin-top:3px">
          <span><b>Option 1 — Tenure &amp; EMI</b><br><span class="note">Pick the tenure; the EMI is computed and payroll recovers it every month from the EMI start.</span></span></label>
        <label class="sa-opt" style="flex:1;display:flex;gap:10px;align-items:flex-start;border:2px solid #cbd5e1;border-radius:8px;padding:10px 12px;cursor:pointer;background:#fff">
          <input type="radio" name="recovery_mode" value="manual" <?= $saMode === 'manual' ? 'checked' : '' ?> onchange="saMode()" style="width:auto;margin-top:3px">
          <span><b>Option 2 — Manual entry</b><br><span class="note">No EMI. Each amount is entered on <a href="?r=loan-recovery">Loans / Salary Advance Recovery</a> — deducted from a month's payroll, or recorded as collected.</span></span></label>
      </div>
      <div class="row" id="sa-emi-row">
        <div><label>Tenure (months)</label><select name="tenure_months" id="sa-tenure" onchange="saEmi()"><option value="">— pick —</option>
<?php foreach ((array) $saCfg['tenure_months'] as $t): ?><option value="<?= (int) $t ?>" <?= (int) ($saEdit['tenure_months'] ?? post('tenure_months')) === (int) $t ? 'selected' : '' ?>><?= (int) $t ?></option><?php endforeach; ?></select></div>
        <div><label>EMI — recovery per month (₹)</label><input id="sa-emi" readonly value="<?= e((string) ($saEdit['per_month'] ?? '')) ?>" placeholder="computed from sanctioned ÷ tenure" style="background:#f8fafc;font-weight:700">
          <div class="note">Sanctioned ÷ tenure, rounded up to the rupee; the last instalment is the remainder. Never typed.</div></div>
        <div><label>EMI start (month)</label><input type="month" name="emi_start" value="<?= e((string) ($saEdit['emi_start'] ?? post('emi_start') ?: date('Y-m', strtotime('first day of next month')))) ?>"></div>
      </div>
      <div class="note" id="sa-manual-note" style="padding:8px 10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px">Manual recovery: nothing is deducted until an amount is entered on <b>Compensation &amp; Claims → Loans / Salary Advance Recovery</b>. Payroll then deducts exactly that amount in the month chosen (never more than the balance), and cash collected directly lowers the balance.</div>
    </fieldset>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><a class="btn btn-outline" href="?r=<?= e($saKind['route']) ?>">Cancel</a><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Save <?= e($saKind['Noun']) ?></button></div>
  </form>
</div>
<script>
var SA_FACTS = <?= json_encode($saFacts, JSON_UNESCAPED_UNICODE) ?>;
function saFill() { var f = SA_FACTS[document.getElementById('sa-emp').value] || {manager: '', designation: '', department: '', branch: ''};
  ['manager', 'designation', 'department', 'branch'].forEach(function (k) { document.getElementById('sa-' + k).value = f[k]; }); }
function saMode() {
  var manual = (document.querySelector('input[name=recovery_mode]:checked') || {}).value === 'manual';
  document.getElementById('sa-emi-row').style.display = manual ? 'none' : '';
  document.getElementById('sa-manual-note').style.display = manual ? '' : 'none';
  document.querySelectorAll('.sa-opt').forEach(function (l) { var on = l.querySelector('input').checked;
    l.style.borderColor = on ? '#f97316' : '#cbd5e1'; l.style.background = on ? '#fff7ed' : '#fff'; });
}
saMode();
function saEmi() {
  // A PREVIEW ONLY — the server derives the stored EMI the same way (SalaryAdvance::emiMinor); this box is never posted.
  var s = (document.getElementById('sa-sanc').value || '').trim(), t = parseInt(document.getElementById('sa-tenure').value || '0', 10);
  var out = document.getElementById('sa-emi');
  if (!/^\d+(\.\d{1,2})?$/.test(s) || t < 1) { out.value = ''; return; }
  var parts = s.split('.'), paise = parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
  var per = Math.floor(paise / t), rupees = Math.floor((per + 99) / 100), emi = Math.min(rupees * 100, paise);
  out.value = (emi / 100).toFixed(2);
}
</script>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th style="min-width:170px">Employee</th><th style="min-width:160px">Reporting Manager</th><th style="min-width:120px">Designation</th><th style="min-width:120px">Department</th><th>Branch</th><th style="min-width:150px">Reason</th>
      <th class="n">Requested</th><th class="n">Sanctioned</th><th>Manager</th><th>Director</th><th>HR Admin</th><th class="n">Tenure</th><th class="n">EMI</th><th>EMI start</th>
      <th>Status</th><th class="n">Recovered so far</th><th class="n">Balance</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
<?php if ($saRows === []): ?><tr><td colspan="19" class="note" style="padding:18px">No <?= e($saKind['noun']) ?> yet.</td></tr><?php endif; ?>
<?php foreach ($saRows as $adv): $ae = $saById[(string) $adv['employee_id']] ?? null; $facts = $ae === null ? ['manager' => '—', 'designation' => '—', 'department' => '—', 'branch' => '—'] : $saFacts[(string) $ae['id']];
      $sanctionedMinor = (string) ($adv['amount'] ?? '') === '' ? 0 : \SmartPRS\Surface\Amount::of((string) $adv['amount'])->minor;
      $recovered = $ae === null ? 0 : $salaryAdvance->recoveredMinor(\SmartPRS\Surface\Employment::code($ae), (string) $adv['id'], $saCompensation);
      $balance = max(0, $sanctionedMinor - $recovered); ?>
    <tr>
      <td><?= (int) $adv['id'] ?></td><td><b><?= e($saLabel($ae)) ?></b></td>
      <td class="note"><?= e($facts['manager']) ?></td><td class="note"><?= e($facts['designation']) ?></td><td class="note"><?= e($facts['department']) ?></td><td class="note"><?= e($facts['branch']) ?></td>
      <td class="note" style="max-width:220px"><?= e((string) ($adv['remarks'] ?? '')) ?></td>
      <td class="n" style="font-family:var(--mono)"><?= (string) ($adv['amount_requested'] ?? '') === '' ? '—' : e(\SmartPRS\Surface\Amount::of((string) $adv['amount_requested'])->format()) ?></td>
      <td class="n" style="font-family:var(--mono)"><?= $sanctionedMinor === 0 ? '—' : e(\SmartPRS\Platform\Support\Money::ofMinor($sanctionedMinor)->format()) ?></td>
<?php foreach ($salaryAdvance->levels() as $lvl): $k = (string) $lvl['key']; $st = (string) ($adv[$k . '_status'] ?? ''); ?>
      <td><?php if ($st !== ''): ?><span class="badge <?= $st === 'Approved' ? 'badge-green' : ($st === 'Rejected' ? 'badge-red' : 'badge-amber') ?>"><?= e($st) ?></span><div class="note" style="font-size:11px"><?= e($saLabel($saById[(string) ($adv[$k . '_id'] ?? '')] ?? null)) ?></div><?php else: ?><span class="note">—</span><?php endif; ?></td>
<?php endforeach; ?>
<?php if ((string) ($adv['recovery_mode'] ?? '') === 'manual'): ?>
      <td class="n" colspan="3" style="text-align:center"><span class="badge badge-amber" title="Option 2 — amounts are entered on Loans / Salary Advance Recovery">Manual entry</span></td>
<?php else: ?>
      <td class="n"><?= (int) ($adv['tenure_months'] ?? 0) ?: '—' ?></td>
      <td class="n" style="font-family:var(--mono)"><?= (string) ($adv['per_month'] ?? '') === '' ? '—' : e(\SmartPRS\Surface\Amount::of((string) $adv['per_month'])->format()) ?></td>
      <td><?= e((string) ($adv['emi_start'] ?? '—')) ?></td>
<?php endif; $saSt = $salaryAdvance->statusOf($adv) ?: 'pending'; $saSt = in_array($saSt, ['disbursed', 'recovering', 'active'], true) && $sanctionedMinor > 0 && $balance === 0 ? 'recovered' : $saSt; ?>
      <td><span class="badge <?= $saStatusBadge($saSt) ?>"><?= e($saSt) ?></span></td>
      <td class="n" style="font-family:var(--mono)"><?= e(\SmartPRS\Platform\Support\Money::ofMinor($recovered)->format()) ?></td>
      <td class="n" style="font-family:var(--mono)"><b><?= e(\SmartPRS\Platform\Support\Money::ofMinor($balance)->format()) ?></b></td>
      <td style="white-space:nowrap;text-align:right">
<?php if ($saMayEdit): ?>
        <a class="btn btn-outline btn-sm" href="?r=<?= e($saKind['route']) ?>&amp;edit=<?= (int) $adv['id'] ?>#sa-form"><i class="fas fa-pen"></i> Edit</a>
<?php if (in_array((string) ($adv['status'] ?? ''), ['disbursed', 'recovering', 'active'], true) && $balance > 0 && $ae !== null): ?>
        <a class="btn btn-sm" style="background:#ecfdf5;color:#15803d;border:1px solid #86efac" href="?r=loan-recovery&amp;add=<?= $saKind['table'] === 'loans' ? 'loan' : 'advance' ?>&amp;emp=<?= e((string) $ae['id']) ?>&amp;ob=<?= e((string) $adv['id']) ?>#lr-form" title="Record a recovery against this <?= e($saKind['noun']) ?>"><i class="fas fa-hand-holding-dollar"></i> Recover</a>
<?php endif; ?>
<?php if ($salaryAdvance->statusOf($adv) === 'approved'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Mark this <?= e($saKind['noun']) ?> as disbursed? Payroll starts recovering it from the EMI start month.')"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="<?= e($saKind['route']) ?>-disburse"><input type="hidden" name="id" value="<?= (int) $adv['id'] ?>">
          <button class="btn btn-sm" type="submit" style="color:#15803d"><i class="fas fa-money-bill-wave"></i> Disburse</button></form>
<?php endif; ?>
<?php endif; ?>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
</div>
<p class="note">Recovered so far is read from the closed payroll runs (line <code><?= e((string) $saCfg['line_prefix']) ?>&lt;id&gt;</code>) plus any amount recorded as collected on <a href="?r=loan-recovery">Loans / Salary Advance Recovery</a>; balance = sanctioned − recovered. <?php if (($salaryAdvance->decidingLevel()['label'] ?? '') !== ''): ?>The status follows the <?= e((string) $salaryAdvance->decidingLevel()['label']) ?> decision alone (Approved / Rejected / On Hold); Manager and Director decisions are recorded but optional.<?php else: ?>The overall status derives from the three decisions: any Rejected → rejected, any On Hold → on hold, all Approved → approved.<?php endif; ?> Disburse makes it money owed, which payroll recovers per month from the EMI start.</p>
