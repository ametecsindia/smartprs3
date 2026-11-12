<?php
/**
 * MODULE BOUNDARIES (Part IV §IV.3 and §IV.3.1).
 *
 * "A module owns its tables; no module reads another module's tables." and
 * "each module exposes an explicit contracts namespace; anything else is
 * internal by construction."
 *
 * SmartPRS 2 defect: there are no modules. Payroll reads attendance tables,
 * leave reads payroll tables, and a change to any of them is a whole-system
 * change with no declared surface to keep stable. This gate is the substitute
 * for the network boundary a microservice split would have given.
 *
 * A module may reference another module only through <Root>\<Module>\Contracts\.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

/**
 * The fifteen modules of Part IV §IV.3, as directory names under src/.
 * Directory                 §IV.3 module
 */
const GATE_MODULES = [
    'Platform',        // 1  Platform
    'IdentityAccess',  // 2  Identity & Access
    'Organisation',    // 3  Organisation
    'People',          // 4  People
    'TimeAttendance',  // 5  Time & Attendance
    'Absence',         // 6  Absence
    'Payroll',         // 7  Compensation & Payroll
    'Statutory',       // 8  Statutory
    'MoneyMovement',   // 9  Money Movement
    'Talent',          // 10 Talent
    'Compliance',      // 11 Compliance
    'Communication',   // 12 Communication
    'Integration',     // 13 Integration
    'Configuration',   // 14 Configuration & Rules
    'Audit',           // 15 Audit & Observability
];

const GATE_CONTRACTS = 'Contracts';

function gate_module_boundaries(string $root): array
{
    $files = gate_php_files($root, 'src');
    if ($files === []) {
        return [];
    }

    $findings = [];

    // Top-level directories under src/ are the module list.
    $present = [];
    foreach ($files as $rel => $abs) {
        $present[explode('/', $rel)[1] ?? ''] = true;
    }
    foreach (array_keys($present) as $dir) {
        if ($dir !== '' && !in_array($dir, GATE_MODULES, true)) {
            $findings[] = gate_find('module-boundaries', $root, $root . '/src/' . $dir, 1,
                "src/$dir/ is not one of the fifteen modules declared in Part IV §IV.3");
        }
    }

    // Namespace roots actually in use (PSR-4 maps <Root>\ to src/).
    $roots = [];
    foreach ($files as $rel => $abs) {
        $mod = explode('/', $rel)[1] ?? '';
        $head = (string) file_get_contents($abs, false, null, 0, 4096);
        if (preg_match('/^\s*namespace\s+([^;{]+)/m', $head, $m)) {
            $segs = explode('\\', trim($m[1]));
            $at = array_search($mod, $segs, true);
            if ($at > 0) {
                $roots[implode('\\', array_slice($segs, 0, (int) $at))] = true;
            }
        }
    }

    foreach ($files as $rel => $abs) {
        $own = explode('/', $rel)[1] ?? '';
        $t = gate_tokens($abs);
        foreach ($t as $tok) {
            if (!in_array($tok['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $segs = explode('\\', ltrim($tok['text'], '\\'));
            // Longest matching namespace root first, then module, then surface.
            for ($k = count($segs) - 2; $k >= 1; $k--) {
                if (!isset($roots[implode('\\', array_slice($segs, 0, $k))])) {
                    continue;
                }
                $target = $segs[$k];
                if (!in_array($target, GATE_MODULES, true) || $target === $own) {
                    break;
                }
                if (($segs[$k + 1] ?? '') !== GATE_CONTRACTS) {
                    $findings[] = gate_find('module-boundaries', $root, $abs, $tok['line'],
                        "$own reaches into $target internals via `{$tok['text']}`"
                        . " — cross-module access goes through $target\\" . GATE_CONTRACTS . '\\ only');
                }
                break;
            }
        }
    }

    return $findings;
}

gate_cli_main(__FILE__, 'module-boundaries', 'gate_module_boundaries');
