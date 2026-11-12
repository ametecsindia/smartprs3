<?php
/**
 * D10 — document storage inventory and public-disk exposure.
 *
 * Five code paths write employee KYC, PCC, PAN, Aadhaar, bank proofs, commission
 * evidence and agent documents to the WEB-SERVED disk, and there is no .htaccess in
 * the repository at all. If public/storage is a live symlink, every one of those
 * files is fetchable without authentication.
 *
 * R-XXIX-029: the access-log extract must be collected FIRST, before any other
 * discovery step, because logs rotate and they are the evidence base for the
 * disclosure assessment. Run this check first. If the logs have already rotated,
 * that is recorded as "exposure window not evidenced" — which is a finding, not a
 * blank.
 *
 * No database access. Runs on Windows/Laragon and on Linux.
 * Standalone: php checks/d10_files.php --app=C:\laragon\www\smartprs --out=./pack \
 *             --access-log=C:\laragon\etc\apache2\logs\access.log
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

const D10_EXPOSED_TREES = ['employee-docs', 'emp-photos', 'commission-proofs', 'offroll-docs',
    'self-onboarding', 'documents', 'letters', 'assets'];

function d10_files(DscCtx $c): void
{
    $app = isset($c->opt['app']) ? rtrim((string) $c->opt['app'], "/\\") : null;
    if ($app === null || !is_dir($app)) {
        $c->finding('HIGH', 'files', 'Document storage NOT inventoried',
            'No --app path was given, or it is not a directory. D10 is mandatory for Gate G-2: re-run this '
            . 'check on the application server with --app=PATH.');
        dsc_finish($c, 'd10_files');
        return;
    }

    $sep = DIRECTORY_SEPARATOR;
    $storage = $app . $sep . 'storage' . $sep . 'app' . $sep . 'public';
    $publicLink = $app . $sep . 'public' . $sep . 'storage';

    // D10.1  Is the public symlink present?
    $linkState = is_link($publicLink) ? 'SYMLINK -> ' . (readlink($publicLink) ?: '?')
        : (is_dir($publicLink) ? 'REAL DIRECTORY (copied, not linked)' : 'ABSENT');

    // D10.2  Is anything protecting it?
    $htaccess = [];
    foreach ([$app . $sep . 'public' . $sep . '.htaccess',
              $publicLink . $sep . '.htaccess',
              $storage . $sep . '.htaccess'] as $f) {
        $htaccess[] = ['path' => $f, 'present' => is_file($f) ? 'yes' : 'no',
            'bytes' => is_file($f) ? (string) filesize($f) : '',
            'content' => is_file($f) ? (string) file_get_contents($f) : ''];
    }
    dsc_write_csv($c, 'files/htaccess.csv', $htaccess, 'none');

    $exposed = $linkState !== 'ABSENT';
    $protectedByHtaccess = false;
    foreach ($htaccess as $h) {
        if ($h['present'] === 'yes' && preg_match('/deny|Require\s+all\s+denied|RewriteRule/i', (string) $h['content'])) {
            $protectedByHtaccess = true;
        }
    }
    $c->finding($exposed && !$protectedByHtaccess ? 'BLOCKER' : ($exposed ? 'HIGH' : 'INFO'), 'files',
        "public/storage: $linkState" . ($protectedByHtaccess ? ' (an .htaccess with deny/rewrite rules is present)' : ''),
        $exposed && !$protectedByHtaccess
            ? 'Everything under storage/app/public is served by the web server WITHOUT AUTHENTICATION, and no '
              . '.htaccess protects it. That includes KYC, PCC, PAN, Aadhaar, bank proofs and self-onboarding ID '
              . 'documents. This is a live DPDP exposure on this install today, not a migration item — §XXIX.4.8. '
              . 'Report it to the customer during the visit, not in the pack afterwards.'
            : 'Recorded for the exposure assessment.',
        ['link' => $linkState]);

    // D10.3  Inventory of the exposed trees, plus every other top-level tree present.
    $inventory = [];
    $trees = D10_EXPOSED_TREES;
    if (is_dir($storage)) {
        foreach ((array) scandir($storage) as $e) {
            if ($e !== '.' && $e !== '..' && is_dir($storage . $sep . $e) && !in_array($e, $trees, true)) {
                $trees[] = $e;
            }
        }
    }
    sort($trees);

    $hashFiles = isset($c->opt['hash-files']);
    $fileList = [];
    foreach ($trees as $tree) {
        $p = $storage . $sep . $tree;
        if (!is_dir($p)) {
            $inventory[] = ['tree' => $tree, 'state' => 'ABSENT', 'files' => 0, 'bytes' => 0, 'oldest' => '', 'newest' => ''];
            continue;
        }
        [$n, $b, $lo, $hi] = d10_walk($p, $tree, $fileList, $hashFiles, $c);
        $inventory[] = ['tree' => $tree, 'state' => 'PRESENT', 'files' => $n, 'bytes' => $b,
            'oldest' => $lo ? gmdate('Y-m-d', $lo) : '', 'newest' => $hi ? gmdate('Y-m-d', $hi) : ''];
    }
    usort($inventory, static fn($a, $b) => $a['tree'] <=> $b['tree']);
    dsc_write_csv($c, 'files/inventory.csv', $inventory, 'none');

    // File-level manifest. Paths are hashed in a redacted pack: a document path is
    // "employee-docs/EMP001-aadhaar.pdf" and that is personal data by itself.
    if ($fileList) {
        usort($fileList, static fn($a, $b) => $a['relative_path'] <=> $b['relative_path']);
        if ($c->redact) {
            foreach ($fileList as &$f) {
                $f['relative_path'] = $f['tree'] . '/' . dsc_hash($c, $f['relative_path'])
                    . '.' . strtolower(pathinfo($f['relative_path'], PATHINFO_EXTENSION));
            }
            unset($f);
        }
        dsc_write_csv($c, 'files/file_manifest.csv', $fileList, 'none');
    }

    $totalFiles = array_sum(array_column($inventory, 'files'));
    $totalBytes = array_sum(array_column($inventory, 'bytes'));
    $c->finding('INFO', 'files', "$totalFiles document files, " . round($totalBytes / 1048576, 1) . ' MB',
        'R-XXIX-030: every file must be inventoried by SHA-256, size and path, and its hash verified after '
        . 'transfer to SmartPRS 3 private storage. A hash mismatch after transfer is a FAILED LOAD, not a '
        . 'warning.' . ($hashFiles ? '' : ' Hashes were NOT computed — re-run with --hash-files before transfer.'));

    // D10.4  Self-onboarding writes to the PUBLIC disk under a predictable path.
    $dirs = [];
    if (is_dir($storage)) {
        foreach ((array) scandir($storage) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $storage . $sep . $e;
            if (!is_dir($p)) {
                continue;
            }
            $sub = [];
            foreach ((array) scandir($p) as $e2) {
                if ($e2 !== '.' && $e2 !== '..' && is_dir($p . $sep . $e2)) {
                    $sub[] = $e2;
                }
            }
            sort($sub);
            $dirs[] = ['directory' => $e, 'subdirectories' => count($sub),
                'sample' => implode('|', array_slice($c->redact ? array_map(static fn($x) => dsc_hash($c, $x), $sub) : $sub, 0, 10))];
        }
    }
    dsc_write_csv($c, 'files/public_tree.csv', $dirs, 'none');

    // D10.5  Access-log evidence — the DPDP breach-assessment input. Collect FIRST.
    $log = (string) ($c->opt['access-log'] ?? '');
    if ($log === '' || !is_readable($log)) {
        dsc_write_text($c, 'files/public_exposure.csv',
            "status\nEXPOSURE WINDOW NOT EVIDENCED — no readable access log was supplied (--access-log)\n");
        $c->finding('HIGH', 'files', 'Exposure window NOT evidenced',
            'R-XXIX-029: no web-server access log was read, so it is not known whether anything outside the '
            . 'application has actually fetched these paths. Access logs rotate. If they have already rotated '
            . 'beyond the retention window, record that explicitly and move on; if they have not, collect them '
            . 'NOW — this is the evidence base for the DPDP disclosure assessment.');
    } else {
        $hits = [];
        $fh = fopen($log, 'r');
        $lines = 0;
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $lines++;
                if (!preg_match('#/storage/([a-z0-9_-]+)/#i', $line, $m)) {
                    continue;
                }
                if (!in_array($m[1], D10_EXPOSED_TREES, true)) {
                    continue;
                }
                $f = preg_split('/\s+/', trim($line)) ?: [];
                $ip = $f[0] ?? '?';
                $status = $f[8] ?? '?';
                $key = ($c->redact ? dsc_hash($c, $ip) : $ip) . '|' . $m[1] . '|' . $status;
                $hits[$key] = ($hits[$key] ?? 0) + 1;
            }
            fclose($fh);
        }
        arsort($hits);
        $rows = [];
        foreach (array_slice($hits, 0, 500, true) as $k => $n) {
            [$ip, $tree, $status] = explode('|', $k);
            $rows[] = ['client' => $ip, 'tree' => $tree, 'http_status' => $status, 'requests' => $n];
        }
        dsc_write_csv($c, 'files/public_exposure.csv', $rows, 'none');
        $ok = array_sum(array_map(static fn($r) => (int) $r['http_status'] === 200 ? $r['requests'] : 0, $rows));
        $c->finding($ok > 0 ? 'BLOCKER' : 'INFO', 'files',
            "$ok successful (HTTP 200) fetches of document paths in " . number_format($lines) . ' log lines',
            $ok > 0
                ? 'Documents on the public disk HAVE been fetched over the web server on this install. Client '
                  . 'addresses are pseudonymised in a redacted pack; the raw log stays with the customer. This '
                  . 'is a DPDP disclosure assessment input and must be escalated the same day.'
                : 'No successful fetches found in the supplied log window. Record the window covered.',
            ['log' => basename($log), 'lines' => $lines]);
    }

    dsc_finish($c, 'd10_files');
}

/** @return array{0:int,1:int,2:int,3:int} files, bytes, oldest mtime, newest mtime */
function d10_walk(string $dir, string $tree, array &$fileList, bool $hash, DscCtx $c): array
{
    $n = 0;
    $b = 0;
    $lo = 0;
    $hi = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY);
    } catch (Throwable $e) {
        $c->note('d10', "cannot walk $dir: " . $e->getMessage());
        return [0, 0, 0, 0];
    }
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $n++;
        $size = (int) $f->getSize();
        $b += $size;
        $m = (int) $f->getMTime();
        $lo = $lo === 0 ? $m : min($lo, $m);
        $hi = max($hi, $m);
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $fileList[] = [
            'tree' => $tree,
            'relative_path' => $rel,
            'bytes' => $size,
            'modified' => gmdate('Y-m-d H:i:s', $m),
            'sha256' => $hash ? hash_file('sha256', $f->getPathname()) : '',
        ];
    }
    return [$n, $b, $lo, $hi];
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d10_files.php', 'd10_files');
}
