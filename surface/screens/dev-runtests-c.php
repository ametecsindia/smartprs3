<?php declare(strict_types=1);
// DEV ONLY (owner, 13 Oct): run the project's own test suite, ONE PROCESS PER TEST FILE (a fatal
// error in one file no longer stops the rest), and show a one-line summary per file + every failure.
// Read-only for app data — the suite works on fixtures. Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(900);
session_write_close();   // do not hold the session lock while the suite runs
$rtRoot = realpath(__DIR__ . '/../..');
$rtPhp = '';
foreach (array_merge([PHP_BINARY], glob('C:/laragon/bin/php/*/php.exe') ?: []) as $c) {
    if ($c !== '' && is_file($c) && stripos(basename($c), 'php') === 0 && stripos($c, 'cgi') === false) { $rtPhp = $c; }
}
$rtLines = ['PHP: ' . $rtPhp];
foreach (glob($rtRoot . '/tests/*_test.php') ?: [] as $f) {
    $name = basename($f, '.php');
    $p = proc_open('"' . $rtPhp . '" tests/run.php ' . $name . ' 2>&1', [1 => ['pipe', 'w']], $pipes, $rtRoot);
    $out = is_resource($p) ? (string) stream_get_contents($pipes[1]) : 'could not start';
    is_resource($p) && proc_close($p);
    $pass = preg_match('/(\d+) passed/', $out, $m) === 1 ? (int) $m[1] : 0;
    $failN = preg_match('/(\d+) FAILED/', $out, $m2) === 1 ? (int) $m2[1] : 0;
    $fatal = preg_match('/(PHP )?Fatal error:\s*([^\n]{0,220})/', $out, $m3) === 1 ? $m3[2] : '';
    $fails = [];
    if (preg_match_all('/^FAIL\s+([^\n]{0,260})/m', $out, $m4)) { $fails = $m4[1]; }
    $rtLines[] = implode("\t", [str_replace('_test', '', $name), $pass, $failN, $fatal, implode(' || ', array_slice($fails, 0, 6))]);
}
?><pre id="rt"><?= e(implode("\n", $rtLines)) ?></pre>
