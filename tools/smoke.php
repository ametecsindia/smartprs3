<?php

declare(strict_types=1);

/**
 * OPEN EVERY SCREEN AGAINST THE REAL SCHEMA, AND FAIL ON ANY WARNING.
 *
 * This exists because of `employee_code`. Twenty-nine reads in the front
 * controller asked for a column the ported `employees` table does not have, and
 * all of them worked for months: the file store returns whatever key it was
 * handed, the fixtures used the same wrong name, and nothing ever loaded a page
 * with a real MySQL row in it. The defect was invisible to the unit tests, to
 * the gates, and to me.
 *
 * PHP says "Undefined array key" for exactly that mistake, and then carries on
 * and renders the page anyway — which is why nobody sees it. So this walks every
 * route with a signed-in session, and treats ANY notice, warning or deprecation
 * as a failure. A page that renders while complaining is a page that is wrong in
 * a way somebody will find later, with a real month of payroll in front of them.
 *
 * IT REFUSES TO RUN WITHOUT THE DATABASE. Running it on the file store would
 * reproduce the exact conditions that hid the bug, and report a clean pass for a
 * check that examined nothing — which is worse than not running it.
 *
 * Usage: php tools/smoke.php
 */

require_once __DIR__ . '/../surface/RowStore.php';
require_once __DIR__ . '/../surface/Store.php';
require_once __DIR__ . '/../surface/SqlStore.php';
require_once __DIR__ . '/../surface/Database.php';
require_once __DIR__ . '/../surface/Session.php';

use SmartPRS\Surface\Database;

$root = dirname(__DIR__);

if (Database::connect($root . '/.env') === null) {
    fwrite(STDERR, "Refused: no database.\n\n"
        . Database::why() . "\n\n"
        . "This check exists to find columns the code reads and the SCHEMA does not have. On the\n"
        . "file store every key exists the moment something writes it, so a run there would pass\n"
        . "cleanly having examined nothing — which is the condition that hid the defect this check\n"
        . "was written for.\n");

    exit(2);
}

// Every route the front controller answers, read from the front controller
// itself. A hand-kept list is one that goes stale and then smokes nothing.
preg_match_all("/\\\$route === '([a-z-]+)'/", (string) file_get_contents($root . '/public/app.php'),
    $found);
$screenFiles = array_map(static fn (string $f): string => basename($f, '.php'), glob($root . '/surface/screens/*.php') ?: []);
$routes = array_values(array_unique(array_merge($found[1], $screenFiles)));
sort($routes);

$port = (int) (getenv('SMARTPRS_SMOKE_PORT') ?: 8399);
$host = "127.0.0.1:{$port}";
$log = tempnam(sys_get_temp_dir(), 'prs3-smoke');
$jar = tempnam(sys_get_temp_dir(), 'prs3-jar');

// display_errors=1 puts warnings in the RESPONSE BODY, which is how they get
// seen at all — the whole point is that the page renders happily without them.
$server = proc_open(
    sprintf('exec php -d display_errors=1 -d error_reporting=E_ALL -S %s -t %s/public',
        $host, $root),
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
);

if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the built-in server.\n");

    exit(2);
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

$fetch = static function (string $query) use ($host, $jar): string {
    $command = sprintf(
        'curl -sS -b %s -c %s %s "http://%s/app.php%s" 2>&1',
        escapeshellarg($jar), escapeshellarg($jar),
        str_starts_with($query, 'POST:') ? '-X POST -d ' . escapeshellarg(substr($query, 5)) : '',
        $host,
        str_starts_with($query, 'POST:') ? '' : $query,
    );

    return (string) shell_exec($command);
};

$fetch('?r=signin');
$fetch('POST:action=signin&email=admin@smartprslocal&password=12345678');

// The signed-in check is not optional: every route below would redirect to the
// sign-in page and pass, having rendered nothing. A smoke test that smokes the
// login screen 25 times is the vacuous case for this whole file.
if (!str_contains($fetch('?r=dashboard'), 'sidebar')) {
    fwrite(STDERR, "Refused: could not sign in, so every route would have rendered the sign-in\n"
        . "page and passed. Seed the local account first: php tools/seed-local-admin.php\n");

    exit(2);
}

// A ROUTE WITH NO ROW IN FRONT OF IT IS HALF A CHECK. Most screens render an
// "nothing yet" branch when they have no subject, and that branch touches none of
// the code that reads a row's columns — which is exactly where this application's
// defects have been. So every route is visited twice: bare, and with a real
// employment id from the database.
$subject = '';

try {
    $pdo = Database::connect($root . '/.env');
    $tenant = $pdo->query("SELECT tenant_id FROM users WHERE email = "
        . $pdo->quote(\SmartPRS\Surface\Session::LOCAL_DEFAULT_EMAIL) . " LIMIT 1")->fetchColumn();
    $subject = (string) $pdo->query('SELECT id FROM employees WHERE tenant_id = '
        . (int) $tenant . ' ORDER BY id LIMIT 1')->fetchColumn();
} catch (\Throwable) {
    $subject = '';
}

if ($subject === '') {
    fwrite(STDERR, "Refused: there is no employment to open any screen against, so every route\n"
        . "would render its empty branch and pass having exercised none of the row-reading code.\n"
        . "Seed some: php tools/seed-demo-data.php\n");

    exit(2);
}

$visits = [];

foreach ($routes as $route) {
    $visits[$route] = '?r=' . rawurlencode($route);
    $visits[$route . ' (with a row)'] = '?r=' . rawurlencode($route) . '&id=' . rawurlencode($subject);
}

// EVERY DECLARED MODULE, LIST AND FORM. `?r=m` is one route and eighty-four
// screens: the module engine renders each from its own declaration against its own
// table, so visiting the route once exercises whichever module happens to be first
// and says nothing about the other eighty-three. Every defect this project has
// found in that engine was a column the declaration named and the table did not
// have, which is invisible until that particular module is opened.
//
// Read from config/modules.json rather than listed here, so a module somebody adds
// on Tuesday is smoked on Tuesday.
$declared = json_decode((string) file_get_contents($root . '/config/modules.json'), true);

foreach ((array) ($declared['modules'] ?? []) as $module) {
    $key = (string) $module['key'];
    $visits['module ' . $key] = '?r=m&k=' . rawurlencode($key);
    $visits['module ' . $key . ' (form)'] = '?r=m&k=' . rawurlencode($key) . '&new=1';
}

$noisy = [];
$failed = 0;

// Where the server has got to in its own log, so each route is judged only on
// what IT produced.
$readLog = static function () use ($log): string {
    clearstatcache(true, $log);

    return (string) file_get_contents($log);
};
$seen = strlen($readLog());

foreach ($visits as $route => $query) {
    $raw = $fetch($query);
    $body = strip_tags($raw);
    $complaints = [];

    // 1. THE PAGE MUST FINISH. This is the check that does not depend on how PHP
    //    chose to report anything: a fatal stops rendering mid-tag, so the
    //    document never reaches its closing tag. The body-text search below
    //    missed exactly this — the employees screen was dying inside a table cell
    //    and the response contained no error text at all, so it read as clean.
    //    A truncated page cannot lie about being truncated.
    if (!str_contains($raw, '</html>')) {
        $complaints[] = 'the page stopped part-way through rendering';
    }

    // 2. What the SERVER wrote to its own log for this request. PHP logs a
    //    warning there whatever display_errors and html_errors are set to, so
    //    this survives a configuration that keeps the message out of the body.
    $log_now = $readLog();
    $fresh = substr($log_now, $seen);
    $seen = strlen($log_now);

    preg_match_all('/PHP (?:Fatal error|Warning|Notice|Deprecated):[^\n]{0,180}/', $fresh, $logged);

    // 3. THE TAGS MUST BALANCE. An unclosed <span> in the standing warning was
    //    closing `div.content` early on EVERY page in the application; because
    //    that div is flex:1 it then stretched to fill the viewport and pushed the
    //    page header down by 250 pixels. Every check passed throughout — the
    //    logic was right and the markup was not — and a person found it by
    //    looking at a screenshot.
    //
    //    Counted, not parsed. DOMDocument was the obvious tool and it reported
    //    NOTHING for that exact input: libxml's HTML parser silently repairs bad
    //    nesting, which is precisely what a browser does and precisely why the
    //    defect was invisible. A parser that forgives cannot be used to find
    //    something unforgivable. Counting opens against closes forgives nothing.
    //
    //    Only elements this codebase always closes explicitly are counted. `p`,
    //    `tr`, `td`, `li` and `option` are legitimately left open in HTML and
    //    would drown the signal.
    if (str_contains($raw, '</html>')) {
        foreach (['div', 'span', 'table', 'form', 'details', 'select', 'textarea', 'aside',
            'nav', 'main', 'header', 'thead', 'tbody'] as $tag) {
            $opened = preg_match_all('/<' . $tag . '(?=[\s>])/i', $raw);
            $closed = preg_match_all('/<\/' . $tag . '\s*>/i', $raw);

            if ($opened !== $closed) {
                $complaints[] = sprintf('markup: %d <%s> opened, %d closed', $opened, $tag, $closed);
            }
        }
    }

    // 4. And the body, for anything that reached the page.
    preg_match_all('/(?:Fatal error|Warning|Notice|Deprecated): [^\n]{0,180}/', $body, $shown);

    $complaints = array_merge($complaints, $logged[0], $shown[0]);

    if ($complaints !== []) {
        $noisy[$route] = array_values(array_unique($complaints));
        $failed++;
    }

    printf("  %-22s %s\n", $route, $complaints === [] ? 'clean' : 'COMPLAINS');
}

echo "\n";

foreach ($noisy as $route => $lines) {
    echo "?r={$route}\n";

    foreach (array_slice($lines, 0, 6) as $line) {
        echo '    ' . trim($line) . "\n";
    }
}

printf("\n%s  %d visit(s) over %d route(s), %d complaining.\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($visits), count($routes), $failed);

exit($failed === 0 ? 0 : 1);
