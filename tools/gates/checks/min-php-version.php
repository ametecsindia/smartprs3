<?php
/**
 * THE CODE RUNS ON THE PHP THE CUSTOMER ACTUALLY HAS.
 *
 * composer.json declares the floor. This check asserts the source honours it,
 * because Composer only enforces the floor on the machine doing the install —
 * by which point the customer has already failed to install.
 *
 * Found the hard way: composer.json declared ^8.4 while the first machine that
 * tried it ran PHP 8.3.30, and the whole tree used exactly one 8.4-only
 * function (array_any, twice). One line of convenience had raised the
 * prerequisite bar for the entire on-premise estate. Part XXVI is explicit that
 * a lower bar means more installs that work, and SmartPRS 2's worst operational
 * failures come from customers whose environment did not match its assumptions.
 *
 * Scope: src/, tests/, tools/ and database/ — everything shipped. If you
 * genuinely need a newer feature, raise the floor in composer.json deliberately
 * and say why in the release notes. Do not add an allow-list entry here; there
 * isn't one, on purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/**
 * Functions introduced after the declared floor, mapped to the version that
 * added them and to what to write instead. Extend when the floor moves.
 */
const GATE_PHP_FUNCS = [
    // PHP 8.4
    'array_find' => ['8.4', 'a foreach that returns the first match'],
    'array_find_key' => ['8.4', 'a foreach that returns the first key'],
    'array_any' => ['8.4', 'a foreach that returns true on the first hit'],
    'array_all' => ['8.4', 'a foreach that returns false on the first miss'],
    'mb_trim' => ['8.4', 'trim(), or preg_replace with /u'],
    'mb_ltrim' => ['8.4', 'ltrim(), or preg_replace with /u'],
    'mb_rtrim' => ['8.4', 'rtrim(), or preg_replace with /u'],
    'mb_ucfirst' => ['8.4', 'mb_strtoupper on the first grapheme'],
    'mb_lcfirst' => ['8.4', 'mb_strtolower on the first grapheme'],
    'bcdivmod' => ['8.4', 'bcdiv() and bcmod()'],
    'grapheme_str_split' => ['8.4', 'preg_split with /u'],
    'request_parse_body' => ['8.4', 'the interface layer, not domain code'],
    'http_get_last_response_headers' => ['8.4', 'the integration layer'],
    // PHP 8.5 — listed now so the check keeps working when the floor is 8.4.
    'array_first' => ['8.5', 'reset() on a copy, or a foreach'],
    'array_last' => ['8.5', 'end() on a copy, or a foreach'],
];

/** Syntax introduced after the floor. Matched on token shape, not on a regex over source. */
const GATE_PHP_SYNTAX = [
    'property hooks' => ['8.4', 'a real method — get/set hooks need 8.4'],
    'asymmetric visibility' => ['8.4', 'a private property with a public getter'],
];

function gate_min_php_version(string $root): array
{
    $findings = [];

    $declared = gate_php_floor($root);
    if ($declared === null) {
        return [gate_find(
            'min-php-version',
            $root,
            $root . '/composer.json',
            1,
            'composer.json declares no "php" requirement — the floor is undefined, so nothing can be checked against it'
        )];
    }

    $files = array_merge(
        gate_php_files($root, 'src'),
        gate_php_files($root, 'tests'),
        gate_php_files($root, 'tools'),
        gate_php_files($root, 'database')
    );

    foreach ($files as $abs) {
        $t = gate_tokens($abs);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            if ($t[$i]['id'] !== T_STRING) {
                continue;
            }

            $name = strtolower($t[$i]['text']);
            if (!isset(GATE_PHP_FUNCS[$name])) {
                continue;
            }

            // A call, not a method call and not a declaration.
            if (gate_sig_text($t, $i, +1) !== '(') {
                continue;
            }
            $before = gate_sig_text($t, $i, -1);
            if ($before === '->' || $before === '::' || $before === 'function') {
                continue;
            }

            [$since, $instead] = GATE_PHP_FUNCS[$name];
            if (gate_version_le($since, $declared)) {
                continue; // the floor already covers it
            }

            $findings[] = gate_find(
                'min-php-version',
                $root,
                $abs,
                $t[$i]['line'],
                "{$name}() needs PHP {$since} but composer.json declares {$declared} — use {$instead}"
            );
        }

        foreach (gate_php_syntax_findings($t) as [$line, $what, $since, $instead]) {
            if (gate_version_le($since, $declared)) {
                continue;
            }
            $findings[] = gate_find(
                'min-php-version',
                $root,
                $abs,
                $line,
                "{$what} needs PHP {$since} but composer.json declares {$declared} — use {$instead}"
            );
        }
    }

    return $findings;
}

/** The declared floor as a bare "major.minor", or null. */
function gate_php_floor(string $root): ?string
{
    $path = $root . '/composer.json';
    if (!is_file($path)) {
        return null;
    }

    $json = json_decode((string) file_get_contents($path), true);
    $req = $json['require']['php'] ?? null;
    if (!is_string($req)) {
        return null;
    }

    // The lowest version named in the constraint is the floor we must honour.
    if (!preg_match_all('/(\d+)\.(\d+)/', $req, $m, PREG_SET_ORDER)) {
        return null;
    }

    $lowest = null;
    foreach ($m as $one) {
        $v = $one[1] . '.' . $one[2];
        if ($lowest === null || gate_version_le($v, $lowest)) {
            $lowest = $v;
        }
    }

    return $lowest;
}

/** true when $a <= $b, both "major.minor". */
function gate_version_le(string $a, string $b): bool
{
    return version_compare($a, $b, '<=');
}

/**
 * Property hooks and asymmetric visibility, by token shape.
 *
 * @param list<array{id:int|string,text:string,line:int}> $t
 * @return list<array{0:int,1:string,2:string,3:string}>
 */
function gate_php_syntax_findings(array $t): array
{
    $out = [];
    $n = count($t);

    for ($i = 0; $i < $n; $i++) {
        // Asymmetric visibility: private(set) / protected(set)
        if (in_array($t[$i]['id'], [T_PRIVATE, T_PROTECTED], true)
            && gate_sig_text($t, $i, +1) === '(') {
            $j = gate_sig($t, $i, +1);
            $k = $j === null ? null : gate_sig($t, $j, +1);
            if ($k !== null && strtolower($t[$k]['text']) === 'set') {
                $out[] = [$t[$i]['line'], 'asymmetric visibility', '8.4', 'a private property with a public getter'];
            }
        }

        // Property hooks: a property declaration that opens a brace instead of
        // ending in ; or =. A local variable never follows a type or a
        // visibility keyword, which is what keeps this off ordinary code.
        if ($t[$i]['id'] === T_VARIABLE && gate_sig_text($t, $i, +1) === '{') {
            $prev = gate_sig($t, $i, -1);
            if ($prev !== null && in_array(
                $t[$prev]['id'],
                [T_STRING, T_ARRAY, T_CALLABLE, T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_STATIC],
                true
            )) {
                $out[] = [$t[$i]['line'], 'property hooks', '8.4', 'a real method — get/set hooks need 8.4'];
            }
        }
    }

    return $out;
}

gate_cli_main(__FILE__, 'min-php-version', 'gate_min_php_version');
