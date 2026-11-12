<?php

declare(strict_types=1);

/**
 * CAN THE APPLICATION ACCOUNT ACTUALLY CONNECT? ASKED AT SETUP, NOT AT FIRST USE.
 *
 * The setup script created the application's MySQL account by piping SQL into the
 * `mysql` client and then moved on. `mysql` printed nothing, the script reported
 * success, and the account had been created with an authentication plugin the
 * server does not load — so the application fell back to the JSON file store and
 * said so in a banner, three steps away from the step that actually went wrong.
 *
 * That fallback is deliberate and it is correct: the product tells you, at the top
 * of every screen, that nothing being written is reaching MySQL, because a silent
 * fallback is how a month of payroll ends up in the wrong place. But a banner is
 * where you learn about it, not where you should have to. Setup should have
 * failed, and setup could not fail, because nothing in it ever tried the account it
 * had just made.
 *
 * A CREATED ACCOUNT IS NOT A WORKING ACCOUNT. That is the whole lesson: the command
 * succeeded, the object exists, and it does not do the thing it was made for.
 *
 * Usage: php tools/db/verify-connection.php
 */

require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

use SmartPRS\Surface\Database;

$root = dirname(__DIR__, 2);
$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    $why = Database::why();

    fwrite(STDERR, "FAIL  the application cannot reach MySQL, so it will run on JSON files.\n\n"
        . $why . "\n\n");

    // The two failures a person actually hits, each named with its own fix rather
    // than left inside a driver's message. "SQLSTATE[HY000] [1524]" is not a
    // sentence anybody can act on.
    if (str_contains($why, '1524') || str_contains($why, 'not loaded')) {
        fwrite(STDERR,
            "The account exists and was created with an authentication plugin this server does not\n"
            . "load — MySQL 8.4 REMOVED mysql_native_password, and a my.ini that still names it as\n"
            . "the default makes every new account unusable while CREATE USER reports success.\n\n"
            . "Recreate the account against a plugin the server has:\n"
            . "    php tools/db/grant-application-account.php > grants.sql\n"
            . "    mysql -uroot -p < grants.sql\n"
            . "It now asks the server which plugins are active and names one explicitly.\n");
    } elseif (str_contains($why, '1044')) {
        // 1044 AND 1049 LOOK THE SAME FROM HERE, and saying which it is would be a
        // guess. A least-privilege account is granted table by table on ONE
        // database, so "this database does not exist" and "you hold nothing on
        // this database" are the same refusal from the server's point of view.
        // Both fixes are given rather than the likelier one asserted: a setup
        // message that confidently names the wrong cause sends somebody to fix
        // something that was not broken.
        fwrite(STDERR,
            "The account was accepted and then refused this database. From an account granted table\n"
            . "by table on one database, that is TWO possible causes and the server does not\n"
            . "distinguish them:\n\n"
            . "  - the database does not exist yet   ->  php tools/db/migrate.php\n"
            . "  - it exists and this account holds nothing on it, which is what happens when the\n"
            . "    grants step ran against a different database name than .env now says\n"
            . "                                      ->  php tools/db/grant-application-account.php > grants.sql\n"
            . "                                          mysql -uroot -p < grants.sql\n\n"
            . "Check DB_DATABASE in .env first: if it changed since the grants were made, both of\n"
            . "the above are explained by the same edit.\n");
    } elseif (str_contains($why, '1045') || str_contains($why, 'Access denied')) {
        fwrite(STDERR,
            "The credentials in .env were refused. DB_USERNAME= empty is the usual cause — an empty\n"
            . "username is not a default, it is an unanswered question. On a stock Laragon it is\n"
            . "`root`; SmartPRS 2's own .env holds the values that work on this machine.\n");
    } elseif (str_contains($why, '1049') || str_contains($why, 'Unknown database')) {
        fwrite(STDERR, "The database does not exist yet. Build it:\n    php tools/db/migrate.php\n");
    }

    exit(1);
}

// CONNECTING IS NOT ENOUGH. The account could open the database and hold no rights
// on the tables in it, which fails later, on a screen, mid-payroll. So the check is
// a real read and a real write against a real table.
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
    . $pdo->quote($database))->fetchColumn();

if ($tables === 0) {
    fwrite(STDERR, "FAIL  connected to `{$database}`, and it has no tables.\n\n"
        . "Connecting to an empty database is the failure that looks most like success: every\n"
        . "screen renders, every list is empty, and nothing says why.\n\n"
        . "    php tools/db/migrate.php\n");

    exit(1);
}

// CONNECTING IS NOT ENOUGH, and neither is a write probe. The first version of this
// created a TEMPORARY table — which needs CREATE TEMPORARY TABLES, a privilege the
// grant script deliberately withholds, so it reported FAIL against a correctly
// configured install. A check that fails on a healthy system is worse than no
// check: it teaches the person running setup that the red line is normal.
//
// So the grants are read instead. What the account may DO is exactly the question,
// and the server will answer it without anything being written.
$grants = implode(' ', array_map(
    static fn (array $row): string => (string) reset($row),
    $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_ASSOC),
));
$missing = array_values(array_filter(['INSERT', 'UPDATE', 'DELETE'],
    static fn (string $verb): bool
        => !str_contains($grants, $verb) && !str_contains($grants, 'ALL PRIVILEGES')));

if ($missing !== []) {
    fwrite(STDERR, "FAIL  connected to `{$database}` with no " . implode('/', $missing)
        . " grant.\n\nA read-only application account renders every screen perfectly and loses "
        . "every punch, every\nleave request and every payroll run — the failure arrives at the "
        . "moment somebody presses\nSave, which is the worst moment for it. Re-run the grants "
        . "step:\n    php tools/db/grant-application-account.php > grants.sql\n"
        . "    mysql -uroot -p < grants.sql\n");

    exit(1);
}

printf("PASS  connected to `%s` as the application account: %d table(s), read and write both work.\n",
    $database, $tables);
printf("      %d employment(s) visible.\n",
    (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());

exit(0);
