<?php declare(strict_types=1);
// DEV ONLY (owner, 13 Oct): the project's test suite, one process per test file, 25 s cap per file,
// progress written to storage/dev-tests.txt as it goes (?show=1 just shows that file). Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$rdRoot = realpath(__DIR__ . '/../..');
$rdLog = $rdRoot . '/storage/dev-tests' . (isset($_GET['only']) ? '-only' : '') . '.txt';
if (($_GET['show'] ?? '') === '1') { ?><pre id="rt"><?= e(is_file($rdLog) ? (string) file_get_contents($rdLog) : 'not started') ?></pre><?php return; }
@set_time_limit(0);
ignore_user_abort(true);
session_write_close();
$rdPhp = '';
foreach (glob('C:/laragon/bin/php/*/php.exe') ?: [] as $c) { $rdPhp = $c; }
file_put_contents($rdLog, 'PHP: ' . $rdPhp . "\n");
$rdOnly = array_filter(explode(',', preg_replace('/[^a-z_,]/', '', (string) ($_GET['only'] ?? ''))));
foreach (glob($rdRoot . '/tests/*_test.php') ?: [] as $f) {
    if ($rdOnly !== [] && !in_array(basename($f, '_test.php'), $rdOnly, true)) { continue; }
    $name = basename($f, '.php');
    $p = proc_open('"' . $rdPhp . '" tests/run.php ' . $name, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $rdRoot);
    if (!is_resource($p)) { file_put_contents($rdLog, "{$name}\t0\t0\tcould not start\t\n", FILE_APPEND); continue; }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $t0 = microtime(true); $timedOut = false;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) { break; }
        if (microtime(true) - $t0 > 25) { $timedOut = true; proc_terminate($p); break; }
        usleep(100000);
    }
    $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    proc_close($p);
    // The FINAL summary line covers every file the filter matched (the filter is a substring, so
    // 'extras' also runs alert_emails_extras) — read that, not the first file's line.
    $sum = preg_match('/(\d+) assertions, (\d+) failures, (\d+) files/', $out, $ms) === 1;
    $failN = $sum ? (int) $ms[2] : 0;
    $pass = $sum ? (int) $ms[1] - $failN : 0;
    $files = $sum ? (int) $ms[3] : 0;
    $fatal = $timedOut ? 'TIMEOUT after 25 s' : (preg_match('/Fatal error:\s*([^\n]{0,200})/', $out, $m3) === 1 ? $m3[1] : '');
    $fails = preg_match_all('/^FAIL\s+([^\n]{0,240})/m', $out, $m4) ? $m4[1] : [];
    file_put_contents($rdLog, implode("\t", [str_replace('_test', '', $name) . ($files > 1 ? " (+" . ($files - 1) . ' sharing the name)' : ''), $pass, $failN, str_replace("\t", ' ', $fatal), str_replace("\t", ' ', implode(' || ', array_slice($fails, 0, 6)))]) . "\n", FILE_APPEND);
}
file_put_contents($rdLog, "DONE\n", FILE_APPEND);
?><pre id="rt">done</pre>
