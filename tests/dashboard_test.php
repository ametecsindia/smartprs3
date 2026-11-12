<?php

declare(strict_types=1);

/**
 * A DASHBOARD IS THE EASIEST PLACE IN AN HR SYSTEM TO LEAK A NUMBER, because
 * nobody thinks of a summary as data. The reports screen puts total CTC behind
 * the salary permission; a tile showing the same total to anybody who can open
 * the landing page hands it straight back.
 *
 * And a tile whose report is missing must never read as zero. A real zero on a
 * dashboard gets acted on — nobody took leave, nothing is pending, everything is
 * settled — so "0" and "there is no figure" must not look the same.
 */

use SmartPRS\Surface\Dashboard;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Reports;
use SmartPRS\Surface\Store;

group('dashboard');

$store = (new Store(sys_get_temp_dir() . '/prs3-dash-' . bin2hex(random_bytes(4))))->forTenant('1');

foreach ([
    ['emp_code' => 'E1', 'name' => 'One', 'status' => 'active', 'ctc' => '1200000'],
    ['emp_code' => 'E2', 'name' => 'Two', 'status' => 'active', 'ctc' => '800000'],
    ['emp_code' => 'E3', 'name' => 'Three', 'status' => 'exited', 'ctc' => '500000'],
] as $row) {
    $store->put('employees', $row);
}

$modules = new Modules($store, __DIR__ . '/../config/modules.json');
$reports = new Reports($store, __DIR__ . '/../config/reports.json', $modules);
// The live dashboard lost its Annual CTC tile (owner, 22 Oct 2026); the leak this file guards is still
// real for any money tile, so the test declares one beside the shipped tiles.
$declared = json_decode((string) file_get_contents(__DIR__ . '/../config/dashboard.json'), true);
$declared['kpis'][] = ['key' => 'ctc', 'label' => 'Annual CTC', 'report' => 'ctc-by-department'];
$dashFile = sys_get_temp_dir() . '/prs3-dash-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($dashFile, json_encode($declared));
$dashboard = new Dashboard($reports, $dashFile);

$everything = static fn (string $p): bool => true;
$nothing = static fn (string $p): bool => false;
$noSalary = static fn (string $p): bool => $p !== 'employee.salary.view';

$byKey = static function (array $kpis): array {
    $out = [];

    foreach ($kpis as $kpi) {
        $out[(string) $kpi['key']] = $kpi;
    }

    return $out;
};

// NON-VACUITY: with every permission the figures are real, or every lock below
// proves only that everything is locked.
$open = $byKey($dashboard->kpis($everything));
same(3, $open['headcount']['value'], 'the headcount tile counts every employment');
same(2, $open['active']['value'], 'and a bucket tile takes one row of the same report');
ok($open['ctc']['value'] > 0, 'and the cost tile has a figure');
ok($open['ctc']['money'] === true, 'which is money, so it formats as rupees');
same(null, $open['headcount']['why'], 'nothing to report on a tile that worked');

// THE LEAK THIS FILE EXISTS FOR.
$limited = $byKey($dashboard->kpis($noSalary));
ok($limited['ctc']['locked'] === true,
    'A tile fed by a report the viewer cannot open must be LOCKED — the reports screen refusing '
    . 'the total is worth nothing if the dashboard prints it.');
same(null, $limited['ctc']['value'],
    'and it must carry no value at all, not a value the template is trusted to hide');
ok($limited['headcount']['locked'] === false,
    'while a tile they may see is unaffected — this is not "lock everything"');

$sealed = $byKey($dashboard->kpis($nothing));

foreach ($sealed as $kpi) {
    ok($kpi['locked'] === true && $kpi['value'] === null, sprintf(
        'With no permissions at all, "%s" shows no figure.', (string) $kpi['key']));
}

// A MISSING REPORT IS NOT A ZERO.
$broken = new Dashboard($reports, __DIR__ . '/../config/dashboard.json');
$missing = (new ReflectionClass($broken))->getMethod('figure');
$missing->setAccessible(true);
$result = $missing->invoke($broken, ['report' => 'no-such-report'], $everything);

same(null, $result['value'],
    'A tile whose report does not exist must have NO value — 0 there is indistinguishable from a '
    . 'real zero, and a real zero on a dashboard gets acted on.');
ok(str_contains((string) $result['why'], 'no-such-report'),
    'and it must name the report, since fixing it is a one-word edit to the declaration');

// A bucket the report never produced IS a real zero, and says so.
$zero = $missing->invoke($broken,
    ['report' => 'headcount-by-status', 'bucket' => 'never-used'], $everything);
same(0, $zero['value'], 'a bucket with no rows is a genuine zero');
ok(str_contains((string) $zero['why'], 'real zero'),
    'and it says which kind of zero it is, so the reader does not have to guess');

// Panels obey the same lock.
$panels = [];

foreach ($dashboard->panels($noSalary) as $panel) {
    $panels[(string) $panel['key']] = $panel;
}

ok($panels['cost']['locked'] === true, 'a panel fed by a locked report is locked');
same([], $panels['cost']['rows'], 'and carries no rows');
ok($panels['by-department']['locked'] === false, 'while the others still render');

// Every declared tile must name a report that exists — checked here rather than
// discovered as a dash on somebody's landing page.
foreach ($dashboard->kpis($everything) as $kpi) {
    ok($kpi['value'] !== null || $kpi['locked'], sprintf(
        'Tile "%s" names a report that must exist.', (string) $kpi['key']));
}
