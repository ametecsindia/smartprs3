<?php
/**
 * ROSTER: a blank fill-in sample for a date range — Emp ID, Name, Reporting
 * Manager, then one column per day in the range. Each day column is an Excel
 * dropdown restricted to the live Working Shift codes (Organization Setup →
 * Working Shifts — the SAME register the grid and Assign-a-shift form already
 * read; nothing new is configured here — a Weekly Off column value is just
 * whichever code that tenant gave their WO shift, exactly like G/AN/NS are).
 * Filled in and brought back through roster-import.php.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Roster;
use SmartPRS\Surface\Sheet;

if (!$may('employee.list')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.list')->summary;
    header('Location: ?r=roster');
    exit;
}

$from = (string) post('from');
$to = (string) post('to');

foreach ([$from, $to] as $d) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
        $_SESSION['flash_problem'] = 'Pick both a From and a To date before downloading the sample.';
        header('Location: ?r=roster');
        exit;
    }
}

if ($to < $from) {
    $_SESSION['flash_problem'] = 'The range ends before it starts.';
    header('Location: ?r=roster');
    exit;
}

if ((strtotime($to) - strtotime($from)) / 86400 > 92) {
    $_SESSION['flash_problem'] = 'That is more than 93 days. Pick a shorter range for one sample.';
    header('Location: ?r=roster');
    exit;
}

$roster = new Roster($store, __DIR__ . '/../../config/roster.json');
$liveShifts = array_values(array_filter($roster->shifts(),
    static fn (array $s): bool => !Modules::isRetired($s) && (string) ($s['code'] ?? '') !== ''));
usort($liveShifts, static fn (array $a, array $b): int => strcmp((string) $a['code'], (string) $b['code']));
// ponytail: first shift wins a duplicated code (two shifts sharing one code is
// a data problem elsewhere, not something this sample needs to resolve).
$byCode = [];
foreach ($liveShifts as $s) {
    $byCode[(string) $s['code']] ??= $s;
}
$shiftCodes = array_keys($byCode);

if ($shiftCodes === []) {
    $_SESSION['flash_problem'] = 'No live Working Shift has a code set yet. Add one under Organization Setup → '
        . 'Working Shifts — including one for Weekly Off, if this tenant tracks it that way — before downloading a roster sample.';
    header('Location: ?r=roster');
    exit;
}

$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $filter[$k] = strcasecmp(post('f_' . $k), 'all') === 0 ? '' : (string) post('f_' . $k);
}

$scoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$shown = orgFilterApply($store, $scoped, $filter);
usort($shown, static fn (array $a, array $b): int => strcmp(Employment::code($a), Employment::code($b)));

$names = [];

foreach (liveRows($store, 'employees') as $e) {
    $names[(string) ($e['id'] ?? '')] = trim(Employment::code($e) . ' ' . (string) ($e['name'] ?? ''));
}

$dates = [];

for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
    $dates[] = date('Y-m-d', $t);
}

// STATUS WORDS — not live Working Shifts, so nothing to look up; a tenant that
// wants them mapped to real days off/holidays/leave still does that through
// the existing Leave & Weekly Off / Holiday screens. Here they are marking
// options only: roster-import.php treats them as a no-op, same as a blank cell.
$statusWords = ['W.OFF' => 'Weekly Off', 'Holiday' => 'Company Holiday', 'Leave' => 'Approved Leave'];
$dropdownValues = array_merge($shiftCodes, array_keys($statusWords));

$legendText = [];
foreach ($byCode as $code => $s) {
    $legendText[] = $code . ' — ' . (string) $s['name'] . ' (' . substr((string) $s['start_time'], 0, 5) . '–' . substr((string) $s['end_time'], 0, 5) . ')';
}
foreach ($statusWords as $word => $desc) {
    $legendText[] = $word . ' — ' . $desc;
}

$legend = ['Shift codes (Organization Setup → Working Shifts):', implode(' | ', $legendText)];
$dayRow = array_merge(['Day', '', '', '', ''], array_map(static fn (string $d): string => date('D', (int) strtotime($d)), $dates));
$header = array_merge(['Emp ID', 'Name', 'Reporting Manager', 'Designation', 'Branch'],
    array_map(static fn (string $d): string => date('d-m-Y', (int) strtotime($d)), $dates));
$rows = [$legend, $dayRow, $header];

foreach ($shown as $e) {
    $rows[] = array_merge([
        Employment::code($e),
        (string) ($e['name'] ?? ''),
        $names[(string) ($e['reporting_manager_id'] ?? '')] ?? '',
        orgValueOf($store, $e, 'designation'),
        orgValueOf($store, $e, 'branch'),
    ], array_fill(0, count($dates), ''));
}

$dropdowns = [];

foreach (array_keys($dates) as $i) {
    $dropdowns[5 + $i] = $dropdownValues;
}

// ONE FILL COLOUR PER VALUE — a small palette for however many live shift
// codes exist, plus fixed colours for the three status words so they read the
// same across every roster download.
$palette = ['BFD7FF', 'FFE699', 'C6E0B4', 'D9D2E9', '9DC3E6', 'F4B183', 'B6D7A8', 'EA9999'];
$colors = [];
foreach (array_values($shiftCodes) as $i => $code) {
    $colors[$code] = $palette[$i % count($palette)];
}
$colors['W.OFF'] = 'D9D9D9';
$colors['Holiday'] = 'F8CBAD';
$colors['Leave'] = 'FFF2CC';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="roster-sample-' . date('d-m-Y', (int) strtotime($from))
    . '-to-' . date('d-m-Y', (int) strtotime($to)) . '.xlsx"');
echo Sheet::xlsx($rows, $dropdowns, 3, [], [], [], [], [], [0 => 14.0], [
    'cols' => [5, 5 + count($dates) - 1],
    'colors' => $colors,
], [0 => 'note', 1 => 'meta', 2 => 'head'], 'Roster');   // the legend one line across, the weekday row grey, the dates row the heading (27 Oct)
exit;
