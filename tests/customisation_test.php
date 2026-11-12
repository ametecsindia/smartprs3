<?php

declare(strict_types=1);

/**
 * CUSTOMISATION: the overlay, and — more importantly — what it REFUSES.
 *
 * The refusals are the point. An overlay that lets an administrator repoint a
 * module's table or drop its permission is not a customisation feature, it is a
 * privilege escalation with a form in front of it.
 *
 * The non-vacuity probe at the end deliberately feeds a valid override through
 * `problems()` and asserts it comes back empty, because a checker that rejects
 * everything passes every "it refuses X" test while being useless.
 */

use SmartPRS\Surface\Customisation;

group('customisation');

$shipped = [
    'key' => 'employees',
    'label' => 'Employments',
    'table' => 'employees',
    'columns' => ['emp_code', 'name', 'status'],
    'search' => ['emp_code', 'name'],
    'permissions' => ['view' => 'employee.view', 'edit' => 'employee.update'],
    'fields' => [
        'emp_code' => ['label' => 'Employee code', 'required' => true],
        'name' => ['label' => 'Name', 'required' => true],
        'status' => ['label' => 'Status', 'options' => ['active', 'exited']],
    ],
];

// A change an administrator would actually make: their own word for a field,
// their own list of statuses, one column added to the list.
$valid = [
    'label' => 'Staff',
    'columns' => ['emp_code', 'name', 'status'],
    'fields' => [
        'emp_code' => ['label' => 'Token no.'],
        'status' => ['options' => ['on roll', 'off roll']],
    ],
];

// NON-VACUITY: a checker that refuses everything would pass every refusal test
// below while being worthless. This asserts it accepts the valid case.
ok(Customisation::problems($valid, $shipped) === [],
    'A valid customisation must pass, or every refusal below proves nothing.');

// Structural keys are refused, and NAMED. `table` is the one that matters: it
// would point a screen at another table through a settings form.
$table = Customisation::problems(['table' => 'payslips'], $shipped);
ok(count($table) === 1 && str_contains($table[0], 'table'),
    'Repointing a module at another table must be refused by name.');
ok(Customisation::problems(['permissions' => []], $shipped) !== [],
    'Removing the permission in front of a module must be refused.');

// A column nobody has a field for shows blank cells that read as missing data.
ok(Customisation::problems(['columns' => ['nonexistent']], $shipped) !== [],
    'A column the module has no field for must be refused, not ignored.');
ok(Customisation::problems(['columns' => []], $shipped) !== [],
    'A module with no columns is a list of blank rows.');
ok(Customisation::problems(['search' => ['nope']], $shipped) !== [],
    'Searching a column that does not exist must be refused.');

// Required + hidden: every save refused for a box nobody can see.
ok(Customisation::problems(['fields' => ['name' => ['required' => true, 'hidden' => true]]],
    $shipped) !== [], 'Required and hidden together must be caught here, not at save time.');

// A lookup cannot be redeclared through the form — that is the tenant-scoped
// read path, and pointing it elsewhere is the same defect as repointing a table.
ok(Customisation::problems(['fields' => ['status' => ['lookup' => ['table' => 'users']]]],
    $shipped) !== [], 'A lookup target must not be settable through a customisation.');
ok(Customisation::problems(['fields' => ['no_such' => ['label' => 'x']]], $shipped) !== [],
    'Customising a field that does not exist must be refused.');

// The overlay itself: fields MERGE, they do not replace.
$store = new SmartPRS\Surface\Store(sys_get_temp_dir() . '/prs3-custom-' . bin2hex(random_bytes(4)));
$store = $store->forTenant('1');
$store->put('module_customisations', ['module_key' => 'employees', 'version' => 1,
    'payload' => json_encode(['label' => 'Staff',
        'fields' => ['emp_code' => ['label' => 'Token no.']]])]);
// A second, higher version wins; the first is kept and not overwritten.
$store->put('module_customisations', ['module_key' => 'employees', 'version' => 2,
    'payload' => json_encode(['fields' => ['emp_code' => ['label' => 'Token number']]])]);

$applied = (new Customisation($store))->apply($shipped);

ok($applied['fields']['emp_code']['label'] === 'Token number',
    'The highest version must be the live one.');
ok($applied['fields']['emp_code']['required'] === true,
    'Overriding a label must not discard the rest of the field — fields merge, they do not replace.');
ok($applied['fields']['status']['options'] === ['active', 'exited'],
    'A field nobody customised must be untouched.');
ok($applied['table'] === 'employees' && $applied['permissions']['edit'] === 'employee.update',
    'Nothing structural may change through the overlay, whatever is in the payload.');
ok(($applied['customised'] ?? false) === true,
    'A customised module must say so, so a screen that differs from the shipped one admits it.');

// The version that is no longer live is still there to be read.
ok(count((new Customisation($store))->history('employees')) === 2,
    'Every version is kept: "what did it say before?" is asked when something has gone wrong.');

// ---- what has this customer changed, everywhere ----------------------------

group('configuration inventory');

$inv = new SmartPRS\Surface\Store(sys_get_temp_dir() . '/prs3-inv-' . bin2hex(random_bytes(4)));
$inv = $inv->forTenant('1');
$c = new Customisation($inv);

$c->saveRaw('employees', ['label' => 'Staff'], '1', 'ejaz@ametecsindia.com', 'our word for it');
$c->saveRaw('employees', ['label' => 'Staff on roll'], '1', 'ejaz@ametecsindia.com', 'second pass');
$c->saveRaw('workflow:expense-claim', ['steps' => ['finance' => ['when_amount_over' => 25000]]],
    '1', 'ejaz@ametecsindia.com', 'board raised the limit');
$c->saveRaw('letter:offer', ['label' => 'Letter of offer'], '1', 'ejaz@ametecsindia.com', '');
// A customisation for something no longer declared anywhere.
$c->saveRaw('module:retired-thing', ['label' => 'Gone'], '1', 'ejaz@ametecsindia.com', '');

$known = ['employees' => 'Employments', 'workflow:expense-claim' => 'Expense claim',
    'letter:offer' => 'Offer letter'];
$rows = $c->inventory($known);

same(4, count($rows), 'one row per customised thing, whatever its kind — not one per version');

$byKey = [];

foreach ($rows as $row) {
    $byKey[$row['kind'] . ':' . $row['key']] = $row;
}

same(2, $byKey['module:employees']['version'], 'the live version is the highest');
same(2, $byKey['module:employees']['versions'], 'and every version is counted, not just the live one');
same('Employments', $byKey['module:employees']['label'], 'described by what it actually is');
same('workflow', $byKey['workflow:expense-claim']['kind'], 'workflows are their own kind');
same('letter', $byKey['letter:offer']['kind'], 'and so are letters');

// THE ONE THAT MATTERS: a change in force against nothing.
ok($byKey['module:retired-thing']['orphan'] === true,
    'A customisation whose key is no longer declared anywhere must be REPORTED, not filtered out: '
    . 'it sits in the table doing nothing for ever while somebody believes their change is live.');
ok($byKey['module:employees']['orphan'] === false, 'and a live one is not called an orphan');
same('module', $rows[0]['kind'], 'orphans sort to the top, because they are the finding');
ok($rows[0]['orphan'] === true, 'and they are the first thing on the screen');
