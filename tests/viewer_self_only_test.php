<?php

declare(strict_types=1);

use SmartPRS\Surface\Authorisation;

/**
 * Owner, 13 Nov 2026: a new employee with no designation logs in as the built-in
 * viewer, and by default sees only their own things — the same menus as the
 * Employee/Agent role, plus punching their own attendance. Setting a designation
 * then gives that designation's access; a saved Roles & Permissions tick still wins.
 */
group('viewer self only');

$a = new Authorisation(null, ['Employee/Agent']);
foreach (['employee.view', 'employee.directory.view', 'employee.team.view', 'employee.probation.view',
    'employee.transfer.view', 'employee.idcard.view', 'payroll.structure.view',
    'attendance.day.view', 'attendance.punch.view', 'assistant.view'] as $code) {
    ok(!$a->allows('viewer', $code), "a login with no designation does not get {$code}");
}
foreach (['ess.attendance.punch', 'leave.request.submit', 'leave.balance.view', 'home.dashboard.view', 'notification.inbox.view'] as $code) {
    ok($a->allows('viewer', $code), "a login with no designation keeps {$code}");
}
foreach (['home.dashboard.view', 'leave.request.submit'] as $code) {
    same($a->allows('Employee/Agent', $code), $a->allows('viewer', $code), "{$code} matches Employee/Agent");
}
