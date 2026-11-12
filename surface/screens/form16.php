<?php declare(strict_types=1);
/**
 * FORM 16 (Form 130 under the Income-tax Act, 2025) — owner, 15 Oct.
 *   HR (payroll.view): every employee for the year — PAN, salary paid, TDS deducted, Part A status,
 *     readiness; upload TRACES Part A PDFs/ZIP (matched by PAN), print Part B one or all, download
 *     all Part A as a ZIP, release the year to employees (payroll.run / Administrator).
 *   Employee: once HR releases the year, their own Part A (download) and Part B (print / save PDF).
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/form16-lib.php';
$f16 = f16Store();
$f16Years = array_values(array_filter($taxDeclarations->yearList(), static fn (string $y): bool => $y <= $taxDeclarations->autoFy()));
$fy = in_array((string) ($_GET['fy'] ?? ''), $f16Years, true) ? (string) $_GET['fy'] : $taxDeclarations->fy();
$span = f16Span($fy);
$hr = $may('payroll.view');
$manage = $may('payroll.run') || isAdministrator();
$own = (string) ($session->user()['employee_id'] ?? '');
$released = $f16->released($fy);
$partA = $f16->partA($fy);
$runRows = $taxDeclarations->runRows();
echo f16PrintCss();

if ($taxDeclarations->year($fy) === []): ?>
  <div class="page-header"><div><h2>Form 16</h2></div></div>
  <div class="card"><div class="problem" style="margin:0">No income-tax rules exist for <?= e($fy) ?>.</div></div>
<?php return; endif;

// ---- PRINT: one employee's Part B, or everyone in the filter -----------------------------------
if (in_array((string) ($_GET['print'] ?? ''), ['partb', 'all'], true)):
    $people = [];
    if ($_GET['print'] === 'all') {
        $hr || $people = [];
        $hr && $people = orgFilterApply($store, f16People($fy, $runRows), orgFilter());
    } else {
        $id = (string) ($_GET['id'] ?? '');
        $e = $id === '' ? null : $store->find('employees', $id);
        $ok = $e !== null && (($hr && $scope->only([$e], $may('employee.list')) !== []) || ($id === $own && $released));
        $ok && $people = [$e];
    }
?>
  <div class="no-print" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button> <a class="btn btn-outline" href="?r=form16&amp;fy=<?= e($fy) ?>">Back</a>
    <span class="note"><?= count($people) ?> certificate(s) &middot; in the print dialog choose "Save as PDF"; each employee starts on a new page.</span></div>
<?php if ($people === []): ?>
  <div class="card"><p class="note" style="margin:0">Nothing to print — the employee is outside your reach, or Form 16 for <?= e($fy) ?> has not been released yet.</p></div>
<?php else: ?>
  <div id="f16-print"><?php foreach ($people as $e) { echo f16PartBHtml($e, $fy, f16PartB($e, $fy, $runRows), $partA[(string) $e['id']] ?? null); } ?></div>
<?php endif;
return; endif;

// ---- EMPLOYEE: their own certificate ----------------------------------------------------------
if (!$hr):
    $e = $own === '' ? null : $store->find('employees', $own);
?>
  <div class="page-header"><div><h2>Form 16 &middot; <?= e($fy) ?></h2><p>Your annual certificate of tax deducted from salary (Form 130 under the Income-tax Act, 2025)</p></div>
<?php if (count($f16Years) > 1): ?><div class="actions"><form method="get"><input type="hidden" name="r" value="form16"><select name="fy" onchange="this.form.submit()"><?php foreach ($f16Years as $y): ?><option<?= $y === $fy ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select></form></div><?php endif; ?></div>
<?php if ($e === null): ?>
  <div class="card"><p class="note" style="margin:0">This login is not linked to an employee record.</p></div>
<?php elseif (!$released): ?>
  <div class="card"><p style="margin:0"><b>Form 16 for <?= e($fy) ?> is not available yet.</b> HR issues it after the year ends on <?= e(date('d-m-Y', (int) strtotime($span['to'] . '-01 +1 month -1 day'))) ?>, once the last quarter's TDS return is filed — by 15 June. Your tax so far is on <a href="?r=tds-declaration">TDS Declaration</a>.</p></div>
<?php else: $pa = $partA[(string) $e['id']] ?? null; ?>
  <div class="card">
    <div class="tds-grid" style="margin-bottom:14px">
      <div><span class="note">Employee</span><br><b><?= e(Employment::label($e)) ?></b></div>
      <div><span class="note">PAN</span><br><b><?= e((string) ($e['pan'] ?? '') ?: '—') ?></b></div>
      <div><span class="note">Tax year</span><br><b><?= e($fy) ?></b></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
<?php if ($pa !== null): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-file"><input type="hidden" name="fy" value="<?= e($fy) ?>"><input type="hidden" name="id" value="<?= e((string) $e['id']) ?>"><button class="btn btn-primary" type="submit"><i class="fas fa-file-pdf"></i> Download Part A (TRACES)</button></form>
<?php else: ?>
      <span class="badge badge-gray">Part A not uploaded yet</span>
<?php endif; ?>
      <a class="btn btn-primary" target="_blank" href="?r=form16&amp;fy=<?= e($fy) ?>&amp;print=partb&amp;id=<?= e((string) $e['id']) ?>"><i class="fas fa-file-invoice"></i> Part B — view / save as PDF</a>
    </div>
    <p class="note" style="margin:12px 0 0">Part A shows the TDS your employer deposited with the Government, quarter by quarter; Part B shows your salary, deductions and the tax worked out. Keep both for your income-tax return.</p>
  </div>
<?php endif;
return; endif;

// ---- HR ---------------------------------------------------------------------------------------
$filter = orgFilter();
$scoped = f16People($fy, $runRows);
$rows = [];
foreach (orgFilterApply($store, $scoped, $filter) as $e) {
    $decl = $taxDeclarations->declaration((string) $e['id'], $fy);
    // Cheap per row: closed runs only (no payslip is worked out here; Part B does that when printed).
    $f = $taxDeclarations->figures($e, $fy, $span['ended'] ? date('Y-m', (int) strtotime($span['to'] . '-01 +1 month')) : date('Y-m'), [], $runRows, \SmartPRS\Surface\TaxDeclarations::estimates($decl));
    $co = f16Company($e);
    $issues = [];
    (string) ($e['pan'] ?? '') === '' && $issues[] = 'No PAN';
    (string) ($co['tan'] ?? '') === '' && $issues[] = 'No company TAN';
    (string) ($co['tds_person_name'] ?? '') === '' && $issues[] = 'No responsible person';
    $span['ended'] && (int) $f['estimated'] > 0 && $issues[] = (int) $f['estimated'] . ' month(s) paid outside SmartPRS 3 not entered';
    !isset($partA[(string) $e['id']]) && $issues[] = 'Part A not uploaded';
    $rows[] = ['e' => $e, 'gross' => (int) $f['gross'] + IncomeTax::p($decl['items']['EARLIER_SALARY'] ?? ''), 'tds' => (int) $f['tds_paid'] + IncomeTax::p($decl['items']['EARLIER_TDS'] ?? ''), 'issues' => $issues];
}
$counts = array_fill_keys(array_map(static fn (array $e): string => (string) $e['id'], $scoped), 1);
$nA = count(array_filter($rows, static fn (array $r): bool => isset($partA[(string) $r['e']['id']])));
$nReady = count(array_filter($rows, static fn (array $r): bool => $r['issues'] === []));
$keep = keepHidden(orgFilterKeep($filter));
?>
<style>.f16-n{text-align:right;font-family:var(--mono);white-space:nowrap}.f16-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:16px}.f16-tiles .card{margin:0;padding:14px 16px}.f16-tiles .note{text-transform:uppercase;font-size:11px;letter-spacing:.05em}.f16-tiles b{display:block;font-size:24px;margin-top:4px}</style>
<div class="page-header">
  <div><h2>Form 16 &middot; <?= e($fy) ?></h2><p>Annual TDS certificate (Form 130 under the Income-tax Act, 2025, earlier Form 16) &middot; <?= e(date('d-m-Y', (int) strtotime($span['from'] . '-01'))) ?> to <?= e(date('d-m-Y', (int) strtotime($span['to'] . '-01 +1 month -1 day'))) ?> &middot; <?= $span['ended'] ? 'year ended' : '<b style="color:#b45309">year in progress — Part B is provisional</b>' ?></p></div>
  <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
<?php if (count($f16Years) > 1): ?><form method="get"><input type="hidden" name="r" value="form16"><select name="fy" onchange="this.form.submit()"><?php foreach ($f16Years as $y): ?><option<?= $y === $fy ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select></form><?php endif; ?>
    <a class="btn btn-outline" target="_blank" href="?r=form16&amp;fy=<?= e($fy) ?>&amp;print=all<?= orgFilterQuery($filter) ?>"><i class="fas fa-print"></i> Print all Part B</a>
    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-file"><input type="hidden" name="fy" value="<?= e($fy) ?>"><input type="hidden" name="all" value="1"><?= $keep ?><button class="btn btn-outline" type="submit"<?= $nA === 0 ? ' disabled title="No Part A uploaded yet"' : '' ?>><i class="fas fa-file-zipper"></i> Download all Part A</button></form>
  </div>
</div>

<div class="f16-tiles">
  <div class="card"><span class="note">Employees</span><b><?= count($rows) ?></b></div>
  <div class="card"><span class="note">Part A uploaded</span><b><?= $nA ?></b></div>
  <div class="card"><span class="note">Ready to issue</span><b style="color:<?= $nReady === count($rows) && $rows !== [] ? '#16a34a' : 'var(--accent)' ?>"><?= $nReady ?></b></div>
  <div class="card"><span class="note">Employees can see it</span><b style="color:<?= $released ? '#16a34a' : '#64748b' ?>"><?= $released ? 'Yes' : 'Not yet' ?></b></div>
</div>

<?php if ($manage): ?>
<div class="card">
  <h3 style="font-size:15px;margin:0 0 4px">How to issue Form 16</h3>
  <ol class="note" style="margin:0 0 12px;padding-left:20px;line-height:1.7">
    <li>After 31 March, file the Q4 TDS return. Then on <b>TRACES</b> (tdscpc.gov.in) → Downloads → Form 16, request and download Part A for all employees.</li>
    <li>Upload the PDFs or the ZIP below — each file is matched to the employee by the PAN in its file name. If the ZIP has a password (TRACES uses one), type it; the company TANs are also tried.</li>
    <li>Fix anything the "Ready" column lists, check a few Part B printouts, then <b>Release to employees</b>.</li>
  </ol>
  <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end">
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-upload"><input type="hidden" name="fy" value="<?= e($fy) ?>">
      <div><label for="f16_files">Part A files (PDF or ZIP, several at once)</label><input id="f16_files" type="file" name="files[]" multiple accept=".pdf,.zip" required></div>
      <div><label for="f16_pw">ZIP password (if any)</label><input id="f16_pw" type="password" name="password" autocomplete="off" style="width:160px"></div>
      <button class="btn btn-primary" type="submit"><i class="fas fa-upload"></i> Upload Part A</button></form>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-release"><input type="hidden" name="fy" value="<?= e($fy) ?>"><input type="hidden" name="on" value="<?= $released ? '0' : '1' ?>">
      <button class="btn <?= $released ? 'btn-outline' : 'btn-primary' ?>" type="submit"><i class="fas <?= $released ? 'fa-eye-slash' : 'fa-paper-plane' ?>"></i> <?= $released ? 'Withdraw from employees' : 'Release to employees' ?></button></form>
  </div>
</div>
<?php endif; ?>

<div class="card"><?= tdsOrgButtons('form16', $filter, $scoped, $counts, '&amp;fy=' . e($fy)) ?></div>

<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Employees &middot; <?= e($fy) ?></h3><p class="note" style="margin:0">Active employees and everyone paid in the year (leavers included). Salary and TDS are from the closed payroll plus amounts HR entered for months before SmartPRS 3.</p></div><div class="actions"><span class="badge badge-gray"><?= count($rows) ?> employee(s)</span></div></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee ID</th><th>Name</th><th>Designation</th><th>Branch</th><th>PAN</th><th class="f16-n">Salary paid</th><th class="f16-n">TDS deducted</th><th>Part A</th><th>Ready</th><th></th></tr></thead>
    <tbody>
<?php if ($rows === []): ?><tr><td colspan="10" class="note">No employee matches these filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): $e = $r['e']; $pa = $partA[(string) $e['id']] ?? null; ?>
      <tr><td><b><?= e(Employment::code($e)) ?></b></td><td><?= e((string) ($e['name'] ?? '')) ?><?= strtolower((string) ($e['status'] ?? '')) === 'exited' ? ' <span class="badge badge-gray">left</span>' : '' ?></td>
        <td><?= e(orgValueOf($store, $e, 'designation')) ?></td><td><?= e(orgValueOf($store, $e, 'branch')) ?></td>
        <td><?= (string) ($e['pan'] ?? '') === '' ? '<span style="color:#b91c1c">missing</span>' : e((string) $e['pan']) ?></td>
        <td class="f16-n"><?= tdsMoney($r['gross']) ?></td><td class="f16-n"><?= tdsMoney($r['tds']) ?></td>
        <td><?php if ($pa !== null): ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-file"><input type="hidden" name="fy" value="<?= e($fy) ?>"><input type="hidden" name="id" value="<?= e((string) $e['id']) ?>"><button class="btn btn-sm" type="submit" title="<?= e((string) $pa['file_name']) ?>"><i class="fas fa-file-pdf"></i> <?= e(SmartPRS\Surface\Dates::dmy(substr((string) $pa['created_at'], 0, 10))) ?></button></form><?php else: ?><span class="badge badge-gray">not uploaded</span><?php endif; ?>
<?php if ($manage): ?>
          <form method="post" enctype="multipart/form-data" style="display:inline" title="Upload this employee's Part A (any file name)"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="form16-upload"><input type="hidden" name="fy" value="<?= e($fy) ?>"><input type="hidden" name="id" value="<?= e((string) $e['id']) ?>"><label class="btn btn-sm" style="margin:0;cursor:pointer"><i class="fas fa-upload"></i><input type="file" name="files[]" accept=".pdf" hidden onchange="this.form.submit()"></label></form>
<?php endif; ?></td>
        <td><?= $r['issues'] === [] ? '<span class="badge badge-green">Ready</span>' : '<span class="note" style="color:#b45309">' . e(implode(' · ', $r['issues'])) . '</span>' ?></td>
        <td><a class="btn btn-sm" target="_blank" href="?r=form16&amp;fy=<?= e($fy) ?>&amp;print=partb&amp;id=<?= e((string) $e['id']) ?>"><i class="fas fa-file-invoice"></i> Part B</a></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</div>
