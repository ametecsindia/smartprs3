<?php
/**
 * THE WHOLE PROGRAMME, ON ONE SCREEN.
 *
 *   php tools/programme/status.php
 *
 * Every gate, every sweep and the definition of done, in one command, so that
 * "where is this?" has an answer that is produced rather than remembered.
 *
 * The one editorial decision here: BLOCKED is reported separately from FAILED,
 * and the blockers are named as the DECISIONS they are rather than as work
 * items. Three of them are waiting on a person — a Product Owner approving a
 * blueprint, a compliance owner being appointed, a customer signing off a
 * tenant — and describing those as "outstanding tasks" on an engineering board
 * is how they sit there for a quarter while everyone assumes somebody else is
 * doing them.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$run = static function (string $label, string $command) use ($root): array {
    $output = [];
    $status = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $status);
    $text = implode("\n", $output);
    $last = '';

    foreach (array_reverse($output) as $line) {
        if (preg_match('/^(PASS|FAIL|BLOCKED)/', trim($line)) === 1) {
            $last = trim($line);

            break;
        }
    }

    return ['label' => $label, 'ok' => $status === 0, 'summary' => $last ?: trim((string) end($output))];
};

echo "\n  SmartPRS 3 — programme status\n";
echo '  ' . str_repeat('=', 92) . "\n\n";

echo "  VERIFICATION\n";

foreach ([
    ['the test suite', 'php tests/run.php'],
    ['the enforcement gates', 'php tools/gates/gate.php'],
    ['the gate self-test', 'php tools/gates/self-test.php'],
    ['engine conformance', 'php tools/conformance/run.php'],
    ['the blueprint worked rules', 'php tools/blueprint/compile-worked-rules.php'],
    ['the demo console', 'php tools/demo/smoke-console.php'],
    ['the /api/v3 contract', 'php tools/api/contract-gate.php'],
    ['the OpenAPI gates', 'php tools/api/openapi-gate.php'],
    ['the webhook engine', 'php tools/api/webhook-gate.php'],
] as [$label, $command]) {
    $result = $run($label, $command);
    printf("    %s  %-28s %s\n", $result['ok'] ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m",
        $label, $result['summary']);
}

echo "\n  PHASES\n";
$phases = [];
$blocked = [];

foreach (range(0, 10) as $number) {
    $gate = "{$root}/tools/phase{$number}/exit-gate.php";

    if (!is_file($gate)) {
        continue;
    }

    $result = $run("phase {$number}", 'php ' . escapeshellarg($gate));
    $isBlocked = !$result['ok'] && str_contains($result['summary'], 'NOT PROVEN')
        || str_starts_with($result['summary'], 'BLOCKED');

    if ($isBlocked) {
        $blocked[] = $number;
    }

    $phases[$number] = $result['ok'];

    printf("    %s  phase %-2d  %s\n",
        $result['ok'] ? "\033[32m✓\033[0m" : ($isBlocked ? "\033[33m·\033[0m" : "\033[31m✗\033[0m"),
        $number, mb_strimwidth($result['summary'], 0, 84, '…'));
}

echo "\n  DEFINITION OF DONE (Part XXXII §XXXII.7)\n";
$dod = $run('definition of done', 'php tools/programme/definition-of-done.php');
$dodOutput = [];
exec('cd ' . escapeshellarg($root) . ' && php tools/programme/definition-of-done.php 2>&1', $dodOutput);

foreach ($dodOutput as $line) {
    if (preg_match('/(\d+) of (\d+) done/', $line) === 1) {
        echo '    ' . trim(preg_replace('/\033\[[0-9;]*m/', '', $line) ?? '') . "\n";
    }
}

echo "\n  BLUEPRINT COVERAGE (Part XXXV)\n";
$traceOutput = [];
exec('cd ' . escapeshellarg($root) . ' && php tools/programme/traceability.php 2>&1', $traceOutput);

foreach ($traceOutput as $line) {
    $plain = trim(preg_replace('/\033\[[0-9;]*m/', '', $line) ?? '');

    if (preg_match('/^(TOTAL|.*CHECKED\s+\d|.*CITED\s+\d|UNCITED\s+\d)/', $plain) === 1) {
        echo '    ' . $plain . "\n";
    }
}

echo "\n    What is built is the SPINE and one worked path through each phase — not 3,313\n"
    . "    requirements. Read that number as scope remaining, not as a defect count.\n";

echo "\n  " . str_repeat('=', 92) . "\n";
echo "\n  WHAT IS WAITING ON A PERSON, NOT ON ENGINEERING\n\n";

$decisions = [];

foreach (file($root . '/docs/registers/REGISTER-DECISIONS.md') ?: [] as $line) {
    if (preg_match('/^\|\s*`(D-[A-Za-z0-9-]+)`.*\|\s*1\s*\|/', $line, $m) === 1) {
        $decisions[] = $m[1];
    }
}

$answers = is_file($root . '/config/decisions-answered.json')
    ? json_decode((string) file_get_contents($root . '/config/decisions-answered.json'), true)
    : [];

printf("    1. Product Owner approval of the blueprint, and %d Tier 1 decision(s) still unanswered.\n"
    . "       These change a table, a key, a unique index or an aggregate boundary; or what is sold,\n"
    . "       billed or owned; or carry a legal clock. They are the expensive ones to reverse once\n"
    . "       code exists, and code now exists.            → config/decisions-answered.json\n\n",
    count($decisions) - count((array) ($answers['decisions'] ?? [])));

printf("    2. A NAMED statutory compliance owner (D-XVII-01, R-XXXII-006). Until they exist, no\n"
    . "       statutory value in this product can be attributed to anybody, so none has been\n"
    . "       written. The machinery that will refuse an unattributed pack is finished and proven\n"
    . "       against a synthetic one.                     → config/compliance-owner.json\n\n");

printf("    3. A pilot tenant, and a named person at that customer to sign its migration off. The\n"
    . "       harness that refuses a cutover without a passing reconciliation, a rehearsed rollback\n"
    . "       and a signature is finished and proven.      → a customer, not a file\n\n");

printf("    4. Customer-facing documentation — an administrator manual, a Studio guide, a developer\n"
    . "       extension guide. Part XXX makes a module incomplete without it, and it is written by\n"
    . "       people.                                      → a writer, not a generator\n\n");

echo "  " . str_repeat('=', 92) . "\n";
