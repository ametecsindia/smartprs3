<?php
/**
 * LAW 1 — NO BUSINESS LITERAL IN CODE (spine S1).
 *
 * SmartPRS 2 defect: component names, role names, leave-type codes and PF/ESI
 * thresholds are string and numeric literals inside controllers and services.
 * Renaming a component in configuration silently stops matching the code that
 * looks for it, and a statutory ceiling change is a code release.
 *
 * Flags a string or numeric literal in src/*_/Domain/ that sits in a COMPARISON,
 * an ASSIGNMENT or a RETURN — the three positions where a literal is deciding
 * or producing business meaning.
 *
 * Allowed without an entry: 0, 1, -1, '', array keys and index subscripts,
 * enum case values, and anything inside a throw statement or an exception
 * constructor. Everything else goes in tools/gates/allow/literals.php, and
 * every hit on that list is counted and printed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

const GATE_LIT_COMPARE = [
    T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL,
    T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, '<', '>',
];

const GATE_LIT_ASSIGN = [
    '=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
    T_CONCAT_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
];

function gate_no_business_literal(string $root): array
{
    $allow = gate_allow('literals');
    $values = $allow['values'] ?? $allow;
    $allowed = [];
    foreach ($values as $v) {
        $allowed[(string) $v] = true;
    }

    $domain = gate_domain_files($root);
    if ($domain === []) {
        return gate_vacuous('no-business-literal', $root,
            'no src/<Module>/Domain/ directory exists yet — Phase 2 builds the Platform kernel, whose namespaces are not the module/layer convention of Part IV §IV.2.1. This check reads nothing until the first module lands (Part XXXII Phase 3).');
    }

    $findings = [];
    foreach ($domain as $abs) {
        $t = gate_tokens($abs);
        $safe = gate_lit_safe_zones($t);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            $id = $t[$i]['id'];
            $isStr = $id === T_CONSTANT_ENCAPSED_STRING;
            $isNum = $id === T_LNUMBER || $id === T_DNUMBER;
            if (!$isStr && !$isNum) {
                continue;
            }
            if (isset($safe[$i])) {
                continue;   // throw statement or exception constructor argument
            }

            $raw = $isStr ? gate_str_value($t[$i]['text']) : $t[$i]['text'];
            $prev = gate_sig($t, $i, -1);
            $next = gate_sig($t, $i, 1);

            // A leading unary minus belongs to the literal, not to the context.
            if ($isNum && $prev !== null && $t[$prev]['id'] === '-') {
                $before = gate_sig($t, $prev, -1);
                $bid = $before === null ? null : $t[$before]['id'];
                $isOperand = in_array($bid, [T_VARIABLE, T_LNUMBER, T_DNUMBER, T_STRING, ')', ']'], true);
                if (!$isOperand) {
                    $raw = '-' . $raw;
                    $prev = $before;
                }
            }

            // --- structural allowances -------------------------------------
            if ($isStr && $raw === '') {
                continue;
            }
            if ($isNum && in_array((float) $raw, [0.0, 1.0, -1.0], true)) {
                continue;
            }
            if ($next !== null && $t[$next]['id'] === T_DOUBLE_ARROW) {
                continue;   // array key, or a match() arm subject
            }
            if ($prev !== null && $next !== null
                && $t[$prev]['id'] === '[' && $t[$next]['id'] === ']') {
                continue;   // $a['key'] index subscript
            }
            if (gate_lit_is_enum_case($t, $prev)) {
                continue;
            }

            // --- is it in a flagged position? ------------------------------
            $pid = $prev === null ? null : $t[$prev]['id'];
            $nid = $next === null ? null : $t[$next]['id'];
            $where = match (true) {
                in_array($pid, GATE_LIT_COMPARE, true), in_array($nid, GATE_LIT_COMPARE, true) => 'comparison',
                in_array($pid, GATE_LIT_ASSIGN, true) => 'assignment',
                $pid === T_RETURN => 'return',
                $pid === T_CASE => 'switch case',
                default => null,
            };
            if ($where === null) {
                continue;
            }

            $kind = $isStr ? 'string' : 'numeric';
            if (isset($allowed[$raw])) {
                $note = $allow['notes'][$raw] ?? '';
                $findings[] = gate_find(
                    'no-business-literal',
                    $root,
                    $abs,
                    $t[$i]['line'],
                    "allow-listed $kind literal " . var_export($raw, true) . " in $where" . ($note !== '' ? " — $note" : ''),
                    true
                );
                continue;
            }

            $findings[] = gate_find(
                'no-business-literal',
                $root,
                $abs,
                $t[$i]['line'],
                "$kind literal " . var_export($raw, true)
                . (str_starts_with($where, 'a') ? ' in an ' : ' in a ') . "$where inside the domain layer"
                . ' — if a customer could ask for it to be different, it is data'
            );
        }
    }

    return $findings;
}

/** Token indexes inside a throw statement or an exception constructor call. */
function gate_lit_safe_zones(array $t): array
{
    $safe = [];
    $n = count($t);
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i]['id'] === T_THROW) {
            for ($j = $i; $j < $n && $t[$j]['id'] !== ';'; $j++) {
                $safe[$j] = true;
            }
            continue;
        }
        if ($t[$i]['id'] !== T_NEW) {
            continue;
        }
        $cls = gate_sig($t, $i, 1);
        if ($cls === null || !preg_match('/(Exception|Error)$/', $t[$cls]['text'])) {
            continue;
        }
        $open = gate_sig($t, $cls, 1);
        if ($open === null || $t[$open]['id'] !== '(') {
            continue;
        }
        for ($j = $open, $end = gate_match_paren($t, $open); $j <= $end; $j++) {
            $safe[$j] = true;
        }
    }
    return $safe;
}

/** True when the literal follows `case NAME =` — an enum case value. */
function gate_lit_is_enum_case(array $t, ?int $prev): bool
{
    if ($prev === null || $t[$prev]['id'] !== '=') {
        return false;
    }
    $name = gate_sig($t, $prev, -1);
    if ($name === null || $t[$name]['id'] !== T_STRING) {
        return false;
    }
    $case = gate_sig($t, $name, -1);
    return $case !== null && $t[$case]['id'] === T_CASE;
}

gate_cli_main(__FILE__, 'no-business-literal', 'gate_no_business_literal');
