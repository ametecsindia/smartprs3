<?php
/**
 * THE PHASE 5 EXIT GATE — Part XXXII, Compensation and money movement.
 *
 *   php tools/phase5/exit-gate.php
 *
 * Phase 5 is the only phase whose ENTRY gate is a test: "the payroll golden-test
 * matrix is written and FAILING" before the pipeline is built. So this file and
 * `tests/phase5/payroll_matrix.json` were written first, run first, and failed
 * first — which is the only way to know that what passes later is the pipeline
 * rather than a matrix shaped around it.
 *
 * The five exit conditions:
 *
 *   1. The golden-test matrix passes with EXACT values.
 *   2. The run-level reconciliation assertion ABORTS a run on any mismatch.
 *   3. No component name appears as a literal anywhere in the codebase.
 *   4. A re-run reproduces the original result exactly and reports any changed
 *      input.
 *   5. Every recovery reverses cleanly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

$root = dirname(__DIR__, 2);
$matrix = json_decode(
    (string) file_get_contents($root . '/tests/phase5/payroll_matrix.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$results = [];

echo "SmartPRS 3 — Phase 5 exit gate (Part XXXII, Compensation and money movement)\n";

$engineExists = class_exists(\SmartPRS\Platform\Payroll\PayrollRun::class);

if (!$engineExists) {
    printf(
        "\n  \033[33mENTRY GATE\033[0m  the matrix is written (%d case(s), %d perturbation(s)) and the "
        . "pipeline does not exist yet.\n              Part XXXII requires exactly this state before Phase 5 "
        . "begins: a matrix that has never\n              passed is a matrix nobody has shaped around the "
        . "code.\n\nFAIL  0 of 5 Phase 5 exit criteria proven — the pipeline is not built\n",
        count($matrix['cases']),
        count($matrix['reconciliation']['perturbations']),
    );

    exit(1);
}

require __DIR__ . '/criteria.php';
