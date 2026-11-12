<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use SmartPRS\Platform\Persistence\TableRegistry;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * R-XXII-138 — the two deliberately confusable tenants the isolation suite runs
 * against, built on a real MariaDB from the real migrations.
 *
 * Every tenant-scoped row is inserted THROUGH TenantScopedQuery, never with a
 * hand-written tenant_id. That makes the fixture itself a test of R-XXII-083:
 * if the layer stopped stamping the tenant, seeding would fail before a single
 * isolation assertion ran.
 *
 * The row bodies are synthesised from information_schema rather than written
 * per table, so a new migration is covered the day it lands. Nullable columns
 * and columns with defaults are left alone; only NOT NULL columns with no
 * default are filled, and foreign keys are pointed at a row of the same tenant.
 *
 * @return array{PDO, string, string, string}|null  [pdo, database, tenantA, tenantB]
 */
function tenant_fixture(string $database): ?array
{
    $dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
    $user = getenv('SMARTPRS_TEST_USER') ?: 'root';
    $password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';

    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "  ! skipped: no database ({$e->getMessage()})\n";

        return null;
    }

    require_once __DIR__ . '/laravel_schema_stub.php';

    Schema::reset();

    $files = glob(__DIR__ . '/../../database/migrations/*.php');
    sort($files);

    foreach ($files as $file) {
        (require $file)->up();
    }

    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");

    foreach (Schema::$statements as $statement) {
        $pdo->exec($statement);
    }

    $columns = fixture_columns($pdo, $database);
    $order = fixture_table_order($pdo, $database, array_keys($columns));

    // The two tenants. `tenants` is platform-scoped: it defines the tenant, so
    // it is seeded raw — there is no context to stamp from yet.
    $tenantA = fixture_insert_raw($pdo, 'tenants', fixture_row($pdo, $columns['tenants'], 'alpha', null));
    $tenantB = fixture_insert_raw($pdo, 'tenants', fixture_row($pdo, $columns['tenants'], 'bravo', null));

    foreach ($order as $table) {
        if ($table === 'tenants') {
            continue;
        }

        if (!TableRegistry::isTenantScoped($table)) {
            fixture_insert_raw($pdo, $table, fixture_row($pdo, $columns[$table], 'platform', null));

            continue;
        }

        foreach ([$tenantA => 'alpha', $tenantB => 'bravo'] as $tenantId => $seed) {
            $context = new TenantContext();
            $context->establish((string) $tenantId);

            TenantScopedQuery::on($pdo, $context, $table)
                ->insert(fixture_row($pdo, $columns[$table], $seed, (string) $tenantId));
        }
    }

    return [$pdo, $database, (string) $tenantA, (string) $tenantB];
}

/** @return array<string, list<array<string, mixed>>> table => column metadata */
function fixture_columns(PDO $pdo, string $database): array
{
    $statement = $pdo->prepare(
        'SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                c.CHARACTER_MAXIMUM_LENGTH, c.EXTRA, k.REFERENCED_TABLE_NAME
         FROM information_schema.COLUMNS c
         LEFT JOIN information_schema.KEY_COLUMN_USAGE k
           ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME
          AND k.COLUMN_NAME = c.COLUMN_NAME AND k.REFERENCED_TABLE_NAME IS NOT NULL
         WHERE c.TABLE_SCHEMA = :schema
         ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
    );
    $statement->execute([':schema' => $database]);

    $out = [];

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['TABLE_NAME']][] = $row;
    }

    return $out;
}

/**
 * Referenced tables before referencing ones, so a NOT NULL foreign key always
 * has something to point at. Self-references are ignored — they are nullable.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function fixture_table_order(PDO $pdo, string $database, array $tables): array
{
    $statement = $pdo->prepare(
        'SELECT DISTINCT k.TABLE_NAME AS child, k.REFERENCED_TABLE_NAME AS parent
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.COLUMNS c
           ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME
          AND c.COLUMN_NAME = k.COLUMN_NAME
         WHERE k.TABLE_SCHEMA = :schema AND k.REFERENCED_TABLE_NAME IS NOT NULL
           AND c.IS_NULLABLE = "NO" AND k.TABLE_NAME <> k.REFERENCED_TABLE_NAME'
    );
    $statement->execute([':schema' => $database]);

    $needs = [];

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        $needs[$edge['child']][$edge['parent']] = true;
    }

    $ordered = [];
    $remaining = $tables;

    while ($remaining !== []) {
        $progressed = false;

        foreach ($remaining as $i => $table) {
            if (array_diff_key($needs[$table] ?? [], array_flip($ordered)) !== []) {
                continue;
            }

            $ordered[] = $table;
            unset($remaining[$i]);
            $progressed = true;
        }

        if (!$progressed) {
            throw new RuntimeException('Foreign keys form a cycle: ' . implode(', ', $remaining));
        }
    }

    return $ordered;
}

/**
 * A row body for one table: NOT NULL, no-default columns only. Everything else
 * is left for the database to default or null, which keeps the fixture honest
 * about what a table actually requires.
 *
 * @param  list<array<string, mixed>>  $columns
 * @return array<string, scalar|null>
 */
function fixture_row(PDO $pdo, array $columns, string $seed, ?string $tenantId): array
{
    $row = [];

    foreach ($columns as $column) {
        $name = (string) $column['COLUMN_NAME'];

        if ($name === TableRegistry::TENANT_COLUMN) {
            continue; // stamped by the persistence layer, never by the fixture
        }

        if ($column['IS_NULLABLE'] === 'YES'
            || $column['COLUMN_DEFAULT'] !== null
            || str_contains((string) $column['EXTRA'], 'auto_increment')
            // A generated column is computed, and naming one in an INSERT is an
            // error — `open_marker` is the first of them (V.6.4).
            || str_contains((string) $column['EXTRA'], 'GENERATED')) {
            continue;
        }

        $row[$name] = $column['REFERENCED_TABLE_NAME'] !== null
            ? fixture_parent_id($pdo, (string) $column['REFERENCED_TABLE_NAME'], $tenantId)
            : fixture_value((string) $column['DATA_TYPE'], $column['CHARACTER_MAXIMUM_LENGTH'], $seed . '-' . $name, $name);
    }

    // A TABLE WHOSE EVERY COLUMN IS NULLABLE (a register of optional facts) still
    // needs one value to be a row at all: the first plain text column carries
    // the seed, so the isolation sweep has something of each tenant to look for.
    if ($row === []) {
        foreach ($columns as $column) {
            $name = (string) $column['COLUMN_NAME'];

            if ($name !== TableRegistry::TENANT_COLUMN && $column['REFERENCED_TABLE_NAME'] === null
                && in_array((string) $column['DATA_TYPE'], ['varchar', 'text', 'char'], true)
                && !str_contains((string) $column['EXTRA'], 'GENERATED')) {
                $row[$name] = fixture_value((string) $column['DATA_TYPE'], $column['CHARACTER_MAXIMUM_LENGTH'], $seed . '-' . $name, $name);

                break;
            }
        }
    }

    return $row;
}

function fixture_parent_id(PDO $pdo, string $parent, ?string $tenantId): string
{
    $sameTenant = $tenantId !== null && TableRegistry::isTenantScoped($parent);

    $statement = $pdo->prepare(
        "SELECT id FROM `{$parent}`" . ($sameTenant ? ' WHERE tenant_id = :tenant' : '') . ' ORDER BY id LIMIT 1'
    );
    $statement->execute($sameTenant ? [':tenant' => $tenantId] : []);
    $id = $statement->fetchColumn();

    if ($id === false) {
        throw new RuntimeException("Fixture ordering is wrong: `{$parent}` is empty.");
    }

    return (string) $id;
}

function fixture_value(string $dataType, ?int $length, string $seed, ?string $column = null): string
{
    // MariaDB stores a JSON column as LONGTEXT with a json_valid CHECK, so the
    // data type alone does not say "this must be JSON". Every JSON column in
    // this schema ends in _json, which does.
    if (str_ends_with($column ?? '', '_json')) {
        return '[]';
    }

    // Binary columns are filled with ASCII so the fixture stays readable in a
    // client and never carries a NUL through PDO's emulated prepares.
    return match ($dataType) {
        'bigint', 'int', 'mediumint', 'smallint', 'tinyint' => '1',
        'decimal', 'float', 'double' => '0.0000',
        'date' => '2026-01-01',
        'datetime', 'timestamp' => '2026-01-01 00:00:00',
        'time' => '00:00:00',
        'json' => '{}',
        'binary', 'varbinary', 'blob' => substr(md5($seed), 0, min(16, $length ?? 16)),
        default => substr($seed . '-' . substr(md5($seed), 0, 8), 0, max(1, min(64, $length ?? 64))),
    };
}

/** @param array<string, scalar|null> $row */
function fixture_insert_raw(PDO $pdo, string $table, array $row): string
{
    $names = array_map(static fn (string $c): string => ':' . $c, array_keys($row));

    $statement = $pdo->prepare(
        "INSERT INTO `{$table}` (`" . implode('`, `', array_keys($row)) . '`) VALUES ('
        . implode(', ', $names) . ')'
    );
    $statement->execute(array_combine($names, array_values($row)));

    return $pdo->lastInsertId();
}
