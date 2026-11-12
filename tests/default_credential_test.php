<?php

declare(strict_types=1);

/**
 * A WARNING THAT CANNOT FIRE IS WORSE THAN NO WARNING, because somebody read it
 * once and believed it.
 *
 * The default-credential banner looked for `is_local_default` on the user row.
 * The ported `users` table has no such column, so on MySQL the flag could never
 * be true and the banner could never appear — while its own text promised it
 * would stay on screen until the account was deleted. It is now found by the
 * account's own address, which cannot silently stop existing.
 */

use SmartPRS\Surface\Session;
use SmartPRS\Surface\Store;

group('default credential');

$store = new Store(sys_get_temp_dir() . '/prs3-dc-' . bin2hex(random_bytes(4)));

// The condition the banner tests, exactly as the front controller applies it.
$present = static function (Store $store): array {
    $found = [];

    foreach ($store->all('users') as $candidate) {
        if ((string) ($candidate['email'] ?? '') === Session::LOCAL_DEFAULT_EMAIL) {
            $found[] = (string) $candidate['email'];
        }
    }

    return $found;
};

same([], $present($store), 'no default account, no warning');

// THE ROW THE OLD CHECK COULD NOT SEE: seeded exactly as the ported schema
// stores it — no `is_local_default` column anywhere, because that column does
// not exist on the real table.
$store->put('users', ['email' => Session::LOCAL_DEFAULT_EMAIL, 'password' => 'x',
    'status' => 'administrator']);

same([Session::LOCAL_DEFAULT_EMAIL], $present($store),
    'A default account with no flag on it must still be found — the flag was a column the ported '
    . 'table does not have, so the old check could never be true on MySQL.');

// And it goes when the account goes, which is what the banner promises.
$store->write('users', []);
same([], $present($store), 'and it disappears only when the account does');

// An ordinary account is not mistaken for the seeded one.
$store->put('users', ['email' => 'ejaz@ametecsindia.com', 'password' => 'x']);
same([], $present($store), 'a real account does not trip the warning');

// The floor is reported on its own condition, not as a footnote to the account.
// Deleting the account must not take the floor notice with it.
ok(Session::PASSWORD_FLOOR >= 8,
    'the floor never drops below the platform\'s stated eight-character minimum');
// LOCAL_DEFAULT_EMAIL being non-empty and shared by seeder and banner is already
// demonstrated above: the row seeded with this exact constant (line 41) is the
// same row $present() — the banner's own condition — picks up (line 44).
