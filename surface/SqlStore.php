<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE SAME SHAPE AS `Store`, BACKED BY MySQL.
 *
 * `Store` was honest scaffolding — JSON files, with its ceiling written on it —
 * and the upgrade path it promised was that every caller goes through `all()`,
 * `find()` and `put()`, so the body could be swapped without touching anything
 * above. This is that swap, and it is a separate class rather than a rewrite of
 * `Store` so both can exist while the screens move across one at a time.
 *
 * TENANCY IS STILL STRUCTURAL, and now it is structural in SQL. Every read
 * carries `WHERE tenant_id = ?` and every write carries the value; neither is
 * something a screen can forget, because no screen builds the query. The
 * platform-scoped list is the same one `Store` uses, so the two cannot disagree
 * about which tables belong to a tenant.
 *
 * A ROW FROM ANOTHER TENANT IS NOT FOUND, not forbidden — "forbidden" confirms
 * the id exists, which is worth having if you are guessing ids.
 *
 * IDENTIFIERS ARE VALIDATED, NEVER INTERPOLATED BLIND. A table or column name
 * cannot be a bound parameter in SQL, so the only safe thing to do with one is
 * to check it against what the schema actually contains and refuse anything
 * else. Escaping it would be a second-best that has to be right every time.
 *
 * ponytail: no connection pool, no query builder, no lazy hydration. This is a
 * repository, not an ORM; the day a screen needs a join it gets a method here
 * with the query written out, which is easier to read than the builder call that
 * would produce it.
 */
final class SqlStore implements RowStore
{
    /** Same list as `Store::PLATFORM_SCOPED`, so the two cannot drift apart. */
    public const PLATFORM_SCOPED = Store::PLATFORM_SCOPED;

    /** @var array<string, list<string>> table => its real columns, read once */
    private array $columns = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ?string $tenantId = null,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public function forTenant(string $tenantId): RowStore
    {
        return new self($this->pdo, $tenantId);
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * The columns a table really has. Read from the database rather than from a
     * map in this file, because a map is a second description of the schema that
     * goes stale the first time a migration lands without it.
     *
     * @return list<string>
     * @throws \InvalidArgumentException when the table is not in the schema
     */
    public function columnsOf(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException(
                "'{$table}' is not a possible table name. It is refused rather than escaped: an "
                . 'identifier cannot be a bound parameter, so the only safe thing to do with one is '
                . 'to check it against the schema.'
            );
        }

        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$table]);
        $columns = array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'],
            $statement->fetchAll());

        if ($columns === []) {
            throw new \InvalidArgumentException(
                "There is no table '{$table}' in this database. A missing table is a refusal here "
                . 'rather than an empty result set, because a screen that silently shows nothing is '
                . 'indistinguishable from one whose data has gone.'
            );
        }

        return $this->columns[$table] = $columns;
    }

    /**
     * Column => its SQL type, so a form can offer a date picker for a date and a
     * checkbox for a flag without anybody declaring either.
     *
     * @return array<string, string>
     */
    public function columnTypes(string $table): array
    {
        $this->columnsOf($table);

        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        $types = [];

        foreach ($statement->fetchAll() as $row) {
            $types[(string) $row['COLUMN_NAME']] = (string) $row['DATA_TYPE'];
        }

        // A JSON COLUMN ON MARIADB IS A LONGTEXT WITH A CHECK, and reporting it as
        // longtext is how four modules ended up rendering a plain textarea over a
        // column that refuses anything but JSON. The person typed a word, and what
        // came back was `SQLSTATE[23000]: Integrity constraint violation: 4025
        // CONSTRAINT `settings.value` failed` — a sentence naming a constraint they
        // have never seen, about a field the form said was free text.
        //
        // So the constraint is read and the type is reported as what it actually
        // is. MySQL 8 answers `json` here directly and this query returns nothing,
        // which is the same answer arrived at twice — the two servers agree again
        // by the time anything downstream looks.
        try {
            $checks = $this->pdo->prepare(
                'SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND CHECK_CLAUSE LIKE ?'
            );
            $checks->execute([$table, '%json_valid%']);

            foreach ($checks->fetchAll() as $row) {
                $column = (string) $row['CONSTRAINT_NAME'];

                if (isset($types[$column])) {
                    $types[$column] = 'json';
                }
            }
        } catch (\PDOException $e) {
            // A server without CHECK_CONSTRAINTS (MySQL before 8.0.16) simply has
            // no such columns to find. Swallowed deliberately and narrowly: the
            // consequence of not knowing is the behaviour that existed before this
            // block, not a wrong answer.
            unset($e);
        }

        return $types;
    }

    /**
     * Columns this table allows NULL in. Read from the schema for the same
     * reason `columnsOf()` is: a NOT NULL column with a database default (an
     * `is_active` flag, say) must never be sent an explicit NULL just because
     * a form left it blank — the default only applies when the column is left
     * out of the statement entirely, so a caller building a row from posted
     * input needs to know which is which.
     *
     * @return list<string>
     */
    public function nullableColumns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = "YES"'
        );
        $statement->execute([$table]);

        return array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'],
            $statement->fetchAll());
    }

    /**
     * Columns that must be given a value on insert.
     *
     * @return list<string>
     */
    private function requiredWithoutDefault(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND IS_NULLABLE = "NO" AND COLUMN_DEFAULT IS NULL
               AND EXTRA NOT LIKE "%auto_increment%"'
        );
        $statement->execute([$table]);

        return array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'],
            $statement->fetchAll());
    }

    /**
     * UUIDv7: 48 bits of millisecond timestamp, then randomness. Time-ordered,
     * so it does not fragment the index the way a v4 does, and still opaque.
     * Returned as 16 RAW BYTES because that is what the column holds — a
     * 36-character string in a BINARY(16) is a silent truncation.
     */
    private static function uuidV7(): string
    {
        $bytes = random_bytes(16);
        $time = substr(pack('J', (int) (microtime(true) * 1000)), 2, 6);
        $bytes = $time . substr($bytes, 6);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);   // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);   // variant

        return $bytes;
    }

    private function isScoped(string $table): bool
    {
        return $this->tenantId !== null
            && !in_array($table, self::PLATFORM_SCOPED, true)
            && in_array('tenant_id', $this->columnsOf($table), true);
    }

    /**
     * EVERY ROW OF A TABLE, WITH NO LIMIT. Measured here against the real
     * `attendance_logs` shape: 40,000 rows is 0.51s and 68 MB resident before PHP
     * does anything with them, where the first page of 50 via SQL LIMIT is 0.001s
     * and a COUNT(*) is 0.005s. A year of punches for 200 staff is ~500,000 rows,
     * about 850 MB — not a slow screen, a fatal error at the memory limit.
     *
     * ponytail: kept, because some callers genuinely need every row and most
     * tables are small. What used to be listed here as exposure is now closed:
     * `Modules::stats()` and `Reports::run()` go through `aggregate()`, a search
     * in `Modules::rows()` goes through `page()`/`count()` with a WHERE, and
     * `Modules::lookup()` counts before it reads. What is left, and is honest:
     *   - `Modules::lookup()` BELOW ITS CAP — a small table read whole to fill a
     *     dropdown completely, which is the point of it.
     *   - `Reports::labelsFor()` — the lookup table a report's group keys are
     *     named from, same bargain.
     *   - `Register`, `Attendance`, `Settlement` — a payroll run reads the month
     *     it is closing and has to.
     * Everything a person PAGES through goes via `page()`.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $table): array
    {
        $this->columnsOf($table);

        if (!$this->isScoped($table)) {
            return $this->pdo->query("SELECT * FROM `{$table}`")->fetchAll();
        }

        $statement = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE tenant_id = ?");
        $statement->execute([$this->tenantId]);

        return $statement->fetchAll();
    }

    /**
     * One page, in real SQL: `ORDER BY ... LIMIT ... OFFSET ...`.
     *
     * The order column is CHECKED AGAINST THE TABLE'S REAL COLUMNS and refused if
     * it is not one — an identifier cannot be a bound parameter, and the schema is
     * already read here for exactly this reason. `LIMIT` and `OFFSET` are ints
     * cast to ints; they are interpolated because MySQL will not take them as
     * bound parameters under an emulated prepare.
     *
     * @return list<array<string, mixed>>
     */
    public function page(
        string $table,
        string $orderBy,
        int $limit,
        int $offset = 0,
        string $direction = 'asc',
        array $searchColumns = [],
        string $needle = '',
    ): array {
        [$column, $wanted] = Store::orderColumn($orderBy, $direction, $this->columnsOf($table));

        // Ties broken by id, like the file store: two rows sharing an order value
        // must not swap between page 1 and page 2, or a row is shown twice and
        // another never at all.
        $order = "`{$column}` {$wanted}";

        if ($column !== 'id' && in_array('id', $this->columnsOf($table), true)) {
            $order .= ", `id` {$wanted}";
        }

        $window = ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        [$where, $bind] = $this->whereFor($table, $searchColumns, $needle);

        $statement = $this->pdo->prepare(
            "SELECT * FROM `{$table}`{$where} ORDER BY {$order}{$window}"
        );
        $statement->execute($bind);

        return $statement->fetchAll();
    }

    /**
     * THE WHERE CLAUSE EVERY READ SHARES: tenancy first, then the search.
     *
     * Tenancy is built HERE and only here, so a search cannot be a second path
     * that forgets it — the clause a caller gets is the tenancy clause with
     * something added to it, never one assembled instead of it.
     *
     * The search columns are checked against the real schema by the same
     * validator the file store uses (an identifier cannot be bound), and the
     * NEEDLE is bound — with `%` and `_` escaped, so a person typing "50%" is
     * searching for "50%" rather than for everything. Matching is
     * case-insensitive because the columns are `utf8mb4_..._ci`, which is what
     * `mb_strtolower()` does on the file store's side.
     *
     * @param list<string> $searchColumns
     * @return array{0: string, 1: list<mixed>} the clause (with leading space, or ''), and its binds
     */
    private function whereFor(string $table, array $searchColumns, string $needle): array
    {
        $conditions = [];
        $bind = [];

        if ($this->isScoped($table)) {
            $conditions[] = 'tenant_id = ?';
            $bind[] = $this->tenantId;
        }

        if ($searchColumns !== [] && $needle !== '') {
            $columns = Store::searchColumns($searchColumns, $this->columnsOf($table));
            $escaped = addcslashes($needle, '%_\\');
            $likes = [];

            foreach ($columns as $column) {
                $likes[] = "`{$column}` LIKE CONCAT('%', ?, '%')";
                $bind[] = $escaped;
            }

            $conditions[] = '(' . implode(' OR ', $likes) . ')';
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $bind];
    }

    public function find(string $table, string $id): ?array
    {
        $this->columnsOf($table);

        if ($this->isScoped($table)) {
            $statement = $this->pdo->prepare(
                "SELECT * FROM `{$table}` WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $statement->execute([$id, $this->tenantId]);
        } else {
            $statement = $this->pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
            $statement->execute([$id]);
        }

        return $statement->fetch() ?: null;
    }

    /**
     * Insert or update by id, and return what was stored.
     *
     * @throws \RuntimeException when the row belongs to another tenant
     */
    public function put(string $table, array $row): array
    {
        $columns = $this->columnsOf($table);
        $scoped = $this->isScoped($table);

        if ($scoped) {
            $row['tenant_id'] = $this->tenantId;
        }

        // Columns the table does not have are DROPPED, and named. Silently
        // ignoring them is how a screen appears to save a field for months
        // before anybody notices the column was never added.
        $unknown = array_diff(array_keys($row), $columns);

        if ($unknown !== []) {
            throw new \RuntimeException(
                "`{$table}` has no column(s) " . implode(', ', $unknown) . '. The write is refused '
                . 'rather than dropping them, because a field that appears to save and does not is '
                . 'discovered months later by whoever needed it.'
            );
        }

        // A row that exists but belongs to ANOTHER tenant is refused here, by
        // name. Without this the insert below collides on the primary key and
        // the caller gets a duplicate-key error, which is the right outcome
        // reached by accident and explained badly.
        if ($scoped && isset($row['id'])) {
            $owner = $this->pdo->prepare("SELECT tenant_id FROM `{$table}` WHERE id = ? LIMIT 1");
            $owner->execute([$row['id']]);
            $existing = $owner->fetchColumn();

            if ($existing !== false && (string) $existing !== (string) $this->tenantId) {
                throw new \RuntimeException(
                    "Row {$row['id']} in `{$table}` belongs to another tenant. The write is refused "
                    . 'rather than re-stamped: moving a row between tenants silently is the one '
                    . 'mistake that cannot be undone by fixing a filter afterwards.'
                );
            }
        }

        if (isset($row['id']) && $this->find($table, (string) $row['id']) !== null) {
            $set = [];

            foreach (array_keys($row) as $column) {
                if ($column !== 'id') {
                    $set[] = "`{$column}` = ?";
                }
            }

            if (in_array('updated_at', $columns, true) && !isset($row['updated_at'])) {
                $set[] = '`updated_at` = ?';
                $row['updated_at'] = date('Y-m-d H:i:s');
            }

            $values = [];

            foreach ($row as $column => $value) {
                if ($column !== 'id') {
                    $values[] = $value;
                }
            }

            $values[] = $row['id'];
            $where = $scoped ? ' AND tenant_id = ?' : '';

            if ($scoped) {
                $values[] = $this->tenantId;
            }

            $this->pdo->prepare(
                "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE id = ?{$where}"
            )->execute(array_map(self::bindable(...), $values));

            return $this->find($table, (string) $row['id']) ?? $row;
        }

        foreach (['created_at', 'updated_at'] as $stamp) {
            if (in_array($stamp, $columns, true) && !isset($row[$stamp])) {
                $row[$stamp] = date('Y-m-d H:i:s');
            }
        }

        // A NOT NULL column with no default and no value from the caller is
        // filled here where the store can, and REFUSED where it cannot — rather
        // than left to MySQL, whose "Field 'uuid' doesn't have a default value"
        // names the column and nothing about how to satisfy it.
        foreach ($this->requiredWithoutDefault($table) as $column) {
            if (isset($row[$column])) {
                continue;
            }

            $row[$column] = match (true) {
                $column === 'uuid' => self::uuidV7(),
                default => throw new \RuntimeException(
                    "`{$table}`.`{$column}` is NOT NULL with no default, and no value was given. "
                    . 'The write is refused here rather than in the driver, because "does not have '
                    . 'a default value" names the column and nothing about what belongs in it.'
                ),
            };
        }

        $names = array_keys($row);
        $values = array_map(self::bindable(...), array_values($row));

        try {
            $this->pdo->prepare(
                "INSERT INTO `{$table}` (`" . implode('`, `', $names) . '`) VALUES ('
                . implode(', ', array_fill(0, count($names), '?')) . ')'
            )->execute($values);
        } catch (\PDOException $e) {
            // A DRIVER ERROR THAT NAMES NO ROW IS A BUG REPORT WITH THE EVIDENCE
            // MISSING. MySQL's "Data truncated for column 'id'" on a prepared
            // insert can name a column that is not even in the statement — it
            // reports by the TABLE's ordinal, not the statement's — which sends
            // somebody looking at the primary key while the real offender is
            // three columns along. So the shape of what was written is attached
            // here: column, type and size. Not the values: an audit payload
            // holds whatever the screen was writing about, and an error page is
            // not a place to reprint it.
            $shape = [];

            foreach ($names as $i => $name) {
                $value = $values[$i];
                $shape[] = $name . ' ' . get_debug_type($value)
                    . (is_string($value) ? '(' . strlen($value) . ')' : '');
            }

            throw new \RuntimeException(
                "Writing a row to `{$table}` was refused by the database: " . $e->getMessage()
                . ' The row held: ' . implode(', ', $shape) . '.', 0, $e
            );
        }

        $row['id'] ??= $this->pdo->lastInsertId();

        return $this->find($table, (string) $row['id']) ?? $row;
    }

    /**
     * PHP values as the column can hold them.
     *
     * PDO binds `false` as the empty string, which a TINYINT column rejects with
     * a message about datetime format — a wrong error about the wrong thing, so
     * the conversion happens here rather than being discovered per column. An
     * array becomes JSON because the alternative is "Array" in a varchar.
     */
    private static function bindable(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 1 : 0,
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            // An ISO-8601 instant (what date('c') gives) carries a timezone
            // offset that MySQL's DATETIME rejects. Converted here rather than
            // at each of the forty call sites that write a timestamp — and
            // converted to UTC, so two servers in different zones do not store
            // the same instant as two different values.
            is_string($value) && preg_match(
                '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/',
                $value,
            ) === 1 => (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    /** COUNT(*) in SQL: 0.005s where counting `all()` in PHP was 0.51s and 68 MB. */
    public function count(string $table, array $searchColumns = [], string $needle = ''): int
    {
        $this->columnsOf($table);
        [$where, $bind] = $this->whereFor($table, $searchColumns, $needle);
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}`{$where}");
        $statement->execute($bind);

        return (int) $statement->fetchColumn();
    }

    /**
     * COUNT AND SUM IN SQL, GROUPED, TENANT-SCOPED.
     *
     *     SELECT `grp` AS k, COUNT(*) AS c, COALESCE(SUM(ROUND(`col`, 2)), 0) AS s
     *     FROM `t` WHERE tenant_id = ? AND `w` IN (?, ?) GROUP BY `grp`
     *
     * ROUND(col, 2) PER ROW, THEN SUM — not SUM then round. The columns are
     * DECIMAL(18,4), and `Amount::of()` rounds each stored figure to paise before
     * adding it, which is what the PHP loop this replaces did. Rounding once at
     * the end instead would differ from that by up to a paisa per row: two
     * answers to one question, which is how a figure stops being trusted. Both
     * round half away from zero, so the two agree exactly.
     *
     * THE SUM NEVER BECOMES A FLOAT. MySQL returns SUM over a DECIMAL as a
     * decimal STRING, and it is handed to `Amount::of()` — integer paise — and
     * back out as a decimal string. There is no cast on that path.
     *
     * THE COLUMN MUST BE A NUMERIC ONE. `SUM()` over a VARCHAR coerces "n/a" to
     * zero and says nothing, which is the exact defect the PHP path refuses by
     * name; so a non-numeric column is refused here instead of silently totalled.
     *
     * @return list<array{key: string, count: int, sum: string}>
     */
    public function aggregate(
        string $table,
        string $column = '',
        array $where = [],
        string $groupBy = '',
    ): array {
        $columns = $this->columnsOf($table);
        $select = ['COUNT(*) AS c'];

        // Identifiers, all of them, checked against the real schema by the same
        // validator and the same sentence the file store uses.
        Store::searchColumns(array_values(array_filter(array_merge(
            array_map('strval', array_keys($where)),
            [$column, $groupBy],
        ), static fn (string $c): bool => $c !== '')), $columns);

        if ($column !== '') {
            $type = strtolower($this->columnTypes($table)[$column] ?? '');

            if (!str_contains($type, 'decimal') && !str_contains($type, 'int')) {
                throw new \InvalidArgumentException(
                    "`{$table}`.`{$column}` is a {$type}, not an exact numeric column, so SUM() over "
                    . 'it would coerce whatever it holds to a number and report a total that is '
                    . 'wrong by exactly the rows it could not read. Refused rather than summed.'
                );
            }

            $select[] = "COALESCE(SUM(ROUND(`{$column}`, 2)), 0) AS s";
        }

        // The tenancy clause comes from the same place every other read gets it,
        // so this can never be the query that forgot it.
        [$clause, $bind] = $this->whereFor($table, [], '');
        $conditions = $clause === '' ? [] : [substr($clause, strlen(' WHERE '))];

        foreach ($where as $filter => $expected) {
            $values = array_map('strval', (array) $expected);

            if ($values === []) {
                // `IN ()` is not valid SQL, and "no acceptable values" means no
                // row is acceptable — said out loud rather than dropped, which
                // would silently widen the filter to every row.
                $conditions[] = '1 = 0';

                continue;
            }

            $conditions[] = "`{$filter}` IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
            $bind = array_merge($bind, $values);
        }

        $group = '';

        if ($groupBy !== '') {
            array_unshift($select, "`{$groupBy}` AS k");
            $group = " GROUP BY `{$groupBy}`";
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . implode(', ', $select) . " FROM `{$table}`"
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions)) . $group
        );
        $statement->execute($bind);

        $out = [];

        foreach ($statement->fetchAll() as $row) {
            $out[] = [
                'key' => $groupBy === '' ? '' : (string) ($row['k'] ?? ''),
                'count' => (int) $row['c'],
                // Through `Amount`, in paise. Never a cast: this is the money path.
                'sum' => Amount::of($row['s'] ?? 0)->toDecimalString(),
            ];
        }

        return $out;
    }

    /**
     * Deliberately absent: `Store::write()` replaces a whole collection, which a
     * tenant-scoped store must never do. Callers append with `put()`.
     */
    public function write(string $table, array $rows): never
    {
        throw new \BadMethodCallException(
            "write() does not exist on the SQL store ('{$table}'). Replacing a whole table from a "
            . "tenant-scoped caller would delete every other tenant's rows; use put() per row."
        );
    }
}
