<?php
/**
 * EVERY CLASS IS IN A LAYER, ON BOTH AXES.
 *
 * `deptrac.layers.yaml` and `deptrac.modules.yaml` both set
 * `ignore_uncovered_internal_classes: false`, which says: a class in no layer
 * is a hole in the config, not a pass. Deptrac enforces that — but deptrac
 * needs Composer, and packagist is unreachable in the authoring environment, so
 * the configs went unrun while the codebase grew five namespaces past them.
 * Formula, Workflow, Workflow\Sla, Workflow\Persistence and Scheduler were all
 * uncovered, and nothing said so.
 *
 * WHAT THIS CHECKS, AND WHAT IT DOES NOT. It reads the `value:` regexes out of
 * both configs and asserts that every class under src/ is matched by at least
 * one of them. That is coverage, and it is the one failure mode that has
 * actually happened here.
 *
 * It does NOT read the layer structure — `must` versus `must_not`, which layer
 * a pattern belongs to — and so it does NOT check the ruleset, ambiguity
 * between layers, or a single dependency edge. Deptrac does all of that, and
 * this does not pretend to. A pattern under `must_not` still counts as coverage
 * here, which is a deliberate under-approximation: it can report a false PASS
 * on a class that is excluded from every layer it might belong to, and never a
 * false violation.
 *
 * ponytail: the configs are read as text with one regex, not parsed as YAML —
 * there is no yaml extension and adding a parser for two files this check reads
 * one line of would be the wrong trade. Ceiling: a collector written across two
 * lines, or with double quotes, is not seen. Both configs are checked for at
 * least one pattern each so that a reformat which breaks the extraction shows
 * up as a skip rather than as silent coverage.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';

const GATE_DEPTRAC_CONFIGS = ['deptrac.layers.yaml', 'deptrac.modules.yaml'];

function gate_deptrac_coverage(string $root): array
{
    $findings = [];
    $classes = gate_deptrac_classes($root);

    if ($classes === []) {
        return gate_vacuous('deptrac-coverage', $root, 'no classes were found under src/');
    }

    foreach (GATE_DEPTRAC_CONFIGS as $config) {
        $path = rtrim($root, '/') . '/' . $config;

        if (!is_file($path)) {
            $findings[] = gate_find('deptrac-coverage', $root, $path, 1,
                "SKIPPED: {$config} does not exist", true);

            continue;
        }

        $patterns = gate_deptrac_patterns((string) file_get_contents($path));

        if ($patterns === []) {
            $findings[] = gate_find('deptrac-coverage', $root, $path, 1,
                "SKIPPED: no collector patterns could be read out of {$config} — the file's shape "
                . 'changed and this check is now reading nothing', true);

            continue;
        }

        foreach ($classes as $class => $file) {
            if (!gate_deptrac_matches($class, $patterns)) {
                $findings[] = gate_find('deptrac-coverage', $root, $file, 1,
                    "{$class} is in no layer of {$config} — that config sets "
                    . 'ignore_uncovered_internal_classes: false, so deptrac reports it as a violation. '
                    . 'Add it to a layer, or say why it belongs to none.');
            }
        }
    }

    if ($findings === []) {
        $findings[] = gate_find('deptrac-coverage', $root, $root . '/deptrac.layers.yaml', 1,
            'OK: all ' . count($classes) . ' class(es) under src/ are covered by both axes', true);
    }

    return $findings;
}

/**
 * The `value: '...'` regexes in a deptrac config.
 *
 * @return list<string>
 */
function gate_deptrac_patterns(string $yaml): array
{
    preg_match_all("/value:\s*'([^']+)'/", $yaml, $matches);

    return $matches[1];
}

/** @param list<string> $patterns */
function gate_deptrac_matches(string $class, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        // Deptrac's classLike value is a regex over the fully-qualified name,
        // written with the YAML-escaped backslashes it needs. Compiled with a
        // delimiter it never contains.
        if (@preg_match('~' . $pattern . '~', $class) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Fully-qualified class names under src/, from the PSR-4 path.
 *
 * Read from the path rather than the tokens because that is what deptrac's
 * classLike collector matches against, and because a file whose namespace
 * disagrees with its path is a different defect that composer's autoloader
 * reports first.
 *
 * @return array<string, string> class => absolute file
 */
function gate_deptrac_classes(string $root): array
{
    $classes = [];

    foreach (gate_php_files($root, 'src') as $relative => $absolute) {
        $name = substr($relative, strlen('src/'), -strlen('.php'));

        // catalogue.php and its like return arrays and declare no class.
        if (!preg_match('/^[A-Z]/', basename($name))) {
            continue;
        }

        $classes['SmartPRS\\' . str_replace('/', '\\', $name)] = $absolute;
    }

    ksort($classes);

    return $classes;
}

gate_cli_main(__FILE__, 'deptrac-coverage', 'gate_deptrac_coverage');
