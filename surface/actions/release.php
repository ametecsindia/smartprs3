<?php
/**
 * RECORD A RELEASE, PUBLISH IT, MARK IT APPLIED ON THE CLOUD, GRANT IT TO
 * ON-PREM CLIENTS. Nothing here moves a file: a release row is the record
 * of a build the operator deploys, and a grant is what a client's server is
 * allowed to fetch. A version is recorded once — a second row with the same
 * version is refused, because two builds with one number is how a client
 * ends up on the wrong one.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ?r=releases');
    exit;
}

$op = post('op');
$id = post('id');
$before = $id === '' ? null : $platform->find('releases', $id);
$now = date('Y-m-d H:i:s');
$by = (string) $session->user()['email'];

$relRoot = dirname(__DIR__, 2);
$relUpdater = new SmartPRS\Surface\Updater($relRoot);
$relBack = static function (string $problem, string $to = '?r=releases'): never { $_SESSION['flash_problem'] = $problem; header('Location: ' . $to); exit; };

if ($op === 'save') {
    $version = trim(post('version'));
    $row = ['version' => $version, 'notes' => trim(post('notes')) ?: null, 'file_path' => trim(post('file_path')) ?: null,
        'checksum' => strtolower(trim(post('checksum'))) ?: null];
    $editTo = '?r=releases&edit=' . ($id === '' ? 'new' : $id);

    // THE PACKAGE (12 Nov 2026): the .zip + .sig from C:\SmartPRS3-Releases, uploaded here — or, when the zip is
    // too big for an upload, copied into storage/releases on this server and picked from the list.
    $upZip = (array) ($_FILES['package'] ?? []);
    $upSig = (array) ($_FILES['signature'] ?? []);
    $picked = basename(post('server_file'));
    $zipTmp = '';
    $sigText = '';

    // ONE FILE (owner, 13 Nov 2026: "why two files … it should cover everything within single file"): make-release.bat now
    // makes smartprs3-<version>.sprs, which carries the package and its signature together; it is unpacked here.
    $unbundle = static function (string $path) use ($relBack, $editTo): ?array {
        $z = new \ZipArchive();

        if ($z->open($path) !== true || $z->locateName('package.zip') === false || $z->locateName('package.sig') === false) {
            $z->close();

            return null;
        }

        $inner = tempnam(sys_get_temp_dir(), 'sprs');
        $ok = $inner !== false && file_put_contents($inner, (string) $z->getFromName('package.zip')) !== false;
        $sig = trim((string) $z->getFromName('package.sig'));
        $z->close();
        $ok || $relBack('Could not unpack the release file on this server (temporary folder not writable).', $editTo);

        return [$inner, $sig];
    };

    if ((int) ($upZip['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $zipTmp = (string) $upZip['tmp_name'];
        [$zipTmp, $sigText] = $unbundle($zipTmp) ?? [$zipTmp, (int) ($upSig['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? trim((string) file_get_contents((string) $upSig['tmp_name'])) : ''];
        $sigText !== '' || $relBack('Choose the .sprs release file from C:\\SmartPRS3-Releases (an older .zip needs its .sig too).', $editTo);
    } elseif (in_array((int) ($upZip['error'] ?? UPLOAD_ERR_NO_FILE), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $relBack('The package is bigger than this server accepts for an upload (' . ini_get('upload_max_filesize') . '). Copy the .zip and .sig into storage/releases on the server and pick it from the list instead.', $editTo);
    } elseif ($picked !== '' && is_file($relUpdater->releasesDir() . '/' . $picked)) {
        $zipTmp = $relUpdater->releasesDir() . '/' . $picked;
        [$zipTmp, $sigText] = $unbundle($zipTmp) ?? [$zipTmp, trim((string) @file_get_contents(preg_replace('/\.zip$/i', '.sig', $zipTmp)))];
        $sigText !== '' || $relBack('No ' . preg_replace('/\.zip$/i', '.sig', $picked) . ' beside it in storage/releases.', $editTo);
    }

    if ($zipTmp !== '') {
        $man = SmartPRS\Surface\Updater::manifest($zipTmp);
        $man['ok'] || $relBack($man['error'], $editTo);
        $chk = SmartPRS\Surface\Updater::verify($zipTmp, '', $sigText);
        $chk['ok'] || $relBack($chk['error'], $editTo);
        $version = (string) $man['manifest']['version'];
        $dest = $relUpdater->releasesDir() . '/' . SmartPRS\Surface\Updater::PACKAGE_PREFIX . $version . '.zip';

        if ($zipTmp !== $dest && !(is_uploaded_file($zipTmp) ? move_uploaded_file($zipTmp, $dest) : copy($zipTmp, $dest))) {
            $relBack('Could not store the package in storage/releases (folder not writable).', $editTo);
        }

        file_put_contents(preg_replace('/\.zip$/i', '.sig', $dest), $sigText);
        $row = ['version' => $version, 'notes' => trim(post('notes')) ?: ((string) ($man['manifest']['notes'] ?? '') ?: null),
            'file_path' => 'storage/releases/' . basename($dest), 'checksum' => $chk['sha256'], 'size' => (int) filesize($dest)];
    }

    if (preg_match('/^\d+(\.\d+)*[A-Za-z0-9.-]*$/', $version) !== 1) {
        $_SESSION['flash_problem'] = 'A version looks like 3.1.0.';
        header('Location: ?r=releases&edit=' . ($id === '' ? 'new' : $id));
        exit;
    }

    foreach ($platform->all('releases') as $r) {
        if ((string) $r['version'] === $version && (string) $r['id'] !== $id) {
            $_SESSION['flash_problem'] = "Version {$version} is already recorded.";
            header('Location: ?r=releases&edit=' . ($id === '' ? 'new' : $id));
            exit;
        }
    }

    if ($before !== null) {
        $row['id'] = $id;
    }

    $saved = $platform->put('releases', $row);
    $audit($before === null ? 'release.created' : 'release.updated', 'releases', (string) $saved['id'], $before, $row, 'platform staff');
    $_SESSION['flash_notice'] = "Release {$version} saved.";
} elseif ($op === 'publish' && $before !== null) {
    $platform->put('releases', ['id' => $id, 'published_at' => $now]);
    $audit('release.published', 'releases', $id, ['published_at' => $before['published_at']], ['published_at' => $now], $by);
    $_SESSION['flash_notice'] = 'Release ' . (string) $before['version'] . ' published; grant it to clients below.';
} elseif ($op === 'applied' && $before !== null) {
    $platform->put('releases', ['id' => $id, 'applied_platform_at' => $now]);
    $audit('release.applied_platform', 'releases', $id, ['applied_platform_at' => $before['applied_platform_at']], ['applied_platform_at' => $now], $by);
    $_SESSION['flash_notice'] = 'Recorded: the cloud runs ' . (string) $before['version'] . '. Bump config/version.json to match if it does not already.';
} elseif ($op === 'install-cloud' && $before !== null) {
    // ONE CLICK UPDATES THE CLOUD SERVER (owner, 12 Nov 2026): every cloud company moves together.
    (string) ($before['published_at'] ?? '') !== '' || $relBack('Publish the release first.');
    $pkg = (new SmartPRS\Surface\LicenceAuthority($platform, $relRoot))->packagePath($before);
    $pkg !== null || $relBack('This release has no package on the server — Edit it and upload the .zip and .sig.');
    $chk = SmartPRS\Surface\Updater::verify($pkg, (string) ($before['checksum'] ?? ''), trim((string) @file_get_contents(preg_replace('/\.zip$/i', '.sig', $pkg))));
    $chk['ok'] || $relBack($chk['error']);
    session_write_close();
    $res = $relUpdater->install($pkg, $by);
    session_start();
    $_SESSION['release_log'] = $res['log'];

    if ($res['ok']) {
        $platform->put('releases', ['id' => $id, 'applied_platform_at' => date('Y-m-d H:i:s')]);
        $audit('release.installed_cloud', 'releases', $id, null, ['from' => $res['from'], 'to' => $res['version']], $by);
        $_SESSION['flash_notice'] = 'Cloud server: ' . $res['message'];
    } else {
        $_SESSION['flash_problem'] = 'Cloud server not updated: ' . $res['message'];
    }
} elseif ($op === 'grant' && $before !== null) {
    $already = [];

    foreach ($platform->all('release_grants') as $g) {
        if ((string) $g['release_id'] === $id) {
            $already[] = (string) $g['client_id'];
        }
    }

    // SEND TO THE CLIENTS TICKED (owner, 12 Nov 2026) — several at once, or every on-prem client.
    $picked = array_map('strval', (array) ($_POST['client_ids'] ?? []));
    $targets = in_array('*', $picked, true) || post('client_id') === '*'
        ? array_map(static fn (array $c): string => (string) $c['id'], $platform->all('onprem_clients'))
        : array_values(array_filter($picked !== [] ? $picked : [post('client_id')], static fn (string $c): bool => $c !== '' && $platform->find('onprem_clients', $c) !== null));
    $count = 0;

    foreach (array_diff($targets, $already) as $clientId) {
        $g = $platform->put('release_grants', ['release_id' => $id, 'client_id' => $clientId, 'granted_by' => $by]);
        $audit('release.granted', 'release_grants', (string) $g['id'], null, ['release_id' => $id, 'client_id' => $clientId], (string) $before['version']);
        $count++;
    }

    $_SESSION[$count > 0 ? 'flash_notice' : 'flash_problem'] = $count > 0
        ? (string) $before['version'] . " granted to {$count} client(s)."
        : 'No client to grant to — pick one that exists and has not got it.';
} else {
    $_SESSION['flash_problem'] = 'Nothing to do: unknown release or operation.';
}

header('Location: ?r=releases');
exit;
