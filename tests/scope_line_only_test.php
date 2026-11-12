<?php

declare(strict_types=1);

use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Scope;

/**
 * OWNER, 28 SEP: "A person with Employee/Agent role should ... see Only their
 * data and No other employee names" and "If an employee has users reporting to
 * him or her, he or she should be able to see ONLY the employees reporting to
 * him or her ... the subordinates and the people reporting to their
 * subordinates to be visible for the top level."
 */
group('Scope · reporting line only');

// 1 → 2 → 3 → 4 (4 at the top); 5 → 4 as well; 9 is an agent nobody reports to.
$reportsTo = static fn (string $id): ?string => ['1' => '2', '2' => '3', '3' => '4', '5' => '4', '9' => '6'][$id] ?? null;
$branchOf = static fn (string $id): string => 'B-KON';
$line = static fn (?string $me): Scope => new Scope($me, $reportsTo(...), $branchOf(...), Scope::LINE);
$rows = array_map(static fn (string $id): array => ['id' => $id], ['1', '2', '3', '4', '5', '6', '9']);
$ids = static fn (array $r): array => array_column($r, 'id');

// Agent: self only, even with a "view others" permission.
same(['9'], $ids($line('9')->only($rows, true)), 'an agent sees only their own record, even holding employee.list');
same(['9'], $ids($line('9')->only($rows, false)), 'and without it');
ok(!$line('9')->covers('6', true), 'not even their own manager');

// Manager: self + whole downline, nobody else.
same(['1', '2', '3'], $ids($line('3')->only($rows, true)), '3 sees 2 and 1 (and self) — nobody beside or above');
same(['1', '2', '3', '4', '5'], $ids($line('4')->only($rows, true)), 'the top sees every level below, both branches of the tree');
ok(!$line('3')->covers('5', true), 'a peer\'s report is not visible');
ok(!$line('2')->covers('3', true), 'nor is one\'s own manager');

// Unlinked logins fail closed unless company-wide.
same([], $line(null)->only($rows, true), 'an unlinked line-only login sees nobody');
same(7, count((new Scope(null, $reportsTo(...), $branchOf(...), Scope::COMPANY))->only($rows, true)),
    'an unlinked administrator still sees everyone');

// Defaults (config/role-reach.json).
same(Scope::COMPANY, Authorisation::defaultReach('administrator'), 'administrator: company');
same(Scope::COMPANY, Authorisation::defaultReach('hr admin'), 'HR Admin: company (case-insensitive)');
same(Scope::LINE, Authorisation::defaultReach('Employee'), 'Employee: own line only');
same(Scope::LINE, Authorisation::defaultReach('Agent'), 'Agent: own line only');
same(Scope::LINE, Authorisation::defaultReach('Team Lead'), 'Team Lead: own line only');
same(Scope::LINE, Authorisation::defaultReach('viewer'), 'viewer: own line only');
