<?php

declare(strict_types=1);

use SmartPRS\Platform\Audit\AuditEntry;
use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Config\PublicationRefused;
use SmartPRS\Platform\Config\PublishCheck;
use SmartPRS\Platform\Config\PublishReport;
use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * Law 5 / spine S14 — the ledger, against a real database.
 *
 * `HashChain` is already tested as arithmetic. What could not be tested until
 * there was a writer is everything that makes the arithmetic evidence: that seq
 * is gapless, that each entry links to the one before it, that a change to a
 * committed row is DETECTED rather than merely disallowed, and that the entry
 * and the action it describes commit together or not at all.
 *
 * SmartPRS 2's chain is broken in four places because code other than the audit
 * service inserts into the table. The corresponding property here is that there
 * is one writer and it takes a lock.
 */

group('Audit ledger (Law 5 / S14)');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_audit_check';

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

$tenantIds = [];

foreach (['T1' => 'Ametecs', 'T2' => 'Another'] as $code => $name) {
    $pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
                VALUES (UNHEX(REPEAT('" . strtolower($code[1]) . "', 32)), '{$code}', '{$name}', 'active', NOW(), NOW())");
    $tenantIds[$code] = (int) $pdo->lastInsertId();
}

$tenant = new TenantContext();
$tenant->establish($tenantIds['T1']);
$ledger = new AuditLedger($pdo, $tenant);

$at = new DateTimeImmutable('2026-08-15 09:00:00');

/** Append one entry inside its own transaction, the way a domain service would. */
$append = static function (string $action, array $new, DateTimeImmutable $when) use ($pdo, $ledger): array {
    $pdo->beginTransaction();
    $result = $ledger->append(new AuditEntry(
        action: $action,
        entityType: 'employments',
        occurredAt: $when,
        businessOn: $when,
        entityId: 4471,
        actorUserId: '100',
        actorLabel: 'Ejaz Hussain',
        newValue: $new,
        reason: 'because the test says so',
    ));
    $pdo->commit();

    return $result;
};

// =============================================================================
// THE ENTRY AND THE ACTION COMMIT TOGETHER, OR NOT AT ALL
// =============================================================================

throws(
    LogicException::class,
    static fn () => $ledger->append(new AuditEntry(
        action: 'employment.update',
        entityType: 'employments',
        occurredAt: $at,
        businessOn: $at,
    )),
    'appending outside a transaction is refused — an entry committed separately from what it '
    . 'describes can be present when the action rolled back, which looks like evidence and is not',
);

$pdo->beginTransaction();
$ledger->append(new AuditEntry(
    action: 'employment.update',
    entityType: 'employments',
    occurredAt: $at,
    businessOn: $at,
    entityId: 1,
));
$pdo->rollBack();

same(
    0,
    (int) $pdo->query('SELECT COUNT(*) FROM audit_entries')->fetchColumn(),
    'and a rolled-back action leaves no entry, because the entry was in the same transaction',
);

// =============================================================================
// GAPLESS SEQ, AND A REAL CHAIN
// =============================================================================

$first = $append('employment.create', ['grade' => 'G3'], $at);
$second = $append('employment.update', ['grade' => 'G4'], $at->modify('+1 hour'));
$third = $append('salary.update', ['basic_minor' => 5_000_000], $at->modify('+2 hours'));

same([1, 2, 3], [$first['seq'], $second['seq'], $third['seq']], 'seq is 1, 2, 3 — gapless per tenant');

$rows = $pdo->query('SELECT seq, prev_hash, hash FROM audit_entries ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);

same(null, $rows[0]['prev_hash'], 'the genesis entry has no predecessor');
same($rows[0]['hash'], $rows[1]['prev_hash'], 'and every later entry links to the one before it');
same($rows[1]['hash'], $rows[2]['prev_hash'], 'all the way along');

same(null, $ledger->verify(), 'an untouched chain verifies');
same(3, (int) $ledger->head()['seq'], 'and the head is the last entry written');

// =============================================================================
// TENANCY: TWO TENANTS, TWO INDEPENDENT CHAINS
// =============================================================================

$other = new TenantContext();
$other->establish($tenantIds['T2']);
$otherLedger = new AuditLedger($pdo, $other);

$pdo->beginTransaction();
$otherFirst = $otherLedger->append(new AuditEntry(
    action: 'employment.create',
    entityType: 'employments',
    occurredAt: $at,
    businessOn: $at,
    entityId: 1,
));
$pdo->commit();

same(1, $otherFirst['seq'], 'a second tenant\'s chain starts at 1 — seq is per tenant, not global');
ok(
    $otherFirst['hash'] !== $rows[0]['hash'],
    'and hashes to something different, because the payloads differ',
);
same(null, $otherLedger->verify(), 'both chains verify independently');
same(3, (int) $ledger->head()['seq'], 'and neither tenant\'s head moved when the other wrote (Law 6)');

// =============================================================================
// TAMPERING IS DETECTED, NOT JUST DISALLOWED
// =============================================================================

// There is no code path that could do this — AuditLedger has no update method,
// and the application database account holds no UPDATE on this table. The point
// of the chain is what happens when somebody goes around both.
$pdo->exec("UPDATE audit_entries SET reason = 'a better reason' WHERE tenant_id = {$tenantIds['T1']} AND seq = 2");

$break = $ledger->verify();

ok($break !== null, 'editing a committed entry breaks the chain');
same(2, $break['seq'], 'and verify names the seq that no longer hashes to what is stored');
ok(
    str_contains($break['reason'], 'Tampered'),
    'saying it was tampered with rather than that the chain is merely inconsistent',
);

$pdo->exec("UPDATE audit_entries SET reason = 'because the test says so' WHERE tenant_id = {$tenantIds['T1']} AND seq = 2");
same(null, $ledger->verify(), 'and putting it back makes the chain verify again — the hash is over the content, exactly');

$pdo->exec("DELETE FROM audit_entries WHERE tenant_id = {$tenantIds['T1']} AND seq = 2");
$gap = $ledger->verify();

ok($gap !== null, 'deleting an entry is detected too');
same(
    3,
    $gap['seq'],
    'reported at the seq that should have been 2 — a gap is indistinguishable from a deleted entry, '
    . 'which is exactly why gaplessness is the property and not just an index',
);

// =============================================================================
// THE DAILY ANCHOR (S14)
// =============================================================================

$pdo->exec("DELETE FROM audit_entries WHERE tenant_id = {$tenantIds['T1']}");

$anchorDay = new DateTimeImmutable('2026-08-16 08:00:00');
$append('employment.create', ['grade' => 'G3'], $anchorDay);
$append('employment.update', ['grade' => 'G4'], $anchorDay->modify('+3 hours'));

same(null, $ledger->anchor(new DateTimeImmutable('2026-08-14'), $at), 'a day with no entries has no anchor to write');

$anchor = $ledger->anchor($anchorDay, $anchorDay->modify('+1 day'));

same(2, $anchor['entry_count'], 'the anchor counts the day\'s entries');
same(
    (string) $pdo->query("SELECT hash FROM audit_entries WHERE tenant_id = {$tenantIds['T1']} ORDER BY seq DESC LIMIT 1")->fetchColumn(),
    (string) $pdo->query('SELECT chain_head_hash FROM audit_anchors')->fetchColumn(),
    'and pins the chain head that day closed on, which is what makes a break attributable to a DATE',
);

$twice = null;

try {
    $ledger->anchor($anchorDay, $anchorDay->modify('+1 day'));
} catch (PDOException $e) {
    $twice = $e;
}

ok($twice !== null, 'a day is anchored once — re-anchoring collides on the unique index rather than overwriting');

// =============================================================================
// EVERY PUBLICATION LEAVES EVIDENCE
// =============================================================================

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
        't' => $tenantIds['T1'],
        'd' => $domainId,
        'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
        'c' => $code,
        'l' => ucfirst($code),
    ]);
}

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantIds['T1']}, UNHEX(REPEAT('c', 32)), 'rule', 'LATE-PENALTY',
                    'Late penalty', 'attendance', 1, NOW(), NOW())");

$statuses = new StatusLookup($pdo, $tenant);
$publisher = new Publisher(
    $pdo,
    $tenant,
    $statuses,
    new ConfigVersionRepository($pdo, $tenant, $statuses),
    $ledger,
);

$rulePayload = [
    'decision_point' => 'attendance.day.penalise',
    'module' => 'attendance',
    'business_description' => 'A late arrival over the declared grace costs half a day.',
    'when_src' => 'attendance.late_minutes > 10m',
    'when_ast' => '{"kind":"binary"}',
    'then_json' => '[]',
    'checksum' => str_repeat('1', 64),
    'catalogue_version' => 1,
    'created_at' => '2026-08-15 09:00:00',
    'updated_at' => '2026-08-15 09:00:00',
];

$before = (int) $ledger->head()['seq'];

$publisher->publish(
    'rule',
    'LATE-PENALTY',
    'rule_payloads',
    $rulePayload,
    Scope::tenantDefault(),
    EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
    new PublishReport('rule:LATE-PENALTY', [PublishCheck::pass('R01', 'Static budgets')]),
    $at,
    audit: ['created_by' => '100', 'approved_by' => '101', 'reason' => 'Opening policy for FY 2026-27.'],
);

// new_value is re-encoded before matching: MySQL 8 stores JSON with its own spacing.
$published = $pdo->query(
    "SELECT * FROM audit_entries WHERE tenant_id = {$tenantIds['T1']} ORDER BY seq DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

same($before + 1, (int) $published['seq'], 'publishing appends exactly one entry');
same('config.published', $published['action'], 'named for what happened');
same('100', (string) $published['actor_user_id'], 'carrying who did it');
same('Opening policy for FY 2026-27.', $published['reason'], 'and why they said they did it');
ok(
    str_contains((string) json_encode(json_decode((string) $published['new_value'], true)), '"R01":"pass"'),
    'with the gate outcomes as they stood at that moment — the stored report can be re-read, but the '
    . 'ledger says what the publisher SAW',
);

$blocked = null;

try {
    $publisher->publish(
        'rule',
        'LATE-PENALTY',
        'rule_payloads',
        $rulePayload,
        Scope::tenantDefault(),
        EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-09-01')),
        new PublishReport('rule:LATE-PENALTY', [
            PublishCheck::block('R06', 'Reachability', '`when` can never be true'),
        ]),
        $at->modify('+1 day'),
        audit: ['created_by' => '100', 'reason' => 'Tried to publish an unreachable rule.'],
    );
} catch (PublicationRefused $e) {
    $blocked = $e;
}

ok($blocked !== null, 'a blocked publication still throws');

$refusal = $pdo->query(
    "SELECT * FROM audit_entries WHERE tenant_id = {$tenantIds['T1']} ORDER BY seq DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

same(
    'config.publish_blocked',
    $refusal['action'],
    'and the REFUSAL is in the ledger too — "the system just would not let me" is a support ticket; '
    . 'a row naming the gate is an answer',
);
ok(
    str_contains((string) json_encode(json_decode((string) $refusal['new_value'], true)), '"R06":"block"'),
    'naming which gate refused it',
);

same(null, $ledger->verify(), 'and the chain is still intact after everything the publisher wrote');

$pdo->exec("DROP DATABASE `{$database}`");
