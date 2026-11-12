<?php
/**
 * Proof that the gates work.
 *
 *   php tools/gates/self-test.php [-v]
 *
 * For every check there is a violating tree and a clean tree under fixtures/.
 * Each check must flag the violating one and pass the clean one. A gate nobody
 * has seen fail is not a gate.
 *
 * Exit 0 = every assertion held.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$verbose = in_array('-v', $_SERVER['argv'], true);
$fixtures = __DIR__ . '/fixtures';
$repoRoot = dirname(__DIR__, 2);

/** check => minimum distinct violations expected in the bad tree. */
const GATE_SELFTEST = [
    'no-business-literal' => 5,
    'no-runtime-ddl' => 3,
    'no-float-money' => 5,
    'no-empty-catch' => 3,
    'no-clock-in-domain' => 6,
    'no-framework-in-domain' => 3,
    'module-boundaries' => 3,
    'tenant-column' => 4,
    'no-pii-in-logs' => 3,
    'min-php-version' => 2,
    'strict-types' => 2,
    // Two: the orphan class is uncovered on BOTH axes, and each config is
    // reported separately so the fix is unambiguous.
    'deptrac-coverage' => 2,
];

$pass = 0;
$fail = 0;

function gate_assert(bool $ok, string $what, string $detail = ''): void
{
    global $pass, $fail, $verbose;
    if ($ok) {
        $pass++;
        if ($verbose) {
            echo "  ok    $what\n";
        }
        return;
    }
    $fail++;
    echo "  FAIL  $what" . ($detail !== '' ? "\n        $detail" : '') . "\n";
}

echo "SmartPRS 3 gate self-test\n\n";

foreach (GATE_SELFTEST as $name => $minimum) {
    require_once __DIR__ . '/checks/' . $name . '.php';
    $fn = 'gate_' . str_replace('-', '_', $name);
    echo "$name\n";

    foreach (['bad', 'good'] as $tree) {
        $root = realpath("$fixtures/$name/$tree");
        if ($root === false) {
            gate_assert(false, "$name: fixtures/$name/$tree exists");
            continue;
        }
        $findings = $fn($root);
        $violations = array_values(array_filter($findings, static fn($f) => !$f['suppressed']));
        $lines = implode('; ', array_map(
            static fn($f) => "{$f['file']}:{$f['line']} {$f['msg']}",
            array_slice($violations, 0, 8)
        ));

        if ($tree === 'bad') {
            gate_assert(
                count($violations) >= $minimum,
                "$name flags the violating fixture (>= $minimum findings)",
                'got ' . count($violations) . ': ' . $lines
            );
            $files = array_unique(array_column($violations, 'file'));
            gate_assert(
                $files !== [] && count($files) === count(array_filter($files, static fn($f) => !str_contains($f, '..'))),
                "$name reports paths relative to the scanned root",
                implode(', ', $files)
            );
            if ($verbose) {
                foreach ($violations as $v) {
                    echo "        - {$v['file']}:{$v['line']} {$v['msg']}\n";
                }
            }
        } else {
            gate_assert(
                $violations === [],
                "$name passes the clean fixture",
                'got ' . count($violations) . ': ' . $lines
            );
        }
    }
}

// db-privilege has no file fixture: it is a runtime check. What must hold is
// that an unconfigured checkout skips loudly instead of failing the build.
echo "db-privilege\n";
require_once __DIR__ . '/checks/db-privilege.php';
$dbRoot = realpath("$fixtures/db-privilege/good") ?: $fixtures;
$db = gate_db_privilege($dbRoot);
gate_assert(
    array_filter($db, static fn($f) => !$f['suppressed']) === [],
    'db-privilege does not fail a checkout with no database configured',
    implode('; ', array_column($db, 'msg'))
);
gate_assert(
    $db !== [] && $db[0]['suppressed'],
    'db-privilege reports why it skipped rather than passing silently',
    implode('; ', array_column($db, 'msg'))
);
if ($verbose) {
    echo '        - ' . ($db[0]['msg'] ?? '(nothing)') . "\n";
}

// The append-only half of that gate CAN be exercised without a database,
// because the part most likely to be wrong is the grant parsing rather than the
// connection. MySQL grants are cumulative across scopes, so an account holding
// DELETE on *.* holds it on audit_entries whatever the table-specific grant says
// — and a gate that read only the narrow grant would pass the exact account
// almost every installation actually has.
$global = ['GRANT SELECT, INSERT, UPDATE, DELETE ON *.* TO `app`@`%`'];
$scoped = [
    'GRANT USAGE ON *.* TO `app`@`%`',
    'GRANT SELECT, INSERT, UPDATE, DELETE ON `smartprs3`.* TO `app`@`%`',
    'GRANT SELECT, INSERT ON `smartprs3`.`audit_entries` TO `app`@`%`',
];
$correct = [
    'GRANT USAGE ON *.* TO `app`@`%`',
    'GRANT SELECT, INSERT, UPDATE ON `smartprs3`.* TO `app`@`%`',
    'GRANT SELECT, INSERT ON `smartprs3`.`audit_entries` TO `app`@`%`',
];

gate_assert(
    in_array('DELETE', gate_db_effective_privileges($global, 'smartprs3', 'audit_entries'), true),
    'db-privilege sees DELETE granted on *.* as DELETE on audit_entries',
    implode(', ', gate_db_effective_privileges($global, 'smartprs3', 'audit_entries')),
);
gate_assert(
    in_array('DELETE', gate_db_effective_privileges($scoped, 'smartprs3', 'audit_entries'), true),
    'and a narrow table grant does NOT revoke a wider database-level one — grants are cumulative, '
    . 'so the union is what the account can actually do',
);
gate_assert(
    !in_array('DELETE', gate_db_effective_privileges($correct, 'smartprs3', 'audit_entries'), true)
    && in_array('UPDATE', gate_db_effective_privileges($correct, 'smartprs3', 'workflow_tasks'), true),
    'the correct grant passes: no DELETE anywhere, and UPDATE still held on workflow_tasks, which '
    . 'the conditional decision needs',
);
gate_assert(
    gate_db_effective_privileges(['GRANT USAGE ON *.* TO `app`@`%`'], 'smartprs3', 'audit_entries') === [],
    'USAGE is not a privilege and is not counted as one',
);

// A check that reads nothing must say so. This is the assertion that would have
// caught four gates passing over zero files: no-business-literal, no-clock-in-
// domain and no-framework-in-domain select src/<Module>/Domain/, and the real
// tree has no such directory yet.
echo "vacuity\n";
$empty = sys_get_temp_dir() . '/gate-selftest-empty-' . getmypid();
@mkdir($empty . '/src/Platform/Support', 0777, true);
file_put_contents($empty . '/src/Platform/Support/Money.php', "<?php\ndeclare(strict_types=1);\n");

foreach (['no-business-literal', 'no-clock-in-domain', 'no-framework-in-domain'] as $name) {
    $fn = 'gate_' . str_replace('-', '_', $name);
    $findings = $fn($empty);
    gate_assert(
        count($findings) === 1 && $findings[0]['suppressed'] && str_starts_with($findings[0]['msg'], 'SKIPPED:'),
        "$name reports that it read nothing, rather than passing silently",
        'got ' . count($findings) . ' finding(s): ' . implode('; ', array_column($findings, 'msg'))
    );
}

// And the one that reads all of src is never vacuous on the same tree.
require_once __DIR__ . '/checks/no-float-money.php';
gate_assert(
    gate_php_files($empty, 'src') !== [],
    'no-float-money reads every file under src/, so it cannot go quiet the same way'
);

@unlink($empty . '/src/Platform/Support/Money.php');
@rmdir($empty . '/src/Platform/Support');
@rmdir($empty . '/src/Platform');
@rmdir($empty . '/src');
@rmdir($empty);

// The fixtures are deliberate violations. A real run must never see them.
echo "exclusions\n";
$real = gate_php_files($repoRoot);
$leaked = array_filter(array_keys($real), static fn(string $r) => str_contains($r, 'tools/gates/fixtures'));
gate_assert($leaked === [], 'fixtures are excluded from a real run', implode(', ', $leaked));

$suppression = null;
require_once __DIR__ . '/checks/no-business-literal.php';
foreach (gate_no_business_literal(realpath("$fixtures/no-business-literal/good")) as $f) {
    if ($f['suppressed']) {
        $suppression = $f;
    }
}
gate_assert($suppression !== null, 'allow-listed literals are reported as visible suppressions, not hidden');

echo "\n";
// PHPStan does not track gate_assert()'s `global $fail; $fail++;` mutation,
// so it sees $fail as always the literal 0 assigned above and misreports
// both comparisons below as always-true. $fail really is incremented on
// every failed assertion — see gate_assert().
/** @var int $fail */
printf("%s  %d assertion(s), %d failure(s)\n", $fail === 0 ? 'PASS' : 'FAIL', $pass + $fail, $fail);
exit($fail === 0 ? 0 : 1);
