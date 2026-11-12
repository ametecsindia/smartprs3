<?php declare(strict_types=1);
/**
 * TDS WINDOWS & REMINDERS (owner, 14 Oct) — the organisation decides when employees may declare and
 * when proofs are taken, how often they are reminded, and what counts after the proof cut-off.
 * Saved as a Customisation version (`tds-settings`); every earlier version is kept.
 */
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/tds-lib.php';

if (!$may('payroll.structure.publish') && !isAdministrator()) { ?>
<div class="page-header"><div><h2>TDS Settings</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'payroll.structure.publish')->summary) ?></div></div>
<?php return; }

$ts = $taxDeclarations->settings();
$tsYears = $taxDeclarations->yearList();
$tsYear = $taxDeclarations->year((string) $ts['fy']);
$tsDis = static fn (string $kind): string => $taxDeclarations->window($kind, date('Y-m-d'))['why'];
?>
  <div class="page-header">
    <div><h2>TDS Settings</h2><p>Declaration and proof windows, reminders and how proofs count &middot; employees can declare or upload <b>only while a window is open</b></p></div>
    <div class="actions"><a class="btn btn-outline" href="?r=tds-declaration"><i class="fas fa-file-signature"></i> Declarations</a> <a class="btn btn-outline" href="?r=tds-proofs"><i class="fas fa-file-arrow-up"></i> Proofs</a></div>
  </div>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-settings-save">
    <div class="tds-sec"><h4>Financial year</h4><div class="tds-body tds-grid">
      <div><label>Financial year in force</label><input value="FY <?= e((string) $ts['fy']) ?>" readonly><span class="note cap">Set in <a href="?r=setup-companies">Organization Configuration &rarr; Companies</a> (Automatic or fixed); applies application-wide.</span></div>
      <div><label>Monthly TDS in payroll</label><select name="tds_in_payroll"><option value="1"<?= $ts['tds_in_payroll'] ? ' selected' : '' ?>>On — every close deducts TDS from the declaration</option><option value="0"<?= $ts['tds_in_payroll'] ? '' : ' selected' ?>>Off — no TDS line is added</option></select></div>
    </div></div>
    <div class="tds-sec"><h4>Declaration window <span class="note">— now <?= e($tsDis('declaration')) ?></span></h4><div class="tds-body tds-grid">
      <div><label>Opens on</label><input type="date" name="declaration_from" value="<?= e((string) $ts['declaration_from']) ?>"></div>
      <div><label>Closes on</label><input type="date" name="declaration_to" value="<?= e((string) $ts['declaration_to']) ?>"><span class="note cap">Blank = open until you close it</span></div>
      <div><label>Reminders, days before it closes</label><input name="declaration_reminder_days" value="<?= e(implode(', ', (array) $ts['declaration_reminder_days'])) ?>"><span class="note cap">Also on the day it opens. Only to employees who have not declared.</span></div>
      <div><label>New joiners may declare for (days after joining)</label><input type="number" min="0" max="180" name="new_joiner_days" value="<?= (int) $ts['new_joiner_days'] ?>"><span class="note cap">Even when the window is closed</span></div>
    </div></div>
    <div class="tds-sec"><h4>Investment proof window <span class="note">— now <?= e($tsDis('proof')) ?></span></h4><div class="tds-body tds-grid">
      <div><label>Opens on</label><input type="date" name="proof_from" value="<?= e((string) $ts['proof_from']) ?>"></div>
      <div><label>Closes on (cut-off)</label><input type="date" name="proof_to" value="<?= e((string) $ts['proof_to']) ?>"></div>
      <div><label>Reminders, days before it closes</label><input name="proof_reminder_days" value="<?= e(implode(', ', (array) $ts['proof_reminder_days'])) ?>"><span class="note cap">Also on the day it opens. Only to employees with declared items still unproven.</span></div>
      <div><label>After the cut-off</label><select name="after_proof_cutoff"><option value="approved_only"<?= $ts['after_proof_cutoff'] === 'approved_only' ? ' selected' : '' ?>>Only approved proofs count (usual practice)</option><option value="keep_declared"<?= $ts['after_proof_cutoff'] === 'keep_declared' ? ' selected' : '' ?>>Keep counting what was declared</option></select></div>
    </div></div>
    <button class="go" type="submit"><i class="fas fa-floppy-disk"></i> Save settings</button>
    <p class="note">Typical practice: declarations in April (and within 30 days of joining), proofs from early January to mid-February, reminders 15, 7, 3 and 1 day before each window closes. Reminders go to the employee's bell (and email once SMTP is set).</p>
  </form>
<?php if ($tsYear !== []): ?>
<?php if (isset($tsYear['carried_forward_from'])): ?>
  <div class="card" style="border-left:4px solid #b45309"><b>The <?= e((string) $ts['fy']) ?> rules are not yet confirmed.</b> The rules of <?= e((string) $tsYear['carried_forward_from']) ?> are being applied, as the law stays the same until a Finance Act changes it. Once this year's Budget is passed, an administrator updates them on <a href="?r=tds-rules&amp;edit=1&amp;fy=<?= e((string) $ts['fy']) ?>">Income Tax Rules → Edit rules</a>.</div>
<?php endif; ?>
  <div class="card"><h3 style="margin:0 0 8px;font-size:15px">Rules in force for <?= e((string) $ts['fy']) ?> <span class="note">(<?= isset($tsYear['source']) ? 'edited by your organisation' : 'shipped rules' ?> — <a href="?r=tds-rules&amp;fy=<?= e((string) $ts['fy']) ?>">view</a><?= isAdministrator() || $may('payroll.structure.publish') ? ' · <a href="?r=tds-rules&amp;edit=1&amp;fy=' . e((string) $ts['fy']) . '">edit</a>' : '' ?>)</span></h3>
    <div class="tds-grid">
<?php foreach ($tsYear['regimes'] as $rk => $r): ?>
      <div class="tds-sec" style="margin:0"><h4><?= e((string) $r['label']) ?></h4><div class="tds-body"><div class="note">Standard deduction <?= tdsMoney(IncomeTax::p($r['standard_deduction'])) ?> &middot; rebate up to <?= tdsMoney(IncomeTax::p($r['rebate']['max'])) ?> for taxable income up to <?= tdsMoney(IncomeTax::p($r['rebate']['income_upto'])) ?></div>
        <table class="tds-sheet"><?php $lo = 0; foreach ((array) ($r['slabs'] ?? $r['slabs_by_age']['below_60']) as $s): ?><tr><td><?= tdsMoney($lo) ?> – <?= $s['upto'] === null ? 'above' : tdsMoney(IncomeTax::p($s['upto'])) ?></td><td class="tds-n"><?= e((string) $s['pct']) ?>%</td></tr><?php $lo = $s['upto'] === null ? 0 : IncomeTax::p($s['upto']); endforeach; ?></table></div></div>
<?php endforeach; ?>
    </div><p class="note">Cess <?= e((string) $tsYear['cess_pct']) ?>% on tax + surcharge. HRA metro cities: <?= e(implode(', ', (array) $tsYear['hra']['metro_cities'])) ?>.</p></div>
<?php endif; ?>
<?= tdsCss() ?>
