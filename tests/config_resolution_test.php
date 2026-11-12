<?php

declare(strict_types=1);

use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\UnresolvedConfiguration;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * `resolve(type, key, scope, as_of)` over the real tables (spine S4, V.8.1).
 *
 * Every engine reads configuration through this one call. What it has to get
 * right is not "find a row" — it is which row, for whom, ON WHICH DATE, and
 * what to do when two rows are equally entitled.
 */

group('Configuration resolution');

$dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
$user = getenv('SMARTPRS_TEST_USER') ?: 'root';
$password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';
$database = 'smartprs3_config_check';

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

$statusId = [];
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
    $statusId[$code] = (int) $pdo->lastInsertId();
}

$pdo->exec("INSERT INTO config_artefacts
              (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
            VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'rule', 'LATE-PENALTY', 'Late penalty', 'attendance', 1, NOW(), NOW())");
$artefactId = (int) $pdo->lastInsertId();

$uuid = 0;

/** Publish one version, with its scope rows. @param array<string, list<string>> $scope */
$publish = static function (
    string $status,
    string $from,
    ?string $to,
    array $scope,
    int $priority,
    int $versionNo,
    int $specificity = 0,
) use ($pdo, $tenantId, $artefactId, $statusId, &$uuid): int {
    $pdo->prepare(
        'INSERT INTO config_versions
           (tenant_id, config_artefact_id, status_value_id, uuid, version_no, scope_json, specificity, priority,
            effective_from, effective_to, payload_table, payload_id, checksum, created_at, updated_at)
         VALUES (:t, :a, :s, UNHEX(:u), :v, :j, :sp, :p, :f, :to, :pt, :pi, REPEAT("0", 64), NOW(), NOW())'
    )->execute([
        't' => $tenantId,
        'a' => $artefactId,
        's' => $statusId[$status],
        'u' => str_pad(dechex(++$uuid + 1000), 32, '0', STR_PAD_LEFT),
        'v' => $versionNo,
        'j' => json_encode($scope, JSON_THROW_ON_ERROR),
        'sp' => $specificity,
        'p' => $priority,
        'f' => $from,
        'to' => $to,
        'pt' => 'rule_payloads',
        'pi' => 1,
    ]);

    $versionId = (int) $pdo->lastInsertId();
    $scopeRow = $pdo->prepare(
        'INSERT INTO config_version_scopes (tenant_id, config_version_id, dimension, operator, value, weight)
         VALUES (:t, :v, :d, "IN", :val, 0)'
    );

    foreach ($scope as $dimension => $values) {
        foreach ($values as $value) {
            $scopeRow->execute(['t' => $tenantId, 'v' => $versionId, 'd' => $dimension, 'val' => $value]);
        }
    }

    return $versionId;
};

$tenant = new TenantContext();
$tenant->establish($tenantId);
$repository = new ConfigVersionRepository($pdo, $tenant, new StatusLookup($pdo, $tenant));

$hyderabad = ['branch' => 'HYD', 'grade' => 'G3'];

// =============================================================================
// ONLY PUBLISHED VERSIONS ARE CANDIDATES
// =============================================================================

$draft = $publish('draft', '2026-04-01', null, [], 0, 1);

same(
    [],
    $repository->candidates('rule', 'LATE-PENALTY', new DateTimeImmutable('2026-08-15')),
    'a draft is not a candidate — a draft that resolves is a configuration change nobody approved',
);

$default = $publish('published', '2026-04-01', null, [], 0, 2);
$candidates = $repository->candidates('rule', 'LATE-PENALTY', new DateTimeImmutable('2026-08-15'));

same(1, count($candidates), 'and a published one is');
same('tenant default', $candidates[0]->scope->isTenantDefault() ? 'tenant default' : 'scoped',
    'a version with no scope rows is the tenant default');

// =============================================================================
// AS AT — THE QUESTION SMARTPRS 2 ANSWERS WITH THE CURRENT ROW
// =============================================================================

// A version that governed April to July, superseded from August.
$pdo->exec("UPDATE config_versions SET effective_to = '2026-07-31' WHERE id = {$default}");
$august = $publish('published', '2026-08-01', null, [], 0, 3);

$inJuly = $repository->resolve('rule', 'LATE-PENALTY', $hyderabad, new DateTimeImmutable('2026-07-15'));
$inAugust = $repository->resolve('rule', 'LATE-PENALTY', $hyderabad, new DateTimeImmutable('2026-08-15'));

same((string) $default, $inJuly->versionId, 'a July date resolves the version that governed July');
same((string) $august, $inAugust->versionId, 'and an August date resolves August');
ok(
    $inJuly->versionId !== $inAugust->versionId,
    'a recalculation of July, run in August, computes July — SmartPRS 2 reads the current row, so a '
    . 're-run of a closed period silently produces different numbers',
);

throws(
    UnresolvedConfiguration::class,
    static fn () => $repository->resolve('rule', 'LATE-PENALTY', $hyderabad, new DateTimeImmutable('2026-03-31')),
    'and a date before anything was effective is a NAMED condition, not the nearest row and not a null',
);

// =============================================================================
// PRECEDENCE — MORE SPECIFIC WINS, THEN PRIORITY
// =============================================================================

$branchScoped = $publish('published', '2026-08-01', null, ['branch' => ['HYD']], 0, 4, specificity: 100);

$resolved = $repository->resolve('rule', 'LATE-PENALTY', $hyderabad, new DateTimeImmutable('2026-08-15'));
same((string) $branchScoped, $resolved->versionId, 'a branch-scoped version beats the tenant default for that branch');

same(
    (string) $august,
    $repository->resolve('rule', 'LATE-PENALTY', ['branch' => 'BLR', 'grade' => 'G3'], new DateTimeImmutable('2026-08-15'))->versionId,
    'and another branch still gets the default — the scope is a predicate, not a preference',
);

// The scope rows are what the resolver sees, so a version scoped to a branch
// the subject is not in must not match, whatever else is true of it.
// Both ways of finding nothing give the caller the same shape, so no call site
// can handle one and forget the other.
throws(
    UnresolvedConfiguration::class,
    static fn () => $repository->resolve('rule', 'NO-SUCH-RULE', $hyderabad, new DateTimeImmutable('2026-08-15')),
    'an artefact that does not exist is the same named condition as one that does not apply',
);

// =============================================================================
// A TIE BLOCKS. IT DOES NOT PICK ONE.
// =============================================================================

// A GENUINE tie, built from the S3.2 weights rather than asserted into
// existence: branch (180) + zone (160) sums to 340, and grade alone is 340. Two
// versions, two different scopes, the same weight, the same priority, both
// matching the same employee. Nothing in the precedence rules can separate them.
//
// R-VIII-010 and R-IX-018: ambiguity is a configuration error, never a runtime
// coin-toss. Picking one by insertion order is how a payslip stops being
// reproducible — and it is what SmartPRS 2's fall-through does.
$publish('published', '2026-08-01', null, ['branch' => ['HYD'], 'zone' => ['SOUTH']], 0, 6);
$publish('published', '2026-08-01', null, ['grade' => ['G3']], 0, 7);

$tied = ['branch' => 'HYD', 'zone' => 'SOUTH', 'grade' => 'G3'];
$thrown = null;

try {
    $repository->resolve('rule', 'LATE-PENALTY', $tied, new DateTimeImmutable('2026-08-15'));
} catch (AmbiguousConfiguration $e) {
    $thrown = $e;
}

ok($thrown !== null, 'two equally entitled versions BLOCK rather than one being chosen');
ok(
    str_contains($thrown?->getMessage() ?? '', 'v6') && str_contains($thrown->getMessage(), 'v7'),
    'and the error names BOTH versions, so an administrator knows which two to separate',
);
ok(
    str_contains($thrown?->getMessage() ?? '', '340'),
    'with the weight that ties them, because "they are equally specific" is not actionable on its own',
);

// =============================================================================
// THE TENANT PREDICATE, AND THE PAYLOAD POINTER
// =============================================================================

$otherTenant = new TenantContext();
$otherTenant->establish($tenantId + 1);

same(
    [],
    (new ConfigVersionRepository($pdo, $otherTenant, new StatusLookup($pdo, $otherTenant)))
        ->candidates('rule', 'LATE-PENALTY', new DateTimeImmutable('2026-08-15')),
    'another tenant sees none of it — Law 6 holds through configuration resolution too',
);

// A payload pointer is data written by the publisher, and a table name from a
// row is still a string from a row.
$pdo->exec("UPDATE config_versions SET payload_table = 'information_schema.TABLES' WHERE id = {$august}");
$tampered = null;

try {
    $repository->payload(new SmartPRS\Platform\Config\ConfigVersionCandidate(
        'rule',
        'LATE-PENALTY',
        (string) $august,
        3,
        SmartPRS\Platform\Support\Scope::tenantDefault(),
        0,
        SmartPRS\Platform\Support\EffectiveRange::fromInclusive('2026-08-01'),
        ['payload_table' => 'information_schema.TABLES', 'payload_id' => 1],
    ));
} catch (RuntimeException $e) {
    $tampered = $e;
}

ok($tampered !== null, 'a payload pointer at an unregistered table is refused, not interpolated into SQL');
ok(
    str_contains($tampered?->getMessage() ?? '', 'rule_payloads'),
    'and the message names what a version may actually point at',
);

$pdo->exec("DROP DATABASE `{$database}`");
