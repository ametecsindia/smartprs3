<?php

declare(strict_types=1);

use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\Persistence\TimerRepository;
use SmartPRS\Platform\Workflow\Sla\BusinessCalendar;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\SweepDecision;
use SmartPRS\Platform\Workflow\Sla\TimerSchedule;

require_once __DIR__ . '/lib/workflow_fixtures.php';

/**
 * The durable half of §IX.7, against a real database and two connections.
 *
 * `workflow_sla_test.php` proves the arithmetic and the decisions. What can
 * only be proved here is the part §IX.7.3 insists on — that a timed obligation
 * is a ROW — and the two consequences: a catch-up sweep cannot double-send
 * (R-IX-060), and two sweep workers running at once take disjoint batches
 * rather than blocking or colliding (§IX.7.6 step 1).
 */

group('Workflow timers (§IX.7.3, §IX.7.6)');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_timer_check';

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

$pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
            VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Ametecs', 'active', NOW(), NOW())");
$tenantId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
            VALUES (UNHEX(REPEAT('b', 32)), 'workflow_instance_state', 'Workflow instance state', 'workflow', NOW(), NOW())");
$domainId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
            VALUES ({$tenantId}, {$domainId}, UNHEX(REPEAT('1', 32)), 'running', 'Running', 0, 1, NOW(), NOW())");
$runningId = (int) $pdo->lastInsertId();

// A config version for the instance to be pinned to (R-IX-051).
$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'workflow', 'LEAVE-APPROVE', 'Leave approval', 'leave', 1, NOW(), NOW())");
$artefactId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO config_versions
              (tenant_id, config_artefact_id, status_value_id, uuid, version_no, scope_json, specificity, priority,
               effective_from, payload_table, payload_id, checksum, created_at, updated_at)
            VALUES ({$tenantId}, {$artefactId}, {$runningId}, UNHEX(REPEAT('d', 32)), 1, '{}', 0, 0,
                    '2026-04-01', 'workflow_payloads', 1, REPEAT('0', 64), NOW(), NOW())");
$versionId = (int) $pdo->lastInsertId();

$pdo->exec("INSERT INTO workflow_instances
              (tenant_id, uuid, config_version_id, workflow_artefact_key, status_value_id, subject_entity_key,
               subject_id, requester_user_id, current_node_key, next_seq, started_at, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('e', 32)), {$versionId}, 'LEAVE-APPROVE', {$runningId},
                    'leave_request', 900, 100, 'manager', 1, NOW(), NOW(), NOW())");
$instanceId = (int) $pdo->lastInsertId();

$tenant = new TenantContext();
$tenant->establish($tenantId);
$timers = new TimerRepository($pdo, $tenant);

$ist = new DateTimeZone('Asia/Kolkata');
$office = BusinessCalendar::weekdays('HYD-OFFICE', $ist, '09:30', '18:00');
$activatedAt = new DateTimeImmutable('2026-08-26 10:00:00', $ist);

$node = wfNode('manager', NodeType::Approval, [
    'participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])],
    'slaMinutes' => 240,
    'reminderSchedule' => [-60, 120],
    'escalationPolicy' => 'notify_only',
    'escalationTarget' => 'u-head',
]);

$schedule = TimerSchedule::forNode($node, $office, $activatedAt, taskId: '5501');

// =============================================================================
// A TIMED OBLIGATION IS A ROW
// =============================================================================

same(4, $timers->schedule($instanceId, $schedule, $activatedAt), 'the activation writes one row per timer');
same(4, count($timers->forInstance($instanceId)), 'and they can be read back in fire order');

// R-IX-060. A catch-up sweep re-scheduling what already exists is the NORMAL
// case after an outage, so it must be a no-op rather than a crash or a second
// reminder.
same(
    0,
    $timers->schedule($instanceId, $schedule, $activatedAt),
    'scheduling the same activation twice writes nothing — R-IX-060 makes a reminder idempotent per '
    . '(task, kind, sequence), so a scheduler catch-up cannot send the same one twice',
);
same(4, count($timers->forInstance($instanceId)), 'and the table still holds four rows, not eight');

// =============================================================================
// THE SWEEP CLAIMS A BATCH — AND ONLY ITS OWN
// =============================================================================

throws(
    LogicException::class,
    static fn () => $timers->claim(new DateTimeImmutable('2026-09-01 12:00:00', $ist)),
    'claiming outside a transaction is refused: SKIP LOCKED holds a row only for the life of the '
    . 'transaction that locked it, so a batch claimed outside one is handed to every worker',
);

$sweepAt = new DateTimeImmutable('2026-09-01 12:00:00', $ist);

$pdo->beginTransaction();
$claimed = $timers->claim($sweepAt);

same(4, count($claimed), 'every timer that is due and still scheduled is claimed');
same(
    array_map(static fn (array $r): string => (string) $r['fire_at'], $claimed),
    (static function (array $rows): array {
        $sorted = array_map(static fn (array $r): string => (string) $r['fire_at'], $rows);
        sort($sorted);

        return $sorted;
    })($claimed),
    'in fire_at order — §IX.7.6 processes a backlog in the order it should have been processed',
);

// The second connection is what makes SKIP LOCKED provable at all: within one
// connection the rows are simply not locked against ourselves.
$second = new PDO($dsn . ';dbname=' . $database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$secondTenant = new TenantContext();
$secondTenant->establish($tenantId);
$secondWorker = new TimerRepository($second, $secondTenant);

$second->beginTransaction();
$alsoClaimed = $secondWorker->claim($sweepAt);

same(
    [],
    $alsoClaimed,
    'a second sweep worker running at the same instant claims NOTHING — SKIP LOCKED gives the two '
    . 'workers disjoint batches instead of one waiting for the other, or both processing the same '
    . 'timer and sending every reminder twice',
);
$second->rollBack();

// =============================================================================
// SETTLING, AND THE ROW THAT SAYS WHY
// =============================================================================

$suppressedCount = 0;

foreach ($claimed as $row) {
    $timer = new SmartPRS\Platform\Workflow\Sla\Timer(
        (string) $row['timer_kind'],
        new DateTimeImmutable((string) $row['fire_at'], $ist),
        (string) $row['node_key'],
        $row['workflow_task_id'] === null ? null : (string) $row['workflow_task_id'],
        (int) $row['sequence_no'],
        offsetMinutes: $row['offset_minutes'] === null ? null : (int) $row['offset_minutes'],
    );

    $decision = Sweep::decide($timer, $node, $sweepAt);
    $timers->settle((int) $row['id'], $decision, $sweepAt);
    $suppressedCount += $decision->isSuppressed() ? 1 : 0;
}

$pdo->commit();

same(0, $suppressedCount, 'nothing is suppressed here — notify_only takes no decision on anybody\'s behalf');

$settled = $timers->forInstance($instanceId);
same(
    [],
    array_values(array_filter($settled, static fn (array $r): bool => $r['status'] === 'scheduled')),
    'every claimed timer is settled, so the next sweep does not pick them up again',
);

$reasons = array_column($settled, 'outcome_reason', 'timer_kind');
ok(
    str_contains((string) ($reasons['due'] ?? ''), 'recorded and reportable'),
    'and each row records WHAT the sweep decided and why, so a breach report is a read rather than '
    . 'a reconstruction',
);

$pdo->beginTransaction();
same([], $timers->claim($sweepAt), 'a settled backlog leaves the next sweep nothing to do');
$pdo->commit();

// =============================================================================
// CANCELLED, NOT DELETED
// =============================================================================

$later = TimerSchedule::forNode($node, $office, $activatedAt->modify('+1 day'), taskId: '5502');
$timers->schedule($instanceId, $later, $activatedAt);

same(
    4,
    $timers->cancelForTask(5502, $sweepAt, 'the task was approved'),
    'deciding a task cancels its outstanding timers',
);

$cancelled = array_values(array_filter(
    $timers->forInstance($instanceId),
    static fn (array $r): bool => (int) ($r['workflow_task_id'] ?? 0) === 5502,
));

same(4, count($cancelled), 'the rows are still there — cancelled, not deleted');
same(
    'the task was approved',
    (string) $cancelled[0]['outcome_reason'],
    'because "why did this reminder never go out" must stay answerable, and a deleted row answers nothing',
);

// =============================================================================
// THE BACKLOG THE OPS DASHBOARD SHOWS (§IX.7.6)
// =============================================================================

$backlog = $timers->backlog($sweepAt);
same(0, $backlog['overdue'], 'with everything settled or cancelled the sweep is not behind');

$timers->schedule($instanceId, TimerSchedule::forNode($node, $office, $activatedAt, taskId: '5503'), $activatedAt);
$behind = $timers->backlog($sweepAt);

same(4, $behind['overdue'], 'and a backlog is counted');
ok(
    $behind['oldest_overdue_at'] !== null,
    'with the oldest overdue timer named — R-IX-067 needs it to say how degraded SLA processing is, '
    . 'rather than only that it is',
);

$pdo->exec("DROP DATABASE `{$database}`");
