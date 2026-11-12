<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * INSTALLS A RELEASE PACKAGE ON THIS SERVER (owner, 12 Nov 2026: cloud and on-prem update online once a
 * release is published on central). One installer for both: the cloud server (Super Admin → Releases →
 * Install on cloud server) and an on-prem server (Administration → Updates & Licence → Download & install).
 *
 * A package is the zip made by make-release.bat (tools/release/build-release.php): the application files
 * at the zip root plus release.json {product, version, notes, built_at}. Its SHA-256 is signed with the
 * licence private key (on the build PC only) and checked here against Licence::PUBLIC_KEY, so a package
 * nobody at Ametecs built is refused before a single file is touched.
 *
 * Install = verify → back up every file it will replace (storage/updates/backup-*.zip) → write each file
 * (temp file + rename) → on any write failure put the backup back → reset opcache → run pending migrations
 * (never --fresh) → record in storage/updates/history.json. Never touched: .env, storage/, public/uploads/
 * and whatever config/update-keep.json lists.
 */
final class Updater
{
    public const PACKAGE_PREFIX = 'smartprs3-';

    /** Paths an update never writes, whatever the package holds. */
    private const ALWAYS_KEEP = ['.env', '.env.bak', 'env-local.txt', 'storage/', 'public/uploads/', '.git/'];

    public function __construct(private readonly string $root)
    {
    }

    public function updatesDir(): string
    {
        $d = $this->root . '/storage/updates';
        is_dir($d) || @mkdir($d, 0775, true);

        return $d;
    }

    /** Where central keeps uploaded packages (storage/releases). */
    public function releasesDir(): string
    {
        $d = $this->root . '/storage/releases';
        is_dir($d) || @mkdir($d, 0775, true);

        return $d;
    }

    public function runningVersion(): string
    {
        $v = json_decode((string) @file_get_contents($this->root . '/config/version.json'), true);

        return is_array($v) ? (string) ($v['version'] ?? '') : '';
    }

    /** True when $sigB64 is Ametecs' signature over the package's SHA-256 (hex). */
    public static function signatureValid(string $sha256, string $sigB64): bool
    {
        $sig = base64_decode(trim($sigB64), true);

        return $sig !== false && $sha256 !== '' && openssl_verify(strtolower($sha256), $sig, Licence::PUBLIC_KEY, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The package's own release.json, or a reason it cannot be read.
     *
     * @return array{ok: bool, manifest: array<string, mixed>, error: string}
     */
    public static function manifest(string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'PHP zip extension is not enabled on this server.'];
        }

        $z = new \ZipArchive();

        if ($z->open($zipPath) !== true) {
            return ['ok' => false, 'manifest' => [], 'error' => 'Not a readable zip file.'];
        }

        $m = json_decode((string) $z->getFromName('release.json'), true);
        $z->close();

        if (!is_array($m) || trim((string) ($m['version'] ?? '')) === '') {
            return ['ok' => false, 'manifest' => [], 'error' => 'This zip is not a SmartPRS3 release package (release.json missing).'];
        }

        return ['ok' => true, 'manifest' => $m, 'error' => ''];
    }

    /**
     * Check a package against its expected SHA-256 and signature.
     *
     * @return array{ok: bool, error: string, sha256: string}
     */
    public static function verify(string $zipPath, string $expectedSha, string $sigB64): array
    {
        if (!is_file($zipPath)) {
            return ['ok' => false, 'error' => 'Package file not found.', 'sha256' => ''];
        }

        $sha = (string) hash_file('sha256', $zipPath);

        if ($expectedSha !== '' && !hash_equals(strtolower($expectedSha), $sha)) {
            return ['ok' => false, 'error' => 'Package checksum does not match — the download is incomplete or changed. Nothing was installed.', 'sha256' => $sha];
        }

        if (!self::signatureValid($sha, $sigB64)) {
            return ['ok' => false, 'error' => 'Package signature is not valid — it was not built by Ametecs. Nothing was installed.', 'sha256' => $sha];
        }

        return ['ok' => true, 'error' => '', 'sha256' => $sha];
    }

    /** @return list<string> */
    private function keepList(): array
    {
        $extra = json_decode((string) @file_get_contents($this->root . '/config/update-keep.json'), true);

        return array_merge(self::ALWAYS_KEEP, array_map('strval', (array) ($extra['keep'] ?? [])));
    }

    private function kept(string $rel, array $keep): bool
    {
        foreach ($keep as $k) {
            if ($rel === rtrim($k, '/') || (str_ends_with($k, '/') && str_starts_with($rel, $k))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Install a verified package over this installation.
     *
     * @return array{ok: bool, version: string, from: string, message: string, log: string}
     */
    public function install(string $zipPath, string $by): array
    {
        @set_time_limit(0);
        $from = $this->runningVersion();
        $m = self::manifest($zipPath);
        $fail = fn (string $msg, string $log = ''): array => ['ok' => false, 'version' => (string) ($m['manifest']['version'] ?? ''), 'from' => $from, 'message' => $msg, 'log' => $log];

        if (!$m['ok']) {
            return $fail($m['error']);
        }

        $version = (string) $m['manifest']['version'];
        $z = new \ZipArchive();
        $z->open($zipPath);
        // The keep-list on this server AND the one the package brings (a first update has no list here yet).
        $pkgKeep = json_decode((string) $z->getFromName('config/update-keep.json'), true);
        $keep = array_merge($this->keepList(), array_map('strval', (array) ($pkgKeep['keep'] ?? [])));
        $files = [];

        for ($i = 0; $i < $z->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $z->getNameIndex($i));

            if ($name === '' || str_ends_with($name, '/') || $name === 'release.json') {
                continue;
            }

            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1) {
                $z->close();

                return $fail('The package holds an unsafe path (' . $name . '). Nothing was installed.');
            }

            $this->kept($name, $keep) || $files[$i] = $name;
        }

        // CAN THIS SERVER'S PHP WRITE THE CODE? Asked before anything changes, folder by folder.
        $blocked = [];

        foreach ($files as $name) {
            $dir = dirname($this->root . '/' . $name);

            while (!is_dir($dir) && $dir !== dirname($dir)) {
                $dir = dirname($dir);
            }

            $target = $this->root . '/' . $name;

            if (!is_writable($dir) || (is_file($target) && !is_writable($target))) {
                $blocked[substr($dir, strlen($this->root)) ?: '/'] = true;
            }
        }

        if ($blocked !== []) {
            $z->close();

            return $fail('This server\'s PHP cannot write the application folder (' . implode(', ', array_slice(array_keys($blocked), 0, 6))
                . (count($blocked) > 6 ? ' …' : '') . '). Give the web server write access once — see deploy/RELEASE-AND-UPDATE.md, step "Server permissions". Nothing was installed.');
        }

        // BACK UP every file about to be replaced.
        $backupPath = $this->updatesDir() . '/backup-' . ($from ?: 'unknown') . '-to-' . $version . '-' . date('Ymd-His') . '.zip';
        $backup = new \ZipArchive();

        if ($backup->open($backupPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $z->close();

            return $fail('Could not create the backup in storage/updates. Nothing was installed.');
        }

        $existing = [];

        foreach ($files as $name) {
            if (is_file($this->root . '/' . $name)) {
                $backup->addFile($this->root . '/' . $name, $name);
                $existing[] = $name;
            }
        }

        $backup->close();

        // WRITE the files; a failure part-way puts the backup back.
        $written = 0;
        $created = [];

        foreach ($files as $i => $name) {
            $target = $this->root . '/' . $name;
            is_dir(dirname($target)) || @mkdir(dirname($target), 0775, true);
            $data = $z->getFromIndex($i);
            $tmp = $target . '.upd-' . bin2hex(random_bytes(4));

            if ($data === false || @file_put_contents($tmp, $data) === false || !@rename($tmp, $target)) {
                @unlink($tmp);
                $z->close();
                $this->restore($backupPath, $created);

                return $fail('Writing ' . $name . ' failed, so every file was put back as it was. Nothing changed.');
            }

            in_array($name, $existing, true) || $created[] = $name;
            $written++;
        }

        $z->close();
        function_exists('opcache_reset') && @opcache_reset();

        // MIGRATIONS (pending only, never --fresh), then the application account's grants.
        $log = "Installed {$written} file(s); backup " . basename($backupPath) . "\n";
        $migrated = $this->runTool('tools/db/migrate.php');
        $log .= $migrated['log'];

        if ($migrated['ok']) {
            $grant = $this->runTool('tools/db/grant-application-account.php --apply');
            $log .= $grant['log'] . ($grant['ok'] ? '' : "\n(grant step reported a problem — the update itself is installed)\n");
        }

        $history = json_decode((string) @file_get_contents($this->updatesDir() . '/history.json'), true);
        $history = is_array($history) ? $history : [];
        $history[] = ['at' => date('Y-m-d H:i:s'), 'from' => $from, 'to' => $version, 'by' => $by, 'files' => $written,
            'backup' => basename($backupPath), 'migrations' => $migrated['ok'] ? 'ok' : 'failed'];
        @file_put_contents($this->updatesDir() . '/history.json', json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @file_put_contents($this->updatesDir() . '/last-install.log', $log);

        return ['ok' => true, 'version' => $version, 'from' => $from, 'log' => $log,
            'message' => "Updated {$from} → {$version}." . ($migrated['ok'] ? '' : ' The files are installed but the database migration did not finish — see the log below.')];
    }

    /** @param list<string> $created files the failed install added (removed again) */
    private function restore(string $backupPath, array $created): void
    {
        $b = new \ZipArchive();

        if ($b->open($backupPath) === true) {
            $b->extractTo($this->root);
            $b->close();
        }

        foreach ($created as $name) {
            @unlink($this->root . '/' . $name);
        }
    }

    /** The PHP command-line binary (not php-fpm), for running tools/*.php. */
    private function phpCli(): string
    {
        $env = Database::env($this->root . '/.env');
        $candidates = array_filter([(string) ($env['SMARTPRS_PHP_CLI'] ?? ''), PHP_BINDIR . '/php', PHP_BINDIR . '/php.exe', '/usr/bin/php', '/usr/local/bin/php']);

        foreach (glob('C:/laragon/bin/php/*/php.exe') ?: [] as $c) {
            $candidates[] = $c;
        }

        if (PHP_SAPI === 'cli') {
            array_unshift($candidates, PHP_BINARY);
        }

        foreach ($candidates as $c) {
            if (is_file($c) && !str_contains(basename($c), 'fpm') && !str_contains(basename($c), 'cgi')) {
                return $c;
            }
        }

        return '';
    }

    /** @return array{ok: bool, log: string} */
    private function runTool(string $cmd): array
    {
        $php = $this->phpCli();

        if ($php === '') {
            return ['ok' => false, 'log' => "\n$ php {$cmd}\nPHP command line not found — set SMARTPRS_PHP_CLI in .env, or run it on the server.\n"];
        }

        $p = @proc_open('"' . $php . '" ' . $cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);

        if (!is_resource($p)) {
            return ['ok' => false, 'log' => "\n$ php {$cmd}\ncould not start\n"];
        }

        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($p);

        return ['ok' => $code === 0, 'log' => "\n$ php {$cmd}   (exit {$code})\n" . $out];
    }
}
