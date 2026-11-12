<?php

declare(strict_types=1);

use SmartPRS\Surface\Authorisation;

/** 28 Sep: one role per name whatever the case; a user manager cannot hand out more than they hold. */
group('role grants');

$a = new Authorisation(null, ['HR Admin', 'HR admin', 'Team Lead', ' Agent ']);
same(1, count(array_filter($a->roles(), static fn (string $r): bool => strcasecmp($r, 'HR Admin') === 0)), 'HR Admin and HR admin are one role');
same('HR Admin', $a->canonicalRole('hr ADMIN'), 'any spelling reads as the declared one');
same('manager', $a->canonicalRole('Manager'), 'a designation Role of "Manager" is the built-in manager');
same('', $a->canonicalRole('Ghost'), 'an undeclared role is nobody');
ok($a->mayGrant('administrator', 'administrator'), 'the administrator may give any role');
ok(!$a->mayGrant('Team Lead', 'administrator'), 'nobody else may make an administrator');
ok($a->mayGrant('manager', 'Agent'), 'a role with no grants may be given by anybody who manages users');
