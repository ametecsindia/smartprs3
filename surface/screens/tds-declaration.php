<?php declare(strict_types=1);
/**
 * TDS DECLARATION (owner, 14 Oct) — the employee's Form 122 / Form 124 (old Form 12BB): regime,
 * rent for HRA, LTA, home-loan interest, Chapter VIII investments, other income and the previous
 * employer — with the year's salary package and the tax worked out live under both regimes.
 * Editable only while the organisation's declaration window is open (new joiners get their own
 * days); HR may declare on someone's behalf at any time. Last year's declaration pre-fills a new year.
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;
use SmartPRS\Surface\TaxDeclarations;

include __DIR__ . '/../partials/tds-lib.php';
$tdFy = (string) ($_GET['fy'] ?? '') !== '' && preg_match('/^\d{4}-\d{2}$/', (string) $_GET['fy']) === 1 ? (string) $_GET['fy'] : $taxDeclarations->fy();
$tdYear = $taxDeclarations->year($tdFy);
$tdWho = tdsWho();
$tdToday = date('Y-m-d');
echo tdsCss();

if ($tdYear === []): ?>
  <div class="page-header"><div><h2>TDS Declaration</h2></div></div>
  <div class="card"><div class="problem" style="margin:0">No income-tax rules are configured for <?= e($tdFy) ?> in config/income-tax.json, so nothing can be declared or worked out for it. Add the year's block there.</div></div>
<?php return; endif;

// ---- HR: EVERYONE, AT A GLANCE ----------------------------------------------------------------
if ($tdWho['hr'] && $tdWho['e'] === null):
    $tdFilter = orgFilter();
    $tdState = in_array((string) ($_GET['state'] ?? ''), ['declared', 'pending'], true) ? (string) $_GET['state'] : '';
    $tdData = tdsRows($tdFy, $tdFilter, $tdState);
    $tdCounts = [];
    foreach ($tdData['scoped'] as $e) { $tdCounts[(string) $e['id']] = isset($tdData['decls'][(string) $e['id']]) ? 1 : 0; }
    $tdWin = $taxDeclarations->window('declaration', $tdToday);
    $tdKeepQs = ($tdState !== '' ? '&amp;state=' . $tdState : '') . '&amp;fy=' . e($tdFy);
    $tdOld = count(array_filter($tdData['decls'], static fn (array $d): bool => (string) $d['regime'] === 'old'));
?>
  <div class="page-header">
    <div><h2>TDS Declarations</h2><p><?= e((string) $tdYear['label']) ?> &middot; declaration window <b><?= e($tdWin['why']) ?></b> &middot; employees declare from <b>Statutory &amp; Compliance &rarr; TDS Declaration</b></p></div>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-export"><input type="hidden" name="kind" value="declarations"><input type="hidden" name="fy" value="<?= e($tdFy) ?>"><input type="hidden" name="state" value="<?= e($tdState) ?>"><?= keepHidden(orgFilterKeep($tdFilter)) ?>
        <button class="btn btn-primary" type="submit"><i class="fas fa-file-excel"></i> Export declarations</button></form>
      <?php if ($may('payroll.structure.publish') || isAdministrator()): ?><a class="btn btn-outline" href="?r=tds-settings"><i class="fas fa-sliders"></i> Windows &amp; reminders</a><?php endif; ?>
    </div>
  </div>
  <div class="dc-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px">
<?php foreach ([['Active employees', count($tdData['scoped'])], ['Declared', count(array_intersect_key($tdData['decls'], $tdCounts))], ['Not declared', count($tdData['scoped']) - count(array_intersect_key($tdData['decls'], $tdCounts))], ['Opted for old regime', $tdOld]] as [$l, $v]): ?>
    <div class="card" style="margin:0;padding:14px 16px"><div class="note" style="text-transform:uppercase;font-size:11px;letter-spacing:.05em"><?= e($l) ?></div><div style="font-size:26px;font-weight:700"><?= (int) $v ?></div></div>
<?php endforeach; ?>
  </div>
  <div class="card"><?= tdsOrgButtons('tds-declaration', $tdFilter, $tdData['scoped'], $tdCounts, $tdKeepQs,
      '<div><label for="td_state">Status</label><select id="td_state" name="state"><option value="">All</option><option value="declared"' . ($tdState === 'declared' ? ' selected' : '') . '>Declared</option><option value="pending"' . ($tdState === 'pending' ? ' selected' : '') . '>Not declared</option></select></div>'
      . '<input type="hidden" name="fy" value="' . e($tdFy) . '">') ?>
    <p class="note" style="margin:-4px 0 0">Button counts = employees who have declared.</p></div>
  <div class="card" style="padding:0">
    <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Employees &middot; <?= e($tdFy) ?></h3><p class="note" style="margin:0">Open a row to see the full computation or declare on the employee's behalf</p></div><div class="actions"><span class="badge badge-gray"><?= count($tdData['rows']) ?> employee(s)</span></div></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Employee ID</th><th>Name</th><th>Company</th><th>Regime</th><th>Status</th><th class="tds-n">Declared (investments)</th><th class="tds-n">Rent / year</th><th>Declared on</th><th></th></tr></thead>
      <tbody>
<?php if ($tdData['rows'] === []): ?><tr><td colspan="9" class="note">No employee matches these filters.</td></tr><?php endif; ?>
<?php foreach ($tdData['rows'] as $r): $d = $r['d']; $sum = 0; foreach ((array) ($d['items'] ?? []) as $c => $v) { if (!in_array($c, ['OTHER_INCOME', 'PREV_INCOME', 'PREV_TDS', 'PREV_PT', 'EARLIER_TDS', 'EARLIER_SALARY'], true)) { $sum += IncomeTax::p($v); } } ?>
        <tr><td><b><?= e(Employment::code($r['e'])) ?></b></td><td><?= e((string) ($r['e']['name'] ?? '')) ?></td><td><?= e(orgValueOf($store, $r['e'], 'company')) ?></td>
          <td><?= $d === null ? '<span class="note">' . e(ucfirst((string) $tdYear['default_regime'])) . ' (default)</span>' : '<span class="badge badge-' . ((string) $d['regime'] === 'old' ? 'amber' : 'blue') . '">' . e(ucfirst((string) $d['regime'])) . '</span>' ?></td>
          <td><?= $d === null ? '<span class="badge badge-gray">Not declared</span>' : '<span class="badge badge-green">Declared</span>' ?></td>
          <td class="tds-n"><?= $d === null ? '—' : tdsMoney($sum) ?></td><td class="tds-n"><?= $d === null ? '—' : tdsMoney(TaxDeclarations::rentAnnual((array) $d['rent'])) ?></td>
          <td><?= $d === null ? '—' : e(SmartPRS\Surface\Dates::dmy(substr((string) $d['submitted_at'], 0, 10))) ?></td>
          <td><a class="btn btn-sm" href="?r=tds-declaration&amp;id=<?= e((string) $r['e']['id']) ?>&amp;fy=<?= e($tdFy) ?>"><i class="fas fa-eye"></i> Open</a></td></tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php return; endif;

// ---- ONE EMPLOYEE -------------------------------------------------------------------------------
if ($tdWho['e'] === null): ?>
  <div class="page-header"><div><h2>TDS Declaration</h2></div></div>
  <div class="card"><p class="note" style="margin:0">This login is not linked to an employee record (or that employee is outside your reach), so there is nothing to declare. Ask an administrator to link your login under Administration &rarr; Users.</p></div>
<?php return; endif;

$e = $tdWho['e'];
$tdDecl = $taxDeclarations->declaration((string) $e['id'], $tdFy);
$tdPrev = $tdDecl === null ? $taxDeclarations->previous((string) $e['id'], $tdFy) : null;
$tdFill = $tdDecl ?? $tdPrev;
$tdWin = $taxDeclarations->window('declaration', $tdToday, $e);
$tdEdit = $tdWin['open'] || $may('payroll.run') || isAdministrator();
$tdS = tdsSheets($e, $tdFy, $tdDecl);
$tdPkg = $tdS['package'];
$tdF = $tdS['figures'];
$tdVal = static fn (string $c): string => ($v = (string) ($tdFill['items'][$c] ?? '')) === '' || IncomeTax::p($v) === 0 ? '' : Money::ofMinor(IncomeTax::p($v))->toDecimalString();
$tdRent = (array) ($tdFill['rent'] ?? []);
$tdRegime = (string) ($tdFill['regime'] ?? $tdYear['default_regime']);
$tdGroups = [];
foreach ((array) $tdYear['items'] as $it) { $tdGroups[(string) $it['group']][] = $it; }
$tdAge = $taxDeclarations->ageAt($e, $tdFy);
$tdDis = $tdEdit ? '' : ' disabled';

// ---- PRINTABLE FORMS (14 Oct): Form 124 (old 12BB) with the Form 122 regime opt-in, and the
// employee's tax computation statement — what corporate payroll hands out and files.
if (in_array((string) ($_GET['print'] ?? ''), ['form124', 'computation'], true)):
    $pv = (string) $_GET['print'];
    $pvSheet = $tdS[$tdS['regime']];
    $pvAmt = static fn (string $c): int => IncomeTax::p($tdDecl['items'][$c] ?? '');
    $pvCo = orgValueOf($store, $e, 'company');
    $pvCoRow = (string) ($e['employer_company_id'] ?? '') === '' ? [] : (array) ($store->find('companies', (string) $e['employer_company_id']) ?? []);
    $pvTan = (string) ($pvCoRow['tan'] ?? '') ?: 'TAN not set — Organization Configuration → Companies';
?>
  <style>
    @media print { body * { visibility: hidden !important; } #tds-print, #tds-print * { visibility: visible !important; } #tds-print { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; border: 0; } .no-print { display: none !important; } @page { size: A4; margin: 12mm; } }
    #tds-print { max-width: 820px; margin: 0 auto; background: #fff; color: #111; padding: 28px 32px; border: 1px solid var(--border); font-size: 12.5px; }
    #tds-print h1 { font-size: 17px; text-align: center; margin: 0 0 2px; } #tds-print .sub { text-align: center; color: #555; margin-bottom: 14px; }
    #tds-print table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; } #tds-print th, #tds-print td { border: 1px solid #999; padding: 5px 7px; text-align: left; vertical-align: top; }
    #tds-print th { background: #eef1f6; } #tds-print .n { text-align: right; font-family: var(--mono); white-space: nowrap; } #tds-print .sig { margin-top: 30px; display: flex; justify-content: space-between; }
  </style>
  <div class="no-print" style="margin-bottom:10px"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button> <a class="btn btn-outline" href="?r=tds-declaration&amp;id=<?= e((string) $e['id']) ?>&amp;fy=<?= e($tdFy) ?>">Back</a></div>
  <div id="tds-print">
<?php if ($pv === 'form124'): ?>
    <h1>FORM 124 <span style="font-weight:400">(earlier Form 12BB)</span></h1>
    <div class="sub">Statement of particulars of claims by an employee for deduction of tax · Section 392 of the Income-tax Act, 2025 · Tax Year <?= e($tdFy) ?><?= $tdDecl === null ? ' · <b style="color:#b91c1c">NOT YET DECLARED</b>' : '' ?></div>
    <table>
      <tr><th style="width:34%">Name and address of the employee</th><td><?= e((string) ($e['name'] ?? '')) ?><br><?= e(trim(implode(', ', array_filter([(string) ($e['present_house_no'] ?? ''), (string) ($e['present_area'] ?? ''), (string) ($e['present_city'] ?? ''), (string) ($e['present_pincode'] ?? '')])), ', ') ?: (string) ($e['address'] ?? '')) ?></td></tr>
      <tr><th>Employee ID / PAN</th><td><?= e(Employment::code($e)) ?> · <?= e((string) ($e['pan'] ?? '') ?: 'PAN not on record') ?></td></tr>
      <tr><th>Employer, TAN and PAN</th><td><?= e((string) ($pvCoRow['legal_name'] ?? '') ?: $pvCo) ?><br>TAN <?= e($pvTan) ?> · PAN <?= e((string) ($pvCoRow['pan'] ?? '') ?: '—') ?><br><?= e((string) ($pvCoRow['address'] ?? '')) ?></td></tr>
      <tr><th>Tax regime opted (Form 122)</th><td><b><?= e($tdS['regime'] === 'old' ? 'Old regime — opting out of the default new regime' : 'New regime (default)') ?></b></td></tr>
    </table>
    <table>
      <thead><tr><th style="width:5%">S.No</th><th style="width:30%">Nature of claim</th><th style="width:18%" class="n">Amount (₹)</th><th>Evidence / particulars</th></tr></thead>
      <tbody>
        <tr><td>1</td><td>House Rent Allowance</td><td class="n"><?= tdsMoney(TaxDeclarations::rentAnnual((array) ($tdDecl['rent'] ?? []))) ?></td><td>(i) Rent paid to the landlord: <?= e((string) ($tdRent['monthly'] ?? '0')) ?> × <?= e((string) ($tdRent['months'] ?? '0')) ?> months<br>(ii) Name of the landlord: <?= e((string) ($tdRent['landlord_name'] ?? '')) ?><?= ($tdRent['relationship'] ?? '') !== '' ? ' (' . e((string) $tdRent['relationship']) . ')' : '' ?><br>(iii) Address of the landlord / house: <?= e((string) ($tdRent['address'] ?? '')) ?>, <?= e((string) ($tdRent['city'] ?? '')) ?><br>(iv) PAN of the landlord: <?= e((string) ($tdRent['landlord_pan'] ?? '')) ?></td></tr>
        <tr><td>2</td><td>Leave travel concession or assistance</td><td class="n"><?= tdsMoney($pvAmt('LTA')) ?></td><td><?= e((string) ($tdRent['lta_journey'] ?? '')) ?></td></tr>
        <tr><td>3</td><td>Deduction of interest on borrowing (house property)</td><td class="n"><?= tdsMoney($pvAmt('HL_INTEREST')) ?></td><td>(i) Interest paid to the lender<br>(ii) Name of the lender: <?= e((string) ($tdRent['lender_name'] ?? '')) ?><br>(iii) Address of the lender: <?= e((string) ($tdRent['lender_address'] ?? '')) ?><br>(iv) PAN of the lender: <?= e((string) ($tdRent['lender_pan'] ?? '')) ?></td></tr>
        <tr><td>4</td><td colspan="3"><b>Deductions under Chapter VIII (old Chapter VI-A)</b></td></tr>
<?php $pvN = 0; foreach ((array) $tdYear['items'] as $it): $c = (string) $it['code'];
        if (in_array((string) $it['group'], ['EXEMPT', 'HOUSE', 'INCOME'], true)) { continue; }
        $v = $c === 'EPF' ? (int) $tdF['employee_pf'] : ($c === 'NPS_EMPLOYER' ? (int) $tdF['employer_nps'] : $pvAmt($c));
        if ($v <= 0) { continue; } $pvN++; ?>
        <tr><td><?= '4.' . $pvN ?></td><td><?= e((string) $it['label']) ?></td><td class="n"><?= tdsMoney($v) ?></td><td><?= e((string) ($it['proof'] ?? '')) ?></td></tr>
<?php endforeach; if ($pvN === 0): ?><tr><td></td><td colspan="3">No Chapter VIII claim</td></tr><?php endif; ?>
        <tr><td>5</td><td>Other income / previous employer (Form 122)</td><td class="n"><?= tdsMoney($pvAmt('OTHER_INCOME') + $pvAmt('PREV_INCOME')) ?></td><td>Previous employer salary <?= tdsMoney($pvAmt('PREV_INCOME')) ?>, TDS <?= tdsMoney($pvAmt('PREV_TDS')) ?>, PT <?= tdsMoney($pvAmt('PREV_PT')) ?>; other income <?= tdsMoney($pvAmt('OTHER_INCOME')) ?></td></tr>
      </tbody>
    </table>
    <p><b>Verification</b><br>I, <?= e((string) ($e['name'] ?? '')) ?><?= ($e['father'] ?? '') !== '' ? ', son / daughter of ' . e((string) $e['father']) : '' ?>, do hereby certify that the information given above is complete and correct.</p>
    <div class="sig"><div>Place: ____________________<br><br>Date: <?= e($tdDecl === null ? '____________' : SmartPRS\Surface\Dates::dmy(substr((string) $tdDecl['submitted_at'], 0, 10))) ?></div><div>Signature of the employee<br><br>Designation: <?= e(orgValueOf($store, $e, 'designation')) ?><br>Full name: <?= e((string) ($e['name'] ?? '')) ?></div></div>
<?php else: ?>
    <h1>STATEMENT OF INCOME-TAX COMPUTATION</h1>
    <div class="sub"><?= e((string) ($pvCoRow['legal_name'] ?? '') ?: $pvCo) ?> · TAN <?= e($pvTan) ?> · Tax Year <?= e($tdFy) ?> · <?= e(ucfirst($tdS['regime'])) ?> regime · amounts <?= e((string) $tdS['basis']) ?> · as on <?= e(date('d-m-Y')) ?></div>
    <table><tr><th style="width:25%">Employee</th><td><?= e(Employment::label($e)) ?></td><th style="width:15%">PAN</th><td><?= e((string) ($e['pan'] ?? '')) ?></td></tr>
      <tr><th>Designation</th><td><?= e(orgValueOf($store, $e, 'designation')) ?></td><th>Age</th><td><?= $tdAge ?></td></tr></table>
    <table><thead><tr><th>Particulars</th><th class="n" style="width:24%">Amount (₹)</th></tr></thead><tbody>
<?php foreach ($pvSheet['lines'] as $l): $b = in_array($l[0], ['Income from salary', 'Gross total income', 'Taxable income', 'Total tax for the year'], true); ?>
      <tr<?= $b ? ' style="font-weight:700;background:#f4f6fa"' : '' ?>><td><?= e((string) $l[0]) ?><?= ($l[2] ?? '') !== '' ? '<br><span style="font-weight:400;color:#555;font-size:11.5px">' . e((string) $l[2]) . '</span>' : '' ?></td><td class="n"><?= $l[1] < 0 ? '(' . tdsMoney(-$l[1]) . ')' : tdsMoney($l[1]) ?></td></tr>
<?php endforeach; ?>
      <tr><td>Less: TDS already deducted this year</td><td class="n">(<?= tdsMoney($tdF['tds_paid'] + $tdS['paid_before']) ?>)</td></tr>
      <tr style="font-weight:700"><td>Balance tax payable through salary</td><td class="n"><?= tdsMoney(max(0, $pvSheet['total'] - $tdF['tds_paid'] - $tdS['paid_before'])) ?></td></tr>
      <tr style="font-weight:700"><td>Monthly TDS for the remaining <?= (int) $tdF['months_left'] ?> month(s)</td><td class="n"><?= tdsMoney((int) $tdS['monthly_tds']) ?></td></tr>
    </tbody></table>
    <div style="border:2px solid #1F3A5F;border-radius:6px;padding:10px 14px;margin:4px 0 12px;display:flex;justify-content:space-between;gap:16px;align-items:center">
      <div><b>Overall tax payable for <?= e($tdFy) ?> (<?= e(ucfirst($tdS['regime'])) ?> regime)</b><br><span style="color:#444"><?= e((string) ($pvSheet['why'] ?? '')) ?></span></div>
      <div style="font-size:18px;font-weight:800;font-family:var(--mono);white-space:nowrap"><?= tdsMoney((int) $pvSheet['total']) ?></div>
    </div>
    <p style="color:#555">For comparison — tax under the <?= $tdS['regime'] === 'new' ? 'old' : 'new' ?> regime: <?= tdsMoney((int) $tdS[$tdS['regime'] === 'new' ? 'old' : 'new']['total']) ?>. Year = <?= (int) $tdF['closed'] ?> closed payroll month(s)<?= (int) ($tdF['estimated'] ?? 0) > 0 ? ' + ' . (int) $tdF['estimated'] . ' estimated' : '' ?> + projection for <?= (int) $tdF['months_left'] ?> month(s). This is a computation for TDS purposes, not the certificate (Form 130).</p>
    <div class="sig"><div></div><div>For <?= e((string) ($pvCoRow['legal_name'] ?? '') ?: $pvCo) ?><br><br><br><?= e((string) ($pvCoRow['tds_person_name'] ?? '') ?: '____________________') ?><br><?= e((string) ($pvCoRow['tds_person_designation'] ?? '')) ?> — person responsible for TDS</div></div>
<?php endif; ?>
  </div>
<?php return; endif;
?>
  <div class="page-header">
    <div><h2>TDS Declaration <?= $tdWho['self'] ? '' : '&mdash; ' . e(Employment::label($e)) ?></h2>
      <p><?= e((string) $tdYear['label']) ?> &middot; window <b><?= e($tdWin['why']) ?></b><?= $tdDecl !== null ? ' &middot; last submitted ' . e(SmartPRS\Surface\Dates::dmy(substr((string) $tdDecl['submitted_at'], 0, 10))) . ' by ' . e((string) $tdDecl['submitted_by']) : '' ?></p></div>
    <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if ($tdWho['hr']): ?><a class="btn btn-outline" href="?r=tds-declaration&amp;fy=<?= e($tdFy) ?>"><i class="fas fa-arrow-left"></i> All employees</a><?php endif; ?>
      <a class="btn btn-outline" href="?r=tds-proofs<?= $tdWho['self'] ? '' : '&amp;id=' . e((string) $e['id']) ?>&amp;fy=<?= e($tdFy) ?>"><i class="fas fa-file-arrow-up"></i> Investment proofs</a>
      <a class="btn btn-outline" target="_blank" href="?r=tds-declaration&amp;id=<?= e((string) $e['id']) ?>&amp;fy=<?= e($tdFy) ?>&amp;print=form124"><i class="fas fa-print"></i> Form 124 / 122</a>
      <a class="btn btn-outline" target="_blank" href="?r=tds-declaration&amp;id=<?= e((string) $e['id']) ?>&amp;fy=<?= e($tdFy) ?>&amp;print=computation"><i class="fas fa-file-invoice"></i> Tax computation</a>
    </div>
  </div>
<?php if ($tdPrev !== null): ?>
  <div class="card" style="border-left:4px solid var(--accent)"><b>Pre-filled from your <?= e((string) $tdPrev['financial_year']) ?> declaration.</b> Keep the same figures or change them, then press <b>Submit declaration</b> — nothing is saved for <?= e($tdFy) ?> until you do.</div>
<?php endif; ?>
<?php if (!$tdEdit): ?>
  <div class="card" style="border-left:4px solid #b45309"><b>The declaration window is <?= e($tdWin['why']) ?>.</b> You can see your declaration and tax, but not change it. Speak to HR / Payroll if something must be corrected.</div>
<?php endif; ?>

  <div class="card">
    <h3 style="margin:0 0 10px;font-size:15px">My package &amp; profile</h3>
    <div class="tds-grid" style="margin-bottom:12px">
      <div><span class="note">Employee</span><br><b><?= e(Employment::label($e)) ?></b></div>
      <div><span class="note">PAN</span><br><b><?= e((string) ($e['pan'] ?? '')) ?: '<span style="color:#b91c1c">not on record — TDS is 20% without PAN</span>' ?></b></div>
      <div><span class="note">Age at year end</span><br><b><?= $tdAge ?><?= $tdAge >= 60 ? ' (senior citizen)' : '' ?></b><?= (string) ($e['dob'] ?? '') === '' ? ' <span class="note">(date of birth not on record)</span>' : '' ?></div>
      <div><span class="note">Company / city</span><br><b><?= e(orgValueOf($store, $e, 'company')) ?> &middot; <?= e((string) ($e['present_city'] ?? '') ?: '—') ?></b></div>
      <div><span class="note">Residential status</span><br><b><?= $taxDeclarations->isResident($e) ? 'Resident' : 'Non-resident (no rebate)' ?></b></div>
    </div>
<?php if ($tdPkg['why'] !== null): ?><div class="problem">The salary structure could not be worked out for this employee: <?= e((string) $tdPkg['why']) ?></div><?php endif; ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Component</th><th>Type</th><th class="tds-n">Monthly</th><th class="tds-n">Year (projected)</th></tr></thead>
      <tbody>
<?php foreach ($tdPkg['lines'] as $l): ?>
        <tr><td><?= e((string) $l['label']) ?> <span class="note"><?= e((string) $l['code']) ?></span></td><td><?= e($l['kind'] === 'earning' ? 'Earning' : ($l['kind'] === 'deduction' ? 'Deduction' : 'Employer contribution')) ?></td><td class="tds-n"><?= tdsMoney((int) $l['minor']) ?></td><td class="tds-n"><?= tdsMoney((int) $l['minor'] * 12) ?></td></tr>
<?php endforeach; ?>
        <tr class="tds-strong"><td colspan="2">Gross salary for the year (closed months + projection)</td><td class="tds-n"><?= tdsMoney((int) ($tdPkg['monthly']['GROSS'] ?? 0)) ?></td><td class="tds-n"><?= tdsMoney((int) $tdF['gross']) ?></td></tr>
<?php $tdC = $tdS[$tdS['regime']]; $tdRl = ucfirst((string) $tdS['regime']); ?>
        <tr class="tds-strong"><td colspan="2">Taxable income &middot; <span class="tds-reg"><?= e($tdRl) ?></span> regime <span class="note">(gross − standard deduction − exemptions − deductions; monthly = year ÷ 12)</span></td><td class="tds-n" id="pk-taxable-m"><?= tdsMoney(intdiv((int) $tdC['taxable'], 12)) ?></td><td class="tds-n" id="pk-taxable"><?= tdsMoney((int) $tdC['taxable']) ?></td></tr>
        <tr class="tds-strong"><td colspan="2">Tax amount &middot; <span class="tds-reg"><?= e($tdRl) ?></span> regime <span class="note">(incl. 4% cess; monthly = TDS per month for the months left)</span></td><td class="tds-n" id="pk-monthly" style="color:var(--accent)"><?= tdsMoney((int) $tdS['monthly_tds']) ?></td><td class="tds-n" id="pk-total"><?= tdsMoney((int) $tdC['total']) ?></td></tr>
      </tbody>
    </table></div>
  </div>

  <form method="post" id="tds-form">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tds-declare">
    <input type="hidden" name="employee_id" value="<?= e((string) $e['id']) ?>"><input type="hidden" name="fy" value="<?= e($tdFy) ?>">
    <div class="card">
      <h3 style="margin:0 0 8px;font-size:15px">1. Tax regime</h3>
      <p class="note" style="margin:0 0 10px"><?= e((string) $tdYear['regime_note']) ?></p>
      <label style="display:inline-flex;gap:8px;align-items:center;margin-right:24px"><input type="radio" name="regime" value="new"<?= $tdRegime === 'new' ? ' checked' : '' ?><?= $tdDis ?>> <b>New regime</b> <span class="note">— lower rates, standard deduction <?= tdsMoney(IncomeTax::p($tdYear['regimes']['new']['standard_deduction'])) ?>, almost no deductions</span></label>
      <label style="display:inline-flex;gap:8px;align-items:center"><input type="radio" name="regime" value="old"<?= $tdRegime === 'old' ? ' checked' : '' ?><?= $tdDis ?>> <b>Old regime</b> <span class="note">— HRA, LTA, 80C, 80D, home loan … standard deduction <?= tdsMoney(IncomeTax::p($tdYear['regimes']['old']['standard_deduction'])) ?></span></label>
    </div>

    <div class="card">
      <h3 style="margin:0 0 10px;font-size:15px">2. Declaration (Form 124)</h3>
      <div class="tds-sec"><h4>A. House rent — for HRA exemption (old regime)</h4><div class="tds-body tds-grid">
        <div><label>Monthly rent (₹)</label><input name="rent[monthly]" inputmode="decimal" value="<?= e((string) ($tdRent['monthly'] ?? '')) ?>"<?= $tdDis ?>></div>
        <div><label>Months rented this year</label><input name="rent[months]" type="number" min="0" max="12" value="<?= e((string) ($tdRent['months'] ?? '12')) ?>"<?= $tdDis ?>></div>
        <div><label>City of the rented house</label><input name="rent[city]" value="<?= e((string) ($tdRent['city'] ?? ($e['present_city'] ?? ''))) ?>"<?= $tdDis ?>><span class="note cap">Metro (50%): <?= e(implode(', ', array_slice((array) $tdYear['hra']['metro_cities'], 0, 10))) ?>; elsewhere 40%</span></div>
        <div><label>Landlord name</label><input name="rent[landlord_name]" value="<?= e((string) ($tdRent['landlord_name'] ?? '')) ?>"<?= $tdDis ?>></div>
        <div><label>Landlord PAN</label><input name="rent[landlord_pan]" maxlength="10" style="text-transform:uppercase" value="<?= e((string) ($tdRent['landlord_pan'] ?? '')) ?>"<?= $tdDis ?>><span class="note cap">Required when rent is over <?= tdsMoney(IncomeTax::p($tdYear['hra']['landlord_pan_above_annual_rent'])) ?> a year</span></div>
        <div><label>Relationship to landlord (if family)</label><input name="rent[relationship]" value="<?= e((string) ($tdRent['relationship'] ?? '')) ?>"<?= $tdDis ?>></div>
        <div style="grid-column:1/-1"><label>Address of the rented house</label><input name="rent[address]" value="<?= e((string) ($tdRent['address'] ?? '')) ?>"<?= $tdDis ?>></div>
      </div></div>
<?php
$tdSections = [['EXEMPT', 'B. Leave travel (LTA)'], ['HOUSE', 'C. Interest on home loan'], ['80C', 'D. ' . IncomeTax::group($tdYear, '80C')['label']], ['80CCD1B', 'E. ' . IncomeTax::group($tdYear, '80CCD1B')['label']], ['80CCD2', 'F. ' . IncomeTax::group($tdYear, '80CCD2')['label']], ['80D', 'G. ' . IncomeTax::group($tdYear, '80D')['label']], ['OTHER_VIA', 'H. ' . IncomeTax::group($tdYear, 'OTHER_VIA')['label']], ['INCOME', 'I. Other income and previous employer (joined this year)']];
foreach ($tdSections as [$g, $title]): $grp = IncomeTax::group($tdYear, $g); ?>
      <div class="tds-sec"><h4><?= e($title) ?><?= isset($grp['cap']) ? ' <span class="note">— limit ' . tdsMoney(IncomeTax::p($grp['cap'])) . '</span>' : '' ?></h4><div class="tds-body">
<?php if (($grp['note'] ?? '') !== ''): ?><p class="note" style="margin:0 0 8px"><?= e((string) $grp['note']) ?></p><?php endif; ?>
        <div class="tds-grid">
<?php foreach ($tdGroups[$g] ?? [] as $it): $c = (string) $it['code']; ?>
<?php if (($it['auto'] ?? '') === 'employee_pf'): ?>
          <div><label><?= e((string) $it['label']) ?></label><input value="<?= e(Money::ofMinor((int) $tdF['employee_pf'])->format()) ?>" disabled><span class="note cap">From payroll (projected for the year)</span></div>
<?php elseif ($c === 'NPS_EMPLOYER'): ?>
          <div><label><?= e((string) $it['label']) ?></label><input value="<?= e(Money::ofMinor((int) $tdF['employer_nps'])->format()) ?>" disabled><span class="note cap"><?= $tdF['employer_nps'] > 0 ? 'From payroll' : 'No employer NPS in your salary structure' ?></span></div>
<?php else: ?>
          <div><label><?= e((string) $it['label']) ?></label><input name="items[<?= e($c) ?>]" inputmode="decimal" placeholder="0" value="<?= e($tdVal($c)) ?>"<?= $tdDis ?>><?php if (isset($it['cap'])): ?><span class="note cap">Limit <?= tdsMoney(IncomeTax::p($it['cap'])) ?><?= isset($it['cap_if_senior_self']) ? ' (' . tdsMoney(IncomeTax::p($it['cap_if_senior_self'])) . ' if you are 60+)' : '' ?><?= isset($it['cap_if_senior_parents']) ? ' (' . tdsMoney(IncomeTax::p($it['cap_if_senior_parents'])) . ' if a parent is 60+)' : '' ?></span><?php endif; ?><span class="note cap">Proof: <?= e((string) ($it['proof'] ?? '')) ?></span></div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($g === 'HOUSE'): ?>
          <div><label>Lender name</label><input name="rent[lender_name]" value="<?= e((string) ($tdRent['lender_name'] ?? '')) ?>"<?= $tdDis ?>></div>
          <div><label>Lender PAN (if not a bank)</label><input name="rent[lender_pan]" maxlength="10" style="text-transform:uppercase" value="<?= e((string) ($tdRent['lender_pan'] ?? '')) ?>"<?= $tdDis ?>></div>
          <div style="grid-column:1/-1"><label>Lender address</label><input name="rent[lender_address]" value="<?= e((string) ($tdRent['lender_address'] ?? '')) ?>"<?= $tdDis ?>></div>
<?php endif; ?>
<?php if ($g === 'EXEMPT'): ?>
          <div style="grid-column:span 2"><label>Journey (from – to, dates, family members)</label><input name="rent[lta_journey]" value="<?= e((string) ($tdRent['lta_journey'] ?? '')) ?>"<?= $tdDis ?>></div>
<?php endif; ?>
<?php if ($g === '80D'): ?>
          <div><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="parents_senior" value="1"<?= (string) ($tdFill['parents_senior'] ?? '') === 'yes' ? ' checked' : '' ?><?= $tdDis ?>> A parent is 60 or older</label></div>
<?php endif; ?>
        </div></div></div>
<?php endforeach; ?>
      <p class="note">I declare that the information above is true; I will submit the proofs when the proof window opens, and understand that unproven amounts stop counting after the proof cut-off and TDS is adjusted in the remaining months.</p>
<?php if ($tdEdit): ?>
      <button class="go" type="submit"><i class="fas fa-paper-plane"></i> Submit declaration</button>
<?php endif; ?>
    </div>
  </form>

  <div class="card">
    <h3 style="margin:0 0 4px;font-size:15px">3. My tax for <?= e($tdFy) ?> <span class="note" id="tds-live">updates as you type</span></h3>
    <div id="tds-sheet"><?= tdsSheetHtml($tdS) ?></div>
  </div>
<script>
// THE PACKAGE TABLE'S TAXABLE / TAX ROWS follow the regime ticked, instantly (both regimes are in #tds-sum).
window.tdsPaint = function(){
  var d = document.getElementById('tds-sum'); if (!d) { return; }
  var r = (document.querySelector('input[name=regime]:checked') || {}).value || d.dataset.regime;
  var g = function(k){ return d.getAttribute('data-' + r + '-' + k) || ''; };
  [['pk-taxable','taxable'],['pk-taxable-m','taxable-m'],['pk-total','total'],['pk-monthly','monthly']].forEach(function(p){ var el = document.getElementById(p[0]); if (el) { el.innerHTML = g(p[1]); } });
  document.querySelectorAll('.tds-reg').forEach(function(el){ el.textContent = r === 'old' ? 'Old' : 'New'; });
};
document.querySelectorAll('input[name=regime]').forEach(function(i){ i.addEventListener('change', window.tdsPaint); });
</script>
<?php if ($tdEdit): ?>
<script>
(function(){
  var f = document.getElementById('tds-form'), out = document.getElementById('tds-sheet'), tag = document.getElementById('tds-live'), t = null;
  function go(){
    var d = new FormData(f); d.set('action', 'tds-preview');
    tag.textContent = 'working out…';
    fetch(location.pathname + '?r=tds-declaration', {method: 'POST', body: d, credentials: 'same-origin'})
      .then(function(r){ return r.text(); }).then(function(h){ out.innerHTML = h; window.tdsPaint(); tag.textContent = 'not saved yet — press Submit declaration to keep it'; })
      .catch(function(){ tag.textContent = 'could not work it out — Submit will still save'; });
  }
  f.addEventListener('input', function(){ clearTimeout(t); t = setTimeout(go, 500); });
  f.addEventListener('change', function(){ clearTimeout(t); t = setTimeout(go, 200); });
})();
</script>
<?php endif; ?>
