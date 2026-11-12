<?php

declare(strict_types=1);

use SmartPRS\Surface\Scope;

/**
 * THE WHOLE DOWNLINE, AND THEN THE BRANCH.
 *
 * Owner, 24 Sep: "any user who has reporting direct people or the person is
 * reporting and other people are reporting under that... the hierarchy up should
 * be able to see all the downline members." And, 23 Sep, for everything outside
 * that line: "Company Admin and HR Admin / HR Manager can be with the option All
 * Branches or the entire company wide. All other roles should have branch
 * assigned."
 *
 * So there are two separate answers and they must not be confused:
 *
 *   1. THE REPORTING LINE is a fact about the org chart. Everybody below this
 *      account, at every level, needs no permission and — crucially — is NOT
 *      narrowed by branch. A Team Lead whose report sits in another branch still
 *      manages that person.
 *   2. BEYOND THE LINE is a declared per-role setting, and it only applies once a
 *      permission has already said this account reads past its own record.
 *
 * The test that matters most here is the first one from a role that is NOT
 * "manager": until 24 Sep the reporting resolver was handed over only for a role
 * whose reach was declared 'team', and reach was a hardcoded match() answering
 * 'self' for every custom role — so on a real install the reporting tree never
 * engaged at all.
 */

group('Scope · downline, then branch');

// 1 → 2 → 3 → 4 spans two branches on purpose; 9 and 8 report to nobody.
$reportsTo = static fn (string $id): ?string => ['1' => '2', '2' => '3', '3' => '4'][$id] ?? null;
$branch = ['1' => 'B-KON', '2' => 'B-KON', '3' => 'B-KON', '4' => 'B-HYD',
    '9' => 'B-KON', '8' => 'B-HYD', '7' => ''];
$branchOf = static fn (string $id): string => $branch[$id] ?? '';

$asBranch = static fn (?string $me): Scope
    => new Scope($me, $reportsTo(...), $branchOf(...), Scope::BRANCH);
$asCompany = static fn (?string $me): Scope
    => new Scope($me, $reportsTo(...), $branchOf(...), Scope::COMPANY);

// --- the line, for an account whose role is narrowed to its branch ---------------
// This is the property the whole change exists for.

ok($asBranch('2')->covers('1', false), '2 sees 1 — their direct report, no permission needed');
ok($asBranch('3')->covers('1', false), '3 sees 1 — skip-level');
ok($asBranch('4')->covers('1', false), '4 sees 1 — three levels down');
ok($asBranch('4')->covers('2', false), '4 sees 2');
ok($asBranch('4')->covers('4', false), 'and 4 still sees their own record');

// A BRANCH SETTING NEVER NARROWS THE LINE. 4 is in B-HYD and 1, 2 and 3 are all
// in B-KON. If branch could cut the line, a manager would stop managing the
// moment somebody moved office.
ok($asBranch('4')->covers('3', false), "4 manages 3 across a branch boundary");
same(['1', '2', '3', '4'], array_column($asBranch('4')->only([
    ['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4'], ['id' => '8'], ['id' => '9'],
], false), 'id'), 'only() gives 4 their whole line, in both branches, and nobody else');

// --- beyond the line needs the permission AND the setting --------------------------

ok(!$asBranch('3')->covers('9', false),
    'without the permission, a colleague in the same branch is still not theirs to read');
ok($asBranch('3')->covers('9', true),
    'WITH the permission and branch reach, 3 reads 9 — same branch, outside the line');
ok(!$asBranch('3')->covers('8', true),
    'but not 8, who is in another branch — this is what "their branch" buys');
ok($asCompany('3')->covers('8', true),
    'the same account set to whole-company reads 8');
ok(!$asCompany('3')->covers('8', false),
    'and whole-company reach still does nothing without the permission');

// --- the one deliberate exception ---------------------------------------------------
// Branch became a required field on employees on 23 Sep. Every employee created
// before that has none, and failing closed against an unset branch would blank
// their screens rather than protect anything.

ok($asBranch('7')->covers('8', true),
    'an actor whose own employee record has NO branch reads the whole company, not nothing');
ok(!$asBranch('7')->covers('8', false),
    'that exception widens the branch test, never the permission');

// --- accounts at the edges ------------------------------------------------------------

ok(!$asBranch(null)->covers('1', true),
    'an unbound account reads beyond itself only when its role reads the whole company (owner, 28 Sep: '
    . 'fail closed) — the administrator, whose reach is company, is unaffected');
ok($asCompany(null)->covers('1', true), 'an unbound COMPANY-reach account (the administrator) still reads the tenant');
ok(!$asBranch(null)->covers('1', false), 'and reaches nobody without it');
ok(!$asBranch('1')->covers('2', false),
    'without a read-others permission, 1 does not read their own manager — the LINE is downward only');
ok(!$asBranch('1')->covers('4', true),
    'and even with one, 1 does not read 4: upward is not a line, and 4 is in another branch');
ok($asBranch('1')->covers('2', true),
    'WITH the permission, 1 does read 2 — same branch. "Their branch" means the branch, not the '
    . 'branch below them; an ordinary employee holds no such permission, which is what keeps this '
    . 'from being a leak');

// --- inMyLine: the tree with the actor taken out ----------------------------------------

ok($asBranch('3')->inMyLine('1'), '1 is in 3\'s line');
ok($asBranch('4')->inMyLine('1'), 'and in 4\'s');
ok(!$asBranch('3')->inMyLine('3'), 'but nobody is in their OWN line — a manager\'s leave is not '
    . 'in their own approval queue');
ok(!$asBranch('1')->inMyLine('3'), 'and a report is not their manager\'s manager');
ok(!$asBranch('3')->inMyLine(''), 'an empty id is nobody');

// --- the setting itself -------------------------------------------------------------------

same(Scope::BRANCH, $asBranch('3')->beyondLine(), 'branch reach reads back as branch');
same(Scope::COMPANY, $asCompany('3')->beyondLine(), 'company reach reads back as company');
same(Scope::LINE, (new Scope('3', $reportsTo(...), $branchOf(...), 'nonsense'))->beyondLine(),
    'an unrecognised setting fails closed to the reporting line (28 Sep)');
same(Scope::COMPANY, (new Scope('3'))->beyondLine(),
    'and a Scope built the old way, with no setting at all, is unchanged');

// --- MORE THAN ONE BRANCH (owner, 8 Oct): a Branch Admin given B-HYD as well ---
$multi = new Scope('9', $reportsTo(...), $branchOf(...), Scope::BRANCH, static fn (): array => ['B-HYD']);
ok($multi->covers('8', true), 'a Branch Admin in B-KON who also looks after B-HYD sees B-HYD');
ok($multi->covers('1', true), 'and still their own branch');
same(['B-KON', 'B-HYD'], $multi->myBranches(), 'own branch first, then the ones ticked');
ok(!$asBranch('9')->covers('8', true), 'without the extra branch, B-HYD stays out of reach');
