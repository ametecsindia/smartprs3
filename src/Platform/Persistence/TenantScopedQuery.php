<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Persistence;

use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * Spine Law 6 / R-XXII-080..084 — the query-scoping layer.
 *
 * A small, boring builder over PDO whose single property is this: it cannot
 * emit a statement against a tenant-scoped table without `tenant_id = ?`. Not
 * "adds one if you forget" — the predicate is not something a caller can put in
 * or take out. It is assembled in whereClause() from TenantContext at emit
 * time, and there is no method on this class that removes it, no flag that
 * disables it, and no way to reach the raw statement.
 *
 * SmartPRS 2's tenancy is 1,375 hand-written call sites, 405 of which have no
 * filter at all, backed by a global scope filtering `users.company_id` — a
 * column that does not exist. The point of this file is that there is nothing
 * for a developer to forget.
 *
 * Consequences of that design, all deliberate:
 *   · no tenant established  → TenantNotEstablished, never "all tenants"
 *   · unknown table          → treated as tenant-scoped (TableRegistry)
 *   · insert                 → tenant_id stamped from context, never by hand;
 *                              a foreign tenant_id in the payload is refused
 *   · update                 → tenant_id may not appear in the SET list;
 *                              rows do not move between tenants
 *   · platform tables        → readable by anyone, writable only inside an
 *                              audited CrossTenantEscape (R-XXII-120: platform
 *                              reference data is read-only to every tenant)
 *
 * WHAT THIS ADDS OVER LARAVEL'S GLOBAL SCOPES, once the framework is installed:
 * an Eloquent global scope is opt-out by design — `withoutGlobalScope()`,
 * `withoutGlobalScopes()`, `Model::withoutGlobalScope(TenantScope::class)`,
 * `DB::table()`, `whereRaw`, any query builder call that bypasses the model, and
 * every `insert()`/`upsert()`/mass-update path apply no scope at all. It also
 * cannot stamp a write, so `tenant_id` still gets set by hand at the call site.
 * A global scope would cover the common read path, and on the target machine
 * one SHOULD be registered as a second layer. What it cannot give you is the
 * *absence of an opt-out*, and R-XXII-082 — "deleting the tenant predicate from
 * a query must break a test, not widen a result set" — is only true when the
 * predicate is not written at the call site in the first place.
 *
 * Everything is bound. No value is ever interpolated into SQL. Identifiers are
 * not bindable in SQL, so they are validated against a strict pattern instead.
 */
final class TenantScopedQuery
{
    /** Table and column names. Anything else is not an identifier we will emit. */
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    private const OPERATORS = ['=', '<>', '<', '<=', '>', '>=', 'LIKE'];

    /** @var list<array{sql: string, bindings: array<string, scalar|null>}> */
    private array $wheres = [];

    /** @var list<string> */
    private array $orderBy = [];

    private ?int $limit = null;

    private bool $forUpdate = false;
    private bool $skipLocked = false;

    private int $placeholder = 0;

    private function __construct(
        private readonly \PDO $pdo,
        private readonly string $table,
        private readonly ?TenantContext $tenant,
        private readonly bool $tenantScoped,
        private readonly bool $crossTenant,
    ) {
        if (preg_match(self::IDENTIFIER, $table) !== 1) {
            throw new \InvalidArgumentException("'{$table}' is not a table name this layer will emit.");
        }

        // A silenced PDO error turns a failed write into "0 rows affected",
        // which reads exactly like successful isolation. Refuse the ambiguity.
        if ($pdo->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('The PDO handle must be in PDO::ERRMODE_EXCEPTION.');
        }
    }

    /** The only constructor application code uses. */
    public static function on(\PDO $pdo, TenantContext $tenant, string $table): self
    {
        return new self($pdo, $table, $tenant, TableRegistry::isTenantScoped($table), false);
    }

    /**
     * R-XXII-084 — the audited escape. Refused unless a CrossTenantEscape grant
     * is open, which requires a platform permission, a reason, a declared call
     * site, and writes its audit row before the block runs.
     */
    public static function acrossTenants(\PDO $pdo, string $table): self
    {
        if (!CrossTenantEscape::isOpen()) {
            throw new CrossTenantRefused(
                "A cross-tenant query on '{$table}' requires an open CrossTenantEscape grant."
            );
        }

        return new self($pdo, $table, null, TableRegistry::isTenantScoped($table), true);
    }

    /**
     * No float in the union, deliberately (Law 7): binding an amount as a float
     * is how SmartPRS 2's loan-instalment comparison lost a paisa. Money binds
     * as integer paise, and any other real quantity binds as a decimal string.
     */
    public function where(string $column, string $operator, string|int|bool|null $value): self
    {
        $this->assertColumn($column);

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Operator '{$operator}' is not in the permitted set.");
        }

        if ($value === null) {
            throw new \InvalidArgumentException('Use whereNull(); NULL never satisfies a comparison operator.');
        }

        $name = ':p' . $this->placeholder++;
        $this->wheres[] = ['sql' => "`{$column}` {$operator} {$name}", 'bindings' => [$name => $value]];

        return $this;
    }

    /** @param list<string|int|float> $values */
    public function whereIn(string $column, array $values): self
    {
        $this->assertColumn($column);

        if ($values === []) {
            // Fail closed: an empty IN list matches nothing, it does not vanish.
            $this->wheres[] = ['sql' => '1 = 0', 'bindings' => []];

            return $this;
        }

        $names = [];
        $bindings = [];

        foreach ($values as $value) {
            $name = ':p' . $this->placeholder++;
            $names[] = $name;
            $bindings[$name] = $value;
        }

        $this->wheres[] = ['sql' => "`{$column}` IN (" . implode(', ', $names) . ')', 'bindings' => $bindings];

        return $this;
    }

    public function whereNull(string $column, bool $null = true): self
    {
        $this->assertColumn($column);
        $this->wheres[] = ['sql' => "`{$column}` IS " . ($null ? 'NULL' : 'NOT NULL'), 'bindings' => []];

        return $this;
    }

    /**
     * R-XXII-080 step 2 — the data-scope predicate, built server-side by
     * Access\DataScope from the actor's role assignments, never from input.
     * The SQL fragment is the one piece of text this class does not author; its
     * values are still bound. Named so a reviewer can grep for every use.
     *
     * @param  array<string, scalar|null>  $bindings  keyed by placeholder name
     */
    public function whereScopePredicate(string $sql, array $bindings = []): self
    {
        $sql = trim($sql);

        if ($sql === '') {
            // IV.4: "Missing scope → empty result, never unfiltered."
            $this->wheres[] = ['sql' => '1 = 0', 'bindings' => []];

            return $this;
        }

        foreach (array_keys($bindings) as $name) {
            if (preg_match('/^:[a-z_][a-z0-9_]*$/', (string) $name) !== 1) {
                throw new \InvalidArgumentException("'{$name}' is not a usable placeholder name.");
            }

            if (str_starts_with((string) $name, ':__') || preg_match('/^:p\d+$/', (string) $name) === 1) {
                throw new \InvalidArgumentException("Placeholder '{$name}' is reserved by the query builder.");
            }
        }

        $this->wheres[] = ['sql' => '(' . $sql . ')', 'bindings' => $bindings];

        return $this;
    }

    /**
     * Law 3's as-at predicate: the rows in force on one calendar day, in the
     * persisted shape of V.5.3 — `effective_from` inclusive, `effective_to`
     * inclusive, NULL meaning "in force until superseded" and never "not yet
     * effective". Both boundary days are inside the range.
     *
     * It lives here rather than in a caller for the same reason the tenant
     * predicate does: EffectiveDatedRepository must be able to add it without
     * anybody being able to write, drop or misspell the OR.
     */
    public function whereInForceOn(string $fromColumn, string $toColumn, string $date): self
    {
        $this->assertColumn($fromColumn);
        $this->assertColumn($toColumn);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException("'{$date}' is not a calendar day in Y-m-d.");
        }

        // Two placeholders for one value: PDO with emulation off binds a named
        // parameter once only, and this layer must not depend on which.
        $from = ':p' . $this->placeholder++;
        $to = ':p' . $this->placeholder++;

        $this->wheres[] = [
            'sql' => "`{$fromColumn}` <= {$from} AND (`{$toColumn}` IS NULL OR `{$toColumn}` >= {$to})",
            'bindings' => [$from => $date, $to => $date],
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertColumn($column);
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Sort direction '{$direction}' is not ASC or DESC.");
        }

        $this->orderBy[] = "`{$column}` {$direction}";

        return $this;
    }

    /**
     * Take a row lock for the rest of the transaction.
     *
     * R-IX-049: node completion is evaluated under a row lock on the workflow
     * instance, and that is the only place node outcomes are computed. The same
     * lock allocates the transition ledger's next seq. SmartPRS 2's leave
     * balance check has no transaction, no lock and no re-check at approval, so
     * two simultaneous applications both pass — a quorum tally computed in
     * application memory is the same race with a different name.
     *
     * Refused outside a transaction, because a FOR UPDATE that commits
     * immediately holds nothing and reads as protection that is not there.
     */
    /**
     * @param bool $skipLocked SKIP LOCKED — pass over rows another worker
     *        already holds instead of waiting for them. For a work QUEUE (the
     *        §IX.7.6 timer sweep) that is the point: two workers must take
     *        disjoint batches and neither should block. For a CONSISTENCY lock
     *        it would be wrong, because the row you skipped is the row you
     *        needed to read, so it is off by default.
     */
    public function forUpdate(bool $skipLocked = false): self
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException(
                'FOR UPDATE outside a transaction holds no lock. Open a transaction, or do not claim one.'
            );
        }

        $this->forUpdate = true;
        $this->skipLocked = $skipLocked;

        return $this;
    }

    public function limit(int $rows): self
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException('A limit below one is not a query, it is a mistake.');
        }

        $this->limit = $rows;

        return $this;
    }

    // -----------------------------------------------------------------------
    // Emission. Every one of these goes through whereClause().
    // -----------------------------------------------------------------------

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    public function select(array $columns = ['*']): array
    {
        [$where, $bindings] = $this->whereClause();

        $sql = 'SELECT ' . $this->columnList($columns) . " FROM `{$this->table}`" . $where . $this->tail();

        return $this->run($sql, $bindings)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    public function first(array $columns = ['*']): ?array
    {
        return $this->limit(1)->select($columns)[0] ?? null;
    }

    /** R-XXII-124: counts, tiles and report totals go through the same scoping. */
    public function count(): int
    {
        [$where, $bindings] = $this->whereClause();

        return (int) $this->run(
            "SELECT COUNT(*) FROM `{$this->table}`" . $where,
            $bindings,
        )->fetchColumn();
    }

    /**
     * R-XXII-083 — the tenant is stamped here, from the context, never by a
     * hand-written array key. A payload naming a different tenant is a bug in
     * the caller and is refused rather than quietly corrected.
     *
     * @param  array<string, scalar|null>  $row
     * @return string  the inserted id
     */
    public function insert(array $row): string
    {
        $this->assertWritable();

        if ($row === []) {
            throw new \InvalidArgumentException('Nothing to insert.');
        }

        if ($this->tenantScoped && !$this->crossTenant) {
            $tenantId = $this->tenant?->tenantId();

            if (array_key_exists(TableRegistry::TENANT_COLUMN, $row)
                && (string) $row[TableRegistry::TENANT_COLUMN] !== $tenantId) {
                throw new TenancyViolation(
                    "Insert into `{$this->table}` supplies tenant_id "
                    . var_export($row[TableRegistry::TENANT_COLUMN], true)
                    . " while the established tenant is {$tenantId}. The persistence layer stamps the tenant; "
                    . 'a call site that names one is either wrong or attacking.'
                );
            }

            $row[TableRegistry::TENANT_COLUMN] = $tenantId;
        }

        $columns = [];
        $names = [];
        $bindings = [];

        foreach ($row as $column => $value) {
            $this->assertColumn((string) $column);
            $columns[] = "`{$column}`";
            $name = ':v' . count($names);
            $names[] = $name;
            $bindings[$name] = $value;
        }

        $this->run(
            "INSERT INTO `{$this->table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $names) . ')',
            $bindings,
        );

        return $this->pdo->lastInsertId();
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return int  rows affected — zero when the row belongs to another tenant
     */
    public function update(array $values): int
    {
        $this->assertWritable();

        if ($values === []) {
            throw new \InvalidArgumentException('Nothing to update.');
        }

        if (array_key_exists(TableRegistry::TENANT_COLUMN, $values)) {
            throw new TenancyViolation(
                "An update may not set `{$this->table}`.tenant_id. A row does not move between tenants."
            );
        }

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $this->assertColumn((string) $column);
            $name = ':v' . count($assignments);
            $assignments[] = "`{$column}` = {$name}";
            $bindings[$name] = $value;
        }

        [$where, $whereBindings] = $this->whereClause();

        $statement = $this->run(
            "UPDATE `{$this->table}` SET " . implode(', ', $assignments) . $where . $this->tail(),
            $bindings + $whereBindings,
        );

        return $statement->rowCount();
    }

    /** @return int rows affected — zero when the row belongs to another tenant */
    public function delete(): int
    {
        $this->assertWritable();

        [$where, $bindings] = $this->whereClause();

        return $this->run("DELETE FROM `{$this->table}`" . $where . $this->tail(), $bindings)->rowCount();
    }

    // -----------------------------------------------------------------------

    /**
     * The whole layer, in one method. The tenant predicate is prepended here
     * and is not reachable from anywhere else — it is never stored in $wheres,
     * so no caller can inspect, replace or drop it.
     *
     * @return array{0: string, 1: array<string, scalar|null>}
     */
    private function whereClause(): array
    {
        $clauses = [];
        $bindings = [];

        if ($this->tenantScoped && !$this->crossTenant) {
            $clauses[] = '`' . TableRegistry::TENANT_COLUMN . '` = :__tenant';
            // Throws TenantNotEstablished when no tenant has been established:
            // the query does not run at all, rather than running unfiltered.
            $bindings[':__tenant'] = $this->tenant?->tenantId();
        }

        foreach ($this->wheres as $where) {
            $clauses[] = $where['sql'];
            $bindings += $where['bindings'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function tail(): string
    {
        return ($this->orderBy === [] ? '' : ' ORDER BY ' . implode(', ', $this->orderBy))
            . ($this->limit === null ? '' : ' LIMIT ' . $this->limit)
            . ($this->forUpdate ? ' FOR UPDATE' . ($this->skipLocked ? ' SKIP LOCKED' : '') : '');
    }

    /** @param array<string, scalar|null> $bindings */
    private function run(string $sql, array $bindings): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement;
    }

    /** @param list<string> $columns */
    private function columnList(array $columns): string
    {
        if ($columns === ['*']) {
            return '*';
        }

        return implode(', ', array_map(
            function (string $column): string {
                $this->assertColumn($column);

                return "`{$column}`";
            },
            $columns,
        ));
    }

    private function assertColumn(string $column): void
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1) {
            throw new \InvalidArgumentException("'{$column}' is not a column name this layer will emit.");
        }
    }

    /**
     * R-XXII-120: platform reference data is read-only to every tenant, and is
     * written only by the rule-pack installer and the migration tooling, both
     * of which run inside an audited grant. Tenant tables are the mirror image:
     * written only in normal mode, where the tenant is stamped from context.
     */
    private function assertWritable(): void
    {
        if ($this->tenantScoped === $this->crossTenant) {
            throw new TenancyViolation($this->crossTenant
                ? "A cross-tenant grant does not authorise writing tenant-owned table `{$this->table}`."
                : "`{$this->table}` is platform-scoped and read-only to a tenant (R-XXII-120).");
        }
    }
}
