<?php declare(strict_types=1);
/**
 * INCOME TAX RULES (owner, 15 Oct) — the year's slabs, deductions and the step-by-step method,
 * printed straight from config/income-tax.json (the same file payroll reads, so this page cannot
 * disagree with the payslip), a quick calculator, and — for HR — every employee's tax for the
 * year worked out by the SAME functions the payroll close calls (tdsPackage → figures → sheets
 * → IncomeTax::monthly).
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\IncomeTax;

include __DIR__ . '/../partials/tds-lib.php';
$years = $taxDeclarations->yearList();
$trCanEdit = isAdministrator() || $may('payroll.structure.publish');
$trFy = in_array((string) ($_GET['fy'] ?? ''), $years, true) ? (string) $_GET['fy'] : $taxDeclarations->fy();
$Y = $taxDeclarations->year($trFy);
echo tdsCss();
$rs = static fn (string $v): string => e(Money::ofMinor(IncomeTax::p($v))->format());

if ($Y === []): ?>
  <div class="page-header"><div><h2>Income Tax Rules</h2></div></div>
  <div class="card"><div class="problem" style="margin:0">No income-tax rules exist for <?= e($trFy) ?> or any earlier year.</div></div>
<?php return; endif;

$fyStart = sprintf('01-%02d-%s', financialYearStartGlobal(), substr($trFy, 0, 4));
$fyEnd = date('d-m-Y', (int) strtotime(substr($trFy, 0, 4) . sprintf('-%02d-01', financialYearStartGlobal()) . ' +1 year -1 day'));
$new = (array) $Y['regimes']['new'];
$old = (array) $Y['regimes']['old'];
if (($_GET['edit'] ?? '') === '1' && $trCanEdit) { include __DIR__ . '/../partials/tax-rules-edit.php'; return; }

/** Slab table: range, rate, tax in the band, tax at the top of the band. */
$slabTable = static function (array $slabs): string {
    $h = '<table class="tds-sheet"><tr class="tds-strong"><td>Taxable income</td><td class="tds-n">Rate</td><td class="tds-n">Tax on this slab</td><td class="tds-n">Total tax at top of slab</td></tr>';
    $floor = 0;
    foreach ($slabs as $s) {
        $top = $s['upto'] === null ? null : IncomeTax::p($s['upto']);
        $range = $top === null ? 'Above ' . Money::ofMinor($floor)->format() : ($floor === 0 ? 'Up to ' : Money::ofMinor($floor)->format() . ' – ') . Money::ofMinor($top)->format();
        $h .= '<tr><td>' . e($range) . '</td><td class="tds-n">' . ((string) $s['pct'] === '0' ? 'Nil' : e((string) $s['pct']) . '%') . '</td>'
            . '<td class="tds-n">' . ($top === null ? '—' : tdsMoney(IncomeTax::slabTax($top, [['upto' => null, 'pct' => $s['pct']]]) - IncomeTax::slabTax($floor, [['upto' => null, 'pct' => $s['pct']]]))) . '</td>'
            . '<td class="tds-n">' . ($top === null ? '—' : tdsMoney(IncomeTax::slabTax($top, $slabs))) . '</td></tr>';
        $floor = $top ?? $floor;
    }

    return $h . '</table>';
};
?>
<style>
  .tr-steps{margin:0;padding-left:20px;line-height:1.7;font-size:13.5px}
  .tr-steps b{color:var(--text1)}
  .tr-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}
  .tr-kv > div{border:1px solid var(--border);border-radius:10px;padding:10px 14px}
  .tr-kv b{display:block;font-size:17px;font-family:var(--mono)}
  .tr-ok{color:#16a34a;font-weight:600}.tr-bad{color:#dc2626;font-weight:600}
  @media print{.sidebar,.topbar,.no-print{display:none!important}}
</style>
<div class="page-header">
  <div><h2>Income Tax Rules &middot; FY <?= e($trFy) ?></h2><p><?= e((string) $Y['label']) ?> &middot; <?= e($fyStart) ?> to <?= e($fyEnd) ?>. These are the exact rules payroll uses to deduct TDS every month.</p></div>
  <div class="actions no-print" style="display:flex;gap:8px;flex-wrap:wrap">
<?php if (count($years) > 1): ?>
    <form method="get" style="display:inline"><input type="hidden" name="r" value="tds-rules"><select name="fy" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option<?= $y === $trFy ? ' selected' : '' ?>><?= e($y) ?></option><?php endforeach; ?></select></form>
<?php endif; ?>
<?php if ($trCanEdit): ?>
    <a class="btn btn-primary" href="?r=tds-rules&amp;edit=1&amp;fy=<?= e($trFy) ?>"><i class="fas fa-pen"></i> Edit rules</a>
<?php endif; ?>
    <a class="btn btn-outline" href="?r=tds"><i class="fas fa-arrow-left"></i> TDS</a>
    <button class="btn btn-outline" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
  </div>
</div>

<?php if (isset($Y['carried_forward_from'])): ?>
<div class="card" style="border-left:4px solid #b45309"><b>FY <?= e($trFy) ?> rules are carried forward from <?= e((string) $Y['carried_forward_from']) ?>.</b> Until this year's Finance Act is entered, the previous year's slabs, rebate and limits apply — which is the law when a Budget changes nothing.<?= $trCanEdit ? ' <a class="btn btn-sm btn-primary" href="?r=tds-rules&amp;edit=1&amp;fy=' . e($trFy) . '">Update the ' . e($trFy) . ' rules</a>' : ' Your administrator updates them here.' ?></div>
<?php endif; ?>
<div class="card">
  <h3 style="font-size:15px;margin:0 0 8px">How TDS on salary is worked out</h3>
  <ol class="tr-steps">
    <li><b>Year's gross salary</b> = salary already paid in closed payroll months this year + this month's full gross &times; the months left to March. (Employer PF/ESI that is part of CTC is not salary and is not taxed.)</li>
    <li><b>Less standard deduction</b> — <?= $rs((string) $new['standard_deduction']) ?> (new regime) or <?= $rs((string) $old['standard_deduction']) ?> (old regime). Old regime also allows HRA/LTA exemptions and professional tax.</li>
    <li><b>Less deductions</b> — old regime only: 80C (PF, LIC, PPF, ELSS…), 80D, home-loan interest and the rest below. The new regime allows only the employer's NPS contribution.</li>
    <li><b>Taxable income</b>, rounded to the nearest ₹<?= (int) ($Y['round_taxable_to'] ?? 10) ?>.</li>
    <li><b>Slab tax</b> — each slab's rate applies only to the part of income inside that slab (tables below).</li>
    <li><b>Less rebate (Section 156, old 87A)</b> — new regime: if taxable income is up to <?= $rs((string) $new['rebate']['income_upto']) ?>, tax up to <?= $rs((string) $new['rebate']['max']) ?> is waived, so tax is nil<?= ($new['rebate']['marginal_relief'] ?? false) ? '; just above the limit, tax never exceeds the income above it (marginal relief)' : '' ?>. Old regime: up to <?= $rs((string) $old['rebate']['income_upto']) ?>, max <?= $rs((string) $old['rebate']['max']) ?>.</li>
    <li><b>Add surcharge</b> (only above ₹50 lakh) and <b>health &amp; education cess <?= e((string) $Y['cess_pct']) ?>%</b> = <b>annual tax</b>, rounded to ₹10.</li>
    <li><b>Monthly TDS</b> = (annual tax − TDS already deducted this year) &divide; months left including this one, rounded up to the rupee. It re-computes every month, so a raise, a bonus or a declaration change corrects itself over the remaining months.</li>
  </ol>
  <p class="note" style="margin:8px 0 0"><b>Why not net salary?</b> Income tax is charged on taxable salary (gross less the allowed deductions above), not on take-home. Net pay is what is left <i>after</i> TDS, so it cannot be the base. Employee PF counts only under the old regime, inside the 80C limit. <?= e((string) $Y['regime_note']) ?></p>
</div>

<div class="tds-cmp" style="margin-bottom:16px">
  <div class="tds-col on"><div class="tds-col-h"><b><?= e((string) $new['label']) ?></b> <span class="badge badge-blue">default</span></div>
    <?= $slabTable((array) $new['slabs']) ?>
    <div class="note" style="padding:8px 14px">Standard deduction <?= $rs((string) $new['standard_deduction']) ?>. No 80C/80D/HRA. With the rebate, a salary up to <?= e(Money::ofMinor(IncomeTax::p($new['rebate']['income_upto']) + IncomeTax::p($new['standard_deduction']))->format()) ?> a year pays no tax.</div></div>
  <div class="tds-col"><div class="tds-col-h"><b><?= e((string) $old['label']) ?></b></div>
<?php foreach (['below_60' => 'Below 60 years', '60_to_79' => '60 to 79 years', '80_plus' => '80 years and above'] as $k => $l): ?>
    <div class="note" style="padding:8px 14px 0"><b><?= e($l) ?></b></div><?= $slabTable((array) $old['slabs_by_age'][$k]) ?>
<?php endforeach; ?>
    <div class="note" style="padding:8px 14px">Standard deduction <?= $rs((string) $old['standard_deduction']) ?>, professional tax deductible, HRA and LTA exemptions, all deductions below.</div></div>
</div>

<div class="card">
  <h3 style="font-size:15px;margin:0 0 8px">Surcharge &amp; cess</h3>
  <div class="tr-kv">
<?php foreach ((array) $new['surcharge'] as $s): ?><div><span class="note">Income above <?= $rs((string) $s['above']) ?></span><b><?= e((string) $s['pct']) ?>%</b></div><?php endforeach; ?>
<?php $lastOld = end($old['surcharge']); ?><div><span class="note">Old regime only, above <?= $rs((string) $lastOld['above']) ?></span><b><?= e((string) $lastOld['pct']) ?>%</b></div>
    <div><span class="note">Health &amp; education cess (on tax + surcharge)</span><b><?= e((string) $Y['cess_pct']) ?>%</b></div>
  </div>
</div>

<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Deductions and limits (old regime)</h3><p class="note" style="margin:0">Employees declare these on TDS Declaration and prove them on Investment Proofs.</p></div></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Section</th><th>What it covers</th><th class="tds-n">Limit</th></tr></thead><tbody>
<?php foreach ((array) $Y['groups'] as $g): if (in_array((string) $g['key'], ['INCOME', 'EXEMPT'], true)) { continue; }
    $its = array_values(array_filter((array) $Y['items'], static fn (array $i): bool => (string) $i['group'] === (string) $g['key']));
    $limit = isset($g['cap']) ? $rs((string) $g['cap']) : (isset($g['cap_pct_of_salary']) ? e((string) $g['cap_pct_of_salary']['new']) . '% of Basic + DA' : 'per item'); ?>
      <tr><td><b><?= e((string) $g['label']) ?></b><?= isset($g['note']) ? '<br><span class="note">' . e((string) $g['note']) . '</span>' : '' ?></td>
        <td class="note"><?php foreach ($its as $i): ?><?= e((string) $i['label']) ?><?= isset($i['cap']) && !isset($g['cap']) ? ' — up to ' . $rs((string) $i['cap']) : '' ?><br><?php endforeach; ?></td>
        <td class="tds-n"><?= $limit ?></td></tr>
<?php endforeach; ?>
      <tr><td><b>HRA exemption</b><br><span class="note"><?= e((string) $Y['hra']['note']) ?></span></td><td class="note">50% cities: <?= e(implode(', ', (array) $Y['hra']['metro_cities'])) ?></td><td class="tds-n">least of 3</td></tr>
    </tbody></table></div>
</div>

<?php
// ---- QUICK CALCULATOR -------------------------------------------------------------------------
$calc = IncomeTax::p((string) ($_GET['gross'] ?? ''));
?>
<div class="card no-print">
  <h3 style="font-size:15px;margin:0 0 8px">Try it: tax on an annual gross salary</h3>
  <form method="get" class="row" style="gap:10px;align-items:flex-end"><input type="hidden" name="r" value="tds-rules"><input type="hidden" name="fy" value="<?= e($trFy) ?>">
    <div><label for="tr_g">Annual gross salary (₹)</label><input id="tr_g" name="gross" inputmode="numeric" value="<?= $calc > 0 ? e((string) intdiv($calc, 100)) : '' ?>" placeholder="e.g. 1500000"></div>
    <button class="btn btn-primary" type="submit"><i class="fas fa-calculator"></i> Calculate</button></form>
<?php if ($calc > 0):
    $in = ['gross' => $calc, 'age' => 30, 'resident' => true, 'items' => []];
    $s = ['new' => IncomeTax::compute($Y, 'new', $in), 'old' => IncomeTax::compute($Y, 'old', $in), 'regime' => 'new',
        'figures' => ['tds_paid' => 0, 'months_left' => 12, 'closed' => 0, 'estimated' => 0], 'paid_before' => 0, 'basis' => 'no deductions', 'tds_off' => false];
    $s['monthly_tds'] = IncomeTax::monthly($s['new']['total'], 0, 12); ?>
  <div style="margin-top:12px"><?= tdsSheetHtml($s) ?></div>
  <p class="note">Old regime shown with no investments declared; declared deductions lower it.</p>
<?php endif; ?>
</div>

<?php
// ---- EVERY EMPLOYEE (HR) ----------------------------------------------------------------------
if (!$may('payroll.view')) { return; }
$trFilter = orgFilter();
$trData = tdsRows($trFy, $trFilter, '');
$trRuns = $taxDeclarations->runRows(); // once, not per employee
$trNow = date('Y-m');
$fyFrom = substr($trFy, 0, 4) . sprintf('-%02d', financialYearStartGlobal());
$fyTo = date('Y-m', (int) strtotime($fyFrom . '-01 +11 months'));
$trFrom = $trNow < $fyFrom ? $fyFrom : ($trNow > $fyTo ? date('Y-m', (int) strtotime($fyTo . '-01 +1 month')) : $trNow);
$tot = ['gross' => 0, 'tax' => 0, 'paid' => 0, 'monthly' => 0];
$bad = 0;
$out = [];
foreach ($trData['rows'] as $r) {
    $e = $r['e'];
    $pkg = tdsPackage($e);
    if ($pkg['why'] !== null) { $out[] = ['e' => $e, 'err' => $pkg['why']]; $bad++; continue; }
    $f = $taxDeclarations->figures($e, $trFy, $trFrom, $pkg['monthly'], $trRuns, \SmartPRS\Surface\TaxDeclarations::estimates($r['d']));
    $s = $taxDeclarations->sheets($e, $trFy, $f, $r['d'], date('Y-m-d'));
    $sh = $s[$s['regime']];
    $off = $taxDeclarations->tdsOff($e);
    $paid = $f['tds_paid'] + $s['paid_before'];
    $left = max(1, (int) $f['months_left']);
    $mon = $off ? 0 : IncomeTax::monthly($sh['total'], $paid, $left);
    // CHECK: what is deducted so far + what the months left will deduct must equal the year's tax
    // (the last month absorbs rounding, so ≤ ₹1 a month over is fine); deducted beyond the year's tax = excess.
    $state = $off ? 'off' : ($paid > $sh['total'] ? 'excess' : 'ok');
    $state === 'excess' && $bad++;
    $tot['gross'] += $f['gross']; $tot['tax'] += $off ? 0 : $sh['total']; $tot['paid'] += $paid; $tot['monthly'] += $mon;
    $out[] = ['e' => $e, 'd' => $r['d'], 'f' => $f, 'sh' => $sh, 'regime' => $s['regime'], 'paid' => $paid, 'mon' => $mon, 'left' => $left, 'state' => $state];
}
?>
<div class="card no-print"><?= tdsOrgButtons('tds-rules', $trFilter, $trData['scoped'], array_fill_keys(array_map(static fn (array $e): string => (string) $e['id'], $trData['scoped']), 1), '&amp;fy=' . e($trFy)) ?></div>
<div class="tr-kv" style="margin-bottom:16px">
  <div><span class="note">Employees</span><b><?= count($out) ?></b></div>
  <div><span class="note">Projected gross salary, FY</span><b><?= tdsMoney($tot['gross']) ?></b></div>
  <div><span class="note">Annual tax (all)</span><b><?= tdsMoney($tot['tax']) ?></b></div>
  <div><span class="note">TDS deducted so far</span><b><?= tdsMoney($tot['paid']) ?></b></div>
  <div><span class="note">TDS to deduct this month</span><b style="color:var(--accent)"><?= tdsMoney($tot['monthly']) ?></b></div>
  <div><span class="note">Need attention</span><b class="<?= $bad > 0 ? 'tr-bad' : 'tr-ok' ?>"><?= $bad ?></b></div>
</div>
<div class="card" style="padding:0">
  <div class="card-header"><div><h3 style="font-size:15px;font-weight:700">Every employee's tax for FY <?= e($trFy) ?></h3><p class="note" style="margin:0">Worked by the same code the payroll close uses. Open a row for the full slab-by-slab computation.</p></div><div class="actions"><span class="badge badge-gray"><?= count($out) ?> employee(s)</span></div></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Employee ID</th><th>Name</th><th>Regime</th><th class="tds-n">Gross salary (FY)</th><th class="tds-n">Taxable income</th><th class="tds-n">Annual tax</th><th class="tds-n">Deducted so far</th><th class="tds-n">Months left</th><th class="tds-n">Monthly TDS now</th><th>Check</th><th></th></tr></thead>
    <tbody>
<?php if ($out === []): ?><tr><td colspan="11" class="note">No employee matches these filters.</td></tr><?php endif; ?>
<?php foreach ($out as $o): $e = $o['e']; ?>
      <tr><td><b><?= e(Employment::code($e)) ?></b></td><td><?= e((string) ($e['name'] ?? '')) ?></td>
<?php if (isset($o['err'])): ?>
        <td colspan="8" class="tr-bad">Cannot compute: <?= e((string) $o['err']) ?></td><td><span class="badge badge-red">Error</span></td>
<?php else: ?>
        <td><?= e(ucfirst($o['regime'])) ?><?= $o['d'] === null ? ' <span class="note">(default)</span>' : '' ?></td>
        <td class="tds-n"><?= tdsMoney($o['f']['gross']) ?></td><td class="tds-n"><?= tdsMoney($o['sh']['taxable']) ?></td>
        <td class="tds-n"><b><?= tdsMoney($o['sh']['total']) ?></b></td><td class="tds-n"><?= tdsMoney($o['paid']) ?></td>
        <td class="tds-n"><?= (int) $o['left'] ?></td><td class="tds-n"><b><?= tdsMoney($o['mon']) ?></b></td>
        <td><?= match ($o['state']) { 'off' => '<span class="badge badge-gray">TDS off</span>', 'excess' => '<span class="badge badge-red" title="More TDS already deducted than the year\'s tax">Excess ' . tdsMoney($o['paid'] - $o['sh']['total']) . '</span>', default => '<span class="badge badge-green">On track</span>' } ?></td>
<?php endif; ?>
        <td><a class="btn btn-sm" href="?r=tds-declaration&amp;id=<?= e((string) $e['id']) ?>&amp;fy=<?= e($trFy) ?>"><i class="fas fa-eye"></i> Open</a></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="note" style="padding:0 14px 12px">Gross salary (FY) = closed payroll months + this month's full salary &times; months left (months before joining are not counted; months of this year not run in SmartPRS 3 are estimated at this month's salary). "On track" means deducted so far + monthly TDS &times; months left covers the annual tax exactly (to the rupee). "Excess" means more was deducted than the year's tax — monthly TDS is then nil for the rest of the year.</p>
</div>
