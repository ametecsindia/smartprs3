<?php
/**
 * EVERY SHIPPED FILE DECLARES strict_types=1.
 *
 * Without it PHP coerces: '15000' satisfies an int parameter, '0.5' satisfies a
 * float, and a Money constructor that means to take paise quietly accepts a
 * string that came from a form. The whole type system this platform rests on —
 * SPX's units, Money's integer minor units, the Days/Number distinction that
 * Part VIII §5.3 exists for — is enforced by PHP only in strict mode. One file
 * without the declaration is a hole in all of it, and it is invisible: the code
 * works, right up to the input that coerces wrong.
 *
 * This was a composer script, `! grep -rLn ... | grep .`, which is a Unix shell
 * idiom that returns 1 on Windows whatever the source says — so on the first
 * machine that ran `composer check`, the check that could not run was
 * indistinguishable from the check that failed. A gate belongs where the other
 * eleven are: same runner, same self-test, same behaviour on every host.
 *
 * Scope: src/, database/ and tests/ — everything shipped or run. tools/ is
 * excluded with everything else in GATE_EXCLUDE, and the gates declare it
 * themselves anyway.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

function gate_strict_types(string $root): array
{
    $files = array_merge(
        gate_php_files($root, 'src'),
        // surface/ and public/ were outside this gate while being the only code a
        // user's request ever reaches. PASS over an unopened directory is not a pass.
        gate_php_files($root, 'surface'),
        gate_php_files($root, 'public'),
        gate_php_files($root, 'database'),
        gate_php_files($root, 'tests'),
    );

    if ($files === []) {
        return gate_vacuous('strict-types', $root, 'no PHP files found under src/, database/ or tests/');
    }

    $findings = [];

    foreach ($files as $abs) {
        $line = gate_strict_types_line($abs);

        if ($line !== null) {
            continue;
        }

        $findings[] = gate_find(
            'strict-types',
            $root,
            $abs,
            1,
            'no declare(strict_types=1) — without it PHP coerces, and every unit distinction this platform '
            . 'depends on is enforced by the engine only in strict mode',
        );
    }

    return $findings;
}

/**
 * The line the declaration is on, or null when the file does not carry one.
 *
 * Read from the TOKENS rather than by matching text, because a declaration
 * inside a comment or a string satisfies a grep and satisfies nothing else —
 * and this file's own docblock quotes the string it looks for.
 */
function gate_strict_types_line(string $file): ?int
{
    $t = gate_tokens($file);
    $n = count($t);

    for ($i = 0; $i < $n; $i++) {
        if ($t[$i]['id'] !== T_DECLARE) {
            continue;
        }

        $open = gate_sig($t, $i, +1);

        if ($open === null || $t[$open]['id'] !== '(') {
            continue;
        }

        $name = gate_sig($t, $open, +1);

        if ($name === null || strtolower($t[$name]['text']) !== 'strict_types') {
            continue;
        }

        $equals = gate_sig($t, $name, +1);
        $value = $equals === null ? null : gate_sig($t, $equals, +1);

        if ($value !== null && $t[$value]['text'] === '1') {
            return $t[$i]['line'];
        }
    }

    return null;
}

gate_cli_main(__FILE__, 'strict-types', 'gate_strict_types');
