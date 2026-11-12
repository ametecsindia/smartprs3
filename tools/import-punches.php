<?php

declare(strict_types=1);

/**
 * IMPORT A BIOMETRIC DEVICE'S PUNCH EXPORT.
 *
 *   php tools/import-punches.php --layout=config/punch-layouts.json --layout-name=zkteco_dat \
 *       storage/punches/2026-09-01.dat [more files…]
 *   php tools/import-punches.php --layout=… --dry-run --tenant=3 file.dat
 *
 * Every punch in this product used to be typed by a human. This is the other
 * way in, and it is deliberately the LAZY rung: no vendor SDK, no TCP protocol,
 * no push listener — the delimited log file every device in this market already
 * exports. See surface/PunchImport.php for what that buys and what it does not.
 *
 * IT REFUSES RATHER THAN HALF-WORKS. No database, no layout file, an unreadable
 * export, a file that parsed to zero punches — each is a refusal with the reason
 * on it. A file that produced nothing is a FINDING, not a success: the usual
 * cause is the wrong layout for the device, and an importer that prints "done,
 * 0 imported" is how somebody discovers in March that attendance stopped in
 * January.
 *
 * WHAT IT COULD NOT DO IS PRINTED, NOT SWALLOWED. Unmapped enrolment numbers
 * with their punch counts, unparsable lines with their line numbers, punches
 * already present from an earlier run. An unmapped punch is somebody who worked
 * and will not be paid for it.
 *
 * ponytail: no --since, no directory watch, no cron. Somebody runs it after
 * pulling the day's file off the device. When that becomes a chore, the loop
 * over $argv below becomes a glob and this file gains a scheduled caller —
 * nothing about the import changes.
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Employment.php';
require_once __DIR__ . '/../surface/PunchImport.php';
require_once __DIR__ . '/../src/Platform/Attendance/Punch.php';

use SmartPRS\Surface\Database;
use SmartPRS\Surface\PunchImport;
use SmartPRS\Surface\SqlStore;

$root = dirname(__DIR__);

$options = ['layout' => $root . '/config/punch-layouts.json', 'layout-name' => '',
    'tenant' => '', 'dry-run' => false];
$files = [];

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m)) {
        if (!array_key_exists($m[1], $options)) {
            fwrite(STDERR, "Unknown option {$argument}.\n");

            exit(2);
        }

        $options[$m[1]] = $m[2] ?? true;

        continue;
    }

    $files[] = $argument;
}

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

if ($files === []) {
    $refuse('no punch export was named.',
        'Usage:',
        '    php tools/import-punches.php --layout=config/punch-layouts.json \\',
        '        --layout-name=zkteco_dat storage/punches/2026-09-01.dat');
}

// ------------------------------------------------------------------ the layout
// Read BEFORE the database is opened: a wrong layout is the likeliest mistake
// here, and finding it out after a connection is a slower way to learn the same
// thing.
try {
    $layout = PunchImport::layout(
        PunchImport::layoutsFrom((string) $options['layout']),
        (string) $options['layout-name'],
    );
} catch (Throwable $e) {
    $refuse($e->getMessage(),
        'A layout declares, per device vendor: the delimiter, whether there is a header row, which',
        'column holds the enrolment number, which holds the instant and in what format, and what',
        'each direction code means. It is DATA and lives in config/punch-layouts.json, because a',
        'vendor\'s column order in code would make a new device model a release. For example:',
        '',
        '    {',
        '      "zkteco_dat": {',
        '        "delimiter": "whitespace",',
        '        "header": false,',
        '        "columns": {"device_user_id": 0, "punch_at": 1, "direction": 3, "verify_mode": 4},',
        '        "timestamp_format": "Y-m-d H:i:s",',
        '        "directions": {"0": "in", "1": "out"}',
        '      }',
        '    }');
}

// ---------------------------------------------------------------- the database
$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    $refuse('there is no database to import punches into.', Database::why(), '',
        'Punches are attendance, and attendance is payroll. This tool will not write them anywhere',
        'else. Set DB_USERNAME and DB_PASSWORD in .env, then: php tools/db/migrate.php');
}

$tenant = (string) ($options['tenant'] ?: (string) $pdo->query(
    'SELECT id FROM tenants ORDER BY id LIMIT 1')->fetchColumn());

if ($tenant === '') {
    $refuse('there is no tenant to import into.',
        'Every row here belongs to a tenant — structural, not a filter. Create one first:',
        '    php tools/seed-local-admin.php');
}

$store = (new SqlStore($pdo))->forTenant($tenant);
$import = new PunchImport($store);

$directory = $import->directory((string) ($layout['identifies'] ?? 'device_user_id'));

printf("layout %s  tenant %s  %d enrolment number(s) mapped to an employment%s\n\n",
    $layout['name'], $tenant, count($directory['codes']),
    $options['dry-run'] ? "\nDRY RUN — nothing will be written." : '');

if ($directory['codes'] === []) {
    // Not a refusal — the run continues and reports every punch as unmapped,
    // which is exactly the report somebody needs to go and fill the column in.
    echo "  ! NO employment has a device user id recorded. Every punch below will be reported as\n"
        . "    unmapped and NOTHING will be imported. `employees.device_user_id` is the column that\n"
        . "    says which enrolment number is which person; until it is filled, a device file is a\n"
        . "    list of numbers.\n\n";
}

// -------------------------------------------------------------------- the run
$totals = ['parsed' => 0, 'imported' => 0, 'already_present' => 0];
$findings = 0;
$refusedFiles = 0;

foreach ($files as $file) {
    $path = $file[0] === '/' ? $file : getcwd() . '/' . $file;

    if (!is_file($path) || !is_readable($path)) {
        printf("%s\n  REFUSED — not a readable file. Named rather than skipped: a typo in a filename\n"
            . "  is otherwise indistinguishable from a device that exported nothing.\n\n", $file);
        $refusedFiles++;
        $findings++;

        continue;
    }

    $report = $import->import((string) file_get_contents($path), $layout,
        (bool) $options['dry-run']);

    printf("%s\n", $file);

    if ($report['parsed'] === 0) {
        printf("  REFUSED — %d line(s) read and NOT ONE parsed as a punch. That is a finding, not an\n"
            . "  empty file: the usual cause is this layout belonging to a different device. Nothing\n"
            . "  was written.\n", count($report['unparsable']));

        foreach (array_slice($report['unparsable'], 0, 5) as $bad) {
            printf("    line %-5d %s\n             %s\n", $bad['line'],
                substr(trim($bad['text']), 0, 90), $bad['why']);
        }

        if (count($report['unparsable']) > 5) {
            printf("    … and %d more line(s)\n", count($report['unparsable']) - 5);
        }

        echo "\n";
        $refusedFiles++;
        $findings++;

        continue;
    }

    printf("  %d punch(es) read · %d imported · %d already present\n",
        $report['parsed'], $report['imported'], $report['already_present']);

    foreach ($report['devices'] as $serial => $count) {
        printf("    device %-24s %6d punch(es)\n", $serial, $count);
    }

    if ($report['unmapped'] !== []) {
        echo "\n  NOT IMPORTED — device user id maps to no employment. These punches are people who\n"
            . "  worked. They were NOT attached to a plausible-looking employment and NOT dropped:\n"
            . "  record the number in `employees.device_user_id` and run this file again — the\n"
            . "  punches that did import will not double.\n";

        foreach ($report['unmapped'] as $deviceUserId => $count) {
            printf("    device user id %-12s %6d punch(es)\n", $deviceUserId, $count);
            $findings++;
        }
    }

    if ($report['ambiguous'] !== []) {
        echo "\n  NOT IMPORTED — device user id is on MORE THAN ONE employment, so there is no answer\n"
            . "  to who punched. Refused rather than resolved by taking the first: two people share a\n"
            . "  number when somebody was enrolled onto a leaver's slot, and either choice pays the\n"
            . "  wrong person for a month.\n";

        foreach ($report['ambiguous'] as $deviceUserId => $count) {
            printf("    device user id %-12s %6d punch(es)\n", $deviceUserId, $count);
            $findings++;
        }
    }

    if ($report['unparsable'] !== []) {
        echo "\n  NOT READ — line by line, so the file can be corrected:\n";

        foreach ($report['unparsable'] as $bad) {
            printf("    line %-5d %s\n             %s\n", $bad['line'],
                substr(trim($bad['text']), 0, 90), $bad['why']);
            $findings++;
        }
    }

    echo "\n";

    foreach (['parsed', 'imported', 'already_present'] as $key) {
        $totals[$key] += $report[$key];
    }
}

printf("%s  %d file(s), %d punch(es) read, %d imported, %d already present%s. %d finding(s).\n",
    $findings === 0 ? 'DONE' : 'DONE WITH FINDINGS',
    count($files), $totals['parsed'], $totals['imported'], $totals['already_present'],
    $options['dry-run'] ? ' (dry run — nothing written)' : '', $findings);

if ($totals['already_present'] > 0) {
    echo "\nAlready present means seen before, not skipped by accident: every punch carries an\n"
        . "external_id built from the device, the person and the instant, so re-importing a file\n"
        . "cannot double anybody's attendance.\n";
}

exit($findings === 0 && $refusedFiles === 0 ? 0 : 1);
