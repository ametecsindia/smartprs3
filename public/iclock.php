<?php
/**
 * ZKTECO / eSSL "ADMS" PUSH RECEIVER — SmartPRS 2's PushController, same protocol.
 * Point the device at this server (Menu → Comm → Cloud Server Setting: this host,
 * port 80/443) and it dials OUT, so no port-forwarding at the client site:
 *
 *   GET  /iclock/cdata?SN=…                      handshake → the option block
 *   POST /iclock/cdata?SN=…&table=ATTLOG          tab-separated punches → attendance
 *   GET  /iclock/getrequest?SN=…                  "any commands?" → OK
 *   *    /iclock/devicecmd | fdata | edata        → OK
 *
 * A device is a Biometric Device Setup row (provider Push) carrying its serial; that row
 * says which tenant it belongs to, its employee-ID prefix, single/dual reader and the
 * IN/OUT machine ids. An unknown serial on a single-tenant (on-premise) install registers
 * itself (SmartPRS 2 behaviour); on the multi-tenant cloud it is logged and ignored until
 * the client's admin adds the serial.
 */
declare(strict_types=1);

require __DIR__ . '/../surface/partials/endpoint-boot.php';

use SmartPRS\Surface\PunchIngest;

$text = static function (string $s): never {
    header('Content-Type: text/plain');
    echo $s;
    exit;
};
$uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$op = ($p = strpos($uri, '/iclock/')) !== false ? trim(substr($uri, $p + 8), '/') : trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');
$sn = trim((string) ($_GET['SN'] ?? $_GET['sn'] ?? $_POST['SN'] ?? ''));

if (!in_array($op, ['cdata', 'getrequest'], true) || $sn === '') {
    $text('OK');
}

$cfg = null;

foreach ($platform->all('biometric_configs') as $row) {
    if (strcasecmp(trim((string) ($row['serial_number'] ?? '')), $sn) === 0 && ($cfg === null || (int) $row['id'] > (int) $cfg['id'])) {
        $cfg = $row;
    }
}

if ($cfg === null) {
    $tenants = array_values(array_filter($platform->all('tenants'), static fn (array $t): bool => (string) ($t['status_code'] ?? '') !== 'closed'));

    if (count($tenants) !== 1) {
        @file_put_contents($root . '/storage/iclock-unknown.log', date('c') . " {$sn} " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n", FILE_APPEND);
        $text('OK');
    }

    $cfg = $platform->forTenant((string) $tenants[0]['id'])->put('biometric_configs', ['provider' => 'Push', 'serial_number' => $sn,
        'label' => 'Push device ' . $sn, 'enabled' => 1, 'empcode' => 'ALL', 'last_status' => 'auto-registered']);
}

$store = $platform->forTenant((string) $cfg['tenant_id']);
$store->put('biometric_configs', ['id' => $cfg['id'], 'last_sync_at' => date('Y-m-d H:i:s')]);

if ($op === 'getrequest' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ($op === 'getrequest') {
        $text('OK');
    }

    $interval = (int) ($cfg['sync_interval_min'] ?? 0);
    $text(implode("\r\n", ['GET OPTION FROM: ' . $sn, 'Stamp=9999', 'OpStamp=9999', 'ErrorDelay=30', 'Delay=30',
        'TransTimes=00:00;23:59', 'TransInterval=' . max(1, $interval ?: 1), 'TransFlag=1111000000',
        'Realtime=' . ($interval > 0 ? 0 : 1), 'Encrypt=0']) . "\r\n");
}

$table = strtoupper((string) ($_GET['table'] ?? $_POST['table'] ?? ''));

if ($table !== 'ATTLOG' || !(bool) ($cfg['enabled'] ?? true)) {
    $text('OK');
}

$in = trim((string) ($cfg['in_machine_id'] ?? ''));
$out = trim((string) ($cfg['out_machine_id'] ?? ''));
$fixed = $in !== '' && strcasecmp($sn, $in) === 0 ? 'in' : ($out !== '' && strcasecmp($sn, $out) === 0 ? 'out' : null);
$punches = [];

foreach (preg_split('~\r\n|\r|\n~', trim((string) file_get_contents('php://input'))) ?: [] as $line) {
    $f = explode("\t", trim($line));
    $pin = trim((string) ($f[0] ?? ''));
    $ts = strtotime(str_replace('/', '-', trim((string) ($f[1] ?? ''))));

    if ($pin === '' || $ts === false) {
        continue;
    }

    $status = trim((string) ($f[2] ?? ''));
    $punches[] = ['device_sn' => $sn, 'device_user_id' => $pin, 'punch_at' => date('Y-m-d H:i:s', $ts),
        // ZKTeco status: 0 check-in, 1 check-out, 4/5 overtime in/out, 2/3 break — SmartPRS 2's mapping.
        'direction' => $fixed ?? (in_array($status, ['1', '2', '5'], true) ? 'out' : 'in'),
        'verify_mode' => trim((string) ($f[3] ?? '')) ?: null];
}

$r = (new PunchIngest($store, $pdo, (string) $cfg['tenant_id']))->ingest($punches, 'push', ['emp_prefix' => $cfg['emp_prefix'] ?? '',
    'device_mode' => $cfg['device_mode'] ?? '']);
$store->put('biometric_configs', ['id' => $cfg['id'], 'last_status' => 'push ' . date('d-m-Y H:i'),
    'last_count' => $r['batch']['accepted']]);
$text('OK: ' . count($punches));
