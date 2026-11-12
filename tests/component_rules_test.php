<?php

declare(strict_types=1);

use SmartPRS\Surface\ComponentRules;
use SmartPRS\Surface\Store;

/**
 * A RULE THAT APPLIES TO NOBODY IS A DEDUCTION SOMEBODY THINKS THEY CONFIGURED.
 *
 * Every assertion below is about a way this screen could accept something and
 * then quietly do nothing with it at payroll time. The refusals are the
 * feature: the grid is easy, and the list of things it will not let you publish
 * is what makes it safe to hand to somebody who is not a developer.
 */

group('Salary component rules');

$root = dirname(__DIR__);
$rules = new ComponentRules(
    (new Store(sys_get_temp_dir() . '/cr-' . bin2hex(random_bytes(4))))->forTenant('1'),
    $root . '/config/salary-components.json',
);

// A COMPONENT NOBODY DECLARED. `config/salary-components.json` is the catalogue,
// and a code outside it is one payroll would skip without saying so.
$refused = $rules->refusals([['component' => 'MYSTERY_ALLOWANCE', 'calculation' => 'flat',
    'amount' => '100']]);
same(1, count($refused), 'a component outside the catalogue is refused');
ok(str_contains($refused[0], 'salary-components.json'), 'and the reason names the file to fix');

// AN EXACT PERCENTAGE, OR NONE. `(float) '40%'` is 40.0 and `(float) 'forty'` is
// 0.0, and both multiply somebody's salary without complaining.
same(1, count($rules->refusals([['component' => 'HRA', 'calculation' => 'percent_of',
    'percent' => '40', 'basis_column' => '']])), 'a percentage with no basis is refused — 40% of '
    . 'CTC and 40% of Basic are different amounts');
ok($rules->refusals([['component' => 'HRA', 'calculation' => 'percent_of', 'percent' => 'forty',
    'basis_column' => 'BASIC']]) !== [], 'a percentage that is not an exact decimal is refused');
same([], $rules->refusals([['component' => 'HRA', 'calculation' => 'percent_of',
    'percent' => '40', 'basis_column' => 'BASIC']]), 'and an exact one is accepted');
same(1, count($rules->refusals([['component' => 'HRA', 'calculation' => 'percent_of',
    'percent' => '40.5', 'basis_column' => 'BASIC']])), 'a fractional rate the engine cannot compute is refused at '
    . 'publish (28 Sep) — it used to publish and then abort every payroll close');

// TWO RULES FOR THE SAME COMPONENT AND THE SAME SCOPE. Both apply, only one can
// win, and which one is whatever the sort returned first.
$duplicate = [
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '1600', 'branch' => 'All'],
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '2400', 'branch' => 'All'],
];
ok($rules->refusals($duplicate) !== [], 'the same component twice for the same scope is refused');

// BUT DIFFERENT SCOPES ARE THE WHOLE POINT — this is the case the version list
// above the grid cannot express at all.
same([], $rules->refusals([
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '1600'],
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '2400',
        'branch' => 'Mumbai - Andheri East'],
]), 'the same component at two different scopes is exactly what this screen is for');

// TWO BALANCES IS AN UNSOLVABLE STRUCTURE.
ok($rules->refusals([
    ['component' => 'SPECIAL', 'calculation' => 'balance'],
    ['component' => 'OTHER_EARNING', 'calculation' => 'balance'],
]) !== [], 'two components cannot both absorb the balance');

// A FIELD THE `employees` TABLE DOES NOT HAVE. A rule against it applies to
// nobody, and says nothing about it.
ok($rules->refusals([['component' => 'PCC_DEDUCTION', 'calculation' => 'employee_status',
    'status_field' => 'favourite_colour']]) !== [], 'a status rule against an undeclared field is '
    . 'refused');
same([], $rules->refusals([['component' => 'PCC_DEDUCTION', 'calculation' => 'employee_status',
    'status_field' => 'pcc_status', 'status_amount' => '500']]), 'and a declared one is accepted');
ok($rules->refusals([['component' => 'PCC_DEDUCTION', 'calculation' => 'employee_status',
    'status_field' => 'pcc_status']]) !== [], 'a status rule with no amount is refused');

// ---------------------------------------------------------------------------
// PUBLISHING. Effective-dated, never overwritten, and specificity derived.
// ---------------------------------------------------------------------------

$first = $rules->publish([
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '1600'],
    ['component' => 'CONVEYANCE', 'calculation' => 'flat', 'amount' => '2400',
        'branch' => 'Mumbai - Andheri East', 'designation' => 'Recovery Manager'],
], '2026-04-01', 'tester');

same(2, $first['published'], 'both rules published');
same(0, $first['closed'], 'nothing to close on a first publish');

$inForce = $rules->inForce('2026-04-15');
same(2, count($inForce), 'both are in force in April');
// MOST SPECIFIC FIRST, and the specificity is COUNTED rather than typed — one
// fact stored twice is one copy that decides which rule wins while being wrong.
same('2400', (string) $inForce[0]['flat_amount'],
    'the branch-and-designation rule sorts ahead of the tenant-wide one');
same(2, (int) $inForce[0]['specificity'], 'specificity is derived from the scopes actually set');
same(0, (int) $inForce[1]['specificity'], 'a rule with no scope set applies to everybody');

// A CHANGE CLOSES THE OLD ROWS AND OPENS NEW ONES. This is the assertion that
// makes a reprinted March payslip agree with the copy the employee already has.
$second = $rules->publish([['component' => 'CONVEYANCE', 'calculation' => 'flat',
    'amount' => '3000']], '2026-05-01', 'tester');

same(2, $second['closed'], 'the April rules were closed, not deleted');
same(1, count($rules->inForce('2026-05-15')), 'May sees only the new rule');
same('3000', (string) $rules->inForce('2026-05-15')[0]['flat_amount'], 'at the new amount');
same(2, count($rules->inForce('2026-04-15')),
    'and April still sees April — the closed rows are how April was computed');
same(3, count($rules->all()), 'every rule ever published stays as the audit trail');

// A REFUSED SET PUBLISHES NOTHING — including the rows that were fine. A partial
// publish leaves a structure that is neither the old one nor the new one.
$before = count($rules->all());
$partial = $rules->publish([
    ['component' => 'BASIC', 'calculation' => 'percent_of', 'percent' => '50',
        'basis_column' => 'CTC'],
    ['component' => 'NOT_A_COMPONENT', 'calculation' => 'flat', 'amount' => '1'],
], '2026-06-01', 'tester');

same(0, $partial['published'], 'nothing is published while any row is refused');
same(0, $partial['closed'], 'and nothing already in force is closed either');
same($before, count($rules->all()), 'the stored rules are untouched');
same(1, count($rules->inForce('2026-06-15')), 'June still has the rule that was working');

// THE CATALOGUE IS A FILE, AND A MISSING ONE IS REFUSED RATHER THAN READ AS
// "no components" — an empty screen looks like a tenant nobody has configured.
throws(RuntimeException::class, static function () use ($root): void {
    (new ComponentRules(
        (new Store(sys_get_temp_dir() . '/cr2-' . bin2hex(random_bytes(4))))->forTenant('1'),
        $root . '/config/no-such-catalogue.json',
    ))->components();
}, 'a missing catalogue is refused, not treated as an empty one');

// Every component names a kind the catalogue explains, because a kind is not a
// label: an earning in the deductions column is a payslip that adds up and is
// wrong.
$kinds = array_keys((array) $rules->catalogue()['kinds']);

foreach ($rules->components() as $code => $component) {
    ok(in_array((string) $component['kind'], $kinds, true),
        "component {$code} declares a kind the catalogue explains");
}
