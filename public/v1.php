<?php
/**
 * SMARTPRS 2's PUBLIC API v1, ANSWERED BY SMARTPRS 3 — so the Smart Biometric Bridge,
 * SmartEPT and the Caller field-force app keep working when a client moves over.
 * Same paths, same headers, same bodies, same answers (routes/api.php in SmartPRS 2):
 *
 *   GET  /api/v1/ping                               X-Api-Key (any scope)
 *   POST /api/v1/attendance/punches                 X-Api-Key, scope "ingest"   {punches: [...]}
 *   POST /api/v1/webhooks/smartept/{slug}           X-SmartEPT-Signature (HMAC of the raw body)
 *   POST /api/v1/fieldforce/geofence/check          X-Api-Key, scope "ingest"   {emp_code, lat, lng}
 *   POST /api/v1/fieldforce/attendance              X-Api-Key, scope "ingest"   {events: [...]}
 *   POST /api/v1/offroll/activity                   X-Api-Key, scope "ingest"   {events: [...]}  off-roll agents (23 Oct 2026):
 *        each event {external_id, agent_code | mobile, type: login|logout|visit, at (ISO with offset), lat, lng,
 *        and for a visit: account_ref, outcome (visited|ptp|paid|not_found|refused|shifted), amount, note}
 *
 * Keys are issued under Time & Attendance → API Keys & Integrations.
 * Stateless: no session, no cookie. Reached via the rewrite in public/.htaccess /
 * the nginx conf, or directly as /v1.php/ping where no rewrite exists.
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

use SmartPRS\Surface\PunchIngest;
use SmartPRS\Surface\Secret;
use SmartPRS\Surface\SmartEpt;

$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$path = ($p = strpos($uri, '/api/v1/')) !== false ? substr($uri, $p + 8) : ltrim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$path = trim($path, '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string) file_get_contents('php://input');
$deny = static fn (int $status, string $msg, ?string $code = null): never
    => json_out($status, ['error' => ['code' => $code ?? ('API_KEY_' . $status), 'message' => $msg]]);

// ------------------------------------------------------------- SmartEPT webhook
if ($method === 'POST' && preg_match('~^webhooks/smartept/([A-Za-z0-9]{8,32})$~', $path, $m) === 1) {
    if (strlen($raw) > SmartEpt::MAX_BODY_BYTES) {
        $deny(413, 'Payload too large.', 'WEBHOOK_413');
    }

    $ep = null;

    foreach ($platform->all('smartept_webhook_endpoints') as $row) {
        if (hash_equals((string) $row['slug'], $m[1])) {
            $ep = $row;
        }
    }

    $secret = $ep === null ? null : Secret::decrypt((string) $ep['secret'], $root . '/.env');
    $sig = (string) ($_SERVER['HTTP_X_SMARTEPT_SIGNATURE'] ?? '');

    if ($ep === null || $secret === null || !SmartEpt::signatureMatches($raw, $secret, $sig)) {
        $deny(401, 'Webhook endpoint or signature is not valid.', 'WEBHOOK_401');
    }

    if (!(bool) $ep['active']) {
        $deny(401, 'This webhook endpoint has been revoked.', 'WEBHOOK_401');
    }

    $record = static function (?string $event, string $status, int $accepted, int $rejected) use ($pdo, $ep): void {
        $pdo->prepare('UPDATE smartept_webhook_endpoints SET last_received_at = NOW(), last_event = ?, last_status = ?,
            received_count = received_count + 1, accepted_count = accepted_count + ?, rejected_count = rejected_count + ?, updated_at = NOW() WHERE id = ?')
            ->execute([$event === null ? null : mb_substr($event, 0, 64), mb_substr($status, 0, 191), $accepted, $rejected, $ep['id']]);
    };
    $payload = body_json($raw);

    if ($payload === null) {
        $record(null, 'REJECTED body was not JSON', 0, 0);
        json_out(422, ['error' => ['code' => 'WEBHOOK_422', 'message' => 'Body was not valid JSON.']]);
    }

    $event = is_string($payload['event'] ?? null) ? trim($payload['event']) : '';
    $hEvent = trim((string) ($_SERVER['HTTP_X_SMARTEPT_EVENT'] ?? ''));

    if ($event === '') {
        $event = $hEvent;
    } elseif ($hEvent !== '' && $hEvent !== $event) {
        $record($event, 'REJECTED event header/body mismatch', 0, 0);
        json_out(422, ['error' => ['code' => 'WEBHOOK_422', 'message' => 'X-SmartEPT-Event ("' . $hEvent . '") does not match the body event ("' . $event . '").']]);
    }

    $subscribed = json_decode((string) ($ep['events'] ?? ''), true);
    $subscribed = is_array($subscribed) && $subscribed !== [] ? $subscribed : SmartEpt::EVENTS;

    if (!in_array($event, SmartEpt::EVENTS, true) || !in_array($event, $subscribed, true)) {
        $record($event ?: null, 'IGNORED not subscribed', 0, 0);
        json_out(202, ['ok' => true, 'ignored' => true, 'event' => $event ?: null, 'message' => 'This endpoint does not accept "' . ($event ?: 'unnamed') . '".']);
    }

    $t = SmartEpt::punchesFor($event, $payload);

    if ($t['punches'] === []) {
        $record($event, 'OK nothing to store', 0, 0);
        json_out(200, ['ok' => true, 'event' => $event, 'batch' => ['received' => 0, 'accepted' => 0, 'duplicates' => 0, 'pending' => 0,
            'rejected' => 0, 'skipped' => $t['skipped']], 'results' => [], 'message' => $t['note'] ?? 'Nothing in this payload needed storing.']);
    }

    $tid = (string) $ep['tenant_id'];
    $ingest = new PunchIngest($platform->forTenant($tid), $pdo, $tid);
    // ONE ATTENDANCE SOURCE PER COMPANY (owner, 12 Nov 2026: "only 1, either from SmartEPT OR QR scanner, to avoid
    // conflicting and redundant data"): a punch for an employee whose company records attendance by QR Attendance is
    // refused here (QR Attendance → Module & policy). A company on SmartEPT punch already refuses web / app / QR punches.
    $srcRefused = [];
    try {
        $srcStore = $platform->forTenant($tid);
        $srcQr = SmartPRS\Surface\QrAttendance::load(new SmartPRS\Surface\Customisation($srcStore), $root);
        $srcCo = [];
        foreach ($srcStore->all('employees') as $se) {
            $srcCo[strtolower(SmartPRS\Surface\Employment::code($se))] = (string) ($se['employer_company_id'] ?? '');
        }
        $t['punches'] = array_values(array_filter($t['punches'], static function (array $p) use ($ingest, $srcCo, $srcQr, &$srcRefused): bool {
            $code = $ingest->match((string) ($p['employee_code'] ?? ''), (string) ($p['device_user_id'] ?? ''), (string) ($p['device_user_id'] ?? ''));
            if ($code !== null && $srcQr->sourceFor($srcCo[strtolower($code)] ?? '') === 'qr') {
                $srcRefused[] = ['external_id' => $p['external_id'], 'status' => 'rejected', 'reason' => 'COMPANY_USES_QR_ATTENDANCE'];
                return false;
            }
            return true;
        }));
    } catch (\Throwable $srcX) {
        error_log('SmartEPT webhook: attendance source not read (punches taken as before): ' . $srcX->getMessage());
    }
    $out = $t['punches'] === [] ? ['batch' => ['received' => 0, 'accepted' => 0, 'duplicates' => 0, 'pending' => 0, 'rejected' => 0], 'results' => []]
        : $ingest->ingest($t['punches'], SmartEpt::SOURCE);
    $out['batch']['received'] += count($srcRefused);
    $out['batch']['rejected'] += count($srcRefused);
    $out['results'] = array_merge($out['results'], $srcRefused);
    $c = $out['batch'] + ['skipped' => $t['skipped']];
    $record($event, 'OK ' . $c['accepted'] . ' accepted, ' . $c['duplicates'] . ' duplicate, ' . $c['pending'] . ' pending, ' . $c['rejected'] . ' rejected',
        $c['accepted'], $c['rejected']);
    json_out(200, ['ok' => true, 'event' => $event, 'batch' => $c, 'results' => $out['results']]);
}

// ------------------------------------------------------------------- API key
$presented = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if ($presented === '' && preg_match('~^Bearer\s+(.+)$~i', trim($auth), $bm) === 1) {
    $presented = trim($bm[1]);
}

if ($presented === '') {
    $deny(401, 'API key missing. Send it as "X-Api-Key: <key>" or "Authorization: Bearer <key>".');
}

$cut = strrpos($presented, '_');
$prefix = $cut === false || $cut === 0 ? '' : substr($presented, 0, $cut);
$key = null;

if ($prefix !== '' && strlen($prefix) <= 12) {
    $q = $pdo->prepare('SELECT * FROM api_keys WHERE prefix = ? ORDER BY id');
    $q->execute([$prefix]);

    foreach ($q->fetchAll() as $row) {
        if (hash_equals((string) $row['key_hash'], hash('sha256', $presented))) {
            $key = $row;
        }
    }
}

if ($key === null) {
    $deny(401, 'API key is not valid.');
}

if (!(bool) $key['active']) {
    $deny(401, 'API key has been revoked.');
}

if (!empty($key['expires_at']) && strtotime((string) $key['expires_at']) < time()) {
    $deny(401, 'API key expired on ' . substr((string) $key['expires_at'], 0, 16) . '.');
}

$scopes = json_decode((string) ($key['scopes'] ?? ''), true);
$scopes = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
$tid = (string) $key['tenant_id'];
$store = $platform->forTenant($tid);
$need = static function (string $scope) use ($scopes, $deny): void {
    if (!in_array($scope, $scopes, true)) {
        $deny(403, 'API key does not have the "' . $scope . '" scope.', 'API_KEY_403');
    }
};

if (empty($key['last_used_at']) || strtotime((string) $key['last_used_at']) < time() - 60) {
    $pdo->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([$key['id']]);
}

if ($method === 'GET' && $path === 'ping') {
    $company = $key['employer_company_id'] ? $store->find('companies', (string) $key['employer_company_id']) : null;
    $version = json_decode((string) @file_get_contents($root . '/config/version.json'), true);
    json_out(200, ['ok' => true, 'app' => 'SmartPRS', 'version' => (string) ($version['version'] ?? ''), 'tenant_id' => (int) $tid,
        'company_name' => (string) ($company['name'] ?? $platform->find('tenants', $tid)['name'] ?? ''), 'scopes' => $scopes,
        'timezone' => date_default_timezone_get(), 'server_time' => date('c')]);
}

if ($method === 'POST' && $path === 'attendance/punches') {
    $need('ingest');
    $punches = body_json($raw)['punches'] ?? null;

    if (!is_array($punches) || $punches === [] || count($punches) > PunchIngest::MAX_BATCH) {
        json_out(422, ['error' => ['code' => 'VALIDATION_422', 'message' => 'The punches field must be an array of 1 to ' . PunchIngest::MAX_BATCH . ' items.']]);
    }

    $good = [];
    $bad = [];

    foreach (array_values($punches) as $p) {
        $p = is_array($p) ? $p : [];
        $why = PunchIngest::invalid($p) ?? (PunchIngest::naive((string) $p['punch_at']) === null ? 'TIME_FORMAT' : null);
        $why === null ? $good[] = $p : $bad[] = ['external_id' => is_scalar($p['external_id'] ?? null) ? (string) $p['external_id'] : null, 'status' => 'rejected', 'reason' => $why];
    }

    $out = (new PunchIngest($store, $pdo, $tid))->ingest($good, 'sbb');
    $out['batch']['received'] += count($bad);
    $out['batch']['rejected'] += count($bad);
    json_out(200, ['ok' => true, 'batch' => $out['batch'], 'results' => array_merge($out['results'], $bad)]);
}

if ($method === 'POST' && str_starts_with($path, 'fieldforce/')) {
    $need('ingest');
    $in = body_form($raw);
    $ingest = new PunchIngest($store, $pdo, $tid);
    $fence = new SmartPRS\Surface\GeoFence($store);
    $employee = static function (string $code) use ($store, $ingest): ?array {
        $c = $ingest->match($code, $code, $code);

        if ($c === null) {
            return null;
        }

        foreach ($store->all('employees') as $e) {
            if (SmartPRS\Surface\Employment::code($e) === $c) {
                return $e;
            }
        }

        return null;
    };
    $verdict = static function (array $emp, mixed $lat, mixed $lng) use ($fence): array {
        $f = $fence->fenceFor($emp);

        if ($f === null) {
            return ['allowed' => true, 'status' => 'no-rule'];
        }

        $r = $fence->check($emp, is_numeric($lat) ? (string) $lat : '', is_numeric($lng) ? (string) $lng : '');

        return ['allowed' => (bool) $r['ok'], 'status' => $r['ok'] ? 'inside' : 'outside', 'message' => (string) ($r['why'] ?? '')];
    };

    if ($path === 'fieldforce/geofence/check') {
        $code = trim((string) ($in['emp_code'] ?? ''));

        if ($code === '' || strlen($code) > 64) {
            json_out(422, ['error' => ['code' => 'VALIDATION_422', 'message' => 'emp_code is required.']]);
        }

        $emp = $employee($code);

        if ($emp === null) {
            json_out(200, ['ok' => true, 'employee_found' => false, 'allowed' => true, 'status' => 'unknown-employee']);
        }

        json_out(200, ['ok' => true, 'employee_found' => true, 'employee' => ['emp_code' => SmartPRS\Surface\Employment::code($emp),
            'name' => (string) ($emp['name'] ?? '')]] + $verdict($emp, $in['lat'] ?? null, $in['lng'] ?? null));
    }

    if ($path === 'fieldforce/attendance') {
        $events = $in['events'] ?? null;

        if (!is_array($events) || $events === [] || count($events) > 500) {
            json_out(422, ['error' => ['code' => 'VALIDATION_422', 'message' => 'events must be an array of 1 to 500 items.']]);
        }

        $results = [];

        foreach ($events as $e) {
            $e = is_array($e) ? $e : [];
            $ext = substr(trim((string) ($e['external_id'] ?? '')), 0, 96);
            $type = strtolower((string) ($e['type'] ?? ''));
            $iso = trim((string) ($e['at'] ?? ''));
            $at = null;

            // Without an offset the instant is ambiguous — refused rather than guessed (SmartPRS 2).
            if (preg_match('~(?:Z|[+\-]\d{2}:?\d{2})$~i', $iso) === 1) {
                $at = SmartEpt::local($iso);
            }

            if ($ext === '' || !in_array($type, ['login', 'logout'], true) || $at === null) {
                $results[] = ['external_id' => $ext ?: null, 'status' => 'rejected', 'reason' => 'VALIDATION'];
                continue;
            }

            $code = substr(trim((string) ($e['emp_code'] ?? '')), 0, 64);
            $emp = $code === '' ? null : $employee($code);
            $geo = $emp === null ? null : $verdict($emp, $e['lat'] ?? null, $e['lng'] ?? null)['status'];
            $one = $ingest->ingest([['external_id' => $ext, 'device_sn' => 'CALLER', 'device_user_id' => $code, 'employee_code' => $code,
                'punch_at' => $at, 'direction' => $type === 'login' ? 'in' : 'out',
                'verify_mode' => is_numeric($e['lat'] ?? null) && is_numeric($e['lng'] ?? null) ? 'gps' : null]], 'caller')['results'][0];
            $results[] = $one + ($geo !== null ? ['geo_status' => $geo] : []);
        }

        json_out(200, ['ok' => true, 'results' => $results]);
    }
}

// ------------------------------------------- off-roll agents' app (23 Oct 2026)
if ($method === 'POST' && $path === 'offroll/activity') {
    $need('ingest');
    $events = body_json($raw)['events'] ?? null;

    if (!is_array($events) || $events === [] || count($events) > 500) {
        json_out(422, ['error' => ['code' => 'VALIDATION_422', 'message' => 'events must be an array of 1 to 500 items.']]);
    }

    $offroll = new SmartPRS\Surface\OffrollPay($store, new SmartPRS\Surface\Customisation($store), $root . '/config/offroll-agents.json');

    if (!$offroll->ready()) {
        json_out(503, ['error' => ['code' => 'NOT_READY', 'message' => 'Off-roll agents are not set up on this server yet.']]);
    }

    json_out(200, ['ok' => true, 'results' => $offroll->ingestApp(array_values($events))]);
}

json_out(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'There is no such endpoint under /api/v1.']]);
