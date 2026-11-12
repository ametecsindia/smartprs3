<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\EmployeeExtras;
use SmartPRS\Surface\EmployeeFields;
use SmartPRS\Surface\EmployeeImport;
use SmartPRS\Surface\Store;

/**
 * BRANCH AND REPORTING MANAGER ARE MANDATORY — owner, 23 Sep: "While adding an
 * employee through Add Employee form or sample file upload, ensure selecting the
 * Branch and Reporting Manager mandatory. This will help in having proper
 * hierarchy up to date."
 *
 * The form already put `required` on the input, which is a courtesy to somebody
 * filling the form in and nothing at all to an uploaded sheet. Three things are
 * worth pinning: a required field left blank is refused; a required COLUMN
 * MISSING ALTOGETHER is refused — the parse loop only ever visits columns the
 * file carries, so this is the one that would have let a whole organisation in
 * with no branches; and an UPDATE row is NOT held to it, because a file whose
 * purpose is to correct one column carries only that column.
 */

group('employee import · required fields');

$dir = sys_get_temp_dir() . '/prs3-req-' . bin2hex(random_bytes(4));
$store = (new Store($dir))->forTenant('1');
$store->put('branches', ['id' => '4', 'name' => 'KONDAPUR']);
$store->put('branches', ['id' => '7', 'name' => 'MADHAPUR']);
$store->put('employees', ['id' => '11', 'emp_code' => 'MGR-004', 'name' => 'Ravi Kumar',
    'branch_id' => '4', 'reporting_manager_id' => '']);

$fields = new EmployeeFields($store, new Customisation($store), dirname(__DIR__) . '/config/employee-fields.json');
$import = new EmployeeImport($store, $fields, new EmployeeExtras($store), $dir . '/spool',
    ['branches' => ['4' => 'KONDAPUR', '7' => 'MADHAPUR'], 'employees' => ['11' => 'Ravi Kumar']]);

$problemsOn = static function (array $parsed, int $line): string {
    foreach ($parsed['rows'] as $row) {
        if ((int) $row['line'] === $line) {
            return implode(' | ', $row['problems']);
        }
    }

    return '(no such line)';
};

$head = "emp_code,name,branch_id,reporting_manager_id\n";

// --- the row that works, so the refusals below mean something ----------------

$good = $import->parse($head . "ABCR-2001,Asha Rao,KONDAPUR,Ravi Kumar\n");
same(1, $good['ok'], 'a row naming a branch and a reporting manager imports');
same(0, $good['errors'], 'and raises nothing');

// --- blank cells --------------------------------------------------------------

$blank = $import->parse($head . "ABCR-2002,Vikram S,,\n");
same(0, $blank['ok'], 'a row with both left empty is refused');
ok(str_contains($problemsOn($blank, 2), 'Branch'), 'and Branch is named');
ok(str_contains($problemsOn($blank, 2), 'Reporting Manager'), 'and Reporting Manager is named');

$half = $import->parse($head . "ABCR-2003,Neha K,KONDAPUR,\n");
same(0, $half['ok'], 'one of the two missing is still a refusal');
ok(str_contains($problemsOn($half, 2), 'Reporting Manager'), 'naming the one that is missing');
ok(!str_contains($problemsOn($half, 2), 'Branch'), 'and not the one that is there');

// --- THE COLUMN DELETED FROM THE SHEET ---------------------------------------
// The per-cell loop never visits a column the file does not carry, so without a
// pass over the FIELDS this row looks complete and imports a branchless employee.

$gone = $import->parse("emp_code,name\nABCR-2004,Imran Q\n");
same(0, $gone['ok'], 'deleting the Branch column does not make it optional');
ok(str_contains($problemsOn($gone, 2), 'Branch'), 'the missing column is reported by name');

// --- an optional field is still optional --------------------------------------

$optional = $import->parse("emp_code,name,branch_id,reporting_manager_id,grade_band\n"
    . "ABCR-2005,Rita M,MADHAPUR,Ravi Kumar,\n");
same(1, $optional['ok'], 'a field nobody marked required is left alone');

// --- UPDATE AND SKIP ROWS ARE NOT HELD TO IT ----------------------------------
// A correction file carries the column being corrected and nothing else, and the
// employee's existing branch is not erased by a cell that is not there.

$update = $import->parse("emp_code,name\nMGR-004,Ravi K\n", 'update');
same('update', $update['rows'][0]['action'], 'an existing code in update mode is an update');
same(0, $update['errors'], 'and is not refused for columns it does not carry');

$skip = $import->parse("emp_code,name\nMGR-004,Ravi K\n", 'skip');
same('skip', $skip['rows'][0]['action'], 'a skipped duplicate writes nothing');
same(0, $skip['errors'], 'so there is nothing to be required about');

// --- WHERE `required` COMES FROM ----------------------------------------------
// Three sources now, and the catalogue's own was the one missing. A tenant who
// saved their field register before this shipped has a SNAPSHOT that says
// nothing about it — the same trap the attendance report's columns sprang — and
// `core` was not the place to put it, because core also means "cannot be turned
// off", which is a different promise.

group('employee fields · required');

$requiredIn = static function (EmployeeFields $reg, string $code): ?bool {
    foreach ($reg->sections() as $section) {
        foreach ($section['fields'] as $field) {
            if ((string) $field['code'] === $code) {
                return (bool) $field['required'];
            }
        }
    }

    return null;
};

same(true, $requiredIn($fields, 'branch_id'), 'Branch is required');
same(true, $requiredIn($fields, 'reporting_manager_id'), 'Reporting Manager is required');
same(false, $requiredIn($fields, 'grade_band'), 'a field the catalogue says nothing about is not');

// A TENANT WHO ALREADY SAVED THEIR REGISTER, with neither field ticked. Their
// snapshot is what the screen shows them, so if only the saved value counted,
// this install could not be made to require Branch at all without a re-save
// nobody knows to do.
$saved = new Customisation($store);
$saved->saveRaw(EmployeeFields::KEY, ['captured' => [
    'emp_code' => ['required' => true, 'type' => 'Alpha Numeric'],
    'name' => ['required' => true, 'type' => 'Text'],
    'branch_id' => ['required' => false, 'type' => 'List'],
    'reporting_manager_id' => ['required' => false, 'type' => 'List'],
    'grade_band' => ['required' => true, 'type' => 'Text'],
]], 'u1', 'hr@example.com', 'Saved before this shipped');

$configured = new EmployeeFields($store, $saved, dirname(__DIR__) . '/config/employee-fields.json');

same(true, $requiredIn($configured, 'branch_id'),
    'the catalogue still makes Branch required on an install configured before it said so');
same(true, $requiredIn($configured, 'grade_band'),
    'and the customer\'s own tick still counts, which is the point of that screen');
