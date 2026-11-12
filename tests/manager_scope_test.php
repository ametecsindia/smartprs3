<?php

declare(strict_types=1);

/**
 * A MANAGER SEES THEIR TEAM — and nobody else's.
 *
 * `DataScope` has had `direct_reports` since the platform was written, tested and
 * compiling to real SQL, and it was wired to nothing: the surface had one role
 * that saw everything and one that saw itself, so a reporting manager either read
 * the whole organisation's leave ledger or could not approve their own team's
 * leave. This file is the proof that the third answer now exists and is genuinely
 * third — narrower than tenant-wide, wider than self.
 *
 * The trap this file is built to avoid: a scope test passes just as happily when
 * the scope refuses EVERYTHING. Every refusal below is therefore paired with an
 * allow that the same code path had to produce.
 */

use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Scope;

group('manager scope');

// The reporting graph, as `employees.reporting_manager_id` holds it (the real
// column — database/migrations/2026_02_01_000300_port_people_tables.php).
// M manages A and B; A manages C (a skip level); S is nobody's report.
$reportsTo = static fn (string $id): ?string => [
    'A' => 'M',
    'B' => 'M',
    'C' => 'A',
][$id] ?? null;

// A reporting manager reads ONLY their own line (config/role-reach.json default, owner 28 Sep).
$manager = new Scope('M', $reportsTo, null, Scope::LINE);
$plain = new Scope('M');                 // same person, no team reach granted
$report = new Scope('A', $reportsTo, null, Scope::LINE);    // a manager in their own right

// ---- NON-VACUITY -----------------------------------------------------------
// The manager scope must differ from BOTH neighbours, or it is one of them
// wearing a new name and every assertion below is decoration.

ok($manager->covers('A', false) && !$plain->covers('A', false),
    'The manager reaches a direct report where the same account without team reach does not — '
    . 'so the new scope is NOT self-only.');

// The comparison is against an account with NO team, because that is what a
// tenant-wide reader is here. Asking the MANAGER with the flag set no longer
// answers "everybody" — see the next assertion, which is the point.
ok(!$manager->covers('S', false) && $plain->covers('S', true),
    'and it refuses somebody outside the team that a tenant-wide read allows — so it is NOT '
    . 'tenant-wide either.');

// THE PERMISSION SAYS "BEYOND YOURSELF", NOT "EVERYBODY". This is the assertion
// that would have caught the bug the manager role shipped with: `employee.list`
// and `leave.balance.view_others` are held by the administrator AND the manager,
// and while the flag short-circuited, declaring the role silently handed a manager
// the whole tenant — the row guard stopped running for the exact account it was
// introduced for. A sweep signed in as a manager found nine such reads.
ok(!$manager->covers('S', true),
    'A manager holding a "view others" permission still does not reach outside their team: the '
    . 'permission decides WHETHER this account reads beyond itself, the scope decides HOW FAR.');

ok($manager->leadsTeam() && !$plain->leadsTeam(),
    'and the account itself knows which of the two it is.');

// ---- WHAT THE MANAGER REACHES ----------------------------------------------

ok($manager->covers('M', false),
    'A manager still sees their own record — "sees the team" must never mean "stops seeing '
    . 'themselves", because a manager has a payslip too.');
ok($manager->covers('B', false), 'and every direct report, not just the first one.');

// ---- WHAT THEY DO NOT ------------------------------------------------------

ok($manager->covers('C', false),
    'A skip level is reached: C reports to A, who reports to M. The reach is the whole line '
    . 'below a manager, every level (owner, 23 Sep), not one link.');
ok(!$manager->covers('S', false), 'and a colleague with no line to them at all is refused.');
ok(!$manager->covers('', false), 'and the empty employment id is nobody, not everybody.');

// A report's own reach is their own, not their manager's.
ok($report->covers('C', false), 'A manages C, so A reaches C.');
ok(!$report->covers('B', false), 'but A does not reach their peer B.');
ok(!$report->covers('M', false), 'and reaches upwards at nobody — a report is not their manager.');

// An unbound account with a resolver in hand is still nobody.
$unbound = new Scope(null, $reportsTo);
ok(!$unbound->leadsTeam(), 'An account linked to no employment leads no team, resolver or not.');
ok(!$unbound->covers('A', false),
    'and reaches nothing: "we could not work out who you are" must never resolve to "so here is '
    . 'everyone", and a reporting resolver does not change that.');

// ---- THE LIST --------------------------------------------------------------

$rows = [['id' => 'M'], ['id' => 'A'], ['id' => 'B'], ['id' => 'C'], ['id' => 'S']];

same(4, count($manager->only($rows, false)), 'the team list is self plus the whole line below (two reports and one skip level), of five rows');
same(['M', 'A', 'B', 'C'], array_column($manager->only($rows, false), 'id'), 'and it is those four');
same(1, count($plain->only($rows, false)), 'without team reach the same account sees one row');
same(5, count($plain->only($rows, true)),
    'an account with no team that may read others sees all five — the flag still means the tenant '
    . 'for somebody with no second answer to give');
same(['M', 'A', 'B', 'C'], array_column($manager->only($rows, true), 'id'),
    'but the same flag narrows a MANAGER to their team, exactly as covers() does. A picker '
    . 'narrowed by a different test from the screen behind it is how the whole staff directory '
    . 'ended up printed above screens that refused every row in it.');

// ---- THE THIRD REFUSAL -----------------------------------------------------
// Three causes, three answers. Telling a manager they lack a permission when what
// they lack is a reporting line sends them to ask for the wrong thing.

$whyManager = $manager->why('S');
$whySelf = $plain->why('S');
$whyUnbound = (new Scope(null))->why('S');

ok(str_contains($whyManager, 'not in your reporting line'),
    'A manager outside their team is told the reporting line is what is missing.');
ok(str_contains($whySelf, 'somebody else'), 'a colleague-reader is told it is somebody else\'s.');
ok(str_contains($whyUnbound, 'not linked'), 'an unlinked account is told its account is not linked.');
same(3, count(array_unique([$whyManager, $whySelf, $whyUnbound])),
    'and the three never read the same — collapsing any two loses the only information the '
    . 'refusal carries.');

// ---- THE ROLE THAT HOLDS IT ------------------------------------------------
// A scope granted to nobody is shape without meaning; so is a permission granted
// to nobody, which is a guard that refuses the whole world and reads as a pass.

$access = new Authorisation();

ok(in_array('manager', $access->roles(), true), 'the manager role exists in this build');

foreach (['employee.view', 'employee.list',
    'attendance.day.view', 'attendance.day.view.history', 'attendance.punch.view',
    'leave.request.view', 'leave.request.submit', 'leave.request.decide',
    'leave.balance.view', 'leave.balance.view_others'] as $held) {
    ok($access->allows('manager', $held), "a reporting manager holds {$held}");
}

// A code that is not in the catalogue denies for EVERY role, administrator
// included — so the fact that these are allowed at all is the evidence that each
// name is real. This is the counter-example that makes that argument hold.
ok(!$access->allows('administrator', 'leave.request.approve.definitely'),
    'a permission the catalogue does not carry is refused even to the administrator, which is '
    . 'what makes the grants above evidence of anything.');

foreach (['payroll.run', 'payroll.view', 'payroll.run.approve',
    'employee.salary.view', 'employee.update'] as $withheld) {
    ok(!$access->allows('manager', $withheld), "and a reporting manager does not hold {$withheld}");
    ok($access->allows('administrator', $withheld),
        "while the administrator does — {$withheld} is withheld, not simply absent everywhere");
}
