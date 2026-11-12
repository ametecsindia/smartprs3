<?php
/**
 * NO PII IN LOGS (Part IV §IV.10; spine S13).
 *
 * SmartPRS 2 defect: identity numbers, bank details and salary figures are
 * written to application logs in plaintext, and the log directory is inside the
 * web root on several installs. A log line is a copy of the data with none of
 * the access control.
 *
 * Field list: tools/gates/allow/pii-fields.php. Matching is on names, so
 * $aadhaar, 'pan', ['dob' => ...] and ->bank_acc all trip it. If the value is
 * genuinely needed for support, log an identifier and look it up behind
 * authorisation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/** PSR-3 levels, plus Laravel's Log facade surface. */
const GATE_LOG_METHODS = [
    'log', 'debug', 'info', 'notice', 'warning', 'warn', 'error',
    'critical', 'alert', 'emergency',
];

/** Bare functions that write a log line. */
const GATE_LOG_FUNCS = ['logger', 'error_log', 'syslog', 'dump', 'dd', 'var_dump'];

function gate_no_pii_in_logs(string $root): array
{
    $fields = gate_allow('pii-fields');
    if ($fields === []) {
        return [];
    }
    // Underscores count as boundaries, so employee_pan matches `pan`.
    $pii = '/(?<![a-z0-9])(' . implode('|', array_map('preg_quote', $fields)) . ')(?![a-z0-9])/i';

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
            if ($t[$i]['id'] !== T_STRING) {
                continue;
            }
            $open = gate_sig($t, $i, 1);
            if ($open === null || $t[$open]['id'] !== '(') {
                continue;
            }

            $name = strtolower($t[$i]['text']);
            $prev = gate_sig($t, $i, -1);
            $pid = $prev === null ? null : $t[$prev]['id'];
            $receiver = $prev === null ? '' : gate_text($t, max(0, $prev - 6), $prev);

            $isLogger = match (true) {
                in_array($name, GATE_LOG_FUNCS, true) && !in_array($pid, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true) => true,
                in_array($name, GATE_LOG_METHODS, true)
                    && in_array($pid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
                    && preg_match('/log/i', $receiver) === 1 => true,
                default => false,
            };
            if (!$isLogger) {
                continue;
            }

            $args = gate_text($t, $open, gate_match_paren($t, $open));
            if (preg_match_all($pii, $args, $m)) {
                $hit = implode(', ', array_unique(array_map('strtolower', $m[1])));
                $findings[] = gate_find('no-pii-in-logs', $root, $abs, $t[$i]['line'],
                    "logging call references PII field(s): $hit — log an identifier, not the value");
            }
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'no-pii-in-logs', 'gate_no_pii_in_logs');
