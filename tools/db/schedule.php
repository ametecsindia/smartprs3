<?php
/**
 * THE SCHEDULED JOBS, RUNNABLE — without artisan and without a queue worker.
 *
 *   php tools/db/schedule.php timers               the §IX.7.6 SLA sweep
 *   php tools/db/schedule.php anchor                the S14 daily audit anchor
 *   php tools/db/schedule.php health                what the health check would say
 *   php tools/db/schedule.php archive               employees whose deletion timer lapsed → backed up, moved to Old data
 *   php tools/db/schedule.php absence               yesterday's morning-after absence sweep — every tenant
 *   php tools/db/schedule.php unauthorized-absence  yesterday's abscond escalation — every tenant, every stage crossed
 *
 * Spine S2 makes the queue and scheduler MANDATORY — the installer refuses to
 * complete without them and the health check fails loudly if either stops —
 * because SmartPRS 2's most damaging operational failures came from customers
 * running neither. On the target machine these become
 * `php artisan schedule:work` entries. Until the skeleton exists, this is how
 * they run, and running them is not optional.
 *
 * Connection: SMARTPRS_DSN / SMARTPRS_USER / SMARTPRS_PASSWORD / SMARTPRS_DB.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Scheduler\TenantSweep;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\Persistence\TimerRepository;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\Timer;

$job = $_SERVER['argv'][1] ?? '';

if (!in_array($job, ['timers', 'anchor', 'health', 'archive', 'absence', 'unauthorized-absence'], true)) {
    fwrite(STDERR, "Usage: php tools/db/schedule.php timers|anchor|health|archive|absence|unauthorized-absence\n");
    exit(2);
}

// HEARTBEAT FOR SYSTEM HEALTH (owner, 22 Oct 2026): a run that finished without a fatal error leaves
// storage/logs/schedule-<job>.last with its time, so "is the scheduled task registered and running?" has an answer.
register_shutdown_function(static function () use ($job): void {
    $fatal = error_get_last();
    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    @mkdir(dirname(__DIR__, 2) . '/storage/logs', 0775, true);
    @file_put_contents(dirname(__DIR__, 2) . '/storage/logs/schedule-' . $job . '.last', date('c'));
});

$dsn = getenv('SMARTPRS_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_USER') ?: 'root';
$password = getenv('SMARTPRS_PASSWORD') ?: '';
$database = getenv('SMARTPRS_DB') ?: 'smartprs3';

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect: {$e->getMessage()}\nRun php tools/db/migrate.php --fresh first.\n");
    exit(1);
}

$now = new DateTimeImmutable('now');

// The cross-tenant grant records itself before it is used. There is no identity
// layer yet, so the sink prints; when the audit ledger takes platform-level
// entries this becomes an append.
$auditSink = static function (array $entry): void {
    printf("  grant     %s · %s · %s\n", $entry['permission'], $entry['call_site'], $entry['reason']);
};

$sweep = new TenantSweep($pdo, $auditSink);

if ($job === 'archive') {
    // EVERY TENANT: the deletion timers that lapsed since the last run.
    $root = dirname(__DIR__, 2);
    $tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $total = 0;

    foreach ($tenants as $tenantId) {
        $archive = new SmartPRS\Surface\Archive((new SmartPRS\Surface\SqlStore($pdo))->forTenant((string) $tenantId),
            $root . '/config/archive.json', $root . '/storage/archive/' . $tenantId, $root . '/public/uploads');

        foreach ($archive->sweep($now->format('Y-m-d H:i:s'), 'scheduler') as $done) {
            printf("  tenant %s  %s → %s  (%d row(s), %d file(s))  %s\n", $tenantId, $done['code'], $done['archived_code'], $done['rows'], $done['files'], $done['bundle']);
            $total++;
        }
    }

    echo "archive: {$total} employment(s) backed up and moved to Old data.\n";
    exit(0);
}

// ---------------------------------------------------------------------------

if ($job === 'absence') {
    // THE MORNING-AFTER SWEEP, RUN WITHOUT A HUMAN. `surface/Absence.php`'s own
    // doc comment named this scheduler entry as the missing piece ("one date per
    // run, invoked from the screen ... a scheduler entry ... when there is one").
    // Same shape as unauthorized-absence below: one PDO connection, looped per
    // tenant, building the exact same Surface classes and config the Absence
    // Alerts screen itself builds — what absent is, and who is told, both still
    // live in config/absence.json and config/attendance-rules.json, per tenant.
    //
    // Evaluates YESTERDAY, same date the screen's "Run now" defaults to and the
    // same reason Absence::run() refuses a date that is not over yet. Safe to run
    // daily: Notifications::queue() dedupes on the employee and the date, so a day
    // already swept is never re-notified, however many days in a row this fires.
    $root = dirname(__DIR__, 2);
    $tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $today = $now->format('Y-m-d');
    $totalAbsent = 0;
    $totalNotified = 0;

    foreach ($tenants as $tenantId) {
        try {
            $tenantStore = (new SmartPRS\Surface\SqlStore($pdo))->forTenant((string) $tenantId);
            $customisation = new SmartPRS\Surface\Customisation($tenantStore);
            $dayRules = new SmartPRS\Surface\DayRules($root . '/config/attendance-rules.json');
            $calendar = new SmartPRS\Surface\Calendar($root . '/config/calendar.json', $customisation);
            $earnRules = new SmartPRS\Surface\EarnRules($root . '/config/attendance-earn-rules.json');
            $penaltyRules = new SmartPRS\Surface\PenaltyRules($root . '/config/attendance-penalty-rules.json');
            $attendance = new SmartPRS\Surface\Attendance($tenantStore, $dayRules, $calendar, $earnRules, $penaltyRules);
            $policy = new SmartPRS\Surface\LeavePolicy($root . '/config/leave-policy.json', $customisation);
            $leaveRules = new SmartPRS\Surface\LeaveRules($root . '/config/leave-rules.json');
            $chargeRules = new SmartPRS\Surface\ChargeRules($root . '/config/leave-charge-rules.json');
            $notifications = new SmartPRS\Surface\Notifications($tenantStore, $root . '/config/notifications.json');
            $leave = new SmartPRS\Surface\Leave($tenantStore, $policy, $leaveRules, $chargeRules, $calendar, $notifications);
            $absence = new SmartPRS\Surface\Absence($root . '/config/absence.json', $customisation, $attendance,
                $notifications, $leave, new SmartPRS\Surface\Shifts($tenantStore));

            $swept = $absence->run($yesterday, $tenantStore->all('employees'),
                $calendar->shiftStart(), $calendar->shiftEnd(), $today);

            printf("  tenant %-4s %s: %d evaluated, %d absent, %d notified, %d already queued%s\n",
                $tenantId, $swept['date'], $swept['evaluated'], count($swept['absent']),
                $swept['notified'], $swept['already'],
                $swept['enabled'] ? '' : '  (alerts are OFF for this tenant — reported, nobody told)');
            $totalAbsent += count($swept['absent']);
            $totalNotified += $swept['notified'];
        } catch (Throwable $e) {
            // ONE TENANT'S FAILURE NEVER SILENCES THE REST — same rule every
            // other job in this file follows.
            fwrite(STDERR, "  tenant {$tenantId}: FAILED — {$e->getMessage()}\n");
        }
    }

    echo "absence: {$totalAbsent} absence(s) found, {$totalNotified} new message(s) queued for {$yesterday}.\n";
    exit(0);
}

// ---------------------------------------------------------------------------

if ($job === 'unauthorized-absence') {
    // THE ABSCOND ESCALATION, RUN WITHOUT A HUMAN. `surface/UnauthorizedAbsence.php`'s
    // own docblock named this scheduler entry as the missing piece since the feature
    // was built (15 Sep) — the "Run now" button on the Leave & Absence screen is for a
    // one-off manual re-check, never meant to be the ONLY way this runs; leaving it at
    // that would mean an unexplained absence only escalates on days someone happens to
    // click the button. Same shape as the archive job above: one PDO connection, looped
    // per tenant, building the exact same Surface classes and config files the web
    // screen itself builds — nothing about what "absent" means, who is told, or which
    // day counts to escalate at is re-decided here (Law 1: that all still lives in
    // config/unauthorized-absence.json and config/attendance-rules.json, per tenant).
    //
    // Evaluates YESTERDAY, same date the "Run now" button defaults to and the same
    // reason `UnauthorizedAbsence::run()` refuses a date that is not over yet — a day
    // whose punches have not all arrived cannot be judged absent yet. Safe to run daily:
    // the dedupe key is anchored to the run's own start date, so a run already escalated
    // is never re-notified for the same stage, however many days in a row this fires.
    $root = dirname(__DIR__, 2);
    $tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $yesterday = $now->modify('-1 day')->format('Y-m-d');
    $today = $now->format('Y-m-d');
    $totalEscalated = 0;
    $totalNotified = 0;

    foreach ($tenants as $tenantId) {
        try {
            $tenantStore = (new SmartPRS\Surface\SqlStore($pdo))->forTenant((string) $tenantId);
            $customisation = new SmartPRS\Surface\Customisation($tenantStore);
            $dayRules = new SmartPRS\Surface\DayRules($root . '/config/attendance-rules.json');
            $calendar = new SmartPRS\Surface\Calendar($root . '/config/calendar.json', $customisation);
            $earnRules = new SmartPRS\Surface\EarnRules($root . '/config/attendance-earn-rules.json');
            $penaltyRules = new SmartPRS\Surface\PenaltyRules($root . '/config/attendance-penalty-rules.json');
            $attendance = new SmartPRS\Surface\Attendance($tenantStore, $dayRules, $calendar, $earnRules, $penaltyRules);
            $policy = new SmartPRS\Surface\LeavePolicy($root . '/config/leave-policy.json', $customisation);
            $leaveRules = new SmartPRS\Surface\LeaveRules($root . '/config/leave-rules.json');
            $chargeRules = new SmartPRS\Surface\ChargeRules($root . '/config/leave-charge-rules.json');
            $notifications = new SmartPRS\Surface\Notifications($tenantStore, $root . '/config/notifications.json');
            $leave = new SmartPRS\Surface\Leave($tenantStore, $policy, $leaveRules, $chargeRules, $calendar, $notifications);
            $unauthorizedAbsence = new SmartPRS\Surface\UnauthorizedAbsence($root . '/config/unauthorized-absence.json',
                $customisation, $attendance, $notifications, $leave, $tenantStore);

            $sweep = $unauthorizedAbsence->run($yesterday, $tenantStore->all('employees'),
                $calendar->shiftStart(), $calendar->shiftEnd(), $today);

            printf("  tenant %-4s %s: %d evaluated, %d escalated, %d notified, %d already queued%s\n",
                $tenantId, $sweep['date'], $sweep['evaluated'], count($sweep['escalated']),
                $sweep['notified'], $sweep['already'],
                $sweep['enabled'] ? '' : '  (escalation is OFF for this tenant — reported, nobody told)');
            $totalEscalated += count($sweep['escalated']);
            $totalNotified += $sweep['notified'];
        } catch (Throwable $e) {
            // ONE TENANT'S FAILURE NEVER SILENCES THE REST. A missing table on a
            // not-yet-migrated tenant is reported by name and skipped, not swallowed —
            // the same "NOT CHECKED is a reported state, never a blank" rule every other
            // job in this file already follows.
            fwrite(STDERR, "  tenant {$tenantId}: FAILED — {$e->getMessage()}\n");
        }
    }

    echo "unauthorized-absence: {$totalEscalated} escalation(s) found, {$totalNotified} new message(s) queued for {$yesterday}.\n";
    exit(0);
}

// ---------------------------------------------------------------------------

if ($job === 'health') {
    foreach (['workflow:timers', 'audit:anchor'] as $key) {
        $health = $sweep->health($key, $now);
        printf(
            "%-16s %s  %s\n",
            $key,
            $health['healthy'] ? 'HEALTHY ' : 'DEGRADED',
            $health['reason'],
        );
    }

    exit(0);
}

// ---------------------------------------------------------------------------

if ($job === 'timers') {
    echo "workflow:timers\n";

    $result = $sweep->run('workflow:timers', static function (TenantContext $tenant) use ($pdo, $now): array {
        $timers = new TimerRepository($pdo, $tenant);

        $pdo->beginTransaction();

        try {
            $claimed = $timers->claim($now);
            $processed = 0;
            $suppressed = 0;
            $highWater = null;

            foreach ($claimed as $row) {
                $timer = new Timer(
                    (string) $row['timer_kind'],
                    new DateTimeImmutable((string) $row['fire_at']),
                    (string) $row['node_key'],
                    $row['workflow_task_id'] === null ? null : (string) $row['workflow_task_id'],
                    (int) $row['sequence_no'],
                    offsetMinutes: $row['offset_minutes'] === null ? null : (int) $row['offset_minutes'],
                );

                // The node the timer belongs to is read from the published
                // definition in the application. Here there is no workflow
                // seeded, so the sweep is exercised against the node shape the
                // timer itself implies — enough to prove the loop, and it is
                // the ONE thing this tool stands in for. Everything else is the
                // production path.
                $node = new Node(
                    key: (string) $row['node_key'],
                    type: NodeType::Approval,
                    label: ucfirst((string) $row['node_key']),
                    slaMinutes: 480,
                );

                $decision = Sweep::decide($timer, $node, $now);
                $timers->settle((int) $row['id'], $decision, $now);

                $processed++;
                $suppressed += $decision->isSuppressed() ? 1 : 0;

                if ($highWater === null || (string) $row['fire_at'] > $highWater) {
                    $highWater = (string) $row['fire_at'];
                }

                printf("    %-11s %s\n", $decision->action, $decision->timer->describe());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return ['processed' => $processed, 'suppressed' => $suppressed, 'high_water_at' => $highWater];
    }, $now);

    printf(
        "  swept     %d tenant(s), %d timer(s), %d suppressed, %dms\n",
        $result['tenants'],
        $result['processed'],
        $result['suppressed'],
        $result['duration_ms'],
    );

    if ($result['suppressed'] > 0) {
        fwrite(STDERR,
            "\n  ALERT: {$result['suppressed']} automatic decision(s) were WITHHELD because their timers\n"
            . "  fired past the stale threshold. The scheduler was not running when it should have been,\n"
            . "  and those instances are blocked with reason stale_timer (R-IX-066). They need a human.\n");
        exit(1);
    }

    exit(0);
}

// ---------------------------------------------------------------------------

echo "audit:anchor\n";

$anchorOn = new DateTimeImmutable($_SERVER['argv'][2] ?? 'yesterday');

$result = $sweep->run('audit:anchor', static function (TenantContext $tenant) use ($pdo, $anchorOn, $now): array {
    $ledger = new AuditLedger($pdo, $tenant);

    // Anchoring a day whose entries have since changed collides on the unique
    // index — which is the detection working, not the job failing, so the
    // collision is reported rather than swallowed.
    $anchor = $ledger->anchor($anchorOn, $now);

    if ($anchor === null) {
        return ['processed' => 0];
    }

    $break = $ledger->verify();

    printf(
        "    tenant %-4s seq %d..%d, %d entr(ies), chain %s\n",
        $tenant->tenantId(),
        $anchor['first_seq'],
        $anchor['last_seq'],
        $anchor['entry_count'],
        $break === null ? 'intact' : 'BROKEN at seq ' . $break['seq'],
    );

    return ['processed' => $anchor['entry_count'], 'suppressed' => $break === null ? 0 : 1];
}, $now);

printf(
    "  anchored  %s across %d tenant(s), %d entr(ies)\n",
    $anchorOn->format('Y-m-d'),
    $result['tenants'],
    $result['processed'],
);

if ($result['suppressed'] > 0) {
    fwrite(STDERR, "\n  ALERT: a tenant's audit chain does not verify. This is tamper evidence, not a bug\n"
        . "  in the anchor job, and it needs investigating before the day is signed.\n");
    exit(1);
}
