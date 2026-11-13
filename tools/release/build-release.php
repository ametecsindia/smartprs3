<?php
/**
 * BUILD A RELEASE PACKAGE (run by make-release.bat on the build PC).
 *
 *   php tools/release/build-release.php [version] [output folder]
 *
 * Asks for the version (default: next patch number) and the release notes, writes config/version.json,
 * and makes in the output folder (default C:\SmartPRS3-Releases):
 *   smartprs3-<version>.zip   the application, with release.json inside
 *   smartprs3-<version>.sig   Ametecs' signature over the zip's SHA-256 (licence private key)
 *   smartprs3-<version>.txt   version, SHA-256, size, notes
 * Left out of the package: .env, storage/, .git, mobile/, public/uploads/, Claude outputs and build junk.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$out = rtrim((string) ($argv[2] ?? 'C:\\SmartPRS3-Releases'), '\\/');
$keyFile = $root . '/storage/keys/license_private.pem';
$ask = static function (string $q, string $default = ''): string {
    echo $q . ($default !== '' ? " [{$default}]" : '') . ': ';
    $a = trim((string) fgets(STDIN));

    return $a === '' ? $default : $a;
};

foreach (['zip' => \ZipArchive::class, 'openssl' => 'openssl_sign'] as $ext => $probe) {
    if (!class_exists($probe) && !function_exists($probe)) {
        fwrite(STDERR, "PHP extension '{$ext}' is not enabled (php.ini). Stopped.\n");
        exit(1);
    }
}

if (!is_readable($keyFile)) {
    fwrite(STDERR, "The licence private key is missing: {$keyFile}\nOnly the build PC that holds it can make a release. Stopped.\n");
    exit(1);
}

$verFile = $root . '/config/version.json';
$ver = json_decode((string) file_get_contents($verFile), true) ?: [];
$current = (string) ($ver['version'] ?? '3.0.0');
$parts = array_map('intval', explode('.', (string) preg_replace('/[^0-9.].*$/', '', $current))) + [0, 0, 0];
$next = $parts[0] . '.' . $parts[1] . '.' . ($parts[2] + 1);
echo "SmartPRS3 release builder\nThis copy is version {$current}.\n\n";
$version = trim((string) ($argv[1] ?? '')) !== '' ? trim((string) $argv[1]) : $ask('New version', $next);

if (preg_match('/^\d+(\.\d+)*[A-Za-z0-9.-]*$/', $version) !== 1) {
    fwrite(STDERR, "A version looks like 3.0.1. Stopped.\n");
    exit(1);
}

if (version_compare($version, $current, '<')) {
    fwrite(STDERR, "{$version} is older than {$current}. Stopped.\n");
    exit(1);
}

$notes = $ask('What changed (one line, shown to clients)', 'Improvements and fixes.');
is_dir($out) || mkdir($out, 0775, true);
$zipPath = $out . DIRECTORY_SEPARATOR . 'smartprs3-' . $version . '.zip';

if (is_file($zipPath) && strtolower($ask("{$zipPath} already exists. Replace it? (y/n)", 'n')) !== 'y') {
    echo "Stopped; nothing changed.\n";
    exit(1);
}

$ver = ['note' => (string) ($ver['note'] ?? ''), 'product' => 'SmartPRS', 'version' => $version, 'released_on' => date('Y-m-d'), 'channel' => 'stable'];
file_put_contents($verFile, json_encode($ver, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// WHAT GOES IN: everything under the project folder except these.
// vendor/ (owner, 13 Nov 2026 — "413 Request Entity Too Large"): every server keeps its own libraries (composer), the
// updater never deletes what a package leaves out, and they made the package too big to upload. docs/ and tests/ are
// not needed to run.
$skipDirs = ['.git', 'storage', 'mobile', 'node_modules', 'Claude outputs', '.phpunit.cache', '.idea', '.vscode', 'public/uploads', 'vendor', 'docs', 'tests'];
$skipFiles = ['.env', '.env.bak', 'env-local.txt'];
$zip = new \ZipArchive();
$zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$count = 0;
$it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
    static function (\SplFileInfo $f) use ($root, $skipDirs, $skipFiles): bool {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));

        return $f->isDir() ? !in_array($rel, $skipDirs, true) : !in_array($rel, $skipFiles, true) && !str_contains($rel, '.upd-');
    }
));

foreach ($it as $f) {
    if ($f->isFile()) {
        $zip->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)));
        $count++;
    }
}

$zip->addFromString('release.json', json_encode(['product' => 'smartprs3', 'version' => $version, 'notes' => $notes,
    'built_at' => date('Y-m-d H:i:s'), 'files' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Packing {$count} files…\n";
$zip->close();

$sha = (string) hash_file('sha256', $zipPath);
$sig = '';
openssl_sign($sha, $sig, (string) file_get_contents($keyFile), OPENSSL_ALGO_SHA256);
file_put_contents($out . DIRECTORY_SEPARATOR . 'smartprs3-' . $version . '.sig', base64_encode($sig));
// ONE FILE TO UPLOAD (owner, 13 Nov 2026): smartprs3-<version>.sprs carries the package and its signature together;
// Releases unpacks it.
$sigPath = $out . DIRECTORY_SEPARATOR . 'smartprs3-' . $version . '.sig';
$bundlePath = $out . DIRECTORY_SEPARATOR . 'smartprs3-' . $version . '.sprs';
$bundle = new \ZipArchive();
$bundle->open($bundlePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$bundle->addFile($zipPath, 'package.zip');
$bundle->addFile($sigPath, 'package.sig');
$bundle->setCompressionName('package.zip', \ZipArchive::CM_STORE);
$bundle->close();
// The .zip and .sig stay beside it for a server that does not read .sprs yet (one installed before 13 Nov 2026).

$size = (int) filesize($bundlePath);
file_put_contents($out . DIRECTORY_SEPARATOR . 'smartprs3-' . $version . '.txt',
    "SmartPRS3 {$version}\r\nBuilt: " . date('d-m-Y H:i') . "\r\nPackage SHA-256: {$sha}\r\nRelease file: smartprs3-{$version}.sprs (" . number_format($size) . " bytes)\r\nNotes: {$notes}\r\n");

$size > 24 * 1048576 && print("\nWARNING: " . round($size / 1048576, 1) . " MB is more than a server accepts by default (25 MB). Tell Claude before uploading.\n");
echo "\nDone.\n  {$bundlePath}  (" . round($size / 1048576, 1) . " MB)  <- the one file to upload\n"
    . "  (smartprs3-{$version}.zip + .sig are there too, only for a server whose Releases screen still asks for two files)\n\n"
    . "Next: Super Admin -> Releases -> Add release -> choose the .sprs file -> Save -> Publish,\n"
    . "then Install on cloud server and/or tick the on-prem clients to send it to.\n"
    . "Also: commit and push this code (config/version.json now says {$version}).\n";
