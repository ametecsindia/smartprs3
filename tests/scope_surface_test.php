<?php

declare(strict_types=1);

/**
 * WHOSE ROW IS THIS — the question the surface never asked.
 *
 * Tenant isolation was structural and proven. Isolation WITHIN a tenant did not
 * exist: every signed-in person could read every employment in their own
 * organisation, including on `?r=me`, the screen titled "what an employee sees",
 * which rendered anybody's payslip and complete leave ledger to anybody who
 * changed the sequential integer in the address bar.
 *
 * The platform already had DataScope, SubjectContext and a Repository that
 * compiles `self` to `employee_id = :scope_employee`. The surface handed every
 * role `tenantWide()` and asked nothing further. `Scope` is the join.
 */

use SmartPRS\Surface\Scope;

group('surface scope');

$bound = new Scope('17');
$unbound = new Scope(null);
$blank = new Scope('');

// NON-VACUITY: somebody reading their OWN record must be allowed, or every
// refusal below proves only that everything is refused.
ok($bound->covers('17', false), 'Your own record is yours to read.');
ok($bound->isBound(), 'and the account knows which employment it is');
same('17', $bound->own(), 'and can say so');

// THE REFUSAL THIS EXISTS FOR.
ok(!$bound->covers('18', false),
    'A colleague\'s record is refused when you hold no permission to read others — this is the '
    . 'whole of intra-tenant isolation, and it did not exist.');

// The permission still means what it means. This is a SECOND lock, not the only
// one: somebody with payroll.view sees any payslip, because that is the point.
ok($bound->covers('18', true), 'Somebody who may read others still may.');
ok($unbound->covers('18', true), 'even when their account is not linked to an employment');

// AN UNBOUND ACCOUNT SEES NOTHING OF ITS OWN, NOT EVERYTHING. "We could not work
// out who you are" must never resolve to "so here is everyone".
ok(!$unbound->isBound(), 'an account with no employment behind it knows it has none');
same(null, $unbound->own(), 'and has no record of its own');
ok(!$unbound->covers('17', false), 'and reads nobody by default');
ok(!$unbound->covers('', false), 'not even the empty employment id');
ok(!$blank->covers('', false),
    'An empty binding is not a match for an empty id — two unknowns are not the same person.');

// The two refusals are DIFFERENT and must not be collapsed. Telling somebody they
// lack permission when in fact nobody set up their record sends them to the wrong
// person for help.
ok(str_contains($bound->why('18'), 'somebody else'),
    'A colleague\'s record is refused as somebody else\'s.');
ok(str_contains($unbound->why('18'), 'not linked'),
    'An unlinked account is told its account is not linked — a configuration problem, not a '
    . 'permission it is missing.');
ok($bound->why('18') !== $unbound->why('18'), 'and the two never read the same');

// Narrowing a list.
$rows = [['id' => '17', 'name' => 'Mine'], ['id' => '18', 'name' => 'Theirs'],
    ['id' => '19', 'name' => 'Also theirs']];

same(1, count($bound->only($rows, false)), 'a bound account sees one row of three');
same('Mine', $bound->only($rows, false)[0]['name'], 'and it is the right one');
same(3, count($bound->only($rows, true)), 'somebody who may read others sees all three');
same(0, count($unbound->only($rows, false)), 'an unbound account sees none');
