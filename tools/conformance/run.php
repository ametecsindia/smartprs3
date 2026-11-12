<?php
/**
 * THE ENGINE CONFORMANCE SUITES — Part XXX §XXX.3.
 *
 *   php tools/conformance/run.php [SPX|RULES|FORMULA|WORKFLOW]
 *
 * Phase 2's exit gate (Part XXXII) is that each engine carries a conformance
 * suite that passes. This runs it, and — the part that makes it worth having —
 * reports every case class in the blueprint's table, including the ones no case
 * covers yet, as NOT COVERED.
 *
 * That is the whole design. A conformance suite reporting "42 passed" tells you
 * nothing about the 61 properties the blueprint requires; a suite reporting
 * "31 of 61 covered, 30 not" tells you exactly where the engine is unproven.
 * The same discipline as `PublishCheck::couldNotCheck()` and the gates' vacuity
 * suppression: a check with nothing to read must say so rather than read green.
 *
 * R-XXX-021: the corpus is data (tests/conformance/*.json), not PHP methods, so
 * it stays runnable against a future re-implementation of an engine. This file
 * is the adapter from that data to THIS implementation, and it is the only part
 * a re-implementation would replace.
 *
 * Exit 0 = every covered case passed. Coverage is reported, never enforced
 * here: it moves up as cases are written, and hiding the gap would defeat it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';
require_once __DIR__ . '/engines.php';

$only = strtoupper((string) ($_SERVER['argv'][1] ?? ''));
$manifest = require __DIR__ . '/../../tests/conformance/manifest.php';

$engines = [
    'SPX' => 'spx.json',
    'RULES' => 'rules.json',
    'FORMULA' => 'formula.json',
    'WORKFLOW' => 'workflow.json',
];

echo "SmartPRS 3 engine conformance — Part XXX §XXX.3\n";

$totalPass = 0;
$totalFail = 0;
$totalCovered = 0;
$totalClasses = 0;
$failures = [];
$notRun = [];

foreach ($engines as $engine => $file) {
    if ($only !== '' && $only !== $engine) {
        continue;
    }

    $classes = $manifest[$engine] ?? [];
    $totalClasses += count($classes);
    $path = __DIR__ . '/../../tests/conformance/' . $file;
    $cases = is_file($path)
        ? (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['cases'] ?? [])
        : [];

    /** @var array<string, list<array{id: string, passed: bool, detail: string}>> */
    $byClass = [];

    foreach ($cases as $case) {
        $outcome = conformance_run_case($engine, $case);
        $byClass[$case['class']][] = $outcome;

        if (($outcome['ran'] ?? true) === false) {
            // Neither a pass nor a failure. The class stays uncovered below,
            // which is the point: a case that did not execute proved nothing.
            $notRun[] = "{$engine} {$outcome['id']}: {$outcome['detail']}";

            continue;
        }

        if ($outcome['passed']) {
            $totalPass++;
        } else {
            $totalFail++;
            $failures[] = "{$engine} {$outcome['id']}: {$outcome['detail']}";
        }
    }

    printf("\n\033[1m%s\033[0m  %d case(s) over %d of %d class(es)\n",
        $engine, count($cases), count($byClass), count($classes));

    foreach ($classes as $class => $mustAssert) {
        $results = $byClass[$class] ?? [];
        $executed = array_values(array_filter($results, static fn (array $r): bool => ($r['ran'] ?? true) !== false));

        if ($executed === []) {
            printf(
                "  %-8s \033[33m%s\033[0m  %s\n",
                $class,
                $results === [] ? 'NOT COVERED' : 'NOT RUN    ',
                conformance_trim($mustAssert),
            );

            continue;
        }

        $results = $executed;
        $totalCovered++;
        $failed = array_values(array_filter($results, static fn (array $r): bool => !$r['passed']));

        printf(
            "  %-8s %s  %d case(s)  %s\n",
            $class,
            $failed === [] ? "\033[32mPASS       \033[0m" : "\033[31mFAIL       \033[0m",
            count($results),
            conformance_trim($mustAssert),
        );

        foreach ($failed as $failure) {
            printf("           └─ %s: %s\n", $failure['id'], $failure['detail']);
        }
    }
}

echo "\n";

foreach ($failures as $failure) {
    echo "FAIL  {$failure}\n";
}

foreach ($notRun as $line) {
    echo "NOT RUN  {$line}\n";
}

printf(
    "%s  %d case(s) passed, %d failed · %d of %d case class(es) covered (%d%%)\n",
    $totalFail === 0 ? 'PASS' : 'FAIL',
    $totalPass,
    $totalFail,
    $totalCovered,
    $totalClasses,
    $totalClasses === 0 ? 0 : (int) round($totalCovered / $totalClasses * 100),
);

if ($totalCovered < $totalClasses) {
    printf(
        "\n%d case class(es) are NOT COVERED. That is the honest state of Phase 2's exit gate, not a\n"
        . "passing suite with a smaller denominator. Each one is a property the blueprint requires and\n"
        . "this corpus does not yet prove.\n",
        $totalClasses - $totalCovered,
    );
}

exit($totalFail === 0 ? 0 : 1);

function conformance_trim(string $text): string
{
    return mb_strlen($text) > 92 ? mb_substr($text, 0, 89) . '…' : $text;
}
