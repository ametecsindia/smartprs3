<?php

declare(strict_types=1);

/**
 * THE APPROVAL CHAIN: A REQUEST GOES TO THE MANAGER AND THE TEAM LEAD, NOT
 * THE WHOLE TENANT, AND THE QUEUE IS SCOPED THE SAME WAY.
 *
 * `recipientsFor()` resolves an employment's Reporting Manager
 * (`reporting_manager_id`) and Team Lead (`team_id` -> `teams.leader_id`) —
 * two independent FK links, deduplicated when they name the same person, and
 * silently empty for a link nobody set rather than falling back to anybody
 * else. `visibleTo()` is `Scope`'s own reach (self / direct reports / tenant)
 * unioned with whichever employees this account leads as a Team Lead, since
 * `Scope` itself knows nothing about team leadership.
 */

use SmartPRS\Surface\Attendance;
use SmartPRS\Surface\Regularisation;
use SmartPRS\Surface\Scope;
use SmartPRS\Surface\Store;

group('regularisation');

function regFresh(): array
{
    $store = (new Store(sys_get_temp_dir() . '/prs3-reg-' . bin2hex(random_bytes(4))))->forTenant('1');
    $regularisation = new Regularisation($store, new Attendance($store));

    return [$store, $regularisation];
}

// ---------------------------------------------------------- recipientsFor: manager and team lead, distinct people
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'm1', 'emp_code' => 'MGR-1', 'name' => 'Manager One']);
$store->put('employees', ['id' => 'l1', 'emp_code' => 'LEAD-1', 'name' => 'Lead One']);
$employee = $store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Employee One',
    'reporting_manager_id' => 'm1', 'team_id' => 't1']);
$store->put('teams', ['id' => 't1', 'name' => 'Team A', 'leader_id' => 'l1']);

same(['MGR-1', 'LEAD-1'], $regularisation->recipientsFor($employee), 'both the reporting manager and the team lead are recipients');

// ---------------------------------------------------------- recipientsFor: manager IS the team lead — told once
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'm1', 'emp_code' => 'MGR-1', 'name' => 'Manager One']);
$employee = $store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Employee One',
    'reporting_manager_id' => 'm1', 'team_id' => 't1']);
$store->put('teams', ['id' => 't1', 'name' => 'Team A', 'leader_id' => 'm1']);

same(['MGR-1'], $regularisation->recipientsFor($employee), 'the same person named twice (manager and team lead) is a recipient once');

// ---------------------------------------------------------- recipientsFor: neither set — nobody, not a fallback to everyone
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'm1', 'emp_code' => 'MGR-1', 'name' => 'Somebody Else']);
$employee = $store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Employee One']);

same([], $regularisation->recipientsFor($employee), 'an employee with no manager and no team on record routes to nobody, never to the whole tenant');

// ---------------------------------------------------------- recipientsFor: a dangling manager id is skipped, not fatal
[$store, $regularisation] = regFresh();
$employee = $store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Employee One',
    'reporting_manager_id' => 'ghost-id']);

same([], $regularisation->recipientsFor($employee), 'a reporting_manager_id pointing at nobody resolves to no recipient rather than an error');

// ---------------------------------------------------------- visibleTo: unbound account, may-read-others — Scope's own answer, unchanged
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'One']);
$store->put('employees', ['id' => 'e2', 'emp_code' => 'EMP-2', 'name' => 'Two']);
$scope = new Scope(null);
$visible = array_map(fn (array $e) => $e['emp_code'], $regularisation->visibleTo($scope, true));
sort($visible);
same(['EMP-1', 'EMP-2'], $visible, 'an unbound account with the tenant-wide permission still sees everyone — Scope\'s own tenant-wide answer passes through untouched');

// ---------------------------------------------------------- visibleTo: self-only Scope, but this account leads a team — the team is added
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'l1', 'emp_code' => 'LEAD-1', 'name' => 'Lead One']);
$store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Team Member', 'team_id' => 't1']);
$store->put('employees', ['id' => 'e2', 'emp_code' => 'EMP-2', 'name' => 'Unrelated Person']);
$store->put('teams', ['id' => 't1', 'name' => 'Team A', 'leader_id' => 'l1']);
$scope = new Scope('l1'); // no reportsTo closure: this role has no manager-tier reach at all
$visible = array_map(fn (array $e) => $e['emp_code'], $regularisation->visibleTo($scope, false));
sort($visible);
same(['EMP-1', 'LEAD-1'], $visible, 'a plain-reach account still sees its own record (Scope) plus the team it leads (added here) — never the unrelated employee');

// ---------------------------------------------------------- visibleTo: no teams table at all — Scope's answer is all there is
[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'One']);
$scope = new Scope('e1');
same(['EMP-1'], array_map(fn (array $e) => $e['emp_code'], $regularisation->visibleTo($scope, false)),
    'no teams collection on this store does not break visibility — it just adds nothing beyond Scope\'s own answer');

// ---------------------------------------------------------- the same missing punch is never raised twice
//
// THE DUPLICATE GUARD, PINNED. Two open requests for one missing punch end as
// two punches, and the day then settles to twice the hours nobody worked — a
// pay defect, not a tidiness one.
//
// It is pinned now because of how it was nearly tested by accident (23 Sep): the
// $audit closure fatalled on a MySQL integer id AFTER the request had been
// stored, so raising one looked like "nothing happened" and the obvious human
// response is to press the button again. That produced no duplicates — this guard
// refused every retry — but nothing in the suite said so, and a guard nobody
// asserts is a guard somebody deletes.

[$store, $regularisation] = regFresh();
$store->put('employees', ['id' => 'e1', 'emp_code' => 'EMP-1', 'name' => 'Employee One']);

$first = $regularisation->request('EMP-1', '2026-09-12', '2026-09-12 09:30:00', 'in', 'Missed the reader');
ok($first['ok'], 'the first request for a missing IN is recorded');

$again = $regularisation->request('EMP-1', '2026-09-12', '2026-09-12 09:31:00', 'in', 'Pressed it twice');
ok(!$again['ok'], 'the SAME employee, day and direction is refused the second time');
ok(str_contains(strtolower((string) $again['why']), 'already'),
    'and the refusal says it is already pending rather than reading as a failure');

// A DIFFERENT DIRECTION ON THE SAME DAY IS A DIFFERENT PUNCH. A day with neither
// an in nor an out needs both raised, and refusing the second would leave the
// day half-fixed.
$out = $regularisation->request('EMP-1', '2026-09-12', '2026-09-12 18:30:00', 'out', 'Missed the reader');
ok($out['ok'], 'the OUT for the same day is a separate request and is allowed');

// AND SO IS THE SAME DIRECTION ON ANOTHER DAY.
$next = $regularisation->request('EMP-1', '2026-09-13', '2026-09-13 09:30:00', 'in', 'Again');
ok($next['ok'], 'the same direction on a different day is allowed');

// ANOTHER EMPLOYEE IS ANOTHER PERSON — the guard must be per employment, never
// per day.
$store->put('employees', ['id' => 'e2', 'emp_code' => 'EMP-2', 'name' => 'Employee Two']);
$other = $regularisation->request('EMP-2', '2026-09-12', '2026-09-12 09:30:00', 'in', 'Missed it too');
ok($other['ok'], 'a different employee on the same day and direction is allowed');
