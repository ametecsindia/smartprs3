<?php
/**
 * DOMAIN IS DETERMINISTIC (Part IV §IV.2.1: "Pure. Deterministic. No framework,
 * no I/O, no clock.").
 *
 * SmartPRS 2 defect: payroll and attendance derivations read the wall clock and
 * the PHP timezone directly, so a run cannot be reproduced, a re-run of a closed
 * period produces different numbers, and no result can be replayed to defend an
 * audit query (Law 4).
 *
 * Domain code takes an injected clock and an injected id/token source.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

const GATE_CLOCK_FUNCS = [
    'now' => 'the ambient clock',
    'time' => 'the ambient clock',
    'date' => 'the ambient clock and the ambient timezone',
    'mktime' => 'the ambient clock',
    'microtime' => 'the ambient clock',
    'mt_rand' => 'ambient randomness',
    'rand' => 'ambient randomness',
    'random_int' => 'ambient randomness',
    'random_bytes' => 'ambient randomness',
    'uniqid' => 'ambient randomness',
];

const GATE_CLOCK_CLASSES = '/^\\\\?(DateTime|DateTimeImmutable|Carbon|CarbonImmutable|(.*\\\\)?Carbon(Immutable)?)$/';

function gate_no_clock_in_domain(string $root): array
{
    $domain = gate_domain_files($root);
    if ($domain === []) {
        return gate_vacuous('no-clock-in-domain', $root,
            'no src/<Module>/Domain/ directory exists yet — Phase 2 builds the Platform kernel, whose namespaces are not the module/layer convention of Part IV §IV.2.1. This check reads nothing until the first module lands (Part XXXII Phase 3).');
    }

    $findings = [];
    foreach ($domain as $abs) {
        $t = gate_tokens($abs);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];

            // new DateTime / new Carbon / new DateTimeImmutable
            if ($tok['id'] === T_NEW) {
                $cls = gate_sig($t, $i, 1);
                if ($cls !== null && preg_match(GATE_CLOCK_CLASSES, $t[$cls]['text'])) {
                    $findings[] = gate_find('no-clock-in-domain', $root, $abs, $tok['line'],
                        "new {$t[$cls]['text']} reads the ambient clock — take an injected Clock and ask it for the instant");
                }
                continue;
            }

            if ($tok['id'] !== T_STRING && $tok['id'] !== T_NAME_QUALIFIED && $tok['id'] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }

            $prev = gate_sig($t, $i, -1);
            $pid = $prev === null ? null : $t[$prev]['id'];
            $next = gate_sig($t, $i, 1);
            $nid = $next === null ? null : $t[$next]['id'];

            // Carbon::now(), CarbonImmutable::today(), \Carbon\Carbon::parse()
            if ($nid === T_DOUBLE_COLON && preg_match(GATE_CLOCK_CLASSES, $tok['text'])) {
                $m = gate_sig($t, $next, 1);
                $call = $m === null ? '' : $t[$m]['text'];
                $findings[] = gate_find('no-clock-in-domain', $root, $abs, $tok['line'],
                    "{$tok['text']}::{$call}() in the domain layer — the clock is injected, never reached for");
                continue;
            }

            if ($tok['id'] !== T_STRING || $nid !== '(') {
                continue;
            }
            $fn = strtolower($tok['text']);
            if (!isset(GATE_CLOCK_FUNCS[$fn])) {
                continue;
            }
            // A method call or a declaration of the same name is not the builtin.
            if (in_array($pid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            $findings[] = gate_find('no-clock-in-domain', $root, $abs, $tok['line'],
                "{$tok['text']}() uses " . GATE_CLOCK_FUNCS[$fn] . ' — domain results must be reproducible from their inputs');
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'no-clock-in-domain', 'gate_no_clock_in_domain');
