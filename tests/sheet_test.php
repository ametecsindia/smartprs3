<?php

declare(strict_types=1);

use SmartPRS\Surface\Sheet;

group('sheet');

$rows = [['Code', 'Name', 'Branch', 'Joined'], ['emp_code', 'name', 'branch', 'doj']];
$bytes = Sheet::xlsx($rows, [2 => ['Hyderabad', 'Pune & Co']], 2);
ok(str_starts_with($bytes, "PK\x03\x04"), 'a workbook is a zip');

$tmp = tempnam(sys_get_temp_dir(), 'x');
file_put_contents($tmp, $bytes);
$zip = new ZipArchive();
same(true, $zip->open($tmp), 'and it opens');
$s1 = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$s2 = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
$zip->close();
unlink($tmp);
ok(str_contains($s1, 'sqref="C3:C1002"'), 'the dropdown covers the data rows under the headers of that column');
ok(str_contains($s1, 'Lists!$A$1:$A$2'), 'and points at the Lists sheet');
ok(str_contains($s2, 'Pune &amp; Co'), 'list values are XML-escaped');

$csv = Sheet::toCsv($bytes, 'sample.xlsx');
same("Code,Name,Branch,Joined\nemp_code,name,branch,doj\n", $csv, 'a workbook reads back as the CSV the importer knows');
same("a,b\n", Sheet::toCsv("a,b\n", 'x.csv'), 'csv passes through');
same("a,b\n", Sheet::toCsv("a,b\n", 'x.xls'), 'an "xls" that is really text passes through');

try {
    Sheet::toCsv("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1junk", 'old.xls');
    ok(false, 'binary xls is refused');
} catch (RuntimeException $e) {
    ok(str_contains($e->getMessage(), '.xlsx'), 'binary xls is refused with the way out');
}

// Excel stores a picked date as a serial with a date style; the reader turns it back.
$tmp = tempnam(sys_get_temp_dir(), 'x');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
$zip->addFromString('xl/sharedStrings.xml', '<sst ' . $ns . '><si><t>doj</t></si><si><r><t>ri</t></r><r><t>ch</t></r></si></sst>');
$zip->addFromString('xl/styles.xml', '<styleSheet ' . $ns . '><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet ' . $ns . '><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
    . '<row r="2"><c r="A2" s="1"><v>46266</v></c><c r="B2"><v>7</v></c></row></sheetData></worksheet>');
$zip->close();
same("doj,,rich\n2026-09-01,7\n", Sheet::toCsv((string) file_get_contents($tmp), 'd.xlsx'), 'shared strings, rich runs, gaps and date serials all read');
unlink($tmp);
