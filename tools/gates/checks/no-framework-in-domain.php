<?php
/**
 * DOMAIN HAS NO FRAMEWORK (Part IV §IV.2.1 and §IV.3.1: "Domain may not
 * reference Http, Illuminate, or another module's internals.").
 *
 * SmartPRS 2 defect: there is no domain layer. Calculation lives in controllers,
 * reads the Request, writes with the query builder and returns a Response, so no
 * payroll rule can be unit-tested, reused by a job, or replayed. The engines
 * SmartPRS 3 needs cannot exist inside a framework class.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/** Namespace roots that are the framework. */
const GATE_FW_ROOTS = ['Illuminate', 'Laravel'];

/** Name segments that are the framework's surface, wherever they appear. */
const GATE_FW_SEGMENTS = ['Http', 'Request', 'Response', 'Eloquent', 'Model', 'DB', 'Facade'];

function gate_no_framework_in_domain(string $root): array
{
    $domain = gate_domain_files($root);
    if ($domain === []) {
        return gate_vacuous('no-framework-in-domain', $root,
            'no src/<Module>/Domain/ directory exists yet — Phase 2 builds the Platform kernel, whose namespaces are not the module/layer convention of Part IV §IV.2.1. This check reads nothing until the first module lands (Part XXXII Phase 3).');
    }

    $findings = [];
    foreach ($domain as $abs) {
        $t = gate_tokens($abs);
        $n = count($t);

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if (!in_array($tok['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $prev = gate_sig($t, $i, -1);
            $pid = $prev === null ? null : $t[$prev]['id'];
            // `$x->request` / `Foo::model` are properties and methods, not types.
            if (in_array($pid, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $name = ltrim($tok['text'], '\\');
            $segs = explode('\\', $name);

            if (in_array($segs[0], GATE_FW_ROOTS, true)) {
                $findings[] = gate_find('no-framework-in-domain', $root, $abs, $tok['line'],
                    "framework reference `$name` in the domain layer — the domain depends on the domain only");
                continue;
            }

            foreach ($segs as $s) {
                if (in_array($s, GATE_FW_SEGMENTS, true)) {
                    $findings[] = gate_find('no-framework-in-domain', $root, $abs, $tok['line'],
                        "`$name` references the framework surface (`$s`) inside the domain layer"
                        . ' — pass a value object in, return a value object out');
                    continue 2;
                }
            }
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'no-framework-in-domain', 'gate_no_framework_in_domain');
