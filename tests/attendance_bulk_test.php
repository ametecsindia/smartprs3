<?php

declare(strict_types=1);

/**
 * BULK ATTENDANCE: A SPREADSHEET BECOMES PUNCHES ONLY WHEN SOMEBODY APPROVES IT.
 *
 * The SmartPRS 2 screen this replaces matched people by name and took whichever
 * it saw last, parsed dates with a library that reads 01/09 as January, and
 * wrote punches with no key on them so a re-approval doubled a month. Each of
 * those is a case below, and each is asserted to be REFUSED with the line named,
 * because a row that is silently skipped is a person who worked and is not paid.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\AttendanceBulk;
use SmartPRS\Surface\Store;

group('attendance bulk');

// ---------------------------------------------------------------- the table
require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();

foreach (glob(__DIR__ . '/../database/migrations/*.php') as $file) {
    (require $file)->up();
}

$table = Illuminate\Support\Facades\Schema::$blueprints[AttendanceBulk::COLLECTION] ?? null;

ok($table !== null, 'the migrations create attendance_bulk_rows');

foreach (['tenant_id', 'batch', 'line_no', 'emp_code', 'given_code', 'given_name', 'log_date',
    'in_time', 'out_time', 'status', 'error', 'uploaded_by', 'decided_by', 'decided_at'] as $column) {
    ok($table !== null && $table->has($column), "and it has the column `{$column}` the class writes");
}

// --------------------------------------------------------------- parsing
$dayCsv = "emp_code,employee_name,date,in_time,out_time\n"
    . "SP-001,Asha Rao,05-06-2026,09:15,18:35\n"
    . ",Vikram Reddy,2026-06-05,9.30 AM,6:10 PM\n";
$parsed = AttendanceBulk::parse($dayCsv);
same('day', $parsed['kind'], 'the day template is recognised by its header');
same(2, count($parsed['rows']), 'two data rows');
same(3, $parsed['rows'][1]['line_no'], 'and each row remembers the line of the file it came from');

$reordered = "date,out_time,in_time,employee_name,emp_code\n2026-06-05,18:00,09:00,Asha Rao,SP-001\n";
$r = AttendanceBulk::parse($reordered)['rows'][0];
same('09:00', $r['in'], 'columns are matched by NAME, so a reordered sheet still reads the in time as in');
same('SP-001', $r['given_code'], 'and the code from wherever the sheet put it');

same('punch', AttendanceBulk::parse("emp_code,employee_name,date,punch_time,direction\nSP-001,,2026-06-05,09:00,in\n")['kind'],
    'the punch template is recognised too');

throws(RuntimeException::class,
    static fn () => AttendanceBulk::parse("code,name,when\nSP-001,Asha,2026-06-05\n"),
    'a header that is neither template is refused — a column matched by position is a wrong-way punch');

$bom = "\xEF\xBB\xBFemp_code,employee_name,date,in_time,out_time\nSP-001,,2026-06-05,09:00,18:00\n";
same('day', AttendanceBulk::parse($bom)['kind'], 'the BOM Excel writes in front of the header is ignored');

$blank = "emp_code,employee_name,date,in_time,out_time\n,,2026-06-05,09:00,18:00\n";
same(1, count(AttendanceBulk::parse($blank)['unparsable']), 'a row naming nobody is reported, not dropped');

// dates and times: declared formats, nothing guessed
same('2026-09-01', AttendanceBulk::date('01/09/2026'), '01/09/2026 is the first of September here');
same('2026-09-01', AttendanceBulk::date('01-09-2026'), 'd-m-Y too');
same(null, AttendanceBulk::date('09/01/2026 '), 'and a value that does not re-format to itself is refused');
same(null, AttendanceBulk::date('2026-13-01'), 'as is an impossible month');
same('09:05', AttendanceBulk::time('9:5'), 'a sloppy time is normalised');
same('18:10', AttendanceBulk::time('6.10 PM'), 'a PM time is a 24-hour time');
same('00:15', AttendanceBulk::time('12:15 AM'), 'and 12 AM is midnight');
same(null, AttendanceBulk::time('25:00'), 'an impossible hour is refused');
same('18:00', AttendanceBulk::time('6 PM'), '6 PM is a time');
same(null, AttendanceBulk::time('6'), 'a bare 6 is not — morning or evening is not said');
same(null, AttendanceBulk::time('0.3854'), 'an Excel time fraction is refused — it would need a float');

// ------------------------------------------------------------- uploading
$store = (new Store(sys_get_temp_dir() . '/prs3-bulk-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('employees', ['id' => '1', 'emp_code' => 'SP-001', 'name' => 'Asha Rao', 'device_user_id' => '37']);
$store->put('employees', ['id' => '2', 'emp_code' => 'SP-002', 'name' => 'Ravi Kumar']);
$store->put('employees', ['id' => '3', 'emp_code' => 'SP-003', 'name' => 'Ravi Kumar']);
$store->put('employees', ['id' => '4', 'emp_code' => 'SP-004', 'name' => 'Vikram Reddy']);

$attendance = new Attendance($store);
$bulk = new AttendanceBulk($store, $attendance);
$now = new DateTimeImmutable('2026-06-10 12:00:00');

$csv = "emp_code,employee_name,date,in_time,out_time\n"
    . "SP-001,Asha Rao,05-06-2026,09:15,18:35\n"          // 2 fine
    . "37,,2026-06-06,09:00,18:00\n"                       // 3 device id resolves
    . ",Vikram Reddy,2026-06-05,9.30 AM,6:10 PM\n"        // 4 unique name resolves
    . ",Ravi Kumar,2026-06-05,09:00,18:00\n"              // 5 two Ravis — refused
    . "SP-009,Nobody,2026-06-05,09:00,18:00\n"            // 6 unknown code — refused, not by name
    . "SP-001,,2026-06-11,09:00,18:00\n"                  // 7 tomorrow — refused
    . "SP-001,,2026-06-10,09:00,13:00\n"                  // 8 today 13:00 > 12:00 — refused
    . "SP-001,,2026-06-07,18:00,09:00\n"                  // 9 out before in — refused
    . "SP-001,,2026-06-08,,\n"                            // 10 no times — refused
    . "SP-001,,31/02/2026,09:00,18:00\n"                  // 11 not a date — refused
    . "SP-001,,2026-06-09,09:00,\n";                      // 12 in only — fine, half a day
$report = $bulk->upload($csv, 'hr@example', $now);

same(11, $report['total'], 'every row is kept');
same(4, $report['pending'], 'four are ready for a decision');
same(7, $report['errors'], 'seven are refused, each with a reason');
same(['SP-009' => 1], $report['unknown'], 'and the codes nobody has are listed for the mapping screen');

$rows = $bulk->rows($report['batch']);
$byLine = [];

foreach ($rows as $row) {
    $byLine[(int) $row['line_no']] = $row;
}

same('SP-001', $byLine[3]['emp_code'], 'a device user id resolves to the employment that carries it');
same('SP-004', $byLine[4]['emp_code'], 'a name held by exactly one employment resolves');
same('09:30', $byLine[4]['in_time'], 'with the AM time normalised');
same('18:10', $byLine[4]['out_time'], 'and the PM one');
ok(str_contains((string) $byLine[5]['error'], 'SP-002, SP-003'),
    'a name held by two employments is refused naming BOTH codes — never whichever came last');
ok(str_contains((string) $byLine[6]['error'], 'Not matched by name'),
    'an unknown code is refused and NOT rescued by the name beside it');
ok(str_contains((string) $byLine[7]['error'], 'not happened yet'), 'a future date is refused');
ok(str_contains((string) $byLine[8]['error'], 'future'), 'and so is a time later today');
ok(str_contains((string) $byLine[9]['error'], 'not after'), 'out before in is refused');
ok(str_contains((string) $byLine[10]['error'], 'Neither'), 'a row with no times is refused');
ok(str_contains((string) $byLine[11]['error'], 'not a date'), 'the 31st of February is not a date');
same(AttendanceBulk::PENDING, $byLine[12]['status'], 'an in with no out is a claim about half a day and is kept');

same(0, count($attendance->punchesFor('SP-001')), 'and NOTHING has reached attendance: upload writes claims, not punches');

// --------------------------------------------------------------- deciding
$batches = $bulk->batches();
same(1, count($batches), 'one upload is one batch');
same(4, $batches[0]['pending'], 'summarised by state');
same(7, $batches[0]['errors'], '…including the errors, so the uploader sees them');

$withdraw = $bulk->withdraw((string) $byLine[12]['id']);
ok($withdraw['ok'], 'a pending row can be withdrawn before the decision');
same(AttendanceBulk::WITHDRAWN, $bulk->rows($report['batch'])[10]['status'], 'and it stays in the batch, marked — not deleted');

$decision = $bulk->decide($report['batch'], true, 'admin@example');
ok($decision['ok'], 'the administrator approves the batch');
same(3, $decision['decided'], 'the three still-pending rows are decided');
same(6, $decision['punches'], 'and become six punches (an in and an out each)');

$punches = $attendance->punchesFor('SP-001');
same(4, count($punches), 'SP-001 now has four punches (lines 2 and 3)');
same(AttendanceBulk::SOURCE, $punches[0]['source'], 'each says it came from a bulk upload');
same(AttendanceBulk::reference($report['batch'], 2, 'in'), $punches[0]['source_reference'],
    'and carries the batch and line it came from');
same('2026-06-05 09:15:00', $punches[0]['at'], 'at the time the sheet said');

$again = $bulk->decide($report['batch'], true, 'admin@example');
ok(!$again['ok'], 'approving the same batch again is refused');
same(4, count($attendance->punchesFor('SP-001')), 'and wrote nothing');

$withdrawDecided = $bulk->withdraw((string) $byLine[2]['id']);
ok(!$withdrawDecided['ok'], 'an approved row cannot be withdrawn — it is the evidence behind a punch');

// the idempotency key holds even if the status flag were lost
$store->put(AttendanceBulk::COLLECTION, array_merge($byLine[2], ['status' => AttendanceBulk::PENDING]));
$replay = $bulk->decide($report['batch'], true, 'admin@example');
same(0, $replay['punches'], 'a row forced back to pending writes no punch: the reference is already present');
same(2, $replay['already'], 'and says so');

// rejecting writes nothing
$report2 = $bulk->upload("emp_code,employee_name,date,in_time,out_time\nSP-004,,2026-06-05,09:00,18:00\n",
    'hr@example', $now);
$rejected = $bulk->decide($report2['batch'], false, 'admin@example');
ok($rejected['ok'], 'a batch can be rejected');
same(2, count($attendance->punchesFor('SP-004')), 'and a rejected batch adds no punch at all (the two are line 4 of the first batch)');
same(AttendanceBulk::REJECTED, $bulk->rows($report2['batch'])[0]['status'], 'the row says rejected');

// punch template
$report3 = $bulk->upload("emp_code,employee_name,date,punch_time,direction\n"
    . "SP-004,,2026-06-06,09:00,in\nSP-004,,2026-06-06,18:00,OUT\nSP-004,,2026-06-06,12:00,sideways\n",
    'hr@example', $now);
same('punch', $report3['kind'], 'the punch template loads');
same(2, $report3['pending'], 'two directions read');
same(1, $report3['errors'], 'and a direction nobody declared is refused rather than guessed');
$bulk->decide($report3['batch'], true, 'admin@example');
same(4, count($attendance->punchesFor('SP-004')), 'approved punch rows are one punch each — two more');

// ceilings are stated
throws(RuntimeException::class,
    static fn () => $bulk->upload("emp_code,employee_name,date,in_time,out_time\n", 'x', $now),
    'a file with a header and no rows is refused rather than becoming an empty batch');

$template = AttendanceBulk::template('day');
same(AttendanceBulk::DAY_COLUMNS, str_getcsv(explode("\n", $template)[0], ',', '"', '\\'),
    'the downloadable template has exactly the header the parser accepts');
same(AttendanceBulk::PUNCH_COLUMNS, str_getcsv(explode("\n", AttendanceBulk::template('punch'))[0], ',', '"', '\\'),
    'for both templates');
