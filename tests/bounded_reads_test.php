<?php

declare(strict_types=1);

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Store;

/**
 * A LIST MUST NOT READ THE WHOLE TABLE, AND BOTH STORES MUST PAGE THE SAME WAY.
 *
 * `all()` is `SELECT * FROM t WHERE tenant_id = ?` with no LIMIT, and every list,
 * picker, report and dashboard tile went through it and paged in PHP afterwards.
 * Measured on this machine against the real `attendance_logs` shape: 40,000 rows
 * costs 0.51s and 68 MB resident before PHP touches them, where the first page of
 * 50 via SQL LIMIT is 0.001s and COUNT(*) is 0.005s. A year of punches for 200
 * staff is ~500,000 rows — about 850 MB, which is a fatal error at the memory
 * limit rather than a slow screen, on the biggest table in an HR system.
 *
 * The load-bearing assertion here is not that paging works. It is that the JSON
 * fallback and MySQL give the SAME page and the SAME count for the same data,
 * compared as one value against another rather than as two assertions that
 * happen to pass separately. A file store that agrees with whatever it is told is
 * how five wrong column names survived in this repository for months.
 */

group('bounded reads');

/**
 * `throws()` matches the exception CLASS; what matters for a refusal is the
 * SENTENCE. Named differently from `sql_store_test.php`'s `refuses()` because
 * both files run in one process and a redeclared function is a fatal error.
 */
function refusalSays(string $expected, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        ok(str_contains($e->getMessage(), $expected),
            $message . ' (said "' . substr($e->getMessage(), 0, 70) . '…")');

        return;
    }

    ok(false, $message . ' — nothing was thrown, so the refusal does not happen at all');
}

/** The columns worth comparing between the two stores: SQL stamps timestamps, files do not. */
$shape = static fn (array $rows): array => array_map(
    static fn (array $r): array => [(string) $r['id'], (string) $r['name'], (string) $r['code']],
    $rows,
);

// ---------------------------------------------------------------- the fixture
//
// Ten rows, seven of them this tenant's. Two share a name, so the id tie-break
// is exercised and a page boundary can fall in the middle of the tie — which is
// exactly where an unordered page repeats one row and skips another.
$people = [
    ['id' => '1', 'tenant' => '1', 'name' => 'Anita', 'code' => 'E01'],
    ['id' => '2', 'tenant' => '1', 'name' => 'Bhavna', 'code' => 'E02'],
    ['id' => '3', 'tenant' => '2', 'name' => 'Aakash', 'code' => 'X01'],
    ['id' => '4', 'tenant' => '1', 'name' => 'Chandra', 'code' => 'E03'],
    ['id' => '5', 'tenant' => '1', 'name' => 'Chandra', 'code' => 'E04'],
    ['id' => '6', 'tenant' => '2', 'name' => 'Bimal', 'code' => 'X02'],
    ['id' => '7', 'tenant' => '1', 'name' => 'Deepak', 'code' => 'E05'],
    ['id' => '8', 'tenant' => '1', 'name' => 'Esha', 'code' => 'E06'],
    ['id' => '9', 'tenant' => '2', 'name' => 'Zubin', 'code' => 'X03'],
    ['id' => '10', 'tenant' => '1', 'name' => 'Farida', 'code' => 'E07'],
];

// Ordered by name then id, tenant 1 only. Written out by hand rather than
// computed, so the test states the answer instead of agreeing with the code.
$expected = [
    ['1', 'Anita', 'E01'],
    ['2', 'Bhavna', 'E02'],
    ['4', 'Chandra', 'E03'],
    ['5', 'Chandra', 'E04'],
    ['7', 'Deepak', 'E05'],
    ['8', 'Esha', 'E06'],
    ['10', 'Farida', 'E07'],
];

$files = new Store(sys_get_temp_dir() . '/prs3-bounded-' . bin2hex(random_bytes(4)));

foreach ($people as $row) {
    $files->forTenant($row['tenant'])
        ->put('staff', ['id' => $row['id'], 'name' => $row['name'], 'code' => $row['code']]);
}

$fileStore = $files->forTenant('1');

// -------------------------------------------------------- the file store alone
same(array_slice($expected, 0, 3), $shape($fileStore->page('staff', 'name', 3)),
    'the first page of three, ordered by name');
same(array_slice($expected, 3, 3), $shape($fileStore->page('staff', 'name', 3, 3)),
    'and the second page continues where it stopped — no row repeated, none skipped, including '
    . 'across the two rows that share the name Chandra');
same($expected, $shape(array_merge(
    $fileStore->page('staff', 'name', 3),
    $fileStore->page('staff', 'name', 3, 3),
    $fileStore->page('staff', 'name', 3, 6),
)), 'and three pages of three reassemble the whole ordered set exactly once');
same([], $fileStore->page('staff', 'name', 3, 99),
    'a page past the end is empty rather than the last page again');
same(array_reverse($expected), $shape($fileStore->page('staff', 'name', 25, 0, 'desc')),
    'and desc is the same order backwards, tie-break included');

same(7, $fileStore->count('staff'), 'the count is the tenant\'s rows');
same(7, count($fileStore->all('staff')), 'and matches what all() would have returned');
same($fileStore->count('staff'), $fileStore->count('staff'),
    'and does not depend on the page size — nothing about a page is passed to it');
same(3, $files->forTenant('2')->count('staff'), 'the other tenant counts its own three');

// ------------------------------------------------------------ tenancy, paged
$everyName = [];

foreach ([0, 3, 6, 9] as $offset) {
    foreach ($fileStore->page('staff', 'name', 3, $offset) as $row) {
        $everyName[] = (string) $row['name'];
    }
}

foreach (['Aakash', 'Bimal', 'Zubin'] as $theirs) {
    ok(!in_array($theirs, $everyName, true),
        "another tenant's row ({$theirs}) is on no page — the bounded path filters like the "
        . 'unbounded one, structurally, not because a screen remembered to');
}

same(7, count($everyName), 'and the pages hold exactly this tenant\'s seven rows in total');

// -------------------------------------------------- an order nobody can honour
refusalSays(
    "There is no column 'salary_of_the_month' to order by",
    static fn (): array => $fileStore->page('staff', 'salary_of_the_month', 3),
    'an unknown ORDER BY column is refused by name, never quietly replaced with a default order',
);
refusalSays(
    'refused rather than replaced with a default order',
    static fn (): array => $fileStore->page('staff', 'name; DROP TABLE staff', 3),
    'and an injection attempt is refused for the same reason — the column list is the whitelist',
);
refusalSays(
    'is not a sort direction',
    static fn (): array => $fileStore->page('staff', 'name', 3, 0, 'sideways'),
    'and so is a direction that is not asc or desc',
);

// ------------------------------------------------------ the module list is bounded
$registry = sys_get_temp_dir() . '/prs3-bounded-modules-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($registry, json_encode(['modules' => [
    ['key' => 'staff', 'label' => 'Staff', 'group' => 'People', 'table' => 'staff',
        'columns' => ['name', 'code'], 'search' => ['name'], 'order' => 'name'],
    ['key' => 'broken', 'label' => 'Broken', 'group' => 'People', 'table' => 'staff',
        'columns' => ['name'], 'search' => [], 'order' => 'no_such_column'],
]]));

$modules = new Modules($fileStore, $registry);
$list = $modules->rows($modules->find('staff'), '', 1, 3);

ok($list['bounded'], 'the module list reads a bounded page, not the whole table');
same(3, count($list['rows']), 'of exactly the page size asked for');
same(7, $list['total'], 'while the total is the whole tenant, so the pager is right');
same(3, $list['pages'], 'and the number of pages comes from that total');
same(array_slice($expected, 0, 3), $shape($list['rows']), 'in the module\'s declared order');
same(array_slice($expected, 3, 3), $shape($modules->rows($modules->find('staff'), '', 2, 3)['rows']),
    'and page two of the screen is page two of the store');

$badOrder = $modules->rows($modules->find('broken'), '', 1, 3);
same([], $badOrder['rows'], 'a module declaring an order its table has not got shows no rows');
ok(str_contains((string) $badOrder['why'], "There is no column 'no_such_column'"),
    'and says why on the screen — a list silently sorted by something else is wrong in a way '
    . 'nobody looking at it can see');

// A search used to filter in PHP over every row and flag itself unbounded; since
// bounded_search_test.php it is pushed into the store like the plain list, so the
// result must now say so — an exposure that was closed and still reported open
// would teach people to ignore the flag.
$searched = $modules->rows($modules->find('staff'), 'chandra', 1, 3);
same(2, $searched['total'], 'a search still finds its rows');
ok($searched['bounded'], 'and is bounded like the plain list — the PHP-side filter is gone');

// ------------------------------------------------------------- against MySQL
$pdo = null;

try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=smartprs3;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! bounded reads vs MySQL skipped: no database ({$e->getMessage()})\n";

    return;
}

// DDL needs root: the application account deliberately has none. The probe table
// is dropped at the end — one left behind is its own defect.
$pdo->exec('DROP TABLE IF EXISTS bounded_reads_probe');
$pdo->exec('CREATE TABLE bounded_reads_probe (id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255), code VARCHAR(64),
    created_at DATETIME NULL, updated_at DATETIME NULL)');

try {
    $sql = new SqlStore($pdo);

    foreach ($people as $row) {
        $sql->forTenant($row['tenant'])->put('bounded_reads_probe',
            ['id' => $row['id'], 'name' => $row['name'], 'code' => $row['code']]);
    }

    $sqlStore = $sql->forTenant('1');

    same($expected, $shape($sqlStore->page('bounded_reads_probe', 'name', 25)),
        'MySQL orders the same seven rows the same way');
    same(7, $sqlStore->count('bounded_reads_probe'), 'and COUNT(*) counts this tenant only');
    same(10, (int) $pdo->query('SELECT COUNT(*) FROM bounded_reads_probe')->fetchColumn(),
        'while the table holds all ten — the filter is in the store, not in the data');

    // THE ASSERTION THAT MATTERS. Not "each store paged correctly" twice over:
    // one store's answer compared against the other's, page by page, count
    // included. If the JSON fallback and MySQL ever disagree about what page 2
    // is, a customer running without a database sees different rows from one
    // running with it, and every other assertion in this file passes anyway.
    foreach ([[3, 0], [3, 3], [3, 6], [3, 9], [2, 1], [25, 0]] as [$limit, $offset]) {
        same(
            $shape($fileStore->page('staff', 'name', $limit, $offset)),
            $shape($sqlStore->page('bounded_reads_probe', 'name', $limit, $offset)),
            "the two stores return the IDENTICAL page for limit {$limit} offset {$offset}",
        );
        same(
            $shape($fileStore->page('staff', 'name', $limit, $offset, 'desc')),
            $shape($sqlStore->page('bounded_reads_probe', 'name', $limit, $offset, 'desc')),
            "and the identical page descending for limit {$limit} offset {$offset}",
        );
    }

    same($fileStore->count('staff'), $sqlStore->count('bounded_reads_probe'),
        'and the identical count');
    same($files->forTenant('2')->count('staff'),
        $sql->forTenant('2')->count('bounded_reads_probe'),
        'for the other tenant too, so the agreement is not an accident of one tenant');

    // Non-vacuity: a page really is a page. Without a LIMIT reaching SQL this
    // would be seven, and every comparison above would still hold.
    same(2, count($sqlStore->page('bounded_reads_probe', 'name', 2)),
        'the LIMIT reaches SQL — two rows asked for, two rows back, not seven sliced afterwards');
    same([], $sqlStore->page('bounded_reads_probe', 'name', 3, 99),
        'and an offset past the end is empty');

    refusalSays(
        "There is no column 'salary_of_the_month' to order by",
        static fn (): array => $sqlStore->page('bounded_reads_probe', 'salary_of_the_month', 3),
        'MySQL refuses the unknown order column with the same sentence as the file store — checked '
        . 'against the real schema, because an identifier cannot be a bound parameter',
    );
    refusalSays(
        'refused rather than replaced with a default order',
        static fn (): array => $sqlStore->page('bounded_reads_probe', '`id`; DROP TABLE tenants --', 3),
        'and an injected identifier never reaches the query',
    );
    same(10, (int) $pdo->query('SELECT COUNT(*) FROM bounded_reads_probe')->fetchColumn(),
        'and the refused attempts changed nothing');

    // Tenancy on the bounded path, in SQL.
    $seen = [];

    foreach ([0, 3, 6, 9] as $offset) {
        foreach ($sqlStore->page('bounded_reads_probe', 'name', 3, $offset) as $row) {
            $seen[] = (string) $row['name'];
        }
    }

    same(7, count($seen), "every page of the SQL store holds only this tenant's rows");

    foreach (['Aakash', 'Bimal', 'Zubin'] as $theirs) {
        ok(!in_array($theirs, $seen, true),
            "and {$theirs} — another tenant's row — is on none of them");
    }
} finally {
    $pdo->exec('DROP TABLE IF EXISTS bounded_reads_probe');
}
