<?php
/**
 * THE PHASE 0 EXIT GATE — Part XXXII, Forensic analysis and specification.
 *
 *   php tools/phase0/exit-gate.php
 *
 * Two conditions, and neither is an engineering one:
 *
 *   1. Product Owner approval of the blueprint.
 *   2. Answers to the Tier 1 decisions in Part XXXIV §2.
 *
 * Phase 0's DELIVERABLE is complete — fourteen as-is analyses, a thirty-five
 * part blueprint, ~350 defects and ~200 security findings with file:line
 * evidence, ~330 decisions registered. Its GATE is not, and the distinction
 * matters: every phase after this one has been built against decisions nobody
 * has formally answered, which is a fact about the programme that should be
 * visible in one command rather than remembered.
 *
 * A Tier 1 decision, by Part XXXIV's own test, is one that changes a table, a
 * key, a unique index or an aggregate boundary; or changes what is sold, billed
 * or owned; or has a legal clock on it. Those are the decisions that are
 * expensive to reverse after code exists — which is precisely the state this
 * repository is now in.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

echo "SmartPRS 3 — Phase 0 exit gate (Part XXXII, Forensic analysis and specification)\n";

$register = $root . '/docs/registers/REGISTER-DECISIONS.md';
$answersPath = $root . '/config/decisions-answered.json';

if (!is_file($register)) {
    echo "\n  \033[31mFAIL\033[0m  the decisions register is missing\n";

    exit(1);
}

// Parse the register's own rows rather than a hand-kept list: a count somebody
// maintains beside the register is a count that drifts from it.
$tier1 = [];

foreach (file($register) ?: [] as $line) {
    if (preg_match('/^\|\s*`(D-[A-Za-z0-9-]+)`\s*\|\s*([A-Z]+)\s*\|\s*(.+?)\s*\|\s*1\s*\|/', $line, $m) === 1) {
        $tier1[$m[1]] = ['part' => $m[2], 'question' => $m[3]];
    }
}

$answered = is_file($answersPath)
    ? json_decode((string) file_get_contents($answersPath), true, 512, JSON_THROW_ON_ERROR)
    : [];

$approval = $answered['blueprint_approval'] ?? null;
$outstanding = array_diff_key($tier1, (array) ($answered['decisions'] ?? []));

printf("\n  %d Tier 1 decision(s) in the register · %d answered · %d outstanding\n",
    count($tier1), count($tier1) - count($outstanding), count($outstanding));

if (!is_array($approval) || trim((string) ($approval['by'] ?? '')) === ''
    || trim((string) ($approval['on'] ?? '')) === ''
) {
    echo "\n  \033[33mNOT PROVEN\033[0m  the blueprint carries no recorded Product Owner approval.\n"
        . "              config/decisions-answered.json needs blueprint_approval { by, role, on }.\n";
} else {
    printf("\n  \033[32mApproved\033[0m by %s (%s) on %s\n", $approval['by'],
        $approval['role'] ?? '?', $approval['on']);
}

if ($outstanding !== []) {
    echo "\n  \033[33mNOT PROVEN\033[0m  " . count($outstanding) . " Tier 1 decision(s) are unanswered. "
        . "By Part XXXIV's own\n              test these are the decisions that change a table, a key, a "
        . "unique index or an\n              aggregate boundary; change what is sold, billed or owned; or "
        . "carry a legal\n              clock. They are the expensive ones to reverse once code exists — "
        . "which is\n              the state this repository is now in.\n\n"
        . "              The first ten, as a sample of what is waiting:\n";

    foreach (array_slice($outstanding, 0, 10, true) as $id => $decision) {
        printf("                %-14s (Part %s) %s\n", $id, $decision['part'],
            mb_strimwidth($decision['question'], 0, 96, '…'));
    }

    echo "\n              Answer one by adding it to config/decisions-answered.json with the answer,\n"
        . "              who decided, and when. The gate re-reads the register each run, so a\n"
        . "              decision added to the blueprint appears here without touching this file.\n";
}

$blocked = $outstanding !== [] || !is_array($approval);

printf("\n%s  Phase 0's DELIVERABLE is complete (14 analyses, a 35-part blueprint, ~350 defects and\n"
    . "        ~200 security findings with file:line evidence). Its GATE is %s.\n",
    $blocked ? 'BLOCKED' : 'PASS',
    $blocked ? 'not passed' : 'passed');

exit($blocked ? 2 : 0);
