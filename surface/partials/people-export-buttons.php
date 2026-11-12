<?php declare(strict_types=1);
/**
 * THE EXCEL + CSV BUTTONS of one Employees-tab list. Include with
 * $peopleExport = ['screen' => '<key in config/people-exports.json>', 'keep' => [field => value, ...]]
 * — `keep` carries the screen's current filter so the file is the table shown.
 */
$peopleExportHidden = '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="people-export">'
    . '<input type="hidden" name="screen" value="' . e((string) $peopleExport['screen']) . '">' . keepHidden((array) ($peopleExport['keep'] ?? []));
?>
<form method="post" style="display:inline"><?= $peopleExportHidden ?><input type="hidden" name="as" value="xlsx"><button class="btn btn-outline btn-sm" type="submit" title="Excel of exactly this table"><i class="fas fa-file-excel"></i> Excel</button></form>
<form method="post" style="display:inline"><?= $peopleExportHidden ?><input type="hidden" name="as" value="csv"><button class="btn btn-outline btn-sm" type="submit" title="CSV of exactly this table"><i class="fas fa-file-csv"></i> CSV</button></form>
