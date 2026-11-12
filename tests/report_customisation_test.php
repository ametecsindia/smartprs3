<?php

declare(strict_types=1);

/**
 * REPORT CUSTOMISATION: the words a customer may change, and the two things they
 * must not.
 *
 * A report inherits the view permission of the module that declares its table,
 * and a money report the salary permission on top. So `table` and `money` are not
 * settings: a form able to change either would move the permission in front of
 * the figure, which is a privilege escalation with a settings screen in front of
 * it. And a `where` filter naming a column the table does not have matches no
 * rows — the report comes back empty, and an empty report reads as "there is
 * nothing to report" rather than as one wrong word. The last section proves the
 * overlay reaches `run()`, not only the settings page.
 */

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Reports;

group('report customisation');

$shipped = [
    'key' => 'headcount-by-department',
    'group' => 'People',
    'label' => 'Headcount by department',
    'question' => 'Where are our people?',
    'table' => 'employees',
    'group_by' => 'department_id',
    'measure' => 'count',
    'money' => false,
    'lookup' => ['table' => 'departments', 'value' => 'id', 'show' => ['name']],
    'where' => ['status' => ['active', 'probation', 'notice']],
];
$columns = ['id', 'emp_code', 'name', 'status', 'department_id', 'branch_id'];

// NON-VACUITY: the change an administrator actually wants must PASS. A checker
// that refuses everything passes every refusal below while being worthless.
$valid = [
    'label' => 'Staff by department',
    'question' => 'Where is everybody sitting?',
    'group' => 'Our people',
    'where' => ['status' => ['active']],
];
ok(Reports::reportProblems($valid, $columns, $shipped) === [],
    'Renaming a report, rewording its question, moving its group and narrowing its filter to a '
    . 'column that exists is the whole point and must pass.');
ok(Reports::reportProblems([], $columns, $shipped) === [], 'Changing nothing is not a problem.');

// THE REFUSAL THIS FILE EXISTS FOR: a filter on a column that is not there.
$problems = Reports::reportProblems(['where' => ['stauts' => ['active']]], $columns, $shipped);
ok($problems !== [],
    'A filter naming a column the table does not have must be refused: it matches no rows, so the '
    . 'report comes back empty and reads as "there is nothing to report".');
ok(str_contains($problems[0], 'stauts'),
    'and the refusal must NAME the column, because the fix is one word and nobody can guess which.');
ok(str_contains($problems[0], 'employees'),
    'and name the table it is not on, because that is where to go and look.');

// A filter with no values at all matches nothing either.
ok(Reports::reportProblems(['where' => ['status' => []]], $columns, $shipped) !== [],
    'A filter listing no values matches nothing and must be refused, not read as "every row".');

// Columns unobtainable is "cannot be checked", never "every column exists".
ok(Reports::reportProblems(['where' => ['status' => ['active']]], [], $shipped) !== [],
    'When the table\'s columns cannot be read, a filter is refused rather than accepted unchecked.');
ok(Reports::reportProblems(['label' => 'Staff'], [], $shipped) === [],
    'but that must not block a change that has nothing to do with the columns.');

// WHAT DECIDES THE PERMISSION IS NOT A SETTING. Refused BY NAME.
foreach (['table' => 'payslips', 'money' => true, 'lookup' => ['table' => 'payslips'],
    'measure' => 'sum', 'column' => 'ctc', 'group_by' => 'id', 'key' => 'other'] as $key => $value) {
    $refusal = Reports::reportProblems([$key => $value], $columns, $shipped);
    ok($refusal !== [], "Changing {$key} through a settings form must be refused.");
    ok(str_contains($refusal[0], $key), "and the refusal must name \"{$key}\".");
}

foreach (['label', 'question', 'group'] as $field) {
    ok(Reports::reportProblems([$field => '  '], $columns, $shipped) !== [],
        "Emptying the {$field} must be refused — a report nobody can find or read.");
}

// ---- the overlay actually reaches run(), not just the screen -----------------

$directory = sys_get_temp_dir() . '/prs3-report-' . bin2hex(random_bytes(4));
$store = (new SmartPRS\Surface\Store($directory))->forTenant('1');

foreach ([['id' => '1', 'name' => 'Asha', 'status' => 'active', 'department_id' => 'd1'],
    ['id' => '2', 'name' => 'Bala', 'status' => 'notice', 'department_id' => 'd1'],
    ['id' => '3', 'name' => 'Chitra', 'status' => 'exited', 'department_id' => 'd1']] as $row) {
    $store->put('employees', $row);
}

$file = $directory . '/reports.json';
file_put_contents($file, json_encode(['reports' => [$shipped]]));

$customisation = new Customisation($store);
$reports = new Reports($store, $file, null, $customisation);
$before = $reports->run((array) $reports->find('headcount-by-department'));

same(2, $before['counted'],
    'As shipped the report counts the two people who are active or on notice — the baseline the '
    . 'change is measured against.');

$result = $reports->customise('headcount-by-department',
    ['label' => 'Staff on the books', 'where' => ['status' => ['active']]], $shipped,
    '1', 'admin@example.com', 'we count only active staff');
ok($result['ok'], 'A valid customisation saves: ' . $result['why']);

$reports = new Reports($store, $file, null, new Customisation($store));
$live = (array) $reports->find('headcount-by-department');
$after = $reports->run($live);

same('Staff on the books', $live['label'], 'The overlay changes what the report is called.');
same(1, $after['counted'],
    'and the narrowed filter reaches run(): one person matches, not two. The overlay changes the '
    . 'FIGURE, not just the heading above it — a screen and a report disagreeing about what was '
    . 'counted is the worst place for a disagreement.');
ok(($live['customised'] ?? false) === true, 'and the report says it has been customised.');

same('employees', $reports->shipped('headcount-by-department')['table'],
    'The shipped report is unchanged by any customisation.');
same(['active', 'probation', 'notice'],
    $reports->shipped('headcount-by-department')['where']['status'],
    'including the filter it shipped with, which is what the next change is compared against.');

// A refusal at the point of saving, not only in the static checker.
$refused = $reports->customise('headcount-by-department', ['where' => ['nope' => ['x']]], $shipped,
    '1', 'admin@example.com', '');
ok(!$refused['ok'] && str_contains($refused['why'], 'nope'),
    'Saving a filter on a column that does not exist is refused and names the column.');

$refused = $reports->customise('headcount-by-department', ['money' => true], $shipped,
    '1', 'admin@example.com', '');
ok(!$refused['ok'],
    'Saving a change to `money` is refused: it would add the salary permission requirement, or '
    . 'take it away.');

array_map('unlink', (array) glob($directory . '/*'));
array_map('unlink', (array) glob($directory . '/*/*'));
