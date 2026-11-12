<?php declare(strict_types=1); if (!$may('payroll.structure.view')): ?>
<div class="page-header"><div><h2>PT Slabs</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.structure.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// PT SLABS (owner, 28 Oct 2026) — Organization Setup → Salary structure → PT Slabs. Every Professional
// Tax rule as one bordered block "PT Rule N", in the Late Login Policy layout the owner approved:
// 1 Applies to · 2 When it is deducted · 3 Slabs · 4 What it deducts in a year. Payroll and the PT
// Applicable answer read the same rules (surface/PtRules.php). Saved by actions/pt-rules-save.php.
use SmartPRS\Surface\PtRules;

$ptR = PtRules::for($store, __DIR__ . '/../../config');
$ptCan = $may('payroll.structure.publish') || isAdministrator();
$ptRules = $ptR->rules();
$ptCustom = $ptR->isCustomised();
$ptToday = date('Y-m-d');
$ptFy = financialYearStart($customisation);
$ptFyOrder = [];
for ($i = 0; $i < 12; $i++) { $ptFyOrder[] = (($ptFy - 1 + $i) % 12) + 1; }

// CHOICES from Organization Setup.
$ptNames = orgNames($store);
$ptCompanies = (array) ($ptNames['companies'] ?? []);
asort($ptCompanies);
$ptBranches = [];
$ptBranchState = [];
$ptOrgStates = [];
foreach (liveRows($store, 'branches') as $b) {
    $ptBranches[(string) $b['id']] = trim((string) ($b['name'] ?? ''));
    $ptBranchState[(string) $b['id']] = trim((string) ($b['state'] ?? ''));
    $ptOrgStates[] = (string) ($b['state'] ?? '');
}
asort($ptBranches);
foreach (liveRows($store, 'companies') as $c) { $ptOrgStates[] = (string) ($c['state'] ?? ''); }
$ptStates = $ptR->stateNames($ptOrgStates);
// Employee Type options for "Exempt employee types" — the list Employee fields declares.
$ptTypes = [];
foreach ((array) (json_decode((string) @file_get_contents(__DIR__ . '/../../config/employee-fields.json'), true)['sections'] ?? []) as $sec) {
    foreach ((array) ($sec['fields'] ?? []) as $fd) {
        (string) ($fd['code'] ?? '') === 'employment_type' && $ptTypes = array_map('strval', (array) ($fd['options'] ?? []));
    }
}
$ptExemptWord = static function (array $r): string {
    $p = [];
    $r['exempt_age'] !== '' && $p[] = 'age ' . $r['exempt_age'] . '+';
    $r['exempt_pwd'] && $p[] = 'persons with disability';
    $r['exempt_types'] !== [] && $p[] = implode(', ', $r['exempt_types']);
    return implode(' · ', $p);
};

// WHO EACH RULE COVERS TODAY: every active employee's PT state (their own, else their branch's).
$ptCovered = [];
$ptUncovered = [];
foreach ($scope->only(liveRows($store, 'employees'), true) as $emp) {
    $st = trim((string) ($emp['pt_state'] ?? ''));
    $bid = (string) ($emp['branch_id'] ?? '');
    $st !== '' && strcasecmp($st, 'Not applicable') !== 0 || $st = $ptBranchState[$bid] ?? '';
    if (strcasecmp(trim((string) ($emp['pt_state'] ?? '')), 'Not applicable') === 0) { continue; }
    $hit = $st === '' ? null : $ptR->ruleFor($st, (string) ($emp['employer_company_id'] ?? ''), $bid, (string) ($emp['gender'] ?? ''), $ptToday);
    $hit === null ? $ptUncovered[$st === '' ? '(no state)' : $st] = ($ptUncovered[$st === '' ? '(no state)' : $st] ?? 0) + 1
        : $ptCovered[$hit['id']] = ($ptCovered[$hit['id']] ?? 0) + 1;
}

// REPLACED: a later rule for the same people has taken effect.
$ptReplacedFrom = [];
foreach ($ptRules as $a) {
    foreach ($ptRules as $b) {
        if ($a['id'] !== $b['id'] && $a['active'] && $b['active'] && strcasecmp($a['state'], $b['state']) === 0 && $a['company_id'] === $b['company_id']
            && $a['branch_id'] === $b['branch_id'] && $a['gender'] === $b['gender'] && $b['effective_from'] > $a['effective_from'] && $b['effective_from'] <= $ptToday) {
            $ptReplacedFrom[$a['id']] = min($ptReplacedFrom[$a['id']] ?? '9999', $b['effective_from']);
        }
    }
}

$ptEditId = (string) ($_GET['edit'] ?? '');
$ptNew = $ptEditId === 'new';
$ptDraft = (array) ($_SESSION['pt_draft'] ?? []);
unset($_SESSION['pt_draft']);
$ptBlank = PtRules::normalise(['state' => '', 'gender' => 'all', 'frequency' => 'monthly',
    'slabs' => [['upto' => '', 'amount' => '0']], 'annual_cap' => (string) ($ptR->config()['annual_cap'] ?? ''), 'effective_from' => $ptToday]);
if ($ptNew && ($_GET['copy'] ?? '') !== '') {
    foreach ($ptRules as $r) {
        if ($r['id'] === (string) $_GET['copy']) { $ptBlank = ['id' => '', 'name' => $r['name'] . ' (copy)', 'effective_from' => $ptToday] + $r; }
    }
}
$ptDmy = static fn (string $iso): string => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) === 1 ? $m[3] . '-' . $m[2] . '-' . $m[1] : '';
$ptMonthsWord = static function (array $r) use ($ptFyOrder): string {
    if ($r['frequency'] === 'monthly') { return 'Every month'; }
    return implode(', ', array_map(static fn (int $m): string => PtRules::MONTHS[$m], array_values(array_intersect($ptFyOrder, $r['months'])))) ?: '—';
};
$ptAppliesWord = static function (array $r) use ($ptCompanies, $ptBranches): string {
    $p = [];
    $r['company_id'] !== '' && $p[] = (string) ($ptCompanies[$r['company_id']] ?? 'Company #' . $r['company_id']);
    $r['branch_id'] !== '' && $p[] = (string) ($ptBranches[$r['branch_id']] ?? 'Branch #' . $r['branch_id']);
    return $p === [] ? '' : implode(' · ', $p);
};

/** One rule block, read-only or as a form. */
$ptBlock = static function (array $r, int $no, bool $editing) use ($ptCan, $ptStates, $ptCompanies, $ptBranches, $ptBranchState, $ptFyOrder, $ptFy, $ptDmy, $ptMonthsWord, $ptAppliesWord, $ptCovered, $ptReplacedFrom, $session, $ptTypes, $ptExemptWord): void {
    $id = $r['id'];
    $anchor = 'pt-' . ($id !== '' ? $id : 'new');
    $sm = $r['special_month'];
    $cap = $r['annual_cap'];
?>
<div class="lp-box<?= $editing ? ' lp-editing' : '' ?>" id="<?= e($anchor) ?>">
<?php if ($editing): ?>
  <form method="post" class="pt-form" novalidate>
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
  <input type="hidden" name="action" value="pt-rules-save">
  <input type="hidden" name="op" value="save">
  <input type="hidden" name="id" value="<?= e($id) ?>">
<?php endif; ?>
  <div class="lp-title">
    <span>PT Rule <?= $no ?><?= $r['name'] !== '' ? ' — ' . e($r['name']) : '' ?></span>
    <span class="lp-actions">
<?php if ($editing): ?>
      <button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save</button>
      <a class="btn btn-outline btn-sm" href="?r=pt-slabs<?= $id !== '' ? '#pt-' . e($id) : '' ?>">Cancel</a>
<?php elseif ($ptCan): ?>
      <a class="btn btn-outline btn-sm" href="?r=pt-slabs&amp;edit=<?= e(rawurlencode($id)) ?>#pt-<?= e($id) ?>"><i class="fas fa-pen"></i> Edit</a>
      <a class="btn btn-outline btn-sm" href="?r=pt-slabs&amp;edit=new&amp;copy=<?= e(rawurlencode($id)) ?>#pt-new" title="Start a new rule from this one — e.g. a women's rule, or a branch's own"><i class="fas fa-copy"></i> Copy</a>
<?php endif; ?>
    </span>
  </div>

  <div class="lp-sec">1. Applies to <small>(ALL = everyone in the state)</small></div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th>State</th><th>Company</th><th>Branch</th><th>Gender</th><th>Rule name</th></tr>
    <tr data-org-scope>
<?php if ($editing): ?>
      <td><select name="state" class="pt-state" required><option value="">— choose —</option>
<?php foreach ($ptStates as $s): ?><option value="<?= e($s) ?>"<?= strcasecmp($s, $r['state']) === 0 ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></td>
      <td><select name="company_id" data-org="company"><option value="">ALL</option>
<?php foreach ($ptCompanies as $cid => $cn): ?><option value="<?= e((string) $cid) ?>"<?= (string) $cid === $r['company_id'] ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></td>
      <td><select name="branch_id" data-org="branch" class="pt-branch"><option value="">ALL</option>
<?php foreach ($ptBranches as $bid => $bn): ?><option value="<?= e((string) $bid) ?>" data-state="<?= e(strtolower($ptBranchState[$bid] ?? '')) ?>"<?= (string) $bid === $r['branch_id'] ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select></td>
      <td><select name="gender"><?php foreach (PtRules::GENDERS as $gk => $gl): ?><option value="<?= $gk ?>"<?= $gk === $r['gender'] ? ' selected' : '' ?>><?= e($gl) ?></option><?php endforeach; ?></select></td>
      <td><input name="name" value="<?= e($r['name']) ?>" placeholder="e.g. Maharashtra — women"></td>
<?php else: ?>
      <td><b><?= e($r['state']) ?></b></td>
      <td><?= $r['company_id'] === '' ? '<span class="lp-all">ALL</span>' : e((string) ($ptCompanies[$r['company_id']] ?? '#' . $r['company_id'])) ?></td>
      <td><?= $r['branch_id'] === '' ? '<span class="lp-all">ALL</span>' : e((string) ($ptBranches[$r['branch_id']] ?? '#' . $r['branch_id'])) ?></td>
      <td><?= $r['gender'] === 'all' ? '<span class="lp-all">ALL</span>' : e(PtRules::GENDERS[$r['gender']]) ?></td>
      <td><?= e($r['name']) ?></td>
<?php endif; ?>
    </tr>
  </table></div>

  <div class="lp-sec">2. Exemptions <small>(these people pay no PT under this rule; a blank Employee field never exempts anyone)</small></div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th style="width:22%">Exempt from age</th><th style="width:22%">Persons with disability</th><th>Exempt employee types</th></tr>
    <tr>
<?php if ($editing): ?>
      <td><input name="exempt_age" type="number" min="14" max="100" step="1" value="<?= e($r['exempt_age']) ?>" placeholder="blank = no age exemption" title="Age on the payroll date, from Date of Birth"></td>
      <td><label class="pt-chk"><input type="checkbox" name="exempt_pwd" value="1"<?= $r['exempt_pwd'] ? ' checked' : '' ?>> Exempt <span class="note">(Employee field "Physically Challenged" = Yes)</span></label></td>
      <td><div class="pt-types"><?php foreach ($ptTypes as $t): ?><label class="pt-chk"><input type="checkbox" name="exempt_types[]" value="<?= e($t) ?>"<?= in_array(strtolower($t), array_map('strtolower', $r['exempt_types']), true) ? ' checked' : '' ?>> <?= e($t) ?></label><?php endforeach; ?></div></td>
<?php else: ?>
      <td><?= $r['exempt_age'] === '' ? '<span class="note">none</span>' : e($r['exempt_age']) . ' years and above' ?></td>
      <td><?= $r['exempt_pwd'] ? 'Exempt' : '<span class="note">not exempt</span>' ?></td>
      <td><?= $r['exempt_types'] === [] ? '<span class="note">none</span>' : e(implode(', ', $r['exempt_types'])) ?></td>
<?php endif; ?>
    </tr>
  </table></div>

  <div class="lp-sec">3. When it is deducted</div>
  <div class="table-wrap"><table class="lp-t">
    <tr><th>Frequency</th><th>Deducted in</th><th>Slab "Up to" figures are</th><th>Effective from</th><th>Status</th></tr>
    <tr>
<?php if ($editing): ?>
      <td><select name="frequency" class="pt-freq"><?php foreach (PtRules::FREQUENCIES as $fk => [$fl]): ?><option value="<?= $fk ?>"<?= $fk === $r['frequency'] ? ' selected' : '' ?>><?= $fl ?></option><?php endforeach; ?></select></td>
      <td><div class="pt-months"><?php foreach (PtRules::MONTHS as $mn => $ml): ?><label><input type="checkbox" name="months[]" value="<?= $mn ?>"<?= in_array($mn, $r['months'], true) ? ' checked' : '' ?>> <?= $ml ?></label><?php endforeach; ?></div></td>
      <td><select name="slab_on" class="pt-slabon" title="What the slab 'Up to' figures are in. Quarterly / Half-yearly / Yearly income: compared with the month's gross × 3 / 6 / 12. Independent of how often PT is deducted — e.g. Madhya Pradesh deducts monthly on yearly-income slabs."><?php foreach (PtRules::SLAB_ON as $sk => [$sl]): ?><option value="<?= $sk ?>"<?= $sk === $r['slab_on'] ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></td>
      <td><input type="date" name="effective_from" value="<?= e($r['effective_from']) ?>"></td>
      <td><select name="active"><option value="1"<?= $r['active'] ? ' selected' : '' ?>>Active</option><option value="0"<?= $r['active'] ? '' : ' selected' ?>>Inactive</option></select></td>
<?php else: ?>
      <td><?= e(PtRules::FREQUENCIES[$r['frequency']][0]) ?></td>
      <td><?= e($ptMonthsWord($r)) ?></td>
      <td><?= e(PtRules::SLAB_ON[$r['slab_on']][0]) ?><?= PtRules::periodMonths($r) > 1 ? '<br><span class="note">month\'s gross × ' . PtRules::periodMonths($r) . '</span>' : '' ?></td>
      <td><?= $r['effective_from'] === '' ? 'Always' : e($ptDmy($r['effective_from'])) ?></td>
      <td><?= !$r['active'] ? '<span class="pt-off">Inactive</span>' : (isset($ptReplacedFrom[$id]) ? '<span class="pt-off">Replaced from ' . e($ptDmy($ptReplacedFrom[$id])) . '</span>' : '<span class="lp-all">Active</span>') ?></td>
<?php endif; ?>
    </tr>
  </table></div>

  <div class="lp-sec">4. Slabs <small>(the slab the salary falls in decides the PT; leave the last "Up to" blank = and above)</small></div>
  <div class="table-wrap"><table class="lp-t pt-slabs">
    <tr><th style="width:44px">#</th><th>From (₹)</th><th>Up to (₹)</th><th>PT amount (₹)</th>
      <th><?php if ($editing): ?>Amount in <select name="special_month" class="pt-sm" style="width:auto;min-width:0;display:inline-block"><option value="0">— no month —</option><?php foreach (PtRules::MONTHS as $mn => $ml): ?><option value="<?= $mn ?>"<?= $mn === $sm ? ' selected' : '' ?>><?= $ml ?></option><?php endforeach; ?></select> (₹)<?php else: ?><?= $sm > 0 ? 'Amount in ' . PtRules::MONTHS[$sm] . ' (₹)' : 'Different amount in one month' ?><?php endif; ?></th>
      <?php if ($editing): ?><th style="width:44px"></th><?php endif; ?></tr>
<?php $prev = null; foreach ($editing && $r['slabs'] === [] ? [['upto' => null, 'amount' => '0', 'special' => '']] : $r['slabs'] as $i => $s): ?>
    <tr class="pt-row">
      <td class="pt-no"><?= $i + 1 ?></td>
      <td class="pt-from"><?= $prev === null ? '0' : e(PtRules::rupees(PtRules::fmt(PtRules::minor((string) $prev) + 1))) ?></td>
<?php if ($editing): ?>
      <td><input name="upto[]" type="number" min="0" step="0.01" value="<?= e((string) ($s['upto'] ?? '')) ?>" placeholder="blank = and above"></td>
      <td><input name="amount[]" type="number" min="0" step="0.01" value="<?= e($s['amount']) ?>" placeholder="0 = nil"></td>
      <td><input name="special[]" type="number" min="0" step="0.01" value="<?= e($s['special']) ?>" class="pt-special" placeholder="same"></td>
      <td><button type="button" class="lp-x pt-del" title="Remove this slab">&times;</button></td>
<?php else: ?>
      <td><?= $s['upto'] === null ? '<i>and above</i>' : e(PtRules::rupees((string) $s['upto'])) ?></td>
      <td><b><?= preg_match('/^0+(\.0+)?$/', $s['amount']) === 1 ? 'Nil' : e(PtRules::rupees($s['amount'])) ?></b></td>
      <td><?= $sm > 0 && $s['special'] !== '' ? e(PtRules::rupees($s['special'])) : '<span class="note">same</span>' ?></td>
<?php endif; ?>
    </tr>
<?php $prev = $s['upto']; endforeach; ?>
  </table></div>
  <div class="pt-under">
<?php if ($editing): ?>
    <button type="button" class="btn btn-outline btn-sm pt-add"><i class="fas fa-plus"></i> Add slab</button>
    <label class="pt-cap">Annual maximum (₹) <input name="annual_cap" type="number" min="0" step="0.01" value="<?= e($cap) ?>" placeholder="blank = none"></label>
    <p class="note" style="margin:6px 0 0;flex-basis:100%">Example — Maharashtra: Up to 7,500 → 0 · Up to 10,000 → 175 · blank → 200, Amount in Feb 300. Tamil Nadu: Half-yearly, Sep &amp; Mar, slabs in Half-yearly income, Up to 21,000 → 0 · Up to 30,000 → 135 … blank → 1,250. Madhya Pradesh: Monthly, slabs in Yearly income, Amount in Mar 174 / 212.</p>
<?php else: ?>
    <span>Annual maximum: <b><?= $cap === '' ? 'none set' : e(PtRules::rupees($cap)) ?></b></span>
    <span>Employees covered today: <b><?= (int) ($ptCovered[$id] ?? 0) ?></b></span>
<?php endif; ?>
  </div>

<?php if (!$editing): $plan = PtRules::yearPlan($r, $ptFy); $capMinor = $cap !== '' ? PtRules::minor($cap) : null; ?>
  <div class="lp-sec">5. What it deducts in a year <small>(financial year, by slab — the same arithmetic payroll uses)</small></div>
  <div class="table-wrap"><table class="lp-t pt-year">
    <tr><th>Salary slab</th><?php foreach ($ptFyOrder as $m): ?><th class="n"><?= PtRules::MONTHS[$m] ?></th><?php endforeach; ?><th class="n">Year total</th></tr>
<?php foreach ($plan as $p): $over = $capMinor !== null && $p['total'] > $capMinor; ?>
    <tr><td><?= e($p['label']) ?></td>
<?php foreach ($ptFyOrder as $m): ?><td class="n<?= $p['months'][$m] === 0 ? ' pt-zero' : '' ?>"><?= $p['months'][$m] === 0 ? '–' : e(PtRules::rupees(PtRules::fmt($p['months'][$m]))) ?></td><?php endforeach; ?>
      <td class="n"><b<?= $over ? ' class="no" title="Above the annual maximum"' : '' ?>><?= e(PtRules::rupees(PtRules::fmt($p['total']))) ?></b><?= $over ? ' <span class="no">above the ' . e(PtRules::rupees($cap)) . ' maximum</span>' : '' ?></td></tr>
<?php endforeach; ?>
  </table></div>
<?php endif; ?>
<?php if ($editing): ?>
  </form>
<?php elseif ($ptCan): ?>
  <form method="post" class="lp-remove" onsubmit="return confirm('Remove PT Rule <?= $no ?>? The earlier version is kept in history.')">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pt-rules-save">
    <input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= e($id) ?>">
    <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-trash"></i> Remove this rule</button></form>
<?php endif; ?>
</div>
<?php
};
?>
<style>
.lp-box{border:1.5px solid var(--border);border-radius:10px;margin-bottom:22px;background:var(--card,#fff);overflow:hidden}
.lp-box.lp-editing{border-color:var(--accent)}
.lp-title{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;background:var(--navy,#0c1929);color:#fff;font-size:16px;font-weight:700}
.lp-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lp-title .btn-outline{background:#fff}
.lp-sec{padding:9px 16px;font-weight:700;font-size:13.5px;background:rgba(249,115,22,.1);border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.lp-sec small{font-weight:400;color:var(--muted)}
.lp-t{width:100%;border-collapse:collapse;margin:0}
.lp-t th,.lp-t td{border:1px solid var(--border);padding:8px 10px;text-align:left;font-size:13px;vertical-align:middle}
.lp-t th{background:rgba(15,23,42,.04);font-weight:600;font-size:12px}
.lp-t input,.lp-t select{width:100%;min-width:90px;box-sizing:border-box}
.lp-t .n{text-align:right;white-space:nowrap}
.lp-all{color:#166534;font-weight:600}
.pt-off{color:#9a3412;font-weight:600}
.lp-x{border:0;background:none;color:#b91c1c;font-size:18px;cursor:pointer;line-height:1}
.lp-remove{padding:10px 16px;text-align:right;border-top:1px solid var(--border)}
.pt-months{display:grid;grid-template-columns:repeat(6,minmax(52px,1fr));gap:4px 10px}
.pt-months label{display:flex;align-items:center;gap:4px;margin:0;font-weight:500;white-space:nowrap}
.pt-months input{width:auto !important;min-width:0 !important}
.pt-chk{display:inline-flex;align-items:center;gap:6px;margin:0 14px 0 0;font-weight:500;white-space:nowrap}
.pt-chk input{width:auto !important;min-width:0 !important}
.pt-types{display:flex;flex-wrap:wrap;gap:4px 0}
.pt-under{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:10px 16px;font-size:13px}
.pt-cap{display:flex;align-items:center;gap:8px;margin:0;font-weight:600;font-size:13px}
.pt-cap input{width:140px}
.pt-from{color:var(--text2);white-space:nowrap}
.pt-special:disabled{background:rgba(15,23,42,.05)}
.pt-year td.pt-zero{color:var(--text3)}
.pt-sum td{vertical-align:top}
.pt-sum th,.pt-sum td{padding:7px 8px}
.pt-lines{min-width:230px}
.pt-lines div{padding-left:10px;text-indent:-10px}
</style>

<div class="page-header">
  <div><h2>PT Slabs</h2>
    <p>Professional Tax rules by state — who they apply to, when they are deducted and the slabs. Payroll deducts exactly these.</p></div>
<?php if ($ptCan && !$ptNew): ?>
  <div class="actions"><a class="btn btn-tint navy" href="?r=pt-slabs&amp;edit=new#pt-new"><i class="fas fa-plus"></i> Add PT rule</a></div>
<?php endif; ?>
</div>

<p class="note" style="margin:0 0 14px">When more than one rule covers an employee, payroll uses the most specific: a Branch rule, then a Company rule, then a Gender rule over "All" — and of those, the latest Effective from on or before the payroll month. The employee's state is their own Professional Tax State, else their branch's.
<?php if (!$ptCustom): ?><br><b>Showing the Government schedule shipped with SmartPRS.</b> Save any change to start using your own rules — every state below is carried over.<?php endif; ?></p>

<?php if ($ptUncovered !== []): ?>
<div class="problem"><b>No PT rule covers some employees:</b> <?= e(implode(' · ', array_map(static fn (string $s, int $c): string => $s . ' (' . $c . ')', array_keys($ptUncovered), $ptUncovered))) ?> — payroll refuses PT "As per Government rules" for them until a rule is added (or their Professional Tax State is set to "Not applicable").</div>
<?php endif; ?>

<div class="lp-box">
  <div class="lp-title"><span>All PT Rules</span>
<?php if ($ptCan && $ptCustom): ?>
    <form method="post" class="lp-actions" style="margin:0" onsubmit="return confirm('Go back to the Government schedule shipped with SmartPRS? Your rules are kept in history.')">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="pt-rules-save"><input type="hidden" name="op" value="reset">
      <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-rotate-left"></i> Restore Government schedule</button></form>
<?php endif; ?>
  </div>
  <div class="table-wrap"><table class="lp-t pt-sum">
    <tr><th>Rule</th><th>State</th><th>Applies to</th><th>Gender</th><th>Deducted</th><th>Slabs</th><th class="n">Annual max</th><th>Status</th><th class="n">Employees</th><th></th></tr>
<?php foreach ($ptRules as $i => $r): ?>
    <tr<?= $r['id'] === $ptEditId ? ' style="background:rgba(249,115,22,.08)"' : '' ?>>
      <td><a href="#pt-<?= e($r['id']) ?>"><b>PT Rule <?= $i + 1 ?></b></a><?= $r['name'] !== '' && strcasecmp($r['name'], $r['state']) !== 0 ? '<br><span class="note">' . e($r['name']) . '</span>' : '' ?></td>
      <td><?= e($r['state']) ?></td>
      <td><?= $ptAppliesWord($r) === '' ? '<span class="lp-all">ALL</span>' : e($ptAppliesWord($r)) ?><?= $ptExemptWord($r) !== '' ? '<br><span class="note">Exempt: ' . e($ptExemptWord($r)) . '</span>' : '' ?></td>
      <td><?= $r['gender'] === 'all' ? '<span class="lp-all">ALL</span>' : e(PtRules::GENDERS[$r['gender']]) ?></td>
      <td><?= e(PtRules::FREQUENCIES[$r['frequency']][0]) ?><?= $r['frequency'] === 'monthly' ? '' : '<br><span class="note">' . e($ptMonthsWord($r)) . '</span>' ?></td>
      <td class="pt-lines"><?= implode('', array_map(static fn (string $l): string => '<div>' . e($l) . '</div>', PtRules::summaryLines($r))) ?></td>
      <td class="n"><?= $r['annual_cap'] === '' ? '—' : e(PtRules::rupees($r['annual_cap'])) ?></td>
      <td><?= !$r['active'] ? '<span class="pt-off">Inactive</span>' : (isset($ptReplacedFrom[$r['id']]) ? '<span class="pt-off">Replaced from ' . e($ptDmy($ptReplacedFrom[$r['id']])) . '</span>' : '<span class="lp-all">Active</span>') ?>
        <br><span class="note"><?= $r['effective_from'] === '' ? 'always' : 'from ' . e($ptDmy($r['effective_from'])) ?></span></td>
      <td class="n"><?= (int) ($ptCovered[$r['id']] ?? 0) ?></td>
      <td style="white-space:nowrap"><?php if ($ptCan): ?><a href="?r=pt-slabs&amp;edit=<?= e(rawurlencode($r['id'])) ?>#pt-<?= e($r['id']) ?>"><i class="fas fa-pen"></i> Edit</a><?php else: ?><a href="#pt-<?= e($r['id']) ?>">View</a><?php endif; ?></td>
    </tr>
<?php endforeach; ?>
  </table></div>
</div>

<?php foreach ($ptRules as $i => $r) {
    $editing = $ptCan && $r['id'] === $ptEditId;
    $ptBlock($editing && ($ptDraft['id'] ?? null) === $r['id'] ? PtRules::normalise($ptDraft) : $r, $i + 1, $editing);
} ?>
<?php if ($ptNew && $ptCan) { $ptBlock(($ptDraft !== [] && ($ptDraft['id'] ?? '') === '') ? PtRules::normalise($ptDraft) : $ptBlank, count($ptRules) + 1, true); } ?>

<script>
(function () {
  document.querySelectorAll('form.pt-form').forEach(function (f) {
    var freq = f.querySelector('.pt-freq'), months = f.querySelector('.pt-months'), slabOn = f.querySelector('.pt-slabon'),
        sm = f.querySelector('.pt-sm'), table = f.querySelector('table.pt-slabs'), state = f.querySelector('.pt-state'), branch = f.querySelector('.pt-branch');
    var DEF = { monthly: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], quarterly: [6, 9, 12, 3], 'half-yearly': [9, 3], yearly: [3] };
    function boxes() { return Array.prototype.slice.call(months.querySelectorAll('input')); }
    function syncFreq(changed) {
      // A new frequency starts from its usual months and slab basis — every tick and the basis stay editable
      // (Madhya Pradesh: Monthly deduction on Yearly income slabs).
      if (changed) {
        boxes().forEach(function (b) { b.checked = DEF[freq.value].indexOf(+b.value) >= 0; });
        slabOn.value = freq.value;
      }
    }
    function rows() { return Array.prototype.slice.call(table.querySelectorAll('tr.pt-row')); }
    function money(v) { return v === '' ? null : Math.round(parseFloat(v) * 100); }
    function fmt(p) { var s = (p / 100).toFixed(2).replace(/\.00$/, ''), w = s.split('.'), x = w[0];
      x = x.length > 3 ? x.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + x.slice(-3) : x; return '₹' + x + (w[1] ? '.' + w[1] : ''); }
    function syncRows() {
      var prev = null;
      rows().forEach(function (r, i) {
        r.querySelector('.pt-no').textContent = i + 1;
        r.querySelector('.pt-from').textContent = prev === null ? '0' : fmt(prev + 1);
        var u = money(r.querySelector('[name="upto[]"]').value);
        if (u !== null) { prev = u; }
        r.querySelector('.pt-special').disabled = sm.value === '0';
      });
    }
    function syncBranches() {
      var st = (state.value || '').toLowerCase();
      Array.prototype.forEach.call(branch.options, function (o) {
        if (o.value === '') { return; }
        var ds = o.getAttribute('data-state') || '', ok = st === '' || ds === '' || ds === st;
        if (!ok && o.selected) { branch.value = ''; }
        o.hidden = !ok; o.disabled = !ok;
      });
    }
    freq.addEventListener('change', function () { syncFreq(true); });
    sm.addEventListener('change', syncRows);
    state.addEventListener('change', syncBranches);
    table.addEventListener('input', syncRows);
    f.querySelector('.pt-add').addEventListener('click', function () {
      var last = rows()[rows().length - 1], c = last.cloneNode(true);
      c.querySelectorAll('input').forEach(function (i) { i.value = ''; i.setCustomValidity(''); });
      last.parentNode.appendChild(c); syncRows(); c.querySelector('[name="upto[]"]').focus();
    });
    table.addEventListener('click', function (ev) {
      var b = ev.target.closest('.pt-del'); if (!b) { return; }
      if (rows().length > 1) { b.closest('tr').remove(); } else { b.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
      syncRows();
    });
    // CHECKED AT THE FIELD (owner: errors pop up where the user is), before anything is sent.
    f.addEventListener('submit', function (ev) {
      f.querySelectorAll('input,select').forEach(function (i) { i.setCustomValidity && i.setCustomValidity(''); });
      var bad = null, say = function (el, msg) { if (!bad) { bad = el; el.setCustomValidity(msg); } };
      if (!state.value) { say(state, 'Choose the State.'); }
      if (!boxes().some(function (b) { return b.checked; })) { say(boxes()[0], 'Tick at least one month PT is deducted in.'); }
      var rs = rows(), prev = -1;
      rs.forEach(function (r, i) {
        var up = r.querySelector('[name="upto[]"]'), am = r.querySelector('[name="amount[]"]'), u = money(up.value);
        if (am.value === '') { say(am, 'Enter the PT amount (0 for nil).'); }
        if (i === rs.length - 1) { if (u !== null) { say(up, 'Leave the last slab\'s "Up to" blank, so every salary above it is covered.'); } return; }
        if (u === null) { say(up, 'Only the last slab may be blank (= and above).'); return; }
        if (u <= prev) { say(up, '"Up to" must be higher than the slab above it.'); }
        prev = u;
      });
      if (sm.value !== '0' && !boxes().some(function (b) { return b.checked && b.value === sm.value; })) { say(sm, 'PT is not deducted in that month — tick it under "Deducted in", or choose "— no month —".'); }
      if (bad) { ev.preventDefault(); bad.reportValidity(); bad.focus(); }
    });
    syncFreq(false); syncRows(); syncBranches();
  });
})();
</script>
