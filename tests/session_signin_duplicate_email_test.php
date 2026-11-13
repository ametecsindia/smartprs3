<?php

declare(strict_types=1);

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Session;
use SmartPRS\Surface\Store;

group('session_signin_duplicate_email');

// Owner, 13 Nov 2026: an employee whose phone was registered could not sign in after resets ("Those credentials were
// not accepted"). The same email existed in two tenants; sign-in took the LAST row with that email whatever the tenant,
// so the right password was checked against the other tenant's login. Sign-in must pick the login that matches.
$dir = sys_get_temp_dir() . '/sprs-si-' . bin2hex(random_bytes(4));
$store = new Store($dir);
$h = PasswordHasher::bcrypt(4);
$store->put('users', ['email' => 'altaf@example.test', 'tenant_id' => 'T-REAL', 'status' => 'employee', 'password_hash' => $h->hash('right-password')]);
$store->put('users', ['email' => 'Altaf@example.test', 'tenant_id' => 'T-OTHER', 'status' => 'viewer', 'password_hash' => $h->hash('other-password')]);
$_SESSION = [];
$s = new Session($store, $h);

same(true, $s->signIn('altaf@example.test', 'right-password', 'T-REAL')['ok'], 'company link: the login inside that company is checked');
$_SESSION = [];
same(true, $s->signIn('altaf@example.test', 'right-password')['ok'], 'plain sign-in: whichever duplicate the password belongs to');
$_SESSION = [];
same(false, $s->signIn('altaf@example.test', 'other-password', 'T-REAL')['ok'], 'company link: the other company\'s password is refused');
$_SESSION = [];
same(false, $s->signIn('altaf@example.test', 'wrong', 'T-REAL')['ok'], 'wrong password: refused');
$_SESSION = [];
same(false, $s->signIn('nobody@example.test', 'right-password')['ok'], 'unknown email: refused');
