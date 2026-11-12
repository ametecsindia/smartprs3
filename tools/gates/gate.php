<?php
/**
 * SmartPRS 3 enforcement gates — orchestrator.
 *
 *   php tools/gates/gate.php
 *   php tools/gates/gate.php --only=no-empty-catch,no-float-money
 *   php tools/gates/gate.php --format=json
 *   php tools/gates/gate.php --fix-hint
 *   php tools/gates/gate.php --root=/path/to/checkout
 *
 * Exit 0 = clean, 1 = violations, 2 = usage error.
 *
 * Every law in spine S1 has a mechanical gate (Part IV §IV.10). A law without a
 * gate is a wish; each of these exists because its absence produced a confirmed
 * defect in SmartPRS 2. See README.md for the defect behind each one.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** name => [law, one-line purpose, fix hint]. The order is the report order. */
const GATE_CHECKS = [
    'no-business-literal' => ['Law 1', 'no business literal in Domain', 'Move the value into configuration, or add it to tools/gates/allow/literals.php with a reason.'],
    'no-runtime-ddl' => ['Law 2', 'no DDL outside database/migrations', 'Write a migration. Custom fields are rows, never columns. No allow-list exists for this one.'],
    'db-privilege' => ['Law 2', 'application DB account cannot execute DDL', 'REVOKE ALTER, CREATE, DROP, INDEX from the application account; run migrations as a separate deploy account.'],
    'no-float-money' => ['Law 7', 'money never touches a float', 'Use the Money value object in integer paise. Declare rounding on the component, apply it at the declared point.'],
    'no-empty-catch' => ['Law 4/5', 'no swallowed exceptions', 'Rethrow, or wrap in a domain exception that carries the cause. If swallowing is genuinely right, annotate `// gate:allow-swallow <reason>`.'],
    'no-clock-in-domain' => ['Law 3/4', 'domain is deterministic', 'Inject a Clock (and an IdGenerator) through the constructor and ask it for the instant.'],
    'no-framework-in-domain' => ['—', 'domain has no framework', 'Move the framework touch to the interface or persistence layer and pass a value object across.'],
    'module-boundaries' => ['—', 'modules talk through Contracts only', 'Depend on the other module\'s Contracts\\ namespace, or move the shared concept into Configuration.'],
    'tenant-column' => ['Law 6', 'tenant_id NOT NULL, leading every unique index', 'Add tenant_id to the create() block and put it first in each ->unique([...]), or allow-list the table in tools/gates/allow/platform-tables.php.'],
    'no-pii-in-logs' => ['—', 'no PII in log lines', 'Log an identifier and resolve it behind authorisation, or push the field through the redaction layer.'],
    'strict-types' => ['1/7', 'every shipped file declares strict_types=1', 'Add `declare(strict_types=1);` after the opening tag. Without it PHP coerces, and every unit distinction in the platform is enforced only in strict mode. There is no allow-list.'],
    'min-php-version' => ['—', 'source honours the PHP floor composer.json declares', 'Rewrite using a construct the declared floor supports, or raise the floor in composer.json deliberately. There is no allow-list.'],
    'deptrac-coverage' => ['—', 'every class is in a layer on both deptrac axes', 'Add the namespace to a layer in deptrac.layers.yaml (and deptrac.modules.yaml), or say why it belongs to none. Both configs set ignore_uncovered_internal_classes: false, so an uncovered class is a violation there too.'],
];

$argv = $_SERVER['argv'];
$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "gate: unknown argument '$a'\n");
        exit(2);
    }
}

if (isset($opt['help'])) {
    echo "usage: php tools/gates/gate.php [--only=NAME[,NAME]] [--format=text|json] [--fix-hint] [--root=PATH]\n\nChecks:\n";
    foreach (GATE_CHECKS as $name => [$law, $what]) {
        // mb_str_pad: the law column contains an em dash, so byte padding skews.
        echo '  ' . str_pad($name, 24) . mb_str_pad($law, 9) . $what . "\n";
    }
    echo "\nEach check also runs on its own: php tools/gates/checks/<name>.php\n";
    exit(0);
}

$format = $opt['format'] ?? 'text';
if (!in_array($format, ['text', 'json'], true)) {
    fwrite(STDERR, "gate: --format must be text or json\n");
    exit(2);
}

$only = isset($opt['only']) && is_string($opt['only'])
    ? array_map('trim', explode(',', $opt['only']))
    : null;
if ($only !== null) {
    $unknown = array_diff($only, array_keys(GATE_CHECKS));
    if ($unknown !== []) {
        fwrite(STDERR, 'gate: unknown check(s): ' . implode(', ', $unknown) . "\n");
        exit(2);
    }
}

$root = gate_root_from_argv($argv);
$t0 = microtime(true);
$violations = [];
$suppressed = [];

foreach (GATE_CHECKS as $name => $meta) {
    if ($only !== null && !in_array($name, $only, true)) {
        continue;
    }
    require_once __DIR__ . '/checks/' . $name . '.php';
    $fn = 'gate_' . str_replace('-', '_', $name);
    foreach ($fn($root) as $f) {
        if ($f['suppressed']) {
            $suppressed[] = $f;
        } else {
            $violations[] = $f;
        }
    }
}

$ran = $only ?? array_keys(GATE_CHECKS);
$elapsed = round(microtime(true) - $t0, 2);

if ($format === 'json') {
    echo json_encode([
        'root' => $root,
        'checks' => $ran,
        'violations' => $violations,
        'suppressions' => $suppressed,
        'summary' => [
            'violations' => count($violations),
            'suppressions' => count($suppressed),
            'seconds' => $elapsed,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($violations === [] ? 0 : 1);
}

echo "SmartPRS 3 gates — root $root\n\n";

if ($violations !== []) {
    usort($violations, static fn($a, $b) => [$a['check'], $a['file'], $a['line']] <=> [$b['check'], $b['file'], $b['line']]);
    $last = null;
    foreach ($violations as $f) {
        if ($f['check'] !== $last) {
            $last = $f['check'];
            [$law, $what] = GATE_CHECKS[$last];
            echo "FAIL  $last  ($law — $what)\n";
        }
        printf("      %s:%d  %s\n", $f['file'], $f['line'], $f['msg']);
    }
    if (isset($opt['fix-hint'])) {
        echo "\nHow to fix:\n";
        foreach (array_unique(array_column($violations, 'check')) as $c) {
            echo "  $c\n    " . GATE_CHECKS[$c][2] . "\n";
        }
    }
    echo "\n";
}

if ($suppressed !== []) {
    echo 'Suppressions (' . count($suppressed) . " — visible on purpose, review them):\n";
    foreach ($suppressed as $f) {
        printf("      %s:%d  %s  %s\n", $f['file'], $f['line'], $f['check'], $f['msg']);
    }
    echo "\n";
}

printf(
    "%s  %d check(s), %d violation(s), %d suppression(s), %ss\n",
    $violations === [] ? 'PASS' : 'FAIL',
    count($ran),
    count($violations),
    count($suppressed),
    $elapsed
);

exit($violations === [] ? 0 : 1);
