<?php

declare(strict_types=1);

/**
 * A MODULE DRAWN AS BOXES MUST STILL DRAW SOMETHING.
 *
 * Owner, 23 Sep: "for Each company, have box type structure with ALL the
 * relevant fields within that box for that particular company). NO Other data
 * outside the box for any company".
 *
 * Boxes mode does two things on the module screen: it SUPPRESSES the register
 * table, and it RENDERS the record form once per row. Those are two different
 * places in public/app.php, and the second one sat inside the first one's
 * `if (!$moduleBoxes)` — that block opened before the table and, instead of
 * ending with it, ran on past the form to the bottom of the screen. So turning
 * boxes on removed the table and skipped every box: the Companies page came back
 * EMPTY. No boxes, no table, no PHP error, nothing to read. It shipped that way
 * twice, because 650 lines of alternate syntax read as correct however carefully
 * they are read. So this is not read — it is counted, with the same tokenizer
 * PHP itself uses.
 *
 * `php -l` cannot catch this: a misplaced `endif` is perfectly valid PHP that
 * simply means something else.
 */

group('module screen · boxes mode nesting');

$root = dirname(__DIR__);
$app = $root . '/public/app.php';
ok(is_file($app), 'public/app.php is where the module screen lives');

$src = (string) file_get_contents($app);
$lines = explode("\n", $src);

/**
 * Every alternate-syntax block still open at $target, innermost last, as
 * [line, keyword]. Braced blocks are not tracked: the module screen is written
 * in alternate syntax throughout, and that is where the trap is.
 */
$openAt = static function (string $src, int $target): array {
    $tokens = token_get_all($src);
    $count = count($tokens);
    $stack = [];

    foreach ($tokens as $i => $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if ($line >= $target) {
            break;
        }

        switch ($id) {
            case T_IF:
            case T_ELSEIF:
            case T_ELSE:
            case T_FOREACH:
            case T_FOR:
            case T_WHILE:
            case T_SWITCH:
                // ALTERNATE SYNTAX OR NOT: a colon where the body starts rather
                // than a brace. Looked for past the condition's own parentheses,
                // because `if ($a ? b : c) {` would otherwise answer too early.
                $depth = 0;
                $alt = false;

                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];

                    if ($next === '(') { $depth++; continue; }
                    if ($next === ')') { $depth--; continue; }
                    if ($depth > 0) { continue; }
                    if ($next === ':') { $alt = true; break; }
                    if ($next === '{' || $next === ';') { break; }
                }

                if (!$alt) {
                    break;
                }

                // `else`/`elseif` continue the block they follow rather than
                // opening a second one — one chain, one `endif`.
                if ($id === T_ELSE || $id === T_ELSEIF) {
                    array_pop($stack);
                }

                $stack[] = [$line, strtolower($text)];
                break;

            case T_ENDIF:
            case T_ENDFOREACH:
            case T_ENDFOR:
            case T_ENDWHILE:
            case T_ENDSWITCH:
                array_pop($stack);
                break;
        }
    }

    return $stack;
};

/** The lines of the open blocks at $target whose source mentions !$moduleBoxes. */
$suppressedAt = static function (int $target) use ($openAt, $src, $lines): array {
    $out = [];

    foreach ($openAt($src, $target) as [$line, $keyword]) {
        if ($keyword === 'if' && str_contains($lines[$line - 1] ?? '', '!$moduleBoxes')) {
            $out[] = $line;
        }
    }

    return $out;
};

/** A landmark line, found rather than hard-coded, so this test survives edits above it. */
$lineOf = static function (string $needle, string $what) use ($lines): int {
    foreach ($lines as $i => $text) {
        if (str_contains($text, $needle)) {
            return $i + 1;
        }
    }

    ok(false, $what . ' is still in app.php');

    return 0;
};

$boxes = $lineOf('foreach ($boxRows as $current)', 'the per-record form loop');
$table = $lineOf("<thead><tr><?php foreach (\$result['columns']", 'the register table');

// --- the boxes are outside the table's guard ------------------------------------
// This is the whole bug in one assertion. When it goes red, boxes mode hides the
// table and then skips the boxes too, and the screen is blank.

same([], $suppressedAt($boxes),
    'the per-record boxes are NOT inside an "if (!$moduleBoxes)" block');

// --- the table is still inside it -----------------------------------------------
// The other half. Without this, boxes mode would draw the table AND the boxes:
// every company twice on one screen, which is the duplication the owner rejected.

ok($suppressedAt($table) !== [],
    'the register table IS inside an "if (!$moduleBoxes)" block, so boxes replace it');

ok($table < $boxes, 'the table is drawn above the record forms, as it always was');

// --- every block in the file closes ----------------------------------------------
// Cheap, and it catches a stray endif in either direction.

same([], $openAt($src, PHP_INT_MAX), 'no alternate-syntax block is left open at the end of app.php');

// --- and the module that asked for boxes still asks for them ----------------------

$config = json_decode((string) file_get_contents($root . '/config/modules.json'), true);
$companies = [];

foreach ((array) ($config['modules'] ?? $config) as $module) {
    if ((string) ($module['key'] ?? '') === 'companies') {
        $companies = $module;
    }
}

ok($companies !== [], 'config/modules.json declares the companies module');
ok(($companies['record_boxes'] ?? false) === true,
    'companies is declared "record_boxes": true — one box per company, no table');
