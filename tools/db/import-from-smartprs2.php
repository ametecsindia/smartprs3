<?php

declare(strict_types=1);

/**
 * COPY SMARTPRS 2'S DATA INTO SMARTPRS 3. READ THE OLD DATABASE, NEVER WRITE IT.
 *
 * SmartPRS 3's schema is a PORT of SmartPRS 2's — most of `database/migrations/`
 * is named `port_*` and builds the same tables with a `tenant_id` in front. So the
 * data can be carried across, and until this file existed the only way to see the
 * new product against real data was to type it in again.
 *
 * THE SOURCE DATABASE IS OPENED READ-ONLY, AND THAT IS ENFORCED RATHER THAN
 * PROMISED. The owner's instruction is that SmartPRS 2 is never edited — it is the
 * system his business runs on today, and a migration tool that can write to the
 * database it is reading from is one bad WHERE clause away from being the worst
 * day of the project. So the source is reached through `SourceReader` below, which
 * exposes exactly one method, refuses any statement that is not a SELECT, and sets
 * the session read-only in MySQL as well. Two locks, because the one you are sure
 * about is the one that turns out to have a gap.
 *
 * IT NEVER MIGRATES THE SOURCE. Nothing here runs a migration, an ALTER, or a
 * CREATE against `smartprs`. SmartPRS 3's own migrations run against SmartPRS 3's
 * own database, which is a different database on the same server, and pointing
 * SmartPRS 3's .env at the SmartPRS 2 database would silently alter it the first
 * time somebody ran the setup script. This file refuses to run at all if the two
 * names are the same.
 *
 * WHAT IT COULD NOT COPY IS REPORTED, NOT SKIPPED. A table the port renamed, a
 * column that no longer exists, a row whose foreign key points at nothing — each
 * is named and counted. An importer that quietly copies eleven of nineteen tables
 * and prints "done" is how somebody discovers in March that attendance stopped in
 * January.
 *
 * Usage:
 *   php tools/db/import-from-smartprs2.php --source=smartprs [--tenant=1] [--dry-run]
 *   php tools/db/import-from-smartprs2.php --source=smartprs --truncate   (replace, not merge)
 *
 * ponytail: whole-table copies in id order, no batching, no resume. It runs on a
 * laptop against one company's data and takes seconds. Batch it when somebody
 * imports a database that does not fit in memory — the shape here does not change,
 * the SELECT grows a LIMIT and an offset.
 */

require_once __DIR__ . '/../../surface/RowStore.php';
require_once __DIR__ . '/../../surface/Store.php';
require_once __DIR__ . '/../../surface/SqlStore.php';
require_once __DIR__ . '/../../surface/Database.php';

use SmartPRS\Surface\Database;

/**
 * The source connection, which can only read.
 *
 * Not a convention and not a comment: there is no method on this object that
 * writes, the statement is checked before it is sent, and the MySQL session is put
 * in read-only mode so the server refuses too. A tool that reads a live business
 * database has to be safe by construction, because the person running it is
 * usually running it at the end of a long day.
 */
// Named SourceReader rather than ReadOnly: `readonly` is a PHP keyword and the class
// would not parse — which is a small reminder that the language agrees this is a
// property worth reserving a word for.
final class SourceReader
{
    public function __construct(private readonly PDO $pdo)
    {
        // The server's own refusal, so a mistake in this file is not the only
        // thing standing between the tool and somebody's live payroll data.
        $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $bind = []): array
    {
        if (!preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) {
            throw new RuntimeException(
                'Refused: this connection reads SmartPRS 2 and nothing else. The statement was not '
                . "a SELECT:\n    " . trim($sql) . "\nThe source database is the system the business "
                . 'runs on today. A tool that can write to it is one bad WHERE clause away from '
                . 'being unrecoverable, so the ability is not present rather than merely unused.');
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

/** RFC-4122 v4, from the CSPRNG. One call site; no library earns its keep here. */
function smartprs_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    // str_split() cannot return false here: PHP 8+ always returns an array
    // for a positive chunk length, and 2 is a literal.
    return implode('-', array_map('bin2hex',
        [substr($bytes, 0, 4), substr($bytes, 4, 2), substr($bytes, 6, 2),
           substr($bytes, 8, 2), substr($bytes, 10, 6)]));
}

$root = dirname(__DIR__, 2);

$options = ['source' => '', 'tenant' => '', 'dry-run' => false, 'truncate' => false];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m)) {
        if (!array_key_exists($m[1], $options)) {
            fwrite(STDERR, "Unknown option {$argument}.\n");

            exit(2);
        }

        $options[$m[1]] = $m[2] ?? true;
    }
}

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

// ------------------------------------------------------------------ the target
$target = Database::connect($root . '/.env');

if ($target === null) {
    $refuse('SmartPRS 3 has no database.', Database::why(), '',
        'Set DB_USERNAME and DB_PASSWORD in .env, then build the schema:',
        '    php tools/db/migrate.php');
}

$targetName = (string) $target->query('SELECT DATABASE()')->fetchColumn();
$source = (string) ($options['source'] ?: 'smartprs');

// THE ONE REFUSAL THAT MATTERS MOST. If somebody points SmartPRS 3's .env at the
// SmartPRS 2 database — an easy thing to do while trying to "connect to the real
// data" — then the setup script's migrations would run against it, and SmartPRS 2
// would be altered by a tool nobody thought was a migration tool.
if (strcasecmp($targetName, $source) === 0) {
    $refuse(sprintf('SmartPRS 3 and SmartPRS 2 are both pointed at `%s`.', $source),
        'This is refused rather than allowed to proceed, and it is the most important check in',
        'this file. SmartPRS 3 runs its own migrations against its own database; if that database',
        'IS SmartPRS 2, the next `php tools/db/migrate.php` alters the system the business runs on',
        'today. Point DB_DATABASE in .env at a separate database — `smartprs3` — and copy the data',
        'across with this tool instead.');
}

// -------------------------------------------------------------- the source
// Same server, same credentials, different database. Read out of the target's own
// DSN so there is one place that knows where MySQL is.
try {
    // Database::env(), never parse_ini_file() — see tools/db/bootstrap-local.php.
    $env = Database::env($root . '/.env');
    $sourcePdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $source),
        (string) ($env['DB_USERNAME'] ?? ''),
        (string) ($env['DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    $refuse(sprintf('could not open the SmartPRS 2 database `%s`: %s', $source, $e->getMessage()),
        'It is opened with the SAME credentials as SmartPRS 3, from the same .env. If SmartPRS 2',
        'uses a different user, that user needs SELECT on this database — and SELECT is all it',
        'needs, because this tool never writes to it.');
}

$reader = new SourceReader($sourcePdo);

// -------------------------------------------------- which tenant receives the data
$tenant = (string) ($options['tenant'] ?: (string) $target->query(
    'SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn());

if ($tenant === '') {
    $refuse('there is no tenant in SmartPRS 3 to import into.',
        'Every row in SmartPRS 3 belongs to a tenant — that is structural here, not a filter, and',
        'a row with no tenant is a row no screen can ever show. Create one first:',
        '    php tools/seed-local-admin.php');
}

// ------------------------------------------------------------ what maps to what
// DISCOVERED, NOT HARD-CODED. The port kept SmartPRS 2's table and column names on
// purpose, so the mapping is "the same name, where both sides have it" — and a
// list typed out here would be a second copy of the schema that goes stale the
// first time a migration adds a column.
$targetTables = array_column($reader->select(
    'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$targetName]),
    't');
$sourceTables = array_column($reader->select(
    'SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$source]), 't');

if ($sourceTables === []) {
    $refuse(sprintf('`%s` has no tables, so there is nothing to import.', $source),
        'Named the wrong database? SmartPRS 2\'s .env says which one it uses.');
}

// Tables SmartPRS 3 owns and SmartPRS 2 never had: its own bookkeeping. Importing
// into them would be importing into the machinery rather than the data.
$ours = ['migrations', 'tenants', 'users', 'module_customisations', 'audit_entries',
    'payroll_runs', 'workflow_requests', 'workflow_decisions'];

$plan = array_values(array_intersect($targetTables, $sourceTables));
$plan = array_values(array_diff($plan, $ours));
sort($plan);

$notInTarget = array_values(array_diff($sourceTables, $targetTables));

if ($plan === []) {
    $refuse('no table exists in both databases.',
        'That is not an empty import, it is a mapping that did not happen: the port renamed',
        'everything, or one of the two names above is wrong. Reported rather than run, because an',
        'importer that copies nothing and prints "done" is worse than one that fails.');
}

printf("SmartPRS 2  %s  (read-only)\nSmartPRS 3  %s  tenant %s\n%s\n\n",
    $source, $targetName, $tenant,
    $options['dry-run'] ? 'DRY RUN — nothing will be written.' : '');

// ------------------------------------------------------------------- the copy
$copied = [];
$empty = [];
$noColumns = [];
$failed = [];
$total = 0;

foreach ($plan as $table) {
    $sourceColumns = array_column($reader->select(
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? '
        . 'AND TABLE_NAME = ?', [$source, $table]), 'c');
    $targetColumns = array_column($reader->select(
        'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? '
        . 'AND TABLE_NAME = ?', [$targetName, $table]), 'c');

    // `id` is deliberately carried across: the rows reference each other by it, and
    // renumbering on the way in would silently break every foreign key in the copy.
    $shared = array_values(array_intersect($sourceColumns, $targetColumns));

    if (array_diff($shared, ['id']) === []) {
        // A TABLE WITH THE SAME NAME AND NO SHARED COLUMN IS A FINDING. It means
        // the port rebuilt it differently, and copying its ids alone would create
        // rows that exist and hold nothing.
        $noColumns[$table] = count($sourceColumns) . ' source column(s), '
            . count($targetColumns) . ' target, nothing in common but the id';

        continue;
    }

    // COLUMNS SMARTPRS 3 INSISTS ON AND SMARTPRS 2 DOES NOT SUPPLY. The ported
    // tables carry NOT NULL `created_at`/`updated_at` with no default, so a copy
    // that only carried the shared columns failed every table on the first real
    // run — found by testing this tool against a stand-in database rather than by
    // a customer finding it against theirs.
    //
    // Only timestamps are filled in, and only with the time of the import, which
    // is honest: it is when this row entered THIS system, and nothing here knows
    // when it entered the old one. Any OTHER mandatory column with no default is
    // refused rather than invented — a made-up value in a column somebody made
    // mandatory is a lie the database was specifically asked to prevent.
    $mandatory = $reader->select(
        'SELECT COLUMN_NAME AS c, DATA_TYPE AS t FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND IS_NULLABLE = "NO" '
        . 'AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE "%auto_increment%"',
        [$targetName, $table]);
    $supply = [];
    $cannotSupply = [];

    foreach ($mandatory as $column) {
        if (in_array($column['c'], $shared, true) || $column['c'] === 'tenant_id') {
            continue;
        }

        if (in_array($column['t'], ['timestamp', 'datetime'], true)) {
            $supply[$column['c']] = date('Y-m-d H:i:s');

            continue;
        }

        $cannotSupply[] = (string) $column['c'];
    }

    if ($cannotSupply !== []) {
        $noColumns[$table] = 'SmartPRS 3 requires ' . implode(', ', $cannotSupply)
            . ' and SmartPRS 2 has no such column. Refused rather than filled with a made-up '
            . 'value: a column somebody made mandatory is one an invented value corrupts quietly';

        continue;
    }

    // A UUID PER ROW, WHICH SMARTPRS 2 HAS NO COLUMN FOR. It is nullable here, so
    // it never appears among the mandatory columns above and the copy would leave
    // it empty — and `/api/v3` addresses an employment by its uuid and nothing
    // else, deliberately, because a sequential integer in a URL lets anyone who
    // can count measure a headcount. Imported rows would therefore list through
    // the API and be addressable by nobody, for ever. Filled here so the customer's
    // real history is reachable the moment it lands; `tools/db/backfill-uuids.php`
    // is the same fix for a database imported before this line existed.
    $needsUuid = in_array('uuid', $targetColumns, true) && !in_array('uuid', $shared, true);

    $rows = $reader->select(sprintf('SELECT `%s` FROM `%s`.`%s`',
        implode('`, `', $shared), $source, $table));

    if ($rows === []) {
        $empty[] = $table;

        continue;
    }

    if ($options['dry-run']) {
        $copied[$table] = count($rows);
        $total += count($rows);
        printf("  %-32s %6d row(s) would be copied over %d column(s)\n",
            $table, count($rows), count($shared));

        continue;
    }

    try {
        $target->beginTransaction();

        if ($options['truncate']) {
            // Scoped to the tenant, never the table. Another customer's rows are
            // not this import's to remove.
            $target->prepare("DELETE FROM `{$table}` WHERE tenant_id = ?")->execute([$tenant]);
        }

        $columns = array_merge(['tenant_id'], $shared, array_keys($supply),
            $needsUuid ? ['uuid'] : []);
        $insert = $target->prepare(sprintf('REPLACE INTO `%s` (`%s`) VALUES (%s)',
            $table, implode('`, `', $columns),
            implode(', ', array_fill(0, count($columns), '?'))));

        foreach ($rows as $row) {
            $insert->execute(array_merge([$tenant], array_values($row), array_values($supply),
                $needsUuid ? [smartprs_uuid()] : []));
        }

        $target->commit();
        $copied[$table] = count($rows);
        $total += count($rows);
        printf("  %-32s %6d row(s) over %d column(s)\n", $table, count($rows), count($shared));
    } catch (Throwable $e) {
        $target->rollBack();
        // NAMED AND COUNTED, and the run continues. One table failing on a foreign
        // key should not cost the other eighteen — but it must not vanish either,
        // and a partial import that reported success is the thing this avoids.
        $failed[$table] = $e->getMessage();
        printf("  %-32s FAILED\n", $table);
    }
}

echo "\n";

foreach ($failed as $table => $why) {
    echo "FAILED  {$table}\n    " . substr(trim($why), 0, 200) . "\n";
}

if ($noColumns !== []) {
    echo "\nNOT IMPORTED — same table name, incompatible shape. The port rebuilt these, so the old\n"
        . "rows have nowhere to go and were NOT silently dropped into a partial insert:\n";

    foreach ($noColumns as $table => $why) {
        echo "    {$table} — {$why}\n";
    }
}

if ($empty !== []) {
    echo "\nEMPTY IN SMARTPRS 2 (nothing to copy, which is not the same as nothing copied): "
        . implode(', ', $empty) . "\n";
}

if ($notInTarget !== []) {
    echo "\nIN SMARTPRS 2 AND NOT IN SMARTPRS 3 — " . count($notInTarget) . " table(s) the port has\n"
        . "not brought across yet. Listed rather than passed over: each one is data this product\n"
        . "cannot yet hold, and that is a decision somebody should make on purpose.\n    "
        . implode(', ', array_slice($notInTarget, 0, 40))
        . (count($notInTarget) > 40 ? ' … and ' . (count($notInTarget) - 40) . ' more' : '') . "\n";
}

printf("\n%s  %d table(s), %d row(s)%s. %d empty, %d incompatible, %d failed.\n",
    $failed === [] ? 'DONE' : 'DONE WITH FAILURES',
    count($copied), $total, $options['dry-run'] ? ' (dry run — nothing written)' : '',
    count($empty), count($noColumns), count($failed));

echo "\nSmartPRS 2 was opened read-only and not written to.\n";

exit($failed === [] ? 0 : 1);
