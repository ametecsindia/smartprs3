<?php

declare(strict_types=1);

/**
 * The engine conformance suites, run as part of the ordinary suite.
 *
 * Part XXXII makes a passing conformance suite Phase 2's exit gate, and
 * R-XXX-020 says "a change to an engine ships only if the conformance suite
 * passes unchanged". A suite that only runs when somebody remembers to run it
 * does not do that, so it runs here too.
 *
 * The COVERAGE number is reported by the runner and deliberately not asserted:
 * it moves up as cases are written, and a test that failed on 57% would be a
 * test that punishes honesty about the gap.
 */

group('Engine conformance (Part XXX §XXX.3)');

$output = [];
$exit = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/conformance/run.php') . ' 2>&1',
    $output,
    $exit,
);

$rendered = implode("\n", $output);

ok($exit === 0, 'every covered conformance case passes' . ($exit === 0 ? '' : "\n" . $rendered));

// The runner's own reporting is the thing that makes the suite worth having, so
// it is asserted rather than assumed: a runner that stopped printing NOT
// COVERED would turn a 57% suite into a green one overnight.
ok(
    str_contains($rendered, 'case class(es) covered'),
    'the runner reports coverage against the blueprint\'s full case-class list, not just what it ran',
);

$manifest = require __DIR__ . '/conformance/manifest.php';
$declared = array_sum(array_map('count', $manifest));

same(61, $declared, 'the manifest carries every case class of §XXX.3.1 to §XXX.3.4');

foreach (['spx', 'rules', 'formula', 'workflow'] as $engine) {
    $path = __DIR__ . '/conformance/' . $engine . '.json';

    ok(is_file($path), "the {$engine} corpus is a data file, per R-XXX-021");

    $corpus = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $classes = array_column($corpus['cases'], 'class');
    $known = array_merge(...array_map('array_keys', array_values($manifest)));

    same(
        [],
        array_values(array_diff($classes, $known)),
        "every {$engine} case names a case class the blueprint declares — a case filed under an "
        . 'invented class would be counted as coverage of nothing',
    );
}
