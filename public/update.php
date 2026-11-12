<?php
/**
 * THE LICENCE SERVER'S PUBLIC DOOR (SaaS / app.smartprs.com only) — SmartPRS 2's
 * /update/* routes, same contract, answered by Surface\LicenceAuthority:
 *
 *   POST /update/activate            {key, fingerprint, server_name}  → {ok, cert, company}
 *   POST /update/heartbeat           {key, fingerprint, server_name}  → {ok, status, amc_expires_on, …}
 *   POST /update/device/activate     {key, device_uid, hostname}
 *   POST /update/device/deactivate   {key, device_uid}
 *   POST /update/check               {key, current_version}           → update offer (granted releases)
 *
 * An on-premise install refuses all of this: it is a client of a licence server,
 * never one itself.
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

$authority = new SmartPRS\Surface\LicenceAuthority($platform, $root);

if ((new SmartPRS\Surface\Licence($root))->isOnPrem()) {
    json_out(404, ['ok' => false, 'error' => 'This installation is not a licence server.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(405, ['ok' => false, 'error' => 'POST only.']);
}

// ponytail: a file-based per-IP counter (SmartPRS 2 throttle 10–60/min); a reverse proxy limit is the upgrade.
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$bucket = sys_get_temp_dir() . '/sp3-lic-' . md5($ip . '|' . date('YmdHi'));
$hits = (int) @file_get_contents($bucket) + 1;
@file_put_contents($bucket, (string) $hits);

if ($hits > 60) {
    json_out(429, ['ok' => false, 'error' => 'Too many requests — try again in a minute.']);
}

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$op = ($p = strpos($uri, '/update/')) !== false ? trim(substr($uri, $p + 8), '/') : trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$in = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', body_form((string) file_get_contents('php://input')));
[$status, $body] = $authority->handle($op, $in, $ip);
json_out($status, $body);
