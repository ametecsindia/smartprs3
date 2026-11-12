<?php
/**
 * PROOF THAT EVERY CONSOLE PAGE ACTUALLY RENDERS.
 *
 *   php tools/demo/smoke-console.php
 *
 * A PHP fatal inside a page still returns 200 with a truncated body, so
 * "the page loaded" is not evidence that it worked. This boots the built-in
 * server, requests every route, and requires the closing tag — which only
 * appears if the page ran to the end. It also fails on any PHP diagnostic the
 * server logged, so a warning cannot hide behind a page that looks fine.
 *
 * Exit 0 = every route rendered completely.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$port = (int) (getenv('SMARTPRS_SMOKE_PORT') ?: 8399);
$log = tempnam(sys_get_temp_dir(), 'smartprs-smoke');

$routes = [
    '',
    'p=spx',
    'p=spx&src=' . rawurlencode('attendance.late_minutes > 10'),   // must be REFUSED, not crash
    'p=spx&src=' . rawurlencode('this is not an expression'),      // must be a parse error, not a 500
    'p=money&amount=1000.00&ways=3',
    'p=money&amount=nonsense&ways=999',                            // rejected input falls back
    'p=formula',
    'p=formula&gross=50000&basic=12.5&hra=40',
    'p=rules&submitted=1&late=23&grace=10&branch=HYD',
    'p=rules&submitted=1&late=23&grace=10&branch=BLR',
    'p=rules&submitted=1&late=23&grace=10&branch=BLR&weeklyoff=1',
    'p=config&asof=2026-07-15&branch=HYD',
    'p=config&asof=2026-08-15&branch=HYD',
    'p=config&asof=1999-01-01&branch=BLR',                          // nothing in force
    'p=workflow',
    'p=workflow&requester=u-ejaz&subject=u-ejaz',
    'p=workflow&raised=2026-08-28+16%3A00&sla=480',          // Friday: weekend + holiday
    'p=workflow&raised=not+a+date&sla=nonsense',             // rejected input falls back
    'p=workflow&amount=49999.99&amended=400000.00',          // §IX.9.1 one paisa below the threshold
    'p=workflow&amount=50000.00',                            // and the threshold itself: `from` is inclusive
    'p=workflow&amount=not+money',                           // a rejected amount falls back, never routes on a guess
    'p=no-such-page',
];

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/public'],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
);

if (!is_resource($server)) {
    fwrite(STDERR, "Could not start the built-in server.\n");
    exit(1);
}

// The server needs a moment, and a busy port fails the first request rather
// than hanging, so this waits for it to answer instead of sleeping blindly.
for ($attempt = 0; $attempt < 40; $attempt++) {
    usleep(100_000);

    if (@file_get_contents("http://127.0.0.1:{$port}/") !== false) {
        break;
    }
}

echo "SmartPRS 3 console smoke test — 127.0.0.1:{$port}\n\n";

$failures = 0;

// An unknown page answers 404 AND renders, which is the point of testing it, so
// the status code must not stop the body from being read.
$anyStatus = stream_context_create(['http' => ['ignore_errors' => true]]);

foreach ($routes as $route) {
    $body = @file_get_contents("http://127.0.0.1:{$port}/?{$route}", context: $anyStatus);
    $complete = is_string($body) && str_contains($body, '</html>');

    printf("  %-5s /?%s\n", $complete ? 'ok' : 'FAIL', $route);

    if (!$complete) {
        $failures++;
    }
}

proc_terminate($server);
proc_close($server);

// A page can render completely and still have emitted a warning on the way.
$diagnostics = array_values(array_filter(
    file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static fn (string $l): bool => (bool) preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $l),
));

@unlink($log);

if ($diagnostics !== []) {
    echo "\n  PHP diagnostics logged by the server:\n";

    foreach (array_slice($diagnostics, 0, 10) as $line) {
        echo '    ' . $line . "\n";
    }

    $failures += count($diagnostics);
}

printf("\n%s  %d route(s), %d failure(s)\n", $failures === 0 ? 'PASS' : 'FAIL', count($routes), $failures);
exit($failures === 0 ? 0 : 1);
