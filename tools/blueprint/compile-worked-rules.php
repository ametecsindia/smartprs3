<?php
/**
 * THE BLUEPRINT'S OWN WORKED RULES MUST COMPILE.
 *
 *   php tools/blueprint/compile-worked-rules.php [-v] [--docs=PATH]
 *
 * Part VII §12 works sixteen rules end to end — RL-ATT-001 is the governing
 * brief's Section 127 acceptance test — and Part XI and Appendix C work more.
 * They are the specification's proof that the engine can express the business,
 * and they are what an implementer copies.
 *
 * They were written before the engine existed. Nothing had ever fed them to the
 * real lexer, parser, symbol table and type checker, so "SPX can express this"
 * was an assertion in prose. This script is the assertion made mechanical: every
 * WHEN and EXCEPT in the documents is parsed and type-checked by the shipped
 * engine, against the closed function catalogue and the real symbol table.
 *
 * A failure here is a defect in the BLUEPRINT, not necessarily in the code: the
 * document promises something the language does not offer. Both are worth
 * knowing before Phase 3 builds against either.
 *
 * Exit 0 = every expression compiles.
 */
declare(strict_types=1);

use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

$argv = $_SERVER['argv'];
$verbose = in_array('-v', $argv, true);
$docs = __DIR__ . '/../../docs';

foreach ($argv as $a) {
    if (str_starts_with($a, '--docs=')) {
        $docs = substr($a, 7);
    }
}

if (!is_dir($docs)) {
    fwrite(STDERR, "compile-worked-rules: no docs directory at {$docs}\n");
    exit(2);
}

require_once __DIR__ . '/lib.php';

$rules = array_merge(worked_rules($docs), worked_formulas($docs));

if ($rules === []) {
    fwrite(STDERR, "compile-worked-rules: found no worked rules or formulas under {$docs}\n");
    exit(2);
}

$symbols = worked_symbol_table($docs, $unreadable);
$gaps = require __DIR__ . '/known-gaps.php';
$ok = 0;
$bad = [];
$expected = [];

foreach ($rules as $r) {
    $label = "{$r['code']} {$r['clause']}";

    try {
        $compiled = Compiled::parse($r['source'], $symbols);
        $check = $compiled->typeCheck();

        if ($check->isValid()) {
            $ok++;

            if (isset($gaps[$label])) {
                // A listed gap that now compiles. Stale entries are as loud as
                // new failures, or the list becomes a place things go to be
                // forgotten — which is what an allow-list is.
                $expected[$label] = $gaps[$label];
            } elseif ($verbose) {
                echo "  ok    {$label}\n";
            }

            continue;
        }

        $bad[] = $r + ['kind' => 'does not type-check', 'why' => implode('; ', $check->messages())];
    } catch (SpxSyntaxError $e) {
        $bad[] = $r + ['kind' => 'does not parse', 'why' => $e->reason . ' (line ' . $e->sourceLine . ')'];
    } catch (Throwable $e) {
        $bad[] = $r + ['kind' => 'threw ' . $e::class, 'why' => $e->getMessage()];
    }
}

if ($unreadable !== []) {
    echo "Declared result types this harness cannot read (a finding about the document,\n"
        . "not about the engine — §4.1 lists the permitted forms):\n";

    foreach ($unreadable as $code => $declared) {
        printf("      %-26s %s\n", $code, $declared);
    }

    echo "\n";
}

echo "Blueprint worked rules and formulas — " . count($rules) . " expression(s) from " . count(array_unique(array_column($rules, 'code'))) . " artefact(s)\n\n";

$known = [];

foreach ($bad as $index => $b) {
    $label = "{$b['code']} {$b['clause']}";

    if (isset($gaps[$label])) {
        $known[$label] = $gaps[$label];
        unset($bad[$index]);
    }
}

$bad = array_values($bad);

if ($known !== []) {
    echo 'Known gaps (' . count($known) . " — the blueprint says one thing and the engine another;\n"
        . "each is a decision outstanding, listed in tools/blueprint/known-gaps.php):\n";

    foreach ($known as $label => $why) {
        printf("      %-26s %s\n", $label, $why);
    }

    echo "\n";
}

if ($expected !== []) {
    echo "STALE  these are listed as known gaps but now compile. Remove them from\n"
        . "       tools/blueprint/known-gaps.php — a list nothing leaves is an allow-list:\n";

    foreach ($expected as $label => $why) {
        printf("      %-26s %s\n", $label, $why);
    }

    echo "\n";
}

foreach ($bad as $b) {
    printf("FAIL  %s %s — %s\n", $b['code'], $b['clause'], $b['kind']);
    printf("      %s:%d  %s\n", $b['doc'], $b['line'], $b['title']);
    printf("      %s\n", $b['why']);
    foreach (explode("\n", $b['source']) as $l) {
        echo "      | {$l}\n";
    }
    echo "\n";
}

printf(
    "%s  %d compiled, %d unexpected failure(s), %d known gap(s), %d stale entr(ies)\n",
    $bad === [] && $expected === [] ? 'PASS' : 'FAIL',
    $ok,
    count($bad),
    count($known),
    count($expected)
);

exit($bad === [] && $expected === [] ? 0 : 1);
