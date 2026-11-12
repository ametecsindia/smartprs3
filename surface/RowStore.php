<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT EVERY SCREEN IS ALLOWED TO ASK OF STORAGE.
 *
 * `Store` (JSON files) and `SqlStore` (MySQL) both implement this, and every
 * surface class type-hints the interface rather than either one. That is what
 * makes moving a screen from files to the database a configuration change
 * instead of an edit to the screen.
 *
 * The interface is deliberately SMALL — four reads and one write. Everything a
 * screen wants beyond this (a join, an aggregate, a partial update) becomes a
 * named method on a repository where the query is written out and can be read,
 * rather than a query builder that lets any screen ask anything. The narrowness
 * is the point: a storage layer a screen can ask arbitrary questions of is one
 * where the tenancy filter is a thing somebody remembers.
 *
 * `write()` is NOT here. Replacing a whole collection is meaningful for a file
 * and catastrophic for a shared table, so it stays on the file store alone and
 * the SQL store refuses it by name.
 */
interface RowStore
{
    /** A store scoped to one tenant. Every read filters and every write stamps. */
    public function forTenant(string $tenantId): self;

    public function tenantId(): ?string;

    /**
     * EVERY ROW. Measured on this machine against the real `attendance_logs`
     * shape: 40,000 rows costs 0.51s and 68 MB resident before PHP touches them,
     * and a year of punches for 200 staff is ~500,000 rows — about 850 MB, which
     * is a fatal error rather than a slow screen. Use `page()` for anything a
     * person looks at; `all()` is for aggregates and small tables.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $collection): array;

    /**
     * ONE PAGE OF ROWS, IN A DECLARED ORDER.
     *
     * The order is REQUIRED, not optional. "Page 2" of an unordered set is not a
     * defined thing: the same rows can appear on both pages and others on
     * neither, and the screen looks right while being wrong.
     *
     * `$orderBy` is a column name from the caller, which makes it an injection
     * surface, so both stores check it against the columns the collection really
     * has and REFUSE an unknown one by name. Never a silent fall back to a
     * default order — that is a screen that says it is sorted by date and is not.
     *
     * Rows are also tie-broken by `id` where the collection has one, so a page of
     * rows sharing an order value is still reproducible.
     *
     * SEARCH IS PART OF PAGING, not a step after it. It is two optional
     * parameters rather than a second method because "the rows matching this
     * text" is still one page of one collection, and a separate search path is a
     * second place for the tenancy filter to be got right. `$searchColumns` is
     * checked against the collection's real columns exactly as `$orderBy` is, and
     * an unknown one is REFUSED: a search that quietly ignores a column returns
     * fewer rows and looks like an answer.
     *
     * @param string $direction 'asc' or 'desc'; anything else is refused
     * @param list<string> $searchColumns columns to match `$needle` in; [] means no search
     * @param string $needle matched case-insensitively as a substring; '' means no search
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException on an unknown column or direction
     */
    public function page(
        string $collection,
        string $orderBy,
        int $limit,
        int $offset = 0,
        string $direction = 'asc',
        array $searchColumns = [],
        string $needle = '',
    ): array;

    /** Another tenant's row is NOT FOUND, never forbidden — forbidden confirms it exists. */
    public function find(string $collection, string $id): ?array;

    /**
     * Insert or update by id, returning what was stored.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function put(string $collection, array $row): array;

    /**
     * How many rows this tenant has. The COUNT that goes with `page()`: it is the
     * same number whatever the page size, and it never loads a row to get there.
     *
     * The search parameters are the SAME ones `page()` takes and must be passed
     * the same values, or the pager counts a different set from the one it is
     * paging — "1 of 4 pages" over three rows.
     *
     * @param list<string> $searchColumns
     */
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int;

    /**
     * COUNT AND SUM, DONE WHERE THE ROWS ARE.
     *
     * The tiles above a list and every report used to read the whole table and
     * add it up in PHP. This is the same question asked of storage: how many rows
     * match, and what does `$column` add up to, optionally broken down by
     * `$groupBy`.
     *
     * THE SUM IS AN EXACT DECIMAL STRING, never a float (Law 7). Both stores
     * round each row to paise BEFORE adding — a DECIMAL(18,4) column holds
     * sub-paise digits, and rounding once at the end gives a different number
     * from rounding each row, which is what the PHP loop this replaces did.
     *
     * `$column`, `$groupBy` and every key of `$where` are checked against the
     * collection's real columns and refused by name if they are not there.
     * `$where` is `column => list of acceptable values`, matched as strings.
     *
     * A group key is the value of `$groupBy` as a string; NULL and '' both come
     * back as '', so the caller can count the rows it could not group rather than
     * dropping them. With no `$groupBy` there is exactly one bucket, keyed ''.
     *
     * @param array<string, list<scalar>> $where
     * @return list<array{key: string, count: int, sum: string}>
     * @throws \InvalidArgumentException on a column the collection has not got
     */
    public function aggregate(
        string $collection,
        string $column = '',
        array $where = [],
        string $groupBy = '',
    ): array;

    /**
     * The columns this collection has.
     *
     * On the interface because a screen that builds a form needs them BEFORE
     * there is a first row to read them from — a form derived from row zero is
     * a screen that only works once it already has data, which is the one moment
     * it is no use.
     *
     * @return list<string>
     */
    public function columnsOf(string $collection): array;
}
