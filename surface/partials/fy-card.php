<?php declare(strict_types=1);
/**
 * FINANCIAL YEAR, at the top of Organization Configuration → Companies (owner, 15 Oct: "all the
 * customisation and settings are in that tab"). The one setting the whole application follows:
 * when the year starts, and which year is in force — Automatic (moves on the first day of each year)
 * or a year an administrator fixes. Saved by the existing `financial-year-save` action.
 */
$fyDecl = json_decode((string) file_get_contents(__DIR__ . '/../../config/financial-year.json'), true) ?: [];
$fySav = (array) ($customisation->live()[(string) ($fyDecl['customisation_key'] ?? 'financial-year')] ?? []);
$fyStartM = financialYearStart($customisation);
$fyAutoY = (int) date('n') >= $fyStartM ? (int) date('Y') : (int) date('Y') - 1;
$fyMode = ($fySav['mode'] ?? 'auto') === 'fixed' ? 'fixed' : 'auto';
$fyNowY = activeFinancialYearStart($customisation);
$fyLbl = static fn (int $y): string => $fyStartM === 1 ? (string) $y : $y . '-' . sprintf('%02d', ($y + 1) % 100);
$fyMonthsN = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>
  <div class="card" style="border-left:4px solid var(--accent)">
    <div class="card-header" style="padding:0 0 10px;border:0"><div><h3 style="font-size:15px;font-weight:700">Financial Year</h3>
      <p class="note" style="margin:0">Applies application-wide: payroll TDS, TDS declarations and proofs, Income Tax Rules, Form 16, statutory screens and every report's "Financial year" period.</p></div>
      <div class="actions"><span class="badge badge-green">In force: FY <?= e($fyLbl($fyNowY)) ?></span></div></div>
    <form method="post" class="row" style="align-items:flex-end;gap:16px;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="financial-year-save"><input type="hidden" name="back" value="setup-companies">
      <div><label for="fy_start">Year starts in</label><select id="fy_start" name="start_month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $m === $fyStartM ? ' selected' : '' ?>><?= $fyMonthsN[$m] ?></option><?php endfor; ?></select></div>
      <div><label for="fy_mode">Financial year in force</label><select id="fy_mode" name="mode" onchange="document.getElementById('fy_active_w').style.display=this.value==='fixed'?'':'none'">
        <option value="auto"<?= $fyMode === 'auto' ? ' selected' : '' ?>>Automatic — changes on the first day of each year (now FY <?= e($fyLbl($fyAutoY)) ?>)</option>
        <option value="fixed"<?= $fyMode === 'fixed' ? ' selected' : '' ?>>Fixed — use the year chosen here</option></select></div>
      <div id="fy_active_w"<?= $fyMode === 'fixed' ? '' : ' style="display:none"' ?>><label for="fy_active">Year</label><select id="fy_active" name="active"><?php for ($y = $fyAutoY + 1; $y >= $fyAutoY - 5; $y--): ?><option value="<?= $y ?>"<?= $y === $fyNowY ? ' selected' : '' ?>>FY <?= e($fyLbl($y)) ?></option><?php endfor; ?></select></div>
      <div><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk"></i> Save &amp; apply everywhere</button></div>
      <div><a class="note" href="?r=tds-rules">Income-tax rules for each year &rarr;</a></div>
    </form>
  </div>
