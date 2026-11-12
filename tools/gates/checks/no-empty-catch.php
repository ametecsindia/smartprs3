<?php
/**
 * NO SWALLOWED EXCEPTIONS (Part IV §IV.10; supports Law 4 and Law 5).
 *
 * SmartPRS 2 defect: 426 of 843 catch blocks destroy the exception — empty
 * body, a lone comment, or `return null` / `return false`. A failed statutory
 * calculation and a successful one that produced nothing are indistinguishable,
 * and authorisation failures become "no result" rather than "denied".
 *
 * A catch may swallow only with an explicit, reviewed annotation inside the
 * block:
 *
 *     } catch (FileNotFound $e) {
 *         // gate:allow-swallow probe only; absence is the expected answer
 *     }
 *
 * Every annotation is printed on every run. Suppressions are visible, counted,
 * and reviewable — which is the whole difference between a decision and a leak.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

const GATE_SWALLOW_BODIES = [
    '',
    'return null;',
    'return false;',
    'return;',
    'return true;',
];

function gate_no_empty_catch(string $root): array
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
            if ($t[$i]['id'] !== T_CATCH) {
                continue;
            }
            $open = gate_sig($t, $i, 1);
            if ($open === null || $t[$open]['id'] !== '(') {
                continue;
            }
            $brace = gate_sig($t, gate_match_paren($t, $open), 1);
            if ($brace === null || $t[$brace]['id'] !== '{') {
                continue;
            }

            $depth = 0;
            $end = $n - 1;
            for ($j = $brace; $j < $n; $j++) {
                if ($t[$j]['id'] === '{') {
                    $depth++;
                } elseif ($t[$j]['id'] === '}') {
                    if (--$depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            $code = '';
            $reason = null;
            $hadComment = false;
            for ($j = $brace + 1; $j < $end; $j++) {
                if (in_array($t[$j]['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $hadComment = true;
                    if (preg_match('/gate:allow-swallow\s*(.*?)\s*(?:\*\/)?$/', $t[$j]['text'], $m)) {
                        $reason = trim($m[1]) !== '' ? trim($m[1]) : '(no reason given)';
                    }
                    continue;
                }
                if ($t[$j]['id'] === T_WHITESPACE) {
                    continue;
                }
                $code .= $t[$j]['text'] . ' ';
            }
            $code = trim(preg_replace('/\s+/', ' ', $code));
            $code = str_replace(' ;', ';', $code);

            if (!in_array($code, GATE_SWALLOW_BODIES, true)) {
                if ($reason !== null) {
                    $findings[] = gate_find('no-empty-catch', $root, $abs, $t[$i]['line'],
                        "gate:allow-swallow on a catch that does not swallow — annotation is unnecessary, remove it");
                }
                continue;
            }

            if ($reason !== null) {
                $findings[] = gate_find('no-empty-catch', $root, $abs, $t[$i]['line'],
                    "swallowed exception, allowed: $reason", true);
                continue;
            }

            $what = match (true) {
                $code !== '' => "only `$code`",
                $hadComment => 'only a comment',
                default => 'an empty body',
            };
            $findings[] = gate_find('no-empty-catch', $root, $abs, $t[$i]['line'],
                "catch block with $what destroys the exception — rethrow, wrap, or annotate `// gate:allow-swallow <reason>`");
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'no-empty-catch', 'gate_no_empty_catch');
