<?php

declare(strict_types=1);

/**
 * A TEST-ONLY stand-in for the sliver of Laravel's schema builder that the
 * foundation migrations use, so those migrations can be compiled to MySQL DDL
 * and executed against a real MariaDB here — without Composer and without the
 * framework.
 *
 * This is not a Laravel reimplementation and must never be loaded by the
 * application. It supports exactly the method vocabulary the migrations use; a
 * migration that reaches for something else fails loudly, which is the point.
 *
 * ponytail: on the target machine `php artisan migrate` runs the same files
 * against the real builder and this file is not involved.
 */

namespace Illuminate\Database\Migrations {
    abstract class Migration
    {
        abstract public function up(): void;
    }
}

namespace Illuminate\Database\Schema {
    final class Column
    {
        public bool $nullable = false;
        public ?string $default = null;
        public ?string $comment = null;
        public bool $autoIncrement = false;
        /** Set by ->change(): this column REPLACES an existing one (MODIFY, not ADD). */
        public bool $changes = false;
        /** Set by ->after(): MySQL positions this column after the named one. */
        public ?string $after = null;
        public ?string $storedAs = null;
        /** @var array{table: string, column: string, onDelete: ?string}|null */
        public ?array $foreign = null;

        public function __construct(
            public readonly string $name,
            public readonly string $sqlType,
            /** The blueprint this column belongs to, so `->index()` can register one. */
            public ?Blueprint $on = null,
        ) {
        }

        /**
         * A per-column index, which SmartPRS 2 uses widely. Registered on the
         * blueprint rather than ignored: dropping an index silently is how a
         * ported table looks identical and answers a query in thirty seconds.
         */
        public function index(?string $name = null): self
        {
            $this->on?->index([$this->name], $name ?? ($this->on->table . '_' . $this->name . '_idx'));

            return $this;
        }

        public function unique(?string $name = null): self
        {
            $this->on?->unique([$this->name], $name ?? ($this->on->table . '_' . $this->name . '_uq'));

            return $this;
        }

        /** A DEFAULT of the current time, which several ported tables rely on. */
        public function useCurrent(): self
        {
            // Unquoted on purpose: 'CURRENT_TIMESTAMP' as a string literal is a
            // default of those eighteen characters, not of the current time.
            $this->default = 'CURRENT_TIMESTAMP';

            return $this;
        }

        public function useCurrentOnUpdate(): self
        {
            return $this;
        }

        public function nullable(bool $value = true): self
        {
            $this->nullable = $value;

            return $this;
        }

        public function default(mixed $value): self
        {
            $this->default = is_bool($value)
                ? ($value ? '1' : '0')
                : (is_int($value) ? (string) $value : "'" . str_replace("'", "''", (string) $value) . "'");

            return $this;
        }

        /**
         * MODIFY, NOT ADD. Laravel's real builder needs doctrine/dbal for this;
         * the stub needs only to emit the right statement, because the point of
         * the stub is that `tools/db/migrate.php` and the schema test can compile
         * a migration without the framework.
         *
         * It exists because a migration widening `settlements.payable_days` from
         * INT to DECIMAL(6,2) was written and every test that compiles the
         * migrations died on "Call to undefined method Column::change()". Loud,
         * and therefore fine — but a stub that cannot express "change a column"
         * quietly limits what migrations anyone is willing to write.
         */
        public function change(): self
        {
            $this->changes = true;

            return $this;
        }

        public function comment(string $comment): self
        {
            $this->comment = $comment;

            return $this;
        }

        /**
         * Column position, MySQL/MariaDB's own ADD/MODIFY COLUMN ... AFTER
         * clause — cosmetic (it changes nothing SmartPRS 3 reads by name), but
         * a migration that reaches for it should not fail loudly for something
         * this ordinary. Applied by Schema::table() itself (it belongs on the
         * ALTER statement, not inside toSql(), which CREATE TABLE also calls,
         * where AFTER has no meaning).
         */
        public function after(string $column): self
        {
            $this->after = $column;

            return $this;
        }

        /**
         * A stored generated column. Laravel's MySQL grammar emits exactly this
         * shape and, like this, omits NULL/NOT NULL and DEFAULT for a generated
         * column — MariaDB rejects `AS (…) STORED NULL` outright.
         */
        public function storedAs(string $expression): self
        {
            $this->storedAs = $expression;

            return $this;
        }

        public function constrained(?string $table = null, string $column = 'id'): self
        {
            if ($table === null) {
                throw new \LogicException(
                    "constrained() must name its table explicitly in SmartPRS 3 ({$this->name}); "
                    . 'guessing table names from column names is how a foreign key ends up pointing nowhere.'
                );
            }

            $this->foreign = ['table' => $table, 'column' => $column, 'onDelete' => null];

            return $this;
        }

        public function cascadeOnDelete(): self
        {
            return $this->onDelete('CASCADE');
        }

        public function restrictOnDelete(): self
        {
            return $this->onDelete('RESTRICT');
        }

        public function nullOnDelete(): self
        {
            return $this->onDelete('SET NULL');
        }

        private function onDelete(string $action): self
        {
            if ($this->foreign === null) {
                throw new \LogicException("onDelete without constrained() on {$this->name}.");
            }

            $this->foreign['onDelete'] = $action;

            return $this;
        }

        public function toSql(): string
        {
            $sql = "`{$this->name}` {$this->sqlType}";

            if ($this->storedAs !== null) {
                $sql .= " AS ({$this->storedAs}) STORED";
            } else {
                $sql .= $this->nullable ? ' NULL' : ' NOT NULL';
            }

            if ($this->default !== null && $this->storedAs === null) {
                $sql .= " DEFAULT {$this->default}";
            }

            if ($this->autoIncrement) {
                $sql .= ' AUTO_INCREMENT';
            }

            if ($this->comment !== null) {
                $sql .= " COMMENT '" . str_replace("'", "''", $this->comment) . "'";
            }

            return $sql;
        }
    }

    final class Blueprint
    {
        /** @var list<Column> */
        public array $columns = [];
        /** @var list<array{kind: string, name: string, columns: list<string>}> */
        public array $indexes = [];

        public function __construct(public readonly string $table)
        {
        }

        public function id(string $name = 'id'): Column
        {
            $column = $this->add($name, 'BIGINT UNSIGNED');
            $column->autoIncrement = true;

            return $column;
        }

        public function foreignId(string $name): Column
        {
            return $this->add($name, 'BIGINT UNSIGNED');
        }

        public function unsignedBigInteger(string $name): Column
        {
            return $this->add($name, 'BIGINT UNSIGNED');
        }

        public function unsignedInteger(string $name): Column
        {
            return $this->add($name, 'INT UNSIGNED');
        }

        public function integer(string $name): Column
        {
            return $this->add($name, 'INT');
        }

        /**
         * Signed 64-bit. Added deliberately, as the vocabulary's own error
         * message asks: a formula sample case asserts its expected value in
         * MINOR UNITS and exactly (§9.10, no tolerance), and a money amount can
         * be negative — a recovery is. INT would overflow at ₹21,474,836.47.
         */
        public function bigInteger(string $name): Column
        {
            return $this->add($name, 'BIGINT');
        }

        public function string(string $name, int $length = 255): Column
        {
            return $this->add($name, "VARCHAR({$length})");
        }

        public function char(string $name, int $length = 255): Column
        {
            return $this->add($name, "CHAR({$length})");
        }

        public function text(string $name): Column
        {
            return $this->add($name, 'TEXT');
        }

        public function json(string $name): Column
        {
            return $this->add($name, 'JSON');
        }

        public function boolean(string $name): Column
        {
            return $this->add($name, 'TINYINT(1)');
        }

        public function date(string $name): Column
        {
            return $this->add($name, 'DATE');
        }

        public function dateTime(string $name): Column
        {
            return $this->add($name, 'DATETIME');
        }

        public function decimal(string $name, int $total = 18, int $places = 4): Column
        {
            return $this->add($name, "DECIMAL({$total},{$places})");
        }

        public function time(string $name): Column
        {
            return $this->add($name, 'TIME');
        }

        /**
         * DATETIME, never TIMESTAMP — spine S16. MySQL's TIMESTAMP stops in 2038
         * and silently converts on the way in and out according to the session
         * time zone, so the same instant reads differently from two connections.
         */
        public function timestamp(string $name): Column
        {
            return $this->add($name, 'DATETIME');
        }

        /** Both columns, so a ported table keeps the audit pair it had. */
        public function timestamps(): void
        {
            $this->add('created_at', 'DATETIME');
            $this->add('updated_at', 'DATETIME');
        }

        public function longText(string $name): Column
        {
            return $this->add($name, 'LONGTEXT');
        }

        public function unsignedTinyInteger(string $name): Column
        {
            return $this->add($name, 'TINYINT UNSIGNED');
        }

        public function uuid(string $name = 'uuid'): Column
        {
            return $this->add($name, 'CHAR(36)');
        }

        public function rememberToken(): Column
        {
            return $this->add('remember_token', 'VARCHAR(100)')->nullable();
        }

        public function binary(string $column, ?int $length = null, bool $fixed = false): Column
        {
            if ($length === null) {
                return $this->add($column, 'BLOB');
            }

            return $this->add($column, ($fixed ? 'BINARY' : 'VARBINARY') . "({$length})");
        }

        /** @param list<string> $columns */
        public function unique(array|string $columns, ?string $name = null): void
        {
            $columns = (array) $columns;
            $this->indexes[] = ['kind' => 'UNIQUE KEY', 'columns' => $columns,
                'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_uq'];
        }

        /** @param list<string> $columns */
        public function index(array|string $columns, ?string $name = null): void
        {
            $columns = (array) $columns;
            $this->indexes[] = ['kind' => 'KEY', 'columns' => $columns,
                'name' => $name ?? $this->table . '_' . implode('_', $columns) . '_idx'];
        }

        public function __call(string $method, array $arguments): never
        {
            throw new \BadMethodCallException(
                "Blueprint::{$method}() is not in the SmartPRS 3 migration vocabulary. "
                . 'Either use a supported column type or extend the test stub deliberately.'
            );
        }

        public function toSql(): string
        {
            $lines = [];

            foreach ($this->columns as $column) {
                $lines[] = '  ' . $column->toSql();
            }

            $lines[] = '  PRIMARY KEY (`id`)';

            foreach ($this->indexes as $index) {
                $columns = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $index['columns']));
                $lines[] = "  {$index['kind']} `{$index['name']}` ({$columns})";
            }

            foreach ($this->columns as $column) {
                if ($column->foreign === null) {
                    continue;
                }

                if ($column->foreign['onDelete'] === null) {
                    throw new \LogicException(
                        "{$this->table}.{$column->name} declares a foreign key with no ON DELETE behaviour (spine S16)."
                    );
                }

                $lines[] = sprintf(
                    '  CONSTRAINT `%s_%s_foreign` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
                    $this->table,
                    $column->name,
                    $column->name,
                    $column->foreign['table'],
                    $column->foreign['column'],
                    $column->foreign['onDelete'],
                );
            }

            return "CREATE TABLE `{$this->table}` (\n" . implode(",\n", $lines)
                . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        /** Take on a column added by a later ALTER, so `has()` stays truthful. */
        public function adopt(Column $column): void
        {
            $this->columns[] = $column;
        }

        public function has(string $name): bool
        {
            foreach ($this->columns as $column) {
                if ($column->name === $name) {
                    return true;
                }
            }

            return false;
        }

        private function add(string $name, string $sqlType): Column
        {
            return $this->columns[] = new Column($name, $sqlType, $this);
        }
    }
}

namespace Illuminate\Support\Facades {
    use Illuminate\Database\Schema\Blueprint;

    final class Schema
    {
        /** @var list<string> */
        public static array $statements = [];
        /** @var array<string, Blueprint> */
        public static array $blueprints = [];

        public static function create(string $table, callable $definition): void
        {
            $blueprint = new Blueprint($table);
            $definition($blueprint);

            self::$blueprints[$table] = $blueprint;
            self::$statements[] = $blueprint->toSql();
        }

        /**
         * Ported migrations guard every create with this so they are re-runnable:
         * MySQL DDL is not transactional, so a migration that stops half way must
         * repair itself on the next run rather than dying on the first table that
         * already exists.
         */
        public static function hasTable(string $table): bool
        {
            return isset(self::$blueprints[$table]);
        }

        public static function hasColumn(string $table, string $column): bool
        {
            return isset(self::$blueprints[$table]) && self::$blueprints[$table]->has($column);
        }

        /**
         * A real ALTER. Mapping this onto create() made an ALTER migration try to
         * create a table that already exists — the failure was loud, which is the
         * good outcome, but the modelling was simply wrong.
         *
         * Columns are added IF NOT EXISTS in effect: the blueprint for the table
         * is updated so later checks see them, and each column becomes its own
         * ADD COLUMN so one that is already there does not abort the rest.
         */
        public static function table(string $table, callable $definition): void
        {
            $blueprint = new Blueprint($table);
            $definition($blueprint);
            $existing = self::$blueprints[$table] ?? null;

            foreach ($blueprint->columns as $column) {
                $afterClause = $column->after !== null ? " AFTER `{$column->after}`" : '';

                if ($column->changes) {
                    // A change is not an add, and it is not skippable either: the
                    // column existing is the PRECONDITION, so the "already there"
                    // rule below would turn every widening into a silent no-op.
                    self::$statements[] = "ALTER TABLE `{$table}` MODIFY COLUMN " . $column->toSql() . $afterClause;
                    $existing?->adopt($column);

                    continue;
                }

                if ($existing !== null && $existing->has($column->name)) {
                    continue;
                }

                self::$statements[] = "ALTER TABLE `{$table}` ADD COLUMN " . $column->toSql() . $afterClause;
                $existing?->adopt($column);
            }
        }

        public static function dropIfExists(string $table): void
        {
            // down() is not executed by the schema test; recorded for completeness.
        }

        public static function reset(): void
        {
            self::$statements = [];
            self::$blueprints = [];
        }
    }
}
