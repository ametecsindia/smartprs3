<?php

declare(strict_types=1);

/**
 * EVERY PHASE EXIT GATE, IN ONE RUN, IN THE STANDING SUITE.
 *
 * The phase gates are the most demanding checks this project has — they are what
 * says the money is right, the packs resolve by statutory date, the traces
 * reproduce. And none of them was in `php tools/check.php`, so nobody ran them
 * unless they remembered to, which meant two things were true at once and
 * neither was visible:
 *
 *   1. Phase 5 had been FAILING. Criterion 3 — no component name appears as a
 *      literal — was red because `public/app.php` and `public/index.php` both
 *      typed 'PT'. Nothing reported it, for weeks.
 *   2. Changing `PayslipCalculator`'s signature broke `tools/phase5/criteria.php`
 *      with a fatal, and the full standing suite stayed green through it.
 *
 * A check outside the one command that runs the checks is a check that runs when
 * somebody is already suspicious, which is the opposite of when it is needed.
 *
 * FAILED IS NOT THE SAME AS NOT PROVEN, and this file's only real logic is
 * keeping them apart. A gate exits non-zero for both, but they mean opposite
 * things: FAILED is a defect in this repository and must go red; NOT PROVEN is a
 * fact about the world this repository cannot supply — phase 6 waits on a named
 * compliance owner to sign a real pack, phase 10 waits on a customer's numbers.
 * Failing the standing suite on those would make it permanently red, and a
 * permanently red suite is one people stop reading. They are REPORTED, loudly,
 * every run, and never silently.
 */

$root = dirname(__DIR__);
$gates = glob($root . '/tools/phase*/exit-gate.php') ?: [];
sort($gates);

if ($gates === []) {
    fwrite(STDERR, "Refused: no tools/phase*/exit-gate.php found at all.\n"
        . "This tool reporting 'nothing failed' having found nothing to run is exactly the\n"
        . "silence it exists to remove.\n");

    exit(2);
}

$failed = [];
$notProven = [];
$passed = 0;

foreach ($gates as $gate) {
    $name = basename(dirname($gate));
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($gate) . ' 2>&1', $out, $code);

    // The verdict is the gate's last non-empty line, which every one of them
    // writes in the same shape: "PASS 6 of 6 …" or "FAIL 4 of 5 …".
    $lines = array_values(array_filter($out, static fn ($l) => trim((string) $l) !== ''));
    $strip = static fn (string $l): string => trim(preg_replace('/\033\[[0-9;]*m/', '', $l) ?? $l);

    // THE VERDICT IS THE LAST LINE THAT LOOKS LIKE ONE, not simply the last
    // line. Phase 0's verdict wraps onto a second line of prose, so reading
    // `end()` got "…with file:line evidence). Its GATE is not passed." — which
    // matches no shape at all and filed a legitimately blocked gate as failed.
    // Scanned from the end so a criterion that merely PRINTS the word PASS
    // earlier in the run cannot be mistaken for the run's own answer.
    $plain = '(no verdict line)';

    foreach (array_reverse($lines) as $line) {
        if (preg_match('/^(PASS|FAIL|BLOCKED)\b/', $strip($line)) === 1) {
            $plain = $strip($line);

            break;
        }
    }

    if ($code === 0) {
        $passed++;
        printf("  %-9s PASS  %s\n", $name, $plain);

        continue;
    }

    // TWO SHAPES OF HELD, because the gates were written at different times and
    // both are legitimate. Phases 6 and 10 end "FAIL n of m … k NOT PROVEN" and
    // the arithmetic has to agree — every criterion not proven is accounted for,
    // so none is quietly failing behind the held ones. Phase 0 ends "BLOCKED"
    // and waits on business decisions only the owner can answer (who the named
    // compliance owner is, what a seat is), which is not something this
    // repository can ever prove about itself.
    //
    // Matched strictly on purpose. A looser reading — "does the output mention
    // blocked anywhere" — is how a genuinely failing gate gets filed as held,
    // and a gate filed as held is a gate nobody looks at again.
    $accountedFor = preg_match('/FAIL\s+(\d+) of (\d+)/', $plain, $m) === 1
        && preg_match('/(\d+) NOT PROVEN/', $plain, $n) === 1
        && (int) $m[2] - (int) $m[1] === (int) $n[1];

    $proven = (str_contains($plain, 'NOT PROVEN') && $accountedFor)
        || str_starts_with($plain, 'BLOCKED');

    if ($proven) {
        $notProven[$name] = $plain;
        printf("  %-9s HELD  %s\n", $name, $plain);

        continue;
    }

    $failed[$name] = $plain;
    printf("  %-9s FAIL  %s\n", $name, $plain);

    foreach (array_slice($lines, -6) as $line) {
        echo '            ' . $strip((string) $line) . "\n";
    }
}

echo "\n";

foreach ($notProven as $name => $why) {
    echo "HELD  {$name}: {$why}\n";
}

if ($failed !== []) {
    echo 'FAIL  ' . count($failed) . ' phase gate(s) failed: ' . implode(', ', array_keys($failed)) . "\n";

    exit(1);
}

printf("PASS  %d phase gate(s) proven%s.\n", $passed,
    $notProven === [] ? '' : ', ' . count($notProven) . ' held on a fact this repository cannot supply');

exit(0);
