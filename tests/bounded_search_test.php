<?php

declare(strict_types=1);

use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Reports;
use SmartPRS\Surface\SqlStore;
use SmartPRS\Surface\Store;

/**
 * THE SECOND HALF OF THE BOUNDED READ: SEARCH, AGGREGATE AND PICKER.
 *
 * `bounded_reads_test.php` proved the unsearched list pages in the store. That
 * left the arrangement that actually hurts: the screen was bounded until somebody
 * typed in the search box, and then it read the whole table. Fast until used.
 *
 * Three pushdowns are proved here, and the load-bearing assertion in each is the
 * SAME ONE as in the predecessor — one store's answer compared against the
 * other's, as one value, not two assertions that happen to pass separately:
 *
 *   1. SEARCH — the declared columns become a WHERE. Both stores must return the
 *      identical rows and the identical count, and neither may return another
 *      tenant's row on any page of a search.
 *   2. AGGREGATE — COUNT and SUM in SQL. The figure must equal the PHP loop it
 *      replaces TO THE PAISA, as a rupee string, and it must not be reachable by
 *      rounding once at the end: the fixture below is chosen so those two answers
 *      differ, or the assertion would pass either way and prove nothing.
 *   3. LOOKUP — a dropdown is complete or it says it is not, and the rows it does
 *      not show are still reachable, or the cap is data loss with a friendly name.
 */

group('bounded search');

/** Same job as `refusalSays()` next door; named apart because both files run in one process. */
function searchRefusalSays(string $expected, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        ok(str_contains($e->getMessage(), $expected),
            $message . ' (said "' . substr($e->getMessage(), 0, 80) . '…")');

        return;
    }

    ok(false, $message . ' — nothing was thrown, so the refusal does not happen at all');
}

/** The columns worth comparing across stores: SQL stamps timestamps, files do not. */
$shape = static fn (array $rows): array => array_map(
    static fn (array $r): array => [(string) $r['id'], (string) $r['name'], (string) $r['code']],
    $rows,
);

/** Buckets in a settled order, so two stores are compared on content not on GROUP BY order. */
$sorted = static function (array $buckets): array {
    usort($buckets, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

    return $buckets;
};

// ---------------------------------------------------------------- the fixture
//
// `ctc` carries FOUR decimal places on purpose — DECIMAL(18,4) is what the real
// column is. Each of tenant 1's figures ends in .0050, which rounds UP to the
// paisa on its own and rounds DOWN if the whole column is summed first:
//
//     per row : 1000.01 + 2000.01 + 3000.01            = 6000.03
//     summed  : ROUND(1000.0050+2000.0050+3000.0050,2) = 6000.02
//
// A paisa apart, on three rows. That is the difference between the PHP loop this
// replaces and the obvious SQL for it, and it is why the store rounds each row
// before adding rather than once at the end.
$people = [
    ['id' => '1', 'tenant' => '1', 'name' => 'Anita Rao', 'code' => 'E01',
        'dept' => 'Finance', 'ctc' => '1000.0050'],
    ['id' => '2', 'tenant' => '1', 'name' => 'Bhavna Rao', 'code' => 'E02',
        'dept' => 'Finance', 'ctc' => '2000.0050'],
    ['id' => '3', 'tenant' => '2', 'name' => 'Aakash Rao', 'code' => 'X01',
        'dept' => 'Finance', 'ctc' => '9999.9900'],
    ['id' => '4', 'tenant' => '1', 'name' => 'Chandra Rao', 'code' => 'E03',
        'dept' => 'Sales', 'ctc' => '3000.0050'],
    ['id' => '5', 'tenant' => '1', 'name' => 'Deepak Menon', 'code' => 'E04',
        'dept' => 'Sales', 'ctc' => '0.0000'],
    ['id' => '6', 'tenant' => '2', 'name' => 'Bimal Rao', 'code' => 'X02',
        'dept' => 'Sales', 'ctc' => '5555.5500'],
    ['id' => '7', 'tenant' => '1', 'name' => 'Esha Nair', 'code' => 'E05',
        'dept' => '', 'ctc' => '0.0000'],
];

$directory = sys_get_temp_dir() . '/prs3-search-' . bin2hex(random_bytes(4));
$files = new Store($directory);

foreach ($people as $row) {
    $files->forTenant($row['tenant'])->put('crew', [
        'id' => $row['id'], 'name' => $row['name'], 'code' => $row['code'],
        'dept' => $row['dept'], 'ctc' => $row['ctc'],
    ]);
}

$fileStore = $files->forTenant('1');

// --------------------------------------------------------------- search, files
//
// Written out by hand rather than computed, so the test states the answer instead
// of agreeing with whatever the code produced.
same([['1', 'Anita Rao', 'E01'], ['2', 'Bhavna Rao', 'E02'], ['4', 'Chandra Rao', 'E03']],
    $shape($fileStore->page('crew', 'name', 25, 0, 'asc', ['name'], 'rao')),
    'a search matches a substring of the declared column, case-insensitively');
same(3, $fileStore->count('crew', ['name'], 'rao'),
    'and the count counts the SAME set — a pager counting something else says "1 of 4 pages" '
    . 'over three rows');
same(5, $fileStore->count('crew'),
    'while the unsearched count is unchanged, so the search is a filter and not a new store');

same([['5', 'Deepak Menon', 'E04']], $shape($fileStore->page('crew', 'name', 25, 0, 'asc',
    ['name', 'code'], 'e04')), 'a search spans every declared column, not just the first');

same([], $fileStore->page('crew', 'name', 25, 0, 'asc', ['name'], 'zzz'),
    'and a search matching nothing is empty rather than everything — an ignored filter is how a '
    . 'list shows rows the person did not ask for');

// `%` and `_` are SQL wildcards. A person typing one is searching for the
// character, and if it reaches LIKE unescaped they get the whole table back.
$files->forTenant('1')->put('crew', ['id' => '8', 'name' => 'Percent 50%', 'code' => 'E06',
    'dept' => 'Sales', 'ctc' => '0.0000']);
same(1, $fileStore->count('crew', ['name'], '50%'), 'a literal % matches one row, not every row');
same(0, $fileStore->count('crew', ['name'], 'x%'),
    'and a % on its own is not a wildcard — a search box is not a query language');

// --------------------------------------------------------- tenancy, searched
$found = [];

foreach ($fileStore->page('crew', 'name', 25, 0, 'asc', ['name'], 'rao') as $row) {
    $found[] = (string) $row['name'];
}

foreach (['Aakash Rao', 'Bimal Rao'] as $theirs) {
    ok(!in_array($theirs, $found, true),
        "another tenant's {$theirs} matches the search text and is still not returned — the "
        . 'searched path filters structurally, not because a screen remembered to');
}

same(2, $files->forTenant('2')->count('crew', ['name'], 'rao'),
    "and the other tenant's own search finds its own two");

// ------------------------------------------------ a search nobody can honour
searchRefusalSays(
    "There is no column 'nickname' to search",
    static fn (): int => $fileStore->count('crew', ['nickname'], 'rao'),
    'an undeclared search column is refused BY NAME, never dropped from the search',
);
searchRefusalSays(
    'returns fewer rows than were asked for',
    static fn (): array => $fileStore->page('crew', 'name', 25, 0, 'asc', ['name', 'nickname'], 'rao'),
    'and one bad column among good ones refuses the whole search rather than searching the rest — '
    . 'a partial search returns fewer rows and every one of them looks right',
);

// ------------------------------------------------------- the aggregate, files
//
// This is the loop that used to live in `Modules::stats()` and `Reports::run()`.
$whole = $fileStore->aggregate('crew', 'ctc');
same(1, count($whole), 'an ungrouped aggregate is one bucket');
same(6, $whole[0]['count'], 'counting this tenant\'s rows');
same('6000.03', $whole[0]['sum'],
    'and totalling the column to the paisa, as a decimal string — each row rounded to paise '
    . 'before it is added, which is what Amount::of() does');
ok($whole[0]['sum'] !== '6000.02',
    'and NOT 6000.02, which is what rounding the column once at the end gives — the two answers '
    . 'differ here on purpose, so an implementation that rounds at the wrong moment fails');
// aggregate()'s return type — list<array{key: string, count: int, sum: string}> —
// now guarantees 'sum' is a string and 'count' is an int (Law 7), so the old
// is_string()/is_int() checks here asserted nothing further.

$byDept = $sorted($fileStore->aggregate('crew', 'ctc', [], 'dept'));
same([
    ['key' => '', 'count' => 1, 'sum' => '0.00'],
    ['key' => 'Finance', 'count' => 2, 'sum' => '3000.02'],
    ['key' => 'Sales', 'count' => 3, 'sum' => '3000.01'],
], $byDept, 'grouped, the rows that CANNOT be grouped get their own bucket rather than vanishing '
    . 'from the breakdown — a report that balances perfectly and is missing a hundred people is '
    . 'worse than one that admits the gap');

same([['key' => '', 'count' => 2, 'sum' => '3000.02']],
    $fileStore->aggregate('crew', 'ctc', ['dept' => ['Finance']]),
    'a filter narrows the rows before they are counted, matched as strings against a list');

searchRefusalSays("There is no column 'divison' to search",
    static fn (): array => $fileStore->aggregate('crew', 'ctc', ['divison' => ['Finance']]),
    'a filter on a column that is not there is refused — it would match no rows, and an empty '
    . 'report reads as "there is nothing to report"');

// --------------------------------------------------- the module list, searched
$registry = sys_get_temp_dir() . '/prs3-search-modules-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($registry, json_encode(['modules' => [
    ['key' => 'crew', 'label' => 'Crew', 'group' => 'People', 'table' => 'crew',
        'columns' => ['name', 'code'], 'search' => ['name', 'code'], 'order' => 'name',
        'display' => ['stats' => [
            ['label' => 'People', 'measure' => 'count'],
            ['label' => 'Finance', 'measure' => 'count', 'where' => ['dept' => ['Finance']]],
            ['label' => 'Total CTC', 'measure' => 'sum', 'column' => 'ctc', 'money' => true],
        ]]],
    ['key' => 'wrong', 'label' => 'Wrong', 'group' => 'People', 'table' => 'crew',
        'columns' => ['name'], 'search' => ['nickname'], 'order' => 'name'],
]]));

$modules = new Modules($fileStore, $registry);
$searched = $modules->rows((array) $modules->find('crew'), 'rao', 1, 2);

ok($searched['bounded'],
    'A SEARCHED LIST IS BOUNDED NOW. It used to fall back to all() the moment somebody typed, so '
    . 'the screen was fast until it was used — the worst possible arrangement.');
same(3, $searched['total'], 'the total is every matching row, not the page');
same(2, count($searched['rows']), 'while the page holds only what was asked for');
same(2, $searched['pages'], 'and the pager is computed from the searched total');
same([['4', 'Chandra Rao', 'E03']],
    $shape($modules->rows((array) $modules->find('crew'), 'rao', 2, 2)['rows']),
    'page two of a search continues where page one stopped');

$broken = $modules->rows((array) $modules->find('wrong'), 'rao');
same([], $broken['rows'], 'a module declaring a search column its table has not got shows no rows');
ok(str_contains((string) $broken['why'], "There is no column 'nickname' to search"),
    'and says so ON THE SCREEN — a search silently missing a column returns fewer rows and looks '
    . 'like an answer');

// ------------------------------------------------------------- stats, in SQL shape
$everything = static fn (string $p): bool => true;
$stats = [];

foreach ($modules->stats((array) $modules->find('crew'), $everything) as $stat) {
    $stats[(string) $stat['label']] = $stat['value'];
}

same(6, $stats['People'], 'the headcount tile counts every row of the tenant');
same(2, $stats['Finance'], 'and a filtered tile counts only its own');
same('6000.03', $stats['Total CTC'],
    'and the money tile is the exact figure, to the paisa, as a rupee string');

// ------------------------------------------------------------ the picker
//
// 501 rows against a cap of 500: one row over, so the boundary is exercised
// rather than a number safely far from it.
$pickRegistry = sys_get_temp_dir() . '/prs3-search-pick-' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($pickRegistry, json_encode(['modules' => [
    ['key' => 'jobs', 'label' => 'Jobs', 'group' => 'Work', 'table' => 'jobs',
        'columns' => ['pick_id'], 'search' => [], 'order' => 'id',
        'fields' => ['pick_id' => ['label' => 'Owner',
            'lookup' => ['table' => 'people', 'value' => 'id', 'show' => ['name']]]]],
]]));

// Written through the raw store once rather than 501 tenant-scoped put()s: this
// is a fixture, and a whole-file rewrite per row is a minute of test runtime.
$smallCrowd = [];

for ($i = 1; $i <= 500; $i++) {
    $smallCrowd[] = ['id' => (string) $i, 'tenant_id' => '1', 'name' => sprintf('P%04d', $i)];
}

// One row belonging to somebody else, to prove the picker is scoped like every
// other read — a dropdown is the easiest place to leak another customer's names.
$smallCrowd[] = ['id' => '9001', 'tenant_id' => '2', 'name' => 'Not Yours'];
$files->write('people', $smallCrowd);

$picker = new Modules($files->forTenant('1'), $pickRegistry);
$field = $picker->fields((array) $picker->find('jobs'))['fields'][0];

same('Owner', $field['label'], 'the picker field is the declared one');
same(500, $field['lookup_bounds']['total'], 'the store counted the tenant\'s rows only');
ok($field['lookup_bounds']['complete'],
    'AT THE CAP THE LIST IS COMPLETE. A dropdown showing 50 of 500 people is a defect, not an '
    . 'optimisation: somebody picks the wrong Sharma, or decides an employee does not exist.');
same(500, count($field['lookup']), 'and every one of them is an option');
ok(!in_array('Not Yours', array_column($field['lookup'], 'label'), true),
    "while another tenant's row is on no dropdown at all");

// One more row, and the same picker must now say it is showing part of a list.
$smallCrowd[] = ['id' => '501', 'tenant_id' => '1', 'name' => 'P0501'];
$files->write('people', $smallCrowd);

$picker = new Modules($files->forTenant('1'), $pickRegistry);
$field = $picker->fields((array) $picker->find('jobs'))['fields'][0];

same(501, $field['lookup_bounds']['total'], 'one row past the cap, the total is the real total');
same(500, $field['lookup_bounds']['shown'], 'and the list is capped');
ok(!$field['lookup_bounds']['complete'],
    'AND IT SAYS SO. The truncation is in the returned data, so the screen can render "500 of '
    . '501" rather than presenting a partial list as the whole one.');
ok(!in_array('P0501', array_column($field['lookup'], 'label'), true),
    'the 501st row really is off the list — ordered by the label, so "the first 500" is a set a '
    . 'person can reason about rather than an accident of insertion order');

// THE ONE THAT DECIDES WHETHER THE CAP IS AN OPTIMISATION OR DATA LOSS.
same([], $picker->problems((array) $picker->find('jobs'), ['pick_id' => '501']),
    'a row the dropdown could not show is STILL SAVEABLE — validated against the store, not '
    . 'against the truncated list. Rejecting it would turn a cap into data loss: the 501st '
    . 'employee becomes unselectable and nothing on the screen says why.');

$missing = $picker->problems((array) $picker->find('jobs'), ['pick_id' => '9999']);
ok($missing !== [] && str_contains($missing[0], 'no record with id 9999'),
    'while an id that does not exist is still refused — the check got looser about the LIST, not '
    . 'about the DATA');
ok($picker->problems((array) $picker->find('jobs'), ['pick_id' => '9001']) !== [],
    "and another tenant's id is refused too, so the looser check did not become a way round the "
    . 'tenancy filter');

// ------------------------------------------------------------- against MySQL
$pdo = null;

try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=smartprs3;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! bounded search vs MySQL skipped: no database ({$e->getMessage()})\n";

    return;
}

// DDL needs root: the application account deliberately has none. Dropped at the
// end — a probe table left behind is its own defect.
$pdo->exec('DROP TABLE IF EXISTS bounded_search_probe');
$pdo->exec('CREATE TABLE bounded_search_probe (id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(255), code VARCHAR(64),
    dept VARCHAR(64), ctc DECIMAL(18,4) NOT NULL DEFAULT 0,
    created_at DATETIME NULL, updated_at DATETIME NULL)');

try {
    $sql = new SqlStore($pdo);

    foreach ($people as $row) {
        $sql->forTenant($row['tenant'])->put('bounded_search_probe', [
            'id' => $row['id'], 'name' => $row['name'], 'code' => $row['code'],
            'dept' => $row['dept'], 'ctc' => $row['ctc'],
        ]);
    }

    $sql->forTenant('1')->put('bounded_search_probe', ['id' => '8', 'name' => 'Percent 50%',
        'code' => 'E06', 'dept' => 'Sales', 'ctc' => '0.0000']);

    $sqlStore = $sql->forTenant('1');

    // -------------------------------------------------- THE ASSERTION THAT MATTERS
    //
    // Not "each store searched correctly" twice over: one store's answer against
    // the other's, needle by needle, count included. If the JSON fallback and
    // MySQL ever disagree about what a search returns, a customer running without
    // a database sees different rows from one running with it — and every other
    // assertion in this file passes anyway.
    foreach ([['name'], ['code'], ['name', 'code']] as $columns) {
        foreach (['rao', 'RAO', 'e0', '50%', 'menon', 'zzz', ''] as $needle) {
            $where = implode('+', $columns);
            same(
                $shape($fileStore->page('crew', 'name', 25, 0, 'asc', $columns, $needle)),
                $shape($sqlStore->page('bounded_search_probe', 'name', 25, 0, 'asc',
                    $columns, $needle)),
                "the two stores return the IDENTICAL rows searching {$where} for '{$needle}'",
            );
            same(
                $fileStore->count('crew', $columns, $needle),
                $sqlStore->count('bounded_search_probe', $columns, $needle),
                "and the identical count searching {$where} for '{$needle}'",
            );
        }
    }

    // Non-vacuity: the LIMIT and the WHERE both reach SQL. Without the WHERE this
    // would be six; without the LIMIT, three.
    same(2, count($sqlStore->page('bounded_search_probe', 'name', 2, 0, 'asc', ['name'], 'rao')),
        'the LIMIT reaches SQL alongside the WHERE — two asked for, two back');
    same(3, $sqlStore->count('bounded_search_probe', ['name'], 'rao'),
        'while the count is the whole matching set, unaffected by the page size');
    same(7, (int) $pdo->query('SELECT COUNT(*) FROM bounded_search_probe WHERE name LIKE "%Rao%"
        OR name LIKE "%Menon%" OR name LIKE "%Percent%"')->fetchColumn(),
        'and the table itself holds all eight rows minus none — the filter is in the store, not '
        . 'in the data');

    // Tenancy, in SQL, on the searched path.
    $seen = [];

    foreach ($sqlStore->page('bounded_search_probe', 'name', 25, 0, 'asc', ['name'], 'rao') as $r) {
        $seen[] = (string) $r['name'];
    }

    same(3, count($seen), "a search returns only this tenant's matching rows");

    foreach (['Aakash Rao', 'Bimal Rao'] as $theirs) {
        ok(!in_array($theirs, $seen, true),
            "and {$theirs} — another tenant's row, matching the same text — is not among them");
    }

    searchRefusalSays(
        "There is no column 'nickname' to search",
        static fn (): int => $sqlStore->count('bounded_search_probe', ['nickname'], 'rao'),
        'MySQL refuses an unknown search column with the SAME sentence as the file store, checked '
        . 'against the real schema because an identifier cannot be a bound parameter',
    );
    searchRefusalSays(
        'refused rather than dropped from the search',
        static fn (): array => $sqlStore->page('bounded_search_probe', 'name', 25, 0, 'asc',
            ['`id`) OR 1=1 -- '], 'x'),
        'and an injected identifier never reaches the query',
    );
    same(8, (int) $pdo->query('SELECT COUNT(*) FROM bounded_search_probe')->fetchColumn(),
        'and the refused attempts changed nothing');

    // ------------------------------------------------ THE MONEY, BOTH WAYS ROUND
    $sqlWhole = $sqlStore->aggregate('bounded_search_probe', 'ctc');

    same('6000.03', $sqlWhole[0]['sum'],
        'MySQL totals the money column to the SAME PAISA as the PHP loop it replaced — '
        . '₹6,000.03, each row rounded to paise before it is added');
    ok($sqlWhole[0]['sum'] !== '6000.02',
        'and NOT ₹6,000.02, which is SUM() over the raw DECIMAL(18,4) rounded once at the end — '
        . 'the fixture is built so the two differ, or this assertion would prove nothing');
    // aggregate()'s return type guarantees 'sum' is a string, so the old
    // is_string() check here asserted nothing further.
    same($fileStore->aggregate('crew', 'ctc'), $sqlWhole,
        'and the whole bucket — count and sum together — is IDENTICAL to the file store\'s. Two '
        . 'answers to one question is how a figure stops being trusted.');

    same($sorted($fileStore->aggregate('crew', 'ctc', [], 'dept')),
        $sorted($sqlStore->aggregate('bounded_search_probe', 'ctc', [], 'dept')),
        'the identical GROUPED buckets too, ungrouped rows in their own bucket on both');
    same($fileStore->aggregate('crew', 'ctc', ['dept' => ['Finance', 'Sales']]),
        $sqlStore->aggregate('bounded_search_probe', 'ctc', ['dept' => ['Finance', 'Sales']]),
        'and the identical answer under an IN filter');

    same([['key' => '', 'count' => 3, 'sum' => '3000.01']],
        $sqlStore->aggregate('bounded_search_probe', 'ctc', ['dept' => ['Sales']]),
        'non-vacuity: a narrower filter really is a different figure');

    // Tenancy, in the aggregate. Tenant 2 holds ₹15,555.54 that must not be in it.
    same('15555.54', $sql->forTenant('2')->aggregate('bounded_search_probe', 'ctc')[0]['sum'],
        "the other tenant totals its own rows");
    ok($sqlWhole[0]['sum'] !== '21555.57',
        "and this tenant's total does not include them — an aggregate is the easiest place in an "
        . 'HR system to leak a number, because nobody thinks of a total as data');

    searchRefusalSays(
        'not an exact numeric column',
        static fn (): array => $sqlStore->aggregate('bounded_search_probe', 'name'),
        'summing a VARCHAR is REFUSED rather than coerced — SUM() over text reads "n/a" as zero '
        . 'and reports a total wrong by exactly the rows it could not read',
    );
    searchRefusalSays(
        "There is no column 'divison' to search",
        static fn (): array => $sqlStore->aggregate('bounded_search_probe', 'ctc', [], 'divison'),
        'and a grouping column the table has not got is refused by name',
    );

    // ------------------------------------------------------- a report, both stores
    $reportFile = sys_get_temp_dir() . '/prs3-search-reports-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($reportFile, json_encode(['reports' => [
        ['key' => 'ctc-by-dept', 'group' => 'Cost', 'label' => 'CTC by department',
            'question' => 'What does each department cost?', 'table' => '%TABLE%',
            'group_by' => 'dept', 'measure' => 'sum', 'column' => 'ctc', 'money' => true],
        ['key' => 'heads-by-dept', 'group' => 'People', 'label' => 'Heads by department',
            'question' => 'Where are they?', 'table' => '%TABLE%', 'group_by' => 'dept',
            'measure' => 'count', 'where' => ['dept' => ['Finance', 'Sales']]],
        ['key' => 'average-ctc', 'group' => 'Cost', 'label' => 'Average CTC by department',
            'question' => 'Are they paid the same?', 'table' => '%TABLE%', 'group_by' => 'dept',
            'measure' => 'average', 'column' => 'ctc', 'money' => true],
    ]]));
    $shipped = (string) file_get_contents($reportFile);

    file_put_contents($reportFile, str_replace('%TABLE%', 'crew', $shipped));
    $fileReports = new Reports($fileStore, $reportFile . '');
    $fileRuns = [];

    foreach (['ctc-by-dept', 'heads-by-dept', 'average-ctc'] as $key) {
        $fileRuns[$key] = $fileReports->run((array) $fileReports->find($key));
    }

    $sqlReportFile = $reportFile . '.sql.json';
    file_put_contents($sqlReportFile, str_replace('%TABLE%', 'bounded_search_probe', $shipped));
    $sqlReports = new Reports($sqlStore, $sqlReportFile);

    same('6000.03', $fileRuns['ctc-by-dept']['total'],
        'the report total is the same exact rupee figure as the tile above the list — one '
        . 'question, one answer');
    same(6, $fileRuns['ctc-by-dept']['counted'],
        'and it says how many rows it counted, which is the first question anyone asks of a total');
    same(1, $fileRuns['ctc-by-dept']['ungrouped'],
        'the row with no department is counted separately, never dropped');

    foreach (['ctc-by-dept', 'heads-by-dept', 'average-ctc'] as $key) {
        same($fileRuns[$key], $sqlReports->run((array) $sqlReports->find($key)),
            "the report '{$key}' comes out IDENTICAL from both stores — rows, total, counted and "
            . 'ungrouped, compared as one value against the other');
    }

    // Non-vacuity on the average: it is a real division, not a copied sum.
    $average = $fileRuns['average-ctc']['rows'];
    same('1500.01', $average[0]['value'],
        'an average divides the exact sum by the row count with a declared rounding mode — '
        . '₹3,000.02 over two people is ₹1,500.01');

    // The report is tenant-scoped because the store is, and nothing about a
    // declaration file changed that.
    $theirs = new Reports($sql->forTenant('2'), $sqlReportFile);
    same('15555.54', $theirs->run((array) $theirs->find('ctc-by-dept'))['total'],
        "the other tenant's report totals its own rows and only its own");

    unlink($reportFile);
    unlink($sqlReportFile);
} finally {
    $pdo->exec('DROP TABLE IF EXISTS bounded_search_probe');
}
