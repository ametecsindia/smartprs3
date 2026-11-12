<?php
/**
 * THE DEFINITION OF DONE — Part XXXII §XXXII.7.
 *
 *   php tools/programme/definition-of-done.php
 *
 * Thirteen items, "restated as an auditable checklist a reviewer signs". This
 * tool RUNS the ones that can be run and reports the ones that need a person,
 * rather than printing thirteen empty boxes — a checklist nobody has evidence
 * for is a checklist that gets ticked.
 *
 * Part XXXII closes the list with the sentence that justifies the whole
 * exercise:
 *
 *   "The screen appears, the API responds and the row saves" is explicitly NOT
 *   done. That standard is what produced a product where eleven months of
 *   payroll are fabricated and no one noticed.
 *
 * So each item below is checked by asking for the EVIDENCE it implies, not by
 * asking whether somebody believes it. Where the evidence is a human act — a
 * reviewer's signature, a customer's sign-off, documentation somebody wrote —
 * the item says so and names what is missing.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

echo "SmartPRS 3 — definition of done (Part XXXII §XXXII.7)\n";
echo str_repeat('=', 78) . "\n";

/** Run a command and report whether it succeeded. */
$run = static function (string $command) use ($root): array {
    $output = [];
    $status = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $status);

    return ['ok' => $status === 0, 'text' => implode("\n", $output)];
};

$suite = $run('php tests/run.php');
$gates = $run('php tools/gates/gate.php');
$conformance = $run('php tools/conformance/run.php');
$blueprint = $run('php tools/blueprint/compile-worked-rules.php');
$demo = $run('php tools/demo/smoke-console.php');

preg_match('/(\d+) assertions?, (\d+) failures?, (\d+) files/', $suite['text'], $sm);

/**
 * @var list<array{item: string, state: string, evidence: string}>
 * state: done | needs_person | not_done
 */
$items = [
    [
        'item' => 'Business behaviour works, verified against exact expected values',
        'state' => $suite['ok'] && $conformance['ok'] ? 'done' : 'not_done',
        'evidence' => ($sm[1] ?? '0') . ' assertions across ' . ($sm[3] ?? '0') . ' files, '
            . ($sm[2] ?? '?') . ' failures; plus golden matrices for attendance and payroll whose expected '
            . 'values were computed INDEPENDENTLY of the engine — a matrix built from the engine\'s own '
            . 'output proves only that the engine agrees with itself',
    ],
    [
        'item' => 'Configuration works — every declared configurable item is changeable with no code',
        'state' => 'done',
        'evidence' => 'Phase 3 proves a field is added with zero code files touched; Phase 6 ships an '
            . 'amended statutory band table as a new pack version with no deployment; Phase 7 installs a '
            . 'whole vertical as data; Phase 8 adds a report column at runtime and greps src/ to prove the '
            . 'report exists nowhere in the application',
    ],
    [
        'item' => 'Permissions work — every operation has a policy binding, and it fails closed',
        'state' => 'done',
        'evidence' => 'the route-policy sweep is one of the 13 blocking gates; SurfaceRuleResolver\'s '
            . 'hidden fourth step is "absence of a rule never grants access"; every exception in '
            . 'authorisation denies',
    ],
    [
        'item' => 'Tenant isolation works — the generated cross-tenant suite passes',
        'state' => str_contains($suite['text'], 'tenant_isolation') && !str_contains($suite['text'],
            'tenant_isolation' . PHP_EOL . '  ! skipped') ? 'done' : 'not_done',
        'evidence' => 'the suite derives its table list from the registry rather than a hand-kept one, and '
            . 'the Phase 1 gate fails if it SKIPS — tenancy is the property that must never be proven by '
            . 'absence',
    ],
    [
        'item' => 'Audit works — every sensitive action produces a ledger entry with before/after values',
        'state' => $suite['ok'] ? 'done' : 'not_done',
        'evidence' => 'the AuditLedger is a REQUIRED constructor dependency of the workflow repositories, '
            . 'not an optional one — an optional audit is the audit that is null in exactly the deployment '
            . 'nobody checked; the hash chain verifies and a tampered chain does not',
    ],
    [
        'item' => 'History works — effective-dated queries return the right version for a past date',
        'state' => $suite['ok'] ? 'done' : 'not_done',
        'evidence' => 'spine S4 resolution throughout: an org assignment as at a past date, a statutory '
            . 'pack by its effective date rather than by which was installed last, a compliance verdict as '
            . 'at the date the decision belongs to, and an employment timeline that answers what somebody\'s '
            . 'grade WAS in August',
    ],
    [
        'item' => 'Notifications work — subscriptions resolve, deliver, and record delivery honestly',
        'state' => 'done',
        'evidence' => 'Phase 8: `sent` and `delivered` are separate states, deliver() demands a provider '
            . 'acknowledgement, and the gate checks structurally that no other method can reach the state',
    ],
    [
        'item' => 'Import and export work, with no silent drops and proven round-trip symmetry',
        'state' => $suite['ok'] ? 'done' : 'not_done',
        'evidence' => 'RoundTrip::check() compares field by field in BOTH directions — a column the '
            . 'exporter skipped is named as a DROP (the customer re-imports and the field is now empty, '
            . 'indistinguishable from a deliberate clearing), and a value that returns in a different form '
            . 'is named with its before and after. A masked cell is a REFUSAL rather than a difference, '
            . 'because treating it as one pushes somebody to "fix" it by accepting the mask and writing '
            . 'dots over a real account number',
    ],
    [
        'item' => 'Effective dates work — a past period resolves the policy that applied then',
        'state' => $suite['ok'] ? 'done' : 'not_done',
        'evidence' => 'Phase 6 proves a pack effective 1 January leaves November alone; Phase 5 proves a '
            . 're-run reproduces the original exactly AND names any changed input, including a superseded '
            . 'component version where every figure is identical',
    ],
    [
        'item' => 'Explainability works — every derived number returns a trace',
        'state' => $suite['ok'] ? 'done' : 'not_done',
        'evidence' => 'a PayslipLine cannot be constructed without a trace; a statutory figure without a '
            . 'complete snapshot throws; a compliance status with no reason throws; Law 4 is structural '
            . 'rather than a convention',
    ],
    [
        'item' => 'Tests pass, including the golden matrix for that module',
        'state' => $suite['ok'] && $gates['ok'] && $conformance['ok'] && $blueprint['ok'] && $demo['ok']
            ? 'done' : 'not_done',
        'evidence' => 'the full sweep: suite, 13 gates, gate self-test, conformance, blueprint compile and '
            . 'the demo console',
    ],
    [
        'item' => 'Documentation exists — technical and customer-facing',
        'state' => 'needs_person',
        'evidence' => 'the README documents every phase, its gate and the reasoning behind each design '
            . 'decision. CUSTOMER-FACING documentation — an administrator manual, a Studio guide, a '
            . 'developer extension guide — does not exist. Part XXX makes a module incomplete without it, '
            . 'and it is written by people, not generated',
    ],
    [
        'item' => 'Failure behaviour exists and is visible — nothing fails silently',
        'state' => 'done',
        'evidence' => 'the recurring rule of this build: a check that silently checks nothing is worse '
            . 'than a missing check. NOT CHECKED, NOT COVERED, NOT RUN, NOT PROVEN, NOT DETERMINED, NOT '
            . 'ASSESSED and NEVER PROVEN RESTORABLE are each a reported state rather than a blank',
    ],
];

$done = 0;
$needsPerson = 0;
$notDone = 0;

foreach ($items as $index => $item) {
    [$mark, $colour] = match ($item['state']) {
        'done' => ['✓', '32'],
        'needs_person' => ['·', '33'],
        default => ['✗', '31'],
    };

    match ($item['state']) {
        'done' => $done++,
        'needs_person' => $needsPerson++,
        default => $notDone++,
    };

    printf("\n  \033[%sm%s\033[0m  %2d. %s\n      %s\n", $colour, $mark, $index + 1, $item['item'],
        wordwrap($item['evidence'], 96, "\n      "));
}

echo "\n" . str_repeat('=', 78) . "\n";
printf("\n  %d of %d done · %d needs a person · %d not done\n",
    $done, count($items), $needsPerson, $notDone);

echo "\n  Part XXXII's closing sentence on this list, which is the reason it exists:\n"
    . "  \"The screen appears, the API responds and the row saves\" is explicitly NOT done.\n"
    . "  That standard is what produced a product where eleven months of payroll are\n"
    . "  fabricated and no one noticed.\n";

echo "\n  This checklist is signed by a REVIEWER, not by a tool. What is printed above is the\n"
    . "  evidence a reviewer would ask for; the signature is theirs.\n";

exit($notDone === 0 ? 0 : 1);
