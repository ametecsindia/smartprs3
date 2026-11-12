<?php
/**
 * ROSTER: bring back a filled roster sample (.xlsx, .xls saved as .xlsx, or
 * .csv) and turn each filled cell into a roster row. A cell names a Working
 * Shift by its CODE — matching the sample's own dropdown, case-insensitive —
 * never a raw id, since a code is what a human types and what the sample
 * offers. Consecutive days for the same employee with the same code become
 * ONE roster row (Roster::assign()'s own from/to range), so a week of "G" is
 * one row, not seven. A blank cell is simply left alone: no row is written,
 * and whatever already covers that day (an earlier roster row, or the scoped
 * Working Shift) still applies — an import can only ADD rows, never erase one
 * (Cancel, on the Roster screen, is still how a row is undone).
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Roster;
use SmartPRS\Surface\Sheet;

$back = '?r=roster';

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ' . $back);
    exit;
}

$upload = (string) ($_FILES['sheet']['tmp_name'] ?? '');

if (!is_uploaded_file($upload)) {
    $_SESSION['flash_problem'] = 'No file was uploaded. Nothing was read.';
    header('Location: ' . $back);
    exit;
}

try {
    $csv = Sheet::toCsv((string) file_get_contents($upload), (string) ($_FILES['sheet']['name'] ?? 'upload.csv'));
} catch (\Throwable $e) {
    $_SESSION['flash_problem'] = $e->getMessage();
    header('Location: ' . $back);
    exit;
}

$lines = array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), static fn (string $l): bool => trim($l) !== ''));

if (count($lines) < 4) {
    $_SESSION['flash_problem'] = 'That file has no data rows. Use the downloaded sample as-is — only its blank cells should change.';
    header('Location: ' . $back);
    exit;
}

// ROW 1 IS THE LEGEND, ROW 2 THE DAY-OF-WEEK ROW (roster-sample.php's own
// first two rows) — neither is data. Row 3 is the real header: Emp ID, Name,
// Reporting Manager, Designation, Branch, then one date per column.
$header = str_getcsv($lines[2]);
$dateCols = [];

foreach ($header as $i => $h) {
    $t = $i >= 5 ? strtotime(str_replace('/', '-', trim((string) $h))) : false;

    if ($t !== false) {
        $dateCols[$i] = date('Y-m-d', $t);
    }
}

if ($dateCols === []) {
    $_SESSION['flash_problem'] = 'No date columns were recognised. Use the downloaded sample as-is — only its blank cells should change.';
    header('Location: ' . $back);
    exit;
}

$roster = new Roster($store, __DIR__ . '/../../config/roster.json');
$shiftByCode = [];

foreach ($roster->shifts() as $s) {
    if (!Modules::isRetired($s) && (string) ($s['code'] ?? '') !== '') {
        $shiftByCode[strtolower(trim((string) $s['code']))] = (string) $s['id'];
    }
}

$byCode = [];

foreach (liveRows($store, 'employees') as $e) {
    $byCode[strtolower(Employment::code($e))] = $e;
}

$now = date('Y-m-d H:i:s');
$by = (string) $session->user()['email'];
$written = 0;
$skipped = 0;
$problems = [];

for ($i = 3; $i < count($lines); $i++) {
    $cells = str_getcsv($lines[$i]);
    $empId = trim((string) ($cells[0] ?? ''));

    if ($empId === '') {
        continue;
    }

    $employee = $byCode[strtolower($empId)] ?? null;

    if ($employee === null) {
        $problems[] = 'Line ' . ($i + 1) . ": no employee with code '{$empId}'.";
        $skipped++;

        continue;
    }

    // GROUP CONSECUTIVE DATE COLUMNS WITH THE SAME CODE into one range, so a
    // week of the same shift is one roster row — the columns are already in
    // date order because they came straight from the sample this generated.
    $runs = [];
    $runCode = null;
    $runFrom = null;
    $runTo = null;

    foreach ($dateCols as $col => $date) {
        $code = trim((string) ($cells[$col] ?? ''));

        // STATUS WORDS ARE INFORMATIONAL ONLY (roster-sample.php's extra
        // dropdown options) — no live Working Shift to look up, so treat them
        // as a blank cell rather than "not a live Working Shift code".
        if (in_array(strtolower($code), ['w.off', 'holiday', 'leave'], true)) {
            $code = '';
        }

        $continues = $code !== '' && $runCode !== null && strcasecmp($code, $runCode) === 0
            && $date === date('Y-m-d', strtotime($runTo . ' +1 day'));

        if ($continues) {
            $runTo = $date;

            continue;
        }

        if ($runCode !== null) {
            $runs[] = ['code' => $runCode, 'from' => $runFrom, 'to' => $runTo];
        }

        $runCode = $code === '' ? null : $code;
        $runFrom = $date;
        $runTo = $date;
    }

    if ($runCode !== null) {
        $runs[] = ['code' => $runCode, 'from' => $runFrom, 'to' => $runTo];
    }

    foreach ($runs as $run) {
        $shiftId = $shiftByCode[strtolower($run['code'])] ?? null;

        if ($shiftId === null) {
            $problems[] = 'Line ' . ($i + 1) . " ({$empId}), {$run['from']}: '{$run['code']}' is not a live Working Shift code.";
            $skipped++;

            continue;
        }

        $result = $roster->assign([(string) $employee['id']], $shiftId, $run['from'], $run['to'], 'Bulk roster import', $by, $now);

        if ($result['ok']) {
            $written++;
        } else {
            $problems[] = 'Line ' . ($i + 1) . " ({$empId}): " . $result['why'];
            $skipped++;
        }
    }
}

if ($written > 0) {
    $audit('roster.imported', 'rosters', '', null, ['written' => $written, 'skipped' => $skipped], "{$written} roster row(s) imported, {$skipped} skipped");
}

$_SESSION[$written > 0 ? 'flash_notice' : 'flash_problem'] = "{$written} roster row(s) written, {$skipped} skipped."
    . ($problems === [] ? '' : ' ' . implode(' ', array_slice($problems, 0, 10)) . (count($problems) > 10 ? ' (' . (count($problems) - 10) . ' more not shown)' : ''));
header('Location: ' . $back);
exit;
