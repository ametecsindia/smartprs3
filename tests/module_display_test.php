<?php

declare(strict_types=1);

/**
 * HOW A LIST LOOKS, DERIVED ONCE.
 *
 * SmartPRS 2 copies the avatar tints, the status-badge map and the rupee
 * formatting into every index view, so a status colour that is wrong is wrong on
 * one screen and right on the others. Here it is derived from the column names
 * and overridden per module — which means the eighty-four modules nobody has
 * hand-styled still get badges and rupees.
 *
 * And a stat tile is an aggregate, so it obeys the same rule the dashboard
 * taught: a summary nobody thinks of as data is the easiest place to hand back a
 * figure the rest of the product locks.
 */

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Store;

group('module display');

$store = (new Store(sys_get_temp_dir() . '/prs3-disp-' . bin2hex(random_bytes(4))))->forTenant('1');

foreach ([
    ['emp_code' => 'E1', 'name' => 'One', 'status' => 'active', 'ctc' => '1200000'],
    ['emp_code' => 'E2', 'name' => 'Two', 'status' => 'probation', 'ctc' => '600000'],
    ['emp_code' => 'E3', 'name' => 'Three', 'status' => 'exited', 'ctc' => '400000'],
] as $row) {
    $store->put('employees', $row);
}

$modules = new Modules($store, __DIR__ . '/../config/modules.json');
$employees = (array) $modules->find('employees');
$look = $modules->display($employees);

// The declared block wins where it exists.
same('name', $look['avatar'], 'the name column carries the avatar');
same('green', $look['badges']['status']['active'], 'active reads green');
same('red', $look['badges']['status']['exited'], 'exited reads red');
ok(in_array('ctc', $look['money'], true), 'ctc is formatted as money');

// DERIVED for the modules nobody hand-styled — which is most of them. Without
// this, a scheme that needs a per-module block stays unapplied on sixty screens.
$derivedCount = 0;

foreach ($modules->all() as $module) {
    $d = $modules->display($module);

    if ($d['badges'] !== [] || $d['money'] !== []) {
        $derivedCount++;
    }
}

ok($derivedCount > 30, sprintf(
    'Badges and money must be DERIVED across the registry, not hand-written: got %d of %d modules '
    . 'styled without anybody writing a display block for them.',
    $derivedCount, count($modules->all())));

// A status column anywhere gets the same colours, so one word does not mean
// green on one screen and grey on another.
$leaves = $modules->display((array) $modules->find('leaves'));
ok(isset($leaves['badges']['status']),
    'A status column on any module becomes a badge without being declared.');

// Money is recognised by what the column means, not by a list somebody keeps.
foreach (['loans' => 'outstanding', 'advances' => 'amount'] as $key => $column) {
    $module = $modules->find($key);

    if ($module !== null && in_array($column, (array) $module['columns'], true)) {
        ok(in_array($column, $modules->display($module)['money'], true), sprintf(
            '`%s` on %s must format as rupees without a declaration.', $column, $key));
    }
}

// ---- stats obey permissions ------------------------------------------------

$everything = static fn (string $p): bool => true;
$noSalary = static fn (string $p): bool => $p !== 'employee.salary.view';
$byLabel = static function (array $stats): array {
    $out = [];

    foreach ($stats as $stat) {
        $out[(string) $stat['label']] = $stat;
    }

    return $out;
};

// NON-VACUITY first: with every permission the figures are real.
$open = $byLabel($modules->stats($employees, $everything));
same(3, $open['Employees']['value'], 'counts every row');
same(1, $open['Active']['value'], 'and a filtered stat counts only its own');
same('2200000.00', $open['Annual CTC']['value'], 'and a sum adds the column up');
ok($open['Annual CTC']['locked'] === false, 'unlocked for somebody who may see salary');

// THE ONE THAT MATTERS.
$limited = $byLabel($modules->stats($employees, $noSalary));
ok($limited['Annual CTC']['locked'] === true,
    'A stat that totals salary must lock for somebody without the salary permission — a tile above '
    . 'a list is an aggregate, and the report screen refusing the same total is worth nothing if '
    . 'this one prints it.');
same(null, $limited['Annual CTC']['value'],
    'and it must carry no value at all, not one the template is trusted to hide');
ok($limited['Employees']['locked'] === false, 'while a headcount tile is unaffected');

// ---- a locked FIELD, not just a locked screen -------------------------------

group('field permissions');

$sensitive = new Modules($store, __DIR__ . '/../config/modules.json', null, $everything);
$restricted = new Modules($store, __DIR__ . '/../config/modules.json', null, $noSalary);
$emp = (array) $sensitive->find('employees');

$names = static fn (array $spec): array
    => array_map(static fn (array $f): string => $f['name'], $spec['fields']);

// NON-VACUITY: with the permission, the field is there.
ok(in_array('ctc', $names($sensitive->fields($emp)), true),
    'Somebody who may see salary gets the salary field.');

// THE HOLE THIS CLOSES: the report was locked, the stat tile was locked, and the
// column was printed in every row. Found by reading the rendered page: the tile
// said "not yours to see" and the table beside it said ₹42,00,000.
$open = $restricted->fields($emp);
ok(!in_array('ctc', $names($open), true),
    'A field with its own permission must disappear for somebody who lacks it — locking the '
    . 'aggregate and printing the column hands the figure back one row at a time.');
ok(in_array('ctc', $open['locked'], true),
    'and it must be NAMED as locked, so the list can show a padlock rather than a blank cell that '
    . 'reads as missing data');
ok(in_array('name', $names($open), true), 'while the ordinary fields are untouched');

// AND IT MUST NOT BE WRITABLE. A field hidden from a template but accepted by
// the save is a lock on the door of a room with no wall.
$before = $store->put('employees', ['emp_code' => 'E9', 'name' => 'Nine', 'status' => 'active',
    'doj' => '2026-01-01', 'ctc' => '100000']);
$whole = ['id' => $before['id'], 'emp_code' => 'E9', 'name' => 'Nine', 'status' => 'active',
    'doj' => '2026-01-01'];

// The write is checked to have SUCCEEDED, not merely to have left the value
// alone. Both saves failing for a missing required field would pass the
// assertion below while proving nothing — which is exactly what happened on the
// first run of this test.
$blocked = $restricted->save($emp, $whole + ['ctc' => '9999999']);
ok($blocked['ok'], 'the restricted save must succeed as a save: ' . $blocked['why']);
$after = $store->find('employees', (string) $before['id']);

same('100000', (string) $after['ctc'],
    'Somebody who cannot SEE a field must not be able to WRITE it either — a form that omits the '
    . 'box while the save accepts the parameter is protection against the template, not against a '
    . 'request.');

// With the permission, the same write goes through — or the assertion above
// proves only that saving is broken.
$allowed = $sensitive->save($emp, $whole + ['ctc' => '250000']);
ok($allowed['ok'], 'and the permitted save must succeed too: ' . $allowed['why']);
same('250000', (string) $store->find('employees', (string) $before['id'])['ctc'],
    'and somebody who may see it can still change it');

// The default is DENY. A Modules built without a permission callable must not
// hand out permissioned fields — a field-level lock that defaults to open is
// open everywhere it was not explicitly wired.
$unwired = new Modules($store, __DIR__ . '/../config/modules.json');
ok(!in_array('ctc', $names($unwired->fields($emp)), true),
    'With nobody to ask, a permissioned field is refused rather than shown.');

// A PARTIAL WRITE MUST NOT ERASE THE COLUMNS IT DID NOT SEND. The two stores
// disagreed about this: SqlStore issues UPDATE ... SET for the columns it was
// given, and the file store overwrote the whole document — so the same save
// updated a name on MySQL and updated the name while blanking everything else on
// files. Found through the lock above, where refusing to write a salary erased it.
$kept = $store->put('employees', ['emp_code' => 'E10', 'name' => 'Ten', 'status' => 'active',
    'ctc' => '750000', 'doj' => '2026-02-02']);
$store->put('employees', ['id' => $kept['id'], 'name' => 'Ten Renamed']);
$reread = $store->find('employees', (string) $kept['id']);

same('Ten Renamed', (string) $reread['name'], 'the column that was sent is updated');
same('750000', (string) $reread['ctc'],
    'and a column the caller never mentioned survives — a lock that deletes the thing it protects '
    . 'is worse than no lock.');
same('E10', (string) $reread['emp_code'], 'as does every other untouched column');
