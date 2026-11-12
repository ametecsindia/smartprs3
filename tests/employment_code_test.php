<?php

declare(strict_types=1);

/**
 * THE COLUMN THAT WAS NEVER THERE.
 *
 * SmartPRS 3's screens were written against `employee_code`. The ported
 * `employees` table calls it `emp_code`. Twenty-nine reads across the front
 * controller, the payroll register and full-and-final asked for a column that
 * does not exist — and every one of them worked for months, because the file
 * store returns whatever key it was handed and the fixtures used the same wrong
 * name. It surfaced the first time a real schema was involved.
 *
 * This test asserts against the SCHEMA rather than against a fixture, so it
 * cannot agree with the same mistake twice.
 */

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Store;

group('employment code');

// The name the migrations actually create. Read from the schema the migrations
// build, not from a constant somebody keeps in step by hand.
require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();

foreach (glob(__DIR__ . '/../database/migrations/*.php') as $file) {
    (require $file)->up();
}

$employees = Illuminate\Support\Facades\Schema::$blueprints['employees'] ?? null;

ok($employees !== null, 'the migrations create an employees table');
ok($employees->has(Employment::CODE_COLUMN), sprintf(
    'The column this application reads an employment code from (`%s`) must be one the migrations '
    . 'create. It was `employee_code` for months, which they never created.',
    Employment::CODE_COLUMN));
ok(!$employees->has(Employment::LEGACY_CODE_COLUMN),
    'and the old name must NOT be on the table — if it were, both would be right and neither would '
    . 'be authoritative.');

// The reader: ported rows, legacy fixtures, and rows with neither.
same('E-1001', Employment::code([Employment::CODE_COLUMN => 'E-1001']), 'a ported row');
same('E-1001', Employment::code(['employee_code' => 'E-1001']),
    'a fixture written before the port — read, so a naming defect does not become missing data');
same('E-1001', Employment::code(['emp_code' => 'E-1001', 'employee_code' => 'OLD']),
    'and the real column wins when a row somehow carries both');
same('', Employment::code(['name' => 'No Code']), 'a row with neither has no code');
same('', Employment::code(['emp_code' => '   ']), 'and whitespace is not a code');

// A blank is SAID, not printed. A payslip with no code on it is one nobody can
// match to a person, and a blank cell reads as a rendering fault instead.
same('no code recorded', Employment::codeOrSay(['name' => 'No Code']),
    'an absent code is stated rather than shown as an empty cell');
same('E-1001', Employment::codeOrSay(['emp_code' => 'E-1001']), 'and a real one is just the code');

// Labels name a person, never an id.
same('E-1001 · Kavita Sharma',
    Employment::label(['emp_code' => 'E-1001', 'name' => 'Kavita Sharma']), 'code and name');
same('Kavita Sharma', Employment::label(['name' => 'Kavita Sharma']), 'name alone when there is no code');
same('#7', Employment::label(['id' => 7]), 'and an id only when there is nothing else to say');

// THE WRITE PATH. The Employments screen wrote employee_code, gross, joined_on,
// bank_account and bank_ifsc — five columns, none of them on the table. The
// store refused all five the first time it saw MySQL; here the same refusal is
// asserted so the screen cannot drift back.
foreach (['employee_code', 'gross', 'joined_on', 'bank_account', 'bank_ifsc'] as $wrong) {
    ok(!$employees->has($wrong), sprintf(
        'The Employments screen used to write `%s`, which the schema does not have.', $wrong));
}

foreach ([Employment::CODE_COLUMN, 'ctc', 'doj', 'bank_acc', 'ifsc', 'status'] as $right) {
    ok($employees->has($right), sprintf(
        'and it now writes `%s`, which the schema does.', $right));
}
