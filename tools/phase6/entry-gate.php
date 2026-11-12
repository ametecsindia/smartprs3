<?php
/**
 * THE PHASE 6 ENTRY GATE — Part XXXII, India statutory framework.
 *
 *   php tools/phase6/entry-gate.php
 *
 * Part XXXII states this phase's dependency in five words: **no compliance
 * owner, no phase.** R-XXXII-006. It is not an engineering blocker that can be
 * worked around, and it is not a formality: the platform can compute nothing
 * statutory it cannot attribute, and D-XVII-01 makes the named owner the author
 * of every statutory value in the product.
 *
 * So this gate is a real refusal, not a banner. It reads
 * `config/compliance-owner.json` and requires a named person, a role, a date of
 * appointment and a second reviewer — the four-eyes of §XVII.12.4, because one
 * person must not be able to ship law.
 *
 * What the refusal does NOT block is the MECHANISM. The pack format, its
 * canonicalisation, its signature, the import and activation gates, the golden
 * replay, the store, applicability resolution and the single-implementation
 * check all carry zero statutory values, and every one of them is engineering
 * that can be built, tested and proven before an owner is appointed — against a
 * synthetic pack whose every value is marked NOT VERIFIED and carries no
 * `source_reference`. That is exactly the split Part XXXII draws when it says
 * Phase 5's statutory deductions are "stubbed behind the rule-pack interface and
 * tested against a synthetic pack".
 *
 * The distinction this gate exists to hold: a pack with real Indian PF and PT
 * rates in it, attributed to nobody, would look finished and be worthless. A
 * synthetic pack that cannot be mistaken for law, in machinery that is finished,
 * is the honest state of this phase.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

$root = dirname(__DIR__, 2);
$path = $root . '/config/compliance-owner.json';

echo "SmartPRS 3 — Phase 6 entry gate (Part XXXII, India statutory framework)\n";

$declared = is_file($path)
    ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
    : null;

$missing = [];

foreach ([
    'name' => 'the named compliance owner (D-XVII-01)',
    'role' => 'their role, as it will appear in every pack manifest',
    'appointed_on' => 'the date of appointment',
    'reviewer_name' => 'the second reviewer — four-eyes, §XVII.12.4',
    'reviewer_role' => "the second reviewer's role",
] as $field => $what) {
    if (!is_array($declared) || trim((string) ($declared[$field] ?? '')) === '') {
        $missing[] = "{$field} — {$what}";
    }
}

if ($missing !== []) {
    echo "\n  \033[31mBLOCKED\033[0m  R-XXXII-006: no compliance owner, no phase.\n\n";
    echo "           " . (is_array($declared)
        ? "config/compliance-owner.json is incomplete:\n"
        : "config/compliance-owner.json does not exist. It needs:\n");

    foreach ($missing as $field) {
        echo "             · {$field}\n";
    }

    echo "\n           This is a decision for the business, not a file somebody writes to get\n"
        . "           past a gate. The named owner personally verifies every statutory value\n"
        . "           against its primary source and signs the pack that carries it, and their\n"
        . "           name appears on the payslip trace an inspector reads in 2031.\n\n"
        . "           WHAT IS NOT BLOCKED: the pack mechanism. Signature, canonicalisation,\n"
        . "           import and activation gates, golden replay, the immutable store,\n"
        . "           applicability resolution and the single-implementation check hold no\n"
        . "           statutory values and are built against a synthetic pack marked\n"
        . "           NOT VERIFIED. Run tools/phase6/exit-gate.php to see which criteria that\n"
        . "           machinery proves and which wait on the appointment.\n\n"
        . "BLOCKED  Phase 6 cannot be entered — the statutory content half is not startable\n";

    exit(2);
}

printf(
    "\n  \033[32mOPEN\033[0m  Compliance owner appointed: %s (%s), %s; reviewed by %s (%s).\n"
    . "        Every statutory value in every pack is now attributable, and the signing\n"
    . "        service has a manifest it can refuse for cause rather than for absence.\n\n"
    . "PASS  Phase 6 entry gate open\n",
    $declared['name'],
    $declared['role'],
    $declared['appointed_on'],
    $declared['reviewer_name'],
    $declared['reviewer_role'],
);

exit(0);
