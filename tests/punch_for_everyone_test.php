<?php

declare(strict_types=1);

use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Store;

/**
 * Owner, 13 Nov 2026: "Punch should be available by default for everyone" — the app's Punch tab and My Attendance.
 * A Roles & Permissions grid saved before that rule (My Attendance = No access) must not take it away;
 * the geo-fence is the only exception.
 */
group('punch for everyone');

$c = new Customisation(new Store(sys_get_temp_dir() . '/pe' . getmypid()));
$c->saveRaw(Authorisation::CUSTOMISATION_KEY, ['viewer' => ['ess.attendance.punch' => 'none'], 'manager' => ['ess.attendance.punch' => 'none'],
    'Field Agent' => ['ess.attendance.punch' => 'none']], 'test', 'test');
$a = new Authorisation($c, ['Field Agent']);
foreach (['viewer', 'manager', 'Field Agent', 'administrator'] as $role) {
    ok($a->allows($role, 'ess.attendance.punch'), "{$role} can punch even with My Attendance saved as No access");
}
