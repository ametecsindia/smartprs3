<?php declare(strict_types=1);
// DEV ONLY (13 Oct): zip the source tree (no storage, no .env) to storage/dev-repo.zip so it can be
// tested in Claude's workspace. Delete after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(300);
session_write_close();
$zr = realpath(__DIR__ . '/../..');
$zOut = $zr . '/storage/dev-repo.zip';
@unlink($zOut);
$z = new ZipArchive();
$z->open($zOut, ZipArchive::CREATE);
$n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zr, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($zr) + 1));
    if (preg_match('#^(storage|Claude outputs|\.git|node_modules|docs)/|^\.env#', $rel) || $f->getSize() > 20000000) { continue; }
    $z->addFile($f->getPathname(), $rel); $n++;
}
$z->close();
?><pre id="z"><?= $n ?> files, <?= round(filesize($zOut) / 1048576, 1) ?> MB</pre>
