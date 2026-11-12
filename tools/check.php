<?php
declare(strict_types=1);

/**
 * One command that runs every verification this project has, in order, and
 * reports a single verdict.
 *
 *   php tools/check.php            everything
 *   php tools/check.php --fast     everything except the slow ones (smoke, exposure, scope)
 *
 * Exit-code policy — the point of this file:
 *
 *   PASS  the check ran and was happy.
 *   FAIL  the check ran and was not.                       -> exit 1
 *   SKIP  the check COULD NOT RUN. Never counted as a pass.
 *
 * A skip is printed loudly but does not by itself fail the run, because a
 * tool that was never installed (phpstan without vendor/) has not regressed
 * anything — failing there would only teach people to ignore the failure.
 *
 * The exceptions are smoke, exposure and scope. They are the checks that find the most
 * defects and are the easiest to lose silently: both need a database, and "no
 * database" looks exactly like "nothing to report" if you let it. So an
 * *accidental* skip of either on a full run is fatal (exit 1). Asking for it
 * with --fast is a deliberate choice and only exits 0. That is the whole rule:
 * you may decline to run them, you may not fail to notice that they did not run.
 *
 * Exposure earns that treatment for the same reason smoke does, one turn worse:
 * it signs in as an ordinary employee and tries to read a colleague's salary. A
 * run that could not create the account, could not sign in, or found no data to
 * hunt for reports no leaks — which is indistinguishable, on the screen, from
 * finding none.
 *
 * Scope is the same trick pointed at the other question. Exposure asks "did a
 * secret come back?"; scope asks "whose row is this?" — it signs in AS one
 * employment and asks every route about another one. It refuses for the same
 * reasons, plus one of its own: if the viewer's own record appeared on no screen
 * at all, every `clean` was a screen showing nobody.
 */

$root = dirname(__DIR__);
$fast = in_array('--fast', array_slice($argv, 1), true);

$php = PHP_BINARY;
$vendor = $root . '/vendor/bin';

// name, argv, slow?, and — for the tools that may simply not be installed —
// a reason string when they are unrunnable. Empty reason means "runnable".
$checks = [
    ['tests',   [$php, $root . '/tests/run.php'],           false, ''],
    ['gates',   [$php, $root . '/tools/gates/gate.php'],    false, ''],
    ['phpstan', [$vendor . '/phpstan', 'analyse', '--memory-limit=1G', '--no-progress'], false,
        is_executable($vendor . '/phpstan') ? '' : 'no vendor/bin/phpstan — run `composer install`'],
    ['deptrac:layers', [$vendor . '/deptrac', 'analyse', '--config-file=' . $root . '/deptrac.layers.yaml'], false,
        is_executable($vendor . '/deptrac') ? '' : 'no vendor/bin/deptrac — run `composer install`'],
    ['deptrac:modules', [$vendor . '/deptrac', 'analyse', '--config-file=' . $root . '/deptrac.modules.yaml'], false,
        is_executable($vendor . '/deptrac') ? '' : 'no vendor/bin/deptrac — run `composer install`'],
    ['smoke',   [$php, $root . '/tools/smoke.php'],         true,  ''],
    ['exposure', [$php, $root . '/tools/exposure.php'],     true,  ''],
    ['scope',   [$php, $root . '/tools/scope.php'],         true,  ''],
    // THE SAME SWEEP FROM THE OTHER SIDE, and it is not optional. The run above
    // signs in as a viewer, so every screen behind a permission that viewer lacks
    // is a refusal it can learn nothing from — and a row guard that refuses
    // EVERYBODY produces exactly that silence while reading as a pass. This one
    // signs in as somebody who should see the colleague and fails when they do
    // not. It found four guards granted to no role at all.
    ['scope:tenant-wide', [$php, $root . '/tools/scope.php', '--role=administrator',
        '--tenant-wide'],                                   true,  ''],
    // THE ROLE IN THE MIDDLE, which is where a scope goes wrong. A viewer reaches
    // nothing and an administrator reaches everything, so neither of them exercises
    // a scope that is supposed to stop somewhere — and a manager's permissions are
    // the administrator's permissions on a smaller set of rows, which is exactly
    // the case a permission-only check cannot see. Declaring the role handed the
    // manager the whole tenant, and this run found nine reads on the first try.
    ['scope:manager', [$php, $root . '/tools/scope.php', '--role=manager'], true, ''],
    // EIGHTY-FOUR SCREENS BEHIND ONE ROUTE. Visiting `?r=m` once exercises whichever
    // module comes first and says nothing about the other eighty-three, and every
    // defect this engine has had was a column one declaration named and one table
    // did not have. This attempts a real insert through every module — inside a
    // transaction that is rolled back — and reads the declarations against the live
    // schema. It found five modules whose forms rendered perfectly and answered a
    // save with a raw SQLSTATE naming a constraint the person had never seen.
    ['modules', [$php, $root . '/tools/modules-writable.php'], true, ''],
    // THE SURFACE WITH NO SESSION. `exposure` and `scope` both sign a browser in,
    // so everything they measure sits behind a cookie, a CSRF token and a sign-in
    // page. `/api/v3` has none of those, which means none of the accidental
    // protection they bring applies to it. This mints credentials — valid,
    // unscoped, expired, revoked, and one belonging to another tenant — and
    // asserts the endpoint refuses each, with positive controls so an endpoint
    // that is simply broken cannot pass by refusing everybody.
    ['api-sweep', [$php, $root . '/tools/api-sweep.php'], true, ''],
    // THE PHASE EXIT GATES, WHICH WERE NOT IN THIS LIST. They are the most
    // demanding checks the project has — the money, the pack resolution, the
    // reproducible traces — and they ran only when somebody remembered to run
    // them. Two things were consequently true at once and neither was visible:
    // phase 5 had been FAILING on a component code typed into two screens, and
    // a signature change fatally broke tools/phase5/criteria.php while this
    // whole suite stayed green. A check outside the command that runs the checks
    // runs when somebody is already suspicious, which is the opposite of when it
    // is wanted. tools/phase-gates.php keeps FAILED apart from NOT PROVEN so
    // that phases 0, 6 and 10 — which wait on facts about a customer and on a
    // named compliance owner — are reported every run instead of making the
    // suite permanently red, which is how a suite stops being read.
    ['phase-gates', [$php, $root . '/tools/phase-gates.php'], true, ''],
    // A REPORTING CYCLE GRANTS TWO PEOPLE EACH OTHER'S SALARY, and every check
    // above reads it as correct. The manager role reaches its direct reports; in
    // a ring each IS the other's direct report, so both reads are authorised by
    // the decider, pass the scope sweep and appear in no audit finding — because
    // nothing is broken, the data is just wrong. `DataScope` refuses a repeating
    // chain and cannot fire here, since the surface hands it a chain one manager
    // long. This walks the tenant's actual reporting data and asks.
    ['reporting-lines', [$php, $root . '/tools/reporting-lines.php'], true, ''],
];

// The checks whose silence is dangerous: a skip here is not "not run", it is
// "nobody noticed nothing was looked at". See the header.
$mustNotBeLostQuietly = ['smoke', 'exposure', 'scope', 'scope:tenant-wide', 'scope:manager',
    'modules', 'api-sweep', 'phase-gates', 'reporting-lines'];

$results = [];

foreach ($checks as [$name, $cmd, $slow, $unrunnable]) {
    printf("  %-16s ", $name);
    flush();

    if ($slow && $fast) {
        $results[] = [$name, 'SKIP', 0.0, '--fast: not run', [], false];
        echo "SKIP        --fast: not run\n";
        continue;
    }
    if ($unrunnable !== '') {
        $results[] = [$name, 'SKIP', 0.0, $unrunnable, [], false];
        echo "SKIP        {$unrunnable}\n";
        continue;
    }

    $started = microtime(true);
    $out = [];
    $code = 0;
    exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $code);
    $took = microtime(true) - $started;

    // Exit 2 is this codebase's "I refused to run" (see tools/smoke.php):
    // a refusal is a skip, not a pass, and for smoke it is the dangerous one.
    $lines = array_values(array_filter($out, static fn($l) => trim($l) !== ''));
    $headline = $lines === [] ? '(no output)' : trim((string) end($lines));

    if ($code === 2) {
        $status = 'SKIP';
        // A refusal explains itself at the TOP ("Refused: no database", then the
        // specific cause); the tail is prose. The headline of a passing run is
        // the last line. Opposite ends, so read the right one.
        $reason = $lines[1] ?? ($lines[0] ?? 'refused, no reason given');
        // PHPStan mis-narrows $name to only the slow checks here, an artefact
        // of how it correlates the $checks tuples across the two continues
        // above — every check name can genuinely reach exit code 2, not only
        // the ones listed in $mustNotBeLostQuietly.
        /** @var string $name */
        $fatalSkip = in_array($name, $mustNotBeLostQuietly, true);
    } else {
        $status = $code === 0 ? 'PASS' : 'FAIL';
        $reason = '';
        $fatalSkip = false;
    }

    $results[] = [$name, $status, $took, $reason !== '' ? $reason : $headline, $out, $fatalSkip];
    printf("%-4s %6.1fs  %s%s\n", $status, $took, $status === 'SKIP' ? 'could not run: ' : '', $reason !== '' ? $reason : $headline);

    // PHPStan's own failure line is "[ERROR] Found 1000+ errors", which tells
    // somebody standing in front of it precisely nothing about what to do. The
    // mixed-typing debt is meant to be ledgered once, on the machine that has
    // vendor/ — see the note at the top of phpstan.neon. Said here because a
    // red check nobody knows how to action is a red check people learn to skip.
    if ($name === 'phpstan' && $status === 'FAIL' && !is_file($root . '/phpstan-baseline.neon')) {
        echo "                   no phpstan-baseline.neon yet. Read the top of phpstan.neon, then:\n"
            . "                   vendor/bin/phpstan analyse --generate-baseline\n";
    }
}

// Failure detail, after the table, so the table stays readable.
foreach ($results as [$name, $status, , , $out, $fatalSkip]) {
    if ($status === 'PASS' || $out === []) {
        continue;
    }
    echo "\n--- {$name} ({$status}) last output ---\n";
    foreach (array_slice($out, -12) as $line) {
        echo "  {$line}\n";
    }
}

$failed = $skipped = $fatalSkips = 0;
foreach ($results as [, $status, , , , $fatalSkip]) {
    if ($status === 'FAIL') {
        $failed++;
    }
    if ($status === 'SKIP') {
        $skipped++;
        if ($fatalSkip) {
            $fatalSkips++;
        }
    }
}

echo "\n";
$names = static fn(string $want) => implode(', ', array_column(array_filter($results, static fn($r) => $r[1] === $want), 0));

if ($fatalSkips > 0) {
    $lost = implode(', ', array_column(array_filter($results,
        static fn ($r) => $r[1] === 'SKIP' && $r[5]), 0));
    echo "FAIL  {$lost} could not run, and not running is not the same as passing.\n";
    echo "      Give it a database, or say --fast if you meant to skip it.\n";
}
if ($failed > 0) {
    echo "FAIL  " . $failed . " check(s) failed: " . $names('FAIL') . "\n";
}
if ($skipped > 0) {
    echo "      " . $skipped . " check(s) SKIPPED (not run, not passed): " . $names('SKIP') . "\n";
}
if ($failed === 0 && $fatalSkips === 0) {
    echo "PASS  " . count(array_filter($results, static fn($r) => $r[1] === 'PASS')) . " of " . count($results) . " check(s) green"
        . ($skipped > 0 ? ", " . $skipped . " skipped" : "") . ".\n";
}

exit($failed > 0 || $fatalSkips > 0 ? 1 : 0);
