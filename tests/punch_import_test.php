<?php

declare(strict_types=1);

use SmartPRS\Surface\PunchImport;
use SmartPRS\Surface\Store;

/**
 * PUNCHES OFF A DEVICE, AND THE FOUR WAYS THAT GOES WRONG.
 *
 * The import exists because every punch in this product was typed by a human.
 * The reason it is TESTED this hard is that each of its failure modes costs a
 * real person money, and three of the four are silent:
 *
 *   - a punch imported twice pays a day that was not worked;
 *   - a punch attached to a plausible-looking employment pays the wrong person;
 *   - a punch dropped because its enrolment number is unknown is somebody who
 *     worked and will not be paid for it;
 *   - a malformed line that stops the import loses the rest of the month.
 *
 * So the assertions below are COUNTS AND INSTANTS a person can check against
 * tests/fixtures/punches-zkteco.dat by reading it — ten lines, of which seven
 * are punches and three are not, three belong to somebody and three do not.
 * The non-vacuity section at the end proves those outcomes are genuinely
 * different from each other, because "imported", "already present" and
 * "unmapped" are all just integers and a broken import can make them agree.
 */

group('punch import');

$fixtures = __DIR__ . '/fixtures';
$file = (string) file_get_contents($fixtures . '/punches-zkteco.dat');

// A real store, files not MySQL — `Store` and `SqlStore` are the same interface
// and this test is about the import's arithmetic, not the driver's.
$directory = sys_get_temp_dir() . '/smartprs3-punch-' . bin2hex(random_bytes(4));
$store = (new Store($directory))->forTenant('3');

foreach ([
    ['emp_code' => 'SP-001', 'name' => 'Ramesh Naidu', 'device_user_id' => '37'],
    ['emp_code' => 'SP-002', 'name' => 'Sunitha Reddy', 'device_user_id' => '41'],
    ['emp_code' => 'SP-003', 'name' => 'Arun Kumar Yadav', 'device_user_id' => ''],
    // The same enrolment number on two employments: somebody was enrolled onto
    // a leaver's slot. There is no right answer to "who punched", so there must
    // not be an answer.
    ['emp_code' => 'SP-004', 'name' => 'Priya Deshmukh', 'device_user_id' => '99'],
    ['emp_code' => 'SP-005', 'name' => 'Vikram Shetty', 'device_user_id' => '99'],
] as $employee) {
    $store->put('employees', $employee);
}

$import = new PunchImport($store);

// =============================================================================
// THE LAYOUT IS DATA, AND IT IS CHECKED BEFORE A LINE IS READ
// =============================================================================

$layouts = PunchImport::layoutsFrom($fixtures . '/punch-layouts.json');
$zk = PunchImport::layout($layouts, 'zkteco_dat');

same('zkteco_dat', $zk['name'], 'a named layout comes back knowing its own name, so a report can '
    . 'say which one produced the rows');

throws(RuntimeException::class,
    static fn () => PunchImport::layoutsFrom($fixtures . '/there-is-no-such-file.json'),
    'a missing layout file is a refusal, not an empty set of layouts — the column order differs '
    . 'per vendor and guessing it is how punches reach the wrong person');

throws(RuntimeException::class, static fn () => PunchImport::layout($layouts),
    'three declared layouts and none named is a refusal: reading a ZKTeco file with an eSSL layout '
    . 'produces rows that parse and mean something else');

throws(RuntimeException::class, static fn () => PunchImport::layout($layouts, 'no_directions'),
    'a layout that declares no direction codes is refused whole, before any line is read — a '
    . 'direction defaulted to `in` is a payable day computed from a guess');

throws(RuntimeException::class, static fn () => PunchImport::layout($layouts, 'matrix_whatever'),
    'and a layout name nobody declared is refused rather than falling back to the first one');

// =============================================================================
// A GOOD FILE: THE RIGHT NUMBER OF PUNCHES, AT THE RIGHT INSTANTS
// =============================================================================

$parsed = PunchImport::parse($file, $zk);

same(7, count($parsed['punches']), 'ten lines, one blank, three unreadable — seven punches. Counted '
    . 'by hand off tests/fixtures/punches-zkteco.dat');
same(3, count($parsed['unparsable']), 'and the three that are not punches are held, not lost');

same('2026-09-01 09:12:33', $parsed['punches'][0]['punch_at'],
    'the first punch is 09:12:33 on 1 September, as the store keeps an instant — the same '
    . "'Y-m-d H:i:s' wall clock Attendance::record() writes, no offset bolted on");
same('2026-09-01', $parsed['punches'][0]['log_date'],
    'and the date the punch carries, which is what attendance_logs.log_date holds');
same('in', $parsed['punches'][0]['direction'], "the device's code 0 is an IN because the layout "
    . 'says so, not because 0 looks like a beginning');
same('out', $parsed['punches'][1]['direction'], 'and 1 is an OUT by the same declaration');
same('ZK-A1', $parsed['punches'][0]['device_sn'],
    'the serial rides along, so a punch can be traced back to the box it came off');

$report = $import->import($file, $zk);

same(3, $report['imported'], 'three punches import: 37 twice and 41 once. The other four are a '
    . 'stranger twice, a shared enrolment number, and a line the file repeats');
same(3, $store->count(PunchImport::COLLECTION), 'and the table holds exactly those three');

$stored = $store->all(PunchImport::COLLECTION);
$byCode = [];

foreach ($stored as $row) {
    $byCode[(string) $row['emp_code']][] = (string) $row['punch_at'];
}

same(['2026-09-01 09:12:33', '2026-09-01 18:31:02'], $byCode['SP-001'] ?? [],
    'SP-001 worked 09:12 to 18:31 — the two instants off the device, against the employment whose '
    . 'device_user_id column says 37 is him');
same(['2026-09-01 09:58:10'], $byCode['SP-002'] ?? [], 'SP-002 has the one punch enrolment 41 made');
same('device', (string) $stored[0]['source'],
    'and every imported row says it came off a device, so a manual punch and a device punch are '
    . 'never confused in an audit');
same('37', (string) $stored[0]['device_user_id'],
    'the enrolment number is KEPT on the row. Without it, a mis-mapped punch can never be traced '
    . 'back to which device user it really was');

// =============================================================================
// THE SAME FILE TWICE ADDS NOTHING — the defect this tool always ships with
// =============================================================================

$again = $import->import($file, $zk);

same(0, $again['imported'], 'the second import of the same file writes NOTHING');
same(4, $again['already_present'], 'and recognises all four attributable punches, including the '
    . 'line the file itself repeats');
same(3, $store->count(PunchImport::COLLECTION),
    'the table still holds three rows. This is the assertion that fails the day somebody keys the '
    . 'external_id off the file name or the import time');

// A THIRD import, with the file's line order shuffled and its name irrelevant.
// A device re-export is the same punches in a different order, and an import
// that only recognises them positionally doubles the month.
$shuffled = implode("\n", array_reverse(explode("\n", $file)));

same(0, $import->import($shuffled, $zk)['imported'],
    'and re-importing the same punches in a different ORDER adds nothing either — identity is the '
    . 'device, the person and the instant, never the position in the file');
same(3, $store->count(PunchImport::COLLECTION), 'still three rows');

// =============================================================================
// AN UNMAPPED ENROLMENT NUMBER IS REPORTED AND NOT IMPORTED
// =============================================================================

same(['88' => 2], $report['unmapped'],
    'enrolment 88 is on no employment, and its TWO punches are reported by count. Somebody worked '
    . 'those hours; the report is how anybody finds out');

$deviceIds = array_map(static fn (array $r): string => (string) $r['device_user_id'], $stored);

ok(!in_array('88', $deviceIds, true),
    'and not one of 88\'s punches reached the table — not attached to the nearest plausible '
    . 'employment, which would pay the wrong person, and not silently dropped, which would not pay '
    . 'the right one');

same(['99' => 1], $report['ambiguous'],
    'enrolment 99 is on TWO employments, so it is refused as ambiguous rather than resolved by '
    . 'taking the first row the store returned');
ok(!in_array('99', $deviceIds, true), 'and 99\'s punch is not in the table under either employment');

$map = $import->directory();
same('SP-001', $map['codes']['37'] ?? null, 'the mapping comes from employees.device_user_id — a '
    . 'column somebody filled in, not a similarity between numbers');
ok(!array_key_exists('99', $map['codes']),
    'and a number claimed by two employments is in nobody\'s directory');
ok(!array_key_exists('', $map['codes']),
    'an employment with no device id recorded does not become the home for every blank id');

// =============================================================================
// A MALFORMED LINE IS REPORTED BY LINE NUMBER AND STOPS NOTHING
// =============================================================================

same([6, 7, 8], array_column($report['unparsable'], 'line'),
    'lines 6, 7 and 8 — a junk timestamp, a truncated row, and a direction code the layout does '
    . 'not declare. By LINE NUMBER, because "3 lines failed" is a report nobody can act on');

ok(str_contains($report['unparsable'][0]['why'], 'not-a-time'),
    'the reason quotes what it could not read');
ok(str_contains($report['unparsable'][1]['why'], 'columns'),
    'a truncated line says it was short of columns — this is how a device export ends when the '
    . 'box is unplugged mid-write, and the month before it must still import');
ok(str_contains($report['unparsable'][2]['why'], "'7'"),
    'and an undeclared direction code is named, not defaulted');

ok($report['imported'] > 0 && $report['unparsable'] !== [],
    'punches imported IN THE SAME RUN as lines failed: one bad line does not cost the file');

// A file that is entirely unreadable under this layout yields zero punches, and
// that is a finding the tool refuses on rather than a quiet success.
$wrongLayout = PunchImport::parse($file, PunchImport::layout($layouts, 'essl_csv'));
same(0, count($wrongLayout['punches']),
    'the same bytes read with the eSSL layout produce NO punches — which is what the tool refuses '
    . 'on, because "0 imported" printed as success is how attendance stops in January and is '
    . 'discovered in March');

// =============================================================================
// NON-VACUITY — these outcomes are genuinely different from each other
// =============================================================================
// Everything above is integers and arrays. An import that did nothing at all, or
// one that treated every punch identically, could satisfy several of them at
// once. This section is what makes that impossible.

ok($report['imported'] !== $report['already_present']
    && $report['imported'] !== count($report['unmapped'])
    && $report['already_present'] !== count($report['unparsable']),
    'imported (3), already present (1), unmapped ids (1) and unparsable lines (3) are not one '
    . 'number reported four times');

ok($report['parsed'] > $report['imported'],
    'more punches were READ than were IMPORTED — the withholding actually happens, rather than '
    . 'every parsed row landing in the table');

same($report['parsed'], $report['imported'] + $report['already_present']
    + array_sum($report['unmapped']) + array_sum($report['ambiguous']),
    'and every punch read is accounted for exactly once: imported, already present, unmapped or '
    . 'ambiguous. A punch that is in none of those buckets has vanished, which is the outcome this '
    . 'whole file exists to make impossible');

// THE PROOF THAT WITHHELD PUNCHES ARE WITHHELD AND NOT LOST. Record 88 against
// the employment that had no device id, re-import the SAME file: the two
// withheld punches arrive, and the three already there do not double. This one
// assertion pair fails if the unmapped refusal is a silent drop, AND fails if
// idempotency is a no-op — the two defects cannot both hide behind it.
$sp003 = null;

foreach ($store->all('employees') as $employee) {
    if ($employee['emp_code'] === 'SP-003') {
        $sp003 = $employee;
    }
}

$store->put('employees', ['id' => $sp003['id'], 'device_user_id' => '88']);
$recovered = new PunchImport($store);
$third = $recovered->import($file, $zk);

same(2, $third['imported'], 'with 88 recorded against SP-003, exactly the two withheld punches '
    . 'import — they were held back, not thrown away');
same(5, $store->count(PunchImport::COLLECTION),
    'and the table goes from three rows to five: the two that were missing, and not one repeat of '
    . 'the three that were already there');
same([], $third['unmapped'], 'nothing is unmapped any more');
same(['99' => 1], $third['ambiguous'],
    'and 99 is STILL ambiguous, because that is a different problem with a different fix — one '
    . 'employment has to give the number up');

// A dry run REPORTS what it would do and writes nothing — a claim worth checking
// against a punch that is genuinely new, since "wrote nothing" is also what a
// broken import does.
$tomorrow = "37\t2026-09-02 09:04:11\t0\t1\tZK-A1\n";

same(1, $recovered->import($tomorrow, $zk, dryRun: true)['imported'],
    'a dry run reports the one new punch it would import');
same(5, $store->count(PunchImport::COLLECTION), 'and writes nothing');
same(1, $recovered->import($tomorrow, $zk)['imported'], 'the same file, run for real, imports it');
same(6, $store->count(PunchImport::COLLECTION),
    'and now there are six rows — so the dry run was a dry run and not a failed import');

// Cleanup: this store is a scratch directory, not part of the suite's state.
foreach (glob($directory . '/*.json') ?: [] as $json) {
    unlink($json);
}

@rmdir($directory);
