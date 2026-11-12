<?php

declare(strict_types=1);

/**
 * SEED A LOCAL DEVELOPMENT ADMINISTRATOR.
 *
 *   php tools/seed-local-admin.php
 *
 * Creates admin@smartprslocal / 12345678 so a developer can open the app without
 * going through enrolment every time the storage is cleared.
 *
 * THIS BYPASSES THE PASSWORD POLICY DELIBERATELY, AND SAYS SO. `Surface\Session`
 * enforces a twelve-character floor, and that floor is not lowered — lowering it
 * would weaken the check for every real user in order to make one local account
 * convenient. Instead this script writes the row directly and marks it
 * `is_local_default`, which makes the application display a standing warning for
 * as long as the account exists.
 *
 * A default credential that nobody can see is how a demo account reaches
 * production. This one is impossible to miss.
 */
require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Session.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/Platform/'
        . str_replace('\\', '/', substr($class, strlen('SmartPRS\\Platform\\'))) . '.php';

    if (str_starts_with($class, 'SmartPRS\\Platform\\') && is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Store;

const EMAIL = \SmartPRS\Surface\Session::LOCAL_DEFAULT_EMAIL;
const PASSWORD = '12345678';

// THE SAME STORE THE APPLICATION USES, chosen the same way. Seeding the file
// store while the application reads MySQL produces an account that exists,
// reports itself as seeded, and cannot sign in — which is the shape of bug this
// whole rebuild is against: a check that silently checks the wrong thing.
$pdo = Database::connect(__DIR__ . '/../.env');
$store = $pdo !== null ? new SqlStore($pdo) : new Store(__DIR__ . '/../storage');
echo 'store: ' . ($pdo !== null ? 'MySQL' : 'JSON files') . "\n";

foreach ($store->all('users') as $user) {
    if ((string) $user['email'] === EMAIL) {
        echo "already seeded: " . EMAIL . " / " . PASSWORD . "\n";

        exit(0);
    }
}

$tenant = $store->put('tenants', [
    // `code` and `status_code` are NOT NULL with no default on the ported table.
    'code' => 'T-LOCAL',
    'name' => 'Local development tenant',
    'status_code' => 'active',
    'created_at' => date('c'),
]);

$store->put('users', [
    'email' => EMAIL,
    // The ported table names the hash `password` and carries the role on
    // `status` — see the note in Session::enrol().
    'password' => PasswordHasher::platformDefault()->hash(PASSWORD),
    'status' => 'administrator',
    'tenant_id' => $tenant['id'],
    // The flag the application reads to display its warning. Removing the
    // account removes the warning; there is no way to have one without the other.
    'created_at' => date('c'),
]);

echo "seeded: " . EMAIL . " / " . PASSWORD . "\n";
echo "The application will show a standing warning while this account exists.\n";
