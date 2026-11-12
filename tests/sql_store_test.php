<?php

declare(strict_types=1);

use SmartPRS\Surface\SqlStore;

/**
 * The SQL store's tenancy properties, proven against a real database rather than
 * described in a comment. Skips when no database is reachable, and the runner
 * reports the skip — a suite that silently passes with nothing connected is the
 * check that stops checking.
 */

group('SqlStore');

/**
 * `throws()` matches the exception CLASS. What matters here is the MESSAGE: a
 * refusal whose text stops explaining itself is a refusal nobody can act on, and
 * every one of these carries the reason as its whole point.
 */
function refuses(string $expectedText, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        ok(
            str_contains($e->getMessage(), $expectedText),
            $message . ' (said "' . substr($e->getMessage(), 0, 60) . '…")',
        );

        return;
    }

    ok(false, $message . ' — nothing was thrown, so the refusal does not happen at all');
}

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_sqlstore_test';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! SqlStore tests skipped: no database ({$e->getMessage()})\n";

    return;
}

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");
$pdo->exec('CREATE TABLE tenants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255), created_at DATETIME, updated_at DATETIME)');
$pdo->exec('CREATE TABLE employees (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255), code VARCHAR(64),
    created_at DATETIME, updated_at DATETIME,
    CONSTRAINT fk_employees_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id))');

$platform = new SqlStore($pdo);
$a = $platform->put('tenants', ['name' => 'Tenant A']);
$b = $platform->put('tenants', ['name' => 'Tenant B']);

$A = $platform->forTenant((string) $a['id']);
$B = $platform->forTenant((string) $b['id']);

$A->put('employees', ['name' => 'Asha', 'code' => 'E001']);
$A->put('employees', ['name' => 'Vikram', 'code' => 'E002']);
$B->put('employees', ['name' => 'Someone else', 'code' => 'X1']);

same(2, count($A->all('employees')), 'a tenant sees only its own rows');
same(1, count($B->all('employees')), 'and the other tenant sees only its own');
same(3, (int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn(),
    'while the table holds all of them — the filter is in the store, not in the data');

$mine = $A->all('employees')[0];
$id = (string) $mine['id'];

// NOT FOUND, not forbidden: "forbidden" confirms the id exists, which is an
// answer worth having if you are guessing ids.
same(null, $B->find('employees', $id), "another tenant's row is NOT FOUND rather than forbidden");

$mine['name'] = 'Asha Rao';
$A->put('employees', $mine);
same('Asha Rao', $A->find('employees', $id)['name'], 'an update by id round-trips');
same(2, count($A->all('employees')), 'and updates rather than inserting a second row');

refuses(
    'belongs to another tenant',
    static fn (): array => $B->put('employees', ['id' => $id, 'name' => 'stolen']),
    "writing another tenant's row is refused by name, not by a duplicate-key error",
);
same(2, count($A->all('employees')), 'and the attempt left the other tenant untouched');
same('Asha Rao', $A->find('employees', $id)['name'], 'with its value unchanged');

// A field that appears to save and does not is discovered months later by
// whoever needed it.
refuses(
    'has no column',
    static fn (): array => $A->put('employees', ['name' => 'x', 'no_such_column' => 1]),
    'a column the table does not have is refused rather than silently dropped',
);

refuses(
    'There is no table',
    static fn (): array => $A->all('no_such_table'),
    'a missing table is a refusal, never an empty result set',
);

// An identifier cannot be a bound parameter, so it is checked rather than escaped.
refuses(
    'is not a possible table name',
    static fn (): array => $A->all('employees; DROP TABLE tenants'),
    'an impossible table name is refused before it reaches the query',
);

refuses(
    'does not exist on the SQL store',
    static fn (): never => $B->write('employees', []),
    'write() is absent: replacing a whole table would delete every other tenant',
);

ok($A->find('employees', $id)['created_at'] !== null, 'timestamps are filled in on insert');
same(0, count($platform->forTenant('99999')->all('employees')),
    'a tenant with no rows sees none, rather than everything');

$pdo->exec("DROP DATABASE `{$database}`");
