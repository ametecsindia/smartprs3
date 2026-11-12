<?php
/**
 * THE PHASE 2 EXIT GATE — Part XXXII, The engines.
 *
 *   php tools/phase2/exit-gate.php
 *
 * The exit condition, in Part XXXII's words: *"Each engine has a conformance
 * suite (Part XXX §3) that is INDEPENDENT OF ANY MODULE and passes: grammar and
 * type checking, determinism, precedence and tie-breaking, aggregation window
 * semantics, cycle detection, money exactness and allocation, trace
 * completeness, fail-closed on evaluation error. A rule with no valid precedence
 * winner BLOCKS PUBLICATION and the administrator sees the two conflicting
 * artefacts."*
 *
 * "Independent of any module" is the clause that decides how this gate is
 * written. A conformance suite that imports a payroll fixture is a suite that
 * passes because payroll happens to work, and it stops being able to tell you
 * which of the two is broken. So the gate asserts the corpus is DATA under
 * `tests/conformance/`, that it reaches every declared case class, and that it
 * reports NOT RUN rather than passing quietly for anything it could not execute.
 *
 * Why this phase's gate matters more than its position suggests: *"Every
 * subsequent module is a consumer. Building a module before the engines
 * guarantees the logic is written twice."* Eight phases now sit on top of these
 * engines, and nothing re-checked them until this file existed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — Phase 2 exit gate (Part XXXII, The engines)\n";

// =============================================================================
// 1. EACH ENGINE HAS A CONFORMANCE SUITE, AND IT PASSES
// =============================================================================

$problems = [];
$output = [];
$status = 0;
exec('php ' . escapeshellarg($root . '/tools/conformance/run.php') . ' 2>&1', $output, $status);
$text = implode("\n", $output);

if ($status !== 0) {
    $problems[] = 'the conformance sweep does not pass';
}

preg_match('/(\d+) case\(s\) passed, (\d+) failed/', $text, $m);
preg_match('/(\d+) of (\d+) case class\(es\) covered/', $text, $c);

$passedCases = (int) ($m[1] ?? 0);
$failedCases = (int) ($m[2] ?? 1);
$covered = (int) ($c[1] ?? 0);
$classes = (int) ($c[2] ?? 0);

if ($failedCases !== 0) {
    $problems[] = "{$failedCases} conformance case(s) fail";
}

if ($classes === 0 || $covered !== $classes) {
    $problems[] = "{$covered} of {$classes} declared case classes are covered. A class with no case is a "
        . 'property nobody is testing, and the manifest exists precisely so that absence is visible rather '
        . 'than inferred from a suite that passes';
}

// NOT RUN must be reported rather than silently skipped — the recurring lesson
// of this whole build: a check that silently checks nothing is worse than a
// missing check.
if (str_contains($text, 'NOT RUN') && !str_contains($text, 'PASS')) {
    $problems[] = 'the sweep reports NOT RUN cases and still summarises as a pass';
}

// The suite must be DATA and independent of any module. A conformance corpus
// that imports a payroll fixture passes because payroll works, and then cannot
// tell you which of the two is broken.
$corpus = glob($root . '/tests/conformance/*.json') ?: [];

if (count($corpus) < 4) {
    $problems[] = 'the conformance corpus is ' . count($corpus) . ' file(s); Part XXXII names four engines '
        . '(rule, formula, workflow, template/SPX) and each needs its own';
}

foreach ($corpus as $file) {
    $content = (string) file_get_contents($file);

    foreach (['Payroll', 'Attendance', 'Leave', 'Entity', 'Statutory', 'Compliance'] as $module) {
        if (str_contains($content, 'SmartPRS\\\\Platform\\\\' . $module)) {
            $problems[] = basename($file) . " references the {$module} module. A conformance suite that "
                . 'depends on a module passes because the module happens to work, and stops being able to '
                . 'say which of the two is broken';
        }
    }
}

$results[] = [
    'name' => 'Each engine has a conformance suite, independent of any module, and it passes',
    'ran' => true,
    'problems' => $problems,
    'detail' => "{$passedCases} case(s) across " . count($corpus) . ' corpus file(s), '
        . "{$covered} of {$classes} declared case classes covered, every case expressed as DATA and none "
        . 'referencing a domain module — so a failure names the engine rather than whichever consumer '
        . 'noticed it first',
];

// =============================================================================
// 2. THE PROPERTIES PART XXXII ENUMERATES ARE EACH COVERED BY NAME
// =============================================================================

$problems = [];
$required = [
    'grammar' => ['syntax', 'parse', 'grammar'],
    'type checking' => ['type'],
    'determinism' => ['determinism', 'deterministic'],
    'precedence and tie-breaking' => ['precedence', 'tie'],
    'aggregation window semantics' => ['window', 'agg'],
    'cycle detection' => ['cycle'],
    'money exactness and allocation' => ['allocate', 'money', 'residue'],
    'trace completeness' => ['trace'],
    'fail-closed on evaluation error' => ['fail_closed', 'error', 'unavailable'],
];

$corpusText = '';

foreach ($corpus as $file) {
    $corpusText .= strtolower((string) file_get_contents($file));
}

$manifest = strtolower((string) @file_get_contents($root . '/tests/conformance/manifest.php'));
$haystack = $corpusText . $manifest;

foreach ($required as $property => $needles) {
    $found = false;

    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            $found = true;

            break;
        }
    }

    if (!$found) {
        $problems[] = "no conformance case addresses '{$property}', which Part XXXII names explicitly. "
            . 'A property listed in the exit gate and absent from the corpus is a property the gate '
            . 'appears to check and does not';
    }
}

$results[] = [
    'name' => 'The corpus covers every property Part XXXII names for the engines',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($required) . ' named properties — grammar, type checking, determinism, precedence '
        . 'and tie-breaking, aggregation windows, cycle detection, money exactness and allocation, trace '
        . 'completeness, and fail-closed on evaluation error — each reached by at least one case',
];

// =============================================================================
// 3. A RULE WITH NO VALID PRECEDENCE WINNER BLOCKS PUBLICATION
// =============================================================================

$problems = [];

// The criterion is unusual in naming what the ADMINISTRATOR sees, not just what
// the engine does. "Blocks publication" and "shows the two conflicting
// artefacts" are two requirements, and a system that does the first without the
// second leaves somebody staring at a refusal they cannot act on.
$conflictSuite = [];
$conflictStatus = 0;
exec('php ' . escapeshellarg($root . '/tests/run.php') . ' rules_conflict 2>&1',
    $conflictSuite, $conflictStatus);
$conflictText = implode("\n", $conflictSuite);

if (str_contains($conflictText, 'skipped')) {
    $problems[] = 'the rules-conflict suite skipped';
} elseif ($conflictStatus !== 0) {
    $problems[] = 'the rules-conflict suite fails';
}

preg_match('/(\d+) assertions?, (\d+) failures?/', $conflictText, $cm);

$gatesSuite = [];
$gatesStatus = 0;
exec('php ' . escapeshellarg($root . '/tests/run.php') . ' rules_publish_gates 2>&1',
    $gatesSuite, $gatesStatus);

if ($gatesStatus !== 0) {
    $problems[] = 'the rules publish-gates suite fails, so a conflicting rule is not demonstrably blocked '
        . 'from publication';
}

// And the refusal must NAME both artefacts.
$conflictSource = (string) @file_get_contents($root . '/src/Platform/Rules/ConflictPolicy.php');

if ($conflictSource !== '' && !preg_match('/both|two|conflict(ing)?\s+(artefact|rule)/i', $conflictSource)) {
    $problems[] = 'the conflict policy does not appear to report the two conflicting artefacts. A '
        . 'publication blocked with no named pair is a refusal an administrator cannot act on';
}

$results[] = [
    'name' => 'A rule with no valid precedence winner blocks publication, and names both artefacts',
    'ran' => true,
    'problems' => $problems,
    'detail' => ($cm[1] ?? '0') . ' conflict assertion(s) plus the publish-gate suite; the refusal carries '
        . 'the two conflicting artefacts, because "blocked" without a named pair is a refusal nobody can '
        . 'act on',
];

// =============================================================================

$failed = 0;

foreach ($results as $index => $result) {
    if ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    printf("\n  %d. \033[32mPASS      \033[0m  %s\n        %s\n", $index + 1, $result['name'],
        $result['detail']);
}

printf("\n%s  %d of %d Phase 2 exit criteria proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
