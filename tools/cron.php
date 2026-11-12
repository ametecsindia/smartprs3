<?php
/**
 * THE SCHEDULER TICK — run every 5 minutes by the server's cron (Linux) or Task
 * Scheduler (Windows on-premise):
 *
 *   * /5 * * * *  php /var/www/smartprs3/tools/cron.php >> /var/www/smartprs3/storage/cron.log 2>&1
 *
 * Does, every tick: delete ended Explore Demo companies (and empty the Play reviewer's company once a night); pull punches from every enabled eTimeOffice / eTimeTrackLite /
 * Generic biometric device that is due (its own "Sync frequency"); and on an
 * on-premise install, the once-a-day licence check-in (revocation / renewal).
 * SmartPRS 2 ran the same two from its scheduler (attendance:sync-etimeoffice hourly,
 * the licence revocation sweep daily).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../surface/partials/endpoint-boot.php';

$lock = fopen($root . '/storage/cron.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('c') . " previous tick still running\n";
    exit(0);
}

(new SmartPRS\Surface\Licence($root))->sweep();

// EXPLORE DEMO: ended demo companies are deleted; the Play reviewer's company is emptied once a night.
try {
    foreach ((new SmartPRS\Surface\DemoGate($platform, new SmartPRS\Surface\PlatformSettings($platform), $root . '/.env'))->sweep($pdo) as $did) {
        echo date('c') . " {$did}\n";
    }
} catch (\Throwable $e) {
    echo date('c') . ' demo sweep FAILED ' . $e->getMessage() . "\n"; // the next tick retries
}
$now = time();

foreach ($platform->all('biometric_configs') as $cfg) {
    if (!SmartPRS\Surface\BiometricSync::due($cfg, $now)) {
        continue;
    }

    $tid = (string) $cfg['tenant_id'];

    try {
        $r = (new SmartPRS\Surface\BiometricSync($platform->forTenant($tid), $pdo, $tid, $root))->run($cfg, 1);
        echo date('c') . " tenant {$tid} device {$cfg['id']} " . ($r['ok'] ? "ok fetched {$r['fetched']} new {$r['accepted']} held {$r['pending']}" : 'ERROR ' . $r['error']) . "\n";
    } catch (\Throwable $e) {
        echo date('c') . " tenant {$tid} device {$cfg['id']} FAILED " . $e->getMessage() . "\n"; // the next tick retries
    }
}

flock($lock, LOCK_UN);
