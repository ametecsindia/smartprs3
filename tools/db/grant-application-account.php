<?php

declare(strict_types=1);

/**
 * GRANT THE APPLICATION ACCOUNT EXACTLY WHAT IT NEEDS, AND NOTHING MORE.
 *
 *   php tools/db/grant-application-account.php            # print the SQL
 *   php tools/db/grant-application-account.php --apply    # run it
 *
 * Law 2: the application account cannot execute DDL. Schema changes are
 * migrations, run deliberately by a different account that a person invokes on
 * purpose — an application that can ALTER a table is one where a bad deploy
 * changes a live schema because a page was loaded.
 *
 * APPEND-ONLY TABLES GET NARROWER GRANTS STILL, and the list is read from the
 * gate rather than repeated here. Two copies of a privilege list is one copy
 * that goes stale, and the stale one is always the one in force.
 *
 * The grants are per TABLE, not per database, because a database-level grant
 * covers tables that do not exist yet — including the append-only one somebody
 * adds next month, which would arrive writable.
 *
 * ponytail: no roles, no per-environment split. Add those when there is more
 * than one deployment to differ.
 */
require_once __DIR__ . '/../gates/checks/db-privilege.php';
require_once __DIR__ . '/../../surface/Database.php';

use SmartPRS\Surface\Database;

$root = dirname(__DIR__, 2);
$env = Database::env($root . '/.env');
$database = $env['DB_DATABASE'] ?? '';
$account = $env['DB_USERNAME'] ?? '';
$password = $env['DB_PASSWORD'] ?? '';
$apply = in_array('--apply', $argv, true);

if ($database === '' || $account === '') {
    fwrite(STDERR, "The .env file must name DB_DATABASE and DB_USERNAME.\n");

    exit(2);
}

if ($account === 'root') {
    // Refused rather than warned about: root holds DDL on everything, so
    // granting it "exactly what it needs" is a no-op that looks like it worked.
    fwrite(STDERR, "DB_USERNAME is 'root'. Point the application at its own account first — "
        . "granting privileges to root changes nothing and the gate will still fail.\n");

    exit(2);
}

// The migration account, which is the one that HAS DDL. Read from .env so the
// two accounts are configured in one place.
$adminUser = $env['DB_MIGRATION_USERNAME'] ?? 'root';
$adminPassword = $env['DB_MIGRATION_PASSWORD'] ?? '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $database),
    $adminUser,
    $adminPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$statement = $pdo->prepare(
    'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME'
);
$statement->execute([$database]);
$tables = $statement->fetchAll(PDO::FETCH_COLUMN);

if ($tables === []) {
    fwrite(STDERR, "`{$database}` has no tables. Run the migrations first: granting on an empty "
        . "database produces a set of grants that covers nothing.\n");

    exit(2);
}

// The `IDENTIFIED ...` clause this server accepts, asked rather than assumed — see
// tools/db/auth-plugin.php for why naming the plugin is necessary on MySQL and a
// syntax error on MariaDB.
require_once __DIR__ . '/auth-plugin.php';

try {
    $identified = smartprs_identified_clause($pdo, $password);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(2);
}

$sql = [];

// THIS SCRIPT GRANTS. IT DOES NOT CREATE ACCOUNTS.
//
// Recreating a user needs the GLOBAL `CREATE USER` privilege, and this connects as
// the MIGRATION account, which holds rights on one database and deliberately
// nothing outside it. The first version always dropped and recreated the account,
// so it died with "you need the CREATE USER privilege" on a correctly-locked-down
// server and worked only where the migrator had been over-granted. A setup step
// that succeeds only against a permissive configuration is a step that teaches
// people to make one.
//
// Whether the account exists is not checked up front either: reading `mysql.user`
// is itself a privilege this connection should not have, and a check that needs
// more rights than the thing it is checking is the same mistake one level up. The
// GRANT is attempted, and the server's own answer is reported.

foreach (['localhost', '127.0.0.1'] as $host) {
    // Revoked first so the result is exactly what is listed below rather than
    // whatever accumulated across previous runs. A grant nobody remembers adding is
    // the one that turns out to matter.
    $sql[] = sprintf("REVOKE ALL PRIVILEGES ON `%s`.* FROM '%s'@'%s';",
        $database, $account, $host);
}

$narrowed = [];

foreach ($tables as $table) {
    // GATE_APPEND_ONLY is the gate's own constant. If a table is added to it,
    // the next run of this script narrows the grant without anybody editing here.
    $denied = GATE_APPEND_ONLY[$table] ?? [];
    $granted = array_values(array_diff(['SELECT', 'INSERT', 'UPDATE', 'DELETE'], $denied));

    if ($denied !== []) {
        $narrowed[$table] = $denied;
    }

    foreach (['localhost', '127.0.0.1'] as $host) {
        $sql[] = sprintf('GRANT %s ON `%s`.`%s` TO \'%s\'@\'%s\';',
            implode(', ', $granted), $database, $table, $account, $host);
    }
}

// NO `FLUSH PRIVILEGES`. It needs the global RELOAD privilege, which this
// connection deliberately does not have — and it does nothing here anyway: MySQL
// reloads the grant tables itself after GRANT and REVOKE. It was a habit copied
// from scripts that edit `mysql.user` directly, and it turned a completed run into
// a failed one on the last line.


if (!$apply) {
    echo implode("\n", $sql), "\n";

    exit(0);
}

foreach ($sql as $line) {
    try {
        $pdo->exec($line);
    } catch (PDOException $e) {
        // A REVOKE against an account that holds nothing yet is not a failure; it
        // is the first run. Everything else is reported with the statement that
        // caused it, because "access denied" with no statement beside it is a
        // message somebody has to reverse-engineer.
        if (str_starts_with($line, 'REVOKE') && str_contains($e->getMessage(), '1141')) {
            continue;
        }

        fwrite(STDERR, "Failed on:\n    {$line}\n\n" . $e->getMessage() . "\n");

        // The one failure worth translating: MySQL says "there is no such grant"
        // (1141/1410) when the account does not exist at all, which reads as a
        // permissions problem and is a missing account.
        if (str_contains($e->getMessage(), '1410') || str_contains($e->getMessage(), '1133')
            || str_contains($e->getMessage(), '1396')) {
            fwrite(STDERR, "\nThat usually means the account '{$account}' does not exist yet. This\n"
                . "script grants to an account; it does not create one, because creating one needs\n"
                . "a global privilege its connection deliberately lacks.\n\n"
                . "On a laptop:   php tools/db/bootstrap-local.php\n"
                . "Anywhere else: have whoever makes your database accounts create it, then run\n"
                . "               this to give it exactly what the application needs.\n");
        }

        exit(1);
    }
}

printf("granted on %d table(s) to '%s'; %d table(s) narrowed for being append-only:\n",
    count($tables), $account, count($narrowed));

foreach ($narrowed as $table => $denied) {
    printf("  %-24s no %s\n", $table, implode(', ', $denied));
}

echo "No DDL was granted anywhere — that is Law 2, and tools/gates/checks/db-privilege.php\n"
    . "fails the build if it ever stops being true.\n";
