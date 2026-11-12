<?php

declare(strict_types=1);

/**
 * GIVE EVERY ROW THE IDENTIFIER THE API ADDRESSES IT BY.
 *
 * `/api/v3` identifies an employment by its `uuid`, never by its integer id, and
 * that is `Platform\Api\RouteGrammar`'s rule rather than a preference: a
 * sequential integer in a URL is an enumeration oracle, and a customer who can
 * count can measure a competitor's headcount through their own tenant's API.
 *
 * `Surface\SqlStore::put()` stamps a UUID v7 on every insert, so anything this
 * build creates is addressable. Two sets of rows are not, and the second is the
 * one that matters:
 *
 *   1. Rows written before that stamping existed.
 *   2. EVERY ROW IMPORTED FROM SMARTPRS 2. That schema has no such column, so
 *      the importer had nothing to copy — which means a customer's entire real
 *      history lists through the API and cannot be addressed, for ever.
 *
 * The API reports that state rather than hiding it ("unaddressable": 3 on the
 * page envelope, "id": null on the row) and deliberately does NOT fall back to
 * the integer id, which would undo the grammar's one rule through a nullable
 * column. So the filling belongs here.
 *
 * WHY A TOOL AND NOT A MIGRATION. This project's migration runner applies SCHEMA
 * statements — it has no query facade, and giving it one so that a data fix could
 * be written as a migration would be a new facility built for one job. This is
 * data, it runs once, and it says what it did.
 *
 * IT FILLS ONLY WHAT IS EMPTY. An existing uuid is never rewritten. It may
 * already be in somebody's integration, in a third party's records, or printed on
 * a document, and a tool that reissues identifiers breaks every link anybody has
 * made to them. Re-running is therefore safe and is the normal case after an
 * import.
 *
 * Usage:
 *   php tools/db/backfill-uuids.php            report what is missing, write nothing
 *   php tools/db/backfill-uuids.php --apply    fill them
 */

require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

use SmartPRS\Surface\Database;

$root = dirname(__DIR__, 2);
$apply = in_array('--apply', $argv, true);

// THE MIGRATION ACCOUNT, not the application's. Filling a column across every
// table is a data-shape change, and the application account is deliberately not
// the one that makes those — the same separation Law 2 draws for DDL.
$env = Database::env($root . '/.env');
$user = (string) ($env['DB_MIGRATION_USERNAME'] ?? '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306',
            $env['DB_DATABASE'] ?? 'smartprs3'),
        $user !== '' ? $user : (string) ($env['DB_USERNAME'] ?? ''),
        $user !== '' ? (string) ($env['DB_MIGRATION_PASSWORD'] ?? '')
            : (string) ($env['DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Refused: could not connect as the migration account: " . $e->getMessage()
        . "\n\nFilling a column across every table is a data-shape change, so it runs as the\n"
        . "migration account rather than the application's — the same separation Law 2 draws for\n"
        . "DDL. On a laptop: php tools/db/bootstrap-local.php\n");

    exit(2);
}

// Asked of the schema rather than listed here: a list typed into a tool is a
// second copy of the schema that goes stale on the next migration somebody writes.
$tables = array_column($pdo->query(
    'SELECT TABLE_NAME AS t FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = "uuid" ORDER BY TABLE_NAME'
)->fetchAll(PDO::FETCH_ASSOC), 't');

if ($tables === []) {
    fwrite(STDERR, "Refused: no table in this database has a `uuid` column.\n\n"
        . "That is not an empty backfill, it is a schema that has not been migrated:\n"
        . "    php tools/db/migrate.php\n");

    exit(2);
}

$missing = [];
$total = 0;

foreach ($tables as $table) {
    $count = (int) $pdo->query(sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE `uuid` IS NULL OR `uuid` = %s', $table, "''"
    ))->fetchColumn();

    if ($count > 0) {
        $missing[$table] = $count;
        $total += $count;
    }
}

if ($missing === []) {
    printf("Every row in %d table(s) with a `uuid` column already has one. Nothing to do.\n",
        count($tables));

    exit(0);
}

foreach ($missing as $table => $count) {
    printf("  %-34s %6d row(s) with no uuid\n", $table, $count);
}

if (!$apply) {
    printf("\n%d row(s) across %d table(s) are unaddressable through /api/v3.\n"
        . "Nothing was written. Re-run with --apply to fill them.\n", $total, count($missing));

    exit(1);
}

$filled = 0;

foreach (array_keys($missing) as $table) {
    // Generated per row BY THE DATABASE. A PHP loop over a customer's full
    // history is the migration that times out on the one installation big enough
    // to matter.
    //
    // ponytail: UUID() is v1 (time-and-node) where SqlStore stamps v7. Both are
    // RFC-4122 and both are opaque to every caller — the API sorts by id and
    // pages by cursor, so nothing here orders on the identifier. Swap in a v7
    // generator the day a customer's integration sorts on it.
    $filled += (int) $pdo->exec(sprintf(
        'UPDATE `%s` SET `uuid` = UUID() WHERE `uuid` IS NULL OR `uuid` = %s', $table, "''"
    ));
}

$left = 0;

foreach (array_keys($missing) as $table) {
    $left += (int) $pdo->query(sprintf(
        'SELECT COUNT(*) FROM `%s` WHERE `uuid` IS NULL OR `uuid` = %s', $table, "''"
    ))->fetchColumn();
}

printf("\n%s  %d row(s) filled across %d table(s); %d still without one.\n",
    $left === 0 ? 'DONE' : 'DONE WITH ROWS LEFT', $filled, count($missing), $left);
echo "Existing uuids were not touched: a reissued identifier breaks every link anybody made\n"
    . "to the old one.\n";

exit($left === 0 ? 0 : 1);
