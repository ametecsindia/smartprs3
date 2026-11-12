<?php declare(strict_types=1);
/**
 * EXPORT BAR for Asset Stock / Asset Issue & Returns: pick what to export,
 * then CSV or PDF. Posts to surface/actions/asset-export.php, carrying the
 * screen's own filters ($exportKeep) so the file matches what is on screen.
 *
 * @var array<string, string> $exportReports report key => label
 * @var array<string, string> $exportKeep hidden fields (company, branch, emp)
 */
?>
<form method="post" class="asset-export" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
  <input type="hidden" name="action" value="asset-export">
<?php foreach ($exportKeep as $k => $v): if ((string) $v === '') { continue; } ?>
  <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
<?php endforeach; ?>
  <label for="ax_<?= e((string) ($route ?? 'x')) ?>" style="margin:0;font-weight:600;white-space:nowrap"><i class="fas fa-download"></i> Export</label>
  <select id="ax_<?= e((string) ($route ?? 'x')) ?>" name="report" style="width:auto;min-width:200px">
<?php foreach ($exportReports as $k => $label): ?>    <option value="<?= e($k) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
  </select>
<?php if (isset($exportReports['returned'])): // RETURNED ITEMS: the period they came back in (owner, 22 Oct 2026) ?>
  <span class="ax-range" style="display:none;gap:6px;align-items:center">
    <label style="margin:0;white-space:nowrap">From</label><input type="date" name="from" value="<?= e(date('Y-m-01')) ?>" style="width:auto">
    <label style="margin:0;white-space:nowrap">To</label><input type="date" name="to" value="<?= e(date('Y-m-d')) ?>" style="width:auto">
  </span>
  <script>(function (s) { var r = s.form.querySelector('.ax-range'); var f = function () { r.style.display = s.value === 'returned' ? 'inline-flex' : 'none'; }; s.addEventListener('change', f); f(); })(document.currentScript.parentNode.querySelector('select[name=report]'));</script>
<?php endif; ?>
  <button type="submit" name="format" value="csv" class="btn btn-outline" style="border-color:#15803d;color:#15803d;font-weight:700;white-space:nowrap"><i class="fas fa-file-csv"></i> CSV</button>
  <button type="submit" name="format" value="pdf" class="btn btn-outline" style="border-color:#b91c1c;color:#b91c1c;font-weight:700;white-space:nowrap"><i class="fas fa-file-pdf"></i> PDF</button>
</form>
