<?php
/**
 * THE PHASE 1 EXIT GATE — Part XXXII, Platform foundation.
 *
 *   php tools/phase1/exit-gate.php
 *
 * Five conditions:
 *
 *   1. Every gate in Part IV §IV.10 is BLOCKING in CI.
 *   2. A generated cross-tenant isolation test exists for every aggregate built
 *      so far, and passes.
 *   3. An as-at query answers "what was this org assignment on date D?".
 *   4. The audit chain verifies.
 *   5. `migrate:fresh` matches the deployed structure exactly.
 *
 * Part XXXII's own warning about this phase: *"This is the phase where cutting
 * corners recreates SmartPRS 2. There is no later opportunity to make tenancy
 * structural."* Which is why this gate exists now, late, rather than not at all:
 * the foundation is the thing nothing re-checks once the phases above it start
 * passing, and a foundation nobody re-checks is one that quietly stops holding.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — Phase 1 exit gate (Part XXXII, Platform foundation)\n";

// =============================================================================
// 1. EVERY §IV.10 GATE IS BLOCKING
// =============================================================================

$problems = [];
$output = [];
$status = 0;
exec('php ' . escapeshellarg($root . '/tools/gates/gate.php') . ' 2>&1', $output, $status);
$text = implode("\n", $output);

if ($status !== 0) {
    $problems[] = 'the gate runner exits non-zero, so the toolchain is not currently clean';
}

// BLOCKING means the runner exits non-zero on a violation — not that it prints
// one. A check that reports and returns 0 is advisory, and advisory is how a
// gate becomes a thing people read past.
preg_match('/(\d+) check\(s\)/', $text, $m);
$checkCount = (int) ($m[1] ?? 0);

$expected = glob($root . '/tools/gates/checks/*.php') ?: [];

if ($checkCount < count($expected)) {
    $problems[] = "the runner ran {$checkCount} check(s) and " . count($expected)
        . ' check file(s) exist. A check that is present on disk and not wired into the runner is a check '
        . 'nobody is running';
}

// Prove BLOCKING rather than assume it: the gate self-test seeds each check with
// a violation and asserts it fails. A gate suite that has never been seen to
// fail is a gate suite nobody knows is wired up.
$selfTest = [];
$selfStatus = 0;
exec('php ' . escapeshellarg($root . '/tools/gates/self-test.php') . ' 2>&1', $selfTest, $selfStatus);

if ($selfStatus !== 0) {
    $problems[] = 'the gate self-test fails, so the gates cannot be shown to catch what they claim to';
}

if (!preg_match('/(\d+) assertion/', implode("\n", $selfTest), $sm) || (int) $sm[1] < count($expected)) {
    $problems[] = 'the self-test makes fewer assertions than there are checks, so at least one check has '
        . 'never been proven to fail on a violation';
}

$results[] = [
    'name' => 'Every gate in Part IV §IV.10 is blocking in CI',
    'ran' => true,
    'problems' => $problems,
    'detail' => $checkCount . ' checks, all wired into a runner that EXITS NON-ZERO on a violation, and a '
        . 'self-test that seeds each one with a deliberate violation and asserts it fails — because a gate '
        . 'suite nobody has watched fail is a gate suite nobody knows is wired up',
];

// =============================================================================
// 2. A GENERATED CROSS-TENANT ISOLATION TEST FOR EVERY AGGREGATE
// =============================================================================

$problems = [];
$tables = [];

foreach (glob($root . '/database/migrations/*.php') ?: [] as $migration) {
    preg_match_all("/create\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($migration), $tm);
    $tables = [...$tables, ...$tm[1]];
}

$isolation = (string) file_get_contents($root . '/tests/tenant_isolation_test.php');
preg_match('/(\d+)/', $isolation, $unused);

// The test must be GENERATED from the table registry rather than hand-listed:
// a hand-kept list is a list that stops including the table added last week,
// and the table added last week is the one nobody has tested for isolation.
if (!str_contains($isolation, 'TableRegistry') && !str_contains($isolation, 'tenantScoped')) {
    $problems[] = 'the cross-tenant suite does not derive its table list from the registry. A hand-kept '
        . 'list stops including the table added last week, and that is the table nobody has tested';
}

$out = [];
$st = 0;
exec('php ' . escapeshellarg($root . '/tests/run.php') . ' 2>&1', $out, $st);
$testText = implode("\n", $out);

if ($st !== 0) {
    $problems[] = 'the test suite does not pass';
}

if (str_contains($testText, 'tenant_isolation') && str_contains($testText, 'skipped: no database')) {
    $problems[] = 'the cross-tenant suite SKIPPED because no database was reachable. A suite that skips is '
        . 'a suite that passes for the wrong reason, and tenancy is the one property that must never be '
        . 'proven by absence';
}

$results[] = [
    'name' => 'A generated cross-tenant isolation test exists for every aggregate and passes',
    'ran' => true,
    'problems' => $problems,
    'detail' => count(array_unique($tables)) . ' tables declared across the migrations; the suite derives '
        . 'its list from the table registry rather than a hand-kept one, and it must RUN rather than skip — '
        . 'tenancy is the property that must never be proven by absence',
];

// =============================================================================
// 3 AND 4. THE AS-AT QUERY, AND THE AUDIT CHAIN
// =============================================================================
//
// Both are proven by RE-RUNNING the suites that already cover them rather than
// by rebuilding their logic here. A gate that re-implements what it checks is a
// second implementation of the thing, and this build has spent nine phases
// refusing exactly that. What the gate adds is the part a test file cannot say
// about itself: that it RAN, rather than skipping because no database was
// reachable.

/** @return array{ran: bool, ok: bool, detail: string} */
function phase1Suite(string $root, string $file): array
{
    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($root . '/tests/run.php') . ' ' . escapeshellarg($file) . ' 2>&1',
        $output, $status);
    $text = implode("\n", $output);

    if (str_contains($text, 'skipped')) {
        return ['ran' => false, 'ok' => false, 'detail' => trim($text)];
    }

    preg_match('/(\d+) assertions?, (\d+) failures?/', $text, $m);

    return [
        'ran' => true,
        'ok' => $status === 0 && (int) ($m[2] ?? 1) === 0,
        'detail' => ($m[1] ?? '0') . ' assertion(s), ' . ($m[2] ?? '?') . ' failure(s)',
    ];
}

$asAt = phase1Suite($root, 'organisation');

$results[] = [
    'name' => 'An as-at query answers "what was this org assignment on date D?"',
    'ran' => $asAt['ran'],
    'problems' => $asAt['ran']
        ? ($asAt['ok'] ? [] : ['the organisation suite fails: ' . $asAt['detail']])
        : ['the organisation suite SKIPPED — no database was reachable, so the as-at query has not been '
            . 'exercised. A history that cannot be queried as at a past date cannot reconstruct a payslip, '
            . 'a register or an approval route as it stood then, and skipping hides that entirely.'],
    'detail' => $asAt['detail'] . ' — the assignment in force on a date, NULL before the first one and '
        . 'inside a gap (an answer, not a missing row), and the boundary right to the day',
];

$chain = phase1Suite($root, 'audit_ledger');

$results[] = [
    'name' => 'The audit chain verifies',
    'ran' => $chain['ran'],
    'problems' => $chain['ran']
        ? ($chain['ok'] ? [] : ['the audit ledger suite fails: ' . $chain['detail']])
        : ['the audit ledger suite SKIPPED — no database was reachable. An audit chain nobody verified is '
            . 'a column of hashes.'],
    'detail' => $chain['detail'] . ' — an intact chain verifies and a tampered one does not, which is the '
        . 'half that makes the first mean anything',
];

// =============================================================================
// 5. `migrate:fresh` MATCHES THE DEPLOYED STRUCTURE EXACTLY
// =============================================================================

$schema = phase1Suite($root, 'schema');

$results[] = [
    'name' => 'migrate:fresh matches the deployed structure exactly',
    'ran' => $schema['ran'],
    'problems' => $schema['ran']
        ? ($schema['ok'] ? [] : ['the schema comparison fails: the structure built by the migrations does '
            . 'not match what is deployed — ' . $schema['detail']])
        : ['the schema comparison SKIPPED — no database was reachable. A structural comparison that skips '
            . 'is not a comparison, and schema drift stays invisible until the day a migration fails on '
            . 'one install and not another.'],
    'detail' => $schema['detail'] . ' — the migrations rebuild a structure identical to the deployed one, '
        . 'so no install is carrying a column somebody added by hand',
];

// =============================================================================

$failed = 0;
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);
    } elseif ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);
    } else {
        printf("\n  %d. \033[32mPASS      \033[0m  %s\n        %s\n", $index + 1, $result['name'],
            $result['detail']);

        continue;
    }

    foreach ($result['problems'] as $problem) {
        echo "        └─ {$problem}\n";
    }
}

printf("\n%s  %d of %d Phase 1 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun, count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN");

exit($failed === 0 && $notRun === 0 ? 0 : 1);
