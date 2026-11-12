<?php

declare(strict_types=1);

/**
 * The SmartPRS 3 platform test suite.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php money      run only tests whose filename contains "money"
 *
 * Plain PHP, no framework: PHPUnit and Pest need Composer, and packagist is
 * unreachable in this environment (see README). The target machine keeps this
 * runner for the primitives and adds Pest once `composer install` has run.
 */

require_once __DIR__ . '/lib/bootstrap.php';

$filter = $argv[1] ?? null;

$files = glob(__DIR__ . '/*_test.php');
sort($files);

if ($filter !== null) {
    $files = array_values(array_filter(
        $files,
        static fn (string $f): bool => str_contains(basename($f), $filter),
    ));
}

$start = hrtime(true);

foreach ($files as $file) {
    $before = Assertions::$passed;
    $failuresBefore = count(Assertions::$failures);

    echo basename($file, '_test.php') . "\n";

    try {
        (static function (string $file): void {
            require $file;
        })($file);
    } catch (Throwable $e) {
        // A test file that blows up is a failure, not a dead run: the remaining
        // files still tell you what else is broken.
        Assertions::$failures[] = basename($file) . ' threw ' . $e::class . ': ' . $e->getMessage();
    }

    $passed = Assertions::$passed - $before;
    $failed = count(Assertions::$failures) - $failuresBefore;

    printf("  %d passed%s\n\n", $passed, $failed > 0 ? ", {$failed} FAILED" : '');
}

$elapsed = (hrtime(true) - $start) / 1_000_000;

foreach (Assertions::$failures as $failure) {
    echo "FAIL  {$failure}\n";
}

printf(
    "\n%d assertions, %d failures, %d files, %.1f ms\n",
    Assertions::$passed + count(Assertions::$failures),
    count(Assertions::$failures),
    count($files),
    $elapsed,
);

exit(Assertions::$failures === [] ? 0 : 1);
