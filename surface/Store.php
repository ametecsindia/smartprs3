<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;

/**
 * A FILE-BACKED STORE, SO THE APPLICATION RUNS WITH NOTHING INSTALLED.
 *
 * ponytail: JSON files under `storage/`, one per collection, written whole. No
 * database, no composer, no migration — the point of this slice is that somebody
 * can open a browser and use it five minutes after unzipping, and every step
 * between them and that is a step where it does not get looked at.
 *
 * The ceiling is honest and near: whole-file writes are not concurrent-safe and
 * a few thousand rows is the practical limit. The upgrade path is this one class
 * — every caller goes through `all()`, `find()` and `put()`, so swapping the
 * body for the tenant-scoped repositories in `src/Platform/Persistence` changes
 * nothing above it.
 *
 * What it does NOT do is pretend to be the real persistence layer. Tenancy,
 * effective dating, the audit chain and the append-only guarantees live in
 * Platform and are not reimplemented here — this is a demonstration surface over
 * engines that already have them.
 */
final class Store implements RowStore
{
    /**
     * Collections that belong to the PLATFORM, not to any tenant: the tenant
     * registry itself, and the users who sign in (a user must be found before
     * there is a tenant to scope by). Everything not on this list is tenant
     * data, so a new collection is scoped by DEFAULT — the opposite way round
     * from an allow-list of scoped tables, where the one somebody forgets to add
     * is the one that leaks.
     */
    public const PLATFORM_SCOPED = ['tenants', 'users'];

    public function __construct(
        private readonly string $directory,
        private readonly ?string $tenantId = null,
    ) {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The storage directory {$directory} could not be created.");
        }
    }

    /** A store scoped to one tenant. Every read and write below is filtered. */
    public function forTenant(string $tenantId): RowStore
    {
        return new self($this->directory, $tenantId);
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $collection): array
    {
        $rows = $this->raw($collection);

        if ($this->tenantId === null || in_array($collection, self::PLATFORM_SCOPED, true)) {
            return $rows;
        }

        // STRUCTURAL, not remembered. Every caller reads through here, so a
        // screen cannot forget the where-clause — which is the failure mode a
        // tenancy that lives in query strings has, and the reason SmartPRS 2's
        // NULL tenant_id rows are visible to everybody.
        return array_values(array_filter($rows,
            fn (array $row): bool => ($row['tenant_id'] ?? null) === $this->tenantId));
    }

    /**
     * THE ORDER A CALLER ASKED FOR, CHECKED AGAINST THE COLUMNS THAT EXIST.
     *
     * Static and shared with `SqlStore` on purpose: one validator means one
     * refusal message, and the two stores cannot come to disagree about which
     * orders are allowed. A column name cannot be a bound parameter in SQL, so
     * the only safe thing to do with one is check it against the real schema —
     * and REFUSE what is not there rather than quietly ordering by something
     * else, which is a screen that says it is sorted by date and is not.
     *
     * @param list<string> $columns the collection's real columns
     * @return array{0: string, 1: string} the column, and 'ASC' or 'DESC'
     */
    public static function orderColumn(string $orderBy, string $direction, array $columns): array
    {
        if (!in_array($orderBy, $columns, true)) {
            throw new \InvalidArgumentException(
                "There is no column '{$orderBy}' to order by. It is refused rather than replaced with "
                . 'a default order, because a list that says it is sorted by one thing and is sorted '
                . 'by another is wrong in a way nobody looking at it can see. Available: '
                . implode(', ', $columns) . '.'
            );
        }

        $wanted = strtoupper(trim($direction));

        if ($wanted !== 'ASC' && $wanted !== 'DESC') {
            throw new \InvalidArgumentException(
                "'{$direction}' is not a sort direction. Use 'asc' or 'desc'."
            );
        }

        return [$orderBy, $wanted];
    }

    /**
     * THE COLUMNS A CALLER ASKED TO SEARCH, CHECKED AGAINST THE ONES THAT EXIST.
     *
     * Static and shared with `SqlStore` for the same reason `orderColumn()` is:
     * one validator, one refusal message, and no way for the two stores to
     * disagree about which searches are allowed.
     *
     * A DECLARED COLUMN THAT IS NOT THERE IS A REFUSAL, never a column dropped
     * from the search. Dropping it returns FEWER rows than the person asked for
     * and every one of them looks right — somebody searches an employee code,
     * gets nothing, and concludes the employee does not exist.
     *
     * @param list<string> $wanted the module's declared search columns
     * @param list<string> $columns the collection's real columns
     * @return list<string> the same columns, once they are known to exist
     */
    public static function searchColumns(array $wanted, array $columns): array
    {
        foreach ($wanted as $column) {
            if (!in_array($column, $columns, true)) {
                throw new \InvalidArgumentException(
                    "There is no column '{$column}' to search. It is refused rather than dropped from "
                    . 'the search, because a search that quietly ignores a column returns fewer rows '
                    . 'than were asked for and every one of them looks right — somebody searches a '
                    . 'code, finds nothing, and concludes the record does not exist. Available: '
                    . implode(', ', $columns) . '.'
                );
            }
        }

        return array_values($wanted);
    }

    /** Does this row match `$needle` in any of `$columns`? Case-insensitive substring. */
    private static function matches(array $row, array $columns, string $needle): bool
    {
        foreach ($columns as $column) {
            if (str_contains(mb_strtolower((string) ($row[$column] ?? '')), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rows of a collection that match a search, tenant-scoped like `all()`
     * because it goes through it.
     *
     * @return list<array<string, mixed>>
     */
    private function matching(string $collection, array $searchColumns, string $needle): array
    {
        $rows = $this->all($collection);

        if ($searchColumns === [] || $needle === '' || $rows === []) {
            return $rows;
        }

        $columns = self::searchColumns($searchColumns, $this->columnsOf($collection));
        $lowered = mb_strtolower($needle);

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::matches($row, $columns, $lowered),
        ));
    }

    /**
     * One page of rows, ordered, over the decoded array.
     *
     * Deliberately the same shape and the same refusals as `SqlStore::page()`:
     * the file store agreeing with whatever it is told is how five wrong column
     * names survived in this codebase for months.
     *
     * @return list<array<string, mixed>>
     */
    public function page(
        string $collection,
        string $orderBy,
        int $limit,
        int $offset = 0,
        string $direction = 'asc',
        array $searchColumns = [],
        string $needle = '',
    ): array {
        $rows = $this->matching($collection, $searchColumns, $needle);
        $columns = $this->columnsOf($collection);

        // A collection with no rows has no columns to check an order against, so
        // there is nothing here to get wrong and nothing to refuse. `SqlStore`
        // still knows the schema of an empty table and still refuses there; the
        // two only differ where the answer is the empty page either way.
        //
        // Checked on the COLLECTION, not on the matched rows: a search that
        // happens to match nothing must still refuse an order the table has not
        // got, or the refusal appears and disappears depending on what was typed.
        if ($columns === []) {
            return [];
        }

        [$column, $wanted] = self::orderColumn($orderBy, $direction, $columns);

        if ($rows === []) {
            return [];
        }
        $sign = $wanted === 'ASC' ? 1 : -1;
        $tieBreak = $column !== 'id' && in_array('id', $columns, true);

        usort($rows, static function (array $a, array $b) use ($column, $sign, $tieBreak): int {
            $order = $sign * self::compare($a[$column] ?? null, $b[$column] ?? null);

            return $order !== 0 || !$tieBreak
                ? $order
                : $sign * self::compare($a['id'] ?? null, $b['id'] ?? null);
        });

        return array_slice($rows, max(0, $offset), max(1, $limit));
    }

    /**
     * Two values as MySQL would order them: NULL first, numbers numerically,
     * text case-insensitively (which is what `utf8mb4_unicode_ci` does).
     *
     * ponytail: a JSON file has no column types, so a numeric-LOOKING string in
     * what MySQL calls a VARCHAR sorts numerically here and lexically there
     * ('10' before '9'). The upgrade path is the type already declared on the
     * module field; add it here the day a module orders by such a column.
     */
    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }

        if (is_numeric($a) && is_numeric($b)) {
            // COMPARED AS EXACT DECIMALS, NOT AS FLOATS, and this is demonstrable
            // rather than theoretical. The columns this orders include `ctc`, which
            // is DECIMAL(18,4) — up to eighteen significant digits, where a double
            // carries about sixteen. Measured on this machine:
            //
            //     (float) '12345678901234.5678' <=> (float) '12345678901234.5679'  is 0
            //
            // Two different amounts, reported equal. MySQL orders them correctly, so
            // the file store and the SQL store would disagree about what page 2
            // contains — the two-implementations-drifting defect this codebase keeps
            // finding, this time inside the very comparator that makes the fallback
            // store behave like the real one. Law 7: money never touches a float.
            return self::compareDecimals((string) $a, (string) $b);
        }

        return strcasecmp((string) $a, (string) $b) <=> 0;
    }

    /**
     * Two numeric strings, compared exactly, with no arithmetic.
     *
     * ponytail: string comparison rather than bcmath, which is not guaranteed to be
     * installed, and rather than Money, which is a currency and this may be a head
     * count. Exponent notation ("1e3") is not handled — no column in this schema
     * holds one, and a wrong answer there would be silent, so it is named here
     * rather than assumed away.
     */
    private static function compareDecimals(string $a, string $b): int
    {
        $sign = static fn (string $v): int => str_starts_with($v, '-') ? -1 : 1;

        if ($sign($a) !== $sign($b)) {
            return $sign($a) <=> $sign($b);
        }

        $split = static function (string $v): array {
            $v = ltrim($v, '+-');
            [$whole, $fraction] = array_pad(explode('.', $v, 2), 2, '');

            return [ltrim($whole, '0'), rtrim($fraction, '0')];
        };

        [$aw, $af] = $split($a);
        [$bw, $bf] = $split($b);

        // Longer integer part is the larger magnitude; same length compares
        // lexically, which for digit strings IS numeric order.
        $order = strlen($aw) <=> strlen($bw) ?: strcmp($aw, $bw);

        if ($order === 0) {
            $length = max(strlen($af), strlen($bf));
            $order = strcmp(str_pad($af, $length, '0'), str_pad($bf, $length, '0'));
        }

        return $order * $sign($a);
    }

    /** @return list<array<string, mixed>> */
    private function raw(string $collection): array
    {
        $path = $this->path($collection);

        if (!is_file($path)) {
            return [];
        }

        $rows = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Another tenant's row is NOT FOUND, not forbidden. "Forbidden" confirms the
     * id exists, which is an answer worth having if you are guessing ids.
     */
    public function find(string $collection, string $id): ?array
    {
        foreach ($this->all($collection) as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /** Insert or replace by id, and return what was stored. */
    public function put(string $collection, array $row): array
    {
        $row['id'] ??= self::id();
        $scoped = $this->tenantId !== null && !in_array($collection, self::PLATFORM_SCOPED, true);

        if ($scoped) {
            $existing = $this->raw($collection);

            foreach ($existing as $candidate) {
                // Refused, not silently re-stamped. Writing another tenant's row
                // into this tenant is the one bug that cannot be undone by
                // fixing the filter afterwards.
                if (($candidate['id'] ?? null) === $row['id']
                    && ($candidate['tenant_id'] ?? null) !== $this->tenantId) {
                    throw new \RuntimeException(
                        "Row {$row['id']} in '{$collection}' belongs to another tenant. The write is "
                        . 'refused rather than re-stamped: moving a row between tenants silently is '
                        . 'the one mistake that cannot be undone by fixing a filter afterwards.'
                    );
                }
            }

            $row['tenant_id'] = $this->tenantId;
        }

        $rows = $this->raw($collection);
        $replaced = false;

        foreach ($rows as $index => $existing) {
            if (($existing['id'] ?? null) === $row['id']) {
                // MERGED, not replaced. `SqlStore` issues `UPDATE ... SET` for
                // the columns it was given and leaves the rest alone; this store
                // used to overwrite the whole document, so the same partial write
                // behaved differently on the two — on MySQL it updated a name, on
                // files it updated the name and ERASED every column the caller
                // had not sent.
                //
                // Found through the field-permission work: a viewer who may not
                // see salary edits somebody's name, the save correctly refuses to
                // write `ctc`, and on the file store the salary is silently blank
                // afterwards. A lock that deletes the thing it is protecting is
                // worse than no lock.
                $rows[$index] = array_merge($existing, $row);
                $row = $rows[$index];
                $replaced = true;

                break;
            }
        }

        if (!$replaced) {
            $rows[] = $row;
        }

        $this->writeRaw($collection, $rows);

        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    public function write(string $collection, array $rows): void
    {
        if ($this->tenantId !== null && !in_array($collection, self::PLATFORM_SCOPED, true)) {
            // A scoped store only ever SEES its own rows, so writing the whole
            // collection from one would delete every other tenant's. Refused
            // rather than made to merge: a merge here would be a second, quieter
            // way to write, and this class has one on purpose.
            throw new \RuntimeException(
                "write() is refused on a tenant-scoped store ('{$collection}'). A scoped store sees "
                . 'only its own rows, so writing the whole collection would delete every other '
                . "tenant's. Use put() per row."
            );
        }

        $this->writeRaw($collection, $rows);
    }

    /** The actual write. `put()` uses this because it has already merged the raw rows. */
    private function writeRaw(string $collection, array $rows): void
    {
        // Write-then-rename, so a crash mid-write leaves the previous file
        // intact rather than a truncated one. Cheap, and the difference between
        // losing one save and losing the collection.
        $path = $this->path($collection);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        file_put_contents($temporary, json_encode(array_values($rows),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        rename($temporary, $path);
    }

    /**
     * A JSON file has no schema, so the columns are whatever the rows carry —
     * the union of them, not the first row's, because rows written before a
     * field existed would otherwise hide it from the form forever.
     *
     * @return list<string>
     */
    public function columnsOf(string $collection): array
    {
        $columns = [];

        foreach ($this->all($collection) as $row) {
            foreach (array_keys($row) as $column) {
                $columns[(string) $column] = true;
            }
        }

        return array_keys($columns);
    }

    public function count(string $collection, array $searchColumns = [], string $needle = ''): int
    {
        return count($this->matching($collection, $searchColumns, $needle));
    }

    /**
     * The PHP half of the aggregate, over the tenant's rows.
     *
     * This IS the loop that used to live in `Modules::stats()` and `Reports::run()`
     * — moved here so the SQL store can answer the same question with one query
     * and the two can be compared value against value. Money goes through
     * `Amount`/`Money`, in paise, and comes back as a decimal string.
     *
     * ponytail: still O(rows) here, and that is the ceiling of a JSON file store,
     * not of this method — the SQL store answers it without loading a row. The
     * upgrade path for a customer big enough to feel it is the database they
     * would already be on.
     *
     * @return list<array{key: string, count: int, sum: string}>
     */
    public function aggregate(
        string $collection,
        string $column = '',
        array $where = [],
        string $groupBy = '',
    ): array {
        $rows = $this->all($collection);
        $columns = $this->columnsOf($collection);

        // Checked the same way and with the same words as the SQL store, so a
        // report or a tile naming a column that is not there fails identically on
        // both. An unchecked filter matches nothing and reads as "no rows".
        //
        // `$groupBy` is DELIBERATELY NOT CHECKED HERE, and only here. This store
        // has no schema — `columnsOf()` is the union of the keys the rows happen
        // to carry — so a real column that is null on every row is invisible to
        // it, and refusing on that would be the fallback store inventing a
        // refusal out of missing data. Grouping is also the one place where "no
        // such key" already has a defined, VISIBLE answer: the rows land in the
        // '' bucket, which the caller reports as "(not set)" rather than dropping.
        // A filter or a sum column has no such answer — it would silently match
        // nothing or total nothing — so those are refused. `SqlStore` has the real
        // schema and checks all three.
        if ($columns !== []) {
            self::searchColumns(array_values(array_filter(array_merge(
                array_map('strval', array_keys($where)),
                [$column],
            ), static fn (string $c): bool => $c !== '')), $columns);
        }

        foreach ($where as $filter => $expected) {
            $wanted = array_map('strval', (array) $expected);
            $rows = array_values(array_filter($rows, static fn (array $row): bool
                => in_array((string) ($row[(string) $filter] ?? ''), $wanted, true)));
        }

        $buckets = [];

        foreach ($rows as $row) {
            $key = $groupBy === '' ? '' : (string) ($row[$groupBy] ?? '');
            $buckets[$key] ??= ['count' => 0, 'sum' => Money::zero()];
            $buckets[$key]['count']++;

            if ($column !== '') {
                $buckets[$key]['sum'] = $buckets[$key]['sum']->plus(Amount::of($row[$column] ?? 0));
            }
        }

        if ($buckets === [] && $groupBy === '') {
            // An empty collection still answers "none, nothing" rather than
            // nothing at all: the tile above a list must read 0, not blank.
            $buckets[''] = ['count' => 0, 'sum' => Money::zero()];
        }

        $out = [];

        foreach ($buckets as $key => $bucket) {
            $out[] = ['key' => (string) $key, 'count' => $bucket['count'],
                'sum' => $bucket['sum']->toDecimalString()];
        }

        return $out;
    }

    /** A time-ordered identifier, so a list sorts naturally by creation. */
    public static function id(): string
    {
        return sprintf('%08x-%s', time(), bin2hex(random_bytes(6)));
    }

    private function path(string $collection): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $collection) !== 1) {
            throw new \InvalidArgumentException("'{$collection}' is not a collection name.");
        }

        return $this->directory . '/' . $collection . '.json';
    }
}
