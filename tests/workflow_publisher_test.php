<?php

declare(strict_types=1);

use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Config\PublicationRefused;
use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\Transition;
use SmartPRS\Platform\Workflow\WorkflowPublisher;

/**
 * The third publisher, and the one whose payload is not a single row.
 *
 * A rule and a formula are one payload row each. A workflow is four tables —
 * the header, its nodes, each node's participants, and the transitions between
 * them — so the interesting property here is not "does it write the columns"
 * but "can it ever write half of them". §IX.6.3's whole argument is that a
 * mechanism nothing enforces is not a mechanism, and a published approval chain
 * with no steps is exactly that failure at configuration time rather than
 * runtime.
 *
 * `tests/workflow_engine_test.php` owns the eighteen validator checks. This
 * file owns the write.
 */

group('WorkflowPublisher (spine S4)');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_workflow_publisher_check';

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
            VALUES (UNHEX(REPEAT('b', 32)), 'config_version_status', 'Config version status', 'configuration', NOW(), NOW())");
$domainId = (int) $pdo->lastInsertId();

$seed = 0;
$insertStatus = $pdo->prepare(
    'INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
     VALUES (:t, :d, UNHEX(:u), :c, :l, 0, 1, NOW(), NOW())'
);

foreach (['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'] as $code) {
    $insertStatus->execute([
        't' => $tenantId,
        'd' => $domainId,
        'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
        'c' => $code,
        'l' => ucfirst($code),
    ]);
}

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'workflow', 'LEAVE-APPROVE',
                    'Leave approval', 'leave', 1, NOW(), NOW())");

$tenant = new TenantContext();
$tenant->establish($tenantId);
$statuses = new StatusLookup($pdo, $tenant);
$versions = new ConfigVersionRepository($pdo, $tenant, $statuses);
$publisher = new WorkflowPublisher(new Publisher($pdo, $tenant, $statuses, $versions), $pdo, $tenant);

$now = new DateTimeImmutable('2026-08-15 09:00:00');

// The SAME definition workflow_engine_test.php asserts the eighteen validator
// checks against. A publisher exercising a definition the validator never saw
// would prove nothing about the pair.
require_once __DIR__ . '/lib/workflow_fixtures.php';

// =============================================================================
// THE GRAPH LANDS, WHOLE
// =============================================================================

$definition = wfDefinition();
$versionId = $publisher->publish($definition, $now, null, ['created_by' => '100', 'approved_by' => '101']);

$version = $pdo->query("SELECT * FROM config_versions WHERE id = {$versionId}")->fetch(PDO::FETCH_ASSOC);

same('workflow_payloads', $version['payload_table'], 'the version points at the workflow payload table');
ok((int) $version['payload_id'] > 0, 'and at a real row in it');
same(
    WorkflowPublisher::checksum($definition),
    $version['checksum'],
    'the stored checksum is computed from the definition, so no caller can supply a different one',
);

$payloadId = (int) $version['payload_id'];
$payload = $pdo->query("SELECT * FROM workflow_payloads WHERE id = {$payloadId}")->fetch(PDO::FETCH_ASSOC);

same('leave_request', $payload['subject_entity_key'], 'the header carries the subject entity');
same('raise', $payload['start_node_key'], 'and the start node');
same(0, (int) $payload['is_section_56_operation'], 'and Section 56 is false because the definition says so');

$nodes = $pdo->query(
    "SELECT node_key, node_type, label, is_terminal FROM workflow_nodes
     WHERE workflow_payload_id = {$payloadId} ORDER BY node_key"
)->fetchAll(PDO::FETCH_ASSOC);

same(
    count($definition->nodes),
    count($nodes),
    'every node of the definition is a row — a published workflow with a header and no steps is the '
    . 'configuration-time form of the defect §IX.6.3 is about',
);

$terminal = array_values(array_filter($nodes, static fn (array $n): bool => (int) $n['is_terminal'] === 1));
same(
    2,
    count($terminal),
    'is_terminal is derived from the node TYPE, not from an argument — finalise and reject, here',
);

$transitions = $pdo->query(
    "SELECT from_node_key, outcome_code, to_node_key, evaluation_order
     FROM workflow_definition_transitions WHERE workflow_payload_id = {$payloadId}
     ORDER BY evaluation_order"
)->fetchAll(PDO::FETCH_ASSOC);

same(count($definition->transitions), count($transitions), 'and every transition is a row');
same(
    array_map(static fn (Transition $t): string => $t->from . ':' . $t->outcome, $definition->transitions),
    array_map(static fn (array $r): string => $r['from_node_key'] . ':' . $r['outcome_code'], $transitions),
    'in the DECLARED order — first match wins, so "whichever matched" must never depend on row order',
);

$participants = $pdo->query(
    "SELECT n.node_key, p.kind, p.exclude_requester FROM workflow_node_participants p
     JOIN workflow_nodes n ON n.id = p.workflow_node_id
     WHERE n.workflow_payload_id = {$payloadId} ORDER BY n.node_key, p.seq"
)->fetchAll(PDO::FETCH_ASSOC);

same(2, count($participants), 'each node\'s participant specs are rows of their own');
same('reporting_line', $participants[1]['kind'], 'carrying the kind the definition declared');
same(
    1,
    (int) $participants[1]['exclude_requester'],
    'and exclude_requester defaults to TRUE in the row as it does on the object — a default that '
    . 'disagreed between the two would let a requester approve their own request',
);

// =============================================================================
// A REFUSAL WRITES NOTHING BELOW THE HEADER
// =============================================================================

// W04: a transition to a node that does not exist. The validator blocks, so the
// publication is refused — and the question this asserts is what the four
// tables look like afterwards.
$broken = wfDefinition([
    'key' => 'LEAVE-APPROVE',
    'transitions' => [
        new Transition('raise', 'submitted', 'manager'),
        new Transition('raise', 'blocked', 'refuse'),
        new Transition('manager', 'approved', 'nowhere'),
    ],
]);

$nodesBefore = (int) $pdo->query('SELECT COUNT(*) FROM workflow_nodes')->fetchColumn();
$refused = null;

try {
    $publisher->publish($broken, $now->modify('+1 hour'), null, ['created_by' => '100', 'approved_by' => '101']);
} catch (PublicationRefused $e) {
    $refused = $e;
}

ok($refused !== null, 'a definition that fails a validator check is refused');
same(
    $nodesBefore,
    (int) $pdo->query('SELECT COUNT(*) FROM workflow_nodes')->fetchColumn(),
    'and not one node row was written — the children go in the publisher\'s own transaction, so a '
    . 'refusal cannot leave a half-built graph behind',
);

$checkRows = (int) $pdo->query(
    "SELECT COUNT(*) FROM config_publication_checks c
     JOIN config_versions v ON v.id = c.config_version_id WHERE v.version_no = 2"
)->fetchColumn();

ok(
    $checkRows > 0,
    'but the check outcomes ARE stored, because a blocked publication that says nothing is an '
    . 'administrator raising a support ticket',
);

$blockedVersion = $pdo->query(
    "SELECT status_value_id FROM config_versions WHERE version_no = 2"
)->fetch(PDO::FETCH_ASSOC);

ok(
    (int) $blockedVersion['status_value_id'] !== $statuses->id('config_version_status', 'published'),
    'and the reserved version stays unpublished — a version pointing at nothing is a draft, not a workflow',
);

// =============================================================================
// THE CHECKSUM COVERS THE GRAPH, NOT ONLY THE HEADER
// =============================================================================

$swapped = wfDefinition([
    'nodes' => [
        wfNode('raise', NodeType::Submit),
        // The same node key and label, but a NOTIFY where an approval was. The
        // header is byte-identical; the workflow is not the same workflow.
        wfNode('manager', NodeType::Approval, [
            'participants' => [new ParticipantSpec('named_user', ['user_id' => 'u-1'])],
        ]),
        wfNode('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
        wfNode('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
        wfNode('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
    ],
]);

ok(
    WorkflowPublisher::checksum($definition) !== WorkflowPublisher::checksum($swapped),
    'changing who approves changes the checksum — a hash over the header alone would not notice that '
    . 'the approver had been swapped for somebody else',
);

same(
    WorkflowPublisher::payload($definition, $now),
    WorkflowPublisher::payload($swapped, $now),
    'and the header rows really are identical, which is what makes the assertion above worth making',
);

$pdo->exec("DROP DATABASE `{$database}`");
