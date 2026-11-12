<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\EmployeeFields;
use SmartPRS\Surface\Store;

/**
 * ONE LIST, THREE SCREENS.
 *
 * The Add form, the self-onboarding page and the bulk upload sample are all
 * built from `inUse()`. The assertions below are about the ways that list could
 * lie: offering a field the table has no column for, losing a field somebody
 * ticked, or letting the employment code be turned off — which would leave a
 * row that attendance, leave and payroll all point at by a value nobody entered.
 */

group('Employee field register');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/ef-' . bin2hex(random_bytes(4))))->forTenant('1');
$fields = new EmployeeFields($store, new Customisation($store),
    $root . '/config/employee-fields.json');

// NOTHING CONFIGURED IS NOT AN EMPTY FORM. A register with no fields would make
// the Add screen two boxes, which reads as broken rather than as unconfigured.
$starting = $fields->inUse();
ok(count($starting) > 5, 'an unconfigured tenant still gets a usable starting set');
ok(in_array('emp_code', array_column($starting, 'code'), true),
    'and the employment code is always in it');
ok(in_array('name', array_column($starting, 'code'), true), 'as is the name');

// EVERY FIELD SAYS WHERE IT IS KEPT. Not whether it MAY be kept — the customer
// decides that — but which shelf: a column on `employees`, or beside the record
// in `employee_field_values` when the table has no column for it.
foreach ($starting as $field) {
    ok(in_array((string) $field['storage'], ['column', 'extra'], true),
        "field {$field['code']} says where its value is kept");
    ok(in_array((string) $field['type'], SmartPRS\Surface\EmployeeFields::TYPES, true),
        "field {$field['code']} carries a data type the screens can render");
}

// ---------------------------------------------------------------------------
// REFUSALS. Each one is a way the register could accept something and then
// quietly do nothing with it.
// ---------------------------------------------------------------------------

ok($fields->refusals(['not_a_field' => ['required' => false]]) !== [],
    'a field outside config/employee-fields.json is refused');

// NOTHING IS REFUSED FOR WANT OF A COLUMN. Greying half the register out was the
// product telling a customer what they may know about their own staff; a field
// with no column is kept beside the record instead.
same([], $fields->refusals(['emp_code' => [], 'name' => [], 'mother_name' => []]),
    'a field the table has no column for is still the customer\'s to capture');

// A data type the screens cannot render IS refused, because the type decides the
// control on three screens and the type column in the upload sample.
ok($fields->refusals(['emp_code' => [], 'name' => [],
    'father' => ['type' => 'Interpretive Dance']]) !== [],
    'a data type nothing can render is refused');

// THE CORE FIELDS CANNOT BE TURNED OFF.
$withoutCode = $fields->refusals(['name' => []]);
ok($withoutCode !== [], 'the employment code cannot be turned off');
ok(str_contains(implode(' ', $withoutCode), 'keyed by it'),
    'and the reason says what else in the product would break');

// ---------------------------------------------------------------------------
// SAVING. The selection is a SET: a field left out was turned off.
// ---------------------------------------------------------------------------

$saved = $fields->save([
    'emp_code' => [],
    'name' => [],
    'father' => ['required' => true, 'type' => 'Alpha Numeric'],
    'mother_name' => ['type' => 'Text'],
], '1', 'tester@example.com');
same(true, $saved['ok'], 'a valid selection saves');

// A NEW READER, because the point is that the choice PERSISTS — the object that
// saved it holding the answer in memory would prove nothing.
$again = new EmployeeFields($store, new Customisation($store),
    $root . '/config/employee-fields.json');
$codes = array_column($again->inUse(), 'code');

same(['emp_code', 'name', 'father', 'mother_name'], $codes, 'exactly what was ticked is what the '
    . 'three screens show — and the fields left out are gone, because the absence of a tick is the '
    . 'instruction');

// THE CUSTOMER'S DATA TYPE SURVIVES, and it is what the three screens render by.
$byCode = [];

foreach ($again->inUse() as $field) {
    $byCode[(string) $field['code']] = $field;
}

same('Alpha Numeric', (string) $byCode['father']['type'],
    'the chosen data type is what comes back — not the catalogue\'s suggestion');
same('extra', (string) $byCode['mother_name']['storage'],
    'a field with no column is captured and kept beside the record');
same('column', (string) $byCode['name']['storage'], 'and one with a column is kept in it');

$father = array_values(array_filter($again->inUse(),
    static fn (array $f): bool => $f['code'] === 'father'))[0];
same(true, (bool) $father['required'], 'and required survives the round trip');

// The sample file, the Add form and the self-onboarding page all read the same
// method — so this one assertion is what keeps the three in step.
same($again->inUse(), $again->inUse(), 'the list is the same list every time it is asked for');

// ---------------------------------------------------------------------------
// A MISSING CATALOGUE IS REFUSED, not read as "this tenant keeps nothing".
// ---------------------------------------------------------------------------

throws(RuntimeException::class, static function () use ($store, $root): void {
    (new EmployeeFields($store, new Customisation($store), $root . '/config/no-such-file.json'))
        ->sections();
}, 'a missing catalogue is refused rather than treated as an empty register');

// Every declared field names a control the screens can actually render, and a
// select declares its options — a dropdown with nothing in it is a field nobody
// can fill in.
foreach ($fields->catalogue()['sections'] as $section) {
    foreach ($section['fields'] as $field) {
        ok(in_array((string) $field['control'],
            ['text', 'date', 'number', 'select', 'lookup', 'document', 'derived'], true),
            "field {$field['code']} names a control the form can render");

        if ((string) $field['control'] === 'select') {
            ok(($field['options'] ?? []) !== [],
                "select field {$field['code']} declares its options");
        }

        if ((string) $field['control'] === 'lookup') {
            ok(($field['lookup'] ?? '') !== '',
                "lookup field {$field['code']} names the table it looks up");
        }
    }
}

// ------------------------------------------------- the two types the owner asked for
same(['Text', 'Numeric', 'Alpha Numeric', 'Date', 'Boolean (Yes/No/NA)', 'List', 'Email', 'Image/PDF/Doc'],
    EmployeeFields::TYPES, 'Email and Image/PDF/Doc are data types a customer may choose');
$declaredTypes = json_decode((string) file_get_contents(dirname(__DIR__) . '/config/employee-fields.json'), true)['data_types'];
same(EmployeeFields::TYPES, $declaredTypes, 'and the declared list agrees with the class');
$shippedTypes = [];
foreach ($fields->catalogue()['sections'] as $section) {
    foreach ($section['fields'] as $field) {
        $shippedTypes[(string) $field['code']] = (string) $field['type'];
    }
}
same('Email', $shippedTypes['email'], 'the official email ships as an Email field');
same('Image/PDF/Doc', $shippedTypes['photo_path'], 'the photograph ships as a file field, not a typed path');
