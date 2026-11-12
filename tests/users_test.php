<?php

declare(strict_types=1);

/**
 * SOMEBODY HAS TO BE ABLE TO MAKE A MANAGER.
 *
 * The `manager` role has been declared in Authorisation and wired to
 * `direct_reports` in Scope for a while, and there was no screen that could
 * assign it: the only way to make somebody a manager was an UPDATE against
 * `users.status` typed into MySQL. A role nobody can assign is a role nobody
 * holds, and every guard behind it refuses the whole world — which reads as a
 * pass in every sweep. This file is the proof that the assignment now exists AND
 * that each refusal around it is counting something rather than saying no on
 * principle.
 *
 * The trap: a refusal test passes just as happily when the method refuses
 * EVERYTHING. Every refusal below is therefore paired with the same call
 * succeeding once the one refused condition is removed.
 */

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Authorisation;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Users;

group('users');

$hasher = PasswordHasher::bcrypt(4);   // cost 4: this file hashes a dozen passwords
$access = new Authorisation();

$fresh = static function () use ($hasher, $access): array {
    $store = (new Store(sys_get_temp_dir() . '/prs3-users-' . bin2hex(random_bytes(6))))
        ->forTenant('T1');

    foreach ([
        ['id' => 'E1', 'emp_code' => 'A001', 'name' => 'Asha'],
        ['id' => 'E2', 'emp_code' => 'A002', 'name' => 'Bala'],
        ['id' => 'E3', 'emp_code' => 'A003', 'name' => 'Chandra'],
    ] as $employment) {
        $store->put('employees', $employment);
    }

    // `users` is platform-scoped, so the store does NOT stamp tenant_id — these
    // rows carry it themselves, exactly as Users::create() writes them.
    $store->put('users', ['id' => 'U-ADMIN', 'email' => 'admin@one', 'status' => 'administrator',
        'tenant_id' => 'T1']);

    return [new Users($store, $access, $hasher), $store];
};

[$users, $store] = $fresh();

$good = 'correct-horse-battery';

// ---- NON-VACUITY: THE HAPPY PATHS -------------------------------------------
// Every refusal below is worthless unless the same call succeeds when the one
// refused condition is absent.

$created = $users->create('mgr@one', $good, 'manager', 'E1');
ok($created['ok'], 'A manager account is created: role, password and employment all acceptable.');
same(true, isset($created['user']['id']), 'and the row that was written comes back');

ok(!isset($created['user']['password']) || $created['user']['password'] !== $good,
    'The password is never stored as it was typed.');
ok(password_verify($good, (string) $created['user']['password']),
    'and what IS stored verifies the password, so it is a hash of it rather than a mangling.');

$viewer = $users->create('viewer@one', $good, 'viewer');
ok($viewer['ok'], 'An account with no employment is allowed — that is a link somebody makes later.');
ok(str_contains($viewer['why'], 'sees nothing of its own'),
    'and the success message says what it costs until they do.');

// ---- create(): THE REFUSALS -------------------------------------------------

$duplicate = $users->create('MGR@ONE', $good, 'viewer', 'E2');
ok(!$duplicate['ok'], 'The same email twice in one tenant is refused, case and all.');
ok(str_contains($duplicate['why'], 'already an account'), 'and says which address it is.');

$short = $users->create('short@one', str_repeat('a', Session::PASSWORD_FLOOR - 1), 'viewer');
ok(!$short['ok'], 'A password under the floor is refused.');
ok(str_contains($short['why'], (string) Session::PASSWORD_FLOOR),
    'and the message names the floor by reading the constant, not a number written twice.');
ok($users->create('short@one', str_repeat('a', Session::PASSWORD_FLOOR), 'viewer')['ok'],
    'while exactly the floor is accepted — the check is < and not <=, and that boundary is the '
    . 'whole of what it does.');

$unknownRole = $users->create('ghost@one', $good, 'supervisor');
ok(!$unknownRole['ok'], 'A role this build does not declare is refused at creation.');
ok(str_contains($unknownRole['why'], 'grants NOTHING'),
    'and the refusal says WHY it is not a smaller role: the account would sign in and be able to '
    . 'do nothing at all, which the person reports as broken software.');
ok(str_contains($unknownRole['why'], 'manager'),
    'and it lists the roles that do exist, so the fix is on the screen rather than in the source.');

// The counter-example that makes the assertion above evidence of anything: the
// role NAME is what is refused, not the account.
ok($users->create('ghost@one', $good, 'manager')['ok'],
    'and the identical call with a declared role goes through — the refusal was about the role.');

$foreign = $users->create('outside@one', $good, 'viewer', 'E-OTHER-TENANT');
ok(!$foreign['ok'], 'An employment that is not in this tenant is refused.');
ok(str_contains($foreign['why'], 'structural'),
    'and says tenancy is structural rather than a filter, so "not yours" and "not there" are the '
    . 'same sentence on purpose.');

$taken = $users->create('second@one', $good, 'viewer', 'E1');
ok(!$taken['ok'], 'An employment already linked to another account is refused at creation too — '
    . 'the same defect through a different door.');
ok(str_contains($taken['why'], 'whose row is this'),
    'and names the question the ambiguity would make unanswerable.');

// ---- setRole() --------------------------------------------------------------

[$users, $store] = $fresh();
$manager = $users->create('mgr@one', $good, 'viewer', 'E1')['user'];
$managerId = (string) $manager['id'];

$promoted = $users->setRole($managerId, 'manager', 'U-ADMIN');
ok($promoted['ok'], 'An administrator promotes somebody else to manager. THIS is the feature.');
same('manager', (string) $promoted['user']['status'],
    'and the role lands on `status`, which is where Session::signIn reads it from — a role written '
    . 'anywhere else is a role nobody signs in with.');

$self = $users->setRole('U-ADMIN', 'viewer', 'U-ADMIN');
ok(!$self['ok'], 'An account cannot change its own role.');
ok(str_contains($self['why'], 'your own role'), 'and is told which of the two it is.');
// Both directions, and the same account, so the refusal is about WHO and not
// about which way the change goes.
ok(!$users->setRole($managerId, 'administrator', $managerId)['ok'],
    'not upwards either — self-promotion is the same guard and the more obvious half of it.');
ok($users->setRole($managerId, 'administrator', 'U-ADMIN')['ok'],
    'while somebody else making exactly that change is allowed: the refusal is the actor, not '
    . 'the role being set.');

ok(!$users->setRole($managerId, 'supervisor', 'U-ADMIN')['ok'],
    'An undeclared role is refused on the way in as well as at creation.');
ok(!$users->setRole('U-NOBODY', 'viewer', 'U-ADMIN')['ok'],
    'and an account that is not in this tenant is not found.');

// ---- THE LAST ADMINISTRATOR, AND THAT THE CHECK IS COUNTING ------------------
// Two administrators exist at this point (U-ADMIN and the promoted manager). The
// first demotion must SUCCEED and the second must fail — if the check were a flat
// "administrators are never demoted" both would fail and the test would still be
// green, which is exactly the vacuous shape this section exists to rule out.

same(true, $users->setRole($managerId, 'viewer', 'U-ADMIN')['ok'],
    'One of two administrators is demoted without complaint.');

$last = $users->setRole('U-ADMIN', 'viewer', $managerId);
ok(!$last['ok'], 'and the remaining one then cannot be — the check is a count, not a rule about '
    . 'the word "administrator".');
ok(str_contains($last['why'], 'last administrator'), 'and says so.');
ok(str_contains($last['why'], 'no support desk'),
    'and says why it is unrecoverable rather than merely discouraged.');

// A no-op is not a demotion: setting the last administrator to administrator is
// allowed, because nothing is being taken away.
ok($users->setRole('U-ADMIN', 'administrator', $managerId)['ok'],
    'while re-setting the last administrator TO administrator is fine — the guard is about losing '
    . 'the last one, not about touching the row.');

// ---- bind() / unbind() ------------------------------------------------------

[$users, $store] = $fresh();
$one = (string) $users->create('one@one', $good, 'viewer')['user']['id'];
$two = (string) $users->create('two@one', $good, 'viewer')['user']['id'];

$bound = $users->bind($one, 'E1');
ok($bound['ok'], 'An account is linked to an employment.');
ok(str_contains($bound['why'], 'A001'),
    'and the confirmation names the employment by its code, resolved through the store, not by id.');

$clash = $users->bind($two, 'E1');
ok(!$clash['ok'], 'The same employment cannot be linked to a second account.');
ok(str_contains($clash['why'], 'one@one'),
    'and names the account holding it, since unlinking that one is the fix.');
ok($users->bind($two, 'E2')['ok'],
    'while a free employment binds — the refusal was the clash, not binding.');

ok($users->bind($one, 'E1')['ok'],
    'Re-binding an account to the employment it already has is not a clash with itself.');

ok(!$users->bind($one, 'E-OTHER-TENANT')['ok'],
    'An employment outside this tenant cannot be linked: tenancy is structural, so it is not there.');
ok(!$users->bind($one, '')['ok'], 'and the empty employment is not a silent unlink.');
ok(!$users->bind('U-NOBODY', 'E3')['ok'], 'and an account that is not here is not found.');

$unbound = $users->unbind($one);
ok($unbound['ok'], 'An account is unlinked.');
ok(str_contains($unbound['why'], 'NOTHING of its own'),
    'and the consequence is stated plainly: an unlinked account sees none of its own records.');
ok(str_contains($unbound['why'], 'none rather'),
    'and says which direction that resolves in — "we could not work out who you are" is none, '
    . 'never all.');
ok(!$users->unbind($one)['ok'], 'Unlinking an already-unlinked account is refused, not a no-op '
    . 'that reads as success.');
ok($users->unbind($two)['ok'],
    'while a genuinely linked account still unlinks — the refusal was the state, not the method.');

// The unlink actually happened in storage, and E1 is free again.
ok($users->bind($two, 'E1')['ok'],
    'and the employment the unlink released can be given to somebody else, which is the only '
    . 'observable proof the write went through.');

// ---- all() ------------------------------------------------------------------

[$users, $store] = $fresh();
$store->put('users', ['id' => 'U-LOCAL', 'email' => Session::LOCAL_DEFAULT_EMAIL,
    'status' => 'administrator', 'tenant_id' => 'T1']);
// Another tenant's account, written through an unscoped store the way the
// platform holds it. `users` is on Store::PLATFORM_SCOPED, so the tenant filter
// in Users is the ONLY thing keeping this row off the screen.
$store->put('users', ['id' => 'U-OTHER', 'email' => 'admin@two', 'status' => 'administrator',
    'tenant_id' => 'T2']);
$users->create('listed@one', $good, 'manager', 'E2');

$listed = $users->all();
ok($listed['ok'], 'the list renders');
$emails = array_column($listed['users'], 'email');

ok(in_array('listed@one', $emails, true), 'and carries this tenant\'s accounts');
ok(!in_array('admin@two', $emails, true),
    'and NOT another tenant\'s — `users` is platform-scoped, so a store filter is not doing this '
    . 'and the class has to.');

$byEmail = [];

foreach ($listed['users'] as $row) {
    $byEmail[(string) $row['email']] = $row;
}

same('manager', $byEmail['listed@one']['role'], 'each row carries the role');
same(true, $byEmail['listed@one']['bound'], 'and whether it is linked');
ok(str_contains((string) $byEmail['listed@one']['employment'], 'A002')
    && str_contains((string) $byEmail['listed@one']['employment'], 'Bala'),
    'and the employment resolved through the store as code and name, not as an id');
same('', $byEmail['admin@one']['employment'], 'an unlinked account shows no employment');
same(false, $byEmail['admin@one']['bound'], 'and says it is unlinked');

same(true, $byEmail[Session::LOCAL_DEFAULT_EMAIL]['is_local_default'],
    'The seeded local default is flagged BY ITS EMAIL, because the ported users table has no '
    . 'is_local_default column and the flag the warning used to read could never be true on MySQL.');
same(false, $byEmail['admin@one']['is_local_default'], 'and no other account is flagged.');

// A role on a row that this build no longer declares is shown as it is, and
// marked — the person looking at this list is the only one who can fix it.
$store->put('users', ['id' => 'U-STALE', 'email' => 'stale@one', 'status' => 'supervisor',
    'tenant_id' => 'T1']);
$stale = null;

foreach ($users->all()['users'] as $row) {
    if ($row['email'] === 'stale@one') {
        $stale = $row;
    }
}

same('supervisor', $stale['role'], 'an undeclared role already in the data is shown as it is');
same(false, $stale['role_declared'], 'and marked as one this build does not declare');
same(true, $byEmail['listed@one']['role_declared'], 'while a declared one is not marked');

// ---- AN UNSCOPED STORE ------------------------------------------------------
// The store `public/app.php` builds before anybody signs in has no tenant on it.
// Reading `users` through that would list every customer's accounts.

$unscoped = new Users(
    new Store(sys_get_temp_dir() . '/prs3-users-none-' . bin2hex(random_bytes(6))),
    $access,
    $hasher,
);

ok(!$unscoped->all()['ok'], 'A store with no tenant refuses to list rather than listing everybody.');
same([], $unscoped->all()['users'], 'and carries no rows with the refusal.');
ok(!$unscoped->create('x@one', $good, 'viewer')['ok'],
    'and refuses to create an account belonging to no organisation — a user with no tenant is a '
    . 'user whose every read is unscoped.');

// ---- THE ROLES THIS SCREEN MAY OFFER ----------------------------------------
// Offering a role the decider does not know would create accounts that can do
// nothing; refusing one it does know would make the manager unassignable again,
// which is the bug this whole file exists about.

foreach ($access->roles() as $role) {
    ok($users->create("role-{$role}@one", $good, $role)['ok'],
        "every role this build declares can actually be assigned — {$role}");
}
