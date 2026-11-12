<?php
/**
 * EVERY PHASE GATE, IN ORDER — Part XXXII §XXXII.7.
 *
 *   php tools/phases/run-all.php
 *
 * One command that answers "where is this programme?" without anyone assembling
 * the answer from ten terminal windows and a memory of what passed last week.
 *
 * The distinction it exists to preserve is the one this build keeps returning
 * to: FAILED and NOT PROVEN are different. A failing gate means something is
 * wrong with the code. A NOT PROVEN gate means a criterion could not be
 * evaluated at all — Phase 6's statutory values wait on a named compliance
 * owner, Phase 10's cutover waits on a named person at a customer — and neither
 * is something an engineer can close by working harder. Collapsing them into one
 * red number would make the two look like the same kind of problem, and only one
 * of them has an engineering answer.
 *
 * So the summary states them separately, and the exit code is non-zero while
 * either exists. A progress report that rounds up is a progress report.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** Phase => what it is, in the words Part XXXII uses. */
const PHASES = [
    0 => 'Forensic analysis and specification',
    1 => 'Platform foundation',
    2 => 'The engines',
    3 => 'People',
    4 => 'Time and absence',
    5 => 'Compensation and money movement',
    6 => 'India statutory framework',
    7 => 'Talent, compliance and the collections pack',
    8 => 'Reports, dashboards and automation',
    9 => 'Delivery, deployment and operations',
    10 => 'Migration, parallel run and cutover',
];

echo "SmartPRS 3 — every phase gate (Part XXXII)\n";
echo str_repeat('=', 78) . "\n";

$passed = 0;
$failed = 0;
$blocked = [];

foreach (PHASES as $number => $title) {
    $gate = "{$root}/tools/phase{$number}/exit-gate.php";

    if (!is_file($gate)) {
        printf("  %-3s \033[33mMISSING\033[0m    Phase %d — %s (no exit gate)\n", '', $number, $title);
        $failed++;

        continue;
    }

    $output = [];
    $status = 0;
    exec('php ' . escapeshellarg($gate) . ' 2>&1', $output, $status);
    $text = implode("\n", $output);

    // The gate's own last line is the authority on its outcome; this runner does
    // not re-judge it, because a runner that decides for itself is a second
    // implementation of every gate.
    $summary = '';

    foreach (array_reverse($output) as $line) {
        if (preg_match('/^(PASS|FAIL|BLOCKED)\s/', trim($line)) === 1) {
            $summary = trim($line);

            break;
        }
    }

    $notProven = substr_count($text, 'NOT PROVEN');

    if ($status === 0) {
        $passed++;
        printf("  \033[32mPASS\033[0m       Phase %-2d — %-46s %s\n", $number, $title, $summary);

        continue;
    }

    if ($notProven > 0) {
        $blocked[$number] = $title;
        printf("  \033[33mBLOCKED\033[0m    Phase %-2d — %-46s %s\n", $number, $title, $summary);

        continue;
    }

    $failed++;
    printf("  \033[31mFAIL\033[0m       Phase %-2d — %-46s %s\n", $number, $title, $summary);

    foreach ($output as $line) {
        if (str_contains($line, '└─')) {
            echo '                 ' . trim($line) . "\n";
        }
    }
}

echo str_repeat('=', 78) . "\n";

printf("\n  %d passed · %d failed · %d blocked on something outside this repository\n",
    $passed, $failed, count($blocked));

foreach ($blocked as $number => $title) {
    printf("\n  Phase %d (%s) is BLOCKED, not failing.\n", $number, $title);

    echo match ($number) {
        0 => "    Product Owner approval of the blueprint, and answers to the Tier 1 decisions of\n"
            . "    Part XXXIV. Those are the decisions that change a table, a key, a unique index or\n"
            . "    an aggregate boundary — the expensive ones to reverse once code exists, which is\n"
            . "    the state this repository is now in. Run tools/phase0/exit-gate.php for the list.\n",
        6 => "    Every statutory value must be verified against its primary source by a NAMED\n"
            . "    compliance owner (R-XXXII-006, D-XVII-01). The mechanism that will refuse a pack\n"
            . "    lacking that is built and proven; the content cannot exist until the appointment.\n"
            . "    Opens by creating config/compliance-owner.json.\n",
        10 => "    A named person at a specific customer signs off a specific tenant's migration.\n"
            . "    The harness that refuses a cutover without it — and without a passing reconciliation\n"
            . "    and a rehearsed rollback — is built and proven. The sign-off is not a repository fact.\n",
        default => "    See the gate's own output.\n",
    };
}

if ($failed === 0 && $blocked === []) {
    echo "\n  Every phase gate passes.\n";
}

echo "\n  A note on the two states. FAILED means something is wrong with the code and an\n"
    . "  engineer can close it. BLOCKED means a criterion cannot be evaluated at all, and no\n"
    . "  amount of engineering closes it — a person has to be appointed, or a customer has to\n"
    . "  sign. Reporting them as one number would make them look like the same kind of\n"
    . "  problem, and only one of them has an engineering answer.\n";

exit($failed === 0 && $blocked === [] ? 0 : 1);
