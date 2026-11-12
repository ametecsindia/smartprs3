<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Late Login Policy</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// LATE LOGIN POLICY (owner, 7 Oct) — each policy is ONE bordered block named
// "Late Login Policy N", in four headed tables: 1 Applies to · 2 Late minutes ·
// 3 Deduction criteria · 4 Attendance settings. Viewing and editing use the same
// layout; "Add" opens the next numbered block. Payroll reads the same rows
// through Surface\LatePolicy (most specific matching policy wins). Saved by
// surface/actions/late-policy-save.php — always as the criteria table (Tiered).
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\LatePolicy;

$lpCan = $may('tenant.settings.update');
$lpRows = liveRows($store, 'late_policy');
usort($lpRows, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
$lpCols = $store instanceof SmartPRS\Surface\SqlStore ? $store->columnsOf('late_policy') : null;
$lpHas = static fn (string $c): bool => $lpCols === null || in_array($c, $lpCols, true);

// CHOICES from Organization Setup — ids for the org registers, names for Role.
$lpNames = orgNames($store);
$lpCompanies = (array) ($lpNames['companies'] ?? []);
$lpBranchLabel = [];
foreach (liveRows($store, 'branches') as $b) {
    $co = $lpCompanies[(string) ($b['employer_company_id'] ?? $b['company_id'] ?? '')] ?? '';
    $lpBranchLabel[(string) $b['id']] = trim((string) ($b['name'] ?? '')) . ($co !== '' ? ' (' . $co . ')' : '');
}
$lpRoles = array_values(array_unique(array_filter((array) ($lpNames['designation_roles'] ?? []))));
$lpChoices = [
    'company' => $lpCompanies,
    'branch' => $lpBranchLabel,
    'department' => (array) ($lpNames['departments'] ?? []),
    'role' => $lpRoles === [] ? [] : array_combine($lpRoles, $lpRoles),
    'designation' => (array) ($lpNames['designations'] ?? []),
    'team' => (array) ($lpNames['teams'] ?? []),
    'employee' => [],
    'staff_type' => [],
    'employee_type' => [],
];
foreach (['company', 'department', 'role', 'designation', 'team'] as $k) { asort($lpChoices[$k]); }
asort($lpChoices['branch']);
foreach ($scope->only($store->all('employees'), $may('employee.list')) as $e) {
    $c = Employment::code($e);
    $c !== '' && $lpChoices['employee'][$c] = Employment::label($e);
}
ksort($lpChoices['employee']);
// Staff type (Office / Field) and Employee type (Permanent / Contract …): the
// option lists Employee fields declares.
foreach ((array) (json_decode((string) @file_get_contents(__DIR__ . '/../../config/employee-fields.json'), true)['sections'] ?? []) as $sec) {
    foreach ((array) ($sec['fields'] ?? []) as $f) {
        $slot = ['type' => 'staff_type', 'employment_type' => 'employee_type'][(string) ($f['code'] ?? '')] ?? null;
        $slot !== null && $lpChoices[$slot] = array_combine((array) $f['options'], (array) $f['options']) ?: [];
    }
}

/**
 * ANY POLICY AS THE CRITERIA TABLE: up to three [late by ≥ min, deduct days]
 * rows plus how often. An older Simple row reads as one criterion.
 *
 * @return array{rows: list<array{0: string, 1: string}>, every: string, n: string}
 */
$lpCriteriaOf = static function (array $p): array {
    $mode = (string) ($p['mode'] ?? '') ?: 'Simple';
    $cut = (string) ($p['cut_mode'] ?? '');
    $n = (string) ((int) ($p['cut_n'] ?? 0) ?: '');

    if ($mode === 'Tiered') {
        return ['rows' => array_map(static fn (int $t): array => [trim((string) ($p["l{$t}_min"] ?? '')), trim((string) ($p["l{$t}_cut"] ?? ''))], [1, 2, 3]),
            'every' => str_ends_with($cut, '_n') ? 'Every_n' : 'Every', 'n' => $n];
    }

    if ($mode === 'Net_hours') {
        return ['rows' => [['', ''], ['', ''], ['', '']], 'every' => 'Every', 'n' => ''];
    }

    $cut = $cut !== '' && $cut !== 'None' ? $cut
        : (['Half_day' => 'Half_day_per_late', 'Full_day' => 'Full_day_per_late', 'One_day_per_n' => 'One_day_per_n'][(string) ($p['addl_late'] ?? '')] ?? 'None');
    $days = ['Half_day_per_late' => '0.5', 'Full_day_per_late' => '1', 'One_day_per_n' => '1', 'Half_day_per_n' => '0.5'][$cut] ?? '';

    return ['rows' => [['', $days], ['', ''], ['', '']], 'every' => str_ends_with($cut, '_n') ? 'Every_n' : 'Every', 'n' => $n];
};

// LATE MINUTES COUNTED FROM (owner, 14 Oct): per policy, so it follows the policy's Company / Branch / … filters.
$lpBasis = LatePolicy::basis($store);
$lpFromWord = ['start' => 'Start of shift', 'grace' => 'After grace period'];
// TIME BASED OR COUNT BASED (owner, 14 Oct): what the deduction criteria measure, per policy.
$lpDeductBy = LatePolicy::deductBy($store);
$lpByWord = ['time' => 'Time late (minutes)', 'count' => 'Count of late logins in the month'];
$lpPlans = LatePolicy::plans($store);
$lpPlanWord = [
    'time' => ['By how late', 'each late login costs by its minutes'],
    'count' => ['By number of late logins', 'e.g. 3 late logins = ½ day, 6 = 1 day'],
    'every' => ['Every N late logins', 'e.g. every 3 late logins = ½ day'],
];
// ½ DAY STEPS UP TO 30 DAYS (owner, 14 Oct): ½, 1, 1½ … 30.
$lpDays = ['' => '— nothing —'];
for ($h = 1; $h <= 60; $h++) {
    $whole = intdiv($h, 2);
    $lpDays[$h % 2 === 0 ? (string) $whole : $whole . '.5'] = ($whole === 0 ? '' : (string) $whole) . ($h % 2 === 1 ? '½' : '') . ($h === 2 ? ' day' : ($h === 1 ? ' day' : ' days'));
}
/** The policy's deduction plan; a policy saved before 14 Oct is read from its own columns. */
$lpPlanOf = static function (array $p) use ($lpPlans, $lpDeductBy, $lpCriteriaOf): array {
    $id = (string) ($p['id'] ?? '');
    $c = $lpCriteriaOf($p);
    $norm = static fn (string $d): string => $d === '' ? '' : (rtrim(rtrim($d, '0'), '.') ?: '0');
    if (is_array($lpPlans[$id] ?? null)) {
        $pl = $lpPlans[$id];
        return ['by' => in_array($pl['by'] ?? '', ['time', 'count', 'every'], true) ? $pl['by'] : 'time',
            'rows' => array_map(static fn ($r): array => [(string) ($r[0] ?? ''), $norm((string) ($r[1] ?? ''))], (array) ($pl['rows'] ?? [])),
            'every_n' => (int) ($pl['every_n'] ?? 3) ?: 3, 'every_d' => $norm((string) ($pl['every_d'] ?? '0.5'))];
    }
    $rows = array_values(array_filter(array_map(static fn (array $r): array => [$r[0], $norm((string) $r[1])], $c['rows']), static fn (array $r): bool => $r[1] !== ''));
    if ($c['every'] === 'Every_n') {
        return ['by' => 'every', 'rows' => [], 'every_n' => (int) ($c['n'] ?: 3), 'every_d' => $rows[0][1] ?? '0.5'];
    }
    return ['by' => ($lpDeductBy[$id] ?? 'time') === 'count' ? 'count' : 'time', 'rows' => $rows, 'every_n' => 3, 'every_d' => '0.5'];
};
$lpEditId = (string) ($_GET['edit'] ?? '');
$lpNew = $lpEditId === 'new';
$lpBlank = ['id' => '', 'grace_min' => 10, 'lates_before_cut' => 2, 'mode' => 'Tiered', 'cut_mode' => 'Every', 'no_cut_on_weekoff' => '1', 'l1_cut' => '0.5'];
$lpWeekoff = ['Ignore' => 'Nothing', 'Comp_off' => 'A comp-off day', 'Pay_ot' => 'Overtime pay'];

/** One policy block, read-only or as a form. */
$lpBlock = static function (array $p, int $no, bool $editing) use ($lpChoices, $lpCriteriaOf, $lpCan, $lpHas, $lpWeekoff, $session, $lpBasis, $lpFromWord, $lpPlanOf, $lpPlanWord, $lpDays): void {
    $crit = LatePolicy::criteria($p);
    $c = $lpCriteriaOf($p);
    $v = static fn (string $k): string => (string) ($p[$k] ?? '');
    $id = (string) ($p['id'] ?? '');
    $from = ($lpBasis[$id] ?? 'start') === 'grace' ? 'grace' : 'start';
    $plan = $lpPlanOf($p);
?>
<div class="lp-box<?= $editing ? ' lp-editing' : '' ?>" id="lp-<?= e($id !== '' ? $id : 'new') ?>">
<?php if ($editing): ?>
  <form method="post">
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
  <input type="hidden" name="action" value="late-policy-save">
  <input type="hidden" name="id" value="<?= e($id) ?>">
  <input type="hidden" name="mode" value="Tiered">
<?php endif; ?>
  <div class="lp-title">
    <span>Late Login Policy <?= $no ?></span>
    <span class="lp-actions">
<?php if ($editing): ?>
      <button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save</button>
      <a class="btn btn-outline btn-sm" href="?r=late-policy">Cancel</a>
<?php elseif ($lpCan): ?>
      <a class="btn btn-outline btn-sm" href="?r=late-policy&amp;edit=<?= e(rawurlencode($id)) ?>#lp-<?= e($id) ?>"><i class="fas fa-pen"></i> Edit</a>
<?php endif; ?>
    </span>
  </div>

  <div class="lp-sec">1. Applies to <small>(ALL = everyone)</small></div>
  <div class="table-wrap"><table class="lp-t">
    <tr><?php foreach (LatePolicy::APPLIES_TO as $k => $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr>
    <tr><?php foreach (LatePolicy::APPLIES_TO as $k => $label): $sel = (string) ($crit[$k] ?? ''); ?>
      <td><?php if ($editing): ?>
<?php if ($k === 'employee'): ?><?= employeePicker('ap_employee_' . ($id ?: 'new'), 'ap_employee', $lpChoices['employee'], $sel, 'ALL') ?>
<?php else: ?><select name="ap_<?= $k ?>"<?= in_array($k, ['company', 'branch', 'department', 'designation', 'role', 'team'], true) ? ' data-org="' . $k . '"' : '' ?>><option value="">ALL</option>
<?php foreach ($lpChoices[$k] as $val => $lab): ?><option value="<?= e((string) $val) ?>"<?= strcasecmp((string) $val, $sel) === 0 ? ' selected' : '' ?>><?= e((string) $lab) ?></option><?php endforeach; ?>
<?php if ($sel !== '' && !isset($lpChoices[$k][$sel])): ?><option value="<?= e($sel) ?>" selected><?= e($sel) ?></option><?php endif; ?>
        </select><?php endif; ?>
<?php else: ?><?= $sel === '' ? '<span class="lp-all">ALL</span>' : e((string) ($lpChoices[$k][$sel] ?? $sel)) ?><?php endif; ?></td>
    <?php endforeach; ?></tr>
  </table></div>

  <div class="lp-sec">2. Late minutes</div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th>Late after (minutes past shift start)</th><th>Late minutes counted from</th><th>Free late logins per month</th><th>Counted on Holidays &amp; W.OFFs?</th></tr>
    <tr>
<?php if ($editing): ?>
      <td><input name="grace_min" type="number" min="0" max="240" value="<?= e($v('grace_min')) ?>"></td>
      <td><select name="late_from" title="Start of shift: 09:40 on a 09:30 shift is 10 late minutes. After grace period (10 min): it is 0, and the deduction criteria read the minutes past the grace."><?php foreach ($lpFromWord as $fv => $fl): ?><option value="<?= $fv ?>"<?= $from === $fv ? ' selected' : '' ?>><?= $fl ?></option><?php endforeach; ?></select></td>
      <td><input name="lates_before_cut" type="number" min="0" max="31" value="<?= e($v('lates_before_cut')) ?>"></td>
      <td><select name="no_cut_on_weekoff"><option value="1"<?= $v('no_cut_on_weekoff') !== '0' ? ' selected' : '' ?>>No</option><option value="0"<?= $v('no_cut_on_weekoff') === '0' ? ' selected' : '' ?>>Yes</option></select></td>
<?php else: ?>
      <td><?= e(\SmartPRS\Surface\AttendanceReport::hm((int) $v('grace_min'))) ?></td><td><?= $lpFromWord[$from] ?></td><td><?= (int) $v('lates_before_cut') ?></td><td><?= $v('no_cut_on_weekoff') === '0' ? 'Yes' : 'No' ?></td>
<?php endif; ?>
    </tr>
  </table></div>

  <?php // DEDUCTION, IN PLAIN SENTENCES (owner, 14 Oct): pick how late logins are charged, then
        // as many rows as needed. Free late logins (section 2) are always skipped first. ?>
  <div class="lp-sec">3. Deduction <small>(after the <?= (int) $v('lates_before_cut') ?> free late login(s) a month)</small></div>
  <div class="lp-plan" data-by="<?= e($plan['by']) ?>">
<?php if ($editing): ?>
    <div class="lp-plan-by">
<?php foreach ($lpPlanWord as $pv => [$pl, $ph]): ?>
      <label><input type="radio" name="deduct_by" value="<?= $pv ?>"<?= $plan['by'] === $pv ? ' checked' : '' ?> onchange="this.closest('.lp-plan').dataset.by=this.value"> <b><?= $pl ?></b><span><?= $ph ?></span></label>
<?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="lp-plan-read"><b><?= $lpPlanWord[$plan['by']][0] ?></b></div>
<?php endif; ?>
<?php foreach (['time', 'count'] as $pk): $rows = $plan['by'] === $pk ? $plan['rows'] : []; ?>
    <div class="lp-plan-rows" data-for="<?= $pk ?>">
<?php if (!$editing && $plan['by'] !== $pk) { echo '</div>'; continue; } ?>
<?php if (!$editing && $rows === []): ?><div class="lp-rule note">No deduction — late logins are only recorded.</div><?php endif; ?>
<?php foreach ($editing ? ($rows === [] ? [['', '']] : $rows) : $rows as [$rt, $rd]): ?>
      <div class="lp-rule">
<?php if ($pk === 'time'): ?>
        <span>A late login</span>
        <?php if ($editing): ?><input name="time_t[]" type="number" min="0" value="<?= e((string) $rt) ?>" placeholder="0"><?php else: ?><b><?= (int) $rt ?></b><?php endif; ?>
        <span>minutes or more late<?= $from === 'grace' ? ' (after the grace)' : '' ?> costs</span>
<?php else: ?>
        <?php if ($editing): ?><input name="count_t[]" type="number" min="1" max="31" value="<?= e((string) $rt) ?>" placeholder="3"><?php else: ?><b><?= (int) $rt ?></b><?php endif; ?>
        <span>late logins =</span>
<?php endif; ?>
        <?php if ($editing): ?><select name="<?= $pk ?>_d[]"><?php foreach ($lpDays as $dv => $dl): ?><option value="<?= $dv ?>"<?= (string) $rd === (string) $dv ? ' selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select>
        <button type="button" class="lp-x" title="Remove this criterion" onclick="var r=this.closest('.lp-plan-rows');if(r.querySelectorAll('.lp-rule').length>1){this.closest('.lp-rule').remove();}else{this.closest('.lp-rule').querySelectorAll('input').forEach(function(i){i.value='';});}">&times;</button>
        <?php else: ?><b><?= e($lpDays[(string) $rd] ?? $rd . ' day(s)') ?></b><?php endif; ?>
      </div>
<?php endforeach; ?>
<?php if ($editing): ?>
      <button type="button" class="btn btn-outline btn-sm lp-add" onclick="var r=this.closest('.lp-plan-rows'),last=r.querySelectorAll('.lp-rule'),c=last[last.length-1].cloneNode(true);c.querySelectorAll('input').forEach(function(i){i.value='';});r.insertBefore(c,this);"><i class="fas fa-plus"></i> Add criteria</button>
      <p class="note lp-hint"><?= $pk === 'time' ? 'The highest row a late login reaches applies. Example: 30 min → ½ day, 120 min → 1 day.' : 'The month costs the highest line reached. Example: 3 late logins = ½ day, 6 late logins = 1 day — so 7 late logins in a month cost 1 day in all.' ?></p>
<?php endif; ?>
    </div>
<?php endforeach; ?>
    <div class="lp-plan-rows" data-for="every">
<?php if ($editing): ?>
      <div class="lp-rule"><span>Every</span><input name="every_n" type="number" min="1" max="31" value="<?= e((string) $plan['every_n']) ?>">
        <span>late logins in the month cost</span>
        <select name="every_d"><?php foreach ($lpDays as $dv => $dl): ?><option value="<?= $dv ?>"<?= (string) $plan['every_d'] === (string) $dv ? ' selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select></div>
      <p class="note lp-hint">Example: every 3 late logins = ½ day — the 3rd, 6th, 9th … late login (after the free ones) each cost ½ day.</p>
<?php elseif ($plan['by'] === 'every'): ?>
      <div class="lp-rule">Every <b><?= (int) $plan['every_n'] ?></b> late logins in the month cost <b><?= e($lpDays[(string) $plan['every_d']] ?? $plan['every_d'] . ' day(s)') ?></b></div>
<?php endif; ?>
    </div>
  </div>

  <?php // FULL-DAY / HALF-DAY HOURS AND BREAK ALLOWED LIVE ON THE SHIFT (owner, 14 Oct): Organization
        // Setup → Shifts, each shift's "Attendance duration" — the day is classified from there. ?>
  <div class="lp-sec">4. Working on a W.OFF <small>(full-day / half-day hours and break allowed are set on each shift — <a href="?r=setup-shifts#shift-week">Shifts</a>)</small></div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th>Working on a W.OFF earns</th></tr>
    <tr>
      <td><?php if ($editing): ?><select name="weekoff_action"><option value="">—</option><?php foreach ($lpWeekoff as $wv => $wl): ?><option value="<?= $wv ?>"<?= $v('weekoff_action') === $wv ? ' selected' : '' ?>><?= $wl ?></option><?php endforeach; ?></select><?php else: ?><?= e($lpWeekoff[$v('weekoff_action')] ?? '—') ?><?php endif; ?></td>
    </tr>
  </table></div>
<?php if ($editing): ?>
  </form>
<?php elseif ($lpCan): ?>
  <form method="post" class="lp-remove" onsubmit="return confirm('Remove Late Login Policy <?= $no ?>? It is kept in history.')">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="late-policy-save">
    <input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= e($id) ?>">
    <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-trash"></i> Remove this policy</button></form>
<?php endif; ?>
</div>
<?php
};
?>
<style>
.lp-box{border:1.5px solid var(--border);border-radius:10px;margin-bottom:22px;background:var(--card,#fff);overflow:hidden}
.lp-box.lp-editing{border-color:var(--accent)}
.lp-title{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:var(--navy,#0c1929);color:#fff;font-size:16px;font-weight:700}
.lp-actions{display:flex;gap:8px;align-items:center}
.lp-title .btn-outline{background:#fff}
.lp-sec{padding:9px 16px;font-weight:700;font-size:13.5px;background:rgba(249,115,22,.1);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.lp-sec small{font-weight:400;color:var(--muted)}
.lp-t{width:100%;border-collapse:collapse;margin:0}
.lp-t th,.lp-t td{border:1px solid var(--border);padding:8px 10px;text-align:left;font-size:13px;vertical-align:middle}
.lp-t th{background:rgba(15,23,42,.04);font-weight:600;font-size:12px}
.lp-t input,.lp-t select{width:100%;min-width:90px;box-sizing:border-box}
.lp-all{color:#166534;font-weight:600}
.lp-plan{padding:12px 16px;display:grid;gap:10px}
.lp-plan-by{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
.lp-plan-by label{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;border:1px solid var(--border);border-radius:8px;padding:10px 12px;cursor:pointer;margin:0}
.lp-plan-by label:has(input:checked){border-color:var(--accent);background:color-mix(in srgb,var(--accent) 6%,transparent)}
.lp-plan-by label span{flex-basis:100%;font-size:12px;color:var(--text3);padding-left:22px}
.lp-plan-rows{display:none;gap:8px}
.lp-plan[data-by="time"] .lp-plan-rows[data-for="time"],.lp-plan[data-by="count"] .lp-plan-rows[data-for="count"],.lp-plan[data-by="every"] .lp-plan-rows[data-for="every"]{display:grid}
.lp-rule{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13.5px}
.lp-rule input{width:90px !important;min-width:0 !important}
.lp-rule select{width:auto !important;min-width:130px}
.lp-x{border:0;background:none;color:#b91c1c;font-size:18px;cursor:pointer;line-height:1}
.lp-add{justify-self:start}
.lp-hint{margin:0}
.lp-remove{padding:10px 16px;text-align:right;border-top:1px solid var(--border)}
</style>

<div class="page-header">
  <div><h2>Late Login Policy</h2>
    <p>Who each policy covers, when a login is late, and what it costs in payroll.</p></div>
<?php if ($lpCan && !$lpNew): ?>
  <div class="actions"><a class="btn btn-tint navy" href="?r=late-policy&amp;edit=new#lp-new"><i class="fas fa-plus"></i> Add Late Login Policy</a></div>
<?php endif; ?>
</div>

<p class="note" style="margin:0 0 14px">If more than one policy covers an employee, the more specific one is used (a named employee first, then the one with more filters set). No policy at all: the 3rd and every later late login in a month costs ½ day.</p>

<?php if ($lpRows === [] && !$lpNew): ?>
<div class="card"><p class="note" style="margin:0">No Late Login Policy yet.</p></div>
<?php endif; ?>
<?php if ($lpRows !== []): $lpDayWord = ['0.5' => '½ day', '1' => '1 day', '1.5' => '1½ days', '2' => '2 days']; ?>
<div class="lp-box">
  <div class="lp-title"><span>All Late Login Policies</span></div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th>Policy</th><th>Applies to</th><th>Late after</th><th>Late minutes from</th><th>Deduction</th><th>Free lates / month</th><th>Holidays &amp; W.OFFs counted</th><th>Deduction criteria</th><th></th></tr>
<?php foreach ($lpRows as $i => $p): $pid = (string) ($p['id'] ?? ''); $crit = LatePolicy::criteria($p); $c = $lpCriteriaOf($p); ?>
    <tr<?= $pid === $lpEditId ? ' style="background:rgba(249,115,22,.08)"' : '' ?>>
      <td><a href="#lp-<?= e($pid) ?>"><b>Late Login Policy <?= $i + 1 ?></b></a></td>
      <td><?= $crit === [] ? '<span class="lp-all">Everyone</span>' : e(implode(' · ', array_map(static fn (string $k, string $val): string => (LatePolicy::APPLIES_TO[$k] ?? $k) . ': ' . (string) ($lpChoices[$k][$val] ?? $val), array_keys($crit), $crit))) ?></td>
      <td><?= e(\SmartPRS\Surface\AttendanceReport::hm((int) ($p['grace_min'] ?? 0))) ?></td>
      <td><?= $lpFromWord[($lpBasis[$pid] ?? 'start') === 'grace' ? 'grace' : 'start'] ?></td>
      <?php $pPlan = $lpPlanOf($p); ?>
      <td><?= e($lpPlanWord[$pPlan['by']][0]) ?></td>
      <td><?= (int) ($p['lates_before_cut'] ?? 0) ?></td>
      <td><?= (string) ($p['no_cut_on_weekoff'] ?? '1') === '0' ? 'Yes' : 'No' ?></td>
      <td><?php $parts = []; if ($pPlan['by'] === 'every') { $parts[] = 'Every ' . $pPlan['every_n'] . ' late logins → ' . ($lpDays[$pPlan['every_d']] ?? $pPlan['every_d'] . ' day'); } else { foreach ($pPlan['rows'] as [$t, $d]) { if ($d === '') { continue; } $parts[] = ($pPlan['by'] === 'count' ? (int) $t . ' late logins' : ((int) $t === 0 ? 'any late' : '≥ ' . \SmartPRS\Surface\AttendanceReport::hm((int) $t))) . ' → ' . ($lpDays[$d] ?? $d . ' day'); } } ?><?= $parts === [] ? '<span class="note">No deduction</span>' : e(implode('; ', $parts)) ?></td>
      <td style="white-space:nowrap"><a href="#lp-<?= e($pid) ?>">View</a><?php if ($lpCan): ?> · <a href="?r=late-policy&amp;edit=<?= e(rawurlencode($pid)) ?>#lp-<?= e($pid) ?>"><i class="fas fa-pen"></i> Edit</a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
<?php foreach ($lpRows as $i => $p) { $lpBlock($p, $i + 1, $lpCan && (string) ($p['id'] ?? '') === $lpEditId); } ?>
<?php if ($lpNew && $lpCan) { $lpBlock($lpBlank, count($lpRows) + 1, true); } ?>
