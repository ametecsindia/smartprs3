<?php declare(strict_types=1);
// DEV ONLY (14 Oct): runs exactly what the owner would type — `php tools/db/migrate.php` (NEVER
// --fresh: pending migrations only, seed skipped on a seeded database) and then
// `php tools/db/grant-application-account.php --apply` — and shows the output. Admin only.
// Delete with the other dev-*.php screens after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(0);
session_write_close();
$dmRoot = realpath(__DIR__ . '/../..');
$dmPhp = '';
foreach (glob('C:/laragon/bin/php/*/php.exe') ?: [] as $c) { $dmPhp = $c; }
$dmOut = 'PHP: ' . $dmPhp . "\n";
foreach (['tools/db/migrate.php', 'tools/db/grant-application-account.php --apply'] as $dmCmd) {
    $p = proc_open('"' . $dmPhp . '" ' . $dmCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dmRoot);
    if (!is_resource($p)) { $dmOut .= "\n$ {$dmCmd}\ncould not start\n"; break; }
    $o = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);
    $dmOut .= "\n$ php {$dmCmd}   (exit {$code})\n" . $o;
    if ($code !== 0) { break; }
}
file_put_contents($dmRoot . '/storage/dev-migrate.txt', $dmOut);
?><pre id="dm"><?= e($dmOut) ?></pre>
