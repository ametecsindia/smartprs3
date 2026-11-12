<?php
/**
 * LAW 7 — MONEY IS EXACT (spine S1).
 *
 * SmartPRS 2 defect: a float comparison decides whether a loan instalment is
 * deducted, so an instalment silently stops being recovered when the residual
 * balance lands a fraction of a paisa away from the comparand.
 *
 * Money is integer minor units in a value object. Floats never touch it.
 *
 * Scanned: ALL of src/. It used to scan src/*_/Domain/ plus files whose path or
 * namespace mentioned Money, Payroll, Salary, Payslip, Ledger or Statutory —
 * which on the real tree selected exactly one file out of 116, because no
 * Domain/ directory exists yet and only Money.php is named after money. A
 * name-shaped filter cannot know that Rational is the money arithmetic.
 *
 * Scanning everything is also the simpler rule, and it is the position
 * composer.json's `lint:no-float` already takes over the whole of src/. A float
 * has no legitimate use in this codebase; the two that existed when this widened
 * were both better as int. If one ever is legitimate, it goes in
 * tools/gates/allow/floats.php with a reason, like every other exception.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/**
 * Heuristic for "an argument that is money". round() and number_format() are
 * fine on a page count; they are a Law 7 violation on an amount.
 * ponytail: name-based, not type-inferred. Add a PHPStan rule over the real
 * Money type once composer install is possible — see phpstan.neon.
 */
const GATE_MONEY_ARG = '/\bMoney\b|->(amount|gross|net|toFloat|asFloat)\s*\(|\$\w*(money|amount|gross|net|salary|wage|paise|ctc|deduction|earning)\w*/i';

function gate_no_float_money(string $root): array
{
    $findings = [];
    // THE APPLICATION IS SCANNED TOO, and until this line it was not. This gate
    // rooted itself at src/ and reported PASS over fourteen thousand lines of
    // surface/ and public/ — the code that actually serves every screen. A gate
    // that says PASS about a directory it never opened is the exact failure this
    // project's standing rule names: a check that silently checks nothing is worse
    // than a missing check, because the missing one does not tell you it is fine.
    foreach (array_merge(gate_php_files($root, 'src'), gate_php_files($root, 'surface'),
        gate_php_files($root, 'public')) as $abs) {
        $t = gate_tokens($abs);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            $line = $tok['line'];

            if ($tok['id'] === T_DOUBLE_CAST) {
                $findings[] = gate_find('no-float-money', $root, $abs, $line,
                    "float cast {$tok['text']} — convert through the Money value object, in paise");
                continue;
            }

            if ($tok['id'] === T_STRING) {
                $lc = strtolower($tok['text']);
                $prev = gate_sig($t, $i, -1);
                $pid = $prev === null ? null : $t[$prev]['id'];

                if (in_array($lc, ['float', 'double'], true)
                    && !in_array($pid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                    $findings[] = gate_find('no-float-money', $root, $abs, $line,
                        "'{$tok['text']}' type declaration in a money path — money is int minor units");
                    continue;
                }

                if ($lc === 'floatval' && $pid !== T_OBJECT_OPERATOR && $pid !== T_DOUBLE_COLON) {
                    $findings[] = gate_find('no-float-money', $root, $abs, $line,
                        'floatval() in a money path');
                    continue;
                }

                if (in_array($lc, ['round', 'number_format'], true)
                    && !in_array($pid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true)) {
                    $open = gate_sig($t, $i, 1);
                    if ($open !== null && $t[$open]['id'] === '(') {
                        $args = gate_text($t, $open, gate_match_paren($t, $open));
                        if (preg_match(GATE_MONEY_ARG, $args)) {
                            $findings[] = gate_find('no-float-money', $root, $abs, $line,
                                "{$tok['text']}() applied to a money value — rounding is declared per component and applied at declared points");
                        }
                    }
                }
                continue;
            }

            if ($tok['id'] === T_IS_EQUAL || $tok['id'] === T_IS_NOT_EQUAL) {
                $l = gate_sig($t, $i, -1);
                $r = gate_sig($t, $i, 1);
                $isFloat = static fn(?int $j): bool => $j !== null && $t[$j]['id'] === T_DNUMBER;
                if ($isFloat($l) || $isFloat($r)) {
                    $findings[] = gate_find('no-float-money', $root, $abs, $line,
                        "'{$tok['text']}' comparison against a float literal — this is the SmartPRS 2 loan-instalment defect");
                }
            }
        }
    }

    // THE ESCAPE HATCH THIS FILE'S OWN DOCBLOCK PROMISED, which did not exist. It
    // said "if one ever is legitimate, it goes in tools/gates/allow/floats.php with
    // a reason" — and nothing here ever read that file, so an entry in it was inert
    // and the only way past this gate was to contort correct code. Pointing the
    // gate at surface/ and public/ made that matter: a bar's width and a page
    // count are floats in display code and are not money, and the choice between
    // a silent allow-file that does nothing and no allow-file at all is not a
    // choice anybody should have to make while trying to get a check green.
    //
    // An allowed line is SUPPRESSED, not dropped: the runner counts and can print
    // every one, so the list cannot quietly become the rule.
    // KEYED BY WHAT THE LINE SAYS, NOT BY WHERE IT IS.
    //
    // These were file:line, which is the obvious choice and is wrong for a 14,000
    // line front controller: any edit ABOVE an allowed line renumbers it, the entry
    // stops matching, and the gate reports six lines it had been told to ignore.
    // That happened twice in one afternoon, and the fix each time was to renumber
    // entries without re-reading them — which is the exact ritual that turns an
    // allow-list into a rubber stamp.
    //
    // The text of the line is the stable identity, and it keeps the property the
    // line number was chosen for: change what the line DOES and the entry stops
    // matching, so somebody has to look again. Whitespace is normalised so
    // reindenting is not a re-review; everything else must match exactly.
    $allowed = gate_allow('floats', ['lines' => [], 'notes' => []]);
    $normalise = static fn (string $text): string
        => trim((string) preg_replace('/\s+/', ' ', $text));
    $sources = [];

    foreach ($findings as $i => $finding) {
        $path = $root . '/' . $finding['file'];
        $sources[$finding['file']] ??= file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $text = $normalise($sources[$finding['file']][$finding['line'] - 1] ?? '');
        $key = $finding['file'] . '|' . $text;

        if (isset($allowed['lines'][$key])) {
            $findings[$i]['suppressed'] = true;
            $findings[$i]['msg'] .= ' — allowed: ' . $allowed['lines'][$key];
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'no-float-money', 'gate_no_float_money');
