<?php declare(strict_types=1);
/**
 * TAX ON THE EMPLOYEE PROFILE (owner, 14 Oct): the year's package, deductions, standard deduction
 * and taxable income, under the regime that applies, with the monthly TDS — the same sheet as
 * TDS Declaration. Included by the Directory's one-employee page with $tdsFor set.
 */
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/tds-lib.php';
$tpfFy = $taxDeclarations->fy();

if ($taxDeclarations->year($tpfFy) === []) { return; }

$tpfDecl = $taxDeclarations->declaration((string) $tdsFor['id'], $tpfFy);
$tpfS = tdsSheets($tdsFor, $tpfFy, $tpfDecl);
$tpfSheet = $tpfS[$tpfS['regime']];
$tpfPick = static function (array $sheet, string $label): int { foreach ($sheet['lines'] as $l) { if ($l[0] === $label) { return (int) $l[1]; } } return 0; };
echo tdsCss();
?>
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <h3 style="margin:0;font-size:15px">Income tax <?= e($tpfFy) ?> &middot; <?= e(ucfirst($tpfS['regime'])) ?> regime <span class="note"><?= $tpfDecl === null ? '(not declared — default regime)' : '(declared ' . e(SmartPRS\Surface\Dates::dmy(substr((string) $tpfDecl['submitted_at'], 0, 10))) . ')' ?></span></h3>
      <a class="btn btn-outline btn-sm" href="?r=tds-declaration<?= (string) ($session->user()['employee_id'] ?? '') === (string) $tdsFor['id'] ? '' : '&amp;id=' . e((string) $tdsFor['id']) ?>"><i class="fas fa-file-signature"></i> Full computation &amp; declaration</a>
    </div>
    <div class="tds-foot" style="margin-top:0">
      <div><span class="note">Gross salary (year)</span><b><?= tdsMoney($tpfPick($tpfSheet, 'Gross salary')) ?></b></div>
      <div><span class="note">Exemptions + standard deduction + PT</span><b><?= tdsMoney($tpfPick($tpfSheet, 'Gross salary') - $tpfPick($tpfSheet, 'Income from salary')) ?></b></div>
      <div><span class="note">Chapter VIII deductions</span><b><?= tdsMoney(array_sum($tpfSheet['deductions'])) ?></b></div>
      <div><span class="note">Taxable income</span><b><?= tdsMoney((int) $tpfSheet['taxable']) ?></b></div>
      <div><span class="note">Tax for the year</span><b><?= tdsMoney((int) $tpfSheet['total']) ?></b></div>
      <div><span class="note">Monthly TDS</span><b style="color:var(--accent)"><?= tdsMoney((int) $tpfS['monthly_tds']) ?></b></div>
    </div>
  </div>
