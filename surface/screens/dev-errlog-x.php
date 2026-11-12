<?php declare(strict_types=1);
// DEV ONLY (6 Nov 2026): copies the last 200 lines of PHP's error log to storage/dev-errlog.txt so the cloud session can
// read why a page came up blank. Administrator only. Delete with the other dev screens.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$log = (string) ini_get('error_log');
$lines = is_file($log) ? array_slice((array) file($log), -200) : ['no error_log file: ' . $log];
file_put_contents(__DIR__ . '/../../storage/dev-errlog.txt', $log . "\n" . implode('', $lines));
?><pre id="src"><?= e($log) ?> — <?= count($lines) ?> line(s) copied</pre>
