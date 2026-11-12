<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\EmployeeExtras;
use SmartPRS\Surface\EmployeeFields;
use SmartPRS\Surface\EmployeeImport;
use SmartPRS\Surface\Store;

group('employee import');

$dir = sys_get_temp_dir() . '/prs3-imp-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Existing', 'doj' => '2020-01-01']);
$fields = new EmployeeFields($store, new Customisation($store), dirname(__DIR__) . '/config/employee-fields.json');
$import = new EmployeeImport($store, $fields, new EmployeeExtras($store), $dir . '/spool');

// THE BRANCH AND REPORTING MANAGER COLUMNS ARE HERE BECAUSE THEY HAVE TO BE
// (owner, 23 Sep): the register marks both required, so a row creating an
// employee without them is refused — see employee_required_fields_test.php,
// which is where that rule is pinned. Every fixture below that expects a row to
// IMPORT therefore carries them; the rows that are meant to be refused are
// refused for their own reason, and carry them too so the count stays honest.
//
// the sample, as Employee fields generates it: labels, codes, types, allowed, example, then data
$csv = "Employee ID,Full Name,Date of Joining,Official Email,Annual CTC,Branch,Reporting Manager\n"
    . "emp_code,name,doj,email,ctc,branch_id,reporting_manager_id\n"
    . "Alpha Numeric,Text,Date,Email,Numeric,List,List\n"
    . ",,,,,,\n"
    . "EMP001,Example Person,2026-01-01,x@y.z,0,KONDAPUR,MGR-004\n"
    . "SP-002,Asha Rao,2026-09-01,asha@example.com,300000,KONDAPUR,MGR-004\n"   // 6 fine
    . "SP-003,Bad Date,31/02/2026,b@example.com,300000,KONDAPUR,MGR-004\n"      // 7 date refused (dd/mm/yyyy is accepted since 7 Oct; 31 Feb is not a date)
    . "SP-004,Bad Mail,2026-09-01,not-an-email,300000,KONDAPUR,MGR-004\n"       // 8 email refused
    . ",No Code,2026-09-01,n@example.com,300000,KONDAPUR,MGR-004\n"             // 9 no code
    . "SP-001,Existing Person,2026-09-02,e@example.com,999999,KONDAPUR,MGR-004\n" // 10 duplicate of an existing code
    . "SP-005,Twice,2026-09-01,t@example.com,1,KONDAPUR,MGR-004\n"
    . "sp-005,Twice Again,2026-09-01,t2@example.com,1,KONDAPUR,MGR-004\n";      // 12 same code twice in the file

$p = $import->parse($csv);
same(2, $p['header_line'], 'the CODE row is the header, not the label row above it');
same(7, count($p['rows']), 'the type, allowed and example rows are skipped; seven data rows');
$byLine = [];
foreach ($p['rows'] as $r) { $byLine[$r['line']] = $r; }
same('create', $byLine[6]['action'], 'a clean new row is created');
ok(str_contains($byLine[7]['problems'][0], 'not a date'), 'an impossible date is refused, not guessed');
ok(str_contains($byLine[8]['problems'][0], 'not an email'), 'an Email column is checked as one');
ok(str_contains($byLine[9]['problems'][0], 'No employee code'), 'a row without a code is refused');
same('skip', $byLine[10]['action'], 'an existing code is skipped in skip mode');
same('update', $import->parse($csv, 'update')['rows'][4]['action'], 'and updated in update mode');
ok(str_contains($byLine[12]['problems'][0], 'twice'), 'the same code twice in one file is refused on the second');
same(3, $p['ok'], 'three rows are good (one of them a skip)');
same(4, $p['errors'], 'four are refused');

throws(RuntimeException::class, static fn () => $import->parse("a,b,c\n1,2,3\n"), 'a file naming no field is refused');

// preview keeps the file; nothing is written
$pv = $import->preview($csv, 'staff.csv', 'skip', 'hr');
same(1, count($store->all('employees')), 'preview writes nothing');
same('previewed', $pv['job']['status'], 'the job is recorded as previewed');
same(1, count($import->history()), 'and appears in the history');

// commit — ALL OR NOTHING: a file with refused rows is not imported at all
$c = $import->commit($pv['token'], 'hr');
ok(!$c['ok'], 'a file with refused rows is not committed');
same(4, $c['errors'], 'four refused');
same(1, count($store->all('employees')), 'and nothing was written');
same('previewed', $import->history()[0]['status'], 'the job is still previewed');
ok(str_contains(EmployeeImport::errorCsv($import->history()[0]), 'not a date'), 'the error CSV names the problems by line');

// the clean rows alone import — headings guessed from other systems' names
$clean = "Employee Code,Employee Name,Date of Joining,Email ID,Annual CTC,Branch,Reporting Manager\n"
    . "SP-001,Existing,2020-01-01,a@example.com,100000,KONDAPUR,MGR-004\n"
    . "SP-002,New Person,2026-09-01,new@example.com,240000,KONDAPUR,MGR-004\n"
    . "SP-005,Another,2026-09-02,,180000,KONDAPUR,MGR-004\n";
$pvc = $import->preview($clean, 'clean.csv', 'skip', 'hr');
same([], $pvc['parse']['unknown'] ?? [], 'Employee Code / Employee Name / Date of Joining / Email ID / Annual CTC all matched');
$c = $import->commit($pvc['token'], 'hr');
ok($c['ok'], 'commit succeeds: ' . $c['why']);
same(2, $c['created'], 'two created (SP-002, SP-005)');
same(1, $c['skipped'], 'one skipped (SP-001)');
same(3, count($store->all('employees')), 'so three employments now');
same('Existing', $store->find('employees', '1')['name'], 'the existing one is untouched in skip mode');
ok(!$import->commit($pvc['token'], 'hr')['ok'], 'committing the same preview twice is refused');
same('imported', array_values(array_filter($import->history(), static fn (array $j): bool => (string) $j['token'] === $pvc['token']))[0]['status'], 'the job says imported');

// update mode changes the existing row
$pv2 = $import->preview("emp_code,name,ctc\nSP-001,Renamed Person,120000\n", 'u.csv', 'update', 'hr');
$c2 = $import->commit($pv2['token'], 'hr');
same(1, $c2['updated'], 'update mode updates');
same('Renamed Person', $store->find('employees', '1')['name'], 'with the cells given');
same('2020-01-01', $store->find('employees', '1')['doj'], 'and leaves the cells not given alone');

group('employee import: patterns from the register');
$csv2 = "EMPLOYEE ID,FULL NAME,MOBILE NUMBER,PAN NUMBER,AADHAAR NUMBER,IFSC CODE,BANK ACCOUNT NUMBER,REASON FOR EXIT,BRANCH,REPORTING MANAGER\n"
    . "emp_code,name,mobile,pan,aadhaar,ifsc,bank_acc,exit_reason,branch_id,reporting_manager_id\n"
    . "SP-101,Fine Person,9876543210,abcde1234f,234567890123,HDFC0001234,123456789012,N/A - Active,KONDAPUR,MGR-004\n"
    . "SP-102,Bad Mobile,12345,ABCDE1234F,234567890123,HDFC0001234,123456789012,Resignation,KONDAPUR,MGR-004\n"
    . "SP-103,Bad PAN,9876543210,ABCD1234F,234567890123,HDFC0001234,123456789012,,KONDAPUR,MGR-004\n"
    . "SP-104,Bad Aadhaar,9876543210,ABCDE1234F,012345678901,HDFC0001234,123456789012,,KONDAPUR,MGR-004\n"
    . "SP-105,Bad IFSC,9876543210,ABCDE1234F,234567890123,HDFC1234,12345,,KONDAPUR,MGR-004\n"
    . "SP-106,Bad Exit,9876543210,ABCDE1234F,234567890123,HDFC0001234,123456789012,Left,KONDAPUR,MGR-004\n";
$q = $import->parse($csv2);
same(1, $q['header_line'] === 2 ? 1 : 0, 'ALL-CAPS labels still find the code row');
same('ABCDE1234F', $q['rows'][0]['values']['pan'] ?? '', 'a PAN is upper-cased on the way in');
same([], $q['rows'][0]['problems'], 'a valid row has no problem');
ok(str_contains($q['rows'][1]['problems'][0], 'Mobile Number (column C)') && str_contains($q['rows'][1]['problems'][0], '10-digit'), 'a bad mobile names the field and the sheet column: ' . $q['rows'][1]['problems'][0]);
ok(str_contains($q['rows'][2]['problems'][0], 'PAN'), 'a bad PAN is refused');
ok(str_contains($q['rows'][3]['problems'][0], 'Aadhaar'), 'an Aadhaar starting with 0 is refused');
same(2, count($q['rows'][4]['problems']), 'a bad IFSC and a short account number are two problems on one row');
$exitField = null;
foreach ($fields->catalogue()['sections'] as $sec) { foreach ($sec['fields'] as $cf) { if ($cf['code'] === 'exit_reason') { $exitField = $cf; } } }
ok($exitField !== null && str_contains((string) $import->problemWith($exitField, 'Left'), 'N/A - Active'), 'a reason for exit off the list is refused and the list (N/A - Active first) is shown');
same('N/A - Active', $exitField['options'][0] ?? '', 'N/A - Active leads the Reason for Exit list');
same(null, $import->problemWith(['type' => 'Text', 'pattern' => '^[0-9]{4}$', 'pattern_hint' => 'four digits'], '1234'), 'problemWith: a match is fine');
same("'12' is not four digits.", $import->problemWith(['type' => 'Text', 'pattern' => '^[0-9]{4}$', 'pattern_hint' => 'four digits'], '12'), 'and a miss says what it wanted');
