<?php declare(strict_types=1);
/**
 * EDIT A YEAR'S INCOME-TAX RULES (owner, 15 Oct: "leave it for the company admins") — included by
 * screens/tds-rules.php with $trFy and $Y (the year as it stands: the organisation's own edits, the
 * shipped file, or the previous year carried forward). Saved by actions/tax-rules-save.php as a new
 * Customisation version; every earlier version is kept.
 */
use SmartPRS\Surface\IncomeTax;

$v = static fn (mixed $x): string => e($x === null ? '' : (string) $x);
$slabRows = static function (string $name, array $slabs) use ($v): string {
    $h = '<table class="tds-sheet"><tr class="tds-strong"><td>Up to (₹) — blank on the last row = "and above"</td><td class="tds-n">Rate %</td></tr>';
    $rows = array_values($slabs);
    for ($i = 0; $i < count($rows) + 2; $i++) {
        $s = $rows[$i] ?? ['upto' => '', 'pct' => ''];
        $h .= '<tr><td><input name="' . $name . '[' . $i . '][upto]" value="' . $v($s['upto']) . '" inputmode="numeric" style="width:100%"></td><td><input name="' . $name . '[' . $i . '][pct]" value="' . $v($s['pct']) . '" inputmode="decimal" style="width:90px;text-align:right"></td></tr>';
    }

    return $h . '</table>';
};
$surRows = static function (string $name, array $rows) use ($v): string {
    $h = '<table class="tds-sheet"><tr class="tds-strong"><td>Taxable income above (₹)</td><td class="tds-n">Surcharge %</td></tr>';
    $rows = array_values($rows);
    for ($i = 0; $i < count($rows) + 1; $i++) {
        $s = $rows[$i] ?? ['above' => '', 'pct' => ''];
        $h .= '<tr><td><input name="' . $name . '[' . $i . '][above]" value="' . $v($s['above']) . '" inputmode="numeric" style="width:100%"></td><td><input name="' . $name . '[' . $i . '][pct]" value="' . $v($s['pct']) . '" inputmode="decimal" style="width:90px;text-align:right"></td></tr>';
    }

    return $h . '</table>';
};
$n = (array) $Y['regimes']['new'];
$o = (array) $Y['regimes']['old'];
$capKeys = ['cap' => 'Limit', 'cap_if_senior_self' => 'Limit if 60+', 'cap_if_senior_parents' => 'Limit if parent 60+'];
?>
<div class="page-header">
  <div><h2>Edit Income Tax Rules &middot; FY <?= e($trFy) ?></h2><p>Change what the Finance Act changed, then save. From then on payroll TDS, declarations, the Rules page and Form 16 for <?= e($trFy) ?> use these figures. Every saved version is kept.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=tds-rules&amp;fy=<?= e($trFy) ?>"><i class="fas fa-xmark"></i> Cancel</a></div>
</div>
<?php if (isset($Y['carried_forward_from'])): ?>
<div class="card" style="border-left:4px solid #b45309">The form is filled with the <b><?= e((string) $Y['carried_forward_from']) ?></b> rules. Change only what the new Finance Act changed and save — that creates the <?= e($trFy) ?> rules.</div>
<?php endif; ?>
<form method="post" class="card">
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tax-rules-save"><input type="hidden" name="fy" value="<?= e($trFy) ?>">
  <div class="tds-sec"><h4>General</h4><div class="tds-body tds-grid">
    <div><label>Health &amp; education cess %</label><input name="cess_pct" value="<?= $v($Y['cess_pct'] ?? '') ?>" inputmode="decimal" required></div>
    <div><label>Default regime</label><select name="default_regime"><option value="new"<?= ($Y['default_regime'] ?? 'new') === 'new' ? ' selected' : '' ?>>New regime</option><option value="old"<?= ($Y['default_regime'] ?? '') === 'old' ? ' selected' : '' ?>>Old regime</option></select></div>
    <div><label>Round taxable income to (₹)</label><input name="round_taxable_to" value="<?= $v($Y['round_taxable_to'] ?? 10) ?>" inputmode="numeric"></div>
  </div></div>

  <div class="tds-cmp" style="margin-bottom:14px">
    <div class="tds-col on"><div class="tds-col-h"><b>New regime</b></div><div style="padding:12px 14px">
      <div class="tds-grid" style="margin-bottom:10px">
        <div><label>Standard deduction (₹)</label><input name="new[standard_deduction]" value="<?= $v($n['standard_deduction'] ?? '') ?>" inputmode="numeric" required></div>
        <div><label>Rebate if taxable income up to (₹)</label><input name="new[rebate_upto]" value="<?= $v($n['rebate']['income_upto'] ?? '') ?>" inputmode="numeric"></div>
        <div><label>Maximum rebate (₹)</label><input name="new[rebate_max]" value="<?= $v($n['rebate']['max'] ?? '') ?>" inputmode="numeric"></div>
        <div><label>&nbsp;</label><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="new[marginal_relief]" value="1"<?= ($n['rebate']['marginal_relief'] ?? false) ? ' checked' : '' ?>> Marginal relief above the rebate limit</label></div>
      </div>
      <b style="font-size:13px">Slabs</b><?= $slabRows('new[slabs]', (array) ($n['slabs'] ?? [])) ?>
      <b style="font-size:13px">Surcharge</b><?= $surRows('new[surcharge]', (array) ($n['surcharge'] ?? [])) ?>
    </div></div>
    <div class="tds-col"><div class="tds-col-h"><b>Old regime</b></div><div style="padding:12px 14px">
      <div class="tds-grid" style="margin-bottom:10px">
        <div><label>Standard deduction (₹)</label><input name="old[standard_deduction]" value="<?= $v($o['standard_deduction'] ?? '') ?>" inputmode="numeric" required></div>
        <div><label>Rebate if taxable income up to (₹)</label><input name="old[rebate_upto]" value="<?= $v($o['rebate']['income_upto'] ?? '') ?>" inputmode="numeric"></div>
        <div><label>Maximum rebate (₹)</label><input name="old[rebate_max]" value="<?= $v($o['rebate']['max'] ?? '') ?>" inputmode="numeric"></div>
      </div>
<?php foreach (['below_60' => 'Below 60 years', '60_to_79' => '60 to 79 years', '80_plus' => '80 years and above'] as $k => $l): ?>
      <b style="font-size:13px">Slabs — <?= e($l) ?></b><?= $slabRows('old[slabs][' . $k . ']', (array) ($o['slabs_by_age'][$k] ?? [])) ?>
<?php endforeach; ?>
      <b style="font-size:13px">Surcharge</b><?= $surRows('old[surcharge]', (array) ($o['surcharge'] ?? [])) ?>
    </div></div>
  </div>

  <div class="tds-sec"><h4>Deduction limits (old regime; employer NPS applies to both)</h4><div class="tds-body tds-grid">
<?php foreach ((array) ($Y['groups'] ?? []) as $g): if (!isset($g['cap']) && !isset($g['cap_pct_of_salary'])) { continue; } ?>
    <div><label><?= e((string) $g['label']) ?></label><?php if (isset($g['cap'])): ?><input name="groupcap[<?= e((string) $g['key']) ?>]" value="<?= $v($g['cap']) ?>" inputmode="numeric"><?php else: ?><input name="grouppct[<?= e((string) $g['key']) ?>]" value="<?= $v($g['cap_pct_of_salary']['new'] ?? '') ?>" inputmode="decimal"><span class="note cap">% of Basic + DA</span><?php endif; ?></div>
<?php endforeach; ?>
<?php foreach ((array) ($Y['items'] ?? []) as $it): foreach ($capKeys as $ck => $cl): if (!isset($it[$ck])) { continue; } ?>
    <div><label><?= e((string) $it['label']) ?> — <?= e($cl) ?> (₹)</label><input name="itemcap[<?= e((string) $it['code']) ?>][<?= e($ck) ?>]" value="<?= $v($it[$ck]) ?>" inputmode="numeric"></div>
<?php endforeach; endforeach; ?>
  </div></div>

  <div class="tds-sec"><h4>HRA exemption (old regime)</h4><div class="tds-body tds-grid">
    <div><label>Metro cities — % of salary</label><input name="hra[metro_pct]" value="<?= $v($Y['hra']['metro_pct'] ?? '') ?>" inputmode="decimal"></div>
    <div><label>Other cities — % of salary</label><input name="hra[other_pct]" value="<?= $v($Y['hra']['other_pct'] ?? '') ?>" inputmode="decimal"></div>
    <div><label>Rent less % of salary</label><input name="hra[rent_less_pct_of_salary]" value="<?= $v($Y['hra']['rent_less_pct_of_salary'] ?? '') ?>" inputmode="decimal"></div>
    <div style="grid-column:1/-1"><label>Metro cities (comma-separated)</label><input name="hra[metro_cities]" value="<?= $v(implode(', ', (array) ($Y['hra']['metro_cities'] ?? []))) ?>"></div>
  </div></div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save rules for <?= e($trFy) ?></button>
    <span class="note">Amounts in rupees. The slabs are checked before saving (rising limits, rates 0–100, last row open-ended).</span>
  </div>
</form>
<?php if (($Y['source'] ?? '') === 'organisation'): ?>
<form method="post" class="card" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="tax-rules-save"><input type="hidden" name="fy" value="<?= e($trFy) ?>"><input type="hidden" name="revert" value="1">
  <span class="note">These rules were edited by your organisation.</span><button class="btn btn-outline" type="submit"><i class="fas fa-rotate-left"></i> Go back to the shipped rules</button></form>
<?php endif; ?>
