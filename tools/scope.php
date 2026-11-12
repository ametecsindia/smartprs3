<?php

declare(strict_types=1);

/**
 * SIGN IN AS ONE EMPLOYEE AND TRY TO READ A COLLEAGUE'S ROW.
 *
 * tools/exposure.php asks "did a SECRET come back?" — a salary, a bank account, a
 * password hash. This asks the other question, the one a value-grep cannot ask:
 * "WHOSE ROW IS THIS?" A colleague's name and employee code are not secrets in the
 * exposure sense — they are on the wall chart — but a screen that renders THEM
 * when the address bar says `id=18` is a screen that will render everything else
 * of theirs too, because it never asked whose record it was fetching. Intra-tenant
 * isolation is the assertion; the name is only the tell.
 *
 * IT MEASURES BOTH DIRECTIONS, and that is the whole design. Two employments are
 * chosen: MINE, which the temporary viewer IS (`users.employee_id`), and THEIRS.
 * Every route is requested twice — once pointed at THEIRS, once at MINE:
 *
 *   pointed at THEIRS   their name/code/email/mobile must be ABSENT.
 *   pointed at MINE     the viewer's OWN name must be PRESENT.
 *
 * Without the second request the first is worthless. A route that 500s, redirects
 * or refuses everybody contains no colleague's name either, and a sweep that only
 * looked one way would print `clean` for a screen that is simply broken. So
 * "own record unreachable" is reported as its own outcome, distinct from `clean`
 * and distinct from `EXPOSED`, and if the viewer's own name appeared on NO screen
 * at all the run refuses outright (exit 2) rather than reporting a clean sweep
 * over nothing.
 *
 * It refuses (exit 2) if: there is no database, there are fewer than two
 * employments to tell apart, the temporary viewer could not be created, sign-in
 * failed, or the positive control never appeared anywhere.
 *
 * A ROLE REFUSAL LEAVES A HOLE THIS FILE CANNOT SEE. A viewer holds no
 * `payroll.view`, so `?r=payslip`, `?r=register` and `?r=settlement` are refusals
 * for them — five routes reported NOT CHECKED, which is honest and is not an
 * answer. So the run takes a role:
 *
 *   php tools/scope.php                                   the viewer sweep, above
 *   php tools/scope.php --role=administrator --tenant-wide the same routes, opened
 *
 * `--tenant-wide` INVERTS the assertion, and it has to. An administrator holds
 * `payroll.view`, which means "any employment's pay" — the colleague appearing is
 * the permission working, and reporting it as a leak would be this file failing
 * the product for doing what the grant says. What is asserted instead is that the
 * routes are REACHABLE and show BOTH records: a screen that shows nobody is how
 * "no colleague here" gets written down as clean, and it is the only way the
 * default sweep above could be lied to. Together the two runs say the thing
 * neither says alone — these screens are decided by the permission, not by
 * accident.
 *
 * Usage: php tools/scope.php [--role=viewer] [--tenant-wide]
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Session.php';

spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/Platform/'
        . str_replace('\\', '/', substr($class, strlen('SmartPRS\\Platform\\'))) . '.php';

    if (str_starts_with($class, 'SmartPRS\\Platform\\') && is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Platform\Identity\PasswordHasher;
use SmartPRS\Surface\Database;
use SmartPRS\Surface\Session;

const PROBE_EMAIL = 'scope-probe@smartprslocal';
const PROBE_PASSWORD = 'scope-probe-pw';

$root = dirname(__DIR__);

$role = 'viewer';
$tenantWide = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--role=')) {
        $role = substr($argument, 7);
    } elseif ($argument === '--tenant-wide') {
        $tenantWide = true;
    } else {
        fwrite(STDERR, "Unknown argument {$argument}. Usage: php tools/scope.php "
            . "[--role=viewer] [--tenant-wide]\n");

        exit(2);
    }
}

$refuse = static function (string $why, string ...$more): never {
    fwrite(STDERR, "Refused: {$why}\n\n" . implode("\n", $more) . "\n");

    exit(2);
};

$pdo = Database::connect($root . '/.env');

if ($pdo === null) {
    $refuse('no database.', Database::why(), '',
        'The two employments this check tells apart are read OUT of MySQL. Without it there is no',
        'colleague to ask about and no own record to prove reachable.');
}

$tenant = (int) $pdo->query('SELECT tenant_id FROM users WHERE email = '
    . $pdo->quote(Session::LOCAL_DEFAULT_EMAIL) . ' LIMIT 1')->fetchColumn();

if ($tenant === 0) {
    $refuse('no seeded local administrator, so there is no demo tenant to probe.',
        'Seed one: php tools/seed-local-admin.php');
}

// A ROLE NOBODY DECLARES HOLDS NOTHING, so the probe would be refused every screen
// and the run would report a wall of NOT CHECKED — or, with --tenant-wide, a wall
// of blind screens — for a typo. Asked of the surface's own role list rather than
// a copy of it here.
require_once $root . '/surface/Authorisation.php';

if (!in_array($role, (new \SmartPRS\Surface\Authorisation())->roles(), true)) {
    $refuse("no role named '{$role}' is declared.",
        'The probe would be created with a role that grants nothing, refused every screen, and',
        'this run would report that as a finding about the application rather than about the',
        'spelling of an argument.');
}

// ------------------------------------------------------------- MINE and THEIRS
// The identifying columns, read rather than guessed. Rows are ordered so the ones
// carrying the most identifying data come first: an employment with no email and
// no mobile can still be told apart by name and code, but it asserts less, and
// the pair this run picks decides how much this run can prove.
$identifying = [
    'name' => 'name',
    'emp_code' => 'employee code',
    'email' => 'email',
    'mobile' => 'mobile',
];

$employments = $pdo->query('SELECT id, reporting_manager_id, '
    . implode(', ', array_keys($identifying))
    . ' FROM employees WHERE tenant_id = ' . $tenant
    . ' AND deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

/** How many of the identifying columns this row actually carries. */
$richness = static fn (array $row): int => count(array_filter(
    array_map(static fn (string $c): string => trim((string) ($row[$c] ?? '')),
        array_keys($identifying)),
    static fn (string $v): bool => strlen($v) >= 4,
));

usort($employments, static fn (array $a, array $b): int => $richness($b) <=> $richness($a));

$employments = array_values(array_filter($employments,
    static fn (array $row): bool => strlen(trim((string) $row['name'])) >= 4));

if (count($employments) < 2) {
    $refuse('fewer than two identifiable employments in tenant ' . $tenant . '.',
        'This check is "can one employment see another one" — with one row there is no other one,',
        'and every route would sweep clean having had nobody to ask about.',
        'Seed some: php tools/seed-demo-data.php');
}

$mine = $employments[0];

// THEIRS MUST NOT REPORT TO MINE, or this whole run asserts the opposite of what
// it says. A manager IS allowed to read their own direct report, so picking one as
// the colleague would report the scope working correctly as a leak — and, worse,
// picking one by accident would make the check pass or fail depending on which two
// rows happened to be richest that day. The demo seed puts every employment under
// one of two leads, so this is not hypothetical.
// AND THEIRS MUST NOT BE SOMEBODY MINE IS ENTITLED TO SEE NAMED: the reporting
// manager, or whoever decided one of MINE's own leave requests — "Approved by
// Ashok Varma" on your own leave statement is the product working, not a leak.
$decidedMine = [];

foreach ($pdo->query('SELECT decided_by FROM leaves WHERE tenant_id = ' . $tenant . ' AND employee_id = ' . (int) $mine['id'])->fetchAll(PDO::FETCH_COLUMN) as $decidedBy) {
    $decidedMine[trim((string) $decidedBy)] = true;
}

$notMyReport = array_values(array_filter(array_slice($employments, 1),
    static fn (array $row): bool
        => (string) ($row['reporting_manager_id'] ?? '') !== (string) $mine['id']
        && (string) ($row['id'] ?? '') !== (string) ($mine['reporting_manager_id'] ?? '')
        && !isset($decidedMine[trim((string) ($row['name'] ?? ''))])
        && !isset($decidedMine[trim((string) ($row['email'] ?? ''))])));

if ($notMyReport === []) {
    $refuse('every other employment reports to the one this run signs in as.',
        'A manager may read their own direct report, so there is no colleague here to be refused',
        'and every screen would be reported as leaking while behaving exactly as designed.');
}

$theirs = $notMyReport[0];

// The values that must not come back. Anything under four characters is dropped —
// a two-character match is a coincidence, not evidence — and anything MINE also
// carries is dropped too, because seeing it proves nothing about whose row was
// rendered. A class with nothing left is reported as unasserted, never as clean.
$ownValues = array_map(static fn (string $c): string
    => strtolower(trim((string) ($mine[$c] ?? ''))), array_keys($identifying));

$needles = [];
$unasserted = [];

foreach ($identifying as $column => $class) {
    $value = trim((string) ($theirs[$column] ?? ''));

    if (strlen($value) < 4 || in_array(strtolower($value), $ownValues, true)) {
        $unasserted[] = $class;

        continue;
    }

    $needles[$value] = $class;
}

if ($needles === []) {
    $refuse('the colleague employment carries no identifying value this run could hunt for.',
        'Every route would be searched for nothing at all and reported clean.');
}

// The positive control: something of MINE's that a screen showing MINE's record
// must print. The name, for the same reason it is the tell on the other side.
$control = trim((string) $mine['name']);

printf("MINE   #%s %s (%s)\nTHEIRS #%s %s (%s) — %d value(s) must not appear\n\n",
    $mine['id'], $mine['name'], $mine['emp_code'],
    $theirs['id'], $theirs['name'], $theirs['emp_code'], count($needles));

// -------------------------------------------------------- the viewer account
// Created here, bound to MINE, rather than assumed: a probe that needs a
// hand-made account is a probe that stops being run. The role rides on `status`
// and the employment binding on `employee_id` — see Session::signIn().
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([PROBE_EMAIL]);

register_shutdown_function(static function () use ($pdo): void {
    // ALWAYS, including on a refusal or a fatal. A probe account with a known
    // password left behind by a security check is its own finding.
    try {
        $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([PROBE_EMAIL]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "\nCOULD NOT DELETE THE PROBE ACCOUNT " . PROBE_EMAIL . ': '
            . $e->getMessage() . "\nDelete it by hand.\n");
    }
});

$created = $pdo->prepare('INSERT INTO users (tenant_id, email, password, status, employee_id,'
    . ' created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')->execute([
        $tenant, PROBE_EMAIL, PasswordHasher::platformDefault()->hash(PROBE_PASSWORD), $role,
        $mine['id'],
    ]);

if (!$created) {
    $refuse('could not create the temporary viewer.',
        'Without an account that IS one employment there is no "your own" and no "somebody',
        'else\'s" — the whole assertion this file makes has no subject.');
}

// ---------------------------------------------------------------- the server
$port = (int) (getenv('SMARTPRS_SCOPE_PORT') ?: 8399);
$host = "127.0.0.1:{$port}";
$log = (string) tempnam(sys_get_temp_dir(), 'prs3-scope');
$jar = (string) tempnam(sys_get_temp_dir(), 'prs3-scope-jar');

$server = proc_open(
    sprintf('exec php -S %s -t %s/public', $host, $root),
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
);

if (!is_resource($server)) {
    $refuse('could not start the built-in server, so nothing could be requested.');
}

register_shutdown_function(static function () use ($server, $log, $jar): void {
    proc_terminate($server, SIGKILL);
    @unlink($log);
    @unlink($jar);
});

for ($i = 0; $i < 40; $i++) {
    usleep(150_000);

    if (@file_get_contents("http://{$host}/app.php?r=signin") !== false) {
        break;
    }
}

$fetch = static function (string $query, string $post = '') use ($host, $jar): string {
    return (string) shell_exec(sprintf(
        'curl -sS -b %s -c %s %s "http://%s/app.php%s" 2>&1',
        escapeshellarg($jar), escapeshellarg($jar),
        $post === '' ? '' : '-X POST -d ' . escapeshellarg($post),
        $host, $post === '' ? $query : '',
    ));
};

$fetch('?r=signin');
$fetch('', 'action=signin&email=' . PROBE_EMAIL . '&password=' . PROBE_PASSWORD);

// The sidebar prints the local part of the signed-in address on every real
// screen. It says "this response is a screen"; it does not say "this screen shows
// my record" — that is what $control is for, and the two are not the same claim.
$signedIn = explode('@', PROBE_EMAIL)[0];

if (!str_contains($fetch('?r=employees'), $signedIn)) {
    $refuse('could not sign in as the temporary viewer.',
        'Every route below would have been served the sign-in page: no colleague on it, no own',
        'record on it either, and a clean sweep that examined no screen at all.');
}

printf("signed in as %s (role: %s, employee_id: %s).\n%s\n\n", $signedIn, $role, $mine['id'],
    $tenantWide
        ? 'TENANT-WIDE MODE: the colleague SHOULD appear. What is asserted is that these screens'
            . " are\nreachable and show both records — a screen that shows nobody is how \"no"
            . " colleague here\"\nbecomes a clean sweep over nothing."
        : 'The colleague must not appear on any screen.');

// ------------------------------------------------------------------ the sweep
// Routes read out of the front controller, like tools/smoke.php and
// tools/exposure.php do. A hand-kept list is a list that misses the route
// somebody added on Tuesday — which is the one most likely to have no scope
// check on it.
preg_match_all("/\\\$route === '([a-z-]+)'/",
    (string) file_get_contents($root . '/public/app.php'), $matched);
$screenFiles = array_map(static fn (string $f): string => basename($f, '.php'), glob($root . '/surface/screens/*.php') ?: []);
$routes = array_values(array_unique(array_merge($matched[1], $screenFiles)));
sort($routes);

$period = (string) ($pdo->query('SELECT cycle_label FROM payroll_runs WHERE tenant_id = '
    . $tenant . ' ORDER BY id DESC LIMIT 1')->fetchColumn() ?: date('Y-m'));

// EVERY SUBJECT-BEARING SPELLING SEPARATELY, not all at once in one query string.
// Exposure sends them together because it only needs to know THAT a page leaked;
// this has to name the parameter, because the fix is a scope check on one
// specific read and "?r=leave leaks" does not say which line to change.
//
// ponytail: `employment=` is probed although nothing currently reads it — it is
// one row here, and the point of a sweep is to be wrong about what exists rather
// than to be a second copy of the router.
$shapes = static fn (array $who): array => [
    'id=' => 'id=' . rawurlencode((string) $who['id']),
    'e=' => 'e=' . rawurlencode((string) $who['id']) . '&t=offer',
    'code=' => 'code=' . rawurlencode((string) $who['emp_code'])
        . '&period=' . rawurlencode($period),
    'employment=' => 'employment=' . rawurlencode((string) $who['id']),
];

$leaks = [];
// --tenant-wide only, and per ROUTE rather than per visit: a route that ignores
// every subject-bearing parameter is not about an employment at all and asserts
// nothing either way, so only a route that DOES read one and still never showed
// the colleague is a finding.
$reads = [];
$showed = [];
$unreachable = [];
$notScreens = [];
$denied = [];
$ignored = 0;
$controlSeen = 0;
$visits = 0;

foreach ($routes as $route) {
    foreach ($shapes($theirs) as $shape => $carrying) {
        $label = "?r={$route}&{$shape}";
        $visits += 2;

        $body = $fetch('?r=' . rawurlencode($route) . '&' . $carrying);
        // THE SAME SCREEN POINTED AT MINE. Fetched for every visit rather than
        // only the quiet ones, because it answers two questions at once: whether
        // the route reads this parameter AT ALL (identical bytes = it does not,
        // and "own record unreachable" would be a scolding for a screen that is
        // not about an employment), and whether the viewer can see themselves.
        $own = $fetch('?r=' . rawurlencode($route) . '&' . $shapes($mine)[$shape]);

        if (!str_contains($body, $signedIn)) {
            // Not a leak — worse. This response is not a screen, so its silence
            // about the colleague means nothing at all.
            $notScreens[] = $label;
            printf("  %-34s NOT A SCREEN\n", $label);

            continue;
        }

        $hits = [];

        foreach ($needles as $needle => $class) {
            if (str_contains($body, (string) $needle)) {
                $hits[$class] = (string) $needle;
            }
        }

        // ASKED BEFORE "does this route read the parameter?", deliberately. A
        // list or a picker renders every employment in the tenant whatever is in
        // the address bar — the colleague is on the page without the parameter
        // having done anything — and testing for the parameter first would file
        // that under "ignores id" and never look.
        if ($hits !== [] && $tenantWide) {
            // The permission working, not a leak. Recorded as the positive
            // evidence it is: this role may read any employment, and here is the
            // screen doing it.
            $showed[$route] = true;

            if (str_contains($own, $control)) {
                $controlSeen++;
            }

            printf("  %-34s both visible (tenant-wide by permission)\n", $label);

            continue;
        }

        if ($hits !== []) {
            $leaks[$label . ($body === $own ? ' (regardless of the parameter)' : '')] = $hits;

            printf("  %-34s EXPOSED — %s%s\n", $label,
                implode(', ', array_map(static fn (string $c, string $v): string
                    => $c . ' "' . $v . '"', array_keys($hits), $hits)),
                $body === $own ? ' (regardless of the parameter)' : '');

            continue;
        }

        // A ROLE REFUSAL IS NOT A CLEAN SCREEN. A viewer holds no `payroll.view`,
        // so `?r=payslip` is a "Denied:" page for them — it contains no
        // colleague because it contains no data at all. Saying so is the
        // difference between "this screen is scoped" and "this run never saw
        // this screen": the row scoping behind that permission is UNTESTED here
        // and needs a probe signed in as somebody who holds it.
        //
        // The two refusals wear the same "Denied:" costume on the screen, so they
        // are told apart by BEHAVIOUR rather than by matching the app's prose: a
        // ROLE refusal refuses the same screen pointed at MINE as well; a SCOPE
        // refusal is precisely the one that does not, and that one is a pass —
        // it is the check working.
        if (str_contains($body, 'Denied:')) {
            if (str_contains($own, $control)) {
                $controlSeen++;
                printf("  %-34s clean (refused: not your record)\n", $label);

                continue;
            }

            $denied[] = $label;
            printf("  %-34s denied to this role — NOT CHECKED\n", $label);

            continue;
        }

        if ($body === $own) {
            $ignored++;
            printf("  %-34s no subject (route ignores %s)\n", $label, rtrim($shape, '='));

            continue;
        }

        // Past here the route demonstrably reads this parameter: the two responses
        // differ. That is what makes "the colleague never appeared" a claim about
        // the screen rather than about the parameter.
        $reads[$route] = true;

        // THE POSITIVE CONTROL, per route and per shape. Pointed at MINE, the
        // same screen must show MINE's name — otherwise "no colleague here" is
        // the silence of a screen that shows nobody, and reporting that as clean
        // would be this sweep measuring a broken app and calling it a secure one.
        if (str_contains($own, $control)) {
            $controlSeen++;
            printf("  %-34s clean\n", $label);

            continue;
        }

        $unreachable[] = $label;
        printf("  %-34s own record unreachable\n", $label);
    }
}

echo "\n";

foreach ($leaks as $label => $hits) {
    echo "{$label}\n";

    foreach ($hits as $class => $value) {
        echo "    {$class} of {$theirs['name']} (#{$theirs['id']}) — \"{$value}\"\n";
    }
}

if ($unasserted !== []) {
    echo "\nNOT ASSERTED (the colleague has no such value, or shares MINE's, so this run did not\n"
        . "check it): " . implode(', ', $unasserted) . "\n";
}

if ($unreachable !== []) {
    echo "\nOWN RECORD UNREACHABLE on " . count($unreachable) . " visit(s) — a failure of a\n"
        . "different kind from a leak: the viewer's own name did not appear on the screen when it\n"
        . "was pointed at their OWN id. Some of these are honest (a viewer holds no payroll.view,\n"
        . "so ?r=payslip is a permission refusal for them), but on a route they may open it means\n"
        . "the screen shows nobody, and its silence about a colleague proves nothing:\n    "
        . implode("\n    ", $unreachable) . "\n";
}

$blind = $tenantWide
    ? array_values(array_diff(array_keys($reads), array_keys($showed)))
    : [];

if ($blind !== []) {
    echo "\nBLIND — this role may read any employment, and on " . count($blind) . " route(s) that\n"
        . "demonstrably read a subject parameter the colleague never appeared. Either the screen\n"
        . "shows nobody, or it is scoped by something other than the permission. Both matter: a\n"
        . "screen that shows nobody is exactly how the viewer sweep's \"no colleague here\" becomes\n"
        . "a clean result over nothing.\n    " . implode("\n    ", $blind) . "\n";
}

if ($denied !== []) {
    $routesDenied = array_values(array_unique(array_map(static fn (string $l): string
        => explode('&', $l)[0], $denied)));

    echo "\nNOT CHECKED — this role is refused the screen outright, so nothing about whose rows it\n"
        . "would show was learned: " . implode(', ', $routesDenied) . "\n"
        . ($tenantWide
            ? "    A role holding every grant is still refused these. That is worth reading:\n"
                . "    nothing at all is proven about their row scoping by either run.\n"
            : "    Row scoping behind a permission needs a probe holding that permission —\n"
                . "    php tools/scope.php --role=administrator --tenant-wide\n");
}

if ($controlSeen === 0) {
    $refuse('the viewer\'s own record appeared on NOT ONE screen.',
        'Every route was either a refusal, an error or a blank — so "no colleague on this page"',
        'means "there is no page", and every `clean` above would be a sweep over nothing. That is',
        'the exact shape of vacuous pass this file exists to make impossible.');
}

$failed = $tenantWide ? ($blind !== []) : ($leaks !== []);

printf("\n%s  %s, %d visit(s), %d value(s) hunted, %d leaking, %d blind, %d unreachable,"
    . " %d denied to this role, %d ignoring the parameter, %d control(s) seen.\n",
    $failed ? 'FAIL' : 'PASS', $tenantWide ? "role {$role}, tenant-wide" : "role {$role}",
    $visits, count($needles), count($leaks), count($blind),
    count($unreachable), count($denied), $ignored, $controlSeen);

exit($failed ? 1 : 0);
