<?php

declare(strict_types=1);

// EVERY SAMPLE COMES BACK AS IT WENT OUT, AND EVERY DOWNLOAD READS AS OPENED (owner, 27 Oct 2026):
// dates in samples are dd-mm-yyyy and read back day-first whatever the PC's Excel locale; times and
// long numbers survive; a template downloaded as a formatted workbook still imports; wrapped rows are
// tall enough that nothing is cut off.

use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Sheet;

group('sample round trip');

$unzip = static function (string $bytes, string $part): string {
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = (string) $zip->getFromName($part);
    $zip->close();
    unlink($tmp);

    return $xml;
};

// A tiny workbook as Excel would save it, with chosen number formats per style.
$excel = static function (string $styles, string $rows): string {
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('xl/styles.xml', '<styleSheet ' . $ns . '>' . $styles . '</styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet ' . $ns . '><sheetData>' . $rows . '</sheetData></worksheet>');
    $zip->close();
    $b = (string) file_get_contents($tmp);
    unlink($tmp);

    return $b;
};

// 1. The sample: example dates read dd-mm-yyyy, date columns are TEXT checked as dd-mm-yyyy (no Excel
//    locale can swap day and month), every other column is text too (leading zeros, 18-digit accounts).
$s = Sheet::xlsx([['Code', 'Joined'], ['emp_code', 'doj'], ['EMP001', '2024-10-27'], ['EMP002', '2025-10-27']], [], 3, [], [], [1]);
$s1 = $unzip($s, 'xl/worksheets/sheet1.xml');
ok(str_contains($s1, '27-10-2024') && !str_contains($s1, '2024-10-27'), 'an example date in the header rows reads dd-mm-yyyy');
ok(str_contains($s1, '<t xml:space="preserve">27-10-2025</t>'), 'a pre-filled date in a date column is written as dd-mm-yyyy text');
ok(preg_match('/<col min="2" max="2"[^>]*style="5"/', $s1) === 1 && preg_match('/<col min="1" max="1"[^>]*style="5"/', $s1) === 1, 'every sample column is formatted as Text');
ok(str_contains($s1, 'type="custom"') && str_contains($s1, 'dd-mm-yyyy') && !str_contains($s1, 'type="date"'), 'the date column is checked as dd-mm-yyyy text, not an Excel date');
ok(str_contains($unzip($s, 'xl/styles.xml'), 'numFmtId="49"'), 'the Text format exists in the workbook');
same("Code,Joined\nemp_code,doj\nEMP001,2024-10-27\nEMP002,2025-10-27\n", Sheet::toCsv($s, 's.xlsx'), 'the sample reads back with ISO dates');

// 2. What a person types or pastes comes back right.
$styles = '<numFmts><numFmt numFmtId="164" formatCode="dd\-mm\-yyyy"/><numFmt numFmtId="165" formatCode="dd-mm-yyyy hh:mm"/></numFmts>'
    . '<cellXfs><xf numFmtId="0"/><xf numFmtId="14"/><xf numFmtId="20"/><xf numFmtId="22"/><xf numFmtId="164"/><xf numFmtId="165"/><xf numFmtId="49"/></cellXfs>';
$typed = $excel($styles, '<row r="1"><c r="A1" t="inlineStr"><is><t>07-10-2026</t></is></c>'
    . '<c r="B1" s="1"><v>46302</v></c><c r="C1" s="2"><v>0.3958333333333333</v></c><c r="D1" s="3"><v>46302.395833333336</v></c>'
    . '<c r="E1" s="4"><v>46302</v></c><c r="F1" s="5"><v>46302.75</v></c><c r="G1" t="inlineStr"><is><t>07-Oct-2026</t></is></c>'
    . '<c r="H1" t="inlineStr"><is><t>07-10-2026 18:05</t></is></c><c r="I1" s="6" t="inlineStr"><is><t>123456789012345678</t></is></c></row>');
same("2026-10-07,2026-10-07,09:30,\"2026-10-07 09:30\",2026-10-07,\"2026-10-07 18:00\",2026-10-07,\"2026-10-07 18:05\",123456789012345678\n",
    Sheet::toCsv($typed, 't.xlsx'), 'typed text dates, Excel dates, times and date-times all read day-first; an 18-digit account keeps every digit');
same('2026-10-07', Dates::toIso('7 October 2026'), 'a month written out is read');
same('2026-09-07', Dates::toIso('07-Sept-2026'), 'Sept is September');
same(null, Dates::toIso('07-Foo-2026'), 'a word that is not a month is refused');
same("a,2026-10-07 09:05,\"x, 07-10-2026\"\n", Dates::csvToIso("a,07-10-2026 09:05,\"x, 07-10-2026\"\n"), 'a typed date-time becomes ISO; a date inside free text is left alone');

// 3. A CSV template handed out as a workbook keeps its header on row 1, so it imports as it is.
$tpl = Sheet::fromCsv("emp_code,date,punch_time\nEMP100,2026-06-05,09:15\n", 'Attendance Punch Template', true);
same("emp_code,date,punch_time\nEMP100,2026-06-05,09:15\n", Sheet::toCsv($tpl, 'tpl.xlsx'), 'a sample keeps its header on the first line and its values as typed');
ok(preg_match('/<col [^>]*style="12"/', $unzip($tpl, 'xl/worksheets/sheet1.xml')) === 1, 'and its columns are Text, so a typed 09:30 or 00123 stays as typed');

// 4. A formatted report brought back (title and subtitle above the header) still reads from its header.
$rep = Sheet::report('Candidates', 'Generated today', ['Name', 'Joined'], [['Asha', '2026-10-05']]);
same("Name,Joined\nAsha,2026-10-05\n", Sheet::toCsv($rep, 'r.xlsx'), 'a report workbook reads back from its header row');

// 5. Nothing cut off: a long cell gets a tall enough row, the header too.
$long = str_repeat('Long remark text ', 12);
$rx = $unzip(Sheet::report('T', 'S', ['Name', str_repeat('A very long column heading ', 5)], [['Asha', $long], ['Ravi', 'short']]), 'xl/worksheets/sheet1.xml');
ok(preg_match('/<row r="5" ht="(\d+(\.\d+)?)" customHeight="1">/', $rx, $m) === 1 && (float) $m[1] >= 4 * 13, 'a row whose text wraps is made tall enough for every line');
ok(str_contains($rx, '<row r="6">'), 'a one-line row keeps the normal height');
ok(preg_match('/<row r="4" ht="(\d+(\.\d+)?)"/', $rx, $m) === 1 && (float) $m[1] >= 3 * 13, 'a long heading gets a header row tall enough for it');
$sx = $unzip(Sheet::xlsx([['Name', 'Remarks'], ['Asha', $long]], [], 1), 'xl/worksheets/sheet1.xml');
ok(preg_match('/<row r="2" ht="(\d+(\.\d+)?)" customHeight="1">/', $sx, $m) === 1 && (float) $m[1] >= 4 * 15, 'the same on plain workbooks and samples');

// 6. Exports: dates in a converted CSV are real dates shown dd-mm-yyyy; ISO in text reads dd-mm-yyyy.
$fx = $unzip(Sheet::fromCsv("Name,Joined,Note\nAsha,2026-10-05,since 2026-10-05\n", 'Export'), 'xl/worksheets/sheet1.xml');
ok(preg_match('/<c r="B5" s="9"><v>46300<\/v><\/c>/', $fx) === 1, 'an exported date is a real Excel date');
ok(str_contains($fx, 'since 05-10-2026'), 'a date inside text reads dd-mm-yyyy');

// 7. THE LAYOUT (owner, 27 Oct: "format it properly"): one navy heading row, grey code/type rows, amber
//    examples with every cell filled (no white holes), header frozen, a real tab name; a legend is one line.
$lx = Sheet::xlsx([['CODE *', 'NAME *', 'DOB (DD-MM-YYYY)'], ['emp_code', 'name', 'dob'], ['Alpha Numeric', 'Text', 'Date'],
    ['EMP001', '', '1990-08-15'], ['EMP002', 'Sunitha', '']], [], 5, [], [], [2], [], [], [], [], [3 => 'example', 4 => 'example'], 'Employees');
$l1 = $unzip($lx, 'xl/worksheets/sheet1.xml');
ok(str_contains($l1, '<c r="A1" t="inlineStr" s="2">') && str_contains($l1, '<c r="A2" t="inlineStr" s="6">') && str_contains($l1, '<c r="A3" t="inlineStr" s="6">'), 'headings navy, codes and types grey');
ok(str_contains($l1, '<c r="B4" s="7"/>') && str_contains($l1, '<c r="C5" s="7"/>'), 'an empty example cell is still shaded — no holes in the band');
ok(str_contains($l1, '15-08-1990'), 'the example date of birth reads dd-mm-yyyy');
ok(str_contains($l1, 'ySplit="5" topLeftCell="A6"'), 'the five header rows are frozen');
ok(str_contains($unzip($lx, 'xl/workbook.xml'), '<sheet name="Employees"'), 'the tab is named');
same("\"CODE *\",\"NAME *\",\"DOB (DD-MM-YYYY)\"\nemp_code,name,dob\n\"Alpha Numeric\",Text,Date\nEMP001,,1990-08-15\nEMP002,Sunitha\n", Sheet::toCsv($lx, 'l.xlsx'), 'and it still reads back exactly');
$ex = Sheet::xlsx([['Audit Log'], ['All branches'], [], ['When', 'Who'], ['2026-10-27', 'admin']], [], 4);
$e1 = $unzip($ex, 'xl/worksheets/sheet1.xml');
ok(str_contains($e1, '<c r="A1" t="inlineStr" s="8">') && str_contains($e1, '<autoFilter ref="A4:B5"/>'), 'an export: large title, filterable heading row');
ok(str_contains($unzip($ex, 'xl/workbook.xml'), '<sheet name="Audit Log"'), 'an export tab is named after its title, not "Employees"');
same("When,Who\n2026-10-27,admin\n", Sheet::toCsv($ex, 'e.xlsx'), 'an export brought back reads from its heading row');
$ro = $unzip(Sheet::xlsx([['Shift codes:', 'GS — General | WO — Weekly off'], ['Day', 'Sun'], ['Emp ID', '01-11-2026'], ['A1', 'GS']], [], 3, [], [], [], [], [], [], [], [0 => 'note', 1 => 'meta', 2 => 'head']), 'xl/worksheets/sheet1.xml');
ok(str_contains($ro, '<mergeCell ref="A1:B1"/>') && str_contains($ro, 'Shift codes:  GS — General | WO — Weekly off'), 'a legend is one line across the sheet');

// 8. A DROPDOWN CELL IS CHECKED AGAINST ITS LIST BEFORE ITS DATA TYPE (28 Oct): Work Schedule is typed
//    Numeric but its sample dropdown offers names ("General") — the import refused the sample's own value.
$lkStore = (new SmartPRS\Surface\Store(sys_get_temp_dir() . '/lk-' . bin2hex(random_bytes(3))))->forTenant('1');
$lk = new SmartPRS\Surface\EmployeeImport($lkStore, new SmartPRS\Surface\EmployeeFields($lkStore, new SmartPRS\Surface\Customisation($lkStore), dirname(__DIR__) . '/config/employee-fields.json'),
    new SmartPRS\Surface\EmployeeExtras($lkStore), sys_get_temp_dir(), ['schedules' => ['3' => 'General', '4' => 'Night Shift']]);
$sch = ['label' => 'Work Schedule', 'type' => 'Numeric', 'control' => 'lookup', 'lookup' => 'schedules'];
same(null, $lk->problemWith($sch, 'General'), 'a dropdown name in a Numeric-typed lookup column is accepted');
same(null, $lk->problemWith($sch, '3'), 'and so is its stored id');
ok(str_contains((string) $lk->problemWith($sch, 'Morning'), 'not one of the schedules'), 'a name not on the list is still refused');
