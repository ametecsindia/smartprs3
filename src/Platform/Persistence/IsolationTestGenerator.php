<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Tenancy\TenantNotEstablished;

/**
 * Spine Law 6's test: "a generated cross-tenant isolation test exists for every
 * aggregate ... and runs in CI" (R-XXII-137, R-XXII-141).
 *
 * Generated from TableRegistry and the live schema, so a table added without an
 * isolation test is impossible: the table appears in information_schema, the
 * registry treats it as tenant-scoped, and five assertions come into being.
 * Nobody writes them, so nobody forgets them.
 *
 * The generation target is executable assertions rather than PHP source on
 * disk: a committed generated file goes stale, and a file whose only reader is
 * the test runner has no reason to exist. `tests/tenant_isolation_test.php` is
 * a dozen lines that seed two tenants and print what this returns.
 *
 * Every assertion executes real SQL against a real database and inspects rows.
 * Reading the source text of a query proves nothing — R-XXII-082 is that
 * deleting the tenant predicate must BREAK these, and only a query that ran can
 * demonstrate that.
 */
final class IsolationTestGenerator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $schema,
    ) {
    }

    /**
     * Every tenant-scoped table in the live schema, in name order.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME'
        );
        $statement->execute([':schema' => $this->schema]);

        /** @var list<string> $all */
        $all = $statement->fetchAll(\PDO::FETCH_COLUMN);

        return TableRegistry::tenantScoped($all);
    }

    /**
     * The generated suite. Each entry is one assertion and its outcome.
     *
     * @return list<array{table: string, assertion: string, passed: bool, detail: string}>
     */
    public function run(string $tenantA, string $tenantB): array
    {
        $results = [];

        foreach ($this->tables() as $table) {
            try {
                $assertions = $this->assertions($table, $tenantA, $tenantB);
            } catch (\Throwable $e) {
                // Report and carry on: one table whose fixture is missing must
                // not hide what the remaining tables would have said.
                $results[] = [
                    'table' => $table,
                    'assertion' => 'the fixture holds one row for each tenant',
                    'passed' => false,
                    'detail' => $e->getMessage(),
                ];

                continue;
            }

            foreach ($assertions as $name => $check) {
                $results[] = $this->attempt($table, $name, $check);
            }
        }

        return $results;
    }

    /**
     * The five Law 6 assertions, per table. A check returns '' when it holds
     * and a description of what leaked when it does not.
     *
     * @return array<string, callable(): string>
     */
    private function assertions(string $table, string $tenantA, string $tenantB): array
    {
        $contextA = new TenantContext();
        $contextA->establish($tenantA);

        $idA = $this->seededId($table, $tenantA);
        $idB = $this->seededId($table, $tenantB);
        $column = $this->updatableColumn($table);

        return [
            'a query as tenant A never returns tenant B rows' => function () use ($table, $contextA, $tenantA, $idB): string {
                $rows = TenantScopedQuery::on($this->pdo, $contextA, $table)->select(['id', 'tenant_id']);

                if ($rows === []) {
                    return 'the fixture returned nothing at all, so the assertion proves nothing';
                }

                foreach ($rows as $row) {
                    if ((string) $row['tenant_id'] !== $tenantA) {
                        return "row {$row['id']} belongs to tenant {$row['tenant_id']}";
                    }

                    if ((string) $row['id'] === $idB) {
                        return "tenant B's row {$idB} was returned";
                    }
                }

                return '';
            },

            'an update as tenant A cannot modify a tenant B row' => function () use ($table, $contextA, $idB, $column): string {
                $before = $this->readCell($table, $idB, $column);

                $affected = TenantScopedQuery::on($this->pdo, $contextA, $table)
                    ->where('id', '=', $idB)
                    ->update([$column => 'CROSS-TENANT-WRITE']);

                $after = $this->readCell($table, $idB, $column);

                if ($affected !== 0) {
                    return "{$affected} row(s) of tenant B were updated";
                }

                return $before === $after ? '' : "tenant B's {$column} changed from "
                    . var_export($before, true) . ' to ' . var_export($after, true);
            },

            'a delete as tenant A cannot remove a tenant B row' => function () use ($table, $contextA, $idB): string {
                $affected = TenantScopedQuery::on($this->pdo, $contextA, $table)
                    ->where('id', '=', $idB)
                    ->delete();

                if ($affected !== 0) {
                    return "{$affected} row(s) of tenant B were deleted";
                }

                return $this->rowExists($table, $idB) ? '' : "tenant B's row {$idB} is gone";
            },

            'an insert cannot write a foreign tenant_id' => function () use ($table, $contextA, $tenantB, $column): string {
                try {
                    TenantScopedQuery::on($this->pdo, $contextA, $table)
                        ->insert(['tenant_id' => $tenantB, $column => 'CROSS-TENANT-INSERT']);
                } catch (TenancyViolation) {
                    return '';
                }

                return 'a row naming tenant B was accepted';
            },

            'a query with no tenant established throws' => function () use ($table): string {
                try {
                    TenantScopedQuery::on($this->pdo, new TenantContext(), $table)->select(['id']);
                } catch (TenantNotEstablished) {
                    return '';
                }

                return 'a tenant-less query ran instead of throwing';
            },
        ];
    }

    /**
     * @param  callable(): string  $check
     * @return array{table: string, assertion: string, passed: bool, detail: string}
     */
    private function attempt(string $table, string $name, callable $check): array
    {
        try {
            $detail = $check();
        } catch (\Throwable $e) {
            // An exception here is a failure, not an error to hide: an FK
            // violation while deleting another tenant's row means the query
            // reached that row.
            $detail = $e::class . ': ' . $e->getMessage();
        }

        return ['table' => $table, 'assertion' => $name, 'passed' => $detail === '', 'detail' => $detail];
    }

    // -----------------------------------------------------------------------
    // Fixture inspection. Deliberately raw: the harness has to be able to see
    // what the layer under test is hiding.
    // -----------------------------------------------------------------------

    private function seededId(string $table, string $tenantId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM `{$table}` WHERE tenant_id = :tenant ORDER BY id LIMIT 1"
        );
        $statement->execute([':tenant' => $tenantId]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException("No fixture row in `{$table}` for tenant {$tenantId}.");
        }

        return (string) $id;
    }

    private function readCell(string $table, string $id, string $column): ?string
    {
        $statement = $this->pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE id = :id");
        $statement->execute([':id' => $id]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function rowExists(string $table, string $id): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE id = :id");
        $statement->execute([':id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * A text column the assertions may scribble on: not the key, not the
     * tenant, and in no unique index, so writing the same marker into both
     * tenants' rows cannot collide.
     */
    private function updatableColumn(string $table): string
    {
        $statement = $this->pdo->prepare(
            'SELECT c.COLUMN_NAME FROM information_schema.COLUMNS c
             WHERE c.TABLE_SCHEMA = :schema AND c.TABLE_NAME = :table
               AND c.DATA_TYPE IN ("varchar", "text", "int", "bigint", "decimal", "date", "datetime")
               AND c.COLUMN_NAME NOT IN ("id", "tenant_id")
               AND c.COLUMN_NAME NOT IN (
                   SELECT s.COLUMN_NAME FROM information_schema.STATISTICS s
                   WHERE s.TABLE_SCHEMA = :key_schema AND s.TABLE_NAME = :key_table AND s.NON_UNIQUE = 0
               )
             ORDER BY c.IS_NULLABLE DESC, c.ORDINAL_POSITION LIMIT 1'
        );
        $statement->execute([
            ':schema' => $this->schema,
            ':table' => $table,
            ':key_schema' => $this->schema,
            ':key_table' => $table,
        ]);
        $column = $statement->fetchColumn();

        if ($column === false) {
            // Still loud. Widening the accepted types from text to any writable
            // scalar covers the join and settings tables the port added; a table
            // with NOTHING writable outside its key remains unprovable on the
            // write path, and that is reported rather than skipped — a table
            // quietly dropped from an isolation suite is a table nobody is
            // checking, which is worse than one that fails.
            throw new \RuntimeException(
                "`{$table}` has no non-unique writable column for the isolation suite to write to. "
                . 'Give the generator one, or the table cannot be proved isolated on the write path.'
            );
        }

        return (string) $column;
    }
}
