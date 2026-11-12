<?php

declare(strict_types=1);

use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * ONE RUNNING WORKFLOW INSTANCE ON A REAL MariaDB.
 *
 * Extracted from workflow_persistence_test.php the moment a SECOND caller
 * needed it — the conformance runner, whose WC-02 and WC-08 cases are about
 * what two database connections do to each other and therefore cannot be proved
 * against an in-memory stand-in. Two copies of a fixture is two definitions of
 * "a running instance", and the first divergence between them shows up as a
 * failing case in whichever copy was not updated.
 *
 * Returns null when there is no database, and says so. The caller decides what
 * that means: the test suite skips, the conformance runner reports NOT RUN —
 * never PASS, because a case that did not execute proved nothing.
 *
 * @return array{
 *     pdo: PDO, tenant: TenantContext, tenant_id: int, second_tenant_id: int,
 *     status: array<string, int>, instance_id: int, artefact_id: int,
 *     connect: Closure(): PDO, database: string
 * }|null
 */
function workflow_db_fixture(string $database): ?array
{
    $dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
    $user = getenv('SMARTPRS_TEST_USER') ?: 'root';
    $password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';

    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        return null;
    }

    require_once __DIR__ . '/laravel_schema_stub.php';

    Illuminate\Support\Facades\Schema::reset();

    $files = glob(__DIR__ . '/../../database/migrations/*.php');
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

    // --- tenants -------------------------------------------------------------

    $pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
                VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Tenant One', 'active', NOW(), NOW())");
    $tenantId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
                VALUES (UNHEX(REPEAT('9', 32)), 'T2', 'Tenant Two', 'active', NOW(), NOW())");
    $secondTenantId = (int) $pdo->lastInsertId();

    // --- the status vocabulary, for BOTH tenants -----------------------------
    //
    // Tenant two gets its own full vocabulary deliberately. An isolation
    // assertion that passes because the other tenant has no statuses is passing
    // for the wrong reason, which is worse than not asserting it.

    $pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
                VALUES (UNHEX(REPEAT('b', 32)), 'workflow_task_state', 'Task state', 'workflow', NOW(), NOW())");
    $taskDomain = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
                VALUES (UNHEX(REPEAT('c', 32)), 'workflow_instance_state', 'Instance state', 'workflow', NOW(), NOW())");
    $instanceDomain = (int) $pdo->lastInsertId();

    // Publishing needs its own vocabulary. WC-04 publishes two versions through
    // the real publisher, because pinning is only interesting against versions
    // that were actually published rather than inserted.
    $pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
                VALUES (UNHEX(REPEAT('8', 32)), 'config_version_status', 'Config version status', 'configuration', NOW(), NOW())");
    $versionDomain = (int) $pdo->lastInsertId();

    $codes = [
        'workflow_task_state' => ['pending', 'approved', 'rejected', 'returned', 'reassigned', 'withdrawn', 'expired', 'superseded'],
        'workflow_instance_state' => ['running', 'completed', 'rejected', 'cancelled', 'blocked', 'failed', 'awaiting_edit', 'expired'],
        'config_version_status' => ['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'],
    ];
    $domains = [
        'workflow_task_state' => $taskDomain,
        'workflow_instance_state' => $instanceDomain,
        'config_version_status' => $versionDomain,
    ];

    $insertStatus = $pdo->prepare(
        'INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
         VALUES (:t, :d, UNHEX(:u), :c, :l, 0, 1, NOW(), NOW())'
    );

    $status = [];
    $seed = 0;

    foreach ([$tenantId, $secondTenantId] as $forTenant) {
        foreach ($domains as $domain => $domainId) {
            foreach ($codes[$domain] as $code) {
                $insertStatus->execute([
                    't' => $forTenant,
                    'd' => $domainId,
                    'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
                    'c' => $code,
                    'l' => ucfirst($code),
                ]);

                if ($forTenant === $tenantId) {
                    $status["{$domain}:{$code}"] = (int) $pdo->lastInsertId();
                }
            }
        }
    }

    // --- the workflow artefact, its version, and one running instance --------

    $pdo->exec("INSERT INTO config_artefacts
                  (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, created_at, updated_at)
                VALUES ({$tenantId}, UNHEX(REPEAT('d', 32)), 'workflow', 'LEAVE-APPROVE', 'Leave approval', 'workflow', NOW(), NOW())");
    $artefactId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO config_versions
                  (tenant_id, config_artefact_id, status_value_id, uuid, version_no, scope_json, specificity, priority,
                   effective_from, payload_table, payload_id, checksum, created_at, updated_at)
                VALUES ({$tenantId}, {$artefactId}, {$status['workflow_instance_state:running']}, UNHEX(REPEAT('e', 32)),
                        1, '{}', 0, 0, '2026-04-01', 'workflow_payloads', 1, REPEAT('0', 64), NOW(), NOW())");
    $versionId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO workflow_instances
                  (tenant_id, uuid, config_version_id, workflow_artefact_key, status_value_id, subject_entity_key,
                   subject_id, requester_user_id, current_node_key, next_seq, started_at, created_at, updated_at)
                VALUES ({$tenantId}, UNHEX(REPEAT('f', 32)), {$versionId}, 'LEAVE-APPROVE',
                        {$status['workflow_instance_state:running']}, 'leave_request', 9, 100, 'manager', 1,
                        NOW(), NOW(), NOW())");
    $instanceId = (int) $pdo->lastInsertId();

    $tenant = new TenantContext();
    $tenant->establish($tenantId);

    return [
        'pdo' => $pdo,
        'tenant' => $tenant,
        'tenant_id' => $tenantId,
        'second_tenant_id' => $secondTenantId,
        'status' => $status,
        'instance_id' => $instanceId,
        'artefact_id' => $artefactId,
        'database' => $database,
        // A SECOND connection, because the mechanisms under test are database
        // predicates and one handle would let PHP serialise what the database is
        // supposed to.
        'connect' => static fn (): PDO => new PDO(
            $dsn . ';dbname=' . $database,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ),
    ];
}
