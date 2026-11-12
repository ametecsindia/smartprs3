<?php

declare(strict_types=1);

/**
 * FIXED HOLIDAYS THAT REPEAT, AND HOLIDAYS THAT DO NOT REACH EVERYBODY.
 *
 * Two things this checks: a `recurring` holiday materializes on the same
 * month/day for any year asked, without being re-typed; and a holiday's
 * `scope` decides who it reaches — "Any" is the explicit wildcard, a
 * dimension with nothing checked (not even Any) reaches nobody, a holiday
 * with no `scope` key at all (shipped, or saved before this feature existed)
 * reaches everybody, and Role always comes from the employee's Designation,
 * never the RBAC access-tier field of the same name.
 */

use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Store;

group('calendar');

function calFresh(): array
{
    $store = (new Store(sys_get_temp_dir() . '/prs3-cal-' . bin2hex(random_bytes(4))))->forTenant('1');
    $customisation = new Customisation($store);
    $calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json', $customisation);

    return [$store, $customisation, $calendar];
}

// ---------------------------------------------------------- recurring materializes every year
[, , $calendar] = calFresh();
$h2026 = $calendar->holidays(2026);
$h2030 = $calendar->holidays(2030);
same("New Year's Day", $h2026['2026-01-01'] ?? null, 'New Year materializes for 2026');
same("New Year's Day", $h2030['2030-01-01'] ?? null, 'the same recurring row materializes for 2030 too, unedited');
same('Republic Day', $h2030['2030-01-26'] ?? null, 'Republic Day recurs');
same('Independence Day', $h2030['2030-08-15'] ?? null, 'Independence Day recurs');
same('Christmas', $h2030['2030-12-25'] ?? null, 'Christmas recurs');
ok(!isset($h2030['2030-03-04']), 'Holi is dated, not recurring — it does not show up in a year nobody entered it for');
same('Holi', $calendar->holidays(2026)['2026-03-04'] ?? null, 'Holi still shows for the year it was actually entered');

$hDefault = $calendar->holidays();
ok(isset($hDefault[(int) date('Y') . '-01-01']), 'no-arg holidays() materializes recurring ones for the current year too');

// ---------------------------------------------------------- no scope key reaches everybody
$legacyHoliday = ['on' => '2026-01-01', 'name' => "New Year's Day", 'recurring' => true];
$anyEmployment = ['employer_company_id' => 'c9', 'branch' => 'Nowhere', 'designation' => 'X', 'designation_id' => 'd9'];
ok(Calendar::holidayMatches($legacyHoliday, $anyEmployment, [], []), 'a holiday with no scope key at all reaches every employment — nothing already relying on it changes');

// ---------------------------------------------------------- "Any" is the explicit wildcard
$anyScoped = ['name' => 'X', 'scope' => ['company' => [Calendar::ANY], 'branch' => ['Pune'], 'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]]];
$pune = ['employer_company_id' => 'c1', 'branch' => 'Pune', 'designation' => 'Engineer', 'designation_id' => 'd1'];
$hyderabad = ['employer_company_id' => 'c1', 'branch' => 'Hyderabad', 'designation' => 'Engineer', 'designation_id' => 'd1'];
ok(Calendar::holidayMatches($anyScoped, $pune, ['c1' => 'Acme'], ['d1' => 'Engineering Role']), 'Pune branch matches a holiday scoped to Pune, everything else Any');
ok(!Calendar::holidayMatches($anyScoped, $hyderabad, ['c1' => 'Acme'], ['d1' => 'Engineering Role']), 'Hyderabad does not match the same holiday — the worked example from the request');

// ---------------------------------------------------------- blank dimension reaches nobody
$blankBranch = ['name' => 'X', 'scope' => ['company' => [Calendar::ANY], 'branch' => [], 'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]]];
ok(!Calendar::holidayMatches($blankBranch, $pune, [], []), 'a dimension with nothing checked — not even Any — reaches nobody in it, so the whole holiday reaches nobody');
ok(!Calendar::holidayMatches($blankBranch, $hyderabad, [], []), 'nobody means nobody, not "whoever is left"');

// ---------------------------------------------------------- every named dimension must match (AND)
$twoDims = ['name' => 'X', 'scope' => ['company' => [Calendar::ANY], 'branch' => ['Pune'], 'role' => [Calendar::ANY], 'designation' => ['Manager']]];
$puneEngineer = ['employer_company_id' => 'c1', 'branch' => 'Pune', 'designation' => 'Engineer', 'designation_id' => 'd1'];
$puneManager = ['employer_company_id' => 'c1', 'branch' => 'Pune', 'designation' => 'Manager', 'designation_id' => 'd2'];
ok(!Calendar::holidayMatches($twoDims, $puneEngineer, [], []), 'Pune branch matches but Designation does not — the AND fails the whole holiday');
ok(Calendar::holidayMatches($twoDims, $puneManager, [], []), 'both Branch and Designation match — the holiday reaches this one');

// ---------------------------------------------------------- role comes from Designation, never employees.role
$roleScoped = ['name' => 'X', 'scope' => ['company' => [Calendar::ANY], 'branch' => [Calendar::ANY], 'role' => ['Site Engineer'], 'designation' => [Calendar::ANY]]];
$employeeWithAccessRole = ['employer_company_id' => 'c1', 'branch' => 'Pune', 'designation' => 'Field Staff', 'designation_id' => 'd7',
    // The RBAC access tier — deliberately named the same as the job Role, and
    // deliberately NOT "Site Engineer", so a bug that reads this field instead
    // of the designation's Role would fail this assertion.
    'role' => 'administrator'];
$designationRoles = ['d7' => 'Site Engineer'];
ok(Calendar::holidayMatches($roleScoped, $employeeWithAccessRole, [], $designationRoles), 'Role resolves via the designation_roles map (job Role), matching despite employees.role saying something else entirely');
$otherDesignationRoles = ['d7' => 'Back Office'];
ok(!Calendar::holidayMatches($roleScoped, $employeeWithAccessRole, [], $otherDesignationRoles), 'a different job Role for the same designation correctly does not match');

// ---------------------------------------------------------- holidaysForEmployment combines scope + recurring materialization
[, , $calendar] = calFresh();
$scopedRecurring = [
    'on' => '2026-01-01', 'name' => "New Year's Day (Pune only)", 'type' => 'General', 'recurring' => true,
    'scope' => ['company' => [Calendar::ANY], 'branch' => ['Pune'], 'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]],
];
$puneReach = $calendar::holidayMatches($scopedRecurring, $pune, [], []);
same(true, $puneReach, 'sanity: the scoped row matches the Pune employment directly');

// ---------------------------------------------------------- saveCalendar: recurring + scope round-trip, and the blank-vs-legacy distinction
[$store, $customisation, $calendar] = calFresh();
// The form always renders "Any" pre-checked for a dimension nobody has
// narrowed — Branch is the only one actually restricted here, so Company,
// Role and Designation are submitted as Any exactly as the real screen would.
$save = $calendar->saveCalendar([
    ['on' => '2026-01-01', 'name' => "New Year's Day", 'type' => 'General', 'recurring' => '1',
        'scope_submitted' => '1', 'scope' => ['company' => [Calendar::ANY], 'branch' => ['Pune'],
            'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]]],
    ['on' => '2026-03-04', 'name' => 'Holi', 'type' => 'Festival'],
], ['Sun'], 'u1', 'admin@example.com');
ok($save['ok'], 'saveCalendar accepts a recurring, scoped row alongside a plain dated one: ' . ($save['why'] ?? ''));

$calendar = new Calendar(dirname(__DIR__) . '/config/calendar.json', $customisation);
$saved = $calendar->config()['holidays'];
$nyRow = null;
$holiRow = null;
foreach ($saved as $row) {
    $row['name'] === "New Year's Day" && $nyRow = $row;
    $row['name'] === 'Holi' && $holiRow = $row;
}
same(true, $nyRow['recurring'] ?? null, 'the recurring flag round-trips through save/load');
same(['company' => [Calendar::ANY], 'branch' => ['Pune'], 'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]], $nyRow['scope'] ?? null,
    'a row that submitted scope_submitted gets an explicit scope, Any where nothing was narrowed and the specific pick where it was');
ok(!isset($holiRow['scope']), 'a row saved WITHOUT scope_submitted (the plain-form case) carries no scope key — stays unrestricted rather than becoming "reaches nobody"');
ok(!isset($holiRow['recurring']), 'Holi was not marked recurring, so no flag is stored for it');

same("New Year's Day", $calendar->holidaysForEmployment($pune, [], [])['2027-01-01'] ?? null,
    'the saved Pune-only recurring holiday reaches a Pune employee in a future year');
ok(!isset($calendar->holidaysForEmployment($hyderabad, [], [])['2027-01-01']), 'and does not reach a Hyderabad employee — the exact example from the request');

// A dimension left with NOTHING checked, not even Any — the owner's own
// "if blank, it doesn't apply" rule, taken literally. Own fresh store: this
// save's holiday list is what it is given, not merged with the one above.
[, $customisationBlank, $calendarBlank] = calFresh();
$saveBlank = $calendarBlank->saveCalendar([
    ['on' => '2026-06-01', 'name' => 'Placeholder', 'type' => 'General',
        'scope_submitted' => '1', 'scope' => ['company' => [Calendar::ANY], 'branch' => [],
            'role' => [Calendar::ANY], 'designation' => [Calendar::ANY]]],
], [], 'u1', 'admin@example.com');
ok($saveBlank['ok'], 'a deliberately blank dimension is a valid save, not an error: ' . ($saveBlank['why'] ?? ''));
$calendarBlank = new Calendar(dirname(__DIR__) . '/config/calendar.json', $customisationBlank);
ok(!Calendar::holidayMatches($calendarBlank->config()['holidays'][0], $pune, [], []),
    'and it reaches nobody, exactly as configured');

// ---------------------------------------------------------- saveCalendar: a recurring duplicate month-day is refused
[, , $calendar] = calFresh();
$dup = $calendar->saveCalendar([
    ['on' => '2026-05-01', 'name' => 'Labour Day', 'type' => 'General', 'recurring' => '1'],
    ['on' => '2031-05-01', 'name' => 'Also Labour Day', 'type' => 'General', 'recurring' => '1'],
], [], 'u1', 'admin@example.com');
ok(!$dup['ok'], 'two recurring holidays cannot share the same month-day even if typed against different arbitrary years');
