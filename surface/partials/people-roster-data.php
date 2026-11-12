<?php declare(strict_types=1);
/**
 * ROSTER GRID — employee × day → shift code, for the Excel/CSV
 * (surface/actions/people-export.php with $params = month + the Organization
 * Setup filter). Same Roster::grid() the screen draws; a rostered cell is
 * marked with * so the file tells an override from the scoped shift.
 */
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Roster;
use SmartPRS\Surface\Shifts;

$export = null;

if (!$may('attendance.day.view')) {
    return;
}

$roRoster = new Roster($store, __DIR__ . '/../../config/roster.json', new Shifts($store));
$roMonth = preg_match('/^\d{4}-\d{2}$/', (string) ($params['month'] ?? '')) === 1 ? (string) $params['month'] : date('Y-m');
$roFilter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = trim((string) ($params['f_' . $k] ?? ''));
    $roFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$roScoped = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
usort($roScoped, static fn (array $a, array $b): int => strcmp(Employment::code($a), Employment::code($b)));
$roShown = orgFilterApply($store, $roScoped, $roFilter);
// THE SAME DATES AND PEOPLE AS THE SCREEN (owner, 22 Oct 2026): the picked From/To range (else the month) and the
// ticked employees. Roster::grid() takes the list of dates and keys cells by date — the month string it used to
// be handed here is what crashed the Excel button.
$roSel = array_values(array_filter(array_map('trim', explode(',', (string) ($params['sel'] ?? '')))));
$roSel === [] || $roShown = array_values(array_filter($roShown, static fn (array $e): bool => in_array(Employment::code($e), $roSel, true)));
$roFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($params['from'] ?? '')) === 1 ? (string) $params['from'] : '';
$roTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($params['to'] ?? '')) === 1 ? (string) $params['to'] : '';
$roDates = $roFrom !== '' && $roTo !== '' && $roTo >= $roFrom ? Roster::rangeDates($roFrom, $roTo, 93) : Roster::monthDates($roMonth);
$roGrid = $roRoster->grid($roShown, $roDates);
$columns = ['CODE', 'EMPLOYEE'];

foreach ($roDates as $roDate) {
    $columns[] = date('d-m', (int) strtotime($roDate)) . ' ' . strtoupper(substr(date('D', (int) strtotime($roDate)), 0, 2));
}

$rows = [];

foreach ($roShown as $e) {
    $line = [Employment::code($e), trim((string) ($e['name'] ?? ''))];

    foreach ($roDates as $roDate) {
        $cell = $roGrid[(string) $e['id']][$roDate];
        $line[] = (string) $cell['code'] . ($cell['rostered'] ? '*' : '');
    }

    $rows[] = $line;
}

$roPeriod = $roFrom !== '' && $roTo !== '' && $roTo >= $roFrom
    ? date('d-m-Y', (int) strtotime($roDates[0])) . ' to ' . date('d-m-Y', (int) strtotime($roDates[count($roDates) - 1]))
    : date('F Y', (int) strtotime($roMonth . '-01'));
$export = ['title' => 'Roster ' . $roPeriod . ($roSel === [] ? '' : ' · ' . count($roShown) . ' selected employee(s)') . ' (* = roster row, otherwise the scoped Working Shift)', 'filter' => $roFilter, 'columns' => $columns, 'rows' => $rows];
