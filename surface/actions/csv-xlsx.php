<?php
/**
 * A TABLE BUILT IN THE BROWSER → EXCEL (owner, 23 Oct 2026). A screen that exports what it is already
 * showing posts that CSV here; it goes back out as a CSV attachment and Dates::filter() hands it over as
 * a formatted workbook, the same as every other download. Only the signed-in viewer's own page data
 * passes through — nothing is read from the store.
 */
declare(strict_types=1);

$cxName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', post('name')) ?: 'export.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . (str_ends_with(strtolower($cxName), '.csv') ? $cxName : $cxName . '.csv') . '"');
echo (string) ($_POST['csv'] ?? '');
exit;
