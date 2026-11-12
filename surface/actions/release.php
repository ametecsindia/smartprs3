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

if ($op === 'save') {
    $version = trim(post('version'));
    $row = ['version' => $version, 'notes' => trim(post('notes')) ?: null, 'file_path' => trim(post('file_path')) ?: null,
        'checksum' => strtolower(trim(post('checksum'))) ?: null];

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
} elseif ($op === 'grant' && $before !== null) {
    $already = [];

    foreach ($platform->all('release_grants') as $g) {
        if ((string) $g['release_id'] === $id) {
            $already[] = (string) $g['client_id'];
        }
    }

    $targets = post('client_id') === '*'
        ? array_map(static fn (array $c): string => (string) $c['id'], $platform->all('onprem_clients'))
        : ($platform->find('onprem_clients', post('client_id')) === null ? [] : [post('client_id')]);
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
