<?php

declare(strict_types=1);

/**
 * A REPORT IS AN AGGREGATE OF ROWS SOMEBODY MAY NOT BE ALLOWED TO READ.
 *
 * The reports screen shipped with no permission check on it at all, while
 * `Reports`' own docblock said "a report is exactly the feature somebody adds in
 * a hurry". Anybody signed in could open the total of every salary in the
 * company. Nobody thinks of a total as data — and a total by department with
 * three people in it is three salaries.
 */

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Reports;
use SmartPRS\Surface\Store;

group('report permissions');

$store = (new Store(sys_get_temp_dir() . '/prs3-rp-' . bin2hex(random_bytes(4))))->forTenant('1');
$modules = new Modules($store, __DIR__ . '/../config/modules.json');
$reports = new Reports($store, __DIR__ . '/../config/reports.json', $modules);

// NON-VACUITY: an ordinary report resolves to an ordinary permission, or every
// refusal below proves only that everything is refused.
$headcount = $reports->permissionsFor((array) $reports->find('headcount-by-department'));
same(null, $headcount['why'], 'a report over a declared table resolves');
same(['employee.view'], $headcount['permissions'],
    'and inherits the view permission of the module that declares its table');

// THE ONE THAT WAS OPEN TO EVERYBODY.
$ctc = $reports->permissionsFor((array) $reports->find('ctc-by-department'));
ok(in_array('employee.salary.view', $ctc['permissions'], true),
    'A report that totals salary needs the salary permission — this one was readable by anybody '
    . 'signed in.');
ok(in_array('employee.view', $ctc['permissions'], true),
    'and still needs the permission for the rows underneath it');

// Every shipped money report, not just the one I happened to look at.
foreach ($reports->all() as $report) {
    $gate = $reports->permissionsFor($report);

    ok($gate['why'] === null && $gate['permissions'] !== [], sprintf(
        'Report "%s" must resolve to at least one permission — a report nothing gates is one '
        . 'everybody can read.', (string) $report['key']));

    if ($report['money'] ?? false) {
        ok(in_array('employee.salary.view', $gate['permissions'], true), sprintf(
            'Money report "%s" must need the salary permission.', (string) $report['key']));
    }
}

// A table no module declares has nothing to inherit from, and "no rule found"
// must never read as "no rule applies".
$orphan = $reports->permissionsFor(['key' => 'x', 'table' => 'no_such_table']);
ok($orphan['why'] !== null, 'A report over a table no module declares must be refused.');
same([], $orphan['permissions'], 'and must not resolve to an empty, therefore satisfied, list');
ok(str_contains((string) $orphan['why'], 'no_such_table'), 'and must name the table');

// Without a module registry there is nothing to derive from — refused, not open.
$blind = new Reports($store, __DIR__ . '/../config/reports.json');
$noRegistry = $blind->permissionsFor(['key' => 'x', 'table' => 'employees']);
ok($noRegistry['why'] !== null,
    'With no module registry the permission cannot be worked out, so the report is refused rather '
    . 'than shown — an undeterminable rule is not an absent one.');
