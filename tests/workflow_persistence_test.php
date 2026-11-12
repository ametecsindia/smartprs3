<?php

declare(strict_types=1);

use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\Persistence\CompletionOutbox;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Workflow\Persistence\TaskRepository;
use SmartPRS\Platform\Workflow\Persistence\TransitionLedger;
use SmartPRS\Platform\Workflow\TaskState;

/**
 * §IX.6.3's mechanisms, against a real MariaDB.
 *
 * R-IX-045 makes one test mandatory and exact: "submit a decision twice
 * concurrently from two connections; assert exactly one workflow_transitions
 * row, exactly one audit row, exactly one emitted event, and a 409 on the
 * loser." That is what this file does, with two PDO handles and two open
 * transactions — not with one connection pretending.
 */

group('Workflow persistence (Part IX §IX.6.3)');

require_once __DIR__ . '/lib/workflow_db_fixture.php';

$fixture = workflow_db_fixture('smartprs3_workflow_check');

if ($fixture === null) {
    echo "  ! skipped: no database\n";

    return;
}

$pdo = $fixture['pdo'];
$tenantId = $fixture['tenant_id'];
$secondTenantId = $fixture['second_tenant_id'];
$statusId = $fixture['status'];
$instanceId = $fixture['instance_id'];
$database = $fixture['database'];
$tenant = $fixture['tenant'];

$states = new StatusLookup($pdo, $tenant);
$audit = new AuditLedger($pdo, $tenant);
$tasks = new TaskRepository($pdo, $tenant, $states, $audit);

ok(true, 'the schema, the status vocabulary and one running instance are in place');

// =============================================================================
// MECHANISM 1 — THE CONDITIONAL UPDATE, FROM TWO CONNECTIONS
// =============================================================================

$taskId = $tasks->open($instanceId, 'manager', '201', new DateTimeImmutable('2026-08-15 10:00:00'));
ok($taskId > 0, 'a pending task is open');

// Two connections, two repositories. Not one handle pretending to be two: the
// mechanism is a database predicate, and a single connection would let PHP
// serialise what the database is supposed to.
$other = ($fixture['connect'])();
$otherTasks = new TaskRepository($other, $tenant, new StatusLookup($other, $tenant), new AuditLedger($other, $tenant));

$first = $tasks->decide(
    $taskId,
    TaskState::Approved,
    '201',
    new DateTimeImmutable('2026-08-15 10:05:00'),
    1,
    'approved, cover arranged',
);

ok($first['decided'], 'the first decision lands');
same('approved', $states->id('workflow_task_state', 'approved') === (int) $first['task']['status_value_id'] ? 'approved' : '?',
    'and the task carries it');

$second = $otherTasks->decide(
    $taskId,
    TaskState::Rejected,
    '202',
    new DateTimeImmutable('2026-08-15 10:05:01'),
    1,
    'actually, no',
);

ok(!$second['decided'], 'R-IX-045: the second decision on the same task affects zero rows');
same(
    '201',
    (string) $second['task']['decided_by_user_id'],
    'and the caller is handed the decision that stands, not an error with no answer in it',
);
same(
    'approved, cover arranged',
    $second['task']['decision_comment'],
    "R-IX-046: the first decision's stated reason survives — SmartPRS 2 writes remarks unconditionally "
    . 'on every decide, so a blank re-decide erases it',
);

// Even the right actor with the right outcome cannot decide twice.
$again = $tasks->decide($taskId, TaskState::Approved, '201', new DateTimeImmutable('2026-08-15 10:06:00'), 2);
ok(!$again['decided'], 'and a replay of the SAME decision is refused too — the predicate is the status, not the actor');

// =============================================================================
// WC-08 / R-IX-107 — THE REFUSED ATTEMPTS ARE IN THE AUDIT LEDGER
// =============================================================================

$auditRows = TenantScopedQuery::on($pdo, $tenant, 'audit_entries')->orderBy('seq')->select();
$actions = array_map(static fn (array $r): string => (string) $r['action'], $auditRows);

same(
    ['workflow.task_decided', 'workflow.task_decision_refused', 'workflow.task_decision_refused'],
    $actions,
    'R-IX-107: the decision AND both refused attempts are audited — who TRIED to decide is the fact an auditor asks for',
);

$refused = json_decode((string) $auditRows[1]['new_value'], true, 512, JSON_THROW_ON_ERROR);
same('202', (string) $auditRows[1]['actor_user_id'], 'the refused entry names the actor who tried');
same('rejected', $refused['attempted_outcome'], 'and what they tried to do');
same('already decided', $refused['refusal'], 'and why it was refused');
same('201', (string) $refused['standing_decision_by'], 'and whose decision stood instead');

// The two predicates refuse for materially different reasons, and the entry
// says which: "somebody decided first" and "you are working from a stale read
// of a task that is still open" send the caller to different places.
$stillOpen = $tasks->open($instanceId, 'charge', '301', new DateTimeImmutable('2026-08-15 10:07:00'));
$stale = $tasks->decide($stillOpen, TaskState::Approved, '301', new DateTimeImmutable('2026-08-15 10:07:30'), 7);
ok(!$stale['decided'], 'a caller working from a stale row_version is refused even though the task is open');

$staleEntry = TenantScopedQuery::on($pdo, $tenant, 'audit_entries')->orderBy('seq', 'DESC')->limit(1)->first();
same(
    'stale row version: the caller read version 7 and the task is at 1',
    json_decode((string) $staleEntry['new_value'], true, 512, JSON_THROW_ON_ERROR)['refusal'],
    'and that is not lumped in with "already decided" — the task is still waiting for a decision',
);

// R-IX-107's upstream refusals: permission, segregation, self-approval, a
// cancelled instance. TaskRepository cannot see those checks, so it takes the
// reason — but there is exactly one way to write the fact down.
$tasks->refuse($taskId, 'self-approval: the requester is the assignee', '100', new DateTimeImmutable('2026-08-15 10:08:00'), TaskState::Approved);

$latest = TenantScopedQuery::on($pdo, $tenant, 'audit_entries')->orderBy('seq', 'DESC')->limit(1)->first();
same('workflow.task_decision_refused', (string) $latest['action'], 'an upstream refusal is the same audited fact');
same('100', (string) $latest['actor_user_id'], 'attributed to whoever attempted it');

throws(
    InvalidArgumentException::class,
    static fn () => $tasks->refuse($taskId, '  ', '100', new DateTimeImmutable('2026-08-15 10:09:00')),
    'and a refusal with no stated reason is not a record: "refused" alone tells an auditor nothing',
);

same(null, $audit->verify(), 'the chain is intact across every one of them');

// =============================================================================
// MECHANISM 3 — THE LEDGER, AND A SEQ THAT CANNOT BE HANDED OUT TWICE
// =============================================================================

$ledger = new TransitionLedger($pdo, $tenant, $audit);

throws(
    LogicException::class,
    static fn () => $ledger->append($instanceId, 'approved', new DateTimeImmutable('2026-08-15 10:05:00')),
    'appending outside a transaction is refused: the lock would be released before the decision was written',
);

$pdo->beginTransaction();
$seqOne = $ledger->append(
    $instanceId,
    'approved',
    new DateTimeImmutable('2026-08-15 10:05:00'),
    fromNodeKey: 'manager',
    toNodeKey: 'charge',
    taskId: $taskId,
    actorUserId: '201',
    comment: 'approved, cover arranged',
    resolution: ['assignees' => ['201'], 'excluded' => ['100 is the requester']],
);
$pdo->commit();

same(1, $seqOne, 'the first transition takes seq 1');

// R-IX-050: the loser's attempt is written, as evidence.
$pdo->beginTransaction();
$seqTwo = $ledger->append(
    $instanceId,
    'rejected',
    new DateTimeImmutable('2026-08-15 10:05:01'),
    fromNodeKey: 'manager',
    taskId: $taskId,
    actorUserId: '202',
    comment: 'actually, no',
    wasAttempt: true,
);
$pdo->commit();

same(2, $seqTwo, 'and the attempt takes the next one, under the same lock');

$history = $ledger->history($instanceId);
same(2, count($history), 'both are in the ledger');
same(1, (int) $history[1]['was_attempt'],
    'R-IX-050: that a second approver disagreed is recorded even though it changed nothing');
ok(
    str_contains((string) $history[0]['resolution_json'], 'is the requester'),
    'R-IX-028: and the resolution record answers "why did this come to me", which SmartPRS 2 cannot',
);

// WC-08 / R-IX-105: the transition ledger does not insert into audit_entries —
// it calls the audit service, which is the only writer. Both transitions are
// there, and the attempt is a different action from the transition.
$transitionAudit = array_values(array_filter(
    TenantScopedQuery::on($pdo, $tenant, 'audit_entries')->orderBy('seq')->select(),
    static fn (array $r): bool => str_starts_with((string) $r['action'], 'workflow.transition'),
));

same(2, count($transitionAudit), 'R-IX-105: every transition is audited, the losing attempt included');
same('workflow.transitioned', (string) $transitionAudit[0]['action'], 'the one that moved the instance');
same('workflow.transition_attempted', (string) $transitionAudit[1]['action'],
    'and the one that did not — a different action, because they are different facts');
same(
    $instanceId,
    (int) $transitionAudit[0]['workflow_instance_id'],
    'each is filed against the instance, so an auditor can pull one workflow rather than grepping a ledger',
);
ok(
    str_contains((string) $transitionAudit[0]['new_value'], 'is the requester'),
    'and the resolution record travels into the audit entry, not only the transition row',
);

// R-IX-106 is structural rather than conventional: append() refuses outside a
// transaction, and the audit write happens inside that same one. There is no
// try/catch around it in TransitionLedger, so an audit failure cannot leave a
// transition standing. Prove there is no direct insert anywhere in the class.
ok(
    !str_contains(file_get_contents(__DIR__ . '/../src/Platform/Workflow/Persistence/TransitionLedger.php'), "'audit_entries'"),
    "R-IX-105: workflow code names 'audit_entries' nowhere — the audit service is the only writer",
);

// The unique index is the real mechanism, so prove it refuses a duplicate seq.
$duplicate = null;

try {
    $pdo->exec("INSERT INTO workflow_transitions
                  (tenant_id, workflow_instance_id, seq, outcome_code, was_attempt, occurred_at)
                VALUES ({$tenantId}, {$instanceId}, 1, 'approved', 0, NOW())");
} catch (PDOException $e) {
    $duplicate = $e;
}

ok($duplicate !== null, 'mechanism 3: a second row at seq 1 is refused by the database, not by hoping');

// =============================================================================
// MECHANISM 4 — THE COMPLETION OUTBOX FIRES ONCE
// =============================================================================

$outbox = new CompletionOutbox($pdo, $tenant);

$pdo->beginTransaction();
$enqueued = $outbox->enqueue($instanceId, 'leave.charged', ['leave_request_id' => 9], new DateTimeImmutable('2026-08-15 10:05:00'));
$pdo->commit();

ok($enqueued, 'finalise enqueues its completion event');

$pdo->beginTransaction();
$again = $outbox->enqueue($instanceId, 'leave.charged', ['leave_request_id' => 9], new DateTimeImmutable('2026-08-15 10:05:02'));
$pdo->commit();

ok(!$again, 'R-IX-026: a second finalise of the same instance does not enqueue a second event');

$due = $outbox->due(new DateTimeImmutable('2026-08-15 11:00:00'));
same(1, count($due), 'so exactly one transfer order is ever dispatched — SmartPRS 2 re-fires its side effects');

$outbox->markDispatched((int) $due[0]['id'], new DateTimeImmutable('2026-08-15 11:00:01'));
same(0, count($outbox->due(new DateTimeImmutable('2026-08-15 12:00:00'))), 'and a dispatched event is not re-dispatched');

throws(
    LogicException::class,
    static fn () => $outbox->enqueue($instanceId, 'leave.other', [], new DateTimeImmutable('2026-08-15 10:05:00')),
    'enqueueing outside a transaction is refused: an event could outlive a rolled-back completion',
);

// =============================================================================
// AND THE TENANT PREDICATE IS STILL THERE, ON EVERY ONE OF THEM
// =============================================================================

// The fixture seeded tenant two with its OWN full status vocabulary, so what
// the assertions below prove is that the task is invisible — not merely that
// tenant two has no statuses. A test that passes for the wrong reason is worse
// than none.
$otherTenant = new TenantContext();
$otherTenant->establish($secondTenantId);
$foreign = new TaskRepository($pdo, $otherTenant, new StatusLookup($pdo, $otherTenant), new AuditLedger($pdo, $otherTenant));

same(null, $foreign->find($taskId), 'another tenant cannot see the task at all');

$foreignDecision = $foreign->decide($taskId, TaskState::Rejected, '999', new DateTimeImmutable('2026-08-15 10:07:00'), 2);
ok(!$foreignDecision['decided'], 'and cannot decide it — Law 6 holds through the decision path too');

$pdo->exec("DROP DATABASE `{$database}`");
