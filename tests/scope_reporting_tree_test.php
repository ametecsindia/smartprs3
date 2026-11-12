<?php

declare(strict_types=1);

use SmartPRS\Surface\Scope;

/**
 * WHO A MANAGER REACHES, DOWN THE WHOLE LINE.
 *
 * The owner's shape (23 Sep), and his words for why: "this is how the actual
 * hierarchy follows in any organization".
 *
 *     1 reports to 2, 2 reports to 3, 3 reports to 4
 *
 *     2 sees 1
 *     3 sees 2 and 1
 *     4 sees 3, 2 and 1
 *
 * This was `direct_reports` — one level — so 3 could not see 1 and 4 could see
 * only 3. Changing it is an authorisation widening, which is the direction that
 * needs a test rather than a look: every assertion below that PASSES because
 * somebody is now visible has a sibling asserting somebody is still not.
 */

group('Scope · reporting tree');

// 1 → 2 → 3 → 4, plus an unrelated pair nobody in that line may see.
$reportsTo = static fn (string $id): ?string => [
    '1' => '2',
    '2' => '3',
    '3' => '4',
    '9' => '8',   // a separate line entirely
][$id] ?? null;

$scopeFor = static fn (?string $me): Scope => new Scope($me, $reportsTo(...));

// --- everybody below you, at every level ------------------------------------

ok($scopeFor('2')->covers('1', false), '2 sees 1 — their direct report');
ok($scopeFor('3')->covers('2', false), '3 sees 2 — their direct report');
ok($scopeFor('3')->covers('1', false), '3 sees 1 — SKIP-LEVEL, the whole point');
ok($scopeFor('4')->covers('3', false), '4 sees 3');
ok($scopeFor('4')->covers('2', false), '4 sees 2');
ok($scopeFor('4')->covers('1', false), '4 sees 1 — three levels down');

// --- and yourself, because a manager is also a person -----------------------

ok($scopeFor('3')->covers('3', false), 'a manager still sees their own record');

// --- but never upwards, and never sideways ----------------------------------
// The widening must not become "anybody in the line", which would hand every
// employee their own manager's payslip.

ok(!$scopeFor('1')->covers('2', false), '1 does NOT see 2 — reach is downward only');
ok(!$scopeFor('1')->covers('4', false), '1 does NOT see 4');
ok(!$scopeFor('2')->covers('3', false), '2 does NOT see their own manager');
ok(!$scopeFor('3')->covers('9', false), '3 does NOT see an unrelated line');
ok(!$scopeFor('4')->covers('8', false), '4 does NOT see an unrelated line either');

// --- an account with no employee behind it sees nothing of its own ----------
// "We could not work out who you are" must never resolve to "so here is everyone".

ok(!$scopeFor(null)->covers('1', false), 'an unbound account reaches nobody');
ok($scopeFor(null)->covers('1', true),
    'unless it holds the permission AND leads no team — then it is an administrator, not a manager');

// --- somebody with no reporting line given reaches only themselves ----------

$selfOnly = new Scope('2');
ok($selfOnly->covers('2', false), 'no resolver: still sees themselves');
ok(!$selfOnly->covers('1', false), 'no resolver: reaches nobody else, as before the manager role');

// --- only() must narrow by the same rule as covers() ------------------------
// A picker narrowed differently from the screen behind it is how a staff
// directory ends up printed above screens that refuse every row in it.

$rows = [['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4'], ['id' => '9']];
same(['1', '2', '3'], array_column($scopeFor('3')->only($rows, false), 'id'),
    'only() gives 3 exactly the tree below them plus themselves');
same(['1', '2', '3', '4'], array_column($scopeFor('4')->only($rows, false), 'id'),
    'and gives 4 one level more');

// --- a reporting cycle is a data defect, and says so ------------------------
// A cycle is never a licence to widen scope (R-XXII-095). Since 24 Sep it is a
// DENIAL of the line, recorded for the screen to report — no longer a raise,
// which blanked the Directory for every account whose line walked through it.

$cyclic = new Scope('1', static fn (string $id): ?string => ['1' => '2', '2' => '1'][$id] ?? null, null, Scope::LINE);
$granted = $cyclic->covers('2', false);

ok(!$granted, 'a reporting cycle is denied rather than silently granting');
ok($cyclic->cycledEmployments() !== [], 'and it is recorded, so the defect is reported rather than hidden');
ok($cyclic->covers('1', false), 'while the account still sees its own record');
