<?php

declare(strict_types=1);

/**
 * THE TOOL THAT SAYS "NOTHING WAS KEPT" HAS TO BE MADE TO PROVE IT.
 *
 * `tools/modules-writable.php` attempts an insert and an edit through all
 * eighty-four modules to find out which of them a customer could actually use. It
 * prints, at the end of every run, "Nothing was kept: every attempt ran in a
 * transaction and was rolled back."
 *
 * That sentence was false for a while and nobody could have known. The edit pass
 * sat AFTER the `finally` that rolled the insert back, so it ran with no
 * transaction open and committed — the tool wrote a row into seventy-seven tables
 * every time it ran. It was found by counting rows around a run rather than by
 * reading the code, and the reading is the part that had already been done twice.
 *
 * The residue then did something worse than take up space: three modules that had
 * refused on an earlier run started passing, because the rows the tool had left
 * behind gave their empty lookups something to point at. A tool for finding out
 * whether the product works had begun changing the answer.
 *
 * So: a promise a tool makes ABOUT ITSELF is exactly the promise nobody checks.
 * This is that check. It counts every table the module registry names, runs the
 * tool, and counts again — and it does not care how the tool is implemented, which
 * is the point. Any future change that leaks a row fails here whatever it looks
 * like on the page.
 */

use SmartPRS\Surface\Database;

group('modules-writable keeps nothing');

$root = dirname(__DIR__);
$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    // Reported, never silent, and in the same words the rest of the suite uses so
    // the runner's skip line reads the same. On the file store the tool under test
    // refuses to run at all, so there would be nothing to count and a pass here
    // would mean only that a program which did nothing changed nothing.
    echo "  ! skipped: no database (" . Database::why() . ")\n";

    return;
}

$registry = json_decode((string) file_get_contents($root . '/config/modules.json'), true);
$tables = array_values(array_unique(array_map(
    static fn (array $m): string => (string) $m['table'],
    (array) ($registry['modules'] ?? []),
)));

$countAll = static function () use ($pdo, $tables): array {
    $counts = [];

    foreach ($tables as $table) {
        try {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (\Throwable) {
            // A table the registry names and the schema has not got is a different
            // defect, reported by the tool itself. Not counted here rather than
            // counted as zero, which would look like a table that emptied.
            continue;
        }
    }

    return $counts;
};

$before = $countAll();

ok($before !== [], 'There are module tables to count. An empty count would make every assertion '
    . 'below pass over nothing, which is the shape of vacuous check this whole file exists to '
    . 'catch in another tool.');

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/modules-writable.php')
    . ' 2>&1', $output, $code);

$after = $countAll();
$moved = [];

foreach ($before as $table => $was) {
    if (($after[$table] ?? $was) !== $was) {
        $moved[] = sprintf('%s %d → %d', $table, $was, $after[$table] ?? $was);
    }
}

same([], $moved,
    'Every module table holds exactly the rows it held before the tool ran. The tool prints '
    . '"nothing was kept" on every run; this is the only thing that makes that sentence true '
    . 'rather than merely printed. When it was false, the rows it left behind gave empty lookups '
    . 'something to point at and three modules started reporting themselves writable when they '
    . 'were not — a tool for measuring the product was changing what it measured.');

// NON-VACUITY. If the tool refused to run — no tenant, no seeded administrator —
// it would write nothing and the assertion above would pass having proved only
// that a program which did nothing changed nothing.
ok($code === 0 || $code === 1,
    'The tool actually ran: it exited 0 (all modules fine) or 1 (findings), not 2, which is this '
    . 'codebase\'s "I refused to run". A refusal writes nothing and would make the count above '
    . 'agree for the wrong reason.');

ok(str_contains(implode("\n", $output), 'module(s):'),
    'and it got as far as printing its verdict, so the insert and edit attempts really happened.');
