<?php

declare(strict_types=1);

use SmartPRS\Platform\Persistence\CrossTenantRefused;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Scheduler\TenantSweep;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * The platform schedule — spine S2, §IX.7.6, R-IX-067.
 *
 * A scheduled job is the one thing in the platform that legitimately has no
 * tenant: it exists to visit all of them. That is also the exact shape of
 * SmartPRS 2's tenancy hole, where `when($tid, …)` skips its callback on a null
 * tenant and anything that lost the variable read every customer silently.
 *
 * So the assertions here are mostly about the SHAPE of the crossing: that it is
 * asked for, recorded before the work runs, closed afterwards, and — the one
 * that matters most — that the job body itself runs under an ordinary tenant
 * context and could not reach another tenant if it tried.
 */

group('Platform schedule (S2, §IX.7.6)');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_scheduler_check';

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "  ! skipped: no database ({$e->getMessage()})\n";

    return;
}

require_once __DIR__ . '/lib/laravel_schema_stub.php';

Illuminate\Support\Facades\Schema::reset();
$files = glob(__DIR__ . '/../database/migrations/*.php');
sort($files);

foreach ($files as $file) {
    (require $file)->up();
}

$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

foreach (Illuminate\Support\Facades\Schema::$statements as $statement) {
    $pdo->exec($statement);
}

$ids = [];

foreach ([['T1', 'Ametecs', 'active'], ['T2', 'Another', 'active'], ['T3', 'Closed', 'suspended']] as $i => [$code, $name, $status]) {
    $pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
                VALUES (UNHEX(REPEAT('" . ($i + 1) . "', 32)), '{$code}', '{$name}', '{$status}', NOW(), NOW())");
    $ids[$code] = (int) $pdo->lastInsertId();
}

$audited = [];
$sink = static function (array $entry) use (&$audited): void {
    $audited[] = $entry;
};

$sweep = new TenantSweep($pdo, $sink);
$now = new DateTimeImmutable('2026-08-26 10:00:00');

// =============================================================================
// THE CROSSING IS ASKED FOR, RECORDED, AND CLOSED
// =============================================================================

$visited = [];

$result = $sweep->run('workflow:timers', static function (TenantContext $tenant) use (&$visited): array {
    $visited[] = $tenant->tenantId();

    return ['processed' => 2];
}, $now);

same(2, $result['tenants'], 'only ACTIVE tenants are swept — a suspended tenant is not enqueued work');
same(2, count($visited), 'and the job body ran once per active tenant');
same(4, $result['processed'], 'with the per-tenant counts summed');

same(1, count($audited), 'the cross-tenant grant recorded itself exactly once');
same('platform.scheduler.run', $audited[0]['permission'], 'naming the platform permission it was taken under');
same(
    'SmartPRS\Platform\Scheduler\TenantSweep::run',
    $audited[0]['call_site'],
    'and the call site — which was in CrossTenantEscape::DECLARED_CALL_SITES before this class '
    . 'existed, describing a door with no room behind it',
);
ok(
    str_contains($audited[0]['reason'], 'workflow:timers'),
    'with a reason naming the job, because "cross-tenant access occurred" is not an audit entry '
    . 'anybody can act on',
);

// The one that matters. Inside the loop the context is ORDINARY: the grant was
// closed the moment the tenant list came back, so a job body that reached for
// another tenant's rows gets the refusal every other piece of code gets.
$reachedAcross = null;

$sweep->run('workflow:timers', static function (TenantContext $tenant) use ($pdo, &$reachedAcross): array {
    try {
        TenantScopedQuery::acrossTenants($pdo, 'config_versions')->select(['id']);
        $reachedAcross = false;
    } catch (CrossTenantRefused $e) {
        $reachedAcross = true;
    }

    return [];
}, $now);

ok(
    $reachedAcross === true,
    'the job body itself CANNOT cross tenants — the grant is open around the tenant list only, so a '
    . 'sweep does not make every job it runs cross-tenant-capable for as long as it runs',
);

// =============================================================================
// ONE BAD TENANT DOES NOT LEAVE THE REST UNSWEPT
// =============================================================================

$swept = [];

$partial = $sweep->run('workflow:timers', static function (TenantContext $tenant) use (&$swept, $ids): array {
    if ((int) $tenant->tenantId() === $ids['T1']) {
        throw new RuntimeException('this tenant has a broken calendar');
    }

    $swept[] = $tenant->tenantId();

    return ['processed' => 1];
}, $now->modify('+1 minute'));

same(
    [(string) $ids['T2']],
    $swept,
    'a tenant whose work throws does not stop the sweep — aborting on the first bad tenant leaves '
    . 'every later one unswept, and nothing says which',
);
same(1, $partial['processed'], 'the tenants that did succeed are still counted');

$heartbeat = $pdo->query(
    "SELECT * FROM scheduler_heartbeats WHERE job_key = 'workflow:timers' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

ok(
    str_contains((string) $heartbeat['error'], 'broken calendar'),
    'and the failure is on the heartbeat, naming the tenant — which is what the health check reads',
);

// =============================================================================
// R-IX-067 — DEGRADED IS THE SAFE READING OF NO EVIDENCE
// =============================================================================

$never = $sweep->health('audit:anchor', $now);

ok(!$never['healthy'], 'a job that has NEVER run reports degraded, not healthy');
ok(
    str_contains($never['reason'], 'never run'),
    'because the absence of a heartbeat and a stale one mean the same thing to a user waiting for a '
    . 'reminder, and the safe reading of no evidence is not "fine"',
);

$errored = $sweep->health('workflow:timers', $now->modify('+1 minute'));
ok(!$errored['healthy'], 'a run that reported an error is degraded even though it ran');

// A clean run, then the margin.
$sweep->run('audit:anchor', static fn (): array => ['processed' => 1], $now);

ok($sweep->health('audit:anchor', $now)['healthy'], 'a clean run just now is healthy');

$stale = $sweep->health('audit:anchor', $now->modify('+30 minutes'));

ok(!$stale['healthy'], 'and thirty minutes later, past the five-minute margin, it is degraded again');
same(30, $stale['minutes_since'], 'with how long it has been, so the banner can say how degraded');
ok(
    str_contains($stale['reason'], 'inbox must say so'),
    'and the reason names R-IX-067\'s obligation: users must not be left to infer a stopped '
    . 'scheduler from missing reminders',
);

$pdo->exec("DROP DATABASE `{$database}`");
