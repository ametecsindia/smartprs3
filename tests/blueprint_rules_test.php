<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\AggMetrics;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\PolicyKeys;
use SmartPRS\Platform\Spx\SymbolTable;

/**
 * The specification's own worked rules, compiled by the engine that runs them.
 *
 * Part VII §12 works fifteen rules end to end, RL-ATT-001 among them — the
 * governing brief's Section 127 acceptance test. They were written before the
 * engine existed, so "SPX can express this" was prose. Sixteen of the
 * twenty-five expressions did not compile the first time they were fed to the
 * real lexer, parser and type checker.
 *
 * Two root causes, both the same shape: a register the engine consults and
 * nothing had filled in. `policy.value(…)` was Number for every key, and
 * `event.value` was Number for every metric — so the unit system stopped
 * exactly where configuration begins, which is where SmartPRS 2's unit
 * confusion actually lives.
 *
 * This runs in the ordinary suite so the documents and the engine cannot drift
 * apart quietly: edit a rule in the markdown and this fails.
 */

require_once __DIR__ . '/../tools/blueprint/lib.php';

group('Blueprint worked rules');

$docs = __DIR__ . '/../docs';

if (!is_dir($docs)) {
    echo "  ! skipped: no docs/ directory in this checkout\n";

    return;
}

$gaps = require __DIR__ . '/../tools/blueprint/known-gaps.php';
$rules = array_merge(worked_rules($docs), worked_formulas($docs));

ok(count($rules) >= 40, 'the worked rules are found in the documents (' . count($rules) . ' expressions)');

$symbols = worked_symbol_table($docs);

foreach ($rules as $r) {
    $why = '';

    try {
        $check = Compiled::parse($r['source'], $symbols)->typeCheck();
        $why = $check->isValid() ? '' : implode('; ', $check->messages());
    } catch (Throwable $e) {
        $why = $e::class . ': ' . $e->getMessage();
    }

        $label = "{$r['code']} {$r['clause']}";

    if (isset($gaps[$label])) {
        // A ratchet, not an allow-list: a listed gap that starts compiling is
        // reported as loudly as a new failure, so the list cannot rot.
        ok($why !== '', "known gap {$label} is still a gap — remove it from known-gaps.php if it now compiles");

        continue;
    }

    ok($why === '', "{$label} compiles ({$r['doc']}:{$r['line']})" . ($why === '' ? '' : " — {$why}"));
}

// Every key the worked rules read must be declared, and declared as PROVED:
// a key exercised by a compiled expression is no longer an inference.
$read = [];

foreach ($rules as $r) {
    if (isset($gaps["{$r['code']} {$r['clause']}"])) {
        continue;
    }

    if (preg_match_all("/policy\.value\('([a-z0-9_]+)'\)/", $r['source'], $m) === 1 || $m[1] !== []) {
        $read = array_merge($read, $m[1]);
    }
}

foreach (array_unique($read) as $key) {
    ok(PolicyKeys::isProved($key), "policy key '{$key}' is proved by a compiled rule, not merely inferred");
}

// The two registers must be self-consistent: every declared type must be a type
// the table can build, or the failure would be a fatal at first use.
ok(count(PolicyKeys::types()) === count(PolicyKeys::PROVED) + count(PolicyKeys::INFERRED),
    'every declared policy key resolves to a type');
ok(count(AggMetrics::types()) === count(AggMetrics::METRICS),
    'every declared aggregation metric resolves to a type');

// An undeclared key is a publish error naming the key — this is what catches a
// typo in a policy name at authoring rather than as a missing value at runtime.
$errors = Compiled::parse("policy.value('late_grace_mins') > 0m", $symbols)->typeCheck()->messages();
ok(
    str_contains(implode(' ', $errors), "'late_grace_mins' is not declared"),
    'a misspelled policy key is refused at publish, naming the key',
);
